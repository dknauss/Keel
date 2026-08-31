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
function add_filter( $h, ...$a ) {}

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
 * Stub: a predictable home URL.
 *
 * @param string $s Path.
 * @return string
 */
function home_url( $s = '' ) {
	return 'https://example.test' . $s;
}

/**
 * Stub: no capabilities, so action markup stays out of these cases.
 *
 * @param string $c Capability.
 * @return bool
 */
function current_user_can( $c ) {
	return false;   // Actions are asserted separately; keep markup out of these cases.
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

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "backport-status: all assertions passed\n";
