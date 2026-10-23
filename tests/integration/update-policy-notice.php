<?php
/** Persistent policy notice on a disposable WordPress install. No updates run. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_update_policy_notice_markup' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$original    = get_option( KEEL_DEFAULTS_OPTION );
$network     = get_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
$user        = get_current_user_id();
$assert      = static function ( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message );
} };
$settings    = is_array( $original ) ? $original : array();
$minor       = array(
	'minor' => true,
	'major' => false,
	'dev'   => false,
);
$guard       = static function () { throw new RuntimeException( 'Notice invoked a policy filter, HTTP or a write.' );
};
$query_guard = static function ( $sql ) use ( $guard ) {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql ) || preg_match( "/option_name\s*=\s*'cron'/i", $sql ) ) {
		$guard();
	}
	return $sql;
};
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	$assert( 9 === has_action( 'after_core_auto_updates_settings', 'keel_defaults_render_update_policy_notice' ), 'Notice precedes Keel reports on core hook.' );
	if ( is_multisite() ) {
		delete_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
	}
	foreach ( array( 'minor', 'all', 'manual', 'inherit' ) as $policy ) {
		$settings['core_update_policy'] = $policy;
		update_option( KEEL_DEFAULTS_OPTION, $settings );
		$before = get_option( KEEL_DEFAULTS_OPTION );
		foreach ( array( 'allow_minor_auto_core_updates', 'allow_major_auto_core_updates', 'allow_dev_auto_core_updates', 'pre_http_request', 'pre_option_cron', 'pre_schedule_event', 'pre_reschedule_event', 'pre_update_option', 'pre_update_site_option' ) as $hook ) {
			add_filter( $hook, $guard, 999 );
		}
		add_filter( 'query', $query_guard, -999 );
		$level = ob_get_level();
		try {
			ob_start();
			keel_defaults_render_update_policy_notice( $minor );
			$html = ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			remove_filter( 'query', $query_guard, -999 );
			foreach ( array( 'allow_minor_auto_core_updates', 'allow_major_auto_core_updates', 'allow_dev_auto_core_updates', 'pre_http_request', 'pre_option_cron', 'pre_schedule_event', 'pre_reschedule_event', 'pre_update_option', 'pre_update_site_option' ) as $hook ) {
				remove_filter( $hook, $guard, 999 );
			}
		}
		$after = get_option( KEEL_DEFAULTS_OPTION );
		$assert( $before === $after, 'Render preserves configured choices.' );
		$assert( false !== strpos( $html, 'Keel update policy' ) && false !== strpos( $html, 'notice-info inline' ), 'Visible persistent native notice.' );
		$assert( false !== strpos( $html, keel_defaults_setting_anchor( 'core_update_policy' ) ), 'Link targets actual control anchor.' );
		$effective = keel_defaults_config_locked_value( 'core_update_policy', $policy );
		if ( 'minor' === $effective ) {
			$assert( false !== strpos( $html, 'Major-version upgrades are not enabled by this policy.' ), 'Minor choice explains scope without mandatory-manual claim.' );
		} elseif ( 'inherit' === $effective ) {
			$assert( false !== strpos( $html, 'WordPress or another component' ), 'Inheritance provenance clear.' );
		}
	}
	if ( is_multisite() ) {
		update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, array( 'core_update_policy' => 'minor' ) );
		$html = keel_defaults_update_policy_notice_markup( $minor );
		$assert( false !== strpos( $html, esc_url( network_admin_url( 'settings.php?page=keel-network' ) ) ), 'Network-managed policy points to network screen.' );
		if ( null === keel_defaults_config_lock( 'core_update_policy' ) ) {
			$assert( false !== strpos( $html, 'Keel network policy' ), 'Network source shown when configuration does not override.' );
		}
	}
	$deny = static function ( $caps, $cap ) { return 'update_core' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny, 999, 2 );
	add_filter( 'pre_option_' . KEEL_DEFAULTS_OPTION, $guard, -999 );
	try {
		$assert( '' === keel_defaults_update_policy_notice_markup( $minor ), 'Without core update authority, notice reads no settings and emits nothing.' );
	} finally {
		remove_filter( 'map_meta_cap', $deny, 999 );
		remove_filter( 'pre_option_' . KEEL_DEFAULTS_OPTION, $guard, -999 );
	}
	wp_set_current_user( 0 );
	$assert( '' === keel_defaults_update_policy_notice_markup( $minor ), 'Unauthorized access yields no notice.' );
} finally {
	if ( false === $original ) {
		delete_option( KEEL_DEFAULTS_OPTION );
	} else {
		update_option( KEEL_DEFAULTS_OPTION, $original );
	}
	if ( is_multisite() ) {
		if ( false === $network ) {
			delete_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
		} else {
			update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, $network );
		}
	}
	wp_set_current_user( $user );
}
echo "OK: real update-policy notice, attribution, access and no side effects.\n";
