<?php
/**
 * Persistent context beside core's unchanged automatic-update status.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render immediately after core's policy text, before other Keel update reports.
 *
 * @param mixed $settings Core's already-filtered automatic-update settings.
 * @return void
 */
function keel_defaults_render_update_policy_notice( $settings = null ) {
	echo keel_defaults_update_policy_notice_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction.
}

/**
 * Explain policy and observed item counts without rerunning core policy filters.
 *
 * @param mixed $settings Core's already-filtered automatic-update settings.
 * @return string Escaped native notice, or empty for unauthorized users.
 */
function keel_defaults_update_policy_notice_markup( $settings ) {
	global $wp_version;
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	$policy  = keel_defaults_config_locked_value( 'core_update_policy', keel_defaults_get( 'core_update_policy' ) );
	$off     = keel_defaults_background_updates_off();
	$network = keel_defaults_network_manages( 'core_update_policy' );
	if ( null !== $off ) {
		/* translators: %s: configuration constant disabling background updates. */
		$source = sprintf( esc_html__( 'Background updates are disabled by %s in wp-config.php.', 'keel-defaults' ), '<code>' . esc_html( $off ) . '</code>' );
	} elseif ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$source = esc_html__( 'Its core update policy is set by WP_AUTO_UPDATE_CORE in wp-config.php.', 'keel-defaults' );
	} else {
		$source = $network ? esc_html__( 'Its update policy is set by Keel network policy.', 'keel-defaults' ) : esc_html__( 'Its update policy is set by Keel Site Defaults.', 'keel-defaults' );
	}

	$branch = '';
	if ( is_string( $wp_version ) && strlen( $wp_version ) <= 64 && preg_match( '/^(\d+\.\d+)(?:\.\d+)?(?:-[a-zA-Z0-9.-]+)?$/D', $wp_version, $match ) ) {
		$branch = $match[1];
	}
	$branch_text = '' === $branch ? esc_html__( 'The current release branch is unavailable.', 'keel-defaults' ) : sprintf(
		/* translators: %s: current WordPress major release branch, such as 7.1. */
		esc_html__( 'This site is on the %s release branch.', 'keel-defaults' ),
		esc_html( $branch )
	);
	$messages = array(
		'minor'   => __( 'Maintenance and security releases only. Major upgrades are not enabled.', 'keel-defaults' ),
		'all'     => __( 'Automatic releases, including major-version upgrades.', 'keel-defaults' ),
		'manual'  => __( 'Automatic core updates are off.', 'keel-defaults' ),
		'inherit' => __( 'WordPress or another component controls core updates.', 'keel-defaults' ),
	);
	$message  = is_string( $policy ) && isset( $messages[ $policy ] ) ? $messages[ $policy ] : __( 'The configured core update policy is unavailable.', 'keel-defaults' );

	// Use the decisions core already supplied; never apply update filters again.
	$observed = is_array( $settings ) && isset( $settings['minor'], $settings['major'], $settings['dev'] )
		&& is_bool( $settings['minor'] ) && is_bool( $settings['major'] ) && is_bool( $settings['dev'] );
	$detail   = '';
	if ( ! $observed ) {
		$detail = esc_html__( 'Core’s current filtered update settings are unavailable to this notice.', 'keel-defaults' );
	} elseif ( in_array( $policy, array( 'minor', 'all', 'manual' ), true ) ) {
		$minor = in_array( $policy, array( 'minor', 'all' ), true );
		$major = 'all' === $policy;
		$dev   = null === $off && defined( 'WP_AUTO_UPDATE_CORE' ) && 'all' === $policy;
		if ( $minor !== $settings['minor'] || $major !== $settings['major'] || $dev !== $settings['dev'] ) {
			$detail = esc_html__( 'Core’s current filtered settings differ from the configured policy. Other components or updater restrictions may be affecting it; review WordPress’s status below.', 'keel-defaults' );
		}
	}

	$items            = array(
		'core_update_policy' => array( __( 'WordPress', 'keel-defaults' ), $message ),
	);
	$extension_labels = array(
		'enabled'  => __( 'Automatic updates enabled.', 'keel-defaults' ),
		'disabled' => __( 'Automatic updates disabled.', 'keel-defaults' ),
		'unset'    => '',
	);
	foreach ( array(
		'plugin' => __( 'Plugins', 'keel-defaults' ),
		'theme'  => __( 'Themes', 'keel-defaults' ),
	) as $type => $label ) {
		$value                     = keel_defaults_extension_update_policy( $type );
		$individual                = 'plugin' === $type ? __( 'individual plugin settings', 'keel-defaults' ) : __( 'individual theme settings', 'keel-defaults' );
		$list_url                  = keel_defaults_extension_update_list_url( $type );
		$extension_labels['unset'] = sprintf(
			/* translators: %s: linked text for the native per-item update settings. */
			__( 'Follow %s.', 'keel-defaults' ),
			'' === $list_url ? esc_html( $individual ) : '<a href="' . esc_url( $list_url ) . '">' . esc_html( $individual ) . '</a>'
		);
		$items[ $type . '_auto_update_policy' ] = array( $label, $extension_labels[ $value ], 'unset' === $value );
	}
	$translations                      = keel_defaults_config_locked_value( 'auto_update_translations', keel_defaults_get( 'auto_update_translations' ) );
	$items['auto_update_translations'] = array( __( 'Translations', 'keel-defaults' ), 'yes' === $translations ? __( 'Automatic updates enabled.', 'keel-defaults' ) : __( 'Automatic updates disabled.', 'keel-defaults' ) );
	$list                              = '';
	foreach ( $items as $key => $item ) {
		$label = esc_html( $item[0] );
		$url   = '';
		if ( keel_defaults_network_manages( $key ) ) {
			if ( current_user_can( 'manage_network_options' ) ) {
				$url = network_admin_url( 'settings.php?page=keel-network' ) . '#' . keel_defaults_setting_anchor( $key );
			}
		} elseif ( current_user_can( 'manage_options' ) ) {
			if ( is_multisite() && in_array( $key, array( 'plugin_auto_update_policy', 'theme_auto_update_policy' ), true ) ) {
				if ( current_user_can( 'manage_network_options' ) ) {
					$url = get_admin_url( get_main_site_id(), 'options-general.php?page=keel' ) . '#' . keel_defaults_setting_anchor( $key );
				}
			} else {
				$url = keel_defaults_setting_url( $key );
			}
		}
		if ( '' !== $url ) {
			$label = '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
		}
		$text   = null !== $off ? __( 'Disabled in configuration.', 'keel-defaults' ) : $item[1];
		$html   = null === $off && ! empty( $item[2] );
		$counts = '';
		if ( in_array( $key, array( 'plugin_auto_update_policy', 'theme_auto_update_policy' ), true ) ) {
			$counts = keel_defaults_extension_update_counts_markup( 'plugin_auto_update_policy' === $key ? 'plugin' : 'theme' );
		}
		$list .= '<li><strong>' . $label . ':</strong> ' . ( $html ? $text : esc_html( $text ) ) . ( '' !== $counts ? ' ' . $counts : '' ) . '</li>';
	}
	$diagnostics = current_user_can( 'view_site_health_checks' ) && ( ! is_multisite() || current_user_can( 'manage_network_options' ) )
		? ' <a href="' . esc_url( admin_url( 'site-health.php?keel-update-diagnostics=1' ) ) . '">' . esc_html__( 'View update diagnostics', 'keel-defaults' ) . '</a>' : '';
	return '<div class="notice notice-info inline keel-update-policy-notice">'
		. '<p><strong>' . esc_html__( 'Keel update policy', 'keel-defaults' ) . '</strong></p>'
		. '<ul class="keel-update-policy-overview">' . $list . '</ul>'
		. ( '' !== $detail ? '<p>' . $detail . '</p>' : '' )
		. '<p class="description">' . $branch_text . ' ' . $source
		. ' ' . esc_html__( 'An update policy describes but does not guarantee the intended outcome. Cron, hosting restrictions, and other plugins can affect update delivery.', 'keel-defaults' )
		. '</p>' . ( '' !== $diagnostics ? '<p>' . $diagnostics . '</p>' : '' ) . '</div>';
}
