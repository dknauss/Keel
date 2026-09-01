<?php
/**
 * Pin the logic that decides whether this site is telling the truth about being patched.
 *
 * Three things here are easy to get wrong and expensive to get wrong quietly,
 * so each is pinned rather than trusted:
 *
 * 1. `outdated` is not a failure, and it is not nothing either. A site held on
 *    a maintained older line is patched, so it is never critical — a check that
 *    shouts at a deliberate choice is a check people switch off. But it is not
 *    'good' either: lines are eventually retired, and when this one is, the
 *    same site flips to critical with no patch to offer. It reports
 *    'recommended', which is the level that exists for exactly this.
 *
 * 2. The branch tip must come from the site's own release line, not from the
 *    newest release. A 6.4.9 site needs 6.4.10, not 7.1. Returning "latest"
 *    here would quietly convert a security notice into a major-version nag.
 *
 * 3. A line with no patched release must return an empty tip. Everything below
 *    4.7 is in that state: there is no patch, and offering one would be a lie.
 *    The tempting bug is to fall back to the nearest secure version on another
 *    line, which is the one answer that is worse than saying nothing.
 *
 * The effective-policy check is pinned too, because it is the only part that
 * reads more than one source. WP_AUTO_UPDATE_CORE overrides the option, and the
 * option is read with get_site_option — on Multisite that is the network value,
 * and the per-site row is real, writable, and ignored. A version of this that
 * used get_option would pass every single-site test and be wrong on every
 * network.
 *
 * Run: php tests/backport-status.php
 *
 * @package keel
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$fail = 0;

/**
 * Collect a failure rather than exiting, so one run names every problem.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

// --- Test doubles -----------------------------------------------------------
// Only what backports.php actually reaches for. The network is never touched:
// keel_defaults_stable_check() is short-circuited by a primed transient, which
// is also how it behaves in production on all but one request a day.

$GLOBALS['keel_test'] = array(
	'version'    => '6.8.7',
	'transients' => array(),
	'options'    => array(),
);

/**
 * Stub: the version under test.
 *
 * @return string
 */
function wp_get_wp_version() {
	return $GLOBALS['keel_test']['version'];
}

/**
 * Stub: read from the in-memory transient store.
 *
 * @param string $k Key.
 * @return mixed
 */
function get_site_transient( $k ) {
	if ( 'update_core' === $k ) {
		$offers = keel_test_offers();
		return empty( $offers ) ? false : (object) array( 'updates' => $offers );
	}

	return isset( $GLOBALS['keel_test']['transients'][ $k ] ) ? $GLOBALS['keel_test']['transients'][ $k ] : false;
}

/**
 * Stub: write to the in-memory transient store.
 *
 * @param string $k Key.
 * @param mixed  $v Value.
 * @param int    $t TTL.
 * @return bool
 */
function set_site_transient( $k, $v, $t = 0 ) {
	$GLOBALS['keel_test']['transients'][ $k ] = $v;
	return true;
}

/**
 * Stub: read from the in-memory option store.
 *
 * @param string $k Option.
 * @param mixed  $d Default.
 * @return mixed
 */
function get_site_option( $k, $d = false ) {
	return isset( $GLOBALS['keel_test']['options'][ $k ] ) ? $GLOBALS['keel_test']['options'][ $k ] : $d;
}

/**
 * Stub: whether file modifications are permitted.
 *
 * @param string $c Context.
 * @return bool
 */
function wp_is_file_mod_allowed( $c ) {
	return empty( $GLOBALS['keel_test']['file_mod_blocked'] );
}

/**
 * Stub: return the value unfiltered.
 *
 * @param string $h Hook.
 * @param mixed  $v Value.
 * @return mixed
 */
function apply_filters( $h, $v ) {
	return $v;
}

/**
 * Stub: registration is not exercised here.
 *
 * @param string $h Hook.
 * @param mixed  ...$a Args.
 */
function add_filter( $h, ...$a ) {
	$GLOBALS['keel_test']['filters_added'][] = $h;
}

/**
 * Stub: record filter removal, so the suppression can be shown to be undone.
 *
 * @param string $h Hook.
 * @param mixed  ...$a Args.
 */
function remove_filter( $h, ...$a ) {
	$GLOBALS['keel_test']['filters_removed'][] = $h;
}

/**
 * Stub: whether a named callback is hooked. Keel asks this to find out whether
 * its own policy filter is in play, rather than re-deriving the condition
 * bootstrap.php uses to register it.
 *
 * @param string $h Hook.
 * @param mixed  $cb Callback.
 * @return bool
 */
function has_filter( $h, $cb = false ) {
	return ! empty( $GLOBALS['keel_test']['keel_filter'] );
}

/**
 * Stub: Keel's own minor-update policy callback.
 *
 * @param bool $enabled Incoming value.
 * @return bool
 */
function keel_defaults_allow_minor_core_updates( $enabled ) {
	$policy = isset( $GLOBALS['keel_test']['policy'] ) ? $GLOBALS['keel_test']['policy'] : 'inherit';
	return 'inherit' === $policy ? $enabled : in_array( $policy, array( 'minor', 'all' ), true );
}

/**
 * Stub: registration is not exercised here.
 *
 * @param string $h Hook.
 * @param mixed  ...$a Args.
 */
function add_action( $h, ...$a ) {}

/**
 * Stub: pass the string through untranslated.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function __( $s, $d = '' ) {
	return $s;
}

/**
 * Stub: pass the string through untranslated.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function esc_html__( $s, $d = '' ) {
	return $s;
}

/**
 * Stub: pass the string through unescaped.
 *
 * @param string $s Text.
 * @return string
 */
function esc_html( $s ) {
	return $s;
}

/**
 * Stub: pass the URL through unescaped.
 *
 * @param string $s URL.
 * @return string
 */
function esc_url( $s ) {
	return $s;
}

/**
 * Stub: a predictable admin URL.
 *
 * @param string $s Path.
 * @return string
 */
function admin_url( $s = '' ) {
	return 'https://example.test/wp-admin/' . $s;
}

/**
 * Stub: a nonce URL that is a URL, which is all the assertions need.
 *
 * @param string $url    URL.
 * @param string $action Action.
 * @return string
 */
function wp_nonce_url( $url, $action = -1 ) {
	return $url . '&_wpnonce=test';
}

/**
 * Stub: a predictable home URL.
 *
 * @param string $s Path.
 * @return string
 */
function home_url( $s = '' ) {
	return 'https://example.test' . $s;
}

/**
 * Stub: no capabilities by default, so action markup stays out of the cases
 * that are about state rather than about what gets rendered.
 *
 * Switchable, because a hard false makes any assertion about action markup pass
 * without testing anything: keel_defaults_backport_actions() returns an empty
 * string, and "the output does not contain X" is true of "". A test for copy
 * that should no longer appear is exactly the test that failure mode hides.
 *
 * @param string $c Capability.
 * @return bool
 */
function current_user_can( $c ) {
	return ! empty( $GLOBALS['keel_test']['can'] );
}

/**
 * Stub of core's automatic updater, so the effective-state logic is exercised
 * rather than skipped. Keel asks core for these answers instead of re-deriving
 * them; the test has to supply them for the same reason.
 */
class WP_Automatic_Updater {
	/**
	 * Whether the updater is switched off.
	 *
	 * @return bool
	 */
	public function is_disabled() {
		return ! empty( $GLOBALS['keel_test']['updater_disabled'] );
	}

	/**
	 * Whether the given path is a version control checkout.
	 *
	 * @param string $context Path.
	 * @return bool
	 */
	public function is_vcs_checkout( $context ) {
		return ! empty( $GLOBALS['keel_test']['vcs'] );
	}
}

/**
 * Stub of core's upgrader skin. Without this the credential probe is skipped
 * entirely, which is exactly the arrangement that let a real site report the
 * updater operable without the probe ever running.
 */
class Automatic_Upgrader_Skin {
	/**
	 * Whether filesystem credentials are available.
	 *
	 * @param bool   $error   Unused.
	 * @param string $context Path.
	 * @param bool   $relaxed Whether relaxed file ownership is permitted.
	 * @return bool
	 */
	public function request_filesystem_credentials( $error = false, $context = '', $relaxed = false ) {
		$GLOBALS['keel_test']['relaxed_seen'] = $relaxed;

		// Counted, because this probe is the expensive part of the state and
		// the verdict used to run a whole extra one whose result it discarded.
		$GLOBALS['keel_test']['fs_probes'] = isset( $GLOBALS['keel_test']['fs_probes'] )
			? $GLOBALS['keel_test']['fs_probes'] + 1
			: 1;

		return empty( $GLOBALS['keel_test']['no_fs_credentials'] );
	}
}

/**
 * Stub: the cached core update offers the ladder reads.
 *
 * @param string $k Key.
 * @return mixed
 */
function keel_test_offers() {
	return isset( $GLOBALS['keel_test']['offers'] ) ? $GLOBALS['keel_test']['offers'] : array();
}

/**
 * Stub: core's own selector, so the ladder marks a rung without recomputing it.
 *
 * @return object|false
 */
function get_core_updates( $options = array() ) {
	// Core returns false, not an empty array, when the update_core transient
	// holds no updates list — a site that has not checked yet, or whose
	// transient was cleared. That is "not known", not "not offered".
	if ( ! empty( $GLOBALS['keel_test']['core_updates_uncached'] ) ) {
		return false;
	}

	// Mirrors core: offers flagged autoupdate are skipped, so only the manual
	// upgrade offer appears — which is why a same-line patch is not reachable
	// from that screen.
	//
	// It also mirrors core's dismissal handling, because that is a second way
	// an offer goes missing and it is opt-in. Core defaults to
	// available => true, dismissed => false, and stamps ->dismissed on what it
	// returns; a caller taking the defaults cannot see a hidden offer at all,
	// and cannot tell the two cases apart if it does ask for them.
	$options = array_merge(
		array(
			'available' => true,
			'dismissed' => false,
		),
		$options
	);

	$dismissed = isset( $GLOBALS['keel_test']['dismissed'] ) ? $GLOBALS['keel_test']['dismissed'] : array();
	$out       = array();

	foreach ( keel_test_offers() as $o ) {
		if ( isset( $o->response ) && 'autoupdate' === $o->response ) {
			continue;
		}

		$is_dismissed = in_array( $o->current, $dismissed, true );

		if ( $is_dismissed ) {
			if ( $options['dismissed'] ) {
				$o->dismissed = true;
				$out[]        = $o;
			}
		} elseif ( $options['available'] ) {
			$o->dismissed = false;
			$out[]        = $o;
		}
	}

	return $out;
}

/**
 * Stub: core's own selector.
 *
 * @return object|false
 */
function find_core_auto_update() {
	$v = isset( $GLOBALS['keel_test']['selected'] ) ? $GLOBALS['keel_test']['selected'] : '';
	return '' === $v ? false : (object) array( 'current' => $v );
}

require dirname( __DIR__ ) . '/includes/backports.php';

/**
 * Prime the cached status map so no HTTP request is made.
 *
 * @param array $map Version => status.
 */
function keel_test_prime( array $map ) {
	$GLOBALS['keel_test']['transients'][ KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT ] = $map;
}

$map = array(
	'4.6.1'  => 'insecure',
	'4.6.2'  => 'insecure',   // a line with no patched release at all
	'6.4.9'  => 'insecure',
	'6.4.10' => 'outdated',
	'6.8.7'  => 'insecure',
	'6.8.8'  => 'outdated',
	'7.1'    => 'latest',
);
keel_test_prime( $map );

// --- 1. status classification ----------------------------------------------

$GLOBALS['keel_test']['version'] = '7.1';
keel_assert( 'latest' === keel_defaults_version_status(), 'current release reports latest' );

$GLOBALS['keel_test']['version'] = '6.8.8';
keel_assert( 'outdated' === keel_defaults_version_status(), 'patched branch tip reports outdated, not insecure' );

$GLOBALS['keel_test']['version'] = '6.8.7';
keel_assert( 'insecure' === keel_defaults_version_status(), 'known-vulnerable release reports insecure' );

$GLOBALS['keel_test']['version'] = '6.8.7-alpha-12345-src';
keel_assert( 'unknown' === keel_defaults_version_status(), 'development build is unknown, never insecure' );

$GLOBALS['keel_test']['version'] = '9.9.9';
keel_assert( 'unknown' === keel_defaults_version_status(), 'unlisted version is unknown' );

// --- 2. branch tip comes from the site's own line ---------------------------

$GLOBALS['keel_test']['version'] = '6.4.9';
keel_assert( '6.4.10' === keel_defaults_branch_tip(), 'a 6.4.9 site is offered 6.4.10, not the newest release' );
keel_assert( '7.1' !== keel_defaults_branch_tip(), 'branch tip never crosses to another release line' );

$GLOBALS['keel_test']['version'] = '6.8.8';
keel_assert( '' === keel_defaults_branch_tip(), 'a site already on its branch tip is offered nothing' );

$GLOBALS['keel_test']['version'] = '4.6.2';
keel_assert( '' === keel_defaults_branch_tip(), 'a line with no patched release returns empty, not a cross-line fallback' );

// --- 3. effective policy, not stored policy ---------------------------------

$GLOBALS['keel_test']['version'] = '6.8.7';
$GLOBALS['keel_test']['options'] = array();
keel_assert( true === keel_defaults_minor_updates_enabled(), 'minor updates default to on when nothing is stored' );

$GLOBALS['keel_test']['options']['auto_update_core_minor'] = 'disabled';
keel_assert( false === keel_defaults_minor_updates_enabled(), 'a stored disabled value switches minor updates off' );

$GLOBALS['keel_test']['options']['auto_update_core_minor'] = 'enabled';
$GLOBALS['keel_test']['file_mod_blocked']                  = true;
keel_assert( false === keel_defaults_minor_updates_enabled(), 'DISALLOW_FILE_MODS beats an enabled option' );
$GLOBALS['keel_test']['file_mod_blocked'] = false;

// --- 4. the Site Health verdict people actually see -------------------------

$GLOBALS['keel_test']['version'] = '6.8.8';
$r                               = keel_defaults_backport_test();
keel_assert( 'recommended' === $r['status'], 'patched-but-behind is recommended: not a fault, not settled either' );
keel_assert( 'critical' !== $r['status'], 'patched-but-behind is never critical — it is not vulnerable' );
keel_assert( false !== strpos( $r['description'], '7.1' ), 'the behind-but-patched result names the current release, so the gap is legible' );
keel_assert(
	false === strpos( $r['description'], 'not vulnerable' ),
	'the behind-but-patched result never claims the site is not vulnerable — stable-check only reports whether a version is flagged'
);
keel_assert(
	false !== strpos( $r['description'], 'not a support guarantee' ),
	'the behind-but-patched result says outright that this is not a support guarantee'
);

$GLOBALS['keel_test']['version'] = '7.1';
$r                               = keel_defaults_backport_test();
keel_assert( 'good' === $r['status'], 'only the current release is good' );

$GLOBALS['keel_test']['version'] = '6.8.7';
$r                               = keel_defaults_backport_test();
keel_assert( 'critical' === $r['status'], 'insecure is critical' );
keel_assert( false !== strpos( $r['description'], '6.8.8' ), 'the critical result names the patch to move to' );

$GLOBALS['keel_test']['version'] = '4.6.2';
$r                               = keel_defaults_backport_test();
keel_assert( 'critical' === $r['status'], 'insecure with no patch is still critical' );
keel_assert(
	false === strpos( $r['description'], 'without changing major version' ),
	'a dead release line is not offered a patch that does not exist'
);

$GLOBALS['keel_test']['version'] = '6.8.7-alpha-12345-src';
$r                               = keel_defaults_backport_test();
keel_assert( 'critical' !== $r['status'], 'a development build is never reported as vulnerable' );

// --- 5. ownership: who decides, and does a button make sense ---------------
// The failure this pins: writing auto_update_core_minor when something
// downstream overrides it reports success for a change with no effect.

$GLOBALS['keel_test']['version'] = '6.8.7';
$GLOBALS['keel_test']['options'] = array( 'auto_update_core_minor' => 'disabled' );

$state = keel_defaults_minor_update_state();
keel_assert( false === $state['policy'], 'a disabled option resolves the policy to off' );
keel_assert( 'option' === $state['owner'], 'with nothing else in play, the stored option owns the decision' );
keel_assert( true === $state['operable'], 'no blockers means the updater is operable' );

$GLOBALS['keel_test']['file_mod_blocked'] = true;
$state                                    = keel_defaults_minor_update_state();
keel_assert( false === $state['operable'], 'blocked file modifications make the updater inoperable' );
keel_assert( ! empty( $state['blockers'] ), 'an inoperable updater names at least one blocker' );
$GLOBALS['keel_test']['file_mod_blocked'] = false;

// --- 6. an unrecognised API status must not read as good --------------------
keel_test_prime(
	array(
		'6.8.7' => 'something-new',
		'7.1'   => 'latest',
	)
);
$GLOBALS['keel_test']['version'] = '6.8.7';
keel_assert( 'unknown' === keel_defaults_version_status(), 'an unrecognised status is unknown, never passed through' );
$r = keel_defaults_backport_test();
keel_assert( 'good' !== $r['status'], 'an unrecognised status never reports good' );

// --- 7. unknown states are distinguished -----------------------------------
keel_test_prime( $map );
$GLOBALS['keel_test']['version'] = '6.8.7-alpha-1-src';
$r                               = keel_defaults_backport_test();
keel_assert(
	false !== strpos( $r['description'], 'development build' ),
	'a development build says so, rather than blaming connectivity'
);

$GLOBALS['keel_test']['version'] = '9.9.9';
$r                               = keel_defaults_backport_test();
keel_assert(
	false !== strpos( $r['description'], 'does not list this exact version' ),
	'an unlisted version says so, rather than blaming connectivity'
);


// --- 8. blockers come from core's own answers ------------------------------
// Pinned because the tempting shortcut — re-deriving is_disabled() from the
// constant and the filter separately — produces a verdict core does not share.

keel_test_prime( $map );
$GLOBALS['keel_test']['version'] = '6.8.7';
$GLOBALS['keel_test']['options'] = array();

$GLOBALS['keel_test']['updater_disabled'] = true;
$state                                    = keel_defaults_minor_update_state();
keel_assert( false === $state['operable'], 'a disabled updater makes the state inoperable' );
keel_assert( true === $state['policy'], 'a disabled updater does not change what the policy says' );
$GLOBALS['keel_test']['updater_disabled'] = false;

$GLOBALS['keel_test']['vcs'] = true;
$state                       = keel_defaults_minor_update_state();
keel_assert( false === $state['operable'], 'version control makes the state inoperable' );
$GLOBALS['keel_test']['vcs'] = false;

$state = keel_defaults_minor_update_state();
keel_assert( true === $state['operable'], 'with nothing blocking, the updater is operable' );


// --- 9. Keel's own policy owns the decision when its filter is registered ---
// The failure this pins: copying bootstrap.php's registration condition, or
// Keel's policy comparison, into this file. Either copy drifts silently.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']     = '6.8.7';
$GLOBALS['keel_test']['options']     = array();
$GLOBALS['keel_test']['keel_filter'] = true;
$GLOBALS['keel_test']['policy']      = 'manual';

$state = keel_defaults_minor_update_state();
keel_assert( 'keel' === $state['owner'], 'Keel owns the decision when its filter is registered' );
keel_assert( false === $state['policy'], 'a manual Keel policy resolves minor updates to off' );

$GLOBALS['keel_test']['policy'] = 'minor';
$state                          = keel_defaults_minor_update_state();
keel_assert( true === $state['policy'], 'a minor Keel policy resolves minor updates to on' );

$GLOBALS['keel_test']['keel_filter'] = false;
$state                               = keel_defaults_minor_update_state();
keel_assert( 'option' === $state['owner'], 'without Keel\'s filter, the stored option owns it again' );


// --- 10. the credential probe actually runs, and is not more permissive ----
// Two failures pinned. Loading only WP_Automatic_Updater used to skip the probe
// silently. And passing relaxed ownership unconditionally is more permissive
// than core, which allows it only when the offer reports no new files.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']           = '6.8.7';
$GLOBALS['keel_test']['options']           = array();
$GLOBALS['keel_test']['keel_filter']       = false;
$GLOBALS['keel_test']['relaxed_seen']      = null;
$GLOBALS['keel_test']['no_fs_credentials'] = true;

$state = keel_defaults_minor_update_state();
keel_assert( false === $state['operable'], 'missing filesystem credentials make the updater inoperable' );
keel_assert( null !== $GLOBALS['keel_test']['relaxed_seen'], 'the credential probe actually ran' );
keel_assert( false === $GLOBALS['keel_test']['relaxed_seen'], 'relaxed ownership is not assumed when no offer says so' );

$GLOBALS['keel_test']['no_fs_credentials'] = false;
$state                                     = keel_defaults_minor_update_state();
keel_assert( true === $state['operable'], 'with credentials available the updater is operable again' );

// --- 11. blockers are not duplicated ---------------------------------------
$GLOBALS['keel_test']['file_mod_blocked'] = true;
$GLOBALS['keel_test']['updater_disabled'] = true;
$state                                    = keel_defaults_minor_update_state();
keel_assert( 1 === count( $state['blockers'] ), 'a blocked file mod is reported once, not twice' );
$GLOBALS['keel_test']['file_mod_blocked'] = false;
$GLOBALS['keel_test']['updater_disabled'] = false;


// --- 12. the ladder -------------------------------------------------------
// The point of showing it: WordPress takes the highest permitted offer, so the
// same-line patch a reader assumes they are getting is the one skipped.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']  = '6.8.7';
$GLOBALS['keel_test']['selected'] = '7.1';
$GLOBALS['keel_test']['offers']   = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '7.0.4',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.9.7',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
		'packages' => (object) array( 'partial' => 'https://example.test/partial.zip' ),
	),
);

$rungs = keel_defaults_update_ladder();
keel_assert( 4 === count( $rungs ), 'the ladder lists every autoupdate offer above the current version' );
keel_assert( '6.8.8' === $rungs[0]['version'], 'the ladder is ordered ascending, nearest first' );
keel_assert( '7.1' === $rungs[3]['version'], 'the ladder ends at the current release' );
keel_assert( true === $rungs[0]['same_line'], 'a same-line patch is marked as such' );
keel_assert( false === $rungs[1]['same_line'], 'a cross-line release is not' );
keel_assert( true === $rungs[0]['delta'], 'a delta package is noted where the offer has one' );

$markup = keel_defaults_ladder_markup();
keel_assert( false !== strpos( $markup, '6.8.8' ), 'the markup names the nearest patch' );
keel_assert(
	false !== strpos( $markup, 'install this one' ),
	'the markup marks the rung WordPress would actually take'
);

// A site one line behind has a single rung, and no ladder is worth showing.
$GLOBALS['keel_test']['version'] = '7.0.4';
$GLOBALS['keel_test']['offers']  = array(
	(object) array(
		'response' => 'autoupdate',
		'current'  => '7.1',
	),
);
keel_assert( 1 === count( keel_defaults_update_ladder() ), 'one line behind gives one rung' );
keel_assert( '' === keel_defaults_ladder_markup(), 'a single rung renders nothing — there is no choice to show' );

$GLOBALS['keel_test']['offers'] = array();


// --- 13. asking which rung wins must not email anybody ---------------------
// find_core_auto_update() runs every offer through should_update(), which
// emails the administrator on rejection. A reporting call that notifies people
// is not reporting, so the notification is suppressed and then restored.

$GLOBALS['keel_test']['filters_added']   = array();
$GLOBALS['keel_test']['filters_removed'] = array();
$GLOBALS['keel_test']['selected']        = '7.1';

keel_defaults_ladder_selection();

keel_assert(
	in_array( 'send_core_update_notification_email', $GLOBALS['keel_test']['filters_added'], true ),
	'the update notification is suppressed before asking which rung wins'
);
keel_assert(
	in_array( 'send_core_update_notification_email', $GLOBALS['keel_test']['filters_removed'], true ),
	'the suppression is removed again afterwards, not left in place'
);


// --- 14. never send people to a screen that cannot deliver ----------------
// Found on a real 6.9.5 site: the panel offered "Install 6.9.7 from the Updates
// screen", but get_core_updates() skips autoupdate offers, so that screen lists
// only 7.1. Following the button would have installed three release lines
// instead of a patch.

keel_test_prime( $map );
$GLOBALS['keel_test']['version'] = '6.8.7';
$GLOBALS['keel_test']['offers']  = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

keel_assert(
	'none' === keel_defaults_updates_screen_offer( '6.8.8' )['state'],
	'a same-line patch is not reachable from the Updates screen'
);
keel_assert(
	'visible' === keel_defaults_updates_screen_offer( '7.1' )['state'],
	'the newest release is reachable from the Updates screen'
);


// --- 15. a dismissed offer is hidden, not absent -------------------------
// get_core_updates() takes dismissed => false by default, so an offer someone
// dismissed comes back missing rather than flagged. Reading that as "the
// Updates screen will not offer this" is wrong twice over: the screen still
// lists it under "Show hidden updates", and the copy for the absent case goes
// on to say the screen would install something else instead — which, when the
// dismissed offer IS the newest release, names the very version being hidden.

$GLOBALS['keel_test']['offers']    = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
);
$GLOBALS['keel_test']['dismissed'] = array( '7.1' );

keel_assert(
	'hidden' === keel_defaults_updates_screen_offer( '7.1' )['state'],
	'a dismissed offer is reported as hidden rather than absent'
);

$GLOBALS['keel_test']['dismissed'] = array();

keel_assert(
	'visible' === keel_defaults_updates_screen_offer( '7.1' )['state'],
	'the same offer is visible once it is no longer dismissed'
);

$GLOBALS['keel_test']['offers'] = array();


// --- 17. one panel says the blocker once -----------------------------------
// Found by reading the real Site Health output on a 6.9.5 site. Three separate
// blocks each named the same cause in full — "automatic updates are switched
// off by the AUTOMATIC_UPDATER_DISABLED constant, normally set in wp-config.php"
// appeared three times in one panel, and "this will not install by itself" was
// said five ways. Each block was written to stand alone, which is right when it
// is shown alone and wrong when they are concatenated.
//
// The panel is verdict, then ladder, then actions. Only the verdict states the
// cause in full now; the other two refer to the kind of problem without
// restating it, so each still reads on its own.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']          = '6.8.7';
$GLOBALS['keel_test']['options']          = array( 'auto_update_core_minor' => 'disabled' );
$GLOBALS['keel_test']['updater_disabled'] = true;
$GLOBALS['keel_test']['can']              = true;
$GLOBALS['keel_test']['selected']         = '';
$GLOBALS['keel_test']['offers']           = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

$state = keel_defaults_minor_update_state();
keel_assert( false === $state['operable'], 'the fixture actually produces a blocked updater' );

$result = keel_defaults_backport_test();
$panel  = $result['description'] . keel_defaults_backport_actions( '6.8.8' );

$blocker = 'automatic updates are switched off';

keel_assert(
	1 === substr_count( $panel, $blocker ),
	sprintf( 'the panel names the blocking cause once, not %d times', substr_count( $panel, $blocker ) )
);
keel_assert(
	1 === substr_count( $panel, 'will not install by itself' ),
	'the panel says a patch will not arrive on its own once'
);
keel_assert(
	false === strpos( $panel, 'Someone has to install it.' ),
	'the consequence is not restated as its own sentence'
);
keel_assert(
	false !== strpos( $panel, '<code>6.8.8</code> will not install by itself' ),
	'the version in that sentence carries code markup like every other version in the panel'
);

$GLOBALS['keel_test']['updater_disabled'] = false;
$GLOBALS['keel_test']['offers']           = array();
$GLOBALS['keel_test']['can']              = false;


// --- 18. do not tell a working site to resume what it never stopped -------
// The same failure as 16, one block further on. When the policy permits minor
// updates and the updater is operable, the verdict says the patch should
// install on a scheduled check — and this block then said reaching it means
// "letting automatic updates resume". They have not stopped. That is the
// ordinary window between a release being offered and cron getting to it, and
// it is the state a correctly configured site is in.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']          = '6.8.7';
$GLOBALS['keel_test']['options']          = array( 'auto_update_core_minor' => 'enabled' );
$GLOBALS['keel_test']['updater_disabled'] = false;
$GLOBALS['keel_test']['can']              = true;
$GLOBALS['keel_test']['offers']           = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

// Core has to have selected the patch for the scheduled check to install it.
// This fixture originally left the selection unset and relied on policy &&
// operable, which is the assumption test 20 exists to reject.
$GLOBALS['keel_test']['selected'] = '6.8.8';

$state = keel_defaults_minor_update_state();
keel_assert( true === $state['policy'], 'the fixture permits minor updates' );
keel_assert( true === $state['operable'], 'the fixture has an operable updater' );

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'letting automatic updates resume' ),
	'a site whose automatic updates are running is not told to resume them'
);
keel_assert(
	false !== strpos( $actions, 'scheduled check' ),
	'it is told the scheduled check will install it, which is what the verdict above says'
);

// The same sentence still has to be there for a site that HAS switched them
// off, which is the case the wording was written for. Core selects nothing on
// such a site, so the stubbed selection has to go with the policy.
$GLOBALS['keel_test']['options']  = array( 'auto_update_core_minor' => 'disabled' );
$GLOBALS['keel_test']['selected'] = '';

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false !== strpos( $actions, 'letting automatic updates resume' ),
	'a site with minor updates switched off is still told resuming them is a route'
);

$GLOBALS['keel_test']['offers'] = array();
$GLOBALS['keel_test']['can']    = false;


// --- 19. missing update data is not proof of a missing offer -------------
// get_core_updates() returns false when the update_core transient has no
// updates list. Reading that as "the Updates screen will not offer this" turns
// an absence of data into a categorical claim — and the copy for that case goes
// on to say the screen would install the newest release instead, which on a
// site whose same-line patch IS the newest release names the version it is
// denying. Visiting update-core.php refreshes the data and may well offer it.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']               = '6.8.7';
$GLOBALS['keel_test']['can']                   = true;
$GLOBALS['keel_test']['core_updates_uncached'] = true;

keel_assert(
	'unknown' === keel_defaults_updates_screen_offer( '6.8.8' )['state'],
	'no cached update data reports unknown, not none'
);

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'will not offer' ),
	'an unknown state does not claim the Updates screen will not offer the patch'
);
keel_assert(
	false === strpos( $actions, 'instead' ),
	'an unknown state does not claim the screen would install something else instead'
);
keel_assert(
	false !== strpos( $actions, 'has not checked' ),
	'an unknown state says the data is missing, which is the thing actually known'
);

$GLOBALS['keel_test']['core_updates_uncached'] = false;
$GLOBALS['keel_test']['can']                   = false;


// --- 20. the route agrees with the ladder about what cron will do --------
// The route said "wait for the scheduled check to install <tip>" from
// policy && operable alone, which does not identify the selected offer. On a
// site with majors enabled too, core selects the highest rung — so the ladder
// marked 7.1 as the winner and the sentence underneath promised 6.8.8. Ask
// keel_defaults_ladder_selection(), which is already the component that knows.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']          = '6.8.7';
$GLOBALS['keel_test']['options']          = array( 'auto_update_core_minor' => 'enabled' );
$GLOBALS['keel_test']['updater_disabled'] = false;
$GLOBALS['keel_test']['can']              = true;
$GLOBALS['keel_test']['offers']           = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

// Core selects a higher rung than the patch.
$GLOBALS['keel_test']['selected'] = '7.1';

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'waiting for the scheduled check to install it' ),
	'the route does not promise cron will install the patch when core selected a different release'
);
keel_assert(
	false !== strpos( $actions, 'and skip' ),
	'the route says cron will install that release and skip the patch'
);

// Core selects the patch itself.
$GLOBALS['keel_test']['selected'] = '6.8.8';

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false !== strpos( $actions, 'waiting for the scheduled check to install it' ),
	'the route does promise the scheduled check when core selected the patch'
);

// Core selects nothing at all.
$GLOBALS['keel_test']['selected'] = '';

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'waiting for the scheduled check' ),
	'the route promises no scheduled installation when core would install nothing'
);


// --- 21. the substitute named is the one the screen actually offers ------
// The "would install X instead" version came from stable-check's idea of the
// latest release, while the screen's offer comes from the update_core
// transient. Those refresh independently, so a valid but older cache offers a
// different version than stable-check names — and Keel would name a version
// the screen is not offering.

$GLOBALS['keel_test']['selected'] = '';
$GLOBALS['keel_test']['offers']   = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.0.4',   // what the screen actually offers
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

$offer = keel_defaults_updates_screen_offer( '6.8.8' );

keel_assert( 'none' === $offer['state'], 'the patch is still not reachable from the screen' );
keel_assert( '7.0.4' === $offer['manual'], 'the helper reports the offer the screen actually shows' );

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false !== strpos( $actions, '7.0.4' ),
	'the copy names the version the screen offers'
);
keel_assert(
	false === strpos( $actions, '7.1' ),
	'the copy does not name stable-check\'s latest release, which the screen is not offering'
);


// --- 22. the verdict does not run a probe it throws away -----------------
// $auto = keel_defaults_minor_updates_enabled() was assigned and never read.
// It runs the filesystem-credential and version-control checks in full, on
// every verdict including good and unknown ones, and the branches below then
// run them again.

$GLOBALS['keel_test']['offers']    = array();
$GLOBALS['keel_test']['version']   = '7.1';   // 'latest' — the cheapest verdict
$GLOBALS['keel_test']['fs_probes'] = 0;

keel_defaults_backport_verdict();

keel_assert(
	0 === $GLOBALS['keel_test']['fs_probes'],
	sprintf( 'a settled verdict probes the filesystem 0 times, not %d', $GLOBALS['keel_test']['fs_probes'] )
);

$GLOBALS['keel_test']['can'] = false;


// --- 23. an empty selection is not proof that updates are switched off ---
// find_core_auto_update() returns nothing for two unrelated reasons: the policy
// declines everything, or the policy is fine and the cached update_core offers
// simply do not contain the patch yet. stable-check and update_core refresh
// independently, so a site can know about 6.8.8 from one cache while the other
// still predates it. Reading the empty selector as "switched off" tells a site
// whose automation is running to resume it — the same error as test 18, one
// level further down, reintroduced by the fix for it.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']          = '6.8.7';
$GLOBALS['keel_test']['options']          = array( 'auto_update_core_minor' => 'enabled' );
$GLOBALS['keel_test']['updater_disabled'] = false;
$GLOBALS['keel_test']['can']              = true;
$GLOBALS['keel_test']['selected']         = '';

// A stale offers list: it predates the patch, so nothing permitted is in it.
$GLOBALS['keel_test']['offers'] = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
);

$state = keel_defaults_minor_update_state();
keel_assert( true === $state['policy'], 'the fixture permits minor updates' );
keel_assert( true === $state['operable'], 'the fixture has an operable updater' );

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'letting automatic updates resume' ),
	'a site with automation running is not told to resume it because the cache is stale'
);
keel_assert(
	false === strpos( $actions, 'waiting for the scheduled check to install it' ),
	'nor is a scheduled installation promised, because core has selected nothing'
);
keel_assert(
	false !== strpos( $actions, 'has not been offered' ),
	'it says what is actually true: WordPress has not been offered the patch yet'
);

// The same empty selection, on a site that HAS switched updates off, still
// gets the sentence about resuming them.
$GLOBALS['keel_test']['options'] = array( 'auto_update_core_minor' => 'disabled' );

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false !== strpos( $actions, 'letting automatic updates resume' ),
	'a site that really has switched updates off is still told resuming them is a route'
);

$GLOBALS['keel_test']['offers'] = array();
$GLOBALS['keel_test']['can']    = false;


// --- 24. an absent offer and a declined offer are different things -------
// Test 23 stopped reading an empty selection as "updates are switched off".
// It then read it as "the cached offers predate the patch" — which is one
// reason among several. find_core_auto_update() also returns nothing when the
// offer IS cached and something downstream declines it: the auto_update_core
// filter, an unmet PHP or MySQL requirement on that offer, disable_autoupdate,
// a recorded non-critical failure for that version, or the selector failing to
// load. Naming the cache in those cases diagnoses the wrong thing.
//
// So the raw offer decides which sentence applies, and neither sentence
// guesses which downstream gate did the declining.

keel_test_prime( $map );
$GLOBALS['keel_test']['version']          = '6.8.7';
$GLOBALS['keel_test']['options']          = array( 'auto_update_core_minor' => 'enabled' );
$GLOBALS['keel_test']['updater_disabled'] = false;
$GLOBALS['keel_test']['can']              = true;
$GLOBALS['keel_test']['selected']         = '';

// The patch IS in the offers, and core still selected nothing.
$GLOBALS['keel_test']['offers'] = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false === strpos( $actions, 'has not been offered' ),
	'a cached offer is not described as one the site has never been offered'
);
keel_assert(
	false === strpos( $actions, 'predates it' ),
	'nor is the cache blamed when the cache plainly contains the release'
);
keel_assert(
	false === strpos( $actions, 'letting automatic updates resume' ),
	'and automation that is running is still not told to resume'
);
keel_assert(
	false !== strpos( $actions, 'not currently scheduling' ),
	'it says core is not scheduling it, which is the whole of what is known'
);

// With the offer absent, the cache explanation is the right one again.
$GLOBALS['keel_test']['offers'] = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
);

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	false !== strpos( $actions, 'has not been offered' ),
	'an absent offer is still explained as one the site has not been offered yet'
);

$GLOBALS['keel_test']['offers'] = array();
$GLOBALS['keel_test']['can']    = false;


// --- 16. the constant-owner action does not send anyone to that screen ----
// WP_AUTO_UPDATE_CORE is a real constant and cannot be undefined, so this runs
// last. The branch it covers used to end "or install the patch once from the
// Updates screen" — the exact advice the rest of this file exists to withdraw,
// left behind on the one path that never went through the shared check.

keel_test_prime( $map );
$GLOBALS['keel_test']['version'] = '6.8.7';
$GLOBALS['keel_test']['offers']  = array(
	(object) array(
		'response' => 'upgrade',
		'current'  => '7.1',
	),
	(object) array(
		'response' => 'autoupdate',
		'current'  => '6.8.8',
	),
);

define( 'WP_AUTO_UPDATE_CORE', false );

$state = keel_defaults_minor_update_state();
keel_assert( 'constant' === $state['owner'], 'the constant owns the decision once defined' );
keel_assert( false === $state['policy'], 'WP_AUTO_UPDATE_CORE=false resolves minor updates to off' );

$GLOBALS['keel_test']['can'] = true;

$actions = keel_defaults_backport_actions( '6.8.8' );

keel_assert(
	'' !== $actions,
	'the action markup is actually rendered, so the assertions below test something'
);

keel_assert(
	false === strpos( $actions, 'install the patch once from the Updates screen' ),
	'the constant-owner action does not recommend the Updates screen'
);
keel_assert(
	false !== strpos( $actions, 'WP_AUTO_UPDATE_CORE' ),
	'the constant-owner action still names the constant to change'
);
keel_assert(
	false !== strpos( $actions, 'will not offer' ),
	'the constant-owner action falls through to the shared availability result'
);

$GLOBALS['keel_test']['offers'] = array();

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "backport-status: all assertions passed\n";
