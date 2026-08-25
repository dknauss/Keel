<?php
/**
 * Passive detection of a Keel setting that is not taking effect.
 *
 * Some plugins turn a feature off by handing one of WordPress's own helpers to a
 * filter. `__return_false` resolves to wp-includes, so reflection cannot say
 * which plugin registered it, and the overlap check stays silent — correctly,
 * because it has nothing to name.
 *
 * It can still say something true. Keel is registered on every hook it governs,
 * so its own callbacks run when WordPress runs the filter, with the real
 * arguments, in the real request. Comparing the value the chain settles on
 * against the value Keel's setting asks for costs a comparison and executes
 * nobody else's code.
 *
 * The distinction this file exists to hold: observing a filter WordPress is
 * already running is not the same as calling one. The second was built, shipped
 * and withdrawn — see the 0.5.1 notes — and nothing here may drift back into it.
 *
 * Run: php tests/policy-divergence.php
 *
 * @package keel
 */

$GLOBALS['keel_options']    = array();
$GLOBALS['keel_transients'] = array();
$GLOBALS['keel_filters']    = array();
$GLOBALS['wp_filter']       = array();
$GLOBALS['keel_foreign']    = 0;

define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/keel-divergence-' . getmypid() );
define( 'KEEL_DEFAULTS_OPTION', 'keel_settings' );
define( 'KEEL_DEFAULTS_NETWORK_OPTION', 'keel_network_settings' );

function add_action( ...$a ) {}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['wp_filter'][ $hook ][ $prio ][] = $cb; }
function register_activation_hook( ...$a ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr( $s ) { return $s; }
function is_multisite() { return false; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function trailingslashit( $p ) { return rtrim( (string) $p, '/\\' ) . '/'; }
function is_customize_preview() { return false; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function get_transient( $k ) {
	++$GLOBALS['keel_transient_reads'];
	return array_key_exists( $k, $GLOBALS['keel_transients'] ) ? $GLOBALS['keel_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) {
	++$GLOBALS['keel_transient_writes'];
	$GLOBALS['keel_transients'][ $k ] = $v;
	return true; }
function delete_transient( $k ) {
	unset( $GLOBALS['keel_transients'][ $k ] );
	return true; }
function apply_filters( $hook, $value ) {
	if ( array_key_exists( $hook, $GLOBALS['keel_filters'] ) ) {
		return $GLOBALS['keel_filters'][ $hook ];
	}
	foreach ( isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : array() as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = call_user_func( $cb, $value );
		}
	}
	return $value; }

require dirname( __DIR__ ) . '/includes/schema.php';
require dirname( __DIR__ ) . '/includes/conflicts.php';

$GLOBALS['keel_transient_writes'] = 0;
$GLOBALS['keel_transient_reads']  = 0;
$fail                             = 0;

function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/** A foreign callback that disables something the untraceable way. */
function keel_divergence_foreign( $value ) {
	++$GLOBALS['keel_foreign'];
	return false;
}

// --- the expectation map is settings-derived, not hardcoded per plugin ---

$expectations = keel_defaults_policy_expectations();

keel_assert( ! empty( $expectations ), 'Hooks with a knowable expected value are declared.' );
keel_assert(
	array_key_exists( 'xmlrpc_enabled', $expectations ),
	'xmlrpc_enabled is one of them — the case that prompted this.'
);

// --- nothing overriding: nothing reported, nothing written ---

$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'yes' );
$writes_before                            = $GLOBALS['keel_transient_writes'];

keel_defaults_observe_policy_result( 'xmlrpc_enabled', true );

keel_assert( array() === keel_defaults_policy_divergences(), 'A setting that takes effect is not reported.' );
keel_assert( $writes_before === $GLOBALS['keel_transient_writes'], 'And nothing is written on a site with no divergence.' );

// --- the reported case: Keel says allow, the chain says no ---

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

$divergences = keel_defaults_policy_divergences();

keel_assert(
	array_key_exists( 'xmlrpc_enabled', $divergences ),
	'A setting that is not producing the configured value is recorded.'
);
keel_assert(
	0 === $GLOBALS['keel_foreign'],
	'And no foreign callback was invoked to find that out.'
);

// --- written once, not on every request ---

$writes_after = $GLOBALS['keel_transient_writes'];
keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );
keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	$writes_after === $GLOBALS['keel_transient_writes'],
	'An unchanged divergence is not rewritten on every request.'
);

/*
 * --- the healthy path touches no storage at all ---
 *
 * This is the cost of the feature on every ordinary request, so it has to be
 * nothing. Reading first, to find out whether a past divergence needed clearing,
 * put a query on every front-end page load — measured, constant, whether or not
 * anything was wrong. Clearing is the record expiring instead.
 */
$GLOBALS['keel_transient_reads'] = 0;
$writes_healthy                  = $GLOBALS['keel_transient_writes'];

keel_defaults_observe_policy_result( 'xmlrpc_enabled', true );

keel_assert(
	0 === $GLOBALS['keel_transient_reads'],
	'A setting that is working reads nothing.'
);
keel_assert(
	$writes_healthy === $GLOBALS['keel_transient_writes'],
	'And writes nothing.'
);

// --- a setting Keel is not governing is not Keel's business ---

$GLOBALS['keel_transients']               = array();
$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'no' );

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	array() === keel_defaults_policy_divergences(),
	'A value Keel itself asked for is not a divergence.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "policy divergence: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

fwrite( STDOUT, "policy divergence: OK (no foreign callback invoked)\n" );
