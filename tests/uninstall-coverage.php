<?php
/**
 * Drift guard for the uninstaller.
 *
 * uninstall.php names the things it deletes. That is correct today and rots
 * tomorrow: a default added next month writes a new option, nobody thinks about
 * deletion, and the plugin quietly starts leaving data behind. Nothing fails,
 * because there is nothing to fail — the leftover only shows up on a site that
 * deleted the plugin and expected it gone.
 *
 * So this reads the plugin's own source for every persistent-storage key it
 * writes, and asserts each one is covered by the uninstaller. Adding a key
 * without adding its removal fails here, naming the key and the file.
 *
 * Pattern taken from the Pixel Managed Platform's tests/uninstall-coverage.php,
 * which does the same job for a prefix-based sweep.
 *
 * Run: php tests/uninstall-coverage.php
 *
 * @package keel
 */

$root      = dirname( __DIR__ );
$uninstall = file_get_contents( $root . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$fail      = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_uninstall_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

keel_uninstall_assert( '' !== $uninstall, 'uninstall.php exists.' );
keel_uninstall_assert(
	false !== strpos( $uninstall, "defined( 'WP_UNINSTALL_PLUGIN' )" ),
	'uninstall.php refuses to run outside WordPress\'s uninstall path.'
);

// Multisite: options are per site, so a network install needs every site
// cleared, not just the one that happened to be current.
keel_uninstall_assert(
	false !== strpos( $uninstall, 'is_multisite()' ) && false !== strpos( $uninstall, 'switch_to_blog' ),
	'uninstall.php clears every site on a network, not only the current one.'
);

// --- what the plugin actually writes ---
$sources = array_merge(
	array( $root . '/keel.php' ),
	glob( $root . '/includes/*.php' )
);

$written = array();

foreach ( $sources as $file ) {
	$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// Options and transients written with a literal key.
	if ( preg_match_all( "/(?:add_option|update_option|set_transient)\(\s*'([a-z0-9_]+)'/i", $code, $m ) ) {
		foreach ( $m[1] as $key ) {
			$written[ $key ] = basename( $file );
		}
	}

	// User meta written with a literal key.
	if ( preg_match_all( "/(?:add_user_meta|update_user_meta)\(\s*[^,]+,\s*'([a-z0-9_]+)'/i", $code, $m ) ) {
		foreach ( $m[1] as $key ) {
			$written[ $key ] = basename( $file );
		}
	}

	// Keys built from a constant or a prefix variable — the shape the HIBP cache
	// uses — caught by their literal prefix rather than the whole key.
	if ( preg_match_all( "/'(keel_[a-z0-9_]*)'\s*\.\s*\\\$/i", $code, $m ) ) {
		foreach ( $m[1] as $key ) {
			$written[ $key ] = basename( $file );
		}
	}
}

// The settings option is named through a constant, so the scan above cannot see
// its value. Assert the constant's value instead of hard-coding it twice.
preg_match( "/const KEEL_DEFAULTS_OPTION\s*=\s*'([a-z0-9_]+)'/i", file_get_contents( $root . '/keel.php' ), $om ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( isset( $om[1] ) ) {
	$written[ $om[1] ] = 'keel.php (KEEL_DEFAULTS_OPTION)';
}

keel_uninstall_assert( ! empty( $written ), 'The scan found at least one storage key (a scan finding nothing would pass everything).' );

foreach ( $written as $key => $where ) {
	keel_uninstall_assert(
		false !== strpos( $uninstall, $key ),
		"Storage key '{$key}' (written in {$where}) is not removed by uninstall.php."
	);
}

// Every key this plugin writes should carry its own prefix. One that does not
// is not just untidy — it is a key the next person auditing "what does Keel
// leave behind?" will not think to look for.
foreach ( array_keys( $written ) as $key ) {
	keel_uninstall_assert(
		0 === strpos( $key, 'keel_' ),
		"Storage key '{$key}' does not start with keel_, so it is invisible to a prefix audit."
	);
}

if ( $fail > 0 ) {
	fwrite( STDERR, "uninstall coverage: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'uninstall coverage: OK (' . count( $written ) . " keys checked)\n" );
