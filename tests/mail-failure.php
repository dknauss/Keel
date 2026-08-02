<?php
/**
 * Lightweight regression test for the mail_failure_notice policy.
 *
 * Run: php tests/mail-failure.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_env']     = 'production';

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function network_home_url() { return 'https://real-site.example-real.ca'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this stub *is* the wp_parse_url implementation for the test.
function is_email( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function current_user_can( $c ) { return true; }
function wp_get_environment_type() { return $GLOBALS['keel_env']; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

function keel_set_from( $email ) { $GLOBALS['keel_filters']['wp_mail_from'] = $email; }

// --- risky From-address detection ---
keel_set_from( 'wordpress@good-domain.example-real.ca' );
keel_assert( false === keel_defaults_mail_is_risky(), 'A real domain is not risky.' );

foreach ( array( 'wordpress@example.com', 'wordpress@foo.local', 'wordpress@bar.test', 'not-an-email', '' ) as $bad ) {
	keel_set_from( $bad );
	keel_assert( true === keel_defaults_mail_is_risky(), "Risky From address flagged: '{$bad}'." );
}

// --- config notice rendering ---
keel_set_from( 'wordpress@example.com' );
$GLOBALS['keel_env'] = 'production';
ob_start();
keel_defaults_render_mail_config_notice();
keel_assert( false !== strpos( ob_get_clean(), 'Site email may be misconfigured' ), 'Risky config warns on production.' );

$GLOBALS['keel_env'] = 'local';
ob_start();
keel_defaults_render_mail_config_notice();
keel_assert( '' === trim( ob_get_clean() ), 'No config warning on a local site.' );
$GLOBALS['keel_env'] = 'production';

// Recommendation text is filterable.
$GLOBALS['keel_filters']['keel_smtp_plugin_recommendation'] = 'Use Acme Mailer.';
ob_start();
keel_defaults_render_mail_config_notice();
keel_assert( false !== strpos( ob_get_clean(), 'Acme Mailer' ), 'Recommendation is filterable.' );
unset( $GLOBALS['keel_filters']['keel_smtp_plugin_recommendation'] );

// --- zero password-reset detection ---
$GLOBALS['pagenow'] = 'users.php';
$_GET               = array(
	'update'      => 'resetpassword',
	'reset_count' => '0',
);
keel_assert( true === keel_defaults_is_zero_reset_result(), 'Zero-count reset is detected.' );
ob_start();
keel_defaults_render_reset_failure_notice();
keel_assert( false !== strpos( ob_get_clean(), 'No password reset links were sent' ), 'Failure notice renders.' );
ob_start();
keel_defaults_hide_zero_reset_notice();
keel_assert( false !== strpos( ob_get_clean(), '#message.updated' ), 'Core success notice is hidden.' );

$_GET = array(
	'update'      => 'resetpassword',
	'reset_count' => '3',
);
keel_assert( false === keel_defaults_is_zero_reset_result(), 'A non-zero reset count is not a failure.' );

$GLOBALS['pagenow'] = 'edit.php';
$_GET               = array(
	'update'      => 'resetpassword',
	'reset_count' => '0',
);
keel_assert( false === keel_defaults_is_zero_reset_result(), 'Detection is scoped to users.php.' );

// Schema.
$schema = keel_defaults_schema();
keel_assert( 'yes' === $schema['mail_failure_notice']['default'], 'mail_failure_notice defaults on.' );
keel_assert( 'email' === $schema['mail_failure_notice']['group'], 'mail_failure_notice is in the Email group.' );
keel_assert( isset( keel_defaults_group_labels()['email'] ), 'Email group is registered.' );

fwrite( STDOUT, "mail failure tests passed.\n" );
