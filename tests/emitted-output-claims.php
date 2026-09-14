<?php
/**
 * Static guard: a claim about emitted output must match the emitted output.
 *
 * Run: php tests/emitted-output-claims.php
 *
 * Comments describe behaviour and nothing checks them. Both halves of that have
 * now shipped bugs here:
 *
 * - keel_defaults_admin_menu_width_css() carried a docblock promising a
 *   `body:not(.folded)` guard that no selector had. A folded menu stayed pinned
 *   open, and the comment went on describing the fix that was never written.
 * - The replacement gated on `:not(.auto-fold)` — a class core sets by default
 *   for every user at every width — which disabled the feature outright. The
 *   test asserted the selector's spelling, never that it matched anything.
 *
 * A guard cannot read English, but it can check a tag. Any function returning a
 * string may declare what that string must and must not contain:
 *
 *     @emits body:not(.folded)
 *     @omits :not(.auto-fold)
 *
 * This calls each tagged function and checks both. Tags are opt-in and cost one
 * line where a claim is load-bearing — the same test that decides whether a
 * coupling deserves a comment at all.
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function is_multisite() {
	return false;
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

$failures = 0;
$checked  = 0;

function keel_assert( $cond, $msg ) {
	global $failures;
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		++$failures;
	}
}

/*
 * Fixtures for emitters that need state set before they return anything
 * meaningful. Keyed by function name; each returns the emitted string.
 */
$emitters = array(
	'keel_defaults_admin_menu_width_css' => static function () {
		$GLOBALS['keel_options']['keel_settings'] = array( 'admin_menu_width' => '240' );
		return keel_defaults_admin_menu_width_css();
	},
);

// --- collect every tagged function --------------------------------------

$claims = array();

foreach ( (array) glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
	// Reading plugin source off disk in a CLI test; wp_remote_get() is for HTTP.
	$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! preg_match_all( '/\/\*\*(.*?)\*\/\s*function\s+([a-z0-9_]+)\s*\(/is', $source, $matches, PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $matches as $match ) {
		preg_match_all( '/^\s*\*\s*@emits\s+(.+?)\s*$/m', $match[1], $emits );
		preg_match_all( '/^\s*\*\s*@omits\s+(.+?)\s*$/m', $match[1], $omits );

		if ( $emits[1] || $omits[1] ) {
			$claims[ $match[2] ] = array(
				'file'  => basename( $file ),
				'emits' => $emits[1],
				'omits' => $omits[1],
			);
		}
	}
}

keel_assert( array() !== $claims, 'At least one function declares @emits/@omits; an empty guard proves nothing.' );

// --- check each claim against the real output ---------------------------

foreach ( $claims as $name => $claim ) {
	if ( ! function_exists( $name ) ) {
		keel_assert( false, "{$name} is tagged but not loaded, so the guard cannot check it." );
		continue;
	}

	$output = isset( $emitters[ $name ] ) ? $emitters[ $name ]() : $name();

	if ( ! is_string( $output ) ) {
		keel_assert( false, "{$name} is tagged but does not return a string." );
		continue;
	}

	foreach ( $claim['emits'] as $needle ) {
		++$checked;
		keel_assert(
			false !== strpos( $output, $needle ),
			sprintf( '%s (%s) claims @emits "%s", but its output does not contain it.', $name, $claim['file'], $needle )
		);
	}

	foreach ( $claim['omits'] as $needle ) {
		++$checked;
		keel_assert(
			false === strpos( $output, $needle ),
			sprintf( '%s (%s) claims @omits "%s", but its output contains it.', $name, $claim['file'], $needle )
		);
	}
}

if ( $failures ) {
	fwrite( STDERR, sprintf( "\n%d claim(s) do not match the emitted output.\n", $failures ) );
	exit( 1 );
}

printf( "emitted output claims: OK (%d claims across %d function(s) checked).\n", $checked, count( $claims ) );
