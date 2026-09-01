<?php
/**
 * Emit a matching authenticated cookie and version-bound nonce for the matrix.
 *
 * Run only through WP-CLI in a disposable integration site.
 *
 * @package keel
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$target     = keel_defaults_branch_tip();
$user       = get_user_by( 'login', 'admin' );
$expiration = time() + HOUR_IN_SECONDS;
$manager    = WP_Session_Tokens::get_instance( $user->ID );
$token      = $manager->create( $expiration );
$cookie     = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token );

// wp_create_nonce() reads the current session token from the request cookie.
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
wp_set_current_user( $user->ID );

echo wp_json_encode(
	array(
		'cookie_name'  => LOGGED_IN_COOKIE,
		'cookie_value' => $cookie,
		'nonce'        => wp_create_nonce( keel_defaults_backport_nonce_action( $target ) ),
		'target'       => $target,
	)
);
