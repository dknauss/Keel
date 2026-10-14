<?php
/**
 * Plugin-root caching must avoid repeated filesystem work without stale site/network policy.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ );
$GLOBALS['keel_test_plugins']        = array( 'one/main.php' );
$GLOBALS['keel_test_network']        = array();
$GLOBALS['keel_test_normalizations'] = 0;
function get_option( $key, $default = false ) { return $GLOBALS['keel_test_plugins']; }
function is_multisite() { return true; }
function get_site_option( $key, $default = false ) { return $GLOBALS['keel_test_network']; }
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function wp_normalize_path( $path ) { ++$GLOBALS['keel_test_normalizations'];
	return $path; }
require dirname( __DIR__ ) . '/includes/conflict-attribution.php';

$first = keel_defaults_active_plugin_roots();
$count = $GLOBALS['keel_test_normalizations'];
if ( keel_defaults_active_plugin_roots() !== $first || $GLOBALS['keel_test_normalizations'] !== $count ) {
	throw new RuntimeException( 'An unchanged plugin list must reuse resolved roots.' );
}
$GLOBALS['keel_test_plugins'][] = 'two/main.php';
if ( ! isset( keel_defaults_active_plugin_roots()['two'] ) ) {
	throw new RuntimeException( 'Site plugin changes must invalidate roots in the same request.' );
}
$GLOBALS['keel_test_network']['network/main.php'] = 123;
if ( ! isset( keel_defaults_active_plugin_roots()['network'] ) ) {
	throw new RuntimeException( 'Network plugin changes must invalidate roots in the same request.' );
}
$GLOBALS['keel_test_plugins'] = array();
$GLOBALS['keel_test_network'] = array();
if ( array() !== keel_defaults_active_plugin_roots() ) {
	throw new RuntimeException( 'Deactivation must discard old roots.' );
}
echo "conflict root cache: OK (reuse and site/network invalidation)\n";
