<?php
/**
 * Drift guard for the translation template and catalogs.
 *
 * The `.pot` had gone stale without anything noticing: 68 strings in the code
 * were missing from it, 43 strings in it no longer existed, it still claimed
 * version 0.1.0-dev, and it named the wrong licence. Much of that drift was
 * self-inflicted on the day it was found — a Title Case sweep and a rule against
 * ampersands rewrote dozens of labels, and every rewrite is a new msgid and a
 * dead old one.
 *
 * The `en_CA` catalog was worse. Both of its two entries had msgids that no
 * longer existed, so it shipped a compiled `.mo` that translated nothing at all
 * while looking like translation support.
 *
 * This does not regenerate anything. It compares what is committed against what
 * the code currently says, and fails with the command to run.
 *
 * Run: php tests/i18n-catalogs.php
 *
 * @package keel
 */

$root = dirname( __DIR__ );
$fail = 0;

/**
 * Assert helper.
 *
 * Collects rather than exiting, so one run names every drifted string instead of
 * the first.
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
 * Every msgid in a PO/POT file.
 *
 * @param string $path File path.
 * @return string[]
 */
function keel_msgids( $path ) {
	$out = array();

	foreach ( file( $path ) as $line ) {
		if ( preg_match( '/^msgid "(.+)"$/', rtrim( $line, "\r\n" ), $m ) ) {
			$out[] = $m[1];
		}
	}

	return $out;
}

/**
 * Translatable strings the code actually contains.
 *
 * Deliberately a source scan rather than a call to wp-cli: the test has to run
 * where `composer test` runs, and shelling out to a tool that may not be
 * installed would make this pass by being skipped — which is the failure mode
 * the whole file exists to prevent.
 *
 * Only single-quoted single-line literals are read. A string built by
 * concatenation or interpolation cannot be matched against a msgid anyway, and
 * the assertion below keeps those out.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function keel_source_strings( $root ) {
	$files = array_merge( array( $root . '/keel.php', $root . '/uninstall.php' ), glob( $root . '/includes/*.php' ) );
	$out   = array();

	foreach ( $files as $file ) {
		$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( preg_match_all( "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'keel-defaults'\s*\)/", $code, $m ) ) {
			foreach ( $m[1] as $s ) {
				// PHP single-quoted literals escape ' and \; the PO file holds the
				// literal characters. Comparing them raw reports every string with an
				// apostrophe as drifted, which is eight of them here.
				$out[] = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $s );
			}
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * Normalise a PO msgid to the way the same text appears in PHP source.
 *
 * PO escapes double quotes and backslashes; PHP single-quoted strings escape
 * only the single quote and the backslash. Comparing them raw reports every
 * string containing an apostrophe as drifted.
 *
 * @param string $id Raw msgid.
 * @return string
 */
function keel_msgid_to_source( $id ) {
	return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $id );
}

// --- the template exists and describes this version of the plugin ---
$pot_path = $root . '/languages/keel-defaults.pot';
keel_assert( is_file( $pot_path ), 'languages/keel-defaults.pot exists.' );

$pot = file_get_contents( $pot_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

preg_match( '/^ \* Version:\s*(\S+)/m', file_get_contents( $root . '/keel.php' ), $vm ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$version = isset( $vm[1] ) ? $vm[1] : '';

keel_assert( '' !== $version, 'The plugin header states a version.' );
keel_assert(
	false !== strpos( $pot, 'Project-Id-Version: Keel Defaults ' . $version ),
	"keel-defaults.pot names the current version ({$version}). Run: wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults"
);
keel_assert(
	false !== strpos( $pot, 'GPL-2.0-or-later' ),
	'keel-defaults.pot states the licence the plugin actually ships under. It said GPL-3.0-or-later for a week after the relicence.'
);

// --- every translatable string in the code is in the template ---
$pot_ids = array_map( 'keel_msgid_to_source', keel_msgids( $pot_path ) );
$source  = keel_source_strings( $root );

keel_assert( count( $source ) > 100, 'The source scan found the translatable strings (' . count( $source ) . ').' );

$missing = array_values( array_diff( $source, $pot_ids ) );

keel_assert(
	array() === $missing,
	count( $missing ) . ' translatable string(s) are not in keel.pot — regenerate it with '
		. '`wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults`. First: "'
		. ( isset( $missing[0] ) ? substr( $missing[0], 0, 70 ) : '' ) . '"'
);

/*
 * --- every catalog entry still matches a live string ---
 *
 * This is the assertion that would have caught en_CA. A translation whose msgid
 * no longer exists is not a partial translation; it is a file that does nothing,
 * and it looks identical to one that works.
 */
foreach ( glob( $root . '/languages/*.po' ) as $po ) {
	$name = basename( $po );

	foreach ( keel_msgids( $po ) as $id ) {
		keel_assert(
			in_array( keel_msgid_to_source( $id ), $pot_ids, true ),
			"{$name} translates a string that no longer exists, so that entry has no effect: \""
				. substr( keel_msgid_to_source( $id ), 0, 70 ) . '"'
		);
	}

	// A compiled catalog beside every source catalog, or the translation ships
	// as a .po WordPress never reads.
	keel_assert(
		is_file( preg_replace( '/\.po$/', '.mo', $po ) ),
		"{$name} has no compiled .mo beside it. Run: wp i18n make-mo languages/"
	);
}

// --- the text domain is one value, everywhere ---
foreach ( array_merge( array( $root . '/keel.php', $root . '/uninstall.php' ), glob( $root . '/includes/*.php' ) ) as $file ) {
	$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( preg_match_all( "/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\([^;]*?,\s*'([a-z0-9_-]+)'\s*\)/", $code, $m ) ) {
		foreach ( array_unique( $m[1] ) as $domain ) {
			keel_assert(
				'keel-defaults' === $domain,
				basename( $file ) . " uses the text domain '{$domain}'; this plugin's domain is 'keel-defaults'."
			);
		}
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "i18n catalogs: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'i18n catalogs: OK (' . count( $source ) . ' strings, ' . count( glob( $root . '/languages/*.po' ) ) . " catalog(s))\n" );
