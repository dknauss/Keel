<?php
/**
 * Regression test for author identity leaking past the archive redirect.
 *
 * `disable_author_archives` redirects `/author/` pages, and for a long time
 * that was the whole implementation. Three other routes publish the same login
 * name and none of them is called "author archive":
 *
 *   - feeds, via <dc:creator>            — closed earlier, K12
 *   - oEmbed, via author_name/author_url — closed here
 *   - the users sitemap, by nicename     — closed here
 *
 * The pattern is what these assertions are really pinning: closing the obvious
 * door is not the same as not publishing the name.
 *
 * Run: php tests/author-identity-leaks.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_is_feed'] = false;

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
function is_feed() { return (bool) $GLOBALS['keel_is_feed']; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// --- oEmbed ---
// The response measured from a live install before the fix.
$response = array(
	'version'       => '1.0',
	'provider_name' => 'example',
	'author_name'   => 'admin',
	'author_url'    => 'http://example.test/author/admin/',
	'title'         => 'Hello world!',
	'type'          => 'rich',
	'html'          => '<blockquote>…</blockquote>',
);

$filtered = keel_defaults_strip_oembed_author( $response );

keel_assert( ! isset( $filtered['author_name'] ), 'oEmbed no longer publishes the author name.' );
keel_assert( ! isset( $filtered['author_url'] ), 'oEmbed no longer publishes the author archive URL, which carries the nicename.' );

// The embed has to keep working — this is a privacy fix, not a teardown.
keel_assert( 'Hello world!' === $filtered['title'], 'The title survives, so embeds still render.' );
keel_assert( isset( $filtered['html'] ), 'The embed markup survives.' );
keel_assert( 'example' === $filtered['provider_name'], 'The provider survives.' );

// Anything that is not an array is handed back untouched.
foreach ( array( null, false, 'string', 42 ) as $odd ) {
	keel_assert( $odd === keel_defaults_strip_oembed_author( $odd ), 'A non-array response is returned unchanged.' );
}

// --- users sitemap ---
keel_assert( false === keel_defaults_drop_users_sitemap( 'provider', 'users' ), 'The users sitemap provider is dropped.' );

// Only that one. Dropping posts or taxonomies would be a de-indexing bug.
foreach ( array( 'posts', 'taxonomies', 'anything-else' ) as $name ) {
	keel_assert( 'provider' === keel_defaults_drop_users_sitemap( 'provider', $name ), "The '{$name}' sitemap provider is untouched." );
}

// --- feeds, closed earlier; asserted here so the three stay together ---
$GLOBALS['keel_is_feed'] = true;
keel_assert( 'admin' !== keel_defaults_mask_feed_author( 'admin' ), 'Feeds do not publish the author name.' );
$GLOBALS['keel_is_feed'] = false;
keel_assert( 'admin' === keel_defaults_mask_feed_author( 'admin' ), 'On-site bylines are untouched — this hides names from harvesting, not from readers.' );

echo "author identity leaks: OK\n";
