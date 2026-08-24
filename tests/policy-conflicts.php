<?php
/**
 * Structural policy-overlap detection and callback non-execution tests.
 *
 * This file is its own process under `composer test`. The terminating fixture
 * deliberately calls exit(97): if detection ever invokes foreign callbacks
 * again, this test process ends before it can print its success marker.
 *
 * Run: php tests/policy-conflicts.php
 *
 * @package keel
 */

$GLOBALS['keel_options']       = array();
$GLOBALS['keel_foreign_calls'] = array();
$GLOBALS['keel_map_reads']     = 0;
$GLOBALS['wp_filter']          = array();

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

/** Minimal WP_Hook-compatible registry that makes accidental execution real. */
class Keel_Test_Hook {
	public $callbacks = array();
	public function __construct( array $callbacks ) { $this->callbacks = $callbacks; }
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
	if ( 'keel_policy_hooks' === $hook ) {
		++$GLOBALS['keel_map_reads'];
	}
	if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && method_exists( $GLOBALS['wp_filter'][ $hook ], 'apply_filters' ) ) {
		return $GLOBALS['wp_filter'][ $hook ]->apply_filters( $value, array_merge( array( $value ), $args ) );
	}
	return $value;
}

define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

// Native filesystem calls are appropriate for this standalone temporary fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
$fixture                       = sys_get_temp_dir() . '/keel-policy-overlaps-' . getmypid();
$sentinel                      = $fixture . '/foreign-callback-ran';
$GLOBALS['keel_exit_sentinel'] = $sentinel;
@mkdir( $fixture . '/rival-plugin', 0777, true );
@mkdir( $fixture . '/passthrough-plugin', 0777, true );

file_put_contents(
	$fixture . '/rival-plugin/plugin.php',
	"<?php\n"
	. "function keel_test_rival_mutates_and_throws( \$value ) { \$GLOBALS['keel_foreign_calls'][] = 'mutated'; throw new RuntimeException( 'must not run' ); }\n"
	. "function keel_test_rival_terminates( \$value ) { file_put_contents( \$GLOBALS['keel_exit_sentinel'], 'ran' ); exit( 97 ); }\n"
	. "function keel_test_rival_mail( \$value ) { \$GLOBALS['keel_foreign_calls'][] = 'mail'; return \$value; }\n"
	. "function keel_test_rival_revision( \$value ) { \$GLOBALS['keel_foreign_calls'][] = 'revision'; return 3; }\n"
	. "class Keel_Test_Static_Callback { public static function run( \$v ) { return \$v; } }\n"
	. "class Keel_Test_Invokable { public function __invoke( \$v ) { return \$v; } }\n"
);
file_put_contents(
	$fixture . '/passthrough-plugin/plugin.php',
	"<?php\nfunction keel_test_passthrough_session( \$value ) { \$GLOBALS['keel_foreign_calls'][] = 'passthrough'; return \$value; }\n"
);
require $fixture . '/rival-plugin/plugin.php';
require $fixture . '/passthrough-plugin/plugin.php';

register_shutdown_function(
	function () use ( $fixture, $sentinel ) {
		if ( file_exists( $sentinel ) ) {
			unlink( $sentinel );
		}
		foreach ( (array) glob( $fixture . '/*/plugin.php' ) as $file ) {
			unlink( $file );
			rmdir( dirname( $file ) );
		}
		@rmdir( $fixture );
	}
);
// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

define( 'WP_PLUGIN_DIR', realpath( $fixture ) );
$GLOBALS['keel_options']['active_plugins'] = array( 'rival-plugin/plugin.php', 'passthrough-plugin/plugin.php' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

$hooks = keel_defaults_policy_hooks();
keel_assert( 'authoritative' === $hooks['auth_cookie_expiration'], 'Session overlap is structural, not effect-probed.' );
keel_assert( 'unconfirmed' === $hooks['pre_wp_mail'], 'Mail callbacks are never executed diagnostically.' );
keel_assert( 'unconfirmed' === $hooks['comments_pre_query'], 'Comment-query callbacks are never executed diagnostically.' );
keel_assert( 'unconfirmed' === $hooks['user_has_cap'], 'Capability callbacks are never executed diagnostically.' );
keel_assert( 'additive' === $hooks['rest_authentication_errors'], 'Compositional authentication results are omitted.' );
$GLOBALS['keel_map_reads'] = 0;

keel_defaults_registered_policy_hooks( 'auth_cookie_expiration', 'keel_defaults_session_length', 50, 3, 'session_regular_days' );
keel_defaults_registered_policy_hooks( 'login_headerurl', 'home_url', 10, 1, 'login_logo_behavior' );
keel_defaults_registered_policy_hooks( 'pre_wp_mail', '__return_false', PHP_INT_MAX, 2, 'suppress_nonproduction_mail' );
keel_defaults_registered_policy_hooks( 'wp_revisions_to_keep', 'keel_defaults_revision_limit', 10, 2, 'post_revisions_limit' );

$records = keel_defaults_registered_policy_hooks();
keel_assert( 'keel_defaults_session_length' === $records['auth_cookie_expiration'][0]['callback'], 'The callback is recorded.' );
keel_assert( 50 === $records['auth_cookie_expiration'][0]['priority'], 'The priority is recorded.' );
keel_assert( 3 === $records['auth_cookie_expiration'][0]['accepted_args'], 'Accepted arguments are recorded.' );
keel_assert( 'session_regular_days' === $records['auth_cookie_expiration'][0]['setting'], 'The governing setting is recorded.' );

$GLOBALS['wp_filter'] = array(
	'auth_cookie_expiration'    => new Keel_Test_Hook(
		array(
			10 => array(
				'passthrough' => array(
					'function'      => 'keel_test_passthrough_session',
					'accepted_args' => 1,
				),
			),
			50 => array(
				'keel' => array(
					'function'      => 'keel_defaults_session_length',
					'accepted_args' => 3,
				),
			),
			60 => array(
				'rival' => array(
					'function'      => 'keel_test_rival_mutates_and_throws',
					'accepted_args' => 1,
				),
			),
		)
	),
	'login_headerurl'           => new Keel_Test_Hook(
		array(
			20 => array(
				'rival' => array(
					'function'      => 'keel_test_rival_terminates',
					'accepted_args' => 1,
				),
			),
		)
	),
	'pre_wp_mail'               => new Keel_Test_Hook(
		array(
			10 => array(
				'rival' => array(
					'function'      => 'keel_test_rival_mail',
					'accepted_args' => 1,
				),
			),
		)
	),
	'wp_post_revisions_to_keep' => new Keel_Test_Hook(
		array(
			10 => array(
				'rival' => array(
					'function'      => 'keel_test_rival_revision',
					'accepted_args' => 2,
				),
			),
		)
	),
	'show_admin_bar'            => new Keel_Test_Hook(
		array(
			10 => array(
				'rival' => array(
					'function'      => 'keel_test_rival_mutates_and_throws',
					'accepted_args' => 1,
				),
			),
		)
	),
);

$registry_before = $GLOBALS['wp_filter'];
$report          = keel_defaults_policy_overlap_report();

keel_assert(
	array( 'passthrough-plugin', 'rival-plugin' ) === $report['structural']['auth_cookie_expiration'],
	'Both attributable callbacks are structural overlaps, including a callback that would pass the value through.'
);
keel_assert( array( 'rival-plugin' ) === $report['structural']['login_headerurl'], 'A terminating callback is detected without invocation.' );
keel_assert( array( 'rival-plugin' ) === $report['structural']['wp_post_revisions_to_keep'], 'A post-type revision override is detected structurally.' );
keel_assert( array( 'rival-plugin' ) === $report['unconfirmed']['pre_wp_mail'], 'Unsafe mail overlap remains informational.' );
keel_assert( ! isset( $report['structural']['show_admin_bar'] ), 'A rival alone is not an overlap when Keel is not registered on the hook.' );
keel_assert( array() === $GLOBALS['keel_foreign_calls'], 'Detection invokes no foreign callback.' );
keel_assert( ! file_exists( $sentinel ), 'Detection does not reach a foreign exit callback.' );
keel_assert( $registry_before === $GLOBALS['wp_filter'], 'Detection does not replace or mutate the live hook registry.' );

$competing = keel_defaults_competing_plugins();
keel_assert( $report['structural'] === $competing, 'The notice API exposes structural overlaps only.' );
keel_assert( array() === $GLOBALS['keel_foreign_calls'], 'Repeated reporting still invokes no foreign callback.' );

keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( 'Keel_Test_Static_Callback::run' ), 'Class::method strings are attributed.' );
keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( array( 'Keel_Test_Static_Callback', 'run' ) ), 'Static callback arrays are attributed.' );
keel_assert( 'rival-plugin' === keel_defaults_callback_plugin_dir( new Keel_Test_Invokable() ), 'Invokable objects are attributed.' );

$health = keel_defaults_site_health_conflicts();
keel_assert( 'recommended' === $health['status'], 'A structural overlap prompts settings review in Site Health.' );
keel_assert( false !== strpos( $health['description'], 'does not prove' ), 'Site Health does not claim an opposing outcome.' );
keel_assert( false !== strpos( $health['description'], 'do not deactivate' ), 'Site Health explicitly rejects deactivation based on presence alone.' );
keel_assert( array() === $GLOBALS['keel_foreign_calls'], 'Site Health invokes no foreign callback.' );
keel_assert( 1 === $GLOBALS['keel_map_reads'], 'The overlap report is computed once per request and reused by every consumer.' );

fwrite( STDOUT, "policy conflicts: OK (foreign callbacks never executed)\n" );
