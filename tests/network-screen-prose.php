<?php
/**
 * One prose measure on the network policy screen.
 *
 * The intro copy shipped with `style="max-width:46em;"` inline on two
 * paragraphs, from the commit that first built the screen. Measured against
 * this WordPress tree's own admin CSS, that is 598px — and it stays 598px while
 * everything under it grows with the viewport:
 *
 *     viewport   intro          settings table   help text in a row
 *     1280px     598px / 74ch   1078px           848px /  98ch
 *     1600px     598px / 74ch   1398px          1168px / 135ch
 *     1920px     598px / 74ch   1718px          1488px / 172ch
 *
 * Read as "the intro is cramped", the fix is to remove the cap. Measured, the
 * opposite is true: 74 characters is inside the 45-75 range a line wants to be,
 * and uncapping the intro takes it to 212 characters at 1920px. The intro was
 * never the defect. It looked wrong because it was the only prose on the screen
 * that was right, sitting above help text running to 172 characters.
 *
 * So the measure stays and stops being a one-off. What this pins is the part
 * that made it a defect in the first place: the rule has to reach the whole
 * screen's prose, and it has to live somewhere the network screen actually
 * loads. Those are two separate ways to get this wrong and the second one has
 * already happened here once — the lock-note styles sat in settings.css, which
 * the network screen deliberately does not enqueue, so they never applied to
 * the screen they were written for.
 *
 * Run: php tests/network-screen-prose.php
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

$root = dirname( __DIR__ );

/**
 * Read a repository file.
 *
 * @param string $relative Path from the repository root.
 * @return string Contents, or '' when the file is absent.
 */
function keel_read( $relative ) {
	$path = dirname( __DIR__ ) . '/' . $relative;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file in a test.
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$network_src = keel_read( 'includes/network.php' );
$assets_src  = keel_read( 'includes/assets.php' );
$network_css = keel_read( 'assets/css/network.css' );

keel_assert( '' !== $network_src, 'includes/network.php is readable.' );

/*
 * --- the measure is not inline ---
 *
 * An inline style cannot be overridden by a stylesheet without !important, is
 * invisible to anyone reading the CSS, and — the reason it matters here — can
 * only ever describe the one element it sits on. The screen grew around these
 * two paragraphs and nothing else could inherit the decision.
 */
keel_assert(
	1 !== preg_match( '/<p[^>]*class="[^"]*description[^"]*"[^>]*style="[^"]*max-width/', $network_src ),
	'No intro paragraph carries an inline max-width; the measure belongs in the stylesheet.'
);

/*
 * --- the intro is addressable from CSS ---
 */
keel_assert(
	false !== strpos( $network_src, 'keel-network-intro' ),
	'The intro paragraphs carry a class a stylesheet can reach.'
);
keel_assert(
	substr_count( $network_src, 'keel-network-intro' ) >= 2,
	'Both intro paragraphs carry it, not just the first (' . substr_count( $network_src, 'keel-network-intro' ) . ' found).'
);

/*
 * --- the stylesheet exists and says it once ---
 */
keel_assert( '' !== $network_css, 'assets/css/network.css exists.' );

keel_assert(
	1 === preg_match( '/\.keel-network-intro/', $network_css ),
	'The stylesheet gives the intro its measure.'
);

/*
 * The half that makes this a fix rather than a tidy-up. Capping the intro alone
 * would leave it the narrowest prose on a screen whose help text is unbounded,
 * which is the complaint restated rather than answered.
 */
keel_assert(
	1 === preg_match( '/form-table\s+p\.description/', $network_css ),
	'The same measure reaches the help text inside the settings rows.'
);

/*
 * `em`, not `px`. The intro renders at 13px and the row help at 14px, because
 * the form table sets its own size — so a pixel cap would give the two a
 * different number of characters per line and miss the point of sharing a rule.
 */
keel_assert(
	1 === preg_match( '/max-width:\s*\d+(?:\.\d+)?em/', $network_css ),
	'The measure is expressed in em, so it tracks each element\'s own font size.'
);

/*
 * --- and the network screen actually loads it ---
 *
 * The failure this guards against has happened on this screen before. The lock
 * note and locked-control styles lived in settings.css, which the network
 * screen deliberately does not enqueue, so a screen that rendered locked
 * controls styled none of them. Shipping a rule the screen never loads looks
 * exactly like shipping no rule.
 */
keel_assert(
	1 === preg_match( '/keel_defaults_enqueue_network_assets\s*\(\s*\)\s*\{(?:[^}]*)css\/network\.css/s', $assets_src )
		|| 1 === preg_match( '/function keel_defaults_enqueue_network_assets.*?css\/network\.css/s', $assets_src ),
	'keel_defaults_enqueue_network_assets() enqueues css/network.css.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "network screen prose: {$fail} failed\n" );
	exit( 1 );
}

echo "network screen prose: OK\n";
