<?php
/**
 * Notices that name a setting link to it.
 *
 * "Turn off Non-Production Email under Settings → Keel" is a correct sentence
 * and a small chore: the reader has to find the screen, then find the row among
 * thirty-nine. The screen already knows where every setting is, so the notice
 * should hand them the place rather than the directions.
 *
 * This pins the contract rather than the wording — an anchor that exists, a link
 * that points at it, and every notice using the same helper so the pattern
 * cannot drift into three spellings of the same idea.
 *
 * Run: php tests/setting-links.php
 *
 * @package keel
 */

$root = dirname( __DIR__ );
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

$settings_src = file_get_contents( $root . '/includes/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// --- the anchor exists, and is derived from the schema key ---

keel_assert(
	false !== strpos( $settings_src, 'keel-setting-' ),
	'The settings screen emits an anchor per setting.'
);

keel_assert(
	1 === preg_match( '/function keel_defaults_setting_url\(/', $settings_src ),
	'There is one helper that builds the link.'
);

keel_assert(
	1 === preg_match( '/function keel_defaults_setting_link\(/', $settings_src ),
	'And one that builds the anchor tag, so notices do not each invent their own.'
);

/*
 * --- every user-facing string naming the screen links to it ---
 *
 * The point of a pattern is that it is one pattern. A notice that spells the
 * destination out in prose while its neighbour links to it is the state this
 * started in. Scanning translatable strings rather than whole files is what
 * makes this a real guard: a file can hold one linked notice and three
 * unlinked ones and still satisfy a file-level check.
 */
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	/*
	 * Drop the link calls before scanning. Their second argument names the
	 * screen on purpose — that is the link text, and the whole point of the
	 * change. What is left is prose.
	 */
	$prose = preg_replace(
		"/keel_defaults_setting_link\(\s*'[a-z0-9_]*'\s*,\s*(?:esc_html__|esc_attr__|__)\(\s*'(?:[^'\\\\]|\\\\.)*'[^)]*\)/",
		'',
		$src
	);

	// Translatable strings only — docblocks and code comments are not read by anyone using the plugin.
	preg_match_all( "/(?:esc_html__|esc_attr__|__)\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $prose, $strings );

	foreach ( $strings[1] as $string ) {
		if ( ! preg_match( '/Settings\s*(?:→|&rarr;)\s*Keel/u', $string ) ) {
			continue;
		}

		/*
		 * Exception, deliberate: "Network Admin → Settings → Keel Defaults" is a
		 * different screen on a different site, and the reader being told about
		 * it is usually a site admin who cannot open it. A link there would 403
		 * for the person most likely to click it, so it stays as directions.
		 */
		if ( preg_match( '/Network Admin\s*(?:→|&rarr;)/u', $string ) ) {
			continue;
		}

		keel_assert(
			false,
			basename( $file ) . ' names the settings screen in prose instead of linking to it: "'
				. mb_substr( $string, 0, 60 ) . '"'
		);
	}
}

/*
 * --- links point at settings that exist ---
 *
 * An anchor built from a key the schema does not have lands the reader on the
 * screen with nothing highlighted, which is worse than the prose it replaced
 * because it looks like it worked.
 */
$schema_src = file_get_contents( $root . '/includes/schema.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$linked     = array();

foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	$src = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	preg_match_all( "/keel_defaults_setting_(?:link|url|anchor)\(\s*'([a-z0-9_]+)'/", $src, $keys );
	foreach ( $keys[1] as $key ) {
		$linked[ $key ] = basename( $file );
	}
}

keel_assert(
	! empty( $linked ),
	'At least one notice links to a specific setting.'
);

foreach ( $linked as $key => $where ) {
	keel_assert(
		false !== strpos( $schema_src, "'" . $key . "'" ),
		$where . " links to '" . $key . "', which is not a setting in the schema."
	);
}

// --- the mail notice, which is where this was noticed ---

$email_src = file_get_contents( $root . '/includes/email.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

keel_assert(
	false !== strpos( $email_src, 'keel_defaults_setting_link' ),
	'The non-production mail notice links to the setting it names.'
);

keel_assert(
	false !== strpos( $email_src, 'suppress_nonproduction_mail' ),
	'And links to that setting specifically, not to the screen.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "setting links: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

fwrite( STDOUT, "setting links: OK\n" );
