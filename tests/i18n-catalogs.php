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
 * `--exclude=build` is not optional. `bin/build-zip.sh` assembles the plugin
 * into `build/`, so a scan of the working tree finds every string twice and
 * keeps the ones from the last build after they have been deleted from source.
 * That happened: removed copy sat in the template as untranslatable entries no
 * translator could ever see rendered.
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
	foreach ( keel_po_entries( $path ) as $entry ) {
		foreach ( array( 'msgid', 'msgid_plural' ) as $field ) {
			if ( isset( $entry[ $field ] ) && '' !== $entry[ $field ] ) {
				$out[] = $entry[ $field ];
			}
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

		$patterns = array(
			// Singular forms: the call ends at the text domain.
			"/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'keel-defaults'\s*\)/",

			/*
			 * Plural forms carry two translatable strings before the count, and
			 * neither was being read. _n() was invisible to this scan in both
			 * directions — a missing plural would not have failed, and once the
			 * reverse check existed the template's copy read as obsolete.
			 */
			"/\b_n\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,/",
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $code, $m ) ) {
				continue;
			}

			// Group 1 is always a translatable string; group 2 exists for plurals.
			$found = isset( $m[2] ) ? array_merge( $m[1], $m[2] ) : $m[1];

			foreach ( $found as $s ) {
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

/**
 * Msgids `make-pot` lifts from the plugin header rather than from a gettext call.
 *
 * The Plugin Name, Plugin URI, Description and Author are translatable and
 * belong in the template, but no `__()` call produces them, so a scan of the
 * source will never find them and they would read as obsolete. They are
 * annotated `#. <Field> of the plugin`, which is what identifies them here —
 * a count would go stale the moment a header field is added or dropped.
 *
 * @param string $path Path to the .pot file.
 * @return string[] Header-derived msgids.
 */
function keel_pot_header_msgids( $path ) {
	// Reading a catalog off disk in a CLI test; wp_remote_get() is for HTTP.
	$lines  = explode( "\n", (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ids    = array();
	$header = false;

	foreach ( $lines as $line ) {
		if ( 1 === preg_match( '/^#\. .+ of the plugin\s*$/', $line ) ) {
			$header = true;
			continue;
		}

		if ( $header && 1 === preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
			$ids[]  = keel_msgid_to_source( $m[1] );
			$header = false;
		}
	}

	return $ids;
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
	"keel-defaults.pot names the current version ({$version}). Run: wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults --exclude=build"
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
		. '`wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults --exclude=build`. First: "'
		. ( isset( $missing[0] ) ? substr( $missing[0], 0, 70 ) : '' ) . '"'
);

/*
 * --- and the template carries nothing the source no longer says ---
 *
 * The check above is one-directional: it fails when source has a string the
 * template lacks, and says nothing about the reverse. That is the direction the
 * contamination actually travels. `make-pot` scans the working tree, and
 * `bin/build-zip.sh` assembles the plugin into `build/`, so a regeneration after
 * a build finds every string twice and keeps the build's copies after they have
 * been deleted from source. Guidance alone does not stop it — an instruction is
 * not a check, and the instruction is exactly what somebody skips.
 *
 * Obsolete entries are not harmless. They reach translate.wordpress.org, where
 * people spend real effort translating copy that can never render.
 */
$obsolete = array_values( array_diff( $pot_ids, $source, keel_pot_header_msgids( $pot_path ) ) );

keel_assert(
	array() === $obsolete,
	count( $obsolete ) . ' template string(s) no longer exist in the source. This is what a regeneration '
		. 'that scanned build/ leaves behind — rebuild with '
		. '`wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults --exclude=build`. First: "'
		. ( isset( $obsolete[0] ) ? substr( $obsolete[0], 0, 70 ) : '' ) . '"'
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

/*
 * --- the header carries the permanent slug ---
 *
 * `--slug` is documented in every regeneration message above and in the .po
 * headers, and nothing asserted it, so dropping it shipped a support URL
 * pointing at a plugin directory entry that does not exist. This is the second
 * documented flag to go missing from that command; `--exclude=build` was the
 * first. A flag that only lives in prose gets left off.
 */
$expected_bugs_to = 'https://wordpress.org/support/plugin/keel-defaults';

keel_assert(
	false !== strpos( $pot, 'Report-Msgid-Bugs-To: ' . $expected_bugs_to ),
	'keel-defaults.pot reports bugs to the permanent slug. Regenerate with the documented command, '
		. '--slug included: wp i18n make-pot . languages/keel-defaults.pot --slug=keel-defaults --exclude=build'
);

/*
 * --- the source language is US English ---
 *
 * The strings in the code are en_US, and each regional spelling belongs in that
 * locale's catalog. The settings screen's help tab said "colour-coded" while the
 * field it describes said "color-coded", so an en_US site read both spellings on
 * one screen and en_CA had nothing to translate. Only translatable strings are
 * checked; comments may spell as their author does.
 */
$regional = '/\b(colour|behaviour|favour|honour|centre|catalogue|licence|recognis|organis|customis|initialis|prioritis|summaris|analys(?:e|ed|es|ing)\b)/i';
foreach ( $source as $string ) {
	if ( preg_match( $regional, $string, $m ) ) {
		keel_assert( false, 'A translatable string uses the regional spelling "' . $m[1] . '"; write it in US English and put the regional spelling in its locale catalog: "' . substr( $string, 0, 90 ) . '"' );
	}
}

/**
 * Translated entries in a PO file, msgid => msgstr.
 *
 * Plural entries are keyed by the singular and carry their forms joined with a
 * NUL, the way a compiled .mo stores them, so the two can be compared directly.
 * Untranslated entries are left out: an empty msgstr falls back to the source.
 *
 * @param string $path File path.
 * @return array<string, string>
 */
function keel_po_translations( $path ) {
	$out = array();
	foreach ( keel_po_entries( $path ) as $entry ) {
		if ( ! isset( $entry['msgid'] ) || '' === $entry['msgid'] ) {
			continue;
		}
		$forms = array();
		foreach ( $entry as $field => $value ) {
			if ( preg_match( '/^msgstr(?:\[(\d+)\])?$/', $field, $match ) ) {
				$forms[ isset( $match[1] ) ? (int) $match[1] : 0 ] = $value;
			}
		}
		ksort( $forms );
		if ( array() !== array_filter( $forms, 'strlen' ) ) {
			$out[ keel_msgid_to_source( $entry['msgid'] ) ] = keel_msgid_to_source( implode( "\0", $forms ) );
		}
	}
	return $out;
}

/**
 * Read PO fields with their quoted continuation lines, keeping escapes raw.
 *
 * @param string $path Catalog path.
 * @return array[]
 */
function keel_po_entries( $path ) {
	$entries = array();
	$entry   = array();
	$field   = null;
	foreach ( file( $path ) as $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $match ) ) {
			// A new entry need not have a blank line before it.
			if ( ( 'msgid' === $match[1] && isset( $entry['msgid'] ) ) || ( 'msgctxt' === $match[1] && isset( $entry['msgid'] ) ) ) {
				$entries[] = $entry;
				$entry     = array();
			}
			$field           = $match[1];
			$entry[ $field ] = $match[2];
		} elseif ( null !== $field && preg_match( '/^"(.*)"$/', $line, $match ) ) {
			$entry[ $field ] .= $match[1];
		} else {
			$field = null;
			if ( '' === trim( $line ) && array() !== $entry ) {
				$entries[] = $entry;
				$entry     = array();
			}
		}
	}
	if ( array() !== $entry ) {
		$entries[] = $entry;
	}
	return $entries;
}

/**
 * Translated entries in a compiled .mo file, msgid => msgstr.
 *
 * @param string $path File path.
 * @return array<string, string>|false False for malformed catalogs.
 */
function keel_mo_translations( $path ) {
	$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$size = strlen( $data );
	if ( $size < 28 ) {
		return false;
	}
	$magic = substr( $data, 0, 4 );
	if ( "\xde\x12\x04\x95" === $magic ) {
		$endian = 'V';
	} elseif ( "\x95\x04\x12\xde" === $magic ) {
		$endian = 'N';
	} else {
		return false;
	}
	$head = unpack( $endian . 'magic/' . $endian . 'revision/' . $endian . 'count/' . $endian . 'ids/' . $endian . 'strs', substr( $data, 0, 20 ) );
	foreach ( array( 'ids', 'strs' ) as $table ) {
		if ( $head[ $table ] > $size || $head['count'] > intdiv( $size - $head[ $table ], 8 ) ) {
			return false;
		}
	}
	$out = array();
	for ( $i = 0; $i < $head['count']; $i++ ) {
		$strings = array();
		foreach ( array( 'ids', 'strs' ) as $table ) {
			$item = unpack( $endian . 'len/' . $endian . 'offset', substr( $data, $head[ $table ] + 8 * $i, 8 ) );
			// Length excludes the required trailing NUL. Subtraction avoids overflow.
			if ( $item['offset'] >= $size || $item['len'] >= $size - $item['offset'] || "\0" !== $data[ $item['offset'] + $item['len'] ] ) {
				return false;
			}
			$strings[ $table ] = substr( $data, $item['offset'], $item['len'] );
		}
		// Skip metadata; plural keys use their singular source form.
		if ( '' !== $strings['ids'] ) {
			$out[ explode( "\0", $strings['ids'] )[0] ] = $strings['strs'];
		}
	}

	return $out;
}

// Parser regressions use scratch files, never the shipped catalogs.
$fixture = tempnam( sys_get_temp_dir(), 'keel-i18n-' );
try {
	$continued = <<<'PO'
msgid ""
msgstr ""
"Language: en_CA\n"

msgid ""
"Color "
"label"
msgstr ""
"Colour "
"label"

msgid "One "
"color"
msgid_plural "Many "
"colors"
msgstr[0] "One "
"colour"
msgstr[1] ""
"Many colours"
PO;
	file_put_contents( $fixture, $continued );
	keel_assert( array( 'Color label', 'One color', 'Many colors' ) === keel_msgids( $fixture ), 'PO msgids include continuations and exclude the metadata header.' );
	keel_assert(
		array(
			'Color label' => 'Colour label',
			'One color'   => "One colour\0Many colours",
		) === keel_po_translations( $fixture ),
		'PO translations join continued singular and plural fields.'
	);

	foreach ( array( 'V', 'N' ) as $endian ) {
		// One entry: header (28 bytes), two descriptors, then NUL-terminated strings.
		$valid = pack( $endian . '*', 0x950412de, 0, 1, 28, 36, 0, 0, 5, 44, 6, 50 ) . "Color\0Colour\0";
		file_put_contents( $fixture, $valid );
		keel_assert( array( 'Color' => 'Colour' ) === keel_mo_translations( $fixture ), "MO reads {$endian}-endian catalogs." );
		$invalid = array(
			'magic'              => 'BAD!' . substr( $valid, 4 ),
			'truncated header'   => substr( $valid, 0, 27 ),
			'truncated tables'   => substr( $valid, 0, 40 ),
			'count overflow'     => substr_replace( $valid, pack( $endian, 0xffffffff ), 8, 4 ),
			'table offset'       => substr_replace( $valid, pack( $endian, 0xffffffff ), 12, 4 ),
			'string offset'      => substr_replace( $valid, pack( $endian, 0xffffffff ), 32, 4 ),
			'string length'      => substr_replace( $valid, pack( $endian, 0xffffffff ), 36, 4 ),
			'missing terminator' => substr( $valid, 0, -1 ),
		);
		file_put_contents( $fixture, pack( $endian . '*', 0x950412de, 0, 0, 28, 28, 0, 0 ) );
		keel_assert( array() === keel_mo_translations( $fixture ), "MO accepts a valid empty {$endian}-endian catalog." );
		foreach ( $invalid as $case => $bytes ) {
			file_put_contents( $fixture, $bytes );
			keel_assert( false === keel_mo_translations( $fixture ), "MO rejects {$endian}-endian {$case}." );
		}
	}
} finally {
	unlink( $fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove the CLI scratch fixture; WordPress is not loaded.
}

// --- every compiled catalog says what its source catalog says ---
foreach ( glob( $root . '/languages/*.po' ) as $po ) {
	$mo = preg_replace( '/\.po$/', '.mo', $po );

	if ( ! is_file( $mo ) ) {
		continue;
	}

	$compiled = keel_mo_translations( $mo );
	keel_assert(
		false !== $compiled && keel_po_translations( $po ) == $compiled, // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- key order differs; contents must not.
		basename( $mo ) . ' is out of date with ' . basename( $po ) . '. WordPress reads only the .mo, so edits to the .po do nothing until it is recompiled. Run: wp i18n make-mo languages/'
	);
}

/*
 * --- en_CA carries every Canadian spelling ---
 *
 * The source is US English, so each string that spells a word the Canadian way
 * differently needs an en_CA entry or that locale reads the US spelling. Only
 * five of the strings that needed one had it.
 */
/**
 * The prose of a string: markup and <code> spans removed, since `color` in an
 * attribute or a function name is not a word to respell.
 *
 * @param string $text String with possible markup.
 * @return string
 */
function keel_prose( $text ) {
	return (string) preg_replace( array( '#<code>.*?</code>#', '#<[^>]+>#' ), '', $text );
}

$en_ca = $root . '/languages/keel-defaults-en_CA.po';
if ( is_file( $en_ca ) ) {
	$ca_strings = keel_po_translations( $en_ca );
	$us_words   = '/\b(behavior|color|labeled|mislabeled|honor|recogniz)/i';

	foreach ( $source as $string ) {
		if ( ! preg_match( $us_words, keel_prose( $string ), $m ) ) {
			continue;
		}

		keel_assert(
			isset( $ca_strings[ $string ] ) && ! preg_match( $us_words, keel_prose( explode( "\0", $ca_strings[ $string ] )[0] ) ),
			"keel-defaults-en_CA.po has no Canadian spelling for \"{$m[1]}\" in: \"" . substr( $string, 0, 90 ) . '"'
		);
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "i18n catalogs: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'i18n catalogs: OK (' . count( $source ) . ' strings, ' . count( glob( $root . '/languages/*.po' ) ) . " catalog(s))\n" );
