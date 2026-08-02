<?php
/**
 * Lightweight regression test for the security-header logic.
 *
 * Run: php tests/headers.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

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
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// --- find_header_key (case-insensitive) ---
keel_assert( 'X-Frame-Options' === keel_defaults_find_header_key( array( 'X-Frame-Options' => 'DENY' ), 'x-frame-options' ), 'Case-insensitive key match.' );
keel_assert( null === keel_defaults_find_header_key( array(), 'X-Frame-Options' ), 'Missing header returns null.' );

// --- X-Content-Type-Options ---
$r = keel_defaults_set_content_type_header( array( 'Existing' => 'h' ) );
keel_assert( 'nosniff' === $r['X-Content-Type-Options'] && 'h' === $r['Existing'], 'Sets nosniff, preserves others.' );
$r = keel_defaults_set_content_type_header( array( 'X-Content-Type-Options' => 'off' ) );
keel_assert( 'nosniff' === $r['X-Content-Type-Options'] && 1 === count( $r ), 'Ineffective value corrected in place.' );
$r = keel_defaults_set_content_type_header( array( 'x-content-type-options' => 'nosniff' ) );
keel_assert( ! isset( $r['X-Content-Type-Options'] ) && 'nosniff' === $r['x-content-type-options'], 'Defers to an existing nosniff without duplicating.' );
$GLOBALS['keel_filters']['keel_disable_x_content_type_options'] = true;
$r = keel_defaults_set_content_type_header( array() );
keel_assert( ! isset( $r['X-Content-Type-Options'] ), 'Disable filter omits nosniff.' );
unset( $GLOBALS['keel_filters']['keel_disable_x_content_type_options'] );

// --- Referrer-Policy ---
$r = keel_defaults_set_referrer_policy_header( array() );
keel_assert( 'strict-origin-when-cross-origin' === $r['Referrer-Policy'], 'Default Referrer-Policy.' );
$r = keel_defaults_set_referrer_policy_header( array( 'referrer-policy' => 'no-referrer' ) );
keel_assert( ! isset( $r['Referrer-Policy'] ) && 'no-referrer' === $r['referrer-policy'], 'Defers to an existing policy without duplicating.' );
$GLOBALS['keel_filters']['keel_referrer_policy'] = 'no-referrer';
$r = keel_defaults_set_referrer_policy_header( array() );
keel_assert( 'no-referrer' === $r['Referrer-Policy'], 'Value is filterable.' );
$GLOBALS['keel_filters']['keel_referrer_policy'] = '   ';
$r = keel_defaults_set_referrer_policy_header( array() );
keel_assert( ! isset( $r['Referrer-Policy'] ), 'An emptied value opts out rather than sending an empty header.' );
unset( $GLOBALS['keel_filters']['keel_referrer_policy'] );

// --- X-Frame-Options (strictness) ---
$r = keel_defaults_set_frame_option_header( array() );
keel_assert( 'SAMEORIGIN' === $r['X-Frame-Options'], 'Default X-Frame-Options is SAMEORIGIN (from the schema).' );
$r = keel_defaults_set_frame_option_header( array( 'X-Frame-Options' => 'DENY' ) );
keel_assert( 'DENY' === $r['X-Frame-Options'], 'A stronger existing DENY is not downgraded.' );
$GLOBALS['keel_filters']['keel_x_frame_options'] = 'DENY';
$r = keel_defaults_set_frame_option_header( array( 'X-Frame-Options' => 'SAMEORIGIN' ) );
keel_assert( 'DENY' === $r['X-Frame-Options'] && 1 === count( $r ), 'A configured DENY tightens a weaker existing value.' );
$r = keel_defaults_set_frame_option_header( array( 'x-frame-options' => 'SAMEORIGIN' ) );
keel_assert( 'DENY' === $r['x-frame-options'] && ! isset( $r['X-Frame-Options'] ), 'Tightening writes back to the existing key, no duplicate.' );
unset( $GLOBALS['keel_filters']['keel_x_frame_options'] );
$r = keel_defaults_set_frame_option_header( array( 'X-Frame-Options' => 'ALLOW-FROM https://x' ) );
keel_assert( 'ALLOW-FROM https://x' === $r['X-Frame-Options'], 'An unrecognised (permissive) value is left alone.' );
$GLOBALS['keel_filters']['keel_disable_x_frame_options'] = true;
$r = keel_defaults_set_frame_option_header( array( 'a' => 'b' ) );
keel_assert( array( 'a' => 'b' ) === $r, 'Disable filter omits X-Frame-Options.' );
unset( $GLOBALS['keel_filters']['keel_disable_x_frame_options'] );

fwrite( STDOUT, "headers tests passed.\n" );
