<?php
/** Static disclosure probe for a disposable real WordPress install. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_update_disclosure_info' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$user    = get_current_user_id();
$version = $GLOBALS['wp_version'];
$assert  = static function ( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message );
} };
$guard   = static function () { throw new RuntimeException( 'Disclosure touched HTTP, site values, update caches or option writes.' );
};
$hooks   = array( 'pre_http_request', 'pre_option_home', 'pre_option_siteurl', 'pre_option_blogname', 'pre_site_transient_update_core', 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes', 'pre_update_option', 'pre_update_site_option' );
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	$assert( current_user_can( 'view_site_health_checks' ), 'Lab administrator can view Site Health.' );
	$assert( 10 === has_filter( 'debug_information', 'keel_defaults_update_disclosure_info' ), 'Disclosure registered on Info surface.' );
	foreach ( $hooks as $hook ) {
		add_filter( $hook, $guard, 999 ); }
	try {
		$info = keel_defaults_update_disclosure_info( array( 'sentinel' => true ) );
		$assert( isset( $info['keel_update_disclosure'] ) && true === $info['sentinel'], 'Static section added without replacing existing information.' );
		$text = wp_json_encode( $info['keel_update_disclosure'], JSON_UNESCAPED_SLASHES );
		$assert( false !== strpos( $text, 'not evidence' ), 'No transmission assertion.' );
		$assert( false === strpos( $text, 'localhost' ) && false === strpos( $text, $version ), 'No actual site URL or installed version.' );
		$GLOBALS['wp_version'] = '99.9';
		$unknown               = keel_defaults_update_disclosure_info( array() );
		$assert( ! isset( $unknown['keel_update_disclosure']['fields']['core'] ), 'Future version has no fabricated category coverage.' );
		$GLOBALS['wp_version'] = $version;
	} finally {
		foreach ( $hooks as $hook ) {
			remove_filter( $hook, $guard, 999 ); }
	}
	wp_set_current_user( 0 );
	$assert( array() === keel_defaults_update_disclosure_info( array() ), 'Anonymous disclosure inaccessible.' );
	if ( is_multisite() ) {
		// Remove only network-management authority from this real administrator.
		$deny = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		wp_set_current_user( $admins[0]->ID );
		add_filter( 'map_meta_cap', $deny, 999, 2 );
		try {
			$assert( array() === keel_defaults_update_disclosure_info( array() ), 'Site-level administrator cannot view network-context section.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 999 ); }
	}
} finally {
	$GLOBALS['wp_version'] = $version;
	wp_set_current_user( $user );
}
echo "OK: real static disclosure access, unknown versions and no data collection.\n";
