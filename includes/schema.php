<?php
/**
 * Settings schema and value access — the data layer that drives the whole plugin.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth: every setting, its default, type, and label.
 *
 * Each entry declares a type ('toggle' yes/no, 'select', 'range', or 'number')
 * and the group (fieldset) it renders under on the settings screen.
 *
 * @return array[]
 */
function keel_defaults_schema() {
	// Pure, static data — no filters touch it — so a request-scoped memo is safe
	// and collapses the ~35 rebuilds/request (bootstrap + Site Health) into one.
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$schema = array(

		// --- Security ---------------------------------------------------
		'restrict_rest_user_discovery'    => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'rest',
		),
		'disable_rest'                    => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'rest',
		),
		'xmlrpc_allow_pingbacks'          => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'xmlrpc',
			'depends' => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'xmlrpc_allow_remote_publishing'  => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'xmlrpc',
			'depends' => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'xmlrpc_allow_multicall'          => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'xmlrpc',
			'depends' => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'block_xmlrpc_endpoint'           => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
			'section' => 'xmlrpc',
		),
		'disable_application_passwords'   => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'require_strong_passwords'        => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'limit_unfiltered_html_to_admins' => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'reserved_usernames'              => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'remove_version'                  => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'security_headers'                => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
		),
		'frame_options'                   => array(
			'default' => 'SAMEORIGIN',
			'type'    => 'select',
			'group'   => 'security',
			'choices' => array(
				'SAMEORIGIN',
				'DENY',
				'',
			),
		),
		'disable_ai_connectors'           => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'security',
		),

		// --- Updates ----------------------------------------------------
		'core_update_policy'              => array(
			'default' => 'minor',
			'type'    => 'select',
			'group'   => 'updates',
			'choices' => array(
				'minor',
				'all',
				'manual',
				'inherit',
			),
		),
		'auto_update_translations'        => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'updates',
		),

		// --- Content & public surfaces ---------------------------------
		'disable_comments'                => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'disable_pingbacks'               => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'disable_self_pingbacks'          => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'disable_author_archives'         => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'redirect_attachment_pages'       => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'disable_emojis'                  => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'content',
		),
		'disable_post_passwords'          => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'content',
		),

		// --- Editor ----------------------------------------------------
		'force_classic_editor'            => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'editor',
		),

		// --- Media -----------------------------------------------------
		'lowercase_upload_filenames'      => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'media',
		),
		'media_sizes_panel'               => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'media',
		),

		// --- Email -----------------------------------------------------
		'mail_failure_notice'             => array(
			'default' => 'yes',
			'type'    => 'toggle',
			'group'   => 'email',
		),

		// --- Admin & front-end UX --------------------------------------
		'title_only_admin_search'         => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'ux',
		),
		'frontend_admin_bar_behavior'     => array(
			'default' => '',
			'type'    => 'select',
			'group'   => 'ux',
			'choices' => array(
				'',
				'hide_non_admins',
				'hide_all',
				'auto_hide',
			),
		),
		'admin_menu_width'                => array(
			'default' => 'default',
			'type'    => 'range',
			'group'   => 'ux',
			// Ordered stops. The slider posts an index (0–4) which sanitize maps back
			// to the value — this deliberately avoids numeric option keys.
			'values'  => array( 'default', '200', '240', '280', '300' ),
		),
		'helper_list_columns'             => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'ux',
		),
		'environment_indicator'           => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'ux',
		),

		// --- Login & sessions ------------------------------------------
		'disable_remember_me'             => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'login',
		),
		'session_regular_days'            => array(
			'default' => 2,
			'type'    => 'number',
			'group'   => 'login',
			'min'     => 1,
		),
		'remember_me_days'                => array(
			'default' => 14,
			'type'    => 'number',
			'group'   => 'login',
			'min'     => 1,
			'depends' => array(
				'field'     => 'disable_remember_me',
				'hide_when' => 'yes',
			),
		),

		// --- Branding ---------------------------------------------------
		'login_logo_behavior'             => array(
			'default' => 'keep_default',
			'type'    => 'select',
			'group'   => 'branding',
			'choices' => array(
				'keep_default',
				'remove_logo',
				'unlink_logo',
				'replace_logo',
			),
		),

		// --- Performance ------------------------------------------------
		'throttle_heartbeat'              => array(
			'default' => 'no',
			'type'    => 'toggle',
			'group'   => 'performance',
		),
	);

	return $schema;
}

/**
 * Read one setting, falling back to its schema default.
 *
 * @param string $key Schema key (without the keel_ prefix).
 * @return mixed
 */
function keel_defaults_get( $key ) {
	$schema = keel_defaults_schema();
	if ( ! isset( $schema[ $key ] ) ) {
		return null;
	}

	// Deliberately uncached: the option is autoloaded, so get_option() answers
	// from the options cache without a query. A static here would only add a
	// second cache that goes stale the moment anything calls update_option()
	// — which is exactly what saving the settings screen does.
	$stored = get_option( KEEL_DEFAULTS_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_key_exists( $key, $stored ) ? $stored[ $key ] : $schema[ $key ]['default'];
}

/**
 * Convenience boolean check for toggle settings.
 *
 * @param string $key Schema key.
 * @return bool
 */
function keel_defaults_enabled( $key ) {
	return 'yes' === keel_defaults_get( $key );
}
