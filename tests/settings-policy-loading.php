<?php
/**
 * Settings policy can load independently of screen registration and site state.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );
function get_option( ...$args ) { throw new RuntimeException( 'Loading must not read site settings.' ); }
function get_site_option( ...$args ) { throw new RuntimeException( 'Loading must not read network settings.' ); }
function add_filter( ...$args ) { throw new RuntimeException( 'Loading must not register filters.' ); }
function add_action( ...$args ) { throw new RuntimeException( 'Loading must not register screen hooks.' ); }
function __( ...$args ) { throw new RuntimeException( 'Loading must not evaluate translated lock text.' ); }
require_once dirname( __DIR__ ) . '/includes/settings-policy.php';
require_once dirname( __DIR__ ) . '/includes/settings-policy.php';
foreach ( array( 'config_lock', 'background_updates_off', 'core_policy_for_constant', 'config_locked_value' ) as $name ) {
	if ( ! is_callable( 'keel_defaults_' . $name ) ) {
		throw new RuntimeException( 'Missing public settings policy helper: ' . $name );
	}
}
if ( function_exists( 'keel_defaults_render_settings_page' ) ) {
	throw new RuntimeException( 'Policy loading must not pull in the screen.' );
}
echo "settings policy loading: OK (4 helpers, no runtime work on load)\n";
