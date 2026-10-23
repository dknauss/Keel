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
 * Explain configured policy without rerunning update filters or promising delivery.
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
		$source = sprintf( esc_html__( 'Configured policy source: %s in wp-config.php disables background updates.', 'keel-defaults' ), '<code>' . esc_html( $off ) . '</code>' );
	} elseif ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$source = esc_html__( 'Configured policy source: WP_AUTO_UPDATE_CORE in wp-config.php. Keel does not override that declaration.', 'keel-defaults' );
	} else {
		$source = $network ? esc_html__( 'Configured policy source: Keel network policy.', 'keel-defaults' ) : esc_html__( 'Configured policy source: Keel Site Defaults.', 'keel-defaults' );
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
		'minor'   => __( 'Automatic WordPress core updates are limited by this policy to maintenance and security releases within the current release branch. Major-version upgrades are not enabled by this policy.', 'keel-defaults' ),
		'all'     => __( 'This policy permits automatic WordPress core updates, including major-version upgrades to a different release branch.', 'keel-defaults' ),
		'manual'  => __( 'No automatic WordPress core releases are enabled by this policy. A host or another updater may apply updates separately.', 'keel-defaults' ),
		'inherit' => __( 'Keel leaves the core update policy unchanged. WordPress or another component controls which releases are enabled; see WordPress’s status above.', 'keel-defaults' ),
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
			$detail = esc_html__( 'Core’s current filtered settings differ from the configured policy. Other components or updater restrictions may be affecting it; review WordPress’s status above.', 'keel-defaults' );
		}
	}

	$link = '';
	if ( $network && current_user_can( 'manage_network_options' ) ) {
		$url  = network_admin_url( 'settings.php?page=keel-network' ) . '#' . keel_defaults_setting_anchor( 'core_update_policy' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review the network core update policy', 'keel-defaults' ) . '</a>';
	} elseif ( ! $network && current_user_can( 'manage_options' ) ) {
		$link = '<a href="' . esc_url( keel_defaults_setting_url( 'core_update_policy' ) ) . '">' . esc_html__( 'Review the core update policy in Site Defaults', 'keel-defaults' ) . '</a>';
	}

	return '<div class="notice notice-info inline keel-update-policy-notice">'
		. '<p><strong>' . esc_html__( 'Keel update policy', 'keel-defaults' ) . '</strong></p>'
		. '<p>' . $branch_text . ' ' . esc_html( $message ) . '</p>'
		// Source, caveat and link share one paragraph to keep the notice short.
		. '<p>' . $source
		. ( '' !== $detail ? ' ' . $detail : '' )
		. ' ' . esc_html__( 'This describes policy, not a guarantee that an update will install. Cron, hosting restrictions and other filters can affect delivery.', 'keel-defaults' )
		. ( '' !== $link ? ' ' . $link : '' )
		. '</p>'
		. '</div>';
}
