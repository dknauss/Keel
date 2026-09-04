<?php
/**
 * Security backport visibility: is this site actually patched, and if not, can it be?
 *
 * WordPress core never asks this question. It knows whether an update is
 * available, which is not the same thing: a site can be on a release with known
 * vulnerabilities while every admin screen looks perfectly calm. The answer
 * lives at api.wordpress.org/core/stable-check/1.0/, which core does not query.
 *
 * What that endpoint asserts is narrow and worth stating exactly: whether
 * WordPress.org presently classifies a given core version as insecure. It is not
 * a statement that a version is supported — only the latest release is — nor that
 * a site is secure, which depends on plugins, themes, PHP and configuration too.
 *
 * This file surfaces that answer, and — only on an explicit click — offers to
 * act on it.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

const KEEL_DEFAULTS_STABLE_CHECK_URL       = 'https://api.wordpress.org/core/stable-check/1.0/';
const KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT = 'keel_stable_check';
const KEEL_DEFAULTS_STABLE_CHECK_FAILED    = 'keel_stable_check_failed';
// Five minutes. Long enough that an outage cannot stall repeated admin loads,
// short enough that recovery is noticed promptly.
const KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL = 300;
// One day. Written as a literal rather than DAY_IN_SECONDS because this file
// is loaded by the test suite without WordPress, where that constant does not
// exist and a file-scope reference to it is a fatal at require time.
const KEEL_DEFAULTS_STABLE_CHECK_TTL = 86400;

/**
 * Fetch the version status map, cached for a day.
 *
 * The endpoint returns every version WordPress has ever shipped, mapped to
 * 'latest', 'outdated' or 'insecure'. It changes only when a release happens,
 * so a daily cache is generous. A failed request caches nothing, so a network
 * blip does not pin a wrong answer for 24 hours.
 *
 * @return array<string,string> Version => status. Empty array if unavailable.
 */
function keel_defaults_stable_check() {
	$cached = get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	// A failed fetch caches a sentinel for a few minutes. Without it, an outage
	// turns every qualifying admin request into a fresh 10-second timeout,
	// because a miss is indistinguishable from a never-fetched state.
	if ( 'unreachable' === get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED ) ) {
		return array();
	}

	$response = wp_remote_get(
		KEEL_DEFAULTS_STABLE_CHECK_URL,
		array(
			// Short: this can run on a cold cache during a Site Health render.
			'timeout'    => 5,
			'user-agent' => 'Keel; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	$map = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $map ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	// Keep only well-formed version => known-status pairs. An unrecognised status
	// must not reach the caller, where it would fall through to a "good" verdict.
	$map = array_filter(
		$map,
		static function ( $status, $version ) {
			return is_string( $version )
				&& preg_match( '/^\d+\.\d+(\.\d+)?$/', $version )
				&& in_array( $status, array( 'latest', 'outdated', 'insecure' ), true );
		},
		ARRAY_FILTER_USE_BOTH
	);

	if ( empty( $map ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, $map, KEEL_DEFAULTS_STABLE_CHECK_TTL );

	return $map;
}

/**
 * The running WordPress version, on every version Keel supports.
 *
 * Core added wp_get_wp_version() in 6.7. Keel declares `Requires at least:
 * 6.4`, so
 * calling it unguarded is a fatal error on 6.4, 6.5 and 6.6 — not a degraded
 * check but a white screen, on three versions inside the supported range. The
 * live backport matrix caught it on its first run; nothing in the unit suite
 * could, because the test doubles define the function.
 *
 * The fallback reads version.php the way core's own implementation does, and
 * deliberately not get_bloginfo( 'version' ): that passes through the
 * `bloginfo` filters, and hiding the version is a thing plugins do — Keel has
 * its own `remove_version` default. A filtered value is the wrong input for a
 * check about which release is actually running.
 *
 * @return string
 */
function keel_defaults_wp_version() {
	if ( function_exists( 'wp_get_wp_version' ) ) {
		return wp_get_wp_version();
	}

	static $fallback = null;

	if ( null === $fallback ) {
		require ABSPATH . WPINC . '/version.php';
		$fallback = isset( $wp_version ) ? $wp_version : '';
	}

	return $fallback;
}

/**
 * Status of the version this site is running.
 *
 * @return string 'latest', 'outdated', 'insecure', or 'unknown'.
 */
function keel_defaults_version_status() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return 'unknown';
	}

	$version = keel_defaults_wp_version();

	// Development builds carry a suffix and are never listed.
	// strpos rather than str_contains: Keel supports PHP 7.4, where that
	// function does not exist.
	if ( false !== strpos( $version, '-' ) ) {
		return 'unknown';
	}

	if ( ! isset( $map[ $version ] ) ) {
		return 'unknown';
	}

	return in_array( $map[ $version ], array( 'latest', 'outdated', 'insecure' ), true )
		? $map[ $version ]
		: 'unknown';
}

/**
 * The patched tip of the branch this site is on.
 *
 * For a site on 6.4.9 this returns 6.4.10 — the highest release on the 6.4 line
 * that WordPress.org is not currently flagging as insecure. Returns an empty string when the branch has no
 * secure release at all, which is the situation for every line below 4.7: there
 * is no patch to move to, and no configuration will produce one.
 *
 * @return string Version string, or '' if none.
 */
function keel_defaults_branch_tip() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return '';
	}

	$version = keel_defaults_wp_version();
	$parts   = explode( '.', $version );

	if ( count( $parts ) < 2 ) {
		return '';
	}

	$branch = $parts[0] . '.' . $parts[1] . '.';
	$tip    = '';

	foreach ( $map as $candidate => $status ) {
		if ( 'insecure' === $status || 0 !== strpos( $candidate, $branch ) ) {
			continue;
		}
		if ( '' === $tip || version_compare( $candidate, $tip, '>' ) ) {
			$tip = $candidate;
		}
	}

	return ( '' !== $tip && version_compare( $tip, $version, '>' ) ) ? $tip : '';
}

/**
 * The version WordPress.org currently marks as latest.
 *
 * Used to tell a patched-but-behind site how far behind it is, which is the
 * fact that turns "outdated" from a label into a decision.
 *
 * @return string Version string, or '' if unavailable.
 */
function keel_defaults_latest_version() {
	foreach ( keel_defaults_stable_check() as $version => $status ) {
		if ( 'latest' === $status ) {
			return $version;
		}
	}

	return '';
}

/**
 * The x.y line a version belongs to, for naming it in prose.
 *
 * @param string $version Full version.
 * @return string
 */
function keel_defaults_version_line( $version ) {
	return implode( '.', array_slice( preg_split( '/[.-]/', $version ), 0, 2 ) );
}

/**
 * How the Updates screen treats this release: not offered, listed, or hidden.
 *
 * Usually the first. get_core_updates() skips every offer whose response is
 * `autoupdate`, so update-core.php lists only the manual upgrade — the newest
 * release. A same-line patch is therefore invisible there, and linking to that
 * screen for it sends people somewhere that will install something else.
 *
 * Dismissal is a second, separate way an offer goes missing, and asking about
 * it is opt-in: core defaults to `dismissed => false`. Taking that default
 * conflates "the screen will not offer this" with "someone hid this", which
 * are opposite situations — the first needs another route entirely, the second
 * needs one click on "Show hidden updates". Worse, the absent-case copy says
 * the screen would install the newest release instead, and when the hidden
 * offer IS the newest release that sentence names the version it is hiding.
 *
 * So both are requested and the answer distinguishes them. Core stamps
 * ->dismissed on what it returns, which is what separates the two.
 *
 * Asked of core rather than inferred, so this stays true if that filtering
 * changes.
 *
 * A fourth answer is 'unknown'. get_core_updates() returns false — not an
 * empty array — when the update_core transient holds no updates list, which is
 * what a site that has not checked yet looks like. Reading that as 'none'
 * converts missing data into a categorical claim that the screen will not offer
 * the patch, and the copy for 'none' then says the screen would install the
 * newest release instead: on a site whose same-line patch IS the newest
 * release, that names the version it is denying. Opening update-core.php
 * refreshes the data and may well offer it.
 *
 * The visible manual offer is returned alongside the state, because the copy
 * for 'none' says which release the screen would install instead — and that
 * cannot be answered from stable-check. stable-check's idea of the latest
 * release and the update_core transient refresh independently, so a valid but
 * older cache offers a different version than stable-check names. Only the
 * transient knows what that screen is showing right now.
 *
 * @param string $version Version to look for.
 * @return array{state: string, manual: string} State, and the version the
 *               screen is offering ('' when it is offering none).
 */
function keel_defaults_updates_screen_offer( $version ) {
	$update_file = ABSPATH . 'wp-admin/includes/update.php';

	if ( ! function_exists( 'get_core_updates' ) && is_readable( $update_file ) ) {
		require_once $update_file;
	}

	if ( ! function_exists( 'get_core_updates' ) ) {
		return array(
			'state'  => 'unknown',
			'manual' => '',
		);
	}

	$offers = get_core_updates(
		array(
			'available' => true,
			'dismissed' => true,
		)
	);

	if ( ! is_array( $offers ) ) {
		return array(
			'state'  => 'unknown',
			'manual' => '',
		);
	}

	$state  = 'none';
	$manual = '';

	foreach ( $offers as $offer ) {
		if ( ! isset( $offer->current ) ) {
			continue;
		}

		$dismissed = isset( $offer->dismissed ) && $offer->dismissed;

		if ( '' === $manual && ! $dismissed ) {
			$manual = $offer->current;
		}

		if ( $version === $offer->current ) {
			$state = $dismissed ? 'hidden' : 'visible';
		}
	}

	return array(
		'state'  => $state,
		'manual' => $manual,
	);
}

/**
 * Whether relaxed file ownership applies, decided the way core decides it.
 *
 * WP_Automatic_Updater::should_update() sets this from the offer being
 * considered — true only when a core offer explicitly reports no new files —
 * and defaults to false. Assuming true is more permissive than core and would
 * call an update possible that core will refuse.
 *
 * @return bool
 */
function keel_defaults_relaxed_ownership_allowed() {
	$offer = keel_defaults_offer_for_version( keel_defaults_branch_tip() );

	if ( null === $offer ) {
		return false;
	}

	/*
	 * isset() then a loose negation, matching core exactly: update-core.php line 868
	 * and WP_Automatic_Updater lines 205 and 445 all read
	 * `isset( $x->new_files ) && ! $x->new_files`.
	 *
	 * Loose is correct here, not lazy. wp_version_check() maps every offer field
	 * except packages and download through esc_html(), so new_files reaches the
	 * transient as a string: false becomes '', true becomes '1', 0 becomes '0'. All
	 * three negate correctly. Tightening this to `false === $offer->new_files` would
	 * refuse every offer, because after esc_html() the value is never boolean false —
	 * the same shape that made the install refuse every non-English site.
	 *
	 * Checked against core 2026-09-02. Do not "fix" it.
	 */
	return isset( $offer->new_files ) && ! $offer->new_files;
}

/**
 * The raw offer for a version, as WordPress.org sent it.
 *
 * The update_core transient, not get_core_updates(): this is the list before
 * core drops every `autoupdate` response, which is the only place a same-line
 * patch appears at all.
 *
 * Asked so that "core selected nothing" can be told apart from "core has not
 * been given anything to select". find_core_auto_update() returns nothing for
 * both, and for several reasons in between — the auto_update_core filter, an
 * unmet PHP or MySQL requirement on the offer, disable_autoupdate, a recorded
 * failure for that version. Only the presence of the offer separates a cache
 * that predates the release from a release something downstream declined, and
 * neither answer is worth guessing at from the selector alone.
 *
 * @param string $version Version to look for.
 * @return object|null The offer, or null when it is not cached.
 */
function keel_defaults_offer_for_version( $version ) {
	return keel_defaults_install_offer_for_version( $version );
}

/**
 * Who is deciding whether same-branch updates install, and what they decided.
 *
 * Two questions, not one, because a single boolean promises more than it can
 * know. `policy` is whether the minor-update decision currently resolves to
 * yes. `owner` is who resolved it, which determines whether any remediation
 * Keel can offer would actually take effect. `operable` is whether the updater
 * looks capable of acting at all — file modifications permitted, not a version
 * control checkout, no recorded critical failure.
 *
 * A patch only arrives when policy is true AND the updater is operable, and the
 * copy must not claim otherwise. See Core_Upgrader::should_update_to_version()
 * and WP_Automatic_Updater::should_update().
 *
 * @return array{policy:bool,owner:string,operable:bool,blockers:array<int,array{code:string,text:string}>}
 */
function keel_defaults_minor_update_state() {
	$blockers = array();

	if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
		$blockers[] = array(
			'code' => 'file_mods',
			'text' => __( 'file changes are blocked, normally by the DISALLOW_FILE_MODS constant in wp-config.php', 'keel-defaults' ),
		);
	}

	// Ask core, rather than re-deriving its answer. is_disabled() passes the
	// constant through the automatic_updater_disabled filter, so a filter can
	// re-enable an updater the constant switched off. Checking the two
	// separately produces a verdict core does not share.
	// Load the bundle if *either* class is missing. Checking only for
	// WP_Automatic_Updater lets another plugin autoload that one class and
	// silently skip the credential probe below, which then reports the updater
	// operable without ever testing it.
	$upgrader_file = ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	if ( ( ! class_exists( 'WP_Automatic_Updater' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) )
		&& is_readable( $upgrader_file )
	) {
		require_once $upgrader_file;
	}

	$updater = class_exists( 'WP_Automatic_Updater' ) ? new WP_Automatic_Updater() : null;

	// is_disabled() already folds in wp_is_file_mod_allowed(), so the specific
	// reason is reported above and this only fires for some *other* cause.
	if ( $updater && $updater->is_disabled() && empty( $blockers ) ) {
		// is_disabled() decides whether it is off. Which input caused it is a
		// separate question, asked only so the message can say where to look —
		// "switched off" with no location is the least useful true statement
		// this check could make.
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$blockers[] = array(
				'code' => 'automatic_disabled_constant',
				'text' => __( 'automatic updates are switched off by the AUTOMATIC_UPDATER_DISABLED constant, normally set in wp-config.php', 'keel-defaults' ),
			);
		} elseif (
			// Core's filter, asked core's question. PrefixAllGlobals wants a plugin
			// prefix on any hook a plugin invokes, but a keel_ prefixed hook would ask
			// something nothing answers: the point is to learn what the updater will
			// conclude, which means firing the hook the updater fires.
			//
			// This filter is documented in wp-admin/includes/class-wp-automatic-updater.php.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, read deliberately.
			apply_filters( 'automatic_updater_disabled', false )
		) {
			$blockers[] = array(
				'code' => 'automatic_disabled_filter',
				'text' => __( 'a plugin or theme on this site switches automatic updates off, using the automatic_updater_disabled filter', 'keel-defaults' ),
			);
		} else {
			$blockers[] = array(
				'code' => 'automatic_disabled_unknown',
				'text' => __( 'automatic updates are switched off, though not by a constant or filter Keel can name', 'keel-defaults' ),
			);
		}
	}

	// Core refuses an update it cannot get filesystem credentials for, before
	// any policy decision is reached. A site needing FTP details fails here.
	//
	// Relaxed file ownership mirrors core rather than being assumed: core allows
	// it only when the offer itself reports no new files. Passing true
	// unconditionally would call an update operable that core would refuse the
	// moment a security release added a file.
	if ( class_exists( 'Automatic_Upgrader_Skin' ) ) {
		$skin = new Automatic_Upgrader_Skin();

		if ( ! $skin->request_filesystem_credentials( false, ABSPATH, keel_defaults_relaxed_ownership_allowed() ) ) {
			$blockers[] = array(
				'code' => 'credentials',
				'text' => __( 'WordPress cannot write to its own files without credentials it would have to stop and ask for', 'keel-defaults' ),
			);
		}
	}

	$failed = get_site_option( 'auto_core_update_failed' );

	if ( is_array( $failed ) && ! empty( $failed['critical'] ) ) {
		$blockers[] = array(
			'code' => 'previous_failure',
			'text' => __( 'an earlier core update failed badly enough that WordPress will not retry on its own', 'keel-defaults' ),
		);
	}

	if ( $updater && $updater->is_vcs_checkout( ABSPATH ) ) {
		$blockers[] = array(
			'code' => 'vcs',
			'text' => __( 'the site is under version control, so WordPress will not overwrite its own files', 'keel-defaults' ),
		);
	}

	// Who owns the minor decision. Order matters: the first owner found wins,
	// because that is the order core and Keel actually resolve it in.
	$owner   = 'option';
	$enabled = 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' );

	// Ask whether Keel's own filter is actually registered, rather than
	// re-deriving the condition bootstrap.php uses to register it, and let that
	// filter compute its own answer. Copying either the condition or the policy
	// comparison here would drift the moment one of them changed — which is the
	// same mistake as rebuilding core's is_disabled() by hand.
	if ( has_filter( 'allow_minor_auto_core_updates', 'keel_defaults_allow_minor_core_updates' ) ) {
		$owner   = 'keel';
		$enabled = keel_defaults_allow_minor_core_updates( $enabled );
	}

	if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$owner   = 'constant';
		$enabled = ( false !== WP_AUTO_UPDATE_CORE );
	}

	/*
	 * Core's hook again, for the same reason: this reports what core would decide,
	 * and a prefixed hook would report what Keel decided instead.
	 *
	 * This filter is documented in wp-admin/includes/class-core-upgrader.php
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, read deliberately.
	$filtered = (bool) apply_filters( 'allow_minor_auto_core_updates', $enabled );

	if ( $filtered !== $enabled ) {
		$owner = 'filter';
	}

	return array(
		'policy'   => $filtered,
		'owner'    => $owner,
		'operable' => empty( $blockers ),
		'blockers' => $blockers,
	);
}

/**
 * Extract translated blocker text for display.
 *
 * Blocker codes are stable control data. Keeping this projection in one place
 * prevents callers from reaching into the structure differently, while also
 * making it impossible to recover control flow by matching translated text.
 *
 * @param array<int,array{code:string,text:string}> $blockers Structured blockers.
 * @return string[] Translated blocker descriptions.
 */
function keel_defaults_blocker_texts( array $blockers ) {
	return array_column( $blockers, 'text' );
}

/**
 * Extract stable blocker codes for control flow.
 *
 * @param array<int,array{code:string,text:string}> $blockers Structured blockers.
 * @return string[] Stable blocker codes.
 */
function keel_defaults_blocker_codes( array $blockers ) {
	return array_column( $blockers, 'code' );
}

/**
 * Back-compat shim for the simple boolean question.
 *
 * @return bool
 */
function keel_defaults_minor_updates_enabled() {
	$state = keel_defaults_minor_update_state();

	return $state['policy'] && $state['operable'];
}

/**
 * Register the Site Health test, asynchronously.
 *
 * Async for the same reason core's WordPress.org communication test is: a direct
 * test runs during the page render, so a cold cache would pause Site Health for
 * the length of the request. Async, the page paints and the result arrives.
 *
 * The test name matters more than it looks. Core's JS builds the action as
 * `'health-check-' + test.replace( '_', '-' )`, replacing only the first
 * underscore — so `keel_backport` gives `health-check-keel-backport`, and a name
 * with two underscores would produce an action nothing is listening on.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function keel_defaults_register_backport_test( $tests ) {
	$tests['async']['keel_backport'] = array(
		'label'             => __( 'Security patch status', 'keel-defaults' ),
		'test'              => 'keel_backport',
		'async_direct_test' => 'keel_defaults_backport_test',
	);

	return $tests;
}

/**
 * Answer the async Site Health request.
 */
function keel_defaults_backport_ajax() {
	// One argument, deliberately. Site Health's JS posts the nonce as _wpnonce,
	// and check_ajax_referer() only falls back to _wpnonce when no field name is
	// given — naming a field looks for that field and nothing else, so passing
	// 'nonce' here failed every browser-triggered request before the test ran.
	check_ajax_referer( 'health-check-site-status' );

	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error( array(), 403 );
	}

	wp_send_json_success( keel_defaults_backport_test() );
}
add_action( 'wp_ajax_health-check-keel-backport', 'keel_defaults_backport_ajax' );
add_filter( 'site_status_tests', 'keel_defaults_register_backport_test' );

/**
 * Report whether this site is running a version with known vulnerabilities.
 *
 * Deliberately distinguishes "behind" from "vulnerable". Being outdated is a
 * legitimate, deliberate state; being insecure is not. Core's update nag cannot
 * tell the difference and reports both identically.
 *
 * @return array Site Health result.
 */
function keel_defaults_backport_test() {
	$result = keel_defaults_backport_verdict();
	$notice = keel_defaults_backport_result_markup();

	if ( '' !== $notice ) {
		$result['actions'] = $notice . $result['actions'];
	}

	// Appended here rather than on site_status_test_result, because that filter
	// only fires through perform_test() — the async AJAX path answers directly
	// and would silently omit it.
	$ladder = keel_defaults_ladder_markup();

	if ( '' !== $ladder ) {
		$result['description'] .= $ladder;
	}

	return $result;
}

/**
 * What the panel may claim about a scheduled install.
 *
 * `policy && operable` establishes only that minor updates are permitted and the
 * updater can run. It does not establish what core would take: a site that also
 * permits majors gets the highest offer, not the nearest, so the same panel could
 * promise the same-line patch was scheduled directly above a ladder marking 7.1 as
 * the release WordPress would actually install.
 *
 * The claim is therefore made only when core's own selection is the patch.
 *
 * @param array        $state    Result of keel_defaults_minor_update_state().
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @param string       $tip      The same-line patch.
 * @return string Escaped sentence.
 */
function keel_defaults_schedule_statement( array $state, $selected, $tip ) {
	if ( ! $state['policy'] || ! $state['operable'] ) {
		return '';
	}

	if ( is_string( $selected ) && '' !== $selected && $selected === $tip ) {
		return esc_html__( 'The configured policy permits minor updates, the updater looks operable, and this is the release WordPress would install, so it should arrive on a scheduled check. That is not a guarantee: WP-Cron has to run.', 'keel-defaults' );
	}

	if ( is_string( $selected ) && '' !== $selected ) {
		return sprintf(
			/* translators: 1: release core would install, 2: the same-line patch. */
			esc_html__( 'Automatic updates are running here, but WordPress would install %1$s rather than %2$s: it takes the highest release the settings permit, not the nearest. This patch will not arrive on its own.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		);
	}

	if ( false === $selected ) {
		return esc_html__( 'Automatic updates appear to be available, but Keel could not determine which release WordPress would install, so nothing here establishes that this patch is scheduled.', 'keel-defaults' );
	}

	return esc_html__( 'Automatic updates appear to be available, but WordPress is selecting no release to install, so this patch is not scheduled.', 'keel-defaults' );
}

/**
 * The sentence printed beneath the ladder, for a given selection state.
 *
 * Pure, and separate from the markup, because the defect it exists to prevent lives
 * between two sentences rather than inside either. The route statement and this note
 * are concatenated into one rendered panel: the blocked route said clearing the
 * blocker comes first while this said any rung could still be installed deliberately,
 * and each was correct read alone.
 *
 * @param string       $selection Result of keel_defaults_selection_state().
 * @param string|false $selected  Result of keel_defaults_ladder_selection().
 * @return string Escaped sentence.
 */
function keel_defaults_ladder_note( $selection, $selected ) {
	if ( 'blocked' === $selection ) {
		/*
		 * Deliberately does not repeat the blocker list. It is stated in full above,
		 * and this list is appended directly beneath it.
		 *
		 * And it offers no deliberate install *from here*. The blockers that produce
		 * this state are file_mods, credentials and vcs, and Keel's own installer
		 * refuses all three — but that is a statement about Keel, not about the site.
		 * DISALLOW_FILE_MODS does stop WP-CLI as well; a checkout or a web-request
		 * credentials problem often does not, and a deployment workflow may be exactly
		 * how this site is meant to be updated. Claiming the release cannot be
		 * installed at all overstated what this screen can know.
		 */
		return esc_html__( 'None of these will install on their own, because the updater cannot act here. Keel will not offer a deliberate install from this screen until that is cleared; a deployment workflow or WP-CLI may still be able to.', 'keel-defaults' );
	}

	if ( 'unknown' === $selection ) {
		return esc_html__( 'Keel could not determine which of these WordPress would install.', 'keel-defaults' );
	}

	if ( 'scheduled' === $selection ) {
		return sprintf(
			/* translators: %s: version WordPress would install. */
			esc_html__( 'WordPress would install %s and skip the rest. It does not step through them one line at a time.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>'
		);
	}

	/*
	 * Not "this site's update settings decline all of them". A compatibility floor on
	 * every offer — a PHP or MySQL requirement none of them meets — produces exactly
	 * this empty selection, and changing settings would not help. The reason is not
	 * knowable from here, so it is not named, and no deliberate install is promised
	 * either: a requirement none of them meets would refuse that too.
	 */
	return esc_html__( 'None of these will install on their own. Something is declining every one of them, which may be this site\'s update settings or a requirement the offers do not meet. If it is a requirement, Keel will refuse a deliberate install for the same reason.', 'keel-defaults' );
}

/**
 * The verdict itself, without the ladder.
 *
 * @return array Site Health result.
 */
function keel_defaults_backport_verdict() {
	$status  = keel_defaults_version_status();
	$version = keel_defaults_wp_version();
	$tip     = keel_defaults_branch_tip();

	$result = array(
		'label'       => __( 'This version is not currently flagged as insecure', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_backport',
	);

	if ( 'unknown' === $status ) {
		$result['status'] = 'recommended';
		$result['label']  = __( 'Patch status could not be determined', 'keel-defaults' );

		// Three quite different situations produce 'unknown', and saying the
		// wrong one sends people to debug something that is not wrong.
		if ( false !== strpos( $version, '-' ) ) {
			$reason = esc_html__( 'This is a development build, which WordPress.org does not classify.', 'keel-defaults' );
		} elseif ( empty( keel_defaults_stable_check() ) ) {
			$reason = esc_html__( 'Keel could not reach WordPress.org, or the response was not usable. That may be a passing outage rather than anything about this site.', 'keel-defaults' );
		} else {
			$reason = esc_html__( 'WordPress.org does not list this exact version.', 'keel-defaults' );
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: WordPress version, 2: explanation. */
			esc_html__( 'Keel could not determine whether %1$s has known vulnerabilities. %2$s', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			$reason
		) . '</p>';

		return $result;
	}

	if ( 'insecure' === $status ) {
		$result['status'] = 'critical';
		$result['label']  = __( 'This version of WordPress has known vulnerabilities', 'keel-defaults' );

		if ( '' === $tip ) {
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: WordPress version. */
				esc_html__( 'WordPress.org classifies %s as insecure, and every release on this line is flagged the same way, so there is no nearer release to move to. No setting will produce one. The only remedy is moving to a line that is still maintained.', 'keel-defaults' ),
				'<code>' . esc_html( $version ) . '</code>'
			) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress.org classifies %1$s as %2$s: it has publicly known vulnerabilities. The nearest release without known vulnerabilities is %4$s, on your own %3$s line.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<strong>' . esc_html__( 'insecure', 'keel-defaults' ) . '</strong>',
			'<code>' . esc_html( keel_defaults_version_line( $version ) ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		) . '</p>';

		$state = keel_defaults_minor_update_state();

		if ( $state['policy'] && $state['operable'] ) {
			// Not from policy alone. policy && operable says the updater can run; it
			// does not say what core would take, and on a site that also permits
			// majors core takes the highest offer and steps over this patch. Promising
			// otherwise contradicted the ladder printed directly below.
			$result['description'] .= '<p>' . keel_defaults_schedule_statement( $state, keel_defaults_ladder_selection(), $tip ) . '</p>';
		} elseif ( $state['policy'] && ! $state['operable'] ) {
			$result['description'] .= '<p><strong>' . esc_html__( 'The policy permits minor updates, but this patch cannot currently install automatically.', 'keel-defaults' ) . '</strong> '
				. esc_html( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ) ) . '.</p>';
		} else {
			// This is the one place in the panel that states the cause in
			// full. The ladder and the actions below name the kind of problem
			// instead of repeating the sentence: all three are concatenated
			// into a single Site Health panel, and each was written to stand
			// alone, so a real 6.9.5 site saw the same constant named three
			// times and "this will not arrive on its own" said five ways.
			$result['description'] .= '<p><strong>' . sprintf(
				/* translators: %s: patched version. */
				esc_html__( '%s will not install by itself.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>'
			) . '</strong> '
				. ( $state['operable']
					? esc_html__( 'Minor updates are switched off on this site, so WordPress will not fetch it.', 'keel-defaults' )
					: sprintf(
						/* translators: %s: reasons the updater cannot run. */
						esc_html__( 'Two things are stopping it: minor updates are switched off, and %s.', 'keel-defaults' ),
						esc_html( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ) )
					)
				) . '</p><p>'
				. esc_html__( 'WordPress has no security-only update setting. Security fixes ship inside ordinary maintenance releases, so switching off minor updates switches off security fixes with them — there is no way to keep one without the other.', 'keel-defaults' ) . '</p>';
		}

		$result['actions'] = keel_defaults_backport_actions( $tip );

		return $result;
	}

	if ( 'outdated' === $status ) {
		// 'recommended', not 'good'. Staying on a maintained older line is a
		// legitimate choice and this must not nag as though it were a fault.
		// But it is not a settled state either: lines stop being patched, and
		// when yours does, this check flips to critical with no patch to offer.
		// Reporting that as 'good' would hide a decision worth revisiting.
		$result['status']      = 'recommended';
		$result['label']       = __( 'This core version is not currently flagged as insecure', 'keel-defaults' );
		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: latest version. */
			esc_html__( '%1$s is not currently flagged as insecure by WordPress.org. The current release is %2$s.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<code>' . esc_html( keel_defaults_latest_version() ) . '</code>'
		) . '</p>';
		$result['description'] .= '<p>' . esc_html__( 'That is not a support guarantee. Only the current release is actively supported. Fixes for older lines are backported where feasible, and this line will eventually be retired. This check covers core only — not plugins, themes or PHP.', 'keel-defaults' ) . '</p>';

		return $result;
	}

	$result['description'] = '<p>' . sprintf(
		/* translators: %s: WordPress version. */
		esc_html__( '%s is the current release.', 'keel-defaults' ),
		'<code>' . esc_html( $version ) . '</code>'
	) . '</p>';

	return $result;
}



/**
 * Offer a way to act, preferring the mechanism core already has.
 *
 * Two routes, in order of preference:
 *
 * 1. Switch minor auto-updates back on. This fixes the cause rather than the
 *    symptom: the patch installs itself, and so does the next one.
 * 2. Install the patch now, once, through core's own upgrader.
 *
 * Neither happens without a click. Keel's job is to make the decision visible,
 * not to make it.
 *
 * @param string $tip Target version.
 * @return string Markup.
 */
function keel_defaults_backport_actions( $tip ) {
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	$out = '';

	$state              = keel_defaults_minor_update_state();
	$codes              = keel_defaults_blocker_codes( $state['blockers'] );
	$filesystem_blocked = ! empty( array_intersect( $codes, array( 'file_mods', 'credentials', 'vcs' ) ) );

	if ( $filesystem_blocked ) {
		// The blockers are listed in the verdict this is appended to, so
		// this says what to do about them rather than naming them again.
		$out .= '<p class="description">'
			. esc_html__( 'Start with what is blocking this patch, above. Until it passes those checks, no change to the update policy will make any difference.', 'keel-defaults' )
			. '</p>';
	} elseif ( ! $state['policy'] ) {
		// Only offer the button when the option is genuinely what decides it.
		// Otherwise it would write a value that something downstream overrides,
		// and report success for a change with no effect.
		if ( 'option' === $state['owner'] ) {
			$out .= sprintf(
				'<p><a class="button button-primary" href="%s">%s</a></p><p class="description">%s</p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=keel_defaults_enable_minor_updates' ),
						'keel_defaults_enable_minor_updates'
					)
				),
				esc_html__( 'Turn automatic security updates back on', 'keel-defaults' ),
				esc_html__( 'Recommended. This patch, and every future one on this release line, will install without being asked for.', 'keel-defaults' )
			);
		} elseif ( 'keel' === $state['owner'] ) {
			$out .= '<p>' . sprintf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=keel-defaults#updates' ) ),
				esc_html__( 'Change the core update policy in Keel', 'keel-defaults' )
			) . '</p><p class="description">'
			. esc_html__( 'Keel is deciding this, not the stored WordPress option. Setting the option directly would have no effect.', 'keel-defaults' )
			. '</p>';
		} elseif ( 'constant' === $state['owner'] ) {
			$out .= '<p class="description">' . sprintf(
				/* translators: %s: constant name. */
				esc_html__( 'The %s constant is deciding this, and Keel will not override it. Change it in wp-config.php.', 'keel-defaults' ),
				'<code>WP_AUTO_UPDATE_CORE</code>'
			) . '</p>';
		} else {
			$out .= '<p class="description">'
			. esc_html__( 'Another plugin is filtering this decision, so neither the stored option nor Keel governs it. Whatever registered that filter has to change.', 'keel-defaults' )
			. '</p>';
		}
	}

	$offer   = keel_defaults_updates_screen_offer( $tip );
	$screen  = $offer['state'];
	$install = keel_defaults_backport_install_button( $tip, $screen, $state );

	if ( 'visible' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'update-core.php' ) ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
				esc_html( $tip )
			)
		);
	} elseif ( 'hidden' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( admin_url( 'update-core.php' ) ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
				esc_html( $tip )
			),
			sprintf(
				/* translators: 1: target version, 2: the "Show hidden updates" link on the Updates screen. */
				esc_html__( 'Someone dismissed %1$s on this site, so the Updates screen keeps it out of the way until you select %2$s.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>',
				'<strong>' . esc_html__( 'Show hidden updates', 'keel-defaults' ) . '</strong>'
			)
		);
	} elseif ( 'unknown' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( admin_url( 'update-core.php' ) ),
			esc_html__( 'Check the Updates screen', 'keel-defaults' ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'This site has not checked WordPress.org for core updates yet, so Keel cannot say whether the Updates screen is offering %s. Opening that screen checks, and answers it.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>'
			)
		);
	} else {
		$code = '<code>' . esc_html( $tip ) . '</code>';

		$lead = keel_defaults_backport_lead( $tip, $offer['manual'] );

		// Which release cron would install is core's answer, not one to derive
		// from policy. policy && operable says updates can run; it does not say
		// what they would pick. On a site that also permits majors, core takes
		// the highest rung — so this promised the patch while the ladder
		// directly above marked a different release as the winner.
		$route = keel_defaults_backport_route(
			$tip,
			$state,
			keel_defaults_ladder_selection(),
			null !== keel_defaults_offer_for_version( $tip )
		);

		// Every branch of the route ends by naming the command line, which was
		// the only deliberate route when those sentences were written. There is
		// a button now, directly beneath this paragraph, and a sentence telling
		// someone to open a terminal while one sits under it reads as though
		// Keel does not know its own feature. Said once, here, rather than
		// rewriting all seven branches — the existing sentences are still true,
		// they are just no longer the whole list.
		if ( '' !== $install ) {
			$route .= ' ' . sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Or install %s now, below.', 'keel-defaults' ),
				$code
			);
		}

		$out .= '<p class="description">' . $lead . ' ' . $route . '</p>';
	}

	$out .= $install;

	return $out;
}

/**
 * Why this release has not appeared, for a site the Updates screen is not offering it on.
 *
 * It used to say "the Updates screen will not offer 6.8.8". That was true when it was
 * written and U1 made it false: Keel renders the offer on that screen now. What still
 * will not list the patch is WordPress's own update list, which drops every offer
 * flagged for automatic installation — a different claim, and the one worth making,
 * because it explains why nobody has seen this release rather than complaining about a
 * screen Keel has just fixed.
 *
 * What that list is offering instead comes from the screen, not from stable-check. The
 * two caches refresh independently, so naming stable-check's latest release can name a
 * version the screen is not showing. When it is offering nothing, no substitute is
 * claimed.
 *
 * @param string $tip    Patched release on this line.
 * @param string $manual Version the Updates screen is offering, or ''.
 * @return string Escaped sentence.
 */
function keel_defaults_backport_lead( $tip, $manual ) {
	$code = '<code>' . esc_html( $tip ) . '</code>';

	if ( '' !== $manual && $manual !== $tip ) {
		return sprintf(
			/* translators: 1: version WordPress own update list is offering, 2: patched release on this line. */
			esc_html__( 'WordPress\'s own update list is offering %1$s and will not include %2$s. Keel adds it to the Updates screen.', 'keel-defaults' ),
			'<code>' . esc_html( $manual ) . '</code>',
			$code
		);
	}

	return sprintf(
		/* translators: %s: patched release on this line. */
		esc_html__( 'WordPress\'s own update list does not include %s. Keel adds it to the Updates screen.', 'keel-defaults' ),
		$code
	);
}

/**
 * The marker beside a rung, if any.
 *
 * A selection on a site whose updater cannot act is still a fact about what
 * core would pick — it is just not a fact about what will happen. Saying
 * "would install" there contradicts the verdict in the same panel, so the
 * blocked case describes the choice without promising the outcome.
 *
 * @param string       $selection Result of keel_defaults_selection_state().
 * @param string|false $selected  Version core selected.
 * @param string       $version   Version of the rung being rendered.
 * @return string Escaped markup, or ''.
 */
function keel_defaults_ladder_rung_mark( $selection, $selected, $version ) {
	if ( ! is_string( $selected ) || $selected !== $version ) {
		return '';
	}

	/*
	 * "WordPress", never "core". They are the same actor to a site owner, and the
	 * ladder used to name it both ways in adjacent rows.
	 */
	if ( 'blocked' === $selection ) {
		// Conditional on purpose: core would pick this, and cannot act. Saying it
		// installs this contradicts the verdict in the same panel.
		return esc_html__( 'WordPress would pick this', 'keel-defaults' );
	}

	if ( 'scheduled' !== $selection ) {
		return '';
	}

	return esc_html__( 'WordPress installs this', 'keel-defaults' );
}

/**
 * What core's selection actually means, in one place.
 *
 * Both the ladder and the route describe the same fact and each worked out its
 * own precedence for it. The route arrived at this order over four separate
 * review findings; the ladder, computing independently, got two of them wrong —
 * it labelled a rung as one WordPress "would install" on a site whose updater
 * could not act, and it blamed the site's update settings for an empty
 * selection that a compatibility floor on every offer produces just as well.
 *
 * So the order is stated once and both ask for it:
 *
 * - `blocked`   a global blocker prevents the updater from acting, or core
 *               selected nothing and the branch-tip credential probe failed.
 *               A selection outranks `credentials` alone because core already
 *               ran that probe for the exact selected offer; it does not
 *               outrank file-mod, VCS, disablement or failure blockers.
 * - `unknown`   the selector could not be asked. Not an answer, and not the
 *               same as an answer of "nothing".
 * - `none`      core would install nothing. Why is not knowable from here.
 * - `scheduled` core would install the version returned.
 *
 * @param array        $state    Result of keel_defaults_minor_update_state().
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @return string One of blocked, unknown, none, scheduled.
 */
function keel_defaults_selection_state( array $state, $selected ) {
	$blocked = empty( $state['operable'] );

	// The credential probe in minor_update_state() is for the branch tip. Core's
	// selector probes every offer with that offer's own new_files flag, so a
	// selected version is stronger evidence for that version than the tip probe
	// is. Disregard that one contextual blocker only. The remaining codes are
	// global and still outrank an inconsistent selection.
	if ( $blocked && is_string( $selected ) && '' !== $selected ) {
		$codes   = keel_defaults_blocker_codes( $state['blockers'] );
		$blocked = empty( $codes ) || ! empty( array_diff( $codes, array( 'credentials' ) ) );
	}

	if ( $blocked ) {
		return 'blocked';
	}

	if ( false === $selected ) {
		return 'unknown';
	}

	if ( '' === $selected ) {
		return 'none';
	}

	return 'scheduled';
}

/**
 * The sentence describing how this release can actually be reached.
 *
 * A pure function of four inputs, deliberately. Every one of this branch's
 * review findings was a sentence asserting something the data under it could
 * not establish — that policy implied what cron would install, that an empty
 * selection meant updates were off, then that it meant a stale cache, then that
 * a selection implied the updater could write. Each was reachable only through
 * a full panel render, which is why each shipped.
 *
 * Taking the four inputs as arguments makes every combination directly
 * testable, including the ones the surrounding stubs cannot produce: a selector
 * that could not be asked at all is a `false` here, where the live function
 * reaches it only by wp-admin/includes/update.php being unreadable.
 *
 * The order matters and is not arbitrary. Operability gates every promise,
 * because a selection does not establish that the updater can complete a
 * filesystem write. Then an unasked selector, because that is not an answer.
 * Only then the selection itself, and only then policy.
 *
 * @param string       $tip          Target version.
 * @param array        $state        Result of keel_defaults_minor_update_state().
 * @param string|false $selected     Result of keel_defaults_ladder_selection().
 * @param bool         $offer_cached Whether the raw offer for $tip is cached.
 * @return string Escaped sentence.
 */
function keel_defaults_backport_route( $tip, array $state, $selected, $offer_cached ) {
	$code = '<code>' . esc_html( $tip ) . '</code>';

	$selection = keel_defaults_selection_state( $state, $selected );

	if ( 'blocked' === $selection ) {
		/*
		 * No deliberate-install promise here. The blockers that produce this state are
		 * file_mods, credentials and vcs — Keel's own installer refuses all three, and
		 * DISALLOW_FILE_MODS stops WP-CLI too. Naming a route that is also closed is
		 * worse than naming none.
		 */
		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Nothing will install on its own while the updater cannot act, whatever core would otherwise select. Keel will not offer a deliberate install of %s from here until that is cleared.', 'keel-defaults' ),
			$code
		);
	}

	if ( 'unknown' === $selection ) {
		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Keel could not determine what WordPress would install next, so nothing here establishes whether %s is scheduled. It can be installed deliberately from the command line.', 'keel-defaults' ),
			$code
		);
	}

	if ( $selected === $tip ) {
		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Reaching %s means waiting for the scheduled check to install it, or installing it deliberately from the command line.', 'keel-defaults' ),
			$code
		);
	}

	if ( '' !== $selected ) {
		return sprintf(
			/* translators: 1: the version the scheduled check would install, 2: target version. */
			esc_html__( 'The scheduled check will install %1$s and skip %2$s. Reaching %2$s means installing it deliberately from the command line.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>',
			$code
		);
	}

	if ( $state['policy'] && ! $offer_cached ) {
		// Absence establishes only that the release is not in the transient. It
		// does not establish why — a freshly refreshed response can omit it too
		// — so the cause is not named.
		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'WordPress is not scheduling %s: it is not in this site\'s cached list of core updates yet. Automatic updates are running here, so a later check may pick it up. Installing it deliberately requires an offer for it, which this site does not currently hold.', 'keel-defaults' ),
			$code
		);
	}

	if ( $state['policy'] ) {
		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'WordPress is not currently scheduling %s, although it is among the releases this site has been offered. Automatic updates are running, so something is declining this particular release. It can be installed deliberately from the command line.', 'keel-defaults' ),
			$code
		);
	}

	return sprintf(
		/* translators: %s: target version. */
		esc_html__( 'Reaching %s means either letting automatic updates resume, or installing it deliberately from the command line.', 'keel-defaults' ),
		$code
	);
}

/**
 * Re-enable minor auto-updates on request.
 *
 * Writes the option only. It does not touch constants or filters, so a site
 * whose policy is set in code stays set in code — and the Site Health test says
 * so rather than pretending the click worked.
 */
function keel_defaults_handle_enable_minor_updates() {
	if ( ! current_user_can( 'update_core' ) ) {
		wp_die( esc_html__( 'You are not allowed to change update settings on this site.', 'keel-defaults' ) );
	}

	check_admin_referer( 'keel_defaults_enable_minor_updates' );

	update_site_option( 'auto_update_core_minor', 'enabled' );
	delete_site_transient( 'update_core' );

	wp_safe_redirect( add_query_arg( 'keel-minor-updates', 'enabled', admin_url( 'site-health.php' ) ) );
	exit;
}
add_action( 'admin_post_keel_defaults_enable_minor_updates', 'keel_defaults_handle_enable_minor_updates' );

/**
 * Show an admin notice when the running version has known vulnerabilities.
 *
 * Deliberately narrow. It fires only for `insecure` — never for `outdated`,
 * which is a legitimate place to be — and only for users who could act on it.
 * A notice that cannot be acted on is just noise wearing a warning's clothes.
 *
 * It is dismissible per user, and the dismissal is keyed to the version, so
 * dismissing it on 6.8.7 does not hide it again on 6.8.9. That is the failure
 * mode of most "remind me later" notices: they are dismissed once, during the
 * one incident that mattered, and never seen again.
 */
function keel_defaults_backport_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Update-related screens already say their piece; do not stack on them.
	if ( $screen && in_array( $screen->id, array( 'update-core', 'update-core-network', 'site-health' ), true ) ) {
		return;
	}

	// Read-only: the notice never triggers a fetch. If nothing is cached yet the
	// notice simply does not appear this request, and Site Health does the
	// fetching on a screen where a slow response is expected.
	if ( ! is_array( get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT ) ) ) {
		return;
	}

	if ( 'insecure' !== keel_defaults_version_status() ) {
		return;
	}

	$version = keel_defaults_wp_version();

	// One key holding the version it was dismissed at, rather than one key per
	// version. Same behaviour — dismissing on 6.8.7 does not hide it on 6.8.9 —
	// but it leaves a single row to remove on uninstall instead of an unbounded
	// set that only a LIKE query could find.
	if ( get_user_meta( get_current_user_id(), 'keel_backport_dismissed', true ) === $version ) {
		return;
	}

	$tip   = keel_defaults_branch_tip();
	$state = keel_defaults_minor_update_state();

	if ( '' === $tip ) {
		$body = sprintf(
			/* translators: %s: WordPress version. */
			esc_html__( 'WordPress %s has known vulnerabilities, and every release on this line is flagged the same way. Moving to a maintained line is the only remedy.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>'
		);
	} elseif ( $state['policy'] && $state['operable'] ) {
		/*
		 * Deliberately no schedule promise. This renders on every admin screen, so it
		 * cannot afford find_core_auto_update() to learn what core would take — and
		 * without that it cannot know whether this patch is the release that would
		 * arrive. Site Health has the answer and the room to explain it.
		 */
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without known vulnerabilities is %2$s, on this same line. Automatic updating appears to be available; Site Health shows which release WordPress would actually install.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	} elseif ( ! $state['operable'] ) {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version, 3: reasons. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without known vulnerabilities is %2$s, on this same line, but it cannot currently install automatically: %3$s.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>',
			esc_html( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ) )
		);
	} else {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without known vulnerabilities is %2$s, on this same line, but minor updates are switched off on this site, so it will not arrive on its own.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	}

	printf(
		'<div class="notice notice-error is-dismissible keel-defaults-backport-notice" data-keel-version="%s"><p>%s</p><p><a href="%s">%s</a></p></div>',
		esc_attr( $version ),
		wp_kses_post( $body ),
		esc_url( admin_url( 'site-health.php' ) ),
		esc_html__( 'See what is preventing it — Site Health', 'keel-defaults' )
	);
}
add_action( 'admin_notices', 'keel_defaults_backport_notice' );

/**
 * Record a per-user, per-version dismissal.
 */
function keel_defaults_dismiss_backport_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		wp_die( '', '', 403 );
	}

	check_ajax_referer( 'keel_defaults_dismiss_backport', 'nonce' );

	$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

	if ( '' !== $version ) {
		update_user_meta( get_current_user_id(), 'keel_backport_dismissed', $version );
	}

	wp_die();
}
add_action( 'wp_ajax_keel_defaults_dismiss_backport', 'keel_defaults_dismiss_backport_notice' );

/**
 * Make the dismissal stick, rather than only hiding the notice until reload.
 */
function keel_defaults_backport_notice_script() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$script = sprintf(
		'document.addEventListener("click",function(e){' .
			'var b=e.target.closest(".keel-defaults-backport-notice .notice-dismiss");if(!b)return;' .
			'var n=b.closest(".keel-defaults-backport-notice");' .
			'var d=new FormData();d.append("action","keel_defaults_dismiss_backport");' .
			'd.append("nonce",%s);d.append("version",n.dataset.keelVersion);' .
			'fetch(%s,{method:"POST",body:d,credentials:"same-origin"});});',
		wp_json_encode( wp_create_nonce( 'keel_defaults_dismiss_backport' ) ),
		wp_json_encode( admin_url( 'admin-ajax.php' ) )
	);

	wp_add_inline_script( 'common', $script );
}
add_action( 'admin_enqueue_scripts', 'keel_defaults_backport_notice_script' );

require_once __DIR__ . '/backport-install.php';

/**
 * Every release WordPress.org is currently offering this site, in order.
 *
 * Core collapses this into one button. The API does not: a site several release
 * lines behind is offered the patched tip of every line between it and current,
 * each flagged for automatic installation. A 6.8.7 site is offered 6.8.8, 6.9.7,
 * 7.0.4 and 7.1; a 5.9 site is offered twelve.
 *
 * That list is worth seeing, because the one WordPress will actually take is the
 * highest it is permitted to — not the nearest — so the step most people assume
 * they are getting is usually the one that gets skipped.
 *
 * Reports only. Choosing a rung would mean running the core upgrader against a
 * specific offer, which is a separate decision and deliberately not taken here.
 *
 * The delta flag means a partial package is offered, not that core will use
 * it — core falls back to the full package when the partial does not apply.
 *
 * @return array<int,array{version:string,same_line:bool,delta:bool}> Ascending.
 */
function keel_defaults_update_ladder() {
	$updates = get_site_transient( 'update_core' );

	if ( ! is_object( $updates ) || empty( $updates->updates ) ) {
		return array();
	}

	$current = keel_defaults_wp_version();
	$line    = implode( '.', array_slice( preg_split( '/[.-]/', $current ), 0, 2 ) );
	$rungs   = array();

	foreach ( (array) $updates->updates as $offer ) {
		if ( ! isset( $offer->response, $offer->current ) || 'autoupdate' !== $offer->response ) {
			continue;
		}

		if ( version_compare( $offer->current, $current, '<=' ) ) {
			continue;
		}

		$offer_line = implode( '.', array_slice( preg_split( '/[.-]/', $offer->current ), 0, 2 ) );

		$rungs[ $offer->current ] = array(
			'version'   => $offer->current,
			'same_line' => ( $offer_line === $line ),
			'delta'     => ! empty( $offer->packages->partial ),
		);
	}

	uksort( $rungs, 'version_compare' );

	return array_values( $rungs );
}

/**
 * Which rung WordPress will actually take, asked rather than recomputed.
 *
 * Core's own find_core_auto_update() applies every gate — the offer flag, the
 * channel decision, the filters, the PHP and MySQL floors — and returns the
 * winner.
 * Reimplementing that selection here would be the mistake CONTRIBUTING.md
 * describes, and it would drift the first time core changed the order.
 *
 * Three answers, not two. '' means core would install nothing; false means the
 * selector could not be asked, because wp-admin/includes/update.php was not
 * readable. Collapsing those was a review finding of its own: it made every
 * caller report that something had declined the release when in fact nothing
 * had been asked. keel_defaults_selection_state() is what turns this into the
 * state both callers act on.
 *
 * @return string|false Version, '' when nothing would be installed, or false
 *                      when the selector could not be asked.
 */
function keel_defaults_ladder_selection() {
	$update_file = ABSPATH . 'wp-admin/includes/update.php';

	if ( ! function_exists( 'find_core_auto_update' ) && is_readable( $update_file ) ) {
		require_once $update_file;
	}

	if ( ! function_exists( 'find_core_auto_update' ) ) {
		// Not the same answer as "core selected nothing". Collapsing the two
		// makes every caller report that something declined the release, when
		// in fact nothing was ever asked.
		return false;
	}

	// find_core_auto_update() is not read-only. It runs every offer through
	// WP_Automatic_Updater::should_update(), which emails the administrator
	// whenever an offer is rejected for policy, filesystem access or version
	// control — so asking which rung wins could send one message per rejected
	// rung, on every Site Health load. Suppress that for the duration of the
	// question, and restore it whatever happens.
	add_filter( 'send_core_update_notification_email', '__return_false', PHP_INT_MAX );

	try {
		$selected = find_core_auto_update();
	} finally {
		remove_filter( 'send_core_update_notification_email', '__return_false', PHP_INT_MAX );
	}

	return ( $selected && isset( $selected->current ) ) ? $selected->current : '';
}

/**
 * Render the ladder, marking the rung WordPress will take.
 *
 * @return string Markup, or '' when there is nothing to show.
 */
function keel_defaults_ladder_markup() {
	$rungs = keel_defaults_update_ladder();

	if ( count( $rungs ) < 2 ) {
		return '';
	}

	$selected  = keel_defaults_ladder_selection();
	$state     = keel_defaults_minor_update_state();
	$selection = keel_defaults_selection_state( $state, $selected );
	$rows      = '';

	/*
	 * The rung a reader is actually looking for. Marking only core's selection
	 * answers "what will happen" and leaves "what do I want" to be worked out from
	 * the version numbers — which is the whole confusion this panel exists to end.
	 */
	$target = keel_defaults_branch_tip();

	foreach ( $rungs as $rung ) {
		$kind = $rung['same_line']
			? __( 'security and maintenance, same release line', 'keel-defaults' )
			: __( 'new release line', 'keel-defaults' );

		if ( $rung['delta'] ) {
			$kind .= __( ' — a delta package is available', 'keel-defaults' );
		}

		$is_target = ( '' !== $target && $target === $rung['version'] );
		$core_mark = keel_defaults_ladder_rung_mark( $selection, $selected, $rung['version'] );

		/*
		 * Composed, not combined. Both marks can land on one rung — the release a
		 * reader wants is also the one WordPress would take — and that used to be a
		 * fourth string spelling out both facts in one sentence. Joining the two short
		 * labels says the same thing, drops a string, and removes the branch that
		 * existed only to hold it.
		 *
		 * The panel above has already established that this is the nearest release on
		 * the site's own line, so the rung does not repeat it.
		 */
		$labels = array();

		if ( $is_target ) {
			$labels[] = esc_html__( 'security fix', 'keel-defaults' );
		}

		if ( '' !== $core_mark ) {
			$labels[] = $core_mark;
		}

		// One arrow, however many labels. rung_mark() returns the label alone for
		// exactly this reason: when it carried its own arrow, a rung that was both the
		// fix and core's choice printed two of them.
		$marks = empty( $labels )
			? ''
			: ' <strong>' . esc_html( '←' ) . ' ' . implode( ' · ', $labels ) . '</strong>';

		$rows .= sprintf(
			'<li><code>%1$s</code> — %2$s%3$s</li>',
			esc_html( $rung['version'] ),
			esc_html( $kind ),
			$marks
		);
	}

	$note = keel_defaults_ladder_note( $selection, $selected );

	// Always at least two rungs: the markup returns early below that, so no
	// plural handling is needed.
	return '<p>' . esc_html(
		sprintf(
			/* translators: %d: number of releases offered. */
			__( 'WordPress.org is offering this site %d releases. It will install at most one of them, and it picks the highest your settings allow rather than the nearest:', 'keel-defaults' ),
			count( $rungs )
		)
	)
		. '</p><ul>' . $rows . '</ul><p class="description">' . $note . '</p>';
}
