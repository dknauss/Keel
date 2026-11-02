<?php
/**
 * Regression test for the auto-hide front-end admin bar.
 *
 * Pins the hover-intent timing. In browser full screen, or with a browser
 * toolbar set to auto-hide, the top of the viewport is also the top of the
 * screen, so the pointer move that reveals the browser's toolbar also reveals
 * this one. Without a reveal delay, every trip to the browser tabs pops the bar;
 * without a hide delay, the bar vanishes while the browser's toolbar is still
 * sliding away over it. Keyboard focus must skip both waits.
 *
 * Run: php tests/admin-bar-auto-hide.php
 *
 * @package keel
 */

$GLOBALS['keel_is_admin']          = false;
$GLOBALS['keel_admin_bar_showing'] = true;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) { return $default; }
function is_multisite() { return false; }
function is_admin() { return $GLOBALS['keel_is_admin']; }
function is_admin_bar_showing() { return $GLOBALS['keel_admin_bar_showing']; }

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$css = keel_defaults_auto_hide_admin_bar_css();
// The comments explain the CSS in prose (the word "transform" included), so check the rules only.
$flat = preg_replace( '/\s+/', ' ', preg_replace( '#/\*.*?\*/#s', '', $css ) );

keel_assert( false !== strpos( $flat, '@media (hover: hover) and (pointer: fine)' ), 'Auto-hide is scoped to hover-capable, fine-pointer devices.' );
keel_assert( false !== strpos( $flat, 'top: -50px !important' ), 'The bar is moved fully off-screen.' );
keel_assert( false === strpos( $flat, 'transform' ), 'The bar carries no transform, so the fixed reveal strip resolves against the viewport.' );
keel_assert( false !== strpos( $flat, '#wpadminbar::before' ), 'A reveal strip exists, or the bar could never come back.' );
keel_assert( 1 === preg_match( '/#wpadminbar \{[^}]*transition: top \d+ms ease-in-out 600ms;/', $flat ), 'Hiding waits a 600ms grace period.' );
keel_assert( 1 === preg_match( '/#wpadminbar:hover, #wpadminbar:focus-within \{[^}]*transition-delay: 250ms;/', $flat ), 'Hover reveal waits a 250ms dwell.' );
keel_assert( 1 === preg_match( '/#wpadminbar:focus-within \{ transition-delay: 0s; \}/', $flat ), 'Keyboard focus reveals the bar without waiting.' );
keel_assert( strrpos( $flat, '#wpadminbar:focus-within {' ) > strpos( $flat, '#wpadminbar:hover,' ), 'The focus override comes after the hover rule, so it wins when both match.' );

$GLOBALS['keel_is_admin'] = true;
keel_assert( '' === keel_defaults_auto_hide_admin_bar_css(), 'Nothing is emitted in wp-admin.' );
$GLOBALS['keel_is_admin']          = false;
$GLOBALS['keel_admin_bar_showing'] = false;
keel_assert( '' === keel_defaults_auto_hide_admin_bar_css(), 'Nothing is emitted when the admin bar is not showing.' );

echo "admin-bar-auto-hide: OK\n";
