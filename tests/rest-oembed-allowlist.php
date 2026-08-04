<?php
/**
 * Regression test for the oEmbed carve-out in the REST gate.
 *
 * The gate's whole value is that it fails closed, so a carve-out is the most
 * dangerous kind of change to make to it. These assertions exist to pin the
 * boundary: oEmbed passes, everything else still 401s, and a prefix cannot be
 * widened by a route that merely starts with the same characters.
 *
 * Run: php tests/rest-oembed-allowlist.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']  = array();
$GLOBALS['keel_options']  = array();
$GLOBALS['keel_loggedin'] = false;
$GLOBALS['wp']            = null;

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
function is_user_logged_in() { return (bool) $GLOBALS['keel_loggedin']; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** Minimal WP_Error stand-in. */
class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
	}
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Put a route on the request the way rest_api_loaded() does.
 *
 * @param string|null $route Route, or null for a non-REST request.
 */
function keel_set_route( $route ) {
	$GLOBALS['wp'] = new stdClass();
	$GLOBALS['wp']->query_vars = ( null === $route ) ? array() : array( 'rest_route' => $route );
}

// --- the carve-out ---
foreach ( array( '/oembed/1.0', '/oembed/1.0/embed', 'oembed/1.0/embed' ) as $route ) {
	keel_set_route( $route );
	keel_assert( true === keel_defaults_rest_route_is_public(), "oEmbed route '{$route}' is public, with or without a leading slash." );
	keel_assert( null === keel_defaults_require_rest_auth( null ), "Anonymous request to '{$route}' is allowed through." );
}

// --- everything else still fails closed ---
foreach ( array( '/wp/v2/users', '/wp/v2/posts', '/', '/wp/v2/comments' ) as $route ) {
	keel_set_route( $route );
	keel_assert( false === keel_defaults_rest_route_is_public(), "Route '{$route}' is not public." );
	$r = keel_defaults_require_rest_auth( null );
	keel_assert( $r instanceof WP_Error && 'rest_not_logged_in' === $r->code, "Anonymous request to '{$route}' is still refused." );
}

// A route that merely starts with the same characters must not slip through.
keel_set_route( '/oembed/1.0-internal/secrets' );
keel_assert( false === keel_defaults_rest_route_is_public(), 'Prefix matching respects a path boundary.' );

// No route at all is not public — a missing route must not open the gate.
keel_set_route( null );
keel_assert( false === keel_defaults_rest_route_is_public(), 'A request with no parsed route is not treated as public.' );

// --- an existing error is never overridden ---
keel_set_route( '/oembed/1.0/embed' );
$existing = new WP_Error( 'something_else' );
keel_assert( $existing === keel_defaults_require_rest_auth( $existing ), 'A WP_Error from another plugin survives the carve-out.' );

// --- the list is filterable, and emptying it restores the strict gate ---
$GLOBALS['keel_filters']['keel_public_rest_routes'] = array();
keel_set_route( '/oembed/1.0/embed' );
keel_assert( false === keel_defaults_rest_route_is_public(), 'Emptying the allowlist closes oEmbed again.' );
$GLOBALS['keel_filters']['keel_public_rest_routes'] = array( '/wp/v2/posts' );
keel_set_route( '/wp/v2/posts/1' );
keel_assert( true === keel_defaults_rest_route_is_public(), 'A site can widen the allowlist deliberately.' );
unset( $GLOBALS['keel_filters']['keel_public_rest_routes'] );

// --- logged-in requests are unaffected ---
$GLOBALS['keel_loggedin'] = true;
keel_set_route( '/wp/v2/users' );
keel_assert( null === keel_defaults_require_rest_auth( null ), 'A logged-in request passes as before.' );

echo "rest oembed allowlist: OK\n";
