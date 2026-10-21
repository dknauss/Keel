<?php
/**
 * Real database autoload contracts. Run only on a disposable WordPress install.
 *
 * @package Keel
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_autoload_health' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
global $wpdb;
$keel_autoload_user   = get_current_user_id();
$keel_autoload_keys   = array( 'keel_probe_large', 'keel_probe_disabled', 'keel_probe_é' );
$keel_autoload_assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	delete_transient( 'keel_autoload_report' );
	delete_option( 'keel_autoload_scan_lock' );
	add_option( $keel_autoload_keys[0], str_repeat( 'é', 600000 ), '', true );
	add_option( $keel_autoload_keys[1], str_repeat( 'z', 1500000 ), '', false );
	add_option( $keel_autoload_keys[2], str_repeat( 'é', 500000 ), '', true );
	keel_defaults_autoload_health();
	$keel_autoload_assert( 0 === keel_defaults_autoload_snapshot()['scanned'], 'First request must schedule without scanning.' );
	for ( $batch = 0; $batch < 41; ++$batch ) {
		keel_defaults_autoload_collect();
		$state = keel_defaults_autoload_snapshot();
		if ( $state['done'] ) {
			break;
		}
	}
	$keel_autoload_assert( '' === $state['error'] && $state['done'] && ! $state['limited'], 'Real SQL scan must complete.' );
	$names = array_column( $state['top'], 'bytes', 'name' );
	$keel_autoload_assert( 1200000 === ( $names[ $keel_autoload_keys[0] ] ?? null ), 'OCTET_LENGTH must measure UTF-8 bytes.' );
	$keel_autoload_assert( 1000000 === ( $names[ $keel_autoload_keys[2] ] ?? null ), 'Unicode option names must survive attribution.' );
	$keel_autoload_assert( ! isset( $names[ $keel_autoload_keys[1] ] ), 'Non-autoloaded options must be excluded.' );
	$queries = $wpdb->num_queries;
	keel_defaults_autoload_snapshot();
	$keel_autoload_assert( $queries === $wpdb->num_queries, 'Cached snapshot must avoid SQL.' );
	$keel_autoload_assert( false === strpos( wp_json_encode( $state, JSON_UNESCAPED_UNICODE ), str_repeat( 'é', 100 ) ), 'No values in cached report.' );
	wp_set_current_user( 0 );
	$keel_autoload_assert( '' === keel_defaults_autoload_health()['description'], 'No diagnostic metadata for unauthenticated callers.' );
	echo "autoload real-database contracts: OK\n";
} finally {
	foreach ( $keel_autoload_keys as $key ) {
		delete_option( $key );
	}
	delete_transient( 'keel_autoload_report' );
	delete_option( 'keel_autoload_scan_lock' );
	wp_clear_scheduled_hook( 'keel_defaults_autoload_collect' );
	wp_set_current_user( $keel_autoload_user );
}
