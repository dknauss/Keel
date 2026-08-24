<?php
/**
 * Accessibility invariants for the settings screen and the admin bar.
 *
 * Written after the first accessibility sweep of this plugin, which found real
 * care in the code — aria-describedby wiring, screen-reader legends on every
 * fieldset, scope="row" headings, an environment label clipped rather than
 * display:none'd so it survives for a screen reader — and almost no coverage of
 * any of it. Two assertions in the whole suite touched accessibility. Care
 * without coverage is the state this repository spent a week learning not to
 * trust, and the sweep found a hard WCAG failure sitting inside the careful part:
 * the staging environment colour rendered white text at 2.41:1, where AA wants
 * 4.5:1, and had done since the indicator was written.
 *
 * The contrast assertions matter most, because that is the failure nobody can
 * see by reading the diff. A colour is three bytes and looks fine in a pull
 * request; whether it is legible is arithmetic nobody does by hand.
 *
 * Run: php tests/accessibility.php
 *
 * @package keel
 */

$fail = 0;

/**
 * Assert helper.
 *
 * Collects rather than exiting, so one run names every failure.
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
 * Relative luminance of an sRGB colour, per WCAG 2.1.
 *
 * @param string $hex Colour as #rgb or #rrggbb.
 * @return float
 */
function keel_relative_luminance( $hex ) {
	$hex = ltrim( (string) $hex, '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	$channels = array();

	foreach ( array( 0, 2, 4 ) as $offset ) {
		$c = hexdec( substr( $hex, $offset, 2 ) ) / 255;
		// The sRGB transfer function. The 0.03928 knee and the 2.4 exponent are
		// WCAG's own constants, not an approximation of them.
		$channels[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Contrast ratio between two colours, per WCAG 2.1.
 *
 * @param string $a First colour.
 * @param string $b Second colour.
 * @return float Between 1 and 21.
 */
function keel_contrast_ratio( $a, $b ) {
	$la = keel_relative_luminance( $a );
	$lb = keel_relative_luminance( $b );

	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/*
 * The formula is checked against values with known answers before it is trusted
 * to judge the plugin's own. A contrast test that quietly computed nonsense
 * would pass everything, which is the exact failure shape this suite keeps
 * finding elsewhere.
 */
keel_assert( abs( keel_contrast_ratio( '#000000', '#ffffff' ) - 21.0 ) < 0.01, 'Black on white is 21:1.' );
keel_assert( abs( keel_contrast_ratio( '#ffffff', '#ffffff' ) - 1.0 ) < 0.01, 'White on white is 1:1.' );
keel_assert( abs( keel_contrast_ratio( '#767676', '#ffffff' ) - 4.54 ) < 0.02, "WCAG's own 4.5:1 boundary grey checks out." );
keel_assert(
	keel_contrast_ratio( '#000000', '#ffffff' ) === keel_contrast_ratio( '#ffffff', '#000000' ),
	'The ratio does not depend on argument order.'
);

// --- stubs, enough to read the environment table ---
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_filters'] = array();

require_once __DIR__ . '/stubs/wp-error.php';

/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function add_action( ...$args ) {}
/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function add_filter( ...$args ) {}
/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function register_activation_hook( ...$args ) {}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function __( $s, $d = null ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function esc_html__( $s, $d = null ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 */
function esc_html_e( $s, $d = null ) {
	echo $s; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @return string
 */
function esc_html( $s ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @return string
 */
function esc_attr( $s ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
/**
 * Stub.
 *
 * @param string $key     Option.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}

/**
 * Stub.
 *
 * @param array $args     Supplied.
 * @param array $defaults Defaults.
 * @return array
 */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
/**
 * Stub.
 *
 * @return bool
 */
function is_admin_bar_showing() {
	return true;
}
/**
 * Stub.
 *
 * @param string $class Class name.
 * @return string
 */
function sanitize_html_class( $class ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
}
/**
 * Stub.
 *
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	return wp_kses_stub_strip( $text );
}
/**
 * Helper for the wp_strip_all_tags stub.
 *
 * @param string $text Text.
 * @return string
 */
function wp_kses_stub_strip( $text ) {
	return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $text ) );
}

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

/*
 * --- WCAG 1.4.3: the environment indicator is readable ---
 *
 * The label is the admin bar's own ~13px bold text, which is below WCAG's
 * "large text" threshold, so 4.5:1 applies rather than 3:1. Staging shipped at
 * 2.41:1 — comfortably the worst contrast in the plugin, and invisible in review
 * because #d79d00 reads as a perfectly ordinary amber.
 */
$environments = keel_environments();

keel_assert( count( $environments ) >= 4, 'The environment table has entries to check (' . count( $environments ) . ').' );

foreach ( $environments as $type => $environment ) {
	$ratio = keel_contrast_ratio( $environment['text_color'], $environment['background_color'] );

	keel_assert(
		$ratio >= 4.5,
		sprintf(
			'Environment "%s" renders %s on %s at %.2f:1; WCAG 2.1 AA wants 4.5:1 for text this size.',
			$type,
			$environment['text_color'],
			$environment['background_color'],
			$ratio
		)
	);

	// Colour must never be the only thing carrying the meaning (WCAG 1.4.1).
	// The text label beside the swatch is what makes the colour coding legal.
	keel_assert(
		isset( $environment['label'] ) && '' !== trim( $environment['label'] ),
		"Environment \"{$type}\" has a text label, so colour is not its only signal."
	);
}

/*
 * --- the label the colour depends on is clipped, never removed ---
 *
 * Between 783px and 960px the admin bar is crowded and the label is hidden to
 * leave only the coloured icon. The icon is aria-hidden, so the label is the
 * node's entire accessible name: hiding it with display:none would take the
 * indicator out of the accessibility tree and leave colour as the only signal,
 * failing 1.4.1 at exactly one viewport width — the kind of thing an automated
 * scan at a single breakpoint never sees.
 */
ob_start();
keel_defaults_environment_styles();
$env_css = ob_get_clean();

if ( '' !== trim( $env_css ) ) {
	keel_assert( false !== strpos( $env_css, 'clip-path: inset(50%)' ), 'The narrow-viewport label is clipped.' );
	keel_assert(
		false === strpos( $env_css, 'display: none' ) && false === strpos( $env_css, 'display:none' ),
		'The narrow-viewport label is never removed from the accessibility tree with display:none.'
	);
}

if ( $fail > 0 ) {
	fwrite( STDERR, "accessibility: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'accessibility: OK (' . count( $environments ) . " environment colours checked)\n" );
