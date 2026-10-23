<?php
/** Exercise fixed-key cleanup with independent site and paged network scopes. */
define( 'WP_UNINSTALL_PLUGIN', true );
$GLOBALS['multi']   = in_array( '--multisite', $argv, true );
$GLOBALS['blog']    = 1;
$GLOBALS['stack']   = array();
$GLOBALS['removed'] = array();
$GLOBALS['pages']   = array();
function is_multisite() { return $GLOBALS['multi']; }
function delete_option( $key ) { $GLOBALS['removed'][ $GLOBALS['blog'] ][] = $key; }
function delete_transient( $key ) {}
function delete_site_transient( $key ) {}
function delete_site_option( $key ) {}
function get_option( $key ) { return false; }
function wp_clear_scheduled_hook( $hook ) {}
function delete_metadata( ...$args ) {}
function get_sites( $args ) { return array(); }
function get_networks( $args ) {
	$GLOBALS['pages'][] = $args;
	return array_slice( range( 1, 204 ), $args['offset'], $args['number'] );
}
function get_main_site_id( $id ) { return 204 === $id ? 0 : 1000 + $id; }
function get_site( $id ) { return 1201 === $id ? null : (object) array(
	'site_id' => 1203 === $id ? 999 : $id - 1000,
	'deleted' => 1202 === $id ? '1' : '0',
); }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog'];
	$GLOBALS['blog']                                 = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
$wpdb = new class() {
	public $options = 'wp_options';
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function query( $sql ) { return 1; }
};
require dirname( __DIR__ ) . '/uninstall.php';
function check( $condition, $message ) { if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
$scopes = $GLOBALS['multi'] ? array_merge( range( 1, 200 ), array( 202 ) ) : array( 0 );
foreach ( $scopes as $id ) {
	$owner_blog = $GLOBALS['multi'] ? 1000 + $id : 1;
	$keys       = array_values(
		array_filter(
			$GLOBALS['removed'][ $owner_blog ] ?? array(),
			static function ( $key ) { return 0 === strpos( $key, 'keel_hibp_v2_' );
			}
		)
	);
	$expected   = array( 'keel_hibp_v2_' . $id . '_state' );
	for ( $slot = 0; $slot < 64; ++$slot ) {
		$expected[] = 'keel_hibp_v2_' . $id . '_slot_' . sprintf( '%02d', $slot ); }
	check( $expected === $keys, 'Exactly 65 fixed keys removed from canonical scope ' . $id );
}
check( 1 === $GLOBALS['blog'] && empty( $GLOBALS['stack'] ), 'Blog context restored.' );
if ( $GLOBALS['multi'] ) {
	check( 2 === count( $GLOBALS['pages'] ) && 200 === $GLOBALS['pages'][0]['number'] && 200 === $GLOBALS['pages'][1]['offset'], 'Network traversal is paged.' );
	check( ! isset( $GLOBALS['removed'][1201] ) && ! isset( $GLOBALS['removed'][1203] ), 'Missing or foreign main sites are never cleaned as an owning scope.' );
	check( ! isset( $GLOBALS['removed'][0] ) && ! isset( $GLOBALS['removed'][1] ), 'Missing main site never falls back to current site.' );
}
echo 'OK: fixed HIBP uninstall scopes' . ( $GLOBALS['multi'] ? ' (multisite)' : '' ) . ".\n";
