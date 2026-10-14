<?php
/**
 * Core backports: policy.
 *
 * Loaded by backports.php; public callback names and behavior are unchanged.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
			'text' => __( 'file changes are blocked, normally because the <code>DISALLOW_FILE_MODS</code> constant is set in <code>wp-config.php</code>', 'keel-defaults' ),
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
				'text' => __( 'automatic updates are switched off by the <code>AUTOMATIC_UPDATER_DISABLED</code> constant, normally set in <code>wp-config.php</code>', 'keel-defaults' ),
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
				'text' => __( 'a plugin or theme on this site switches automatic updates off, using the <code>automatic_updater_disabled</code> filter', 'keel-defaults' ),
			);
		} else {
			$blockers[] = array(
				'code' => 'automatic_disabled_unknown',
				'text' => __( 'automatic updates are switched off, but not by a constant or filter Keel can identify', 'keel-defaults' ),
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
 * The descriptions may name a constant or file in <code>, so print them through
 * wp_kses() allowing code. esc_html() turns that markup into visible tags.
 *
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
		 * Promise a deliberate install only where the installer would perform one.
		 * Its refusals — file_mods, credentials, vcs — close that route, and
		 * DISALLOW_FILE_MODS stops WP-CLI too, so naming a route there would name one
		 * that is also closed. A switched-off automatic updater or an earlier critical
		 * failure stop only the scheduled updater; saying Keel would not offer an
		 * install there sat directly above a working Install button.
		 */
		if ( keel_defaults_installer_refusals( $state['blockers'] ) ) {
			return sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Nothing will install on its own while the updater cannot act, whatever WordPress core would otherwise select. Keel will not offer a deliberate install of %s from here until that is cleared.', 'keel-defaults' ),
				$code
			);
		}

		return sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Nothing will install on its own while automatic updates are held back here, whatever WordPress core would otherwise select. %s can still be installed deliberately from the command line.', 'keel-defaults' ),
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
