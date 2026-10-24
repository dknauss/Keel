<?php
/**
 * The security entry point exposes password callbacks without runtime work.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );

function get_option( ...$args ) {
	throw new RuntimeException( 'Loading password modules must not read settings.' );
}
function wp_remote_get( ...$args ) {
	throw new RuntimeException( 'Loading password modules must not issue requests.' );
}
function update_option( ...$args ) {
	throw new RuntimeException( 'Loading password modules must not write settings.' );
}
function add_action( ...$args ) {
	throw new RuntimeException( 'Password hooks belong in bootstrap, not module loading.' );
}
function add_filter( ...$args ) {
	throw new RuntimeException( 'Password hooks belong in bootstrap, not module loading.' );
}

require_once dirname( __DIR__ ) . '/includes/security.php';
require_once dirname( __DIR__ ) . '/includes/security.php';

$callbacks = array(
	'keel_defaults_password_enforced_for_user',
	'keel_defaults_exemptable_roles',
	'keel_defaults_validate_password',
	'keel_defaults_validate_profile_password',
	'keel_defaults_validate_reset_password',
	'keel_defaults_validate_rest_password',
	'keel_defaults_guard_rest_password_arg',
	'keel_defaults_rest_password_context',
	'keel_hibp_response_limit',
	'keel_hibp_body_is_complete',
	'keel_hibp_body_is_valid',
	'keel_hibp_range_contains',
	'keel_hibp_unavailable',
	'keel_hibp_budget_admit',
	'keel_hibp_last_success',
	'keel_hibp_last_failure',
	'keel_hibp_budget_evidence',
	'keel_hibp_lookup',
	'keel_password_is_pwned',
	'keel_hibp_password_verdict',
	'keel_defaults_password_rescreen_interval',
	'keel_defaults_capture_login_password',
	'keel_defaults_screen_login_password',
	'keel_defaults_screen_user_password',
	'keel_defaults_password_set_forgets_screening',
	'keel_defaults_profile_update_forgets_screening',
	'keel_defaults_render_breached_password_notice',
	'keel_defaults_site_health_breached_passwords',
);
foreach ( $callbacks as $callback ) {
	if ( ! is_callable( $callback ) ) {
		fwrite( STDERR, "Security entry point no longer exposes {$callback}.\n" );
		exit( 1 );
	}
}
if ( ! is_callable( 'keel_defaults_require_rest_auth' ) || ! is_callable( 'keel_defaults_set_frame_option_header' ) ) {
	fwrite( STDERR, "Unrelated security callbacks must remain available.\n" );
	exit( 1 );
}

fwrite( STDOUT, 'password module loading: OK (' . count( $callbacks ) . " password callbacks, no runtime work on load)\n" );
