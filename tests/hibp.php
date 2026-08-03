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

echo "hibp: OK\n";
