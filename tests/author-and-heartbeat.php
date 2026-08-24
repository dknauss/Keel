<?php
/**
 * Author-enumeration and Heartbeat policy tests.
 *
 * @package keel
 */

$GLOBALS['keel_filters']         = array();
$GLOBALS['keel_options']         = array();
$GLOBALS['keel_is_feed']         = false;
$GLOBALS['keel_is_author']       = false;
$GLOBALS['keel_is_comment_feed'] = false;
$GLOBALS['keel_dropped']         = array();
$GLOBALS['keel_status']          = null;
$GLOBALS['keel_removed_actions'] = array();
$GLOBALS['keel_nocache']         = false;

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
function is_feed() {
	return (bool) $GLOBALS['keel_is_feed'];
}
function is_author() {
	return (bool) $GLOBALS['keel_is_author'];
}
function is_comment_feed() {
	return (bool) $GLOBALS['keel_is_comment_feed'];
}
function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['keel_removed_actions'][] = array( $hook, $callback, $priority );
}
function status_header( $status ) {
	$GLOBALS['keel_status'] = (int) $status;
}
function nocache_headers() {
	$GLOBALS['keel_nocache'] = true;
}
function wp_deregister_script( $handle ) {
	$GLOBALS['keel_dropped'][] = $handle;
}
class WP_Query {
	public $is_author = true;
	public $is_feed   = true;
	public $is_404    = false;
	public function set_404() {
		$this->is_author           = false;
		$this->is_404              = true;
		$GLOBALS['keel_is_author'] = false;
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

/*
 * Author names in feeds.
 *
 * Redirecting /author/x/ closes the author pages. It does nothing about
 * <dc:creator> in RSS or <author><name> in Atom, which carry the display name of
 * every post's author — so the same list an enumeration attempt was after is
 * still served to anyone who fetches /feed/.
 */
$GLOBALS['keel_is_feed'] = false;

/*
 * An author feed is both flags in real WP_Query. Keel used to catch it in the
 * broad author 301; the dedicated priority-9 handler now makes it an explicit
 * 404 and clears the flags before the HTML-archive redirect runs.
 */
$GLOBALS['keel_is_author']       = true;
$GLOBALS['keel_is_feed']         = true;
$GLOBALS['keel_status']          = null;
$GLOBALS['keel_removed_actions'] = array();
$GLOBALS['keel_nocache']         = false;
$wp_query                        = new WP_Query();
keel_defaults_block_author_feeds();
keel_assert( 404 === $GLOBALS['keel_status'], 'An author feed returns 404.' );
keel_assert( $wp_query->is_404, 'The main query is converted to a 404.' );
keel_assert( ! $GLOBALS['keel_is_author'] && ! $wp_query->is_feed, 'The author and feed routing flags are cleared before the archive redirect and template loader run.' );
keel_assert( in_array( array( 'template_redirect', 'redirect_canonical', 10 ), $GLOBALS['keel_removed_actions'], true ), 'Canonical guessing is removed for the deliberate 404.' );
keel_assert( $GLOBALS['keel_nocache'], 'The 404 sends no-cache headers.' );

/* Core preserves is_feed in set_404(); Keel must explicitly clear it. */
$GLOBALS['keel_is_comment_feed'] = true;
$GLOBALS['keel_status']          = null;
$wp_query                        = new WP_Query();
keel_defaults_block_comment_feeds();
keel_assert( 404 === $GLOBALS['keel_status'], 'A comment feed returns 404.' );
keel_assert( $wp_query->is_404 && ! $wp_query->is_feed, 'A comment-feed 404 cannot continue into the feed template loader.' );
$GLOBALS['keel_is_comment_feed'] = false;

$GLOBALS['keel_is_author'] = false;
$GLOBALS['keel_is_feed']   = true;
$GLOBALS['keel_status']    = null;
keel_defaults_block_author_feeds();
keel_assert( null === $GLOBALS['keel_status'], 'A non-author feed is untouched.' );
$GLOBALS['keel_is_feed'] = false;
keel_assert( 'Jane Smith' === keel_defaults_mask_feed_author( 'Jane Smith' ), 'Bylines on the site itself are left alone — they are editorial.' );

$GLOBALS['keel_is_feed'] = true;
keel_assert( 'Site Contributor' === keel_defaults_mask_feed_author( 'Jane Smith' ), 'In a feed the author name is replaced.' );

$GLOBALS['keel_filters']['keel_feed_author_name'] = 'The Newsroom';
keel_assert( 'The Newsroom' === keel_defaults_mask_feed_author( 'Jane Smith' ), 'The replacement is filterable.' );
unset( $GLOBALS['keel_filters']['keel_feed_author_name'] );
$GLOBALS['keel_is_feed'] = false;

/*
 * Heartbeat: filterable, and clamped to what core will actually honour.
 * heartbeat_settings values outside 15-120s are ignored by core's own JS, so an
 * unclamped filter returning 600 would look like it worked and change nothing.
 */
keel_assert( 60 === keel_defaults_heartbeat_interval( array() )['interval'], 'The default interval is 60 seconds.' );

$GLOBALS['keel_filters']['keel_heartbeat_interval'] = 600;
keel_assert( 120 === keel_defaults_heartbeat_interval( array() )['interval'], 'An over-long interval is clamped to what core honours, not silently ignored.' );

$GLOBALS['keel_filters']['keel_heartbeat_interval'] = 2;
keel_assert( 15 === keel_defaults_heartbeat_interval( array() )['interval'], 'A too-short interval is clamped up.' );

$GLOBALS['keel_filters']['keel_heartbeat_interval'] = 45;
keel_assert( 45 === keel_defaults_heartbeat_interval( array() )['interval'], 'A sensible interval is honoured.' );
unset( $GLOBALS['keel_filters']['keel_heartbeat_interval'] );

$existing = keel_defaults_heartbeat_interval( array( 'minimalInterval' => 30 ) );
keel_assert( isset( $existing['minimalInterval'] ), 'Other Heartbeat settings are preserved.' );

// The dashboard drop, and the editor exemption that matters more.
$GLOBALS['keel_dropped'] = array();
keel_defaults_drop_dashboard_heartbeat( 'post.php' );
keel_assert( array() === $GLOBALS['keel_dropped'], 'The post editor keeps Heartbeat: post locking and autosave depend on it.' );

keel_defaults_drop_dashboard_heartbeat( 'index.php' );
keel_assert( array( 'heartbeat' ) === $GLOBALS['keel_dropped'], 'The dashboard home drops it.' );

$GLOBALS['keel_dropped']                                     = array();
$GLOBALS['keel_filters']['keel_heartbeat_drop_on_dashboard'] = false;
keel_defaults_drop_dashboard_heartbeat( 'index.php' );
keel_assert( array() === $GLOBALS['keel_dropped'], 'A site can keep the dashboard Heartbeat.' );

echo "author and heartbeat tests passed.\n";
