<?php
/**
 * Core backports: actions.
 *
 * Loaded by backports.php; public callback names and behavior are unchanged.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answer the async Site Health request.
 *
 * @return never Sends a JSON response and terminates the request.
 */
function keel_defaults_backport_ajax() {
	// One argument, deliberately. Site Health's JS posts the nonce as _wpnonce,
	// and check_ajax_referer() only falls back to _wpnonce when no field name is
	// given — naming a field looks for that field and nothing else, so passing
	// 'nonce' here failed every browser-triggered request before the test ran.
	check_ajax_referer( 'health-check-site-status' );

	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error( array(), 403 );
	}

	wp_send_json_success( keel_defaults_backport_test() );
}

/**
 * Re-enable minor auto-updates on request.
 *
 * Writes the option only. It does not touch constants or filters, so a site
 * whose policy is set in code stays set in code — and the Site Health test says
 * so rather than pretending the click worked.
 *
 * @return never Rejects the request or redirects and exits.
 */
function keel_defaults_handle_enable_minor_updates() {
	if ( ! current_user_can( 'update_core' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
		wp_die( esc_html__( 'You are not allowed to change update settings on this site.', 'keel-defaults' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'keel_defaults_enable_minor_updates' );

	update_site_option( 'auto_update_core_minor', 'enabled' );
	delete_site_transient( 'update_core' );

	wp_safe_redirect( add_query_arg( 'keel-minor-updates', 'enabled', admin_url( 'site-health.php' ) ) );
	exit;
}

/**
 * Record a per-user, per-version dismissal.
 *
 * @return never Rejects the request or ends the AJAX response.
 */
function keel_defaults_dismiss_backport_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		wp_die( '', '', 403 );
	}

	check_ajax_referer( 'keel_defaults_dismiss_backport', 'nonce' );

	$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

	if ( '' !== $version ) {
		update_user_meta( get_current_user_id(), 'keel_backport_dismissed', $version );
	}

	wp_die();
}
