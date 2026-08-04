<?php
/**
 * Heading-case guard for the settings screen.
 *
 * The left-hand column of a form-table and the group heading directly above it
 * are read together as one column of headings. Mixing Title Case group headings
 * ("Admin and Front-End UX") with sentence-case row headings ("Login logo")
 * reads as unfinished rather than as a deliberate style. Nobody ever decides on
 * that mix — it accumulates one setting at a time, each addition locally
 * defensible, which is exactly why it wants a test rather than a note in a
 * README.
 *
 * THIS ASSERTS THE RENDERED MARKUP, NOT THE ARRAYS THAT FEED IT.
 *
 * That distinction is the whole point. The sibling plugin first guarded this by
 * iterating the section-label array, and it passed while three headings were
 * still sentence case on screen: a select or number field with no section draws
 * its row heading from its schema label, not from a section title, so those rows
 * were never in the array being checked. Keel has both sources too — sectioned
 * fields render the section title once, everything else renders its own label —
 * so the only guard that cannot be fooled is one that reads the <th> elements
 * the screen actually emits.
 *
 * Run: php tests/settings-heading-case.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

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
function esc_js( $s ) {
	return $s; }
function esc_url( $s ) {
	return $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function is_multisite() {
	return false; }
function current_user_can( $cap ) {
	return true; }
function settings_fields( $g ) {}
function submit_button( ...$a ) {}
function checked( $a, $b = true, $echo = true ) {
	return ( (string) $a === (string) $b ) ? ' checked' : ''; }
function disabled( $a, $b = true, $echo = true ) {
	return ( $a === $b ) ? ' disabled' : ''; }
function selected( $a, $b = true, $echo = true ) {
	return ( (string) $a === (string) $b ) ? ' selected' : ''; }
function wp_kses( $s, $allowed = array() ) {
	return $s; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this stub is what stands in for wp_json_encode().
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function translate_user_role( $n ) {
	return $n; }
function wp_roles() {
	return new Keel_Heading_Test_Roles(); }

class Keel_Heading_Test_Roles {
	public $roles = array(
		'subscriber' => array(
			'name'         => 'Subscriber',
			'capabilities' => array( 'read' => true ),
		),
	);
}

define( 'ABSPATH', __DIR__ . '/' );
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

// --- render the screen and read back its row headings ---
ob_start();
keel_defaults_render_settings_page();
$html = ob_get_clean();

keel_assert( '' !== trim( $html ), 'The settings screen rendered something.' );

preg_match_all( '#<th scope="row">(.*?)</th>#s', $html, $m );
$headings = array_values( array_unique( array_map( 'trim', $m[1] ) ) );

// A guard that finds no headings would pass everything.
keel_assert( count( $headings ) > 20, 'The render produced a plausible number of row headings (' . count( $headings ) . ').' );

/*
 * Words that stay lowercase inside a title: articles, coordinating
 * conjunctions, and short prepositions. Anything leading is capitalized
 * regardless, which is why position is checked as well as membership.
 */
$lowercase_ok = array( 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'vs' );

foreach ( $headings as $heading ) {
	$words = preg_split( '/\s+/', $heading );

	foreach ( $words as $i => $word ) {
		// Compare on the first alphabetic character, so a hyphenated compound is
		// judged on "Self" and its second half is handled below.
		if ( ! preg_match( '/[A-Za-z]/', $word ) ) {
			continue;
		}

		$bare  = strtolower( preg_replace( '/[^A-Za-z-]/', '', $word ) );
		$first = preg_replace( '/[^A-Za-z-]/', '', $word );

		if ( 0 !== $i && in_array( $bare, $lowercase_ok, true ) ) {
			keel_assert(
				$first === $bare,
				"Row heading '{$heading}': '{$word}' is a short word inside a title and should stay lowercase."
			);
			continue;
		}

		// Every other word starts with a capital. An all-caps acronym (REST,
		// HTML, AI, API) satisfies this too.
		keel_assert(
			preg_match( '/^[A-Z]/', $first ),
			"Row heading '{$heading}' is not Title Case: '{$word}' should be capitalized."
		);

		// Both halves of a hyphenated compound, to match the group headings —
		// "Admin and Front-End UX", not "Front-end".
		if ( false !== strpos( $first, '-' ) ) {
			foreach ( explode( '-', $first ) as $part ) {
				if ( '' === $part || in_array( strtolower( $part ), $lowercase_ok, true ) ) {
					continue;
				}
				keel_assert(
					preg_match( '/^[A-Z]/', $part ),
					"Row heading '{$heading}': both halves of '{$word}' should be capitalized."
				);
			}
		}
	}
}

// --- the group headings this is meant to match ---
foreach ( keel_defaults_group_labels() as $key => $label ) {
	$words = preg_split( '/\s+/', $label );
	foreach ( $words as $i => $word ) {
		$bare  = strtolower( preg_replace( '/[^A-Za-z-]/', '', $word ) );
		$first = preg_replace( '/[^A-Za-z-]/', '', $word );
		if ( '' === $first || ( 0 !== $i && in_array( $bare, $lowercase_ok, true ) ) ) {
			continue;
		}
		keel_assert(
			preg_match( '/^[A-Z]/', $first ),
			"Group heading '{$label}' is not Title Case: '{$word}' should be capitalized."
		);
	}
}

/*
 * Sectioned fields draw one heading from the section title; everything else
 * draws its own. Both sources have to reach the assertions above, so pin that
 * a heading of each kind is actually present in the rendered markup — otherwise
 * a future refactor could drop one source and this file would still pass.
 */
keel_assert( in_array( 'REST API', $headings, true ), 'A section-titled heading is present in the render (REST API).' );
keel_assert( in_array( 'Login Logo', $headings, true ), 'A label-titled heading is present in the render (Login Logo).' );

if ( $fail > 0 ) {
	fwrite( STDERR, "settings heading case: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'settings heading case: OK (' . count( $headings ) . " headings checked)\n" );
