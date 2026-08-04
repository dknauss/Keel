<?php
/**
 * Author-enumeration and Heartbeat policy tests.
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_is_feed'] = false;
$GLOBALS['keel_dropped'] = array();

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
function wp_deregister_script( $handle ) {
	$GLOBALS['keel_dropped'][] = $handle;
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
