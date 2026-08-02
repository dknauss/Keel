<?php
/**
 * Lightweight regression test for cross-setting dependencies.
 *
 * Run: php tests/dependencies.php
 *
 * @package keel
 */

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$schema = keel_defaults_schema();

// The three XML-RPC method toggles are moot when the endpoint is fully blocked.
foreach ( array( 'xmlrpc_allow_pingbacks', 'xmlrpc_allow_remote_publishing', 'xmlrpc_allow_multicall' ) as $k ) {
	keel_assert( isset( $schema[ $k ]['depends'] ), "$k declares a dependency." );
	keel_assert( 'block_xmlrpc_endpoint' === $schema[ $k ]['depends']['field'], "$k depends on block_xmlrpc_endpoint." );
	keel_assert( 'yes' === $schema[ $k ]['depends']['hide_when'], "$k hides when the endpoint is blocked." );
}

// Remember Me length is moot when Remember Me is disabled.
keel_assert( isset( $schema['remember_me_days']['depends'] ), 'remember_me_days declares a dependency.' );
keel_assert( 'disable_remember_me' === $schema['remember_me_days']['depends']['field'], 'remember_me_days depends on disable_remember_me.' );

// Every dependency points at a real field, and controllers have no dependency of
// their own (no dangling references, no self/cyclic dependency).
foreach ( $schema as $key => $field ) {
	if ( empty( $field['depends'] ) ) {
		continue;
	}
	$ctrl = $field['depends']['field'];
	keel_assert( isset( $schema[ $ctrl ] ), "$key depends on a real field ($ctrl)." );
	keel_assert( $ctrl !== $key, "$key does not depend on itself." );
	keel_assert( empty( $schema[ $ctrl ]['depends'] ), "controller $ctrl has no dependency of its own (no chains)." );
}

fwrite( STDOUT, "dependencies tests passed.\n" );
