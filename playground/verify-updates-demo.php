<?php
/** Executed as a final runPHP step in a disposable Playground, not shipped runtime. */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
wp_set_current_user( 1 );
function keel_demo_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'Demo verification failed: ' . $message );
	}
}
keel_demo_assert( is_plugin_active( 'keel-defaults/keel.php' ), 'branch code is active' );
keel_demo_assert( current_user_can( 'update_core' ), 'read capability remains available' );
foreach ( array( 'stale' => 172800, 'fresh' => 300 ) as $scenario => $age ) {
	$_GET['keel-demo-attempt'] = $scenario;
	$evidence = keel_defaults_cron_updates( get_site_transient( 'update_core' ), false, time() );
	keel_demo_assert( abs( $evidence['offer_age'] - $age ) <= 1, 'synthetic attempt age: ' . $scenario );
	ob_start();
	keel_defaults_render_update_timeline( false, false );
	$html = ob_get_clean();
	keel_demo_assert( false !== strpos( $html, 'Unknown' ) && false !== strpos( $html, 'partial coverage' ), 'partial schedule remains Unknown' );
	keel_demo_assert( false === strpos( $html, 'Not scheduled' ), 'absence under partial coverage is not a no-job claim' );
	keel_demo_assert( false !== strpos( $html, 'not whether WordPress.org answered' ), 'attempt is not a response claim' );
	keel_demo_assert( ( 'stale' === $scenario ) === ( false !== strpos( $html, 'stale' ) ), 'stale warning follows selected age' );
}
ob_start();
keel_defaults_render_extension_updates();
$html = ob_get_clean();
foreach ( array( 'synthetic-keel-demo/synthetic-keel-demo.php', 'synthetic-keel-demo', 'Turned on by code', 'Turned off by code', 'Unknown' ) as $text ) {
	keel_demo_assert( false !== strpos( $html, $text ), 'extension report contains ' . $text );
}
foreach ( array( 'upgrader_pre_download', 'upgrader_pre_install' ) as $hook ) {
	$error = apply_filters( $hook, false, 'https://example.invalid/fixture.zip', null );
	keel_demo_assert( is_wp_error( $error ) && 'synthetic_demo_install_blocked' === $error->get_error_code(), 'install guard ' . $hook );
}
keel_demo_assert( is_wp_error( wp_remote_get( 'https://example.invalid/no-request' ) ), 'outbound HTTP is blocked' );
keel_demo_assert( true === apply_filters( 'automatic_updater_disabled', false ), 'automatic execution is blocked' );
ob_start();
do_action( 'admin_notices' );
$html = ob_get_clean();
keel_demo_assert( false !== strpos( $html, 'All update evidence on this demo screen is synthetic' ), 'fixture notice is visible' );
echo "Updates demo: all real-WordPress assertions passed.\n";
