<?php
/**
 * Plugin Name: Synthetic fixtures — Keel Updates screen demo
 * Description: Playground-only read filters. No real update evidence or installations.
 */

defined( 'ABSPATH' ) || exit;

function keel_demo_attempt_age() {
	// Read-only scenario selection. Neither scenario records a real check.
	return isset( $_GET['keel-demo-attempt'] ) && 'fresh' === $_GET['keel-demo-attempt'] ? 300 : 172800;
}

add_action( 'admin_notices', function () {
	echo '<div class="notice notice-warning"><p><strong>Demo: synthetic update data.</strong> No live checks or scheduled installs are demonstrated. Downloads and installations are blocked.</p><p><a href="' . esc_url( admin_url( 'update-core.php?keel-demo-attempt=stale' ) ) . '">Attempt: 2 days ago</a> · <a href="' . esc_url( admin_url( 'update-core.php?keel-demo-attempt=fresh' ) ) . '">Attempt: 5 minutes ago</a></p><details><summary>About this demo</summary><p>Open Update diagnostics to inspect partial schedule coverage (Unknown) and fixture plugin/theme decisions changed by code. Other items have no fixture metadata. The disabled updater is a demo safeguard, not evidence about your site.</p></details></div>';
} );

add_filter( 'pre_site_transient_update_core', function () {
	global $wp_version;
	return (object) array( 'last_checked' => time() - keel_demo_attempt_age(), 'version_checked' => $wp_version, 'updates' => array() );
} );

add_filter( 'pre_transient_keel_cron_report', function () {
	return array(
		'schema' => 1, 'site' => get_current_blog_id(), 'checked' => time() - 120,
		'error' => '', 'done' => true,
		'data' => array( 'examined' => 2000, 'limited' => true, 'malformed' => false, 'core' => array(), 'overdue' => array(), 'frequent' => array() ),
		'update' => array( 'offer_age' => keel_demo_attempt_age(), 'failure' => null ),
	);
} );

// Only these installed demo items get metadata; never synthesize a download URL.
add_filter( 'pre_site_transient_update_plugins', function () {
	return (object) array( 'last_checked' => time(), 'response' => array(), 'no_update' => array(
		'synthetic-keel-demo/synthetic-keel-demo.php' => (object) array( 'plugin' => 'synthetic-keel-demo/synthetic-keel-demo.php', 'autoupdate' => false ),
	) );
} );
add_filter( 'pre_site_transient_update_themes', function () {
	return (object) array( 'last_checked' => time(), 'response' => array(), 'no_update' => array(
		'synthetic-keel-demo' => array( 'theme' => 'synthetic-keel-demo', 'autoupdate' => true ),
	) );
} );
add_filter( 'auto_update_plugin', function ( $decision, $item ) {
	return isset( $item->plugin ) && 'synthetic-keel-demo/synthetic-keel-demo.php' === $item->plugin ? true : $decision;
}, PHP_INT_MAX, 2 );
add_filter( 'auto_update_theme', function ( $decision, $item ) {
	return isset( $item->theme ) && 'synthetic-keel-demo' === $item->theme ? false : $decision;
}, PHP_INT_MAX, 2 );

// Keep read capabilities so Keel renders; reject updater execution instead.
add_filter( 'automatic_updater_disabled', '__return_true', PHP_INT_MAX );
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'synthetic_demo_network_blocked', 'SYNTHETIC demo: outbound WordPress HTTP is disabled.' );
}, PHP_INT_MAX );
function keel_demo_block_install() {
	return new WP_Error( 'synthetic_demo_install_blocked', 'SYNTHETIC demo: real downloads and installations are disabled.' );
}
add_filter( 'upgrader_pre_download', 'keel_demo_block_install', PHP_INT_MAX );
add_filter( 'upgrader_pre_install', 'keel_demo_block_install', PHP_INT_MAX );
