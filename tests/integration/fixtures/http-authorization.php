<?php
/** Disposable localhost HTTP lab. Enable only through a temporary MU wrapper. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'KEEL_HTTP_AUTH_LAB' ) || ! KEEL_HTTP_AUTH_LAB || ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	return;
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true ); }
add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter(
	'upgrader_pre_download',
	static function () { return new WP_Error( 'lab_blocked', 'Downloads disabled in HTTP lab.' );
	}
);
add_filter(
	'pre_http_request',
	static function () { return new WP_Error( 'lab_blocked', 'HTTP lab blocks outbound requests.' );
	}
);
// Explicit synthetic delegation: core normally denies update_core to site admins.
add_filter(
	'map_meta_cap',
	static function ( $caps, $cap, $id ) {
		$user = get_userdata( $id );
		if ( 'update_core' === $cap && $user && 'keel_http_delegate' === $user->user_login ) {
			return array( 'read' );
		}
		return $caps;
	},
	99,
	3
);

/** Return real-session nonces and narrowly scoped persisted state. */
function keel_http_lab_context() {
	return array(
		'wordpress' => get_bloginfo( 'version' ),
		'php'       => PHP_VERSION,
		'multisite' => is_multisite(),
		'site'      => get_current_blog_id(),
		'roles'     => wp_get_current_user()->roles,
		'source'    => ( new ReflectionFunction( 'keel_defaults_sanitize' ) )->getFileName(),
		'caps'      => array_map( 'current_user_can', array( 'manage_options', 'manage_network_options', 'update_core', 'view_site_health_checks' ) ),
		'nonces'    => array(
			'control' => wp_create_nonce( 'keel-http-lab' ),
			'minor'   => wp_create_nonce( 'keel_defaults_enable_minor_updates' ),
			'health'  => wp_create_nonce( 'health-check-site-status' ),
			'export'  => wp_create_nonce( 'keel-posture-export-' . get_current_blog_id() ),
			'network' => wp_create_nonce( 'keel-network-save' ),
			'options' => wp_create_nonce( 'keel_settings_group-options' ),
		),
		'state'     => array(
			'minor'    => get_site_option( 'auto_update_core_minor', null ),
			'settings' => get_option( 'keel_settings', null ),
			'network'  => get_site_option( 'keel_network_settings', null ),
		),
	);
}
add_action(
	'wp_ajax_keel_http_lab',
	static function () {
		// All control mutations require the original lab administrator and nonce.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'assume' === $op ) {
			check_ajax_referer( 'keel-http-lab' );
			if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) {
				wp_send_json_error( array(), 403 ); }
			$login = isset( $_POST['login'] ) ? sanitize_key( wp_unslash( $_POST['login'] ) ) : '';
			if ( ! in_array( $login, array( 'keel_http_admin', 'keel_http_editor', 'keel_http_subscriber', 'keel_http_delegate' ), true ) ) {
				wp_send_json_error( array(), 400 ); }
			$user = get_user_by( 'login', $login );
			if ( ! $user ) {
				wp_send_json_error( array(), 404 ); }
			add_action(
				'set_logged_in_cookie',
				static function ( $cookie ) { $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
				}
			);
			wp_set_auth_cookie( $user->ID );
			wp_set_current_user( $user->ID );
		}
		wp_send_json_success( keel_http_lab_context() );
	}
);
