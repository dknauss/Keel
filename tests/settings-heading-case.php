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
 * Words that stay lowercase inside a title: articles and coordinating
 * conjunctions only. Anything leading is capitalized regardless, which is why
 * position is checked as well as membership.
 *
 * Prepositions capitalize — "Pingbacks On New Posts", "Accounts Per Step". That
 * is the sibling plugins' rule, adopted here after this file shipped a longer
 * list that also lowercased short prepositions.
 *
 * The two rules were not merely different, they were mutually exclusive: a
 * heading containing a preposition could not satisfy both, so no such string
 * could be copied between the three repositories. A sweep meant to end drift had
 * created a new kind of it.
 *
 * This list wins because the other one was arbitrary. It held 'per' and 'vs' but
 * stopped somewhere, with nothing deciding where — a half-list is worse than
 * either whole rule, because nothing tells you which half a new word belongs to.
 * The articles-and-conjunctions rule was derived from copy people had already
 * written by hand rather than imposed on it.
 */
$lowercase_ok = array( 'a', 'an', 'the', 'and', 'or', 'nor', 'but' );

/**
 * Everything wrong with one heading's capitalization, as a list of problems.
 *
 * The rule lives here once, and the row headings, the group headings and the
 * self-test below all ask this same function. That matters more than the tidiness
 * of it: the row and group loops used to carry their own copies, and the copies
 * had already drifted — the group loop never checked hyphenated compounds at all,
 * so "Front-end UX" as a group label would have passed while the identical string
 * one row down failed.
 *
 * Returns problems rather than asserting them, so a test can require that a
 * string is REJECTED. An assertion helper cannot express that.
 *
 * @param string   $heading      Heading text.
 * @param string[] $lowercase_ok Articles and coordinating conjunctions.
 * @return string[] Problems found; empty means the heading is well-formed.
 */
function keel_heading_case_errors( $heading, $lowercase_ok ) {
	$errors = array();

	foreach ( preg_split( '/\s+/', trim( (string) $heading ) ) as $i => $word ) {
		// Judge on the letters, so trailing punctuation and stray markup do not
		// decide the verdict.
		$first = preg_replace( '/[^A-Za-z-]/', '', $word );

		if ( '' === $first ) {
			continue;
		}

		$bare = strtolower( $first );

		if ( 0 !== $i && in_array( $bare, $lowercase_ok, true ) ) {
			if ( $first !== $bare ) {
				$errors[] = "'{$word}' is an article or coordinating conjunction inside a title and should stay lowercase.";
			}
			continue;
		}

		// Every other word starts with a capital. An all-caps acronym (REST,
		// HTML, AI, API) satisfies this too.
		if ( ! preg_match( '/^[A-Z]/', $first ) ) {
			$errors[] = "'{$word}' should be capitalized.";
		}

		// Both halves of a hyphenated compound — "Front-End Admin Bar", not
		// "Front-end". A half that is itself an article or conjunction stays
		// lowercase, so "Cut-and-Dried Policy" is correct English and passes.
		if ( false !== strpos( $first, '-' ) ) {
			foreach ( explode( '-', $first ) as $part ) {
				if ( '' === $part || in_array( strtolower( $part ), $lowercase_ok, true ) ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Z]/', $part ) ) {
					$errors[] = "both halves of '{$word}' should be capitalized.";
				}
			}
		}
	}

	return $errors;
}

/*
 * --- the self-test ---
 *
 * Every heading this plugin ships is currently correct, which is exactly why the
 * loops below cannot vouch for the checker. They pass whether the rule is
 * enforced or quietly broken, and a refactor that neuters it would leave this
 * suite green and silent — indistinguishable from a guard doing its job.
 *
 * So the checker is made to classify strings whose verdict is known, in both
 * directions. A rule added to keel_heading_case_errors() later needs a case in
 * both lists here, or this self-test silently under-covers it.
 *
 * The same fixtures are used by the sibling plugins, so a disagreement between
 * the three rules shows up as a failing case rather than as copy that cannot be
 * moved between repositories.
 */
$heading_case_accepts = array(
	'Pingbacks On New Posts' => 'a preposition capitalizes',
	'Accounts Per Step'      => 'so does "Per"',
	'Front-End Admin Bar'    => 'both halves of a hyphenated compound',
	'Comments and Pings'     => 'a coordinating conjunction stays lowercase',
	'REST API'               => 'an all-caps acronym',
	'XML-RPC'                => 'a hyphenated all-caps acronym',
);

$heading_case_rejects = array(
	'Pingbacks on New Posts' => 'a lowercased preposition — the case that inverted when the rule changed',
	'Front-end Admin Bar'    => 'the second half of a hyphenated compound',
	'Comments And Pings'     => 'a capitalized conjunction',
	'pingbacks on new posts' => 'no capitals at all',
);

foreach ( $heading_case_accepts as $case => $why ) {
	$problems = keel_heading_case_errors( $case, $lowercase_ok );
	keel_assert(
		array() === $problems,
		"Self-test: '{$case}' should be accepted ({$why}), but the checker reported: " . implode( ' ', $problems )
	);
}

foreach ( $heading_case_rejects as $case => $why ) {
	keel_assert(
		array() !== keel_heading_case_errors( $case, $lowercase_ok ),
		"Self-test: '{$case}' should be rejected ({$why}), but the checker accepted it."
	);
}

// --- the headings this plugin actually ships ---
foreach ( $headings as $heading ) {
	foreach ( keel_heading_case_errors( $heading, $lowercase_ok ) as $problem ) {
		keel_assert( false, "Row heading '{$heading}': {$problem}" );
	}
}

foreach ( keel_defaults_group_labels() as $key => $label ) {
	foreach ( keel_heading_case_errors( $label, $lowercase_ok ) as $problem ) {
		keel_assert( false, "Group heading '{$label}': {$problem}" );
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
