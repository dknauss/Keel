<?php
/**
 * Guardrail tests for the "Password policy exemptions" control: only
 * low-privilege roles are exemptable, and sanitize refuses anything else.
 *
 * Run: php tests/password-exempt-roles.php
 *
 * @package keel
 */
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) {
	return $s; }
function esc_html( $s ) {
	return $s; }
function esc_html__( $s, $d = null ) {
	return $s; }
function esc_html_e( $s, $d = null ) {
	echo $s; }
function esc_attr( $s ) {
	return $s; }
function esc_attr_e( $s, $d = null ) {
	echo $s; }
function apply_filters( $hook, $value ) {
	return $value; }
function get_option( $k, $d = false ) {
	return $d; }
function absint( $v ) {
	return abs( (int) $v ); }
function translate_user_role( $n ) {
	return $n; }

class Keel_Test_WP_Roles {
	public $roles;
	public function __construct( $roles ) {
		$this->roles = $roles; }
}

$GLOBALS['keel_test_roles'] = array(
	'administrator' => array(
		'name'         => 'Administrator',
		'capabilities' => array(
			'manage_options' => true,
			'edit_posts'     => true,
		),
	),
	'editor'        => array(
		'name'         => 'Editor',
		'capabilities' => array(
			'edit_others_posts' => true,
			'edit_posts'        => true,
		),
	),
	'author'        => array(
		'name'         => 'Author',
		'capabilities' => array(
			'publish_posts' => true,
			'upload_files'  => true,
		),
	),
	'contributor'   => array(
		'name'         => 'Contributor',
		'capabilities' => array( 'edit_posts' => true ),
	),
	'subscriber'    => array(
		'name'         => 'Subscriber',
		'capabilities' => array( 'read' => true ),
	),
	'customer'      => array(
		'name'         => 'Customer',
		'capabilities' => array( 'read' => true ),
	),
	'shop_manager'  => array(
		'name'         => 'Shop manager',
		'capabilities' => array( 'manage_woocommerce' => true ),
	),
);
function wp_roles() {
	return new Keel_Test_WP_Roles( $GLOBALS['keel_test_roles'] ); }

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Only roles with no sensitive capability are offered.
$ex = keel_defaults_exemptable_roles();
keel_assert( isset( $ex['subscriber'] ), 'subscriber is exemptable.' );
keel_assert( isset( $ex['customer'] ), 'customer (read-only) is exemptable.' );
keel_assert( ! isset( $ex['contributor'] ), 'contributor (edit_posts) is NOT exemptable.' );
keel_assert( ! isset( $ex['author'] ), 'author is NOT exemptable.' );
keel_assert( ! isset( $ex['editor'] ), 'editor is NOT exemptable.' );
keel_assert( ! isset( $ex['administrator'] ), 'administrator is NOT exemptable.' );
keel_assert( ! isset( $ex['shop_manager'] ), 'shop_manager (manage_woocommerce) is NOT exemptable.' );

// Sanitize is the guardrail: a forged privileged role is dropped.
$clean = keel_defaults_sanitize( array( 'password_exempt_roles' => array( 'subscriber', 'administrator', 'editor' ) ) );
keel_assert( array( 'subscriber' ) === $clean['password_exempt_roles'], 'Sanitize keeps only exemptable roles (drops administrator/editor).' );

// An empty selection is allowed — it means enforce for everyone.
$clean = keel_defaults_sanitize( array( 'password_exempt_roles' => array() ) );
keel_assert( array() === $clean['password_exempt_roles'], 'Empty exempt list is allowed (everyone enforced).' );

// A low-privilege custom role (customer) can be exempted through the UI.
$clean = keel_defaults_sanitize( array( 'password_exempt_roles' => array( 'customer' ) ) );
keel_assert( array( 'customer' ) === $clean['password_exempt_roles'], 'A low-privilege custom role can be exempted.' );

fwrite( STDOUT, "password exempt-roles tests passed.\n" );
