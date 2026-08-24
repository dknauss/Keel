<?php
/**
 * Effect-based, tri-state policy-overlap tests.
 *
 * Run: php tests/policy-conflicts.php
 *
 * @package keel
 */

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
function wp_json_encode( $v, $f = 0 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	return json_encode( $v, $f );
}
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function is_multisite() { return false; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function get_post( $id ) { return (object) array(
	'ID'        => (int) $id,
	'post_type' => 'post',
); }

/** A small WP_Hook-compatible registry for standalone effect probes. */
class Keel_Test_Hook {
	public $callbacks = array();
	public function __construct( array $callbacks ) { $this->callbacks = $callbacks; }
	public function remove_filter( $hook, $callback, $priority ) {
		unset( $hook );
		foreach ( isset( $this->callbacks[ $priority ] ) ? $this->callbacks[ $priority ] : array() as $id => $registered ) {
			if ( $registered['function'] === $callback ) {
				unset( $this->callbacks[ $priority ][ $id ] );
			}
		}
		if ( empty( $this->callbacks[ $priority ] ) ) {
			unset( $this->callbacks[ $priority ] );
		}
	}
	public function apply_filters( $value, $args ) {
		foreach ( $this->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$args[0] = $value;
				$value   = call_user_func_array(
					$registered['function'],
					array_slice( $args, 0, isset( $registered['accepted_args'] ) ? $registered['accepted_args'] : 1 )
				);
			}
		}
		return $value;
	}
}

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && method_exists( $GLOBALS['wp_filter'][ $hook ], 'apply_filters' ) ) {
		return $GLOBALS['wp_filter'][ $hook ]->apply_filters( $value, array_merge( array( $value ), $args ) );
	}
	return $value;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// Native filesystem calls are appropriate for this standalone temporary fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
$fixture = sys_get_temp_dir() . '/keel-policy-effects-' . getmypid();
@mkdir( $fixture . '/rival-plugin', 0777, true );
@mkdir( $fixture . '/compatible-plugin', 0777, true );

file_put_contents(
	$fixture . '/rival-plugin/plugin.php',
	"<?php\nfunction keel_test_rival_short_session( \$value ) { return 60; }\nfunction keel_test_rival_revision( \$value ) { return 3; }\nfunction keel_test_rival_mail( \$value ) { return \$value; }\nclass Keel_Test_Static_Callback { public static function run( \$v ) { return \$v; } }\nclass Keel_Test_Invokable { public function __invoke( \$v ) { return \$v; } }\n"
);
file_put_contents(
	$fixture . '/compatible-plugin/plugin.php',
	"<?php\nfunction keel_test_compatible_session( \$value ) { return \$value; }\n"
);
require $fixture . '/rival-plugin/plugin.php';
require $fixture . '/compatible-plugin/plugin.php';

register_shutdown_function(
	function () use ( $fixture ) {
		foreach ( glob( $fixture . '/*/plugin.php' ) as $file ) {
			unlink( $file );
			rmdir( dirname( $file ) );
		}
		@rmdir( $fixture );
	}
);
// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

define( 'WP_PLUGIN_DIR', realpath( $fixture ) );
$GLOBALS['keel_options']['active_plugins'] = array( 'rival-plugin/plugin.php', 'compatible-plugin/plugin.php' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

$hooks = keel_defaults_policy_hooks();
keel_assert( 'probe' === $hooks['auth_cookie_expiration'], 'Session overlap is evaluated by effect.' );
keel_assert( 'unconfirmed' === $hooks['pre_wp_mail'], 'Mail callbacks are never replayed blindly.' );
keel_assert( 'unconfirmed' === $hooks['comments_pre_query'], 'Comment-query callbacks are final-value filters but unsafe to replay.' );
keel_assert( 'additive' === $hooks['rest_authentication_errors'], 'REST authentication callback presence is compositional, not an accusation.' );
keel_assert( 'unconfirmed' === $hooks['user_has_cap'], 'Capability source mentions no longer prove direction.' );

keel_defaults_registered_policy_hooks( 'auth_cookie_expiration', 'keel_defaults_session_length', 50, 3, 'session_regular_days' );
$records = keel_defaults_registered_policy_hooks();
keel_assert( 'keel_defaults_session_length' === $records['auth_cookie_expiration'][0]['callback'], 'The callback is recorded.' );
keel_assert( 50 === $records['auth_cookie_expiration'][0]['priority'], 'The priority is recorded.' );
keel_assert( 3 === $records['auth_cookie_expiration'][0]['accepted_args'], 'Accepted arguments are recorded.' );
keel_assert( 'session_regular_days' === $records['auth_cookie_expiration'][0]['setting'], 'The governing setting is recorded.' );

$GLOBALS['keel_options'][ KEEL_DEFAULTS_OPTION ] = array(
	'session_regular_days' => 30,
	'remember_me_days'     => 60,
);
$GLOBALS['wp_filter']['auth_cookie_expiration']  = new Keel_Test_Hook(
	array(
		10 => array(
			'compatible' => array(
				'function'      => 'keel_test_compatible_session',
				'accepted_args' => 1,
			),
		),
		50 => array(
			'keel' => array(
				'function'      => 'keel_defaults_session_length',
				'accepted_args' => 3,
			),
		),
	)
);
$report = keel_defaults_policy_overlap_report();
keel_assert( array( 'compatible-plugin' ) === $report['compatible']['auth_cookie_expiration'], 'A rival that leaves Keel\'s governed result unchanged is compatible.' );
keel_assert( empty( $report['confirmed'] ), 'Compatible callback presence creates no actionable collision.' );

$GLOBALS['wp_filter']['auth_cookie_expiration']->callbacks[60] = array(
	'rival' => array(
		'function'      => 'keel_test_rival_short_session',
		'accepted_args' => 1,
	),
);
$report = keel_defaults_policy_overlap_report();
keel_assert( array( 'rival-plugin' ) === $report['confirmed']['auth_cookie_expiration'], 'A reproduced opposing final value is confirmed.' );
keel_assert( array( 'rival-plugin' ) === keel_defaults_competing_plugins()['auth_cookie_expiration'], 'Only confirmed effects reach the actionable compatibility API.' );

keel_defaults_registered_policy_hooks( 'pre_wp_mail', '__return_false', PHP_INT_MAX, 2, 'suppress_nonproduction_mail' );
$GLOBALS['wp_filter']['pre_wp_mail'] = new Keel_Test_Hook(
	array(
		10 => array(
			'rival' => array(
				'function'      => 'keel_test_rival_mail',
				'accepted_args' => 1,
			),
		),
	)
);
$report                              = keel_defaults_policy_overlap_report();
keel_assert( array( 'rival-plugin' ) === $report['unconfirmed']['pre_wp_mail'], 'Unsafe mail overlap is informational.' );
keel_assert( ! isset( $report['confirmed']['pre_wp_mail'] ), 'Unsafe mail overlap cannot recommend deactivation.' );

keel_defaults_registered_policy_hooks( 'wp_revisions_to_keep', 'keel_defaults_revision_limit', 10, 2, 'post_revisions_limit' );
$GLOBALS['keel_options'][ KEEL_DEFAULTS_OPTION ]['post_revisions_limit'] = 10;
$GLOBALS['wp_filter']['wp_revisions_to_keep']                            = new Keel_Test_Hook(
	array(
		10 => array(
			'keel' => array(
				'function'      => 'keel_defaults_revision_limit',
				'accepted_args' => 2,
			),
		),
	)
);
$GLOBALS['wp_filter']['wp_post_revisions_to_keep']                       = new Keel_Test_Hook(
	array(
		10 => array(
			'rival' => array(
				'function'      => 'keel_test_rival_revision',
				'accepted_args' => 2,
			),
		),
	)
);
$report = keel_defaults_policy_overlap_report();
keel_assert( array( 'rival-plugin' ) === $report['confirmed']['wp_post_revisions_to_keep'], 'A post-type revision override is detected by its final effect.' );

keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( 'Keel_Test_Static_Callback::run' ), 'Class::method strings are attributed.' );
keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( array( 'Keel_Test_Static_Callback', 'run' ) ), 'Static callback arrays are attributed.' );
keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( new Keel_Test_Invokable() ), 'Invokable objects are attributed.' );

$health = keel_defaults_site_health_conflicts();
keel_assert( 'recommended' === $health['status'], 'A confirmed effect is actionable in Site Health.' );
keel_assert( false !== strpos( $health['description'], 'safe effect probe' ), 'The report explains why the warning is justified.' );

fwrite( STDOUT, "policy conflicts: OK\n" );
