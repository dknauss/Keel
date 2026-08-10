<?php
/**
 * Lightweight regression test for the breach-screening (HIBP) response handling.
 *
 * Covers the parts that decide whether a range response can be trusted at all:
 * the transport-boundary check, the well-formedness check, and the padded-row
 * rule. A response that fails any of them must read as "not breached" (fail
 * open) rather than being parsed or cached.
 *
 * Run: php tests/hibp.php
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
$GLOBALS['keel_object_cache'] = array();
function wp_cache_get( $key, $group = '' ) {
	return array_key_exists( $group . ':' . $key, $GLOBALS['keel_object_cache'] ) ? $GLOBALS['keel_object_cache'][ $group . ':' . $key ] : false;
}
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	$GLOBALS['keel_object_cache'][ $group . ':' . $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}

/*
 * --- transport and cache stubs, so keel_hibp_lookup() can be run end to end ---
 *
 * Until now this file only exercised the pure helpers. The lookup itself — the
 * part that decides what gets written to the cache — had no coverage at all.
 */
$GLOBALS['keel_transients']    = array();
$GLOBALS['keel_http_calls']    = 0;
$GLOBALS['keel_http_reply']    = array(
	'code' => 200,
	'body' => '',
);
$GLOBALS['keel_http_lastargs'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['keel_transients'] ) ? $GLOBALS['keel_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['keel_transients'][ $key ] = $value;
	return true;
}
function wp_remote_get( $url, $args = array() ) {
	++$GLOBALS['keel_http_calls'];
	$GLOBALS['keel_http_lastargs'] = $args;
	return $GLOBALS['keel_http_reply'];
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) && isset( $r['code'] ) ? $r['code'] : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

require_once __DIR__ . '/stubs/wp-error.php';

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Build a syntactically valid range line for a suffix.
 *
 * @param string $suffix 35-character SHA-1 suffix.
 * @param int    $count  Breach count (0 = padded row).
 * @return string
 */
function keel_range_line( $suffix, $count ) {
	return $suffix . ':' . $count;
}

$hit    = str_repeat( 'A', 35 );
$other  = str_repeat( 'B', 35 );
$padded = str_repeat( 'C', 35 );

// --- response limit ---
keel_assert( 131072 === keel_hibp_response_limit(), 'Default limit is 128KB.' );
$GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] = 10;
keel_assert( 1024 === keel_hibp_response_limit(), 'Limit is floored at 1KB, so a filter cannot make every response look truncated.' );
unset( $GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] );

// --- transport boundary ---
keel_assert( true === keel_hibp_body_is_complete( 'abc', 10 ), 'A short body is complete.' );
keel_assert( false === keel_hibp_body_is_complete( str_repeat( 'x', 10 ), 10 ), 'A body at the cap is treated as truncated.' );
keel_assert( false === keel_hibp_body_is_complete( str_repeat( 'x', 11 ), 10 ), 'A body over the cap is truncated.' );
// The case the untrimmed check exists for: capped body ending exactly on CRLF.
$capped = str_repeat( 'x', 8 ) . "\r\n";
keel_assert( false === keel_hibp_body_is_complete( $capped, 10 ), 'A capped body ending on CRLF is still truncated (checked before trimming).' );

// --- well-formedness ---
$valid = keel_range_line( $hit, 12 ) . "\r\n" . keel_range_line( $other, 3 );
keel_assert( true === keel_hibp_body_is_valid( $valid ), 'A CRLF range body is valid.' );
keel_assert( true === keel_hibp_body_is_valid( str_replace( "\r\n", "\n", $valid ) ), 'An LF range body is valid.' );
keel_assert( true === keel_hibp_body_is_valid( strtolower( $valid ) ), 'Lower-case hex is valid.' );
keel_assert( false === keel_hibp_body_is_valid( '' ), 'An empty body is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( '<html><body>502 Bad Gateway</body></html>' ), 'An HTML error page served with a 200 is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( $valid . "\r\n" . substr( $padded, 0, 20 ) . ':' ), 'A partial trailing line is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( keel_range_line( substr( $hit, 0, 34 ), 1 ) ), 'A short suffix is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( $hit . ':x' ), 'A non-numeric count is not valid.' );

// --- match semantics ---
$body = implode(
	"\r\n",
	array(
		keel_range_line( $other, 5 ),
		keel_range_line( $padded, 0 ),
		keel_range_line( $hit, 1 ),
	)
);
keel_assert( true === keel_hibp_range_contains( $body, $hit ), 'A row with a real count is a match.' );
keel_assert( true === keel_hibp_range_contains( $body, strtolower( $hit ) ), 'Suffix comparison is case-insensitive.' );
keel_assert( false === keel_hibp_range_contains( $body, $padded ), 'A padded row (count 0) is not a match.' );
keel_assert( false === keel_hibp_range_contains( $body, str_repeat( 'D', 35 ) ), 'An absent suffix is not a match.' );

// --- opt-out plumbing (no network) ---
define( 'KEEL_DISABLE_HIBP', true );
keel_assert( false === keel_password_is_pwned( 'correct horse battery staple' ), 'The constant skips the lookup and reports not-breached.' );

$GLOBALS['keel_filters']['keel_password_is_pwned'] = true;
keel_assert( true === keel_password_is_pwned( 'anything' ), 'The verdict filter still applies when the lookup is disabled, so a local blocklist works.' );
unset( $GLOBALS['keel_filters']['keel_password_is_pwned'] );

// --- Jetpack awareness: warn where it applies, nowhere else ---
keel_assert( '' === keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ), 'With Jetpack absent, the endpoint block carries no warning.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'disable_rest' ), 'The warning belongs to one setting, not to every setting.' );

define( 'JETPACK__VERSION', '14.0' );
keel_assert( '' !== keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ), 'With Jetpack active, the endpoint block warns.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'disable_rest' ), 'Even then the warning stays on its own setting.' );

/*
 * --- what reaches the cache, and what must never ---
 *
 * Raised by the sibling plugin's review: a breach check that caches a negative
 * verdict from a well-formed-but-empty 200 lets a hostile or compromised upstream
 * hold "not breached" for a whole prefix until the entry expires.
 *
 * Keel does not cache a verdict — it caches the range body, and only after the
 * body has passed a completeness and a syntax check, both of which an empty body
 * fails. So the reported shape does not reach it. That is worth an assertion
 * rather than a reading: the bail and the cache write are four lines apart, and
 * nothing stopped a refactor from reordering them.
 */
function keel_hibp_reset( $code, $body ) {
	$GLOBALS['keel_transients']   = array();
	$GLOBALS['keel_object_cache'] = array();
	$GLOBALS['keel_http_calls']   = 0;
	$GLOBALS['keel_http_reply']   = array(
		'code' => $code,
		'body' => $body,
	);
}

/**
 * Whether anything was written to either cache layer.
 *
 * @return bool
 */
function keel_hibp_anything_cached() {
	return ! empty( $GLOBALS['keel_transients'] ) || ! empty( $GLOBALS['keel_object_cache'] );
}

$live_suffix = strtoupper( substr( sha1( 'correct-horse-battery-staple-9271' ), 5 ) );

// The reported case: a 200 whose body is empty.
keel_hibp_reset( 200, '' );
keel_assert( false === keel_hibp_lookup( 'correct-horse-battery-staple-9271' ), 'An empty 200 reports "not breached" — the deliberate fail-open.' );
keel_assert( ! keel_hibp_anything_cached(), 'An empty 200 is never cached, so one bad reply cannot hold a prefix open.' );

// Same question for a body that is not a range list at all.
keel_hibp_reset( 200, '<html>captive portal</html>' );
keel_assert( false === keel_hibp_lookup( 'correct-horse-battery-staple-9271' ), 'A malformed 200 fails open.' );
keel_assert( ! keel_hibp_anything_cached(), 'A malformed 200 is never cached.' );

// And for a transport failure.
keel_hibp_reset( 0, '' );
$GLOBALS['keel_http_reply'] = new WP_Error( 'http_request_failed', 'down' );
keel_assert( false === keel_hibp_lookup( 'correct-horse-battery-staple-9271' ), 'An unreachable API fails open.' );
keel_assert( ! keel_hibp_anything_cached(), 'An unreachable API is never cached.' );

/*
 * A body cut off at the response limit is the dangerous one: the missing tail is
 * indistinguishable from "no match", so a real match silently reads as clean. It
 * must not be trusted and must not be stored.
 *
 * The body is built to reach the cap exactly — keel_hibp_body_is_complete() is a
 * `strlen < limit` test — and it is otherwise perfectly well formed, so nothing
 * but the length check can reject it. The first draft of this case reset the
 * cache and asserted it was empty without ever calling the lookup, which passed
 * against a deliberately broken build.
 */
$GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] = 1024;
$limit  = keel_hibp_response_limit();
$line   = keel_range_line( str_repeat( 'B', 35 ), 2 );
$rows   = (int) floor( $limit / ( strlen( $line ) + 1 ) ) + 2;
$at_cap = implode( "\n", array_fill( 0, $rows, $line ) );

keel_assert( strlen( $at_cap ) >= $limit, 'The fixture really does reach the transport cap (' . strlen( $at_cap ) . ' >= ' . $limit . ').' );
keel_assert( keel_hibp_body_is_valid( trim( $at_cap ) ), 'The fixture is otherwise well formed, so only the length check can reject it.' );

keel_hibp_reset( 200, $at_cap );
keel_assert( false === keel_hibp_lookup( 'correct-horse-battery-staple-9271' ), 'A body at the truncation boundary fails open.' );
keel_assert( ! keel_hibp_anything_cached(), 'A body at the truncation boundary is never cached.' );
unset( $GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] );

// The positive path still works, and a good body IS cached — otherwise the two
// assertions above would pass on a lookup that simply never caches anything.
keel_hibp_reset( 200, keel_range_line( $live_suffix, 42 ) );
keel_assert( true === keel_hibp_lookup( 'correct-horse-battery-staple-9271' ), 'A listed suffix with a non-zero count is reported breached.' );
keel_assert( keel_hibp_anything_cached(), 'A whole, well-formed body IS cached — the guard above is not passing vacuously.' );

// And the cache is actually used, rather than re-requesting every time.
$before = $GLOBALS['keel_http_calls'];
keel_hibp_lookup( 'correct-horse-battery-staple-9271' );
keel_assert( $GLOBALS['keel_http_calls'] === $before, 'A second lookup for the same prefix is served from cache.' );

/*
 * TLS verification is what makes the hostile-upstream case require a real
 * compromise rather than a coffee-shop network. Keel never passes sslverify, so
 * WordPress's default (true) stands; this fails if anyone ever adds it as false.
 */
keel_hibp_reset( 200, keel_range_line( $live_suffix, 1 ) );
keel_hibp_lookup( 'correct-horse-battery-staple-9271' );
keel_assert(
	! array_key_exists( 'sslverify', $GLOBALS['keel_http_lastargs'] ) || false !== $GLOBALS['keel_http_lastargs']['sslverify'],
	'The breach lookup never disables TLS verification.'
);
keel_assert(
	isset( $GLOBALS['keel_http_lastargs']['headers']['Add-Padding'] ),
	'The lookup asks for padding, so response size cannot reveal how many real matches a prefix had.'
);

echo "hibp: OK\n";
