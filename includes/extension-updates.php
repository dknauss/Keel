<?php
/**
 * Explicit plugin/theme update policies; unchanged by default.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve shared-code policy from network governance or the network's main site.
 *
 * @param string $type Plugin or theme.
 * @return string Policy.
 */
function keel_defaults_extension_update_policy( $type ) {
	$key = $type . '_auto_update_policy';
	if ( is_multisite() ) {
		$network = keel_defaults_network_settings();
		if ( array_key_exists( $key, $network ) ) {
			$value = $network[ $key ];
		} elseif ( is_main_site() ) {
			$value = keel_defaults_get( $key );
		} else {
			$stored = get_blog_option( get_main_site_id(), KEEL_DEFAULTS_OPTION, array() );
			$value  = is_array( $stored ) ? ( $stored[ $key ] ?? 'unset' ) : 'unset';
		}
	} else {
		$value = keel_defaults_get( $key );
	}
	return in_array( $value, array( 'enabled', 'disabled', 'unset' ), true ) ? $value : 'unset';
}

/**
 * Preserve both booleans and core's null detection sentinel when unchanged.
 *
 * @param string    $type Plugin or theme.
 * @param bool|null $enabled Incoming filter decision.
 * @return bool|null
 */
function keel_defaults_extension_update_decision( $type, $enabled ) {
	if ( null !== keel_defaults_background_updates_off() ) {
		return $enabled;
	}
	$policy = keel_defaults_extension_update_policy( $type );
	return 'unset' === $policy ? $enabled : 'enabled' === $policy;
}

/**
 * Apply the plugin policy without changing per-item opt-in storage.
 *
 * @param bool|null $enabled Incoming decision.
 * @return bool|null
 */
function keel_defaults_allow_plugin_updates( $enabled ) {
	return keel_defaults_extension_update_decision( 'plugin', $enabled );
}

/**
 * Apply the theme policy without changing per-item opt-in storage.
 *
 * @param bool|null $enabled Incoming decision.
 * @return bool|null
 */
function keel_defaults_allow_theme_updates( $enabled ) {
	return keel_defaults_extension_update_decision( 'theme', $enabled );
}

/**
 * Evaluate a bounded set of installed items with cached update metadata only.
 *
 * Applies the documented item filter to core's baseline, never runs the updater.
 * Empty/missing offers are unknown rather than synthesized. Like core's list
 * tables, inspecting filters executes third-party callbacks; no callback is
 * attributed to a file or asserted to be the sole policy owner.
 *
 * @param string $type Plugin or theme.
 * @param array  $installed Installed item IDs.
 * @param mixed  $cache Existing update_plugins/update_themes transient.
 * @param array  $opted Existing per-item opt-ins.
 * @param bool   $type_enabled Core's type-wide gate for per-item opt-ins.
 * @return array Bounded report, no package URLs or offer contents.
 */
function keel_defaults_extension_update_report( $type, array $installed, $cache, array $opted, $type_enabled ) {
	$out = array(
		'examined'      => 0,
		'on'            => 0,
		'unknown'       => 0,
		'changed'       => array(),
		'changed_count' => 0,
		'limited'       => count( $installed ) > 200,
	);
	foreach ( array_slice( $installed, 0, 200 ) as $id ) {
		++$out['examined'];
		$response  = is_object( $cache ) && isset( $cache->response ) && is_array( $cache->response ) ? $cache->response : array();
		$no_update = is_object( $cache ) && isset( $cache->no_update ) && is_array( $cache->no_update ) ? $cache->no_update : array();
		$raw       = $response[ $id ] ?? $no_update[ $id ] ?? null;
		if ( ! is_object( $raw ) && ! is_array( $raw ) ) {
			++$out['unknown'];
			continue;
		}
		$item = (object) $raw;
		if ( ! isset( $item->{$type} ) || $item->{$type} !== $id ) {
			++$out['unknown'];
			continue;
		}
		$baseline = ! empty( $item->autoupdate ) || ( $type_enabled && in_array( $id, $opted, true ) );
		if ( ! empty( $item->disable_autoupdate ) ) {
			$baseline = false;
		}
		$decision = (bool) apply_filters( 'auto_update_' . $type, $baseline, clone $item );
		if ( $decision ) {
			++$out['on'];
		}
		if ( $decision !== $baseline ) {
			++$out['changed_count'];
			if ( count( $out['changed'] ) < 10 ) {
				$out['changed'][] = array(
					'id' => $id,
					'on' => $decision,
				);
			}
		}
	}
	return $out;
}

/** Authenticated Updates-screen report; no remote requests or installation probes. */
function keel_defaults_render_extension_updates() {
	if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
		return;
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}
	$rows    = array();
	$changed = array();
	$limited = false;
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$ids     = 'plugin' === $type ? array_keys( get_plugins() ) : array_keys( wp_get_themes() );
		$report  = keel_defaults_extension_update_report( $type, $ids, get_site_transient( 'update_' . $type . 's' ), (array) get_site_option( 'auto_update_' . $type . 's', array() ), wp_is_auto_update_enabled_for_type( $type ) );
		$limited = $limited || $report['limited'];
		$rows[]  = array(
			'plugin' === $type ? esc_html__( 'Plugins', 'keel-defaults' ) : esc_html__( 'Themes', 'keel-defaults' ),
			(string) $report['on'],
			(string) ( $report['examined'] - $report['on'] - $report['unknown'] ),
			(string) $report['unknown'],
			(string) $report['changed_count'],
		);
		foreach ( $report['changed'] as $row ) {
			$changed[] = array( '<code>' . esc_html( $row['id'] ) . '</code>', $row['on'] ? esc_html__( 'Turned on by code', 'keel-defaults' ) : esc_html__( 'Turned off by code', 'keel-defaults' ) );
		}
	}
	$html = '<div class="keel-defaults-extension-updates"><h3>' . esc_html__( 'Automatic plugin and theme updates', 'keel-defaults' ) . '</h3>'
		. keel_defaults_update_table_markup( array( esc_html__( 'Type', 'keel-defaults' ), esc_html__( 'Auto-updates on', 'keel-defaults' ), esc_html__( 'Off', 'keel-defaults' ), esc_html__( 'Unknown', 'keel-defaults' ), esc_html__( 'Changed by code', 'keel-defaults' ) ), $rows, array( '28%' ), __( 'Automatic plugin and theme updates', 'keel-defaults' ) );
	if ( $changed ) {
		$html .= '<h4>' . esc_html__( 'Changed by code', 'keel-defaults' ) . '</h4>'
			. keel_defaults_update_table_markup( array( esc_html__( 'Item', 'keel-defaults' ), esc_html__( 'Result', 'keel-defaults' ) ), $changed, array( '28%' ), __( 'Changed by code', 'keel-defaults' ) );
	}
	$html .= '<p class="description"><strong>' . esc_html__( 'Unknown:', 'keel-defaults' ) . '</strong> ' . esc_html__( 'WordPress has no update data for the item yet.', 'keel-defaults' )
		. ' <strong>' . esc_html__( 'Changed by code:', 'keel-defaults' ) . '</strong> ' . esc_html__( 'a plugin, theme or Keel setting overrides WordPress’s baseline decision; up to ten per type are listed.', 'keel-defaults' )
		. ' ' . esc_html__( 'Being on doesn’t guarantee an install: file permissions, version control or a disabled updater can still block it.', 'keel-defaults' );
	if ( $limited ) {
		$html .= ' ' . esc_html__( 'Partial coverage: only the first 200 items of each type are counted.', 'keel-defaults' );
	}
	$html .= '</p>';
	if ( is_multisite() ) {
		$html .= '<p class="description">' . esc_html__( 'Plugin/theme files and opt-ins are network-wide. Network policy wins; otherwise the main site’s Keel setting decides, including on subsite requests.', 'keel-defaults' ) . '</p>';
	}
	echo $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction.
}
