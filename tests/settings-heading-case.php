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
 * Two conventions, not one applied inconsistently.
 *
 * A function word standing on its own in a title and the same word buried inside
 * a hyphenated compound are governed by different rules, and trying to express
 * both with one list cannot work.
 *
 * $lowercase_ok — articles and coordinating conjunctions. Lowercase as a
 * standalone word unless it leads. Prepositions are NOT here: they capitalize
 * standing alone, which is the sibling plugins' rule and the one all three
 * adopted, because it follows copy people had already written by hand —
 * "Accounts Per Step" predates every guard.
 *
 * $interior_ok — the same words PLUS prepositions. Lowercase only when interior
 * to a compound. First and last segments always capitalize.
 *
 * That second list is what makes "Out-of-the-Box Defaults" and "Accounts Per
 * Step" both correct at once. No single list can: 'of' has to be down inside the
 * compound and 'Per' has to be up on its own.
 *
 * This file previously exempted only $lowercase_ok inside compounds, which got
 * four of sixteen cases wrong — it rejected "State-of-the-Art Tooling" and
 * accepted "Out-Of-The-Box Defaults". The fixtures below did not cover a
 * mid-compound function word at all, which is exactly why all three plugins'
 * self-tests missed it.
 */
$lowercase_ok = array( 'a', 'an', 'the', 'and', 'or', 'nor', 'but' );
$interior_ok  = array_merge( $lowercase_ok, array( 'of', 'in', 'on', 'to', 'at', 'by', 'for', 'with', 'from', 'per', 'via', 'as' ) );

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
 * @param string[] $lowercase_ok Words that stay lowercase standing alone.
 * @param string[] $interior_ok  Words that stay lowercase inside a compound.
 * @return string[] Problems found; empty means the heading is well-formed.
 */
function keel_heading_case_errors( $heading, $lowercase_ok, $interior_ok ) {
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

		if ( false === strpos( $first, '-' ) ) {
			continue;
		}

		/*
		 * A hyphenated compound: first and last segments capitalize, and an
		 * interior function word stays down — "Out-of-the-Box", "State-of-the-Art",
		 * "Cut-and-Dried". Position is what decides, which is why this cannot be
		 * folded into the standalone check above.
		 */
		$parts = explode( '-', $first );
		$last  = count( $parts ) - 1;

		foreach ( $parts as $k => $part ) {
			if ( '' === $part ) {
				continue;
			}

			$interior = ( 0 !== $k && $last !== $k );

			if ( $interior && in_array( strtolower( $part ), $interior_ok, true ) ) {
				if ( strtolower( $part ) !== $part ) {
					$errors[] = "'{$part}' inside '{$word}' is a function word and should stay lowercase.";
				}
				continue;
			}

			if ( ! preg_match( '/^[A-Z]/', $part ) ) {
				$errors[] = "'{$part}' in '{$word}' should be capitalized.";
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
 * The first twelve accepts and nine rejects are a SHARED set, carried by Keel,
 * Better by Default and a third, private sibling. All three enforce the same
 * rule, so a plugin whose rule drifts fails its own copy of these rather than
 * producing headings that cannot move between the repositories.
 *
 * What that does not buy, said plainly because it would otherwise be assumed:
 * nothing enforces that the copies stay identical. Edit this block without
 * editing the others and the sets diverge in silence — the drift the shared set
 * exists to prevent, one level above the code. It happened between keel#57 and
 * the day's last pass: this file sat two pairs behind while every test passed.
 *
 * Keeping them in step is a matter of somebody noticing, which is a deliberate
 * trade rather than an oversight. Sharing them properly needs vendoring or a
 * package, and that would need its own release cycle to change one string —
 * more machinery than the rule is worth now that it has settled. Revisit if a
 * fourth plugin joins, or if the rule starts moving again.
 *
 * Better by Default carries its copy in tests/plugin-policy.php. When two copies
 * disagree, which one is right is a maintainer call; the strings below each say
 * what they pin, so the argument can be had on the merits.
 */
$heading_case_accepts = array(
	'Pingbacks On New Posts'   => 'a preposition capitalizes',
	'Accounts Per Step'        => 'so does "Per"',
	'Front-End Admin Bar'      => 'both halves of a hyphenated compound',
	'Comments and Pings'       => 'a coordinating conjunction stays lowercase',
	'REST API'                 => 'an all-caps acronym',
	'XML-RPC'                  => 'a hyphenated all-caps acronym',
	'Cut-and-Dried Policy'     => 'an interior conjunction in a compound stays down',
	'State-of-the-Art Tooling' => 'so do interior prepositions',
	'Out-of-the-Box Defaults'  => 'more than one of them',
	'Opt-In Defaults'          => 'a function word at the END of a compound still capitalizes — position decides, not membership',
	'In-House Tooling'         => 'and at the START of one — the mirror case',
	'The Defaults Screen'      => 'a small word LEADING a title capitalizes',
);

$heading_case_rejects = array(
	'Pingbacks on New Posts'  => 'a lowercased preposition — the case that inverted when the rule changed',
	'Front-end Admin Bar'     => 'the second half of a hyphenated compound',
	'Comments And Pings'      => 'a capitalized conjunction',
	'pingbacks on new posts'  => 'no capitals at all',
	'Cut-And-Dried Policy'    => 'an interior conjunction wrongly capitalized',
	'Out-Of-The-Box Defaults' => 'interior prepositions wrongly capitalized',
	'Opt-in Defaults'         => 'the last segment of a compound is not interior, so it must capitalize',
	'in-House Tooling'        => 'nor is the FIRST',
	'the Defaults Screen'     => 'a leading small word left lowercase',
);

foreach ( $heading_case_accepts as $case => $why ) {
	$problems = keel_heading_case_errors( $case, $lowercase_ok, $interior_ok );
	keel_assert(
		array() === $problems,
		"Self-test: '{$case}' should be accepted ({$why}), but the checker reported: " . implode( ' ', $problems )
	);
}

foreach ( $heading_case_rejects as $case => $why ) {
	keel_assert(
		array() !== keel_heading_case_errors( $case, $lowercase_ok, $interior_ok ),
		"Self-test: '{$case}' should be rejected ({$why}), but the checker accepted it."
	);
}

// --- the headings this plugin actually ships ---
foreach ( $headings as $heading ) {
	foreach ( keel_heading_case_errors( $heading, $lowercase_ok, $interior_ok ) as $problem ) {
		keel_assert( false, "Row heading '{$heading}': {$problem}" );
	}
}

foreach ( keel_defaults_group_labels() as $key => $label ) {
	foreach ( keel_heading_case_errors( $label, $lowercase_ok, $interior_ok ) as $problem ) {
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
