<?php
/**
 * Lightweight regression test for reserved-username creation blocking.
 *
 * Run: php tests/reserved-usernames.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// High-value names are reserved.
$list = keel_reserved_usernames_list();
foreach ( array( 'admin', 'administrator', 'root', 'www', 'test' ) as $name ) {
	keel_assert( in_array( $name, $list, true ), "Reserved list includes '{$name}'." );
}

// Entries are lower-case (core compares case-insensitively; keep both sides lc).
keel_assert( array_map( 'strtolower', $list ) === $list, 'Reserved list is all lower-case.' );

// Merge preserves an illegal login from another source, and adds ours.
$merged = keel_defaults_reserved_usernames( array( 'someexistingban' ) );
keel_assert( in_array( 'someexistingban', $merged, true ), 'Existing illegal logins are preserved.' );
keel_assert( in_array( 'admin', $merged, true ), 'Reserved names are added to the merge.' );

// A misbehaving source passing non-array is coerced safely, not fatal.
$merged = keel_defaults_reserved_usernames( null );
keel_assert( in_array( 'admin', $merged, true ), 'Non-array input is coerced and merged.' );

// The list is filterable (extend or trim).
$GLOBALS['keel_filters']['keel_reserved_usernames'] = array( 'onlythis' );
keel_assert( array( 'onlythis' ) === keel_reserved_usernames_list(), 'keel_reserved_usernames filter controls the list.' );
unset( $GLOBALS['keel_filters']['keel_reserved_usernames'] );

fwrite( STDOUT, "reserved usernames tests passed.\n" );
