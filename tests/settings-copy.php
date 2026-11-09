<?php
/**
 * Copy guards for the settings screen.
 *
 * Keel had no test on its display strings at all, which is how the password
 * help came to be rewritten six times in one day across three sibling plugins —
 * shortened, re-expanded for a wordpress.org disclosure requirement, clarified,
 * trimmed, re-expanded again, and finally moved to a help tab. Every edit was
 * defensible on its own. The oscillation was the problem, and nothing could see
 * it because nothing was watching the string.
 *
 * These assert the conventions rather than the prose: where a given kind of
 * content belongs, and which claims we have retired.
 *
 * Run: php tests/settings-copy.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
// Nothing has enqueued anything: keel_defaults_admin_menu_width_source() reads these.
function did_action( $hook ) { return 0; }
function doing_action( $hook = null ) { return false; }
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
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Word count of a help string, ignoring markup.
 *
 * @param string $help Help text.
 * @return int
 */
function keel_help_words( $help ) {
	return str_word_count( preg_replace( '/<[^>]*>/', '', (string) $help ) );
}

/*
 * The help tabs and the sidebar are built inside keel_defaults_add_help_tab(),
 * which hands them to WP_Screen. Nothing in this file could see them until now,
 * which is why the copy assertions below all read $strings — and why a wrong
 * count and a stale cross-reference could both sit in the tab text unwatched.
 */
class Keel_Copy_Test_Screen {
	public $tabs    = array();
	public $sidebar = '';

	/**
	 * Collect a help tab.
	 *
	 * @param array $args Tab definition.
	 */
	public function add_help_tab( $args ) {
		$this->tabs[ $args['id'] ] = $args['content'];
	}

	/**
	 * Collect the sidebar.
	 *
	 * @param string $html Sidebar markup.
	 */
	public function set_help_sidebar( $html ) {
		$this->sidebar = $html;
	}
}

$GLOBALS['keel_test_screen'] = new Keel_Copy_Test_Screen();

function get_current_screen() {
	return $GLOBALS['keel_test_screen'];
}
function wp_kses( $html, $allowed = array() ) {
	return $html;
}
function esc_url( $url ) {
	return $url;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function is_main_site() { return true; }
function is_multisite() {
	return false;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function number_format_i18n( $number ) {
	return (string) $number;
}

keel_defaults_add_help_tab();

$overview = $GLOBALS['keel_test_screen']->tabs['keel-overview'];
$sidebar  = $GLOBALS['keel_test_screen']->sidebar;

keel_assert( '' !== trim( $overview ), 'The Overview help tab has content to check.' );
keel_assert( '' !== trim( $sidebar ), 'The help sidebar has content to check.' );

$strings = keel_defaults_strings();
keel_assert( ! empty( $strings ), 'Display strings load.' );

// --- where field copy goes ---
// Each field carries one short sentence: it is tied to its control with
// aria-describedby, so a screen reader reads it on every focus, and a paragraph
// read on every Tab press is a real cost. The longer explanation (defaults,
// trade-offs, filters, what breaks) lives in the Settings in detail help tab,
// and the reasoning behind a policy lives in its own topical tab. Dan settled
// this on 2026-11-09, replacing the earlier 40-55 word rule for passwords.
foreach ( $strings as $key => $copy ) {
	if ( ! isset( $copy['help'] ) ) {
		continue;
	}
	$field_words = keel_help_words( $copy['help'] );
	keel_assert( $field_words <= 25, "Field help for '{$key}' is one short sentence, 25 words or fewer (currently {$field_words}); put the rest in its 'detail'." );
}

$detail_tab = isset( $GLOBALS['keel_test_screen']->tabs['keel-settings-detail'] ) ? $GLOBALS['keel_test_screen']->tabs['keel-settings-detail'] : '';
keel_assert( '' !== $detail_tab, 'A Settings in detail help tab is registered.' );
foreach ( $strings as $key => $copy ) {
	if ( empty( $copy['detail'] ) || ! keel_defaults_key_supported( $key ) ) {
		continue;
	}
	keel_assert(
		false !== strpos( $detail_tab, $copy['label'] ),
		"The detail for '{$key}' is reachable: its label appears in the Settings in detail tab."
	);
}

$password_help = $strings['require_strong_passwords']['help'];
keel_assert( false !== strpos( $password_help, '15' ), 'Password field help states the length rule.' );
keel_assert( false === strpos( $password_help, 'haveibeenpwned.com' ), 'The HIBP link stays out of the field help — the help tab and readme.txt carry it.' );
keel_assert( false === strpos( $password_help, 'pages.nist.gov' ), 'The NIST link stays out of the field help for the same reason.' );

/*
 * The same convention, applied to XML-RPC: the cost-of-blocking reasoning lives
 * in the XML-RPC tab, not in the field, and the field's detail points at it.
 */
$xmlrpc_help = $strings['block_xmlrpc_endpoint']['help'];
keel_assert(
	false === stripos( $xmlrpc_help, 'PHP still starts' ) && false === stripos( $xmlrpc_help, 'CDN' ),
	'The cost-of-blocking-in-PHP reasoning stays in the XML-RPC help tab, not the field help.'
);
keel_assert(
	false !== stripos( $strings['block_xmlrpc_endpoint']['detail'], 'help tab' ),
	'The XML-RPC endpoint detail points at the tab that carries the reasoning.'
);

/*
 * And the tab has to actually exist, or the pointer above is a dead reference.
 *
 * Read from the captured tab rather than grepped out of the source file, and
 * asserted on the claim rather than on a clause. The first version of this pinned
 * the literal words "before the request reaches WordPress", which made it fire on
 * a pure rewording — the sentence saying the same thing with "even reaches
 * WordPress" instead. A guard that cannot tell a rewrite from a deletion trains
 * people to edit the test until it passes, which is how a guard stops guarding.
 *
 * What has to survive is the substance: that blocking inside WordPress still
 * costs a PHP request, and that a host, CDN or firewall can refuse xmlrpc.php
 * earlier. Those three tokens can be reworded around; they cannot be dropped
 * while the paragraph still makes its point.
 */
keel_assert( isset( $GLOBALS['keel_test_screen']->tabs['keel-xmlrpc'] ), 'An XML-RPC help tab is registered.' );

$xmlrpc_tab = $GLOBALS['keel_test_screen']->tabs['keel-xmlrpc'];

foreach ( array( 'xmlrpc.php', 'CDN', 'PHP' ) as $token ) {
	keel_assert(
		false !== stripos( $xmlrpc_tab, $token ),
		"The XML-RPC tab still makes the cost-of-blocking point; '{$token}' has gone from it."
	);
}

// --- no field description sends the reader off-site ---
// A link in a narrow admin column is a second copy of something readme.txt or a
// help tab already says properly, and two copies drift.
foreach ( $strings as $key => $copy ) {
	if ( ! isset( $copy['help'] ) ) {
		continue;
	}
	keel_assert( false === strpos( $copy['help'], '<a href' ), "Field help for '{$key}' carries no external link." );
}

// --- retired claims ---
//
// The list is not here. It lives in tests/fixtures/retired-copy.json, shared
// with the sibling plugins, because these three say the same things about the
// same WordPress behaviours and therefore retire the same wrong claims. Each
// repo used to keep its own copy, so retiring a phrase in one did not retire it
// in the others — which is how an author-identity leak lived in two plugins
// while the fix sat in the third.
//
// Scanned across every string, not pinned to the setting each phrase was
// retired from: a guard that only watches the one sentence that diverged would
// miss the same claim reappearing elsewhere.
$fixture_path = __DIR__ . '/fixtures/retired-copy.json';
keel_assert( is_file( $fixture_path ), 'The shared retired-copy fixture exists.' );

$fixture = json_decode( file_get_contents( $fixture_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert( is_array( $fixture ) && isset( $fixture['shared'] ), 'The shared fixture parses and has a shared section.' );

$retired = array();
foreach ( array_merge( $fixture['shared'], isset( $fixture['keel'] ) ? $fixture['keel'] : array() ) as $entry ) {
	$retired[ $entry['phrase'] ] = $entry['why'];
}

// A fixture that loaded but yielded nothing would make every assertion below
// pass without checking anything.
keel_assert( count( $retired ) >= count( $fixture['shared'] ), 'The fixture yielded the shared phrases (' . count( $retired ) . ').' );

/*
 * Every surface that carries copy, not just the schema strings.
 *
 * The first version of this scan read $strings only — label, statement, help —
 * and a retired phrase planted in a help tab passed it clean. Most of this
 * plugin's prose lives in the help tabs and the sidebar, which is precisely
 * where the wording being retired tends to be.
 */
$copy_surfaces = array();

foreach ( $strings as $key => $copy ) {
	foreach ( array( 'label', 'statement', 'help', 'detail' ) as $slot ) {
		if ( isset( $copy[ $slot ] ) ) {
			$copy_surfaces[ "strings[{$key}][{$slot}]" ] = $copy[ $slot ];
		}
	}
}

foreach ( $GLOBALS['keel_test_screen']->tabs as $tab_id => $tab_html ) {
	$copy_surfaces[ "help tab '{$tab_id}'" ] = $tab_html;
}

$copy_surfaces['help sidebar'] = $GLOBALS['keel_test_screen']->sidebar;

/*
 * The same lesson as the help tabs, learned again on a different surface. The
 * Updates-screen offer is prose in a PHP file, not a schema string or a help tab, so
 * two retired phrases lived there through a clean run of this scan. AX's sibling guard
 * walks every PHP file under includes/ for exactly this reason; this one now does too.
 */
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $include_path ) {
	$copy_surfaces[ 'includes/' . basename( $include_path ) ] = file_get_contents( $include_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

foreach ( array( 'readme.txt', 'README.md' ) as $doc ) {
	$doc_path = dirname( __DIR__ ) . '/' . $doc;
	if ( is_file( $doc_path ) ) {
		$copy_surfaces[ $doc ] = file_get_contents( $doc_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}

keel_assert( count( $copy_surfaces ) > 40, 'The retired-phrase scan has surfaces to search (' . count( $copy_surfaces ) . ').' );
keel_assert( isset( $copy_surfaces["help tab 'keel-passwords'"] ), 'The scan reaches the help tabs, not only the schema strings.' );
keel_assert( isset( $copy_surfaces['includes/updates-screen.php'] ), 'The scan reaches prose that lives in PHP files rather than in the schema.' );

/**
 * Strip lines that deliberately quote a retired phrase to explain it.
 *
 * A repository that documents why wording was retired necessarily contains that
 * wording. Better by Default is a teaching repository and does this on purpose;
 * Keel does it in the comment beside the password rule. A bare substring scan
 * cannot tell that from a relapse, so a line carrying `keel-retired-ok` is
 * skipped — and nothing else is, which keeps the escape hatch deliberate and
 * visible in review rather than a general amnesty.
 *
 * @param string $text Copy to scan.
 * @return string
 */
function keel_strip_retired_exemptions( $text ) {
	$kept = array();

	foreach ( preg_split( '/\r\n|\n/', (string) $text ) as $line ) {
		if ( false === stripos( $line, 'keel-retired-ok' ) ) {
			$kept[] = $line;
		}
	}

	return implode( "\n", $kept );
}

foreach ( $retired as $phrase => $why ) {
	foreach ( $copy_surfaces as $where => $text ) {
		keel_assert(
			false === stripos( keel_strip_retired_exemptions( $text ), $phrase ),
			"Retired phrase '{$phrase}' appears in {$where} — {$why}."
		);
	}
}

// --- naming a strength scale ---
// zxcvbn's score 3 is what WordPress labels "Medium". Any copy naming a strength
// word has to say which scale it means, or the two readings differ by one step.
foreach ( $strings as $key => $copy ) {
	if ( ! isset( $copy['help'] ) ) {
		continue;
	}
	$copy_text = $copy['help'] . ( isset( $copy['detail'] ) ? ' ' . $copy['detail'] : '' );
	if ( false !== strpos( $copy_text, 'strength meter' ) ) {
		keel_assert(
			false !== strpos( $copy_text, 'Medium' ) || false !== strpos( $copy_text, 'zxcvbn' ),
			"Copy for '{$key}' naming a strength meter says which scale it means."
		);
	}
}

// --- every setting has copy at all ---
$schema = keel_defaults_schema();
foreach ( array_keys( $schema ) as $key ) {
	keel_assert( isset( $strings[ $key ]['label'] ), "Setting '{$key}' has a label." );
}

/*
 * --- a stated count, checked against the thing counted ---
 *
 * The Overview tab says how many settings hide themselves. The number was very
 * nearly written as "Two", counted off the two *examples* the old sentence gave
 * rather than off the schema — which has five, and one of them (Password Policy
 * Exemptions) the old sentence never mentioned at all.
 *
 * This is the shape the sibling deck got wrong too: an agenda promising eight
 * categories over a deck presenting seven. Every artifact was internally
 * consistent; the gap lived between the claim and the thing claimed about.
 */
$dependent = array();
foreach ( keel_defaults_schema() as $key => $field ) {
	if ( isset( $field['depends'] ) ) {
		$dependent[] = $key;
	}
}

keel_assert( count( $dependent ) > 0, 'The schema has dependent settings to count.' );

$number_words = array(
	1 => 'One',
	2 => 'Two',
	3 => 'Three',
	4 => 'Four',
	5 => 'Five',
	6 => 'Six',
	7 => 'Seven',
	8 => 'Eight',
	9 => 'Nine',
);
$expected     = isset( $number_words[ count( $dependent ) ] ) ? $number_words[ count( $dependent ) ] : (string) count( $dependent );

keel_assert(
	false !== strpos( $overview, $expected . ' settings disappear' ),
	'The Overview tab says "' . $expected . ' settings disappear" — the schema has ' . count( $dependent ) . ' with a "depends" rule.'
);

/*
 * Each dependent setting is also named or covered by the sentence, so the count
 * cannot go on being right while the list quietly stops describing it. The
 * XML-RPC three are covered collectively, which is why they are matched by their
 * shared prefix rather than one by one.
 */
foreach ( $dependent as $key ) {
	if ( 0 === strpos( $key, 'xmlrpc_' ) ) {
		continue;
	}

	$label = isset( $strings[ $key ]['label'] ) ? $strings[ $key ]['label'] : '';
	keel_assert(
		'' !== $label && false !== strpos( $overview, $label ),
		"The Overview tab names '{$label}' as a setting that hides itself."
	);
}

keel_assert(
	false !== strpos( $overview, 'three XML-RPC method controls' ),
	'The Overview tab covers the XML-RPC three as a group, and says how many there are.'
);
keel_assert(
	3 === count(
		array_filter(
			$dependent,
			static function ( $k ) {
				return 0 === strpos( $k, 'xmlrpc_' );
			}
		)
	),
	'There really are three dependent XML-RPC controls.'
);

/*
 * --- a cross-reference to a label, checked against the label ---
 *
 * The settings screen tells the reader which Site Health section to look in. That
 * section was renamed from "Keel" to "Keel Defaults" and this sentence went on
 * naming the old one, sending people to look for something that is not there.
 */
$info_section = keel_defaults_debug_information( array() );
$info_label   = $info_section[ KEEL_DEFAULTS_INFO_SECTION ]['label'];

keel_assert(
	false !== strpos( $sidebar, '<strong>' . $info_label . '</strong>' ),
	"The screen points at the Site Health section by its real label ('{$info_label}')."
);

echo "settings copy: OK\n";
