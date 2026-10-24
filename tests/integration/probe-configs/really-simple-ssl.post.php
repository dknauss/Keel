<?php
/**
 * Really Simple Security 9.8.3 — every free hardening option that bears on
 * account discovery, plus its three free security headers.
 *
 * Written through rsssl_update_option(), the function the plugin's own REST
 * save handler and its `wp rsssl update_option` command both call, as an
 * administrator because it returns early for anyone who cannot manage the
 * plugin. That also runs the plugin's after-save actions, which is where the
 * header rules are generated.
 *
 * Keys are the field ids in settings/config/fields/hardening-basic.php and
 * security-headers.php.
 *
 * @package Keel
 */

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins ? $admins[0]->ID : 1 );

$fields = array(
	'disable_user_enumeration' => true,
	'disable_login_feedback'   => true,
	'hide_wordpress_version'   => true,
	'block_display_is_login'   => true,
	'disable_xmlrpc'           => true,
	'x_content_type_options'   => true,
	'x_frame_options'          => 'SAMEORIGIN',
	'referrer_policy'          => 'strict-origin-when-cross-origin',
);

foreach ( $fields as $name => $value ) {
	rsssl_update_option( $name, $value );
}

if ( function_exists( 'rsssl_request_advanced_headers_rebuild' ) ) {
	rsssl_request_advanced_headers_rebuild();
}

$stored = (array) get_option( 'rsssl_options', array() );
foreach ( array_keys( $fields ) as $name ) {
	echo $name, '=', wp_json_encode( $stored[ $name ] ?? null ), "\n";
}
