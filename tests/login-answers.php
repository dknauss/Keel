<?php
/**
 * Uniform Login Answers (#378): the two wp-login.php callbacks, without WordPress.
 *
 * The lab (docs/review/login-answers-2026-11-14.md) proves the answers over
 * HTTP on real WordPress. This pins the decisions those answers rest on, so a
 * change that brings back an account oracle fails here first:
 *
 * - every account-revealing login error becomes one error, under core's
 *   `incorrect_password` code, whose message repeats nothing typed;
 * - other plugins' errors keep their codes, messages, data and order;
 * - empty-field errors and non-errors pass through untouched;
 * - lost-password: revealing errors go, a request with nothing else left is a
 *   success, and another plugin's error is shown alone.
 *
 * Run: php tests/login-answers.php
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ );

/**
 * Just enough of core's WP_Error for these callbacks.
 */
class WP_Error {
	// Messages by code.
	public $errors = array();
	// Latest data by code.
	public $error_data = array();
	// Earlier data by code.
	protected $additional_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_code() {
		$codes = $this->get_error_codes();
		return $codes ? $codes[0] : '';
	}

	public function get_error_messages( $code = '' ) {
		if ( '' === $code ) {
			return array_merge( array(), ...array_values( $this->errors ) );
		}
		return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
	}

	public function get_all_error_data( $code = '' ) {
		$data = array();
		if ( isset( $this->additional_data[ $code ] ) ) {
			$data = $this->additional_data[ $code ];
		}
		if ( isset( $this->error_data[ $code ] ) ) {
			$data[] = $this->error_data[ $code ];
		}
		return $data;
	}

	public function has_errors() {
		return ! empty( $this->errors );
	}

	public function add( $code, $message, $data = '' ) {
		$this->errors[ $code ][] = $message;
		if ( '' !== $data ) {
			$this->add_data( $data, $code );
		}
	}

	public function add_data( $data, $code = '' ) {
		if ( isset( $this->error_data[ $code ] ) ) {
			$this->additional_data[ $code ][] = $this->error_data[ $code ];
		}
		$this->error_data[ $code ] = $data;
	}

	public function remove( $code ) {
		unset( $this->errors[ $code ], $this->error_data[ $code ], $this->additional_data[ $code ] );
	}
}

function __( $s, $d = null ) {
	return $s;
}
function esc_url( $s ) {
	return $s;
}
function wp_lostpassword_url() {
	return 'https://example.test/wp-login.php?action=lostpassword';
}

require dirname( __DIR__ ) . '/includes/login-answers.php';

$GLOBALS['keel_la_failures'] = 0;
$GLOBALS['keel_la_count']    = 0;

/**
 * Record one assertion.
 *
 * @param bool   $ok      Outcome.
 * @param string $message What it proves.
 */
function keel_la_assert( $ok, $message ) {
	++$GLOBALS['keel_la_count'];
	if ( ! $ok ) {
		++$GLOBALS['keel_la_failures'];
		fwrite( STDERR, "Assertion failed: {$message}\n" );
	}
}

/**
 * What wp-login.php reads from the filtered errors to render the form.
 *
 * @param WP_Error $errors Filtered errors.
 * @return array
 */
function keel_la_shape( WP_Error $errors ) {
	return array(
		'codes'    => $errors->get_error_codes(),
		'first'    => $errors->get_error_code(),
		'messages' => $errors->get_error_messages(),
	);
}

/**
 * One comparable string per rendered shape.
 *
 * @param array $shape From keel_la_shape().
 * @return string
 */
function keel_la_key( array $shape ) {
	return var_export( $shape, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a comparison key, not debug output.
}

// --- Core's three login answers, as wp_authenticate_*() build them (6.4–7.1) ---

$lost = ' <a href="https://example.test/wp-login.php?action=lostpassword">Lost your password?</a>';

$unknown_username = new WP_Error( 'invalid_username', '<strong>Error:</strong> The username <strong>nobody</strong> is not registered on this site. If you are unsure of your username, try your email address instead.' );
$unknown_email    = new WP_Error( 'invalid_email', 'Unknown email address. Check again or try your username.' );
$wrong_password   = new WP_Error( 'incorrect_password', '<strong>Error:</strong> The password you entered for the username <strong>riley</strong> is incorrect.' . $lost );
$wrong_by_email   = new WP_Error( 'incorrect_password', '<strong>Error:</strong> The password you entered for the email address <strong>riley@example.test</strong> is incorrect.' . $lost );

$shapes = array();
foreach ( array( $unknown_username, $unknown_email, $wrong_password, $wrong_by_email ) as $input ) {
	$shapes[] = keel_la_shape( keel_defaults_uniform_login_errors( $input, '' ) );
}

keel_la_assert( 1 === count( array_unique( array_map( 'keel_la_key', $shapes ) ) ), 'Unknown username, unknown email and wrong password (by login or email) render identically.' );
keel_la_assert( array( 'incorrect_password' ) === $shapes[0]['codes'], 'The uniform error uses core\'s incorrect_password code, which refills the username field and moves focus to the password for every input.' );
keel_la_assert( 1 === count( $shapes[0]['messages'] ), 'One message, not one per revealing code.' );

$message = $shapes[0]['messages'][0];
foreach ( array( 'nobody', 'riley', '@', 'not registered', 'Unknown email' ) as $leak ) {
	keel_la_assert( false === strpos( $message, $leak ), "The uniform message never carries '{$leak}'." );
}
keel_la_assert( false !== strpos( $message, 'username, email address or password is incorrect' ), 'The message says in substance that the username, email address or password is incorrect.' );
keel_la_assert( 1 === substr_count( $message, 'action=lostpassword' ), 'The message links to the lost-password form once.' );

// --- Another plugin's error is kept, with its data and position ---

$captcha = new WP_Error( 'captcha_wrong', 'Solve the puzzle again.', array( 'field' => 'captcha' ) );
$captcha->add( 'invalid_username', 'The username <strong>nobody</strong> is not registered on this site.' );
$result = keel_defaults_uniform_login_errors( $captcha, '' );
keel_la_assert( array( 'captcha_wrong', 'incorrect_password' ) === $result->get_error_codes(), 'A plugin error keeps its position; the revealing one beside it becomes the uniform error.' );
keel_la_assert( array( 'Solve the puzzle again.' ) === $result->get_error_messages( 'captcha_wrong' ), 'A plugin error keeps its message.' );
keel_la_assert( array( array( 'field' => 'captcha' ) ) === $result->get_all_error_data( 'captcha_wrong' ), 'A plugin error keeps its data.' );

$captcha_real = new WP_Error( 'captcha_wrong', 'Solve the puzzle again.', array( 'field' => 'captcha' ) );
$captcha_real->add( 'incorrect_password', 'The password you entered for the username <strong>riley</strong> is incorrect.' );
keel_la_assert( keel_la_shape( keel_defaults_uniform_login_errors( $captcha_real, '' ) ) === keel_la_shape( $result ), 'With a plugin error present, real and unknown accounts still render identically.' );

$lockout = new WP_Error( 'too_many_retries', 'Locked out for 20 minutes.' );
keel_la_assert( keel_defaults_uniform_login_errors( $lockout, '' ) === $lockout, 'A lockout another plugin returns is passed through as it wrote it.' );

// --- Errors that say nothing about an account, and non-errors ---

$empty = new WP_Error( 'empty_username', '<strong>Error:</strong> The username field is empty.' );
$empty->add( 'empty_password', '<strong>Error:</strong> The password field is empty.' );
keel_la_assert( keel_defaults_uniform_login_errors( $empty, '' ) === $empty, 'Empty-field errors are left exactly as core wrote them.' );

$loggedout = new WP_Error( 'loggedout', 'You are now logged out.', 'message' );
keel_la_assert( keel_defaults_uniform_login_errors( $loggedout, '' ) === $loggedout, 'Informational messages pass through.' );
keel_la_assert( 'not-an-error' === keel_defaults_uniform_login_errors( 'not-an-error', '' ), 'A value that is not a WP_Error is returned untouched.' );

// --- Lost password ---

$cases = array(
	'unknown username or email (invalidcombo)'   => array( new WP_Error( 'invalidcombo', 'There is no account with that username or email address.' ), 'success' ),
	'unknown email typed with @ (invalid_email)' => array( new WP_Error( 'invalid_email', 'There is no account with that username or email address.' ), 'success' ),
	'real account, reset not allowed'            => array( new WP_Error( 'no_password_reset', 'Password reset is not allowed for this user' ), 'success' ),
	'real account, mail failed'                  => array( new WP_Error( 'retrieve_password_email_failure', 'The email could not be sent.' ), 'success' ),
	'empty field'                                => array( new WP_Error( 'empty_username', 'Please enter a username or email address.' ), 'unchanged' ),
);
foreach ( $cases as $label => $case ) {
	keel_la_assert( keel_defaults_uniform_lostpassword_outcome( $case[0] ) === $case[1], "Lost password, {$label}: {$case[1]}." );
}
keel_la_assert( array( 'empty_username' ) === $cases['empty field'][0]->get_error_codes(), 'An empty lost-password field keeps core\'s error.' );

$lp_captcha_unknown = new WP_Error( 'invalid_email', 'There is no account with that username or email address.' );
$lp_captcha_unknown->add( 'captcha_wrong', 'Solve the puzzle again.' );
$lp_captcha_real = new WP_Error( 'captcha_wrong', 'Solve the puzzle again.' );
keel_la_assert( 'show' === keel_defaults_uniform_lostpassword_outcome( $lp_captcha_unknown ), 'A plugin error beside an unknown-account error is shown.' );
keel_la_assert( 'unchanged' === keel_defaults_uniform_lostpassword_outcome( $lp_captcha_real ), 'A plugin error alone, for a real account, is left as is.' );
keel_la_assert( array( $lp_captcha_unknown->get_error_codes(), $lp_captcha_unknown->get_error_messages() ) === array( $lp_captcha_real->get_error_codes(), $lp_captcha_real->get_error_messages() ), 'With a plugin error, unknown and real accounts end with the same errors.' );

if ( $GLOBALS['keel_la_failures'] > 0 ) {
	fwrite( STDERR, "login answers: {$GLOBALS['keel_la_failures']} of {$GLOBALS['keel_la_count']} assertions failed\n" );
	exit( 1 );
}

echo "login answers: OK ({$GLOBALS['keel_la_count']} assertions)\n";
