<?php
/**
 * Lightweight regression test for strong-password role-scoping.
 *
 * Run: php tests/password-scoping.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

function keel_user( $roles ) {
	$u = new stdClass();
	$u->roles = (array) $roles;
	return $u;
}

// --- enforcement gate ---
keel_assert( true === keel_defaults_password_enforced_for_user( null ), 'Unknown user → enforce.' );
keel_assert( true === keel_defaults_password_enforced_for_user( keel_user( array() ) ), 'Empty roles → enforce.' );
keel_assert( true === keel_defaults_password_enforced_for_user( keel_user( array( 'administrator' ) ) ), 'Administrator → enforce.' );
keel_assert( true === keel_defaults_password_enforced_for_user( keel_user( array( 'editor' ) ) ), 'Editor → enforce.' );
keel_assert( false === keel_defaults_password_enforced_for_user( keel_user( array( 'subscriber' ) ) ), 'Subscriber → exempt.' );
keel_assert( true === keel_defaults_password_enforced_for_user( keel_user( array( 'subscriber', 'editor' ) ) ), 'Any privileged role among many → enforce.' );

// role as a singular string (REST prepared-user shape)
$s = new stdClass();
$s->role = 'subscriber';
keel_assert( false === keel_defaults_password_enforced_for_user( $s ), 'Singular role string is honoured.' );

// filter: clear weak roles → enforce for everyone
$GLOBALS['keel_filters']['keel_weak_roles'] = array();
keel_assert( true === keel_defaults_password_enforced_for_user( keel_user( array( 'subscriber' ) ) ), 'Empty keel_weak_roles enforces for all.' );
// filter: add customer → exempt a subscriber/customer
$GLOBALS['keel_filters']['keel_weak_roles'] = array( 'subscriber', 'customer' );
keel_assert( false === keel_defaults_password_enforced_for_user( keel_user( array( 'customer' ) ) ), 'Extending keel_weak_roles exempts the added role.' );
unset( $GLOBALS['keel_filters']['keel_weak_roles'] );

// --- validator honours the gate (no network: both paths exit before HIBP) ---
keel_assert( true === keel_defaults_validate_password( 'short', keel_user( array( 'subscriber' ) ) ), 'Exempt subscriber passes even a weak password (gate short-circuits).' );
$result = keel_defaults_validate_password( 'short', keel_user( array( 'administrator' ) ) );
keel_assert( is_wp_error( $result ) && 'keel_password_too_short' === $result->get_error_code(), 'Enforced admin still fails a short password.' );

fwrite( STDOUT, "password scoping tests passed.\n" );
