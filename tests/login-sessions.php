<?php
/**
 * Regression tests for the Login & sessions settings: unit consistency (days),
 * per-field minimums, and the Remember Me >= regular-session guardrail.
 *
 * Run: php tests/login-sessions.php
 *
 * @package keel
 */

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
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

fwrite( STDOUT, "login-sessions tests passed.\n" );
