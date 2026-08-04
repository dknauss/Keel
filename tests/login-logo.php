<?php
/**
 * Regression test for the login-logo replacement.
 *
 * Covers which image is chosen, and the escaping context. The escaping is the
 * part worth pinning: the URL goes inside a url() token in a <style> element,
 * which is raw text, so esc_url() is actively wrong there.
 *
 * Run: php tests/login-logo.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']     = array();
$GLOBALS['keel_options']     = array();
$GLOBALS['keel_theme_mods']  = array();
$GLOBALS['keel_attachments'] = array();
$GLOBALS['keel_site_icon']   = '';

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
function get_theme_mod( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_theme_mods'] ) ? $GLOBALS['keel_theme_mods'][ $key ] : $default;
}
function wp_get_attachment_image_src( $id, $size = 'full' ) {
	return array_key_exists( $id, $GLOBALS['keel_attachments'] ) ? $GLOBALS['keel_attachments'][ $id ] : false;
}
function get_site_icon_url( $size = 512 ) {
	return $GLOBALS['keel_site_icon'];
}
/**
 * Stand in for esc_url_raw(): validate the scheme, do not entity-encode.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test stub.
	if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	return $url;
}
function esc_url( $url ) { return str_replace( '&', '&amp;', esc_url_raw( $url ) ); }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Capture the printed style block.
 *
 * @return string
 */
function keel_logo_output() {
	ob_start();
	keel_defaults_login_logo_styles();
	return (string) ob_get_clean();
}

// --- which image wins ---
$GLOBALS['keel_site_icon'] = 'https://example.ca/icon-192.png';
keel_assert( 'https://example.ca/icon-192.png' === keel_defaults_login_logo_url(), 'With no Customizer logo, the site icon is used.' );

$GLOBALS['keel_theme_mods']['custom_logo'] = 7;
$GLOBALS['keel_attachments'][7]            = array( 'https://example.ca/wordmark.png', 320, 84 );
keel_assert( 'https://example.ca/wordmark.png' === keel_defaults_login_logo_url(), 'The Customizer logo wins over the site icon.' );

// A theme mod pointing at a deleted attachment must not strand the logo.
$GLOBALS['keel_attachments'] = array();
keel_assert( 'https://example.ca/icon-192.png' === keel_defaults_login_logo_url(), 'A custom_logo id whose attachment is gone falls back to the site icon.' );

$GLOBALS['keel_theme_mods'] = array();
$GLOBALS['keel_site_icon']  = '';
keel_assert( '' === keel_defaults_login_logo_url(), 'With neither set, there is no replacement image.' );
keel_assert( '' === keel_logo_output(), 'With no image, nothing is printed — an empty url() would re-request the login page as an image.' );

// --- escaping context ---
// The bug this replaces: esc_url() turns & into &amp;, and <style> is raw text,
// so the browser would request the literal "&amp;" and get no image.
$GLOBALS['keel_site_icon'] = 'https://example.ca/logo.png?v=2&size=full';
$out                       = keel_logo_output();
keel_assert( false === strpos( $out, '&amp;' ), 'The URL is not entity-encoded; a <style> element is raw text.' );
keel_assert( false !== strpos( $out, 'v=2&size=full' ), 'The query string survives intact.' );

// A URL cannot close the url() token and open a new declaration.
$GLOBALS['keel_site_icon'] = "https://example.ca/a')}#login h1 a{background-image:url('https://evil.test/x.png";
$out                       = keel_logo_output();
keel_assert( false === strpos( $out, "url('https://evil.test" ), 'The injected url() never opens as a second token.' );
keel_assert( false !== strpos( $out, 'url%28%27' ), 'The injected url( is percent-encoded, so it stays inert text.' );
keel_assert( false !== strpos( $out, '%27' ), 'Single quotes are percent-encoded.' );
// Everything the payload contributed sits inside one quoted url() value.
keel_assert( 1 === substr_count( $out, "background-image:url('" ), 'Exactly one url() token is opened.' );
// Note: the literal text "background-image" appears twice — once as the real
// declaration, once as inert characters inside the quoted url() value. Counting
// the raw substring proves nothing; counting the opened token above does.

// A rejected scheme collapses to '', which must print nothing rather than url('').
$GLOBALS['keel_site_icon'] = 'javascript:alert(1)';
keel_assert( '' === keel_logo_output(), 'A URL whose scheme is rejected prints no rule at all.' );

// '0' is falsy in PHP but survives sanitization: it is not a usable image.
$GLOBALS['keel_site_icon'] = '0';
keel_assert( '' === keel_logo_output(), 'The string "0" is treated as no image.' );

// --- sizing ---
$GLOBALS['keel_site_icon'] = 'https://example.ca/wordmark.png';
$out                       = keel_logo_output();
keel_assert( false !== strpos( $out, 'width:320px' ), 'The box is widened, so a wordmark is not squeezed into core 84px square.' );
keel_assert( false !== strpos( $out, 'background-size:contain' ), 'The image is contained rather than cropped.' );

echo "login logo: OK\n";
