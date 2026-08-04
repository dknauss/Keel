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
$GLOBALS['wp_filter']   = array();

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
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', '/srv/site/wp-content/plugins' );

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

// --- the report reads as informational, and never picks a winner ---
$clear = keel_defaults_site_health_conflicts();
keel_assert( 'good' === $clear['status'], 'With no rivals the check passes.' );
keel_assert( false === strpos( $clear['description'], 'deactivate' ), 'A passing result does not tell anyone to deactivate anything.' );

echo "policy conflicts: OK\n";
