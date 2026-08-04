<?php
/**
 * Regression tests for the Login & sessions settings: unit consistency (days),
 * per-field minimums, and the Remember Me >= regular-session guardrail.
 *
 * Run: php tests/login-sessions.php
 *
 * @package keel
 */

define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['keel_hooks']   = array();
$GLOBALS['keel_options'] = array();
function get_option( $name, $default_value = false ) {
	return ( KEEL_DEFAULTS_OPTION === $name ) ? $GLOBALS['keel_options'] : $default_value;
}
function is_user_logged_in() {
	return false;
}
function add_action( $hook, $cb = null, $priority = 10, $accepted = 1 ) {
	$GLOBALS['keel_hooks'][] = array( $hook, $cb, $priority );
}
function add_filter( $hook, $cb = null, $priority = 10, $accepted = 1 ) {
	$GLOBALS['keel_hooks'][] = array( $hook, $cb, $priority );
}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function absint( $v ) { return abs( (int) $v ); }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$schema = keel_defaults_schema();

// Both length fields are day-based numbers now (no hours field survives).
keel_assert( isset( $schema['session_regular_days'] ), 'session_regular_days field exists.' );
keel_assert( ! isset( $schema['session_regular_hours'] ), 'legacy session_regular_hours field is gone.' );
keel_assert( 'number' === $schema['session_regular_days']['type'], 'Regular session is a number field.' );
keel_assert( 'number' === $schema['remember_me_days']['type'], 'Remember Me length is a number field.' );

// Defaults are prefilled with WordPress's real values — no "0 = default" sentinel.
keel_assert( 2 === $schema['session_regular_days']['default'], 'Regular default is WordPress\'s 2 days.' );
keel_assert( 14 === $schema['remember_me_days']['default'], 'Remember Me default is WordPress\'s 14 days.' );
keel_assert( 1 === (int) $schema['session_regular_days']['min'], 'Regular session has a 1-day floor.' );
keel_assert( 1 === (int) $schema['remember_me_days']['min'], 'Remember Me length has a 1-day floor.' );

// Per-field minimum clamps a below-floor submission up to the floor.
$clean = keel_defaults_sanitize(
	array(
		'session_regular_days' => '0',
		'remember_me_days'     => '0',
	)
);
keel_assert( 1 === $clean['session_regular_days'], 'Regular session clamps 0 up to its 1-day floor.' );

// The guardrail: a remembered login can never be shorter than a regular one.
$clean = keel_defaults_sanitize(
	array(
		'session_regular_days' => '10',
		'remember_me_days'     => '3',
	)
);
keel_assert( 10 === $clean['remember_me_days'], 'Remember Me is clamped up to the regular session length (10).' );

// A coherent pair (remember >= regular) is left untouched.
$clean = keel_defaults_sanitize(
	array(
		'session_regular_days' => '2',
		'remember_me_days'     => '30',
	)
);
keel_assert( 2 === $clean['session_regular_days'] && 30 === $clean['remember_me_days'], 'A valid remember>=regular pair passes through unchanged.' );

/*
 * The clamp has to be the last word.
 *
 * Registered at priority 50, not the default 10: at 10 any plugin filtering
 * auth_cookie_expiration at a default priority lands after it and silently
 * wins, which is the case a site sets a session length to prevent. The
 * priority is asserted against the source because observing it would mean
 * running the whole bootstrap.
 */
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file in a test.
$bootstrap_src = file_get_contents( dirname( __DIR__ ) . '/includes/bootstrap.php' );
keel_assert(
	false !== strpos( $bootstrap_src, "add_filter( 'auth_cookie_expiration', 'keel_defaults_session_length', 50, 3 );" ),
	'The session clamp runs at priority 50, after plugins filtering at the default 10.'
);

// Remember Me is hidden with CSS. An inline <script> fails with JavaScript off
// and is blocked by a strict script-src Content-Security-Policy — either way
// the checkbox stays visible and looks like it works.
keel_assert( false !== strpos( $bootstrap_src, 'keel-hide-remember-me' ), 'The Remember Me checkbox is hidden with a stylesheet.' );
keel_assert( false === strpos( $bootstrap_src, "getElementById('rememberme')" ), 'No inline script is used to hide it.' );
keel_assert( false !== strpos( $bootstrap_src, "unset( \$_POST['rememberme'], \$_REQUEST['rememberme'] )" ), 'The submitted value is still stripped server-side, which is what actually disables it.' );

// The backstop: a stored remembered length below the regular one cannot make a
// remembered login shorter. Sanitize keeps them coherent on save; nothing keeps
// them coherent when WP-CLI or a migration writes the option.
$GLOBALS['keel_options'] = array(
	'disable_remember_me'  => 'no',
	'session_regular_days' => 5,
	'remember_me_days'     => 2,
);
keel_assert( 5 * DAY_IN_SECONDS === keel_defaults_session_length( 999, 1, true ), 'A remembered login is never shorter than a regular one.' );
keel_assert( 5 * DAY_IN_SECONDS === keel_defaults_session_length( 999, 1, false ), 'An ordinary login uses the regular length.' );

$GLOBALS['keel_options']['remember_me_days'] = 30;
keel_assert( 30 * DAY_IN_SECONDS === keel_defaults_session_length( 999, 1, true ), 'A longer remembered length is honoured.' );

$GLOBALS['keel_options']['disable_remember_me'] = 'yes';
keel_assert( 5 * DAY_IN_SECONDS === keel_defaults_session_length( 999, 1, true ), 'With Remember Me disabled, even a remembered login gets the regular length.' );

fwrite( STDOUT, "login-sessions tests passed.\n" );

// --- composing with other plugins ---
// auth_cookie_expiration is a replacing filter: the callback discards the value
// it was handed. Two plugins registering one cannot both win, so Keel stays off
// the hook entirely when its policy is the same answer WordPress already gives.
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_options'] = array( 'session_regular_days' => 1 );
keel_assert( true === keel_defaults_session_policy_is_custom(), 'A shorter regular session is a real policy and does register.' );

$GLOBALS['keel_options'] = array( 'remember_me_days' => 30 );
keel_assert( true === keel_defaults_session_policy_is_custom(), 'A longer remembered session is a real policy and does register.' );

// Disabling Remember Me changes the remembered answer even though both day
// values still match core, which is why the test asks the callback rather than
// comparing the stored numbers.
$GLOBALS['keel_options'] = array( 'disable_remember_me' => 'yes' );
keel_assert( true === keel_defaults_session_policy_is_custom(), 'Disabling Remember Me is a policy even with core day values.' );

$GLOBALS['keel_options'] = array(
	'session_regular_days' => 2,
	'remember_me_days'     => 14,
);
keel_assert( false === keel_defaults_session_policy_is_custom(), 'Explicitly setting core values is still core values.' );
