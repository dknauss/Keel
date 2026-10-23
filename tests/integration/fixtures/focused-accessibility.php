<?php
/**
 * Disposable local accessibility fixture; never install on a real site.
 *
 * Load from a temporary MU-plugin wrapper pointing at this file in the checkout.
 * No setting or locale is persisted. Updates are blocked by the demo guard.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;
if ( ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	return;
}
if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) ) {
	define( 'WP_AUTO_UPDATE_CORE', false );
}
if ( ! defined( 'DISALLOW_UNFILTERED_HTML' ) ) {
	define( 'DISALLOW_UNFILTERED_HTML', true );
}
add_filter(
	'pre_option_keel_settings',
	static function () {
		return array(
			'block_xmlrpc_endpoint' => 'yes',
			'disable_remember_me'   => 'yes',
		);
	}
);
add_filter(
	'pre_site_option_keel_network_settings',
	static function () {
		return array(
			'xmlrpc_allow_pingbacks' => 'no',
			'admin_menu_width'       => '240',
			'remember_me_days'       => 30,
		);
	}
);
/**
 * Select only installed fixture locales; no user metadata or options change.
 *
 * @return string Fixture locale.
 */
function keel_a11y_fixture_locale() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Strict allowlist; raw cookie is never rendered.
	return isset( $_COOKIE['keel_a11y_locale'] ) && 'ar' === $_COOKIE['keel_a11y_locale'] ? 'ar' : 'en_US';
}
add_filter( 'locale', 'keel_a11y_fixture_locale' );
add_filter( 'determine_locale', 'keel_a11y_fixture_locale' );
require dirname( __DIR__, 3 ) . '/playground/updates-screen-fixtures.php';
