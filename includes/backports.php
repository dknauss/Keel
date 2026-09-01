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
 * Status of the version this site is running.
 *
 * @return string 'latest', 'outdated', 'insecure', or 'unknown'.
 */
function keel_defaults_version_status() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return 'unknown';
	}

	$version = wp_get_wp_version();

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

	$version = wp_get_wp_version();
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
	$tip = keel_defaults_branch_tip();

	if ( '' === $tip ) {
		return false;
	}

	$updates = get_site_transient( 'update_core' );

	if ( ! is_object( $updates ) || empty( $updates->updates ) ) {
		return false;
	}

	foreach ( (array) $updates->updates as $offer ) {
		if ( isset( $offer->current ) && $tip === $offer->current ) {
			return isset( $offer->new_files ) && ! $offer->new_files;
		}
	}

	return false;
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
 * @return array{policy:bool,owner:string,operable:bool,blockers:string[]}
 */
function keel_defaults_minor_update_state() {
	$blockers = array();

	if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
		$blockers[] = __( 'file modifications are not permitted', 'keel-defaults' );
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
		$blockers[] = __( 'the automatic updater is switched off', 'keel-defaults' );
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
			$blockers[] = __( 'WordPress cannot get the filesystem access it needs', 'keel-defaults' );
		}
	}

	$failed = get_site_option( 'auto_core_update_failed' );

	if ( is_array( $failed ) && ! empty( $failed['critical'] ) ) {
		$blockers[] = __( 'a previous core update failed critically', 'keel-defaults' );
	}

	if ( $updater && $updater->is_vcs_checkout( ABSPATH ) ) {
		$blockers[] = __( 'the site is under version control', 'keel-defaults' );
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

	/** This filter is documented in wp-admin/includes/class-core-upgrader.php */
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
 * The verdict itself, without the ladder.
 *
 * @return array Site Health result.
 */
function keel_defaults_backport_verdict() {
	$status  = keel_defaults_version_status();
	$version = wp_get_wp_version();
	$tip     = keel_defaults_branch_tip();
	$auto    = keel_defaults_minor_updates_enabled();

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
				esc_html__( 'WordPress.org classifies %s as insecure, and no patched release exists on this release line. No setting will produce one. The only remedy is moving to a line that is still maintained.', 'keel-defaults' ),
				'<code>' . esc_html( $version ) . '</code>'
			) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress.org classifies %1$s as insecure. The applicable release on this line is %2$s, which is not currently flagged, and moving to it does not change major version.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		) . '</p>';

		$state = keel_defaults_minor_update_state();

		if ( $state['policy'] && $state['operable'] ) {
			$result['description'] .= '<p>' . esc_html__( 'The configured policy permits minor updates and the updater looks operable, so this should install on a scheduled check. That is not a guarantee: WP-Cron has to run, and the release has to be offered for automatic installation.', 'keel-defaults' ) . '</p>';
		} elseif ( $state['policy'] && ! $state['operable'] ) {
			$result['description'] .= '<p><strong>' . esc_html__( 'The policy permits minor updates, but the updater cannot currently act.', 'keel-defaults' ) . '</strong> '
				. esc_html( implode( '; ', $state['blockers'] ) ) . '.</p>';
		} else {
			$result['description'] .= '<p><strong>' . esc_html__( 'Minor updates are switched off on this site, so this patch will not arrive on its own.', 'keel-defaults' ) . '</strong> '
				. esc_html__( 'Security backports travel on the same channel as ordinary maintenance releases; there is no separate security channel to leave open.', 'keel-defaults' ) . '</p>';
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
		$result['description'] .= '<p>' . esc_html__( 'That is not a support guarantee. Only the latest release is actively supported; fixes for older lines are backported as a courtesy, where necessary and feasible, and ship as they become ready. Staying here is a reasonable choice and Keel will not nag about it, but the line will eventually be retired, and this check only covers core — not plugins, themes, PHP or anything undisclosed.', 'keel-defaults' ) . '</p>';

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

	$state = keel_defaults_minor_update_state();

	if ( ! $state['operable'] ) {
		$out .= '<p class="description">' . sprintf(
			/* translators: %s: comma-separated list of reasons. */
			esc_html__( 'Automatic updates cannot run on this site at the moment: %s. Changing the update policy will not help until that is resolved.', 'keel-defaults' ),
			esc_html( implode( '; ', $state['blockers'] ) )
		) . '</p>';
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
				esc_html__( 'The %s constant is deciding this, and Keel will not override it. Change it in wp-config.php, or install the patch once from the Updates screen.', 'keel-defaults' ),
				'<code>WP_AUTO_UPDATE_CORE</code>'
			) . '</p>';
		} else {
			$out .= '<p class="description">'
			. esc_html__( 'Another plugin is filtering this decision, so neither the stored option nor Keel governs it. Whatever registered that filter has to change.', 'keel-defaults' )
			. '</p>';
		}
	}

	$out .= sprintf(
		'<p><a class="button" href="%s">%s</a></p>',
		esc_url( admin_url( 'update-core.php' ) ),
		sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
			esc_html( $tip )
		)
	);

	return $out;
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

	$version = wp_get_wp_version();

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
			esc_html__( 'WordPress %s has known vulnerabilities, and no patched release exists on this release line. Moving to a maintained version is the only remedy.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>'
		);
	} elseif ( $state['policy'] && $state['operable'] ) {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The patch for this release line is %2$s, and automatic updates should install it shortly.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	} elseif ( ! $state['operable'] ) {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version, 3: reasons. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The patch is %2$s, but automatic updates cannot run here: %3$s.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>',
			esc_html( implode( '; ', $state['blockers'] ) )
		);
	} else {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The patch is %2$s, but minor updates are switched off on this site, so it will not arrive on its own.', 'keel-defaults' ),
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

	$current = wp_get_wp_version();
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
 * @return string Version, or '' if nothing would be installed.
 */
function keel_defaults_ladder_selection() {
	$update_file = ABSPATH . 'wp-admin/includes/update.php';

	if ( ! function_exists( 'find_core_auto_update' ) && is_readable( $update_file ) ) {
		require_once $update_file;
	}

	if ( ! function_exists( 'find_core_auto_update' ) ) {
		return '';
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

	$selected = keel_defaults_ladder_selection();
	$rows     = '';

	foreach ( $rungs as $rung ) {
		$kind = $rung['same_line']
			? __( 'security and maintenance, same release line', 'keel-defaults' )
			: __( 'new release line', 'keel-defaults' );

		if ( $rung['delta'] ) {
			$kind .= __( ' — a delta package is available', 'keel-defaults' );
		}

		$rows .= sprintf(
			'<li><code>%1$s</code> — %2$s%3$s</li>',
			esc_html( $rung['version'] ),
			esc_html( $kind ),
			( $selected === $rung['version'] )
				? ' <strong>' . esc_html__( '← WordPress would install this one', 'keel-defaults' ) . '</strong>'
				: ''
		);
	}

	$note = '' === $selected
		? esc_html__( 'Nothing would be installed automatically with the current settings.', 'keel-defaults' )
		: esc_html__( 'WordPress takes the highest release it is permitted to, not the nearest — so the intermediate steps are skipped rather than applied in turn.', 'keel-defaults' );

	return '<p>' . esc_html__( 'WordPress.org is currently offering this site more than one release:', 'keel-defaults' )
		. '</p><ul>' . $rows . '</ul><p class="description">' . $note . '</p>';
}
