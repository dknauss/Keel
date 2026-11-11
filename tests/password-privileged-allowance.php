<?php
/**
 * Who the breach check keeps allowance back for.
 *
 * The check works from a shared allowance, and half of it is kept back for
 * privileged accounts. That is only worth anything if "privileged" cannot be
 * claimed by a request, or handed out by the site to anyone who asks. So this
 * pins who counts:
 *
 * - a stored account holding a sensitive capability, and nothing a request
 *   merely describes;
 * - not a role that open registration hands out;
 * - on a network where visitors may create sites, not a role on any site but
 *   the main one;
 * - a Super Admin, always.
 *
 * And it runs the real validator against the SQL-backed store double, so "may
 * use the reserve" is measured by whether a lookup actually went out.
 *
 * Run: php tests/password-privileged-allowance.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']      = array();
$GLOBALS['keel_options']      = array();
$GLOBALS['keel_site_options'] = array();
$GLOBALS['keel_uuid']         = 0;
$GLOBALS['keel_multisite']    = false;
$GLOBALS['keel_main_site']    = true;
$GLOBALS['keel_super_admins'] = array();
$GLOBALS['keel_current']      = null;
$GLOBALS['keel_http_calls']   = 0;
$GLOBALS['keel_http_reply']   = array(
	'code' => 200,
	'body' => '',
);

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function get_site_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_site_options'] ) ? $GLOBALS['keel_site_options'][ $key ] : $default;
}
function is_main_site() { return $GLOBALS['keel_main_site']; }
function is_super_admin( $user_id = 0 ) { return in_array( (int) $user_id, $GLOBALS['keel_super_admins'], true ); }
function wp_get_current_user() { return $GLOBALS['keel_current']; }
function get_current_user_id() { return $GLOBALS['keel_current'] ? $GLOBALS['keel_current']->ID : 0; }
function wp_generate_uuid4() {
	++$GLOBALS['keel_uuid'];
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['keel_uuid'] );
}
function wp_remote_get( $url, $args = array() ) {
	++$GLOBALS['keel_http_calls'];
	return $GLOBALS['keel_http_reply'];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['code'] ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/**
 * Capabilities by role, as core and WooCommerce hand them out.
 *
 * @param WP_User $user Account.
 * @param string  $cap  Capability.
 * @return bool
 */
function user_can( $user, $cap ) {
	$caps = array(
		'administrator' => array( 'manage_options', 'edit_users', 'list_users', 'edit_theme_options', 'edit_posts', 'upload_files' ),
		'editor'        => array( 'edit_others_posts', 'moderate_comments', 'manage_categories', 'edit_pages', 'edit_posts', 'upload_files' ),
		'author'        => array( 'publish_posts', 'edit_posts', 'upload_files' ),
		'contributor'   => array( 'edit_posts' ),
		'shop_manager'  => array( 'manage_woocommerce', 'edit_shop_orders' ),
		'subscriber'    => array( 'read' ),
		'customer'      => array( 'read' ),
	);
	foreach ( (array) $user->roles as $role ) {
		if ( in_array( $cap, $caps[ $role ] ?? array(), true ) ) {
			return true;
		}
	}
	return false;
}

require_once __DIR__ . '/stubs/wp-user.php';
require_once __DIR__ . '/stubs/wp-error.php';

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
define( 'ABSPATH', __DIR__ . '/' );

// Declared before the store double, which otherwise answers false for every test.
function is_multisite() { return $GLOBALS['keel_multisite']; }

require __DIR__ . '/stubs/hibp-store.php';
require dirname( __DIR__ ) . '/keel.php';

$fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * A stored account with the given roles.
 *
 * @param int      $id    User ID.
 * @param string[] $roles Role slugs.
 * @return WP_User
 */
function keel_account( $id, $roles ) {
	return new WP_User( $id, (array) $roles, 'login' . $id, 'Name ' . $id );
}

/**
 * Put the site back to a single site with registration closed.
 *
 * @return void
 */
function keel_plain_site() {
	$GLOBALS['keel_options']      = array();
	$GLOBALS['keel_site_options'] = array();
	$GLOBALS['keel_multisite']    = false;
	$GLOBALS['keel_main_site']    = true;
	$GLOBALS['keel_super_admins'] = array();
	$GLOBALS['keel_current']      = null;
}

// --- the capability list is shared with the exemption guardrail ---
$sensitive = keel_defaults_sensitive_capabilities();
keel_assert( in_array( 'manage_options', $sensitive, true ) && in_array( 'edit_posts', $sensitive, true ) && in_array( 'manage_woocommerce', $sensitive, true ), 'The sensitive capabilities cover administration, editing and shop management.' );
keel_assert( ! in_array( 'read', $sensitive, true ), 'Reading is not a sensitive capability.' );

// --- only a stored account can be privileged ---
keel_plain_site();
$described        = new stdClass();
$described->ID    = 1;
$described->roles = array( 'administrator' );
$described->role  = 'administrator';
foreach ( array( null, false, 0, 1, 'administrator', array( 'roles' => array( 'administrator' ) ), $described, keel_account( 0, array( 'administrator' ) ) ) as $not_an_account ) {
	keel_assert( false === keel_defaults_is_privileged_account( $not_an_account ), 'Nothing but a stored account is privileged: ' . gettype( $not_an_account ) . '.' );
}

// --- by capability, on an ordinary site ---
foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ) as $role ) {
	keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( $role ) ) ), "A {$role} is privileged." );
}
foreach ( array( 'subscriber', 'customer' ) as $role ) {
	keel_assert( false === keel_defaults_is_privileged_account( keel_account( 5, array( $role ) ) ), "A {$role} is not." );
}
keel_assert( false === keel_defaults_is_privileged_account( keel_account( 5, array() ) ), 'An account with no role is not: unknown is not privileged here.' );
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'subscriber', 'editor' ) ) ), 'Any privileged role among several is enough.' );

// --- a role that registering hands out proves nothing ---
$GLOBALS['keel_options'] = array(
	'users_can_register' => 1,
	'default_role'       => 'contributor',
);
keel_assert( false === keel_defaults_is_privileged_account( keel_account( 5, array( 'contributor' ) ) ), 'Where registering makes you a contributor, a contributor is not privileged.' );
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'contributor', 'editor' ) ) ), 'Somebody who was also made an editor still is.' );
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'editor' ) ) ), 'A role registering does not hand out is unaffected.' );
$GLOBALS['keel_options']['users_can_register'] = 0;
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'contributor' ) ) ), 'With registration closed, a contributor was appointed and counts.' );
$GLOBALS['keel_options'] = array(
	'users_can_register' => 1,
	'default_role'       => 'subscriber',
);
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'author' ) ) ), 'Open registration with the usual subscriber default changes nothing for staff.' );

// --- multisite ---
keel_plain_site();
$GLOBALS['keel_multisite']    = true;
$GLOBALS['keel_super_admins'] = array( 9 );
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 9, array() ) ), 'A Super Admin is privileged with no role on the site at all.' );
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'administrator' ) ) ), 'On a closed network, a site administrator is privileged on the main site.' );
$GLOBALS['keel_main_site'] = false;
keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'administrator' ) ) ), 'And on a subsite.' );

foreach ( array( 'blog', 'all' ) as $open ) {
	$GLOBALS['keel_site_options']['registration'] = $open;
	$GLOBALS['keel_main_site']                    = false;
	keel_assert( false === keel_defaults_is_privileged_account( keel_account( 5, array( 'administrator' ) ) ), "Where visitors may create sites ({$open}), administering a subsite is not privilege." );
	keel_assert( false === keel_defaults_is_privileged_account( keel_account( 5, array( 'editor' ) ) ), "Nor is any other role there ({$open})." );
	keel_assert( true === keel_defaults_is_privileged_account( keel_account( 9, array() ) ), "A Super Admin still counts ({$open})." );
	$GLOBALS['keel_main_site'] = true;
	keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'administrator' ) ) ), "The main site's own staff still count ({$open})." );
}
foreach ( array( 'none', 'user' ) as $closed ) {
	$GLOBALS['keel_site_options']['registration'] = $closed;
	$GLOBALS['keel_main_site']                    = false;
	keel_assert( true === keel_defaults_is_privileged_account( keel_account( 5, array( 'administrator' ) ) ), "Where only Super Admins create sites ({$closed}), a subsite administrator was appointed and counts." );
}

// --- the request: the person making it, or the account it is about ---
keel_plain_site();
$admin      = keel_account( 1, array( 'administrator' ) );
$subscriber = keel_account( 3, array( 'subscriber' ) );

keel_assert( false === keel_defaults_breach_check_is_privileged(), 'A signed-out request about nobody is ordinary.' );
keel_assert( false === keel_defaults_breach_check_is_privileged( $described ), 'A signed-out request that describes an administrator is ordinary.' );
keel_assert( true === keel_defaults_breach_check_is_privileged( $admin ), 'A signed-out request holding an administrator\'s reset key is privileged.' );
keel_assert( false === keel_defaults_breach_check_is_privileged( $subscriber ), 'One holding a subscriber\'s is not.' );
$GLOBALS['keel_current'] = $admin;
keel_assert( true === keel_defaults_breach_check_is_privileged( $subscriber ), 'An administrator setting a subscriber\'s password is privileged.' );
keel_assert( true === keel_defaults_breach_check_is_privileged( $described ), 'And creating an account.' );
$GLOBALS['keel_current'] = $subscriber;
keel_assert( false === keel_defaults_breach_check_is_privileged( $subscriber ), 'A subscriber changing their own password is ordinary.' );
keel_assert( false === keel_defaults_breach_check_is_privileged( $described ), 'A subscriber describing an administrator is ordinary.' );
keel_assert( true === keel_defaults_breach_check_is_privileged( $admin ), 'A subscriber\'s browser holding an administrator\'s reset key is privileged: the key is the proof.' );

/*
 * --- and it decides what the validator can spend ---
 *
 * The allowance is put at the halfway line: only the reserve is left. A lookup
 * going out is then the proof that the request was treated as privileged.
 */
$password = 'correct-horse-battery-staple-9271';
$breached = strtoupper( substr( sha1( $password ), 5 ) ) . ':12';

/**
 * A new request against a store holding exactly the reserve.
 *
 * @param string $body HTTP reply body.
 * @return void
 */
function keel_at_the_line( $body ) {
	static $request = 9000.0;

	$GLOBALS['wpdb']            = new Keel_HIBP_Test_DB();
	$GLOBALS['keel_http_calls'] = 0;
	$GLOBALS['keel_http_reply'] = array(
		'code' => 200,
		'body' => $body,
	);
	$policy                     = keel_hibp_budget_policy();
	$state                      = keel_hibp_budget_initial( $policy, time() );
	$state['credit']            = keel_hibp_budget_reserve( $policy );
	keel_hibp_budget_write( keel_hibp_budget_scope(), 'keel_hibp_v2_0_state', null, $state );
	$_SERVER['REQUEST_TIME_FLOAT'] = ++$request;
}

// A subscriber changing their own password: not screened for now, and allowed (fail open).
$GLOBALS['keel_current'] = $subscriber;
keel_at_the_line( $breached );
keel_assert( true === keel_defaults_validate_password( $password, $subscriber ), 'At the line, a subscriber\'s new password is not screened and is not refused.' );
keel_assert( 0 === $GLOBALS['keel_http_calls'], 'No lookup went out for it.' );

// An administrator changing their own.
$GLOBALS['keel_current'] = $admin;
keel_at_the_line( $breached );
$result = keel_defaults_validate_password( $password, $admin );
keel_assert( is_wp_error( $result ) && 'keel_password_pwned' === $result->get_error_code(), 'At the line, an administrator\'s breached password is still caught.' );
keel_assert( 1 === $GLOBALS['keel_http_calls'], 'From one lookup.' );

// An administrator creating an account, where the subject is only a description.
keel_at_the_line( $breached );
$result = keel_defaults_validate_password( $password, $described );
keel_assert( is_wp_error( $result ) && 'keel_password_pwned' === $result->get_error_code() && 1 === $GLOBALS['keel_http_calls'], 'An administrator creating an account is screened from the reserve.' );

// Signed out, with a reset key for a privileged account, and for an ordinary one.
$GLOBALS['keel_current'] = null;
keel_at_the_line( $breached );
$result = keel_defaults_validate_password( $password, $admin );
keel_assert( is_wp_error( $result ) && 1 === $GLOBALS['keel_http_calls'], 'A privileged account resetting its password is screened from the reserve.' );
keel_at_the_line( $breached );
keel_assert( true === keel_defaults_validate_password( $password, $subscriber ) && 0 === $GLOBALS['keel_http_calls'], 'An ordinary account resetting its password is not.' );

// Signed out, describing an account: the shape an unauthenticated REST request arrives in.
keel_at_the_line( $breached );
keel_assert( true === keel_defaults_validate_password( $password, $described ) && 0 === $GLOBALS['keel_http_calls'], 'A signed-out request cannot reach the reserve by describing an administrator.' );
$state = json_decode( keel_hibp_budget_read( keel_hibp_budget_scope(), 'keel_hibp_v2_0_state' ), true );
keel_assert( keel_hibp_budget_reserve( keel_hibp_budget_policy() ) === $state['credit'], 'The reserve is untouched by it.' );

// Above the line everyone is screened, as before.
$GLOBALS['keel_current'] = $subscriber;
keel_at_the_line( $breached );
$scope           = keel_hibp_budget_scope();
$old             = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
$state           = json_decode( $old, true );
$state['credit'] = 600;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', $old, $state );
$result = keel_defaults_validate_password( $password, $subscriber );
keel_assert( is_wp_error( $result ) && 'keel_password_pwned' === $result->get_error_code() && 1 === $GLOBALS['keel_http_calls'], 'With allowance above the line, a subscriber\'s breached password is caught as before.' );

if ( $fail ) {
	fwrite( STDERR, "password privileged allowance: {$fail} failed\n" );
	exit( 1 );
}

echo "password privileged allowance: OK\n";
