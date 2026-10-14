<?php
/**
 * The backport facade loads all callbacks once without probing or updating a site.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['keel_backport_hooks'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['keel_backport_hooks'][] = array( $hook, $callback, $priority, $accepted_args );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $hook, $callback, $priority, $accepted_args );
}

// A module load must only declare callbacks and register hooks.
function get_option( ...$args ) {
	throw new RuntimeException( 'Loading backport modules must not read site state.' );
}
function wp_remote_get( ...$args ) {
	throw new RuntimeException( 'Loading backport modules must not issue HTTP requests.' );
}
function update_option( ...$args ) {
	throw new RuntimeException( 'Loading backport modules must not write site state.' );
}

require_once dirname( __DIR__ ) . '/includes/backports.php';
require_once dirname( __DIR__ ) . '/includes/backports.php';

$expected = array(
	'set_site_transient_update_core'                => 'keel_defaults_refresh_stale_stable_check',
	'wp_ajax_health-check-keel-backport'            => 'keel_defaults_backport_ajax',
	'site_status_tests'                             => 'keel_defaults_register_backport_test',
	'admin_post_keel_defaults_enable_minor_updates' => 'keel_defaults_handle_enable_minor_updates',
	'admin_notices'                                 => 'keel_defaults_backport_notice',
	'wp_ajax_keel_defaults_dismiss_backport'        => 'keel_defaults_dismiss_backport_notice',
	'admin_enqueue_scripts'                         => 'keel_defaults_backport_notice_script',
	'admin_post_keel_defaults_install_backport'     => 'keel_defaults_handle_install_backport',
);

if ( count( $expected ) !== count( $GLOBALS['keel_backport_hooks'] ) ) {
	fwrite( STDERR, "Backport hooks were lost or registered more than once.\n" );
	exit( 1 );
}
foreach ( $GLOBALS['keel_backport_hooks'] as $registered ) {
	list( $hook, $callback, $priority, $args ) = $registered;
	if ( ! isset( $expected[ $hook ] ) || $expected[ $hook ] !== $callback || ! is_callable( $callback ) || 10 !== $priority || 1 !== $args ) {
		fwrite( STDERR, "Backport hook contract changed: {$hook}.\n" );
		exit( 1 );
	}
	unset( $expected[ $hook ] );
}
if ( ! empty( $expected ) ) {
	fwrite( STDERR, "Some backport hooks were never registered.\n" );
	exit( 1 );
}

fwrite( STDOUT, "backport module loading: OK (8 hooks, no runtime work on load)\n" );
