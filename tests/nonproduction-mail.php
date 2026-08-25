<?php
/**
 * Regression tests for suppressing outgoing mail outside production.
 *
 * The expensive direction is a false negative — mail leaving a copy of
 * production — so most of these assert suppression is ON where it should be.
 * The one that matters most asserts the opposite: production must never be
 * suppressed, whatever else is true.
 *
 * Run: php tests/nonproduction-mail.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_env']     = 'production';
$GLOBALS['keel_home']    = 'https://example.ca';
$GLOBALS['keel_actions'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }

function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function do_action( $hook, $arg = null ) { $GLOBALS['keel_actions'][] = array( $hook, $arg ); }
function get_option( $name, $default_value = false ) {
	// Keel keeps every setting in one array option, so the harness's option
	// store *is* that array — matching the other test files.
	return ( KEEL_DEFAULTS_OPTION === $name ) ? $GLOBALS['keel_options'] : $default_value;
}
function wp_get_environment_type() { return $GLOBALS['keel_env']; }
function current_user_can( $cap ) { return true; }
function esc_url( $s ) { return $s; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function network_home_url() { return 'https://example.ca'; }
function home_url() { return $GLOBALS['keel_home']; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test stub.
function trailingslashit( $p ) { return rtrim( (string) $p, '/\\' ) . '/'; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }

/*
 * Keel reads network policy before the site option, so every harness that calls
 * keel_defaults_get() needs this. Single site is the honest default here: the
 * multisite path has its own coverage in tests/network-policy.php.
 */
function is_multisite() {
	return false;
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// --- production is never suppressed ---
// The assertion that matters. Everything else is convenience; this one must
// not break.
$GLOBALS['keel_env'] = 'production';
keel_assert( false === keel_defaults_suppresses_mail(), 'Production sends mail.' );

// --- every other environment is ---
foreach ( array( 'staging', 'development', 'local' ) as $env ) {
	$GLOBALS['keel_env'] = $env;
	keel_assert( true === keel_defaults_suppresses_mail(), "'{$env}' does not send mail." );
}

// --- the host fallback carries into mail, not just the admin bar ---
// A local install that never set WP_ENVIRONMENT_TYPE reports 'production' from
// core. Asking core directly would send; this asks the same resolver the
// environment label uses.
$GLOBALS['keel_env']  = 'production';
$GLOBALS['keel_home'] = 'http://mysite.test:8080';
keel_assert( 'local' === keel_defaults_current_environment(), 'A .test host on a port resolves as local.' );
keel_assert( true === keel_defaults_suppresses_mail(), 'A local host with no constant set still suppresses.' );

$GLOBALS['keel_home'] = 'https://example.ca';
keel_assert( false === keel_defaults_suppresses_mail(), 'A real host with no constant is production again.' );

// --- the setting is a real switch ---
$GLOBALS['keel_env']     = 'staging';
$GLOBALS['keel_options'] = array( 'suppress_nonproduction_mail' => 'no' );
keel_assert( false === keel_defaults_suppresses_mail(), 'Turning the setting off sends mail, which is the point of it being visible.' );
$GLOBALS['keel_options'] = array();

// --- escape hatch ---
$GLOBALS['keel_filters']['keel_suppress_nonproduction_mail'] = false;
keel_assert( false === keel_defaults_suppresses_mail(), 'A staging site that must send can opt out in code.' );
unset( $GLOBALS['keel_filters']['keel_suppress_nonproduction_mail'] );

// --- the short-circuit answers "sent" ---
// Returning false would exercise the failure path on staging and never the
// success path, hiding bugs rather than surfacing them.
$GLOBALS['keel_actions'] = array();
$message                 = array(
	'to'      => 'customer@example.ca',
	'subject' => 'Order',
);
$result                  = keel_defaults_suppress_mail( null, $message );
keel_assert( true === $result, 'Suppressed mail reports success, so callers behave as they would in production.' );
keel_assert( 'keel_outgoing_mail_suppressed' === $GLOBALS['keel_actions'][0][0], 'An action fires so a mail catcher can still record it.' );
keel_assert( 'customer@example.ca' === $GLOBALS['keel_actions'][0][1]['to'], 'The suppressed message reaches that action intact.' );

// --- and the risky-config notice stops nagging about mail nothing sends ---
$GLOBALS['keel_env'] = 'staging';
ob_start();
keel_defaults_render_mail_config_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'No risky-mail warning while mail is suppressed.' );

// --- and it says so on screen ---------------------------------------------
// The setting being visible is most of the job, but "switched on" and "acting"
// are different facts — it does nothing on production. Somebody whose password
// reset never arrived is not going to read the settings screen to find out why.
$GLOBALS['keel_env'] = 'staging';
ob_start();
keel_defaults_render_mail_suppressed_notice();
$notice = (string) ob_get_clean();

keel_assert( '' !== $notice, 'Suppression is announced on screen, not left to the settings page.' );
keel_assert( false !== strpos( $notice, 'switched off' ), 'The notice says mail is off.' );
keel_assert( false !== strpos( $notice, 'staging' ), 'The notice names the environment responsible.' );
keel_assert( false !== strpos( $notice, 'password resets' ), 'The notice names the consequence someone will actually hit.' );
keel_assert( false === strpos( $notice, 'is-dismissible' ), 'Not dismissible: mail being off is a standing condition, not a one-time message.' );

$GLOBALS['keel_env'] = 'production';
ob_start();
keel_defaults_render_mail_suppressed_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'Production says nothing, because nothing is suppressed.' );

echo "nonproduction mail: OK\n";
