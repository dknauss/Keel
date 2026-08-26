<?php
/**
 * The three doors strong-password enforcement is applied at.
 *
 * The policy function, keel_defaults_validate_password(), decides whether a
 * password is acceptable and has thorough coverage in tests/password-scoping.php.
 * It is never called by WordPress directly. Three wrappers put it in front of the three ways a
 * password actually reaches a site:
 *
 *   validate_password_reset  → the reset form and the profile screen
 *   rest_pre_insert_user     → creating or updating a user over REST
 *   rest_endpoints           → the password argument on /wp/v2/users
 *
 * None of them had a test, in either suite. That is the gap that matters: the
 * policy can be perfect while a wrapper returns the wrong type, reads the wrong
 * field, or bails early — and enforcement silently stops on that path while
 * every existing assertion stays green. Two defects found elsewhere in this
 * plugin on the same day had exactly that shape.
 *
 * Run: php tests/password-entry-points.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']  = array();
$GLOBALS['keel_userdata'] = array();
$GLOBALS['keel_current']  = 0;

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
function get_option( $k, $d = false ) { return $d; }
function is_multisite() { return false; }
function wp_unslash( $v ) { return $v; }
function get_userdata( $id ) {
	return isset( $GLOBALS['keel_userdata'][ $id ] ) ? $GLOBALS['keel_userdata'][ $id ] : false;
}
function get_current_user_id() { return $GLOBALS['keel_current']; }

require __DIR__ . '/stubs/wp-error.php';
function is_wp_error( $t ) { return $t instanceof WP_Error; }

define( 'ABSPATH', __DIR__ . '/' );
define( 'KEEL_DISABLE_HIBP', true );

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
 * A user with roles, as the enforcement gate reads them.
 *
 * @param array $roles Role slugs.
 * @return object
 */
function keel_user( $roles ) {
	$u        = new stdClass();
	$u->roles = (array) $roles;
	return $u;
}

/**
 * Stand-in for WP_REST_Request.
 *
 * ArrayAccess as well as get_param(), because the code under test uses both:
 * get_param() for the password and $request['id'] for the account being edited.
 * A stub with only one of them would let a wrapper that reads the wrong one pass.
 */
class Keel_Test_Request implements ArrayAccess {

	/**
	 * Request parameters.
	 *
	 * @var array
	 */
	private $params;

	/**
	 * Route string.
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Constructor.
	 *
	 * @param array  $params Parameters.
	 * @param string $route  Route.
	 */
	public function __construct( $params = array(), $route = '/wp/v2/users' ) {
		$this->params = $params;
		$this->route  = $route;
	}

	/**
	 * Read one parameter.
	 *
	 * @param string $key Parameter name.
	 * @return mixed
	 */
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}

	/**
	 * The route this request is for.
	 *
	 * @return string
	 */
	public function get_route() {
		return $this->route;
	}

	/**
	 * ArrayAccess: does a parameter exist.
	 *
	 * @param mixed $offset Parameter name.
	 * @return bool
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->params[ $offset ] );
	}

	/**
	 * ArrayAccess: read a parameter.
	 *
	 * @param mixed $offset Parameter name.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return isset( $this->params[ $offset ] ) ? $this->params[ $offset ] : null;
	}

	/**
	 * ArrayAccess: write a parameter.
	 *
	 * @param mixed $offset Parameter name.
	 * @param mixed $value  Value.
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->params[ $offset ] = $value;
	}

	/**
	 * ArrayAccess: remove a parameter.
	 *
	 * @param mixed $offset Parameter name.
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->params[ $offset ] );
	}
}

$weak   = 'short';
$strong = 'a reasonably long passphrase that no rule objects to';

// The gate itself, so the assertions below are known to be measuring the
// wrappers rather than a policy that happens to accept everything.
keel_assert( is_wp_error( keel_defaults_validate_password( $weak, keel_user( array( 'administrator' ) ) ) ), 'Precondition: the policy rejects a weak password for an administrator.' );
keel_assert( true === keel_defaults_validate_password( $strong, keel_user( array( 'administrator' ) ) ), 'Precondition: the policy accepts a strong one.' );

/*
 * --- door 1: validate_password_reset ---
 *
 * The reset form and the profile screen. This one neither returns a value nor
 * reads its password from an argument: it takes it from $_POST and reports by
 * adding to the WP_Error it was handed. A wrapper that returned the error
 * instead of adding it would enforce nothing, and would look correct.
 */
$errors = new WP_Error();
// phpcs:disable WordPress.Security.NonceVerification -- Seeding a superglobal; this test is the request.
$_POST['pass1'] = $weak;
keel_defaults_validate_reset_password( $errors, keel_user( array( 'administrator' ) ) );
// The code is taken from the policy rather than written in by hand, so this
// asserts the wrapper carried the policy's own answer through instead of
// pinning a string that a later rule rename would silently invalidate.
$expected_code = keel_defaults_validate_password( $weak, keel_user( array( 'administrator' ) ) )->get_error_code();
keel_assert( array( $expected_code ) === $errors->get_error_codes(), "A weak password on the reset form is rejected, with the policy's own code ({$expected_code}): " . implode( ',', $errors->get_error_codes() ) );

$errors         = new WP_Error();
$_POST['pass1'] = $strong;
keel_defaults_validate_reset_password( $errors, keel_user( array( 'administrator' ) ) );
keel_assert( array() === $errors->get_error_codes(), 'A strong password on the reset form is accepted.' );

// An exempt role is exempt here too, or the scoping stops at one door.
$errors         = new WP_Error();
$_POST['pass1'] = $weak;
keel_defaults_validate_reset_password( $errors, keel_user( array( 'subscriber' ) ) );
keel_assert( array() === $errors->get_error_codes(), 'A subscriber is exempt on the reset form, as everywhere else.' );

// No password submitted is not a failure — the form is shown before it is filled.
$errors = new WP_Error();
unset( $_POST['pass1'] );
keel_defaults_validate_reset_password( $errors, keel_user( array( 'administrator' ) ) );
keel_assert( array() === $errors->get_error_codes(), 'An empty submission adds no error rather than rejecting a blank form.' );
// phpcs:enable WordPress.Security.NonceVerification

/*
 * --- door 2: rest_pre_insert_user ---
 *
 * Creating or updating a user over REST. Returns either the prepared user
 * untouched or a WP_Error, and the untouched case matters as much: a wrapper
 * that returned true on success would discard the object core is assembling.
 */
$prepared             = new stdClass();
$prepared->ID         = 0;
$prepared->roles      = array( 'administrator' );
$prepared->user_login = 'someone';

$out = keel_defaults_validate_rest_password( $prepared, new Keel_Test_Request( array( 'password' => $weak ) ) );
keel_assert( is_wp_error( $out ), 'A weak password over REST is rejected.' );

$out = keel_defaults_validate_rest_password( $prepared, new Keel_Test_Request( array( 'password' => $strong ) ) );
keel_assert( $out === $prepared, 'A strong password returns the prepared user unchanged, not a bare true.' );

$out = keel_defaults_validate_rest_password( $prepared, new Keel_Test_Request( array() ) );
keel_assert( $out === $prepared, 'A request with no password field is passed through — most user updates do not set one.' );

$out = keel_defaults_validate_rest_password( $prepared, new Keel_Test_Request( array( 'password' => '' ) ) );
keel_assert( $out === $prepared, 'An empty password is passed through rather than rejected as weak.' );

// Editing an existing user reads that user's roles, not the payload's.
$GLOBALS['keel_userdata'][7] = keel_user( array( 'subscriber' ) );
$existing                    = new stdClass();
$existing->ID                = 7;
$out                         = keel_defaults_validate_rest_password( $existing, new Keel_Test_Request( array( 'password' => $weak ), '/wp/v2/users/7' ) );
keel_assert( $out === $existing, 'Editing an exempt existing user over REST stays exempt: the role comes from the stored user.' );

/*
 * --- door 3: the rest_endpoints password argument ---
 *
 * The guard wraps the sanitize_callback on /wp/v2/users so a password is
 * validated before core writes it. Three things have to hold: the wrapper is
 * installed on the right routes, it is *not* installed on application-password
 * routes, and the callback it installs actually rejects.
 */
$endpoints = array(
	'/wp/v2/users'                          => array( array( 'args' => array( 'password' => array() ) ) ),
	'/wp/v2/users/(?P<id>[\d]+)'            => array( array( 'args' => array( 'password' => array() ) ) ),
	'/wp/v2/users/me/application-passwords' => array( array( 'args' => array( 'password' => array() ) ) ),
	'/wp/v2/posts'                          => array( array( 'args' => array( 'password' => array() ) ) ),
);

$guarded = keel_defaults_guard_rest_password_arg( $endpoints );

keel_assert( isset( $guarded['/wp/v2/users'][0]['args']['password']['sanitize_callback'] ), 'The users collection route is guarded.' );
keel_assert( isset( $guarded['/wp/v2/users/(?P<id>[\d]+)'][0]['args']['password']['sanitize_callback'] ), 'The single-user route is guarded.' );
keel_assert( ! isset( $guarded['/wp/v2/users/me/application-passwords'][0]['args']['password']['sanitize_callback'] ), 'Application passwords are left alone: they are core-generated and not a human password policy question.' );
keel_assert( ! isset( $guarded['/wp/v2/posts'][0]['args']['password']['sanitize_callback'] ), 'A post password on an unrelated route is not treated as a user password.' );

// And the installed callback enforces, rather than merely existing.
$cb       = $guarded['/wp/v2/users'][0]['args']['password']['sanitize_callback'];
$rejected = call_user_func(
	$cb,
	$weak,
	new Keel_Test_Request(
		array(
			'username' => 'someone',
			'email'    => 'a@example.test',
			'slug'     => 'someone',
		)
	),
	'password'
);
keel_assert( is_wp_error( $rejected ), 'The installed sanitize_callback rejects a weak password.' );

$accepted = call_user_func(
	$cb,
	$strong,
	new Keel_Test_Request(
		array(
			'username' => 'someone',
			'email'    => 'a@example.test',
			'slug'     => 'someone',
		)
	),
	'password'
);
keel_assert( $strong === $accepted, 'And returns the password unchanged when it passes, rather than a truthy stand-in.' );

// A pre-existing sanitize_callback still runs, and its rejection wins.
$endpoints2 = array(
	'/wp/v2/users' => array(
		array(
			'args' => array(
				'password' => array(
					'sanitize_callback' => function ( $value ) {
						return new WP_Error( 'core_said_no', 'core rejected it' );
					},
				),
			),
		),
	),
);
$cb2        = keel_defaults_guard_rest_password_arg( $endpoints2 )['/wp/v2/users'][0]['args']['password']['sanitize_callback'];
$core_err   = call_user_func( $cb2, $strong, new Keel_Test_Request( array() ), 'password' );
keel_assert( is_wp_error( $core_err ) && 'core_said_no' === $core_err->get_error_code(), "Core's own sanitiser runs first and its rejection is returned, not overwritten." );

/*
 * --- the context resolver the guard depends on ---
 */
$GLOBALS['keel_current'] = 7; // the exempt subscriber above
$me                      = keel_defaults_rest_password_context( new Keel_Test_Request( array(), '/wp/v2/users/me' ) );
keel_assert( isset( $me->roles ) && array( 'subscriber' ) === $me->roles, '/users/me resolves to the current user, so their exemption applies.' );

$other = keel_defaults_rest_password_context( new Keel_Test_Request( array( 'id' => 7 ), '/wp/v2/users/7' ) );
keel_assert( isset( $other->roles ) && array( 'subscriber' ) === $other->roles, 'An id in the request resolves to that stored user.' );

$fresh = keel_defaults_rest_password_context(
	new Keel_Test_Request(
		array(
			'username' => 'newbie',
			'email'    => 'n@example.test',
			'slug'     => 'newbie',
		)
	)
);
keel_assert( 'newbie' === $fresh->user_login && 'n@example.test' === $fresh->user_email, 'A user being created has no id, so the context is built from the submitted fields.' );

if ( $fail > 0 ) {
	fwrite( STDERR, "password entry points: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "password entry points: OK (3 doors)\n" );
