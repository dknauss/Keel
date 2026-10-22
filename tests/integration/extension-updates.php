<?php
/** Plugin/theme policy probes for a disposable WordPress install. No updates run. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_extension_update_report' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$original    = get_option( KEEL_DEFAULTS_OPTION );
$network     = get_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
$opt_plugins = get_site_option( 'auto_update_plugins' );
$opt_themes  = get_site_option( 'auto_update_themes' );
$user        = get_current_user_id();
$second      = 0;
$locked      = null !== keel_defaults_background_updates_off();
$assert      = static function ( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message );
} };
$settings    = is_array( $original ) ? $original : array();
$later       = static function () { return false;
};
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	if ( is_multisite() ) {
		delete_site_option( KEEL_DEFAULTS_NETWORK_OPTION ); }
	unset( $settings['plugin_auto_update_policy'], $settings['theme_auto_update_policy'] );
	update_option( KEEL_DEFAULTS_OPTION, $settings );
	$assert( 'unset' === keel_defaults_get( 'plugin_auto_update_policy' ), 'Existing installations inherit unchanged.' );
	$assert( null === apply_filters( 'auto_update_plugin', null, (object) array( 'plugin' => 'fixture/fixture.php' ) ), 'Core null sentinel survives missing new settings.' );
	$settings['plugin_auto_update_policy'] = 'enabled';
	$settings['theme_auto_update_policy']  = 'disabled';
	update_option( KEEL_DEFAULTS_OPTION, $settings );
	$assert( 'enabled' === keel_defaults_extension_update_policy( 'plugin' ), 'Configured policy is known independently of updater operability.' );
	$assert( apply_filters( 'auto_update_plugin', false, (object) array() ) === ! $locked, 'Explicit enable participates in real filter chain.' );
	$assert( apply_filters( 'auto_update_theme', true, (object) array() ) === $locked, 'Separate theme policy refuses.' );
	add_filter( 'auto_update_plugin', $later, 5 );
	$assert( apply_filters( 'auto_update_plugin', false, (object) array() ) === ! $locked, 'Explicit Keel policy overrides an earlier filter.' );
	remove_filter( 'auto_update_plugin', $later, 5 );
	add_filter( 'auto_update_plugin', $later, 99 );
	$assert( false === apply_filters( 'auto_update_plugin', true, (object) array() ), 'Later third-party filter wins.' );
	remove_filter( 'auto_update_plugin', $later, 99 );
	$cache  = (object) array(
		'response' => array(
			'fixture/fixture.php' => (object) array(
				'plugin'  => 'fixture/fixture.php',
				'package' => 'SECRET_URL',
			),
		),
	);
	$report = keel_defaults_extension_update_report( 'plugin', array( 'fixture/fixture.php', 'missing' ), $cache, array(), true );
	$assert( ( $locked ? 0 : 1 ) === $report['on'] && ( $locked ? 0 : 1 ) === $report['changed_count'] && 1 === $report['unknown'], 'Report follows real filters; missing metadata unknown.' );
	$assert( false === strpos( wp_json_encode( $report ), 'SECRET_URL' ), 'Offer payload never enters report.' );
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- turn malformed-input warnings into regression failures.
	set_error_handler(
		static function ( $severity, $message ) { throw new RuntimeException( $message );
		}
	);
	try {
		$clean = keel_defaults_sanitize(
			array(
				'plugin_auto_update_policy' => 'minor',
				'theme_auto_update_policy'  => array( 'enabled' ),
			)
		);
	} finally {
		restore_error_handler(); }
	$assert( 'unset' === $clean['plugin_auto_update_policy'] && 'unset' === $clean['theme_auto_update_policy'], 'Malformed and unsupported policies sanitize to unchanged.' );
	if ( is_multisite() ) {
		update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, array( 'plugin_auto_update_policy' => 'disabled' ) );
		$assert( apply_filters( 'auto_update_plugin', true, (object) array() ) === $locked, 'Network policy wins over main site.' );
		$installing = wp_installing();
		$second     = wpmu_create_blog( get_network()->domain, '/keel-updates-' . wp_rand() . '/', 'Update probe', $admins[0]->ID );
		wp_installing( $installing );
		$assert( ! is_wp_error( $second ), 'Create disposable subsite.' );
		switch_to_blog( $second );
		try {
			update_option(
				KEEL_DEFAULTS_OPTION,
				array(
					'plugin_auto_update_policy' => 'enabled',
					'theme_auto_update_policy'  => 'enabled',
				)
			);
			$assert( apply_filters( 'auto_update_plugin', true, (object) array() ) === $locked, 'Subsite choice cannot override network.' );
			$assert( 'disabled' === keel_defaults_extension_update_policy( 'theme' ), 'Main-site policy resolves even when execution is globally locked.' );
			$assert( apply_filters( 'auto_update_theme', true, (object) array() ) === $locked, 'Main site decides unmanaged shared theme updates.' );
			$assert( null !== keel_defaults_network_lock( 'theme_auto_update_policy' ), 'Subsite shared-code control is locked.' );
			$assert( 'disabled' === keel_defaults_config_locked_value( 'theme_auto_update_policy', 'enabled' ), 'Subsite shows effective main-site policy.' );
			$rows = array_column( keel_defaults_posture_by_group()['updates'], 'state', 'key' );
			$assert( 'Disabled' === $rows['theme_auto_update_policy'], 'Site Health reports shared effective policy instead of dormant subsite preference.' );
			$clean = keel_defaults_sanitize_site( array( 'theme_auto_update_policy' => 'disabled' ) );
			$assert( 'enabled' === $clean['theme_auto_update_policy'], 'Forged subsite POST preserves stored preference.' );
			$clean = keel_defaults_sanitize_network( array( 'theme_auto_update_policy' => 'enabled' ), array( 'theme_auto_update_policy' => '1' ) );
			$assert( $locked ? ! array_key_exists( 'theme_auto_update_policy', $clean ) : 'enabled' === $clean['theme_auto_update_policy'], 'Network governance remains editable from subsite context unless globally locked.' );
		} finally {
			restore_current_blog(); }
	}
	$assert( get_site_option( 'auto_update_plugins' ) === $opt_plugins && get_site_option( 'auto_update_themes' ) === $opt_themes, 'Per-item opt-in options remain byte-equivalent.' );
	wp_set_current_user( 0 );
	ob_start();
	keel_defaults_render_extension_updates();
	$html = ob_get_clean();
	$assert( '' === $html, 'No authenticated report without update permissions.' );
} finally {
	remove_filter( 'auto_update_plugin', $later, 99 );
	remove_filter( 'auto_update_plugin', $later, 5 );
	if ( is_int( $second ) && $second > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $second, true );
	}
	if ( false === $original ) {
		delete_option( KEEL_DEFAULTS_OPTION );
	} else {
		update_option( KEEL_DEFAULTS_OPTION, $original ); }
	if ( is_multisite() ) {
		if ( false === $network ) {
			delete_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
		} else {
			update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, $network ); }
	}
	wp_set_current_user( $user );
}
if ( ! defined( 'DISALLOW_FILE_MODS' ) ) {
	define( 'DISALLOW_FILE_MODS', true );
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$key = $type . '_auto_update_policy';
		$assert( null !== keel_defaults_config_lock( $key ), 'Global restriction locks each new control.' );
		$assert( 'disabled' === keel_defaults_config_locked_value( $key, 'enabled' ), 'Global restriction displays Disabled.' );
		$assert( null === apply_filters( 'auto_update_' . $type, null, (object) array() ), 'Global restriction preserves core sentinel.' );
	}
}
echo "OK: real plugin/theme policy, filter precedence, network governance and opt-in preservation.\n";
