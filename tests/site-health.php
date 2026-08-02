<?php
/**
 * Lightweight regression test for the Site Health posture surface.
 *
 * Run: php tests/site-health.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Registration adds a direct test without clobbering existing ones.
$tests = keel_defaults_site_health_tests( array( 'direct' => array( 'core' => array() ) ) );
keel_assert( isset( $tests['direct']['keel_defaults_posture'] ), 'Posture test is registered.' );
keel_assert( isset( $tests['direct']['core'] ), 'Existing tests are preserved.' );
keel_assert( 'keel_defaults_site_health_posture' === $tests['direct']['keel_defaults_posture']['test'], 'Callback is wired.' );

// state labels
$schema = keel_defaults_schema();
keel_assert( 'On' === keel_defaults_state_label( $schema['require_strong_passwords'], 'yes' ), 'Toggle On label.' );
keel_assert( 'Off' === keel_defaults_state_label( $schema['require_strong_passwords'], 'no' ), 'Toggle Off label.' );
keel_assert( 'Unchanged' === keel_defaults_state_label( $schema['frontend_admin_bar_behavior'], '' ), 'Empty select reads Unchanged.' );

// Default posture (schema defaults: strong passwords + rest discovery both on) → good.
$GLOBALS['keel_options'] = array();
$result                  = keel_defaults_site_health_posture();
keel_assert( 'good' === $result['status'], 'Default posture is good.' );
keel_assert( false !== strpos( $result['description'], 'Security &' ) || false !== strpos( $result['description'], 'Security' ), 'Description lists the Security group.' );
keel_assert( false !== strpos( $result['actions'], 'page=keel' ), 'Actions link to the settings page.' );

// Turning off an unambiguous security item escalates to recommended, with a note.
$GLOBALS['keel_options']['keel_settings'] = array( 'require_strong_passwords' => 'no' );
$result                                   = keel_defaults_site_health_posture();
keel_assert( 'recommended' === $result['status'], 'Strong passwords off → recommended.' );
keel_assert( 'orange' === $result['badge']['color'], 'Recommended badge is orange.' );
keel_assert( false !== strpos( $result['description'], 'Strong passwords are off' ), 'A note explains the recommendation.' );

// An opinionated UX toggle being off does NOT escalate the status (no nagging).
$GLOBALS['keel_options']['keel_settings'] = array( 'disable_emojis' => 'no' );
$result                                   = keel_defaults_site_health_posture();
keel_assert( 'good' === $result['status'], 'An opinionated toggle off stays informational (good).' );

fwrite( STDOUT, "site health tests passed.\n" );
