<?php
/**
 * Static disclosure of core's WordPress.org update-check data categories.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a documented baseline to Site Health Info, without inspecting request data.
 *
 * No inventories, HTTP interception, update calls, storage or site-value collection.
 * Core escapes these strings when rendering and copying Site Health information.
 *
 * @param mixed $info Existing Site Health information.
 * @return mixed Information with the authorized disclosure section.
 */
function keel_defaults_update_disclosure_info( $info ) {
	global $wp_version;
	if ( ! is_array( $info ) || ! current_user_can( 'view_site_health_checks' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
		return $info;
	}

	// A maintained source baseline, never a claim to know actual network traffic.
	$known  = is_string( $wp_version ) && strlen( $wp_version ) <= 32
		&& preg_match( '/^(?:6\.[4-9]|7\.[01])(?:\.\d+)?$/D', $wp_version );
	$fields = array(
		'coverage' => array(
			'label' => __( 'Coverage', 'keel-defaults' ),
			'value' => $known
				? __( 'Documented core baseline for stable WordPress 6.4–7.1; not evidence that this site sent a request. Categories are a summary, not an exhaustive payload schema.', 'keel-defaults' )
				: __( 'This WordPress version is unverified by this disclosure. Request categories are withheld; consult the installed core source.', 'keel-defaults' ),
		),
		'behavior' => array(
			'label' => __( 'What this report does', 'keel-defaults' ),
			'value' => __( 'Explains normal update checks without running them. It makes no extra requests, reads no installed-item inventory, captures no traffic or payloads, stores nothing and offers no blocking switch. Core, plugins and hosts can modify, redirect or prevent requests. Actual transmission and delivery remain unverified.', 'keel-defaults' ),
		),
		'scope'    => array(
			'label' => __( 'Multisite scope', 'keel-defaults' ),
			'value' => __( 'Update checks can include installation/network-wide metadata and the requesting site’s URL or activation context. Plugin and theme files are shared across a network. This is not a per-site traffic log or a network inventory.', 'keel-defaults' ),
		),
	);
	if ( $known ) {
		$fields['core']    = array(
			'label' => __( 'WordPress core update checks', 'keel-defaults' ),
			'value' => __( 'api.wordpress.org/core/version-check/1.7/ — checks core releases and translations. Categories: site and installation URLs in request identity/headers; WordPress, PHP and database versions; locale and package language; site/user counts and multisite status; initial database version; PHP extension versions, platform and image-format support; installed core translation metadata. Newer core can include database storage-engine compatibility metadata. Callers may add statistics and a configured development channel.', 'keel-defaults' ),
		);
		$fields['plugins'] = array(
			'label' => __( 'Plugin update checks', 'keel-defaults' ),
			'value' => __( 'api.wordpress.org/plugins/update-check/1.1/ — checks plugin releases and translations. Categories: WordPress version and site URL in request identity; metadata for installed plugins, including inactive plugins; active-plugin identifiers; installed translation metadata and requested locales. Callers may add statistics.', 'keel-defaults' ),
		);
		$fields['themes']  = array(
			'label' => __( 'Theme update checks', 'keel-defaults' ),
			'value' => __( 'api.wordpress.org/themes/update-check/1.1/ — checks theme releases and translations. Categories: WordPress version and site URL in request identity; installed theme names, identifiers and versions, author names and author/update URIs, including inactive themes; active theme identifier; installed translation metadata and requested locales. Callers may add statistics.', 'keel-defaults' ),
		);
		$fields['limits']  = array(
			'label' => __( 'Limits and other requests', 'keel-defaults' ),
			'value' => __( 'This covers the three normal core update-check endpoints only. It does not describe package downloads, directory searches, third-party update services, telemetry from other components or Keel’s separate stable-check request. Core prefers HTTPS where supported and can fall back to HTTP. Disabling update checks can prevent security updates; this disclosure changes no update behavior.', 'keel-defaults' ),
		);
	}
	$fields['sources']              = array(
		'label' => __( 'Core source references', 'keel-defaults' ),
		'value' => 'https://developer.wordpress.org/reference/functions/wp_version_check/ | https://developer.wordpress.org/reference/functions/wp_update_plugins/ | https://developer.wordpress.org/reference/functions/wp_update_themes/',
	);
	$info['keel_update_disclosure'] = array(
		'label'       => __( 'WordPress.org update-check disclosure', 'keel-defaults' ),
		'description' => __( 'Read-only documentation of data categories core normally sends. No actual site values or request payloads are shown.', 'keel-defaults' ),
		'fields'      => $fields,
	);
	return $info;
}
