<?php
/**
 * The network policy screen does not cap the width of its own prose.
 *
 * It used to, on two paragraphs, with `style="max-width:46em;"` inline — from
 * the commit that first built the screen. Measured against this WordPress
 * tree's admin CSS at a 1920px window, that left the screen like this:
 *
 *     intro          settings table   help text in a row
 *     598px / 74ch   1718px           1488px / 172ch
 *
 * which was reported as "the introductory copy is confined to roughly half the
 * content width while the settings below use all of it".
 *
 * Two coherent answers exist and the middle ground is not one of them. Cap
 * every piece of prose and the screen is internally consistent; cap none and it
 * behaves like the admin around it. Cap only the intro — any value, including
 * core's own 800px — and the mismatch that was reported is still on screen,
 * because the table beside it is 1718px either way.
 *
 * WordPress itself does both, and picks by screen. `wp-admin/network.php` runs
 * thirteen paragraphs at full width with no cap. `edit.css` caps
 * `.privacy-settings-body` and `.health-check-body` at 800px, because those
 * screens lead with explanation. The closest analogue to this one,
 * `wp-admin/network/settings.php`, avoids the question: its page is an `<h1>`
 * and a form table, and all eight explanatory paragraphs live in a help tab.
 *
 * Keel does not follow that last one. "Nothing is hidden. Everything is
 * explained" is the first promise in the readme, and an explanation behind a
 * collapsed tab is not the same promise. The prose stays on the page, and the
 * admin's own width is what wraps it.
 *
 * So this guards a deletion, which is the hard kind to keep deleted. A cap can
 * come back in two places — inline on the markup, or in any stylesheet this
 * screen loads — and both look reasonable in isolation to whoever adds one.
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

keel_assert( '' !== $network_src, 'includes/network.php is readable.' );
keel_assert( '' !== $assets_src, 'includes/assets.php is readable.' );

/*
 * --- nothing inline on the markup ---
 *
 * Where it was last time. An inline style is also the version of this that no
 * stylesheet review would catch.
 */
keel_assert(
	0 === preg_match_all( '/<p[^>]*class="[^"]*description[^"]*"[^>]*style="[^"]*(?:max-)?width/i', $network_src, $inline ),
	'No paragraph on the network screen carries an inline width.'
);

/*
 * --- and nothing in the stylesheets this screen loads ---
 *
 * Read the enqueues rather than naming the files, so a stylesheet added to this
 * screen later is covered on the day it is added rather than the day someone
 * remembers to extend this list. The network screen's assets come from
 * keel_defaults_enqueue_network_assets(), which currently delegates to
 * keel_defaults_enqueue_locked_controls() — both are scanned, and so is
 * anything either of them grows.
 */
$loaded = array();
if ( preg_match( '/function keel_defaults_enqueue_network_assets.*?\n\}/s', $assets_src, $network_fn ) ) {
	$body = $network_fn[0];

	// Follow one level of delegation, which is how this screen gets its CSS today.
	if ( false !== strpos( $body, 'keel_defaults_enqueue_locked_controls' )
		&& preg_match( '/function keel_defaults_enqueue_locked_controls.*?\n\}/s', $assets_src, $locked_fn ) ) {
		$body .= $locked_fn[0];
	}

	if ( preg_match_all( '#[\'"](css/[a-z0-9._-]+\.css)[\'"]#i', $body, $found ) ) {
		$loaded = array_unique( $found[1] );
	}
}

keel_assert(
	array() !== $loaded,
	'Found the stylesheets the network screen enqueues. Finding none would pass every check below without reading anything.'
);

foreach ( $loaded as $sheet ) {
	$css = keel_read( 'assets/' . $sheet );

	keel_assert( '' !== $css, "assets/{$sheet} is readable." );

	/*
	 * Only rules that speak about prose. A width on a control, an icon or a
	 * layout box is somebody else's decision and not this test's business —
	 * banning every max-width would make this fail for reasons unrelated to
	 * what it is protecting, and a guard that cries wolf gets deleted.
	 */
	if ( ! preg_match_all( '/([^{}]*\bdescription\b[^{}]*)\{([^}]*)\}/i', $css, $rules, PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $rules as $rule ) {
		keel_assert(
			1 !== preg_match( '/(?:^|[;\s])(?:max-)?width\s*:/i', $rule[2] ),
			sprintf(
				'assets/%s caps the width of prose on this screen: "%s". The screen deliberately lets the admin column wrap it.',
				$sheet,
				trim( preg_replace( '/\s+/', ' ', $rule[1] ) )
			)
		);
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "network screen prose: {$fail} failed\n" );
	exit( 1 );
}

printf(
	"network screen prose: OK (no width cap; %d stylesheet%s checked: %s)\n",
	count( $loaded ),
	1 === count( $loaded ) ? '' : 's',
	implode( ', ', $loaded )
);
