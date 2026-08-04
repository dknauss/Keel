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

/*
 * Every sensitive capability has to be load-bearing on its own.
 *
 * The roles above are realistic, which is exactly why they could not prove this:
 * a real administrator holds manage_options *and* edit_posts, so deleting
 * manage_options from the sensitive list left the assertion above still passing
 * on edit_posts. Mutation testing caught it — removing that entry killed no
 * test, while every other invariant in this plugin killed one.
 *
 * That gap is not theoretical. A custom role holding manage_options but no
 * editorial capability — a billing or settings-only "Site Manager", which sites
 * really do create — would have become exemptable from the password policy with
 * nothing to catch it.
 *
 * So: one synthetic role per capability, holding that capability and nothing
 * else. Delete any entry from the list and exactly one of these fails, naming it.
 */
$sensitive = array(
	'edit_posts',
	'edit_pages',
	'publish_posts',
	'edit_others_posts',
	'upload_files',
	'moderate_comments',
	'manage_categories',
	'manage_options',
	'edit_theme_options',
	'list_users',
	'edit_users',
	'manage_woocommerce',
	'edit_shop_orders',
);

foreach ( $sensitive as $cap ) {
	$slug                                = 'keel_probe_' . $cap;
	$GLOBALS['keel_test_roles'][ $slug ] = array(
		'name'         => 'Probe ' . $cap,
		'capabilities' => array( $cap => true ),
	);

	$probe = keel_defaults_exemptable_roles();

	keel_assert(
		! isset( $probe[ $slug ] ),
		"A role holding only '{$cap}' is NOT exemptable (that capability is missing from the sensitive list)."
	);

	unset( $GLOBALS['keel_test_roles'][ $slug ] );
}

// And the converse, so the loop above cannot pass by rejecting everything: a
// role holding only a harmless capability is still offered.
$GLOBALS['keel_test_roles']['keel_probe_harmless'] = array(
	'name'         => 'Probe harmless',
	'capabilities' => array( 'read' => true ),
);
$probe = keel_defaults_exemptable_roles();
keel_assert( isset( $probe['keel_probe_harmless'] ), 'A role holding only a harmless capability is still exemptable.' );
unset( $GLOBALS['keel_test_roles']['keel_probe_harmless'] );

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
