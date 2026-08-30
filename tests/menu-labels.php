<?php
/**
 * Keep documented admin menu paths pointing at menus that exist.
 *
 * The docs tell people where to click. Nothing checked that the destination was
 * real, and it drifted in both directions at once: `Settings → Keel` outlived
 * the menu label for five releases after `42a4ab6` renamed it to `Site
 * Defaults`, and the correction then over-applied and rewrote the *network*
 * path to `Site Defaults` as well — a screen that has always been registered as
 * `Keel Defaults`. One file, one pass, wrong in two directions, green CI.
 *
 * A menu label is the one piece of documentation a reader checks against their
 * own screen within seconds, so being wrong about it costs more trust per word
 * than almost anything else in the readme.
 *
 * The check is parent-aware, which is the part that matters. Both screens are
 * reached through a Settings menu and both labels are plausible, so a test that
 * only asked "is this a Keel label somewhere" would have passed the exact
 * mistake that prompted it. `Settings → X` is matched against the labels
 * registered with add_options_page(); `Network Admin → Settings → X` against
 * those registered with add_submenu_page() under settings.php.
 *
 * Only paths that name a Keel screen are checked. Core destinations —
 * `Settings → Discussion`, `Tools → Site Health` — are none of this test's
 * business, and an allowlist of them would be a second thing to maintain and
 * forget.
 *
 * Run: php tests/menu-labels.php
 *
 * @package keel
 */

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$root = dirname( __DIR__ );

/**
 * Pull the labels out of one WordPress menu registration call.
 *
 * Counts TRANSLATED STRINGS, not arguments, and the difference is the whole
 * subtlety. add_options_page() takes the page title first and the menu title
 * second, so they line up. add_submenu_page() puts a plain parent slug first —
 * 'settings.php', not a __() call — so its menu title is the third argument but
 * only the second translated string. Counting arguments here would read past the
 * end and find nothing.
 *
 * Reading the real registration is the point: a list of labels kept beside the
 * code is a second thing to update and the first thing to forget.
 *
 * @param string $code     PHP source to scan.
 * @param string $function Registration function name.
 * @param int    $position 1-based index of the menu title among the __() strings.
 * @return string[] Menu titles.
 */
function keel_menu_labels( $code, $function, $position ) {
	$labels = array();

	if ( ! preg_match_all( '/\b' . preg_quote( $function, '/' ) . '\s*\((.*?)\)\s*;/s', $code, $calls ) ) {
		return $labels;
	}

	foreach ( $calls[1] as $args ) {
		// Translated string literals, in the order they appear in the call.
		if ( ! preg_match_all( "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $args, $strings ) ) {
			continue;
		}

		if ( isset( $strings[1][ $position - 1 ] ) ) {
			$labels[] = stripslashes( $strings[1][ $position - 1 ] );
		}
	}

	return $labels;
}

// Reading repo files in a CLI test; wp_remote_get() is for HTTP, not this.
$settings_src = (string) file_get_contents( $root . '/includes/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$network_src  = (string) file_get_contents( $root . '/includes/network.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$site_labels    = keel_menu_labels( $settings_src, 'add_options_page', 2 );
$network_labels = keel_menu_labels( $network_src, 'add_submenu_page', 2 );

// Silence is not success: if the parse stops finding the calls, every documented
// path would match an empty set and the file would report a clean run it never
// performed. Both screens are registered, so finding neither is a broken test.
keel_assert(
	! empty( $site_labels ),
	'No add_options_page() label found in includes/settings-page.php — the parse is wrong, not the docs.'
);
keel_assert(
	! empty( $network_labels ),
	'No add_submenu_page() label found in includes/network.php — the parse is wrong, not the docs.'
);

/*
 * Documents whose menu paths are instructions to the reader.
 *
 * readme.txt is cut at the changelog. Entries below it describe releases as they
 * were, and the site menu genuinely did read `Keel` until 42a4ab6 — rewriting
 * history to satisfy a guard is how true records get edited into false ones.
 *
 * ROADMAP.md is left out entirely for the same reason at a larger scale: it is a
 * record of decisions and names things as they stood when each was taken.
 */
$docs = array( 'readme.txt', 'README.md' );

/**
 * A path names a Keel screen when its destination could only be one of ours.
 *
 * @param string $label Destination text.
 * @return bool
 */
function keel_is_keel_screen( $label ) {
	return (bool) preg_match( '/\b(Keel|Defaults|Network Policy)\b/i', $label );
}

$checked = 0;

/*
 * Match forward from the arrow rather than trying to find where the label ends.
 *
 * The obvious implementation — capture the destination, then compare it — has to
 * decide where "Site Defaults you can turn off" stops being a menu name, and
 * every answer is wrong somewhere: a lazy match truncates at "Site", a greedy one
 * swallows the rest of the sentence, and markdown bold is only sometimes there to
 * delimit it. Since the valid labels are already known, the reliable question is
 * the other way round: does the text after the arrow *begin with* one of them?
 * Prefix matching needs no end delimiter and cannot truncate.
 *
 * The sloppy extraction below is only ever used to write the error message, where
 * getting the boundary slightly wrong costs nothing.
 */
foreach ( $docs as $doc ) {
	$text = (string) file_get_contents( $root . '/' . $doc ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( 'readme.txt' === $doc ) {
		$changelog = strpos( $text, '== Changelog ==' );
		if ( false !== $changelog ) {
			$text = substr( $text, 0, $changelog );
		}
	}

	if ( ! preg_match_all( '/Settings\s*→\s*/u', $text, $arrows, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $arrows[0] as $arrow ) {
		$before = substr( $text, 0, (int) $arrow[1] );
		$after  = ltrim( substr( $text, (int) $arrow[1] + strlen( $arrow[0] ) ), '*' );

		// The parent decides which menu the path is claiming to be under, and
		// both screens sit behind the word "Settings", so this is the whole test.
		if ( preg_match( '/Network Admin\s*→\s*\**$/u', $before ) ) {
			$valid  = $network_labels;
			$parent = 'Network Admin → Settings';
		} else {
			$valid  = $site_labels;
			$parent = 'Settings';
		}

		$matched = false;
		foreach ( $valid as $label ) {
			// Boundary check so a label is not satisfied by a longer name that
			// merely starts with it.
			if ( 0 === strpos( $after, $label ) && ! preg_match( '/^[A-Za-z]/', (string) substr( $after, strlen( $label ), 1 ) ) ) {
				$matched = true;
				break;
			}
		}

		if ( $matched ) {
			++$checked;
			continue;
		}

		// Not one of ours. Core destinations are none of this test's business, so
		// only a destination that names a Keel screen is a failure.
		preg_match( '/^\**([A-Za-z][A-Za-z ]*)/u', $after, $guess );
		$destination = isset( $guess[1] ) ? rtrim( $guess[1] ) : '';

		if ( ! keel_is_keel_screen( $destination ) ) {
			continue;
		}

		++$checked;

		keel_assert(
			false,
			sprintf(
				'%s says "%s → %s", but the menu registered there is: %s.',
				$doc,
				$parent,
				$destination,
				implode( ', ', $valid )
			)
		);
	}
}

keel_assert(
	$checked > 0,
	'No documented Keel menu path was found in ' . implode( ' or ', $docs ) . '. Either the docs stopped telling people where to click, or this pattern stopped matching — both are failures.'
);

printf(
	"menu labels: OK (%d documented path%s; site: %s; network: %s)\n",
	$checked,
	1 === $checked ? '' : 's',
	implode( ', ', $site_labels ),
	implode( ', ', $network_labels )
);
