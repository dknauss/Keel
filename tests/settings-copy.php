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

$strings = keel_defaults_strings();
keel_assert( ! empty( $strings ), 'Display strings load.' );

// --- where password copy goes ---
// The rule lives in the field; the reasoning and the protocol live in the
// Passwords help tab; readme.txt carries the compliance disclosure. Settled
// across all three plugins after the string oscillated six times in a day.
$password_help = $strings['require_strong_passwords']['help'];
$words         = keel_help_words( $password_help );

keel_assert( $words >= 40 && $words <= 55, "Password field help states the rule in 40-55 words (currently {$words})." );
keel_assert( false === strpos( $password_help, 'haveibeenpwned.com' ), 'The HIBP link stays out of the field help — the help tab and readme.txt carry it.' );
keel_assert( false === strpos( $password_help, 'pages.nist.gov' ), 'The NIST link stays out of the field help for the same reason.' );

/*
 * The same convention, applied to XML-RPC.
 *
 * block_xmlrpc_endpoint's description carried 62 words, and a third of them were
 * "PHP still starts for each blocked request; blocking at the host or CDN is
 * lighter" — which is why-we-built-it-this-way, not what-it-costs-you. It sat
 * there because moving it meant creating a tab for one paragraph.
 *
 * The tab exists now, so the field help states the rule and points at it. These
 * assertions stop the reasoning drifting back into the control, which is the
 * direction copy always drifts: the person editing is looking at the field.
 */
$xmlrpc_help  = $strings['block_xmlrpc_endpoint']['help'];
$xmlrpc_words = str_word_count( preg_replace( '/<[^>]*>/', '', $xmlrpc_help ) );

keel_assert( $xmlrpc_words <= 50, "The XML-RPC endpoint field help states the rule in 50 words or fewer (currently {$xmlrpc_words})." );
keel_assert(
	false === stripos( $xmlrpc_help, 'PHP still starts' ) && false === stripos( $xmlrpc_help, 'CDN' ),
	'The cost-of-blocking-in-PHP reasoning stays in the XML-RPC help tab, not the field help.'
);
keel_assert(
	false !== stripos( $xmlrpc_help, 'help tab' ),
	'The XML-RPC endpoint field help points at the tab that carries the reasoning.'
);

// And the tab has to actually exist, or the pointer above is a dead reference.
$settings_page = file_get_contents( dirname( __DIR__ ) . '/includes/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert( false !== strpos( $settings_page, "'keel-xmlrpc'" ), 'An XML-RPC help tab is registered.' );
keel_assert(
	false !== stripos( $settings_page, 'before the request reaches WordPress' ),
	'The reasoning moved out of the field help landed in the tab rather than being deleted.'
);

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
// Scanned across every string, not pinned to the setting each phrase was
// retired from. The failure this exists for was a phrase surviving in one
// plugin after a sibling retired it, so a guard that only watches the one
// sentence that diverged would miss the same claim reappearing elsewhere.
// Retiring another phrase is one array entry, not a new assertion.
$retired = array(
	'trust leak' => 'overstates an off-site link as a leak; Better by Default retired it and asserts against it',
);

foreach ( $retired as $phrase => $why ) {
	foreach ( $strings as $key => $copy ) {
		foreach ( array( 'label', 'statement', 'help' ) as $slot ) {
			if ( ! isset( $copy[ $slot ] ) ) {
				continue;
			}
			keel_assert(
				false === stripos( $copy[ $slot ], $phrase ),
				"Retired phrase '{$phrase}' does not appear in {$slot} for '{$key}' — {$why}."
			);
		}
	}
}

// --- naming a strength scale ---
// zxcvbn's score 3 is what WordPress labels "Medium". Any copy naming a strength
// word has to say which scale it means, or the two readings differ by one step.
foreach ( $strings as $key => $copy ) {
	if ( ! isset( $copy['help'] ) ) {
		continue;
	}
	if ( false !== strpos( $copy['help'], 'strength meter' ) ) {
		keel_assert(
			false !== strpos( $copy['help'], 'Medium' ) || false !== strpos( $copy['help'], 'zxcvbn' ),
			"Copy for '{$key}' naming a strength meter says which scale it means."
		);
	}
}

// --- every setting has copy at all ---
$schema = keel_defaults_schema();
foreach ( array_keys( $schema ) as $key ) {
	keel_assert( isset( $strings[ $key ]['label'] ), "Setting '{$key}' has a label." );
}

echo "settings copy: OK\n";
