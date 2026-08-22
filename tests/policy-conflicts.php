<?php
/**
 * Regression test for overlapping-defaults detection.
 *
 * The behaviour is a Site Health report; the part worth pinning is the
 * classification — which hooks count as contested, and which foreign code
 * counts as a competing plugin. Get either wrong and the check is noise people
 * learn to ignore.
 *
 * Run: php tests/policy-conflicts.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();
$GLOBALS['wp_filter']    = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function is_customize_preview() { return ! empty( $GLOBALS['keel_is_customize_preview'] ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }

/*
 * Keel reads network policy before the site option, so every harness that calls
 * keel_defaults_get() needs this. Single site is the honest default here: the
 * multisite path has its own coverage in tests/network-policy.php.
 */
function is_multisite() {
	return false;
}

define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

/*
 * A plugins directory with something actually in it.
 *
 * `keel_defaults_callback_plugin_dir()` resolves a callback by the file its code
 * lives in and keeps the first path segment below WP_PLUGIN_DIR. Pointed at a
 * path nothing resolves inside — as this harness used to be — every callback
 * attributes to nothing, so only negative assertions were possible and the
 * positive case went uncovered for the life of the check.
 *
 * Two one-line files under a temporary root fix that: one in a rival plugin's
 * directory, one in a directory named the same as Keel's own, since the check
 * identifies itself by `basename( dirname( KEEL_DEFAULTS_FILE ) )`. The
 * callbacks do nothing; only the file they live in is read.
 */
$keel_fixture_root = sys_get_temp_dir() . '/keel-policy-conflicts-' . getmypid();
$keel_self_dir     = basename( dirname( dirname( __DIR__ ) . '/keel.php' ) );

foreach ( array(
	'rival-plugin' => 'keel_test_rival_callback',
	$keel_self_dir => 'keel_test_self_callback',
) as $keel_dir => $keel_fn ) {
	@mkdir( $keel_fixture_root . '/' . $keel_dir, 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a temp fixture in a CLI test, not a WordPress filesystem write.
	file_put_contents( $keel_fixture_root . '/' . $keel_dir . '/plugin.php', "<?php\nfunction {$keel_fn}() {}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as above.
	require $keel_fixture_root . '/' . $keel_dir . '/plugin.php';
}

register_shutdown_function(
	function () use ( $keel_fixture_root ) {
		foreach ( (array) glob( $keel_fixture_root . '/*/plugin.php' ) as $keel_file ) {
			unlink( $keel_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink -- removing this test's own fixture; wp_delete_file() is not loaded in a CLI test.
			rmdir( dirname( $keel_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- as above.
		}
		@rmdir( $keel_fixture_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- as above.
	}
);

/*
 * realpath(), because the prefix comparison is literal. On macOS
 * sys_get_temp_dir() answers /var/folders/... while Reflection reports the file
 * as /private/var/folders/... — the same directory by two names, and a string
 * compare of one against the other resolves every callback to nothing, which
 * looks exactly like "no conflicts" rather than like a broken fixture.
 */
define( 'WP_PLUGIN_DIR', realpath( $keel_fixture_root ) );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/** Minimal stand-in for WP_Hook. */
class Keel_Test_Hook {
	public $callbacks = array();
	public function __construct( array $callbacks ) {
		$this->callbacks = $callbacks;
	}
}

// --- the hook map ---
$hooks = keel_defaults_policy_hooks();
keel_assert( 'authoritative' === $hooks['auth_cookie_expiration'], 'Session length is authoritative: the callback replaces its input, so two cannot both win.' );
keel_assert( 'additive' === $hooks['wp_headers'], 'Headers are additive: callbacks add keys, so sharing is normal.' );

$GLOBALS['keel_filters']['keel_policy_hooks'] = array( 'auth_cookie_expiration' => 'authoritative' );
keel_assert( 1 === count( keel_defaults_policy_hooks() ), 'The hook map is filterable.' );
unset( $GLOBALS['keel_filters']['keel_policy_hooks'] );

// --- attribution ---
keel_assert( '' === keel_defaults_callback_plugin_dir( 'a_function_that_does_not_exist' ), 'An unresolvable callback is attributed to nothing.' );
keel_assert( '' === keel_defaults_callback_plugin_dir( '__return_false' ), 'Core code resolves outside WP_PLUGIN_DIR and is ignored.' );

// --- classification ---
$GLOBALS['wp_filter'] = array(
	'auth_cookie_expiration' => new Keel_Test_Hook( array( 10 => array( array( 'function' => '__return_false' ) ) ) ),
);
keel_assert( array() === keel_defaults_competing_plugins(), 'Core callbacks on a contested hook are not reported as a competing plugin.' );

// An additive hook is never reported however crowded it gets — otherwise the
// check flags every well-behaved plugin that sets a header, and gets ignored.
$GLOBALS['wp_filter']['wp_headers'] = new Keel_Test_Hook( array( 10 => array( array( 'function' => 'strlen' ), array( 'function' => 'strtolower' ) ) ) );
keel_assert( ! array_key_exists( 'wp_headers', keel_defaults_competing_plugins() ), 'An additive hook is never reported.' );

$GLOBALS['wp_filter'] = array();
keel_assert( array() === keel_defaults_competing_plugins(), 'An empty filter registry produces no conflicts.' );

/*
 * --- a conflict needs two sides, and one of them has to be Keel ---
 *
 * The check walks the contested hooks and reports foreign plugins on them. What
 * it never asked was whether Keel is on that hook at all — and Keel stands down
 * on several. `auth_cookie_expiration` is registered only when the session
 * policy differs from WordPress's own, so on a site that left it alone, another
 * plugin setting a session length was reported as "more than one plugin is
 * setting the same defaults" when exactly one was.
 *
 * That is the shape of false positive that makes a check ignorable, and it
 * scales with the size of the hook map: every hook added multiplies it. So it
 * is fixed before the map grows.
 *
 * These stub a callback in a plugin directory the way the real registry holds
 * it — the resolver reads the file a callback lives in, so the two sides are
 * told apart by where their code is, not by what they are called.
 */
$GLOBALS['wp_filter'] = array(
	'auth_cookie_expiration' => new Keel_Test_Hook(
		array( 10 => array( array( 'function' => 'keel_test_rival_callback' ) ) )
	),
);

keel_assert(
	array() === keel_defaults_competing_plugins(),
	'A rival alone on a contested hook is not a conflict — Keel is not setting it, so nothing is contested.'
);

// The other side of the same rule, which nothing covered either: with Keel on
// the hook too, the rival is reported. A self-presence check that reported
// nothing at all would have passed every assertion above this line.
$GLOBALS['wp_filter']['auth_cookie_expiration'] = new Keel_Test_Hook(
	array(
		10 => array( array( 'function' => 'keel_test_rival_callback' ) ),
		50 => array( array( 'function' => 'keel_test_self_callback' ) ),
	)
);

$both = keel_defaults_competing_plugins();

keel_assert(
	isset( $both['auth_cookie_expiration'] ),
	'With Keel on the hook as well, the rival is reported.'
);
keel_assert(
	array( 'rival-plugin' ) === $both['auth_cookie_expiration'],
	'The report names the rival and not Keel itself.'
);

$GLOBALS['wp_filter'] = array();

// --- the report reads as informational, and never picks a winner ---
$clear = keel_defaults_site_health_conflicts();
keel_assert( 'good' === $clear['status'], 'With no rivals the check passes.' );
keel_assert( false === strpos( $clear['description'], 'deactivate' ), 'A passing result does not tell anyone to deactivate anything.' );


/*
 * --- compare or assert: the rule, not the two behaviours ---
 *
 * These two filters look inconsistent and are not. The roadmap carried the
 * disagreement as an open question for days because both readings are defensible
 * until you ask what an incoming value proves on each hook.
 *
 *   wp_headers              core never seeds X-Frame-Options into the array —
 *                           send_frame_options_header() calls header() directly —
 *                           so a value present in it means a layer decided on one.
 *                           Evidence. Rank it, and never downgrade.
 *
 *   auth_cookie_expiration  core always passes a number (2 or 14 days, from
 *                           wp_set_auth_cookie), so an incoming value proves
 *                           nothing and cannot be told apart from another
 *                           plugin's. No evidence. Assert, and let the settings
 *                           screen stay true.
 *
 * The failure this guards against is a tidying refactor that makes both filters
 * agree in style. Either direction is a real regression and neither would fail a
 * test that only checked current outputs, so these assert the *reason*.
 */
$GLOBALS['keel_options']['keel_settings'] = array(
	'session_regular_days' => 30,
	'remember_me_days'     => 60,
);

// The case that dies if session length starts comparing: core hands 14 days, the
// site asked for 60. min() or any "never lengthen" clamp returns core's 14 and the
// settings screen goes on claiming 60.
keel_assert(
	60 * DAY_IN_SECONDS === keel_defaults_session_length( 14 * DAY_IN_SECONDS, 1, true ),
	'A session longer than core\'s own default is honoured — the incoming value is not a ceiling.'
);
keel_assert(
	30 * DAY_IN_SECONDS === keel_defaults_session_length( 2 * DAY_IN_SECONDS, 1, false ),
	'The same for an ordinary login.'
);


/*
 * And the incoming value must not sway the result at all, in either direction —
 * a "defer to whoever is stricter" version would pass the two above and fail here.
 *
 * Once per exit, not once. keel_defaults_session_length() returns from three
 * places — the Remember Me disabled branch, the remembered branch, and the
 * ordinary fall-through — and a clamp added to any one of them is the same
 * regression. A single check happened to exercise only the fall-through, and a
 * min() planted on the disabled branch passed it.
 */
$session_exits = array(
	'Remember Me disabled' => array( array( 'disable_remember_me' => 'yes' ), true ),
	'a remembered login'   => array( array(), true ),
	'an ordinary login'    => array( array(), false ),
);

foreach ( $session_exits as $exit => $case ) {
	list( $extra, $remember ) = $case;

	$GLOBALS['keel_options']['keel_settings'] = array_merge(
		array(
			'session_regular_days' => 30,
			'remember_me_days'     => 60,
		),
		$extra
	);

	keel_assert(
		keel_defaults_session_length( 1, 1, $remember ) === keel_defaults_session_length( PHP_INT_MAX, 1, $remember ),
		"The incoming expiration does not influence the result at the '{$exit}' exit, however large or small."
	);
}

// The mirror on the comparing side: a stronger incoming value survives. This is
// already covered in tests/headers.php; it is restated here because the pair is
// the point, and a reader who changes one should meet the other.
$GLOBALS['keel_options']['keel_settings'] = array( 'frame_options' => 'SAMEORIGIN' );
$r                                        = keel_defaults_set_frame_option_header( array( 'X-Frame-Options' => 'DENY' ) );
keel_assert( 'DENY' === $r['X-Frame-Options'], 'A stronger incoming header IS evidence, and is not downgraded.' );


/*
 * --- the one place the evidence misleads, and it is core ---
 *
 * WP_Customize_Manager::filter_iframe_security_headers() sets SAMEORIGIN on the
 * previewed front end at priority 10 so the preview loads in its iframe. Keel runs
 * at 99. A site configured DENY escalated core's value, and the preview kept
 * working only because core sets frame-ancestors 'self' in the same call and CSP
 * takes precedence over X-Frame-Options wherever both are present.
 *
 * Relying on that is relying on a second header to undo the first. "Stronger" is
 * the wrong question when the incoming value was set to make a feature work rather
 * than to state a posture.
 */
$GLOBALS['keel_options']['keel_settings'] = array( 'frame_options' => 'DENY' );
$customizer                               = array(
	'X-Frame-Options'         => 'SAMEORIGIN',
	'Content-Security-Policy' => "frame-ancestors 'self'",
);

$GLOBALS['keel_is_customize_preview'] = false;
$r                                    = keel_defaults_set_frame_option_header( $customizer );
keel_assert( 'DENY' === $r['X-Frame-Options'], 'Outside the Customizer a configured DENY still tightens.' );

$GLOBALS['keel_is_customize_preview'] = true;
$r                                    = keel_defaults_set_frame_option_header( $customizer );
keel_assert(
	$customizer === $r,
	'Inside a Customizer preview the headers are left exactly as core set them.'
);
$GLOBALS['keel_is_customize_preview'] = false;

echo "policy conflicts: OK\n";
