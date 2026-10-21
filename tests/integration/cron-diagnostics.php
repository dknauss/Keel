<?php
/**
 * Cron report contracts for a disposable WordPress install only.
 *
 * @package Keel
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_cron_health' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
global $wpdb;
$keel_cron_site     = 0;
$keel_cron_original = get_option( 'cron' );
$keel_cron_offers   = get_site_transient( 'update_core' );
$keel_cron_failure  = get_site_option( 'auto_core_update_failed' );
$keel_cron_user     = get_current_user_id();
$keel_cron_assert   = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
};
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	$now = time();
	if ( is_multisite() ) {
		$installing     = wp_installing();
		$keel_cron_site = wpmu_create_blog( get_network()->domain, '/keel-cron-' . wp_rand() . '/', 'Cron probe', $admins[0]->ID );
		wp_installing( $installing );
		$keel_cron_assert( ! is_wp_error( $keel_cron_site ), 'Create disposable second site.' );
	}
	$fixture = array(
		$now - 3600 => array(
			'keel_probe_hook<script>' => array(
				'hash' => array(
					'schedule' => 'probe_fast',
					'interval' => 30,
					'args'     => array( 'KEEL_CRON_SECRET' ),
				),
			),
		),
		$now + 500  => array(
			'wp_version_check' => array(
				'hash' => array(
					'schedule' => 'twicedaily',
					'interval' => 43200,
					'args'     => array(),
				),
			),
		),
		'version'   => 2,
	);
	update_option( 'cron', $fixture );
	set_site_transient( 'update_core', (object) array( 'last_checked' => $now - 90000 ) );
	update_site_option(
		'auto_core_update_failed',
		array(
			'timestamp'  => $now - 100,
			'error_code' => 'disk_full',
			'error_data' => 'KEEL_CRON_SECRET',
			'critical'   => true,
		)
	);
	delete_transient( 'keel_cron_report' );
	keel_defaults_cron_health();
	$keel_cron_assert( ! keel_defaults_cron_snapshot()['done'], 'Cold report must schedule without collection.' );
	keel_defaults_cron_collect();
	$state = keel_defaults_cron_snapshot();
	$keel_cron_assert( $state['done'] && '' === $state['error'], 'Real SQL CASE/decode must collect successfully.' );
	$keel_cron_assert( (bool) DISABLE_WP_CRON === $state['external'], 'External scheduler configuration must be reported without diagnosing failure.' );
	$keel_cron_assert( $state['data']['overdue'][0]['delay'] >= 3600 && 30 === $state['data']['frequent'][0]['interval'], 'Pressure metadata must match actual cron option.' );
	$keel_cron_assert( $state['update']['offer_age'] >= 90000 && 'disk_full' === $state['update']['failure']['code'], 'Existing network update evidence must be reflected.' );
	$keel_cron_assert( false === strpos( wp_json_encode( $state ), 'KEEL_CRON_SECRET' ), 'Cached metadata must exclude arguments and error data.' );
	$html = keel_defaults_cron_health()['description'];
	$keel_cron_assert( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'Hostile hook names must be escaped.' );
	$queries = $wpdb->num_queries;
	keel_defaults_cron_snapshot();
	$keel_cron_assert( $wpdb->num_queries === $queries, 'Cached snapshot must avoid SQL.' );
	if ( is_multisite() ) {
		switch_to_blog( $keel_cron_site );
		try {
			$keel_cron_assert( false === keel_defaults_cron_snapshot(), 'Other site must not see this report.' );
			keel_defaults_cron_health();
			$keel_cron_assert( ! keel_defaults_cron_snapshot()['done'], 'Second site starts independently.' );
		} finally {
			restore_current_blog(); }
		$keel_cron_assert( keel_defaults_cron_snapshot()['done'], 'First-site report survives switching back.' );
	}
	wp_set_current_user( 0 );
	$keel_cron_assert( '' === keel_defaults_cron_health()['description'], 'No metadata for unauthenticated callers.' );
	wp_set_current_user( $admins[0]->ID );
	$fixture[ $now - 3600 ]['keel_probe_hook<script>']['hash']['args'] = array( str_repeat( 'x', 1048577 ) );
	update_option( 'cron', $fixture );
	delete_transient( 'keel_cron_report' );
	keel_defaults_cron_health();
	keel_defaults_cron_collect();
	$keel_cron_assert( 'cron_too_large' === keel_defaults_cron_snapshot()['error'], 'SQL must return no oversized payload.' );
	echo "cron real-database contracts: OK\n";
} finally {
	if ( isset( $keel_cron_site ) && is_int( $keel_cron_site ) && $keel_cron_site > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $keel_cron_site, true );
	}
	update_option( 'cron', $keel_cron_original );
	if ( false === $keel_cron_offers ) {
		delete_site_transient( 'update_core' ); } else {
		set_site_transient( 'update_core', $keel_cron_offers ); }
		if ( false === $keel_cron_failure ) {
			delete_site_option( 'auto_core_update_failed' ); } else {
				update_site_option( 'auto_core_update_failed', $keel_cron_failure ); }
			delete_transient( 'keel_cron_report' );
			delete_option( 'keel_autoload_scan_lock' );
			wp_set_current_user( $keel_cron_user );
}
