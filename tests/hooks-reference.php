<?php
/**
 * Keep docs/hooks.md in step with the hooks the code actually offers.
 *
 * Fifteen of Keel's filters went undocumented anywhere for several releases,
 * including the four security-header switches, because nothing tied a new
 * `apply_filters()` to a line of documentation. This reads every `keel_` hook
 * the plugin fires and fails if the reference is missing one, or still lists
 * one the code no longer fires.
 *
 * Run: php tests/hooks-reference.php
 *
 * @package keel
 */

function keel_hooks_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$root  = dirname( __DIR__ );
$fired = array();

foreach ( array_merge( glob( $root . '/includes/*.php' ), array( $root . '/keel.php' ) ) as $file ) {
	$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo file in a CLI test, not a remote request.

	// The name can sit on the line after the opening parenthesis.
	if ( preg_match_all( "/\b(?:apply_filters|do_action)\(\s*'(keel_[a-z0-9_]+)'/", $source, $matches ) ) {
		foreach ( $matches[1] as $hook ) {
			$fired[ $hook ] = true;
		}
	}
}

keel_hooks_assert( count( $fired ) >= 30, 'Found the plugin\'s hooks — otherwise this test is watching nothing.' );

$reference = (string) file_get_contents( $root . '/docs/hooks.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo file in a CLI test, not a remote request.

// Rows name a hook as the first cell: "| `keel_x` | filter |".
preg_match_all( '/^\|\s*`(keel_[a-z0-9_]+)`\s*\|\s*(?:filter|action)\s*\|/m', $reference, $rows );
$documented = array_fill_keys( $rows[1], true );

foreach ( array_keys( $fired ) as $hook ) {
	keel_hooks_assert( isset( $documented[ $hook ] ), "`{$hook}` is fired in the code but has no row in docs/hooks.md." );
}

foreach ( array_keys( $documented ) as $hook ) {
	keel_hooks_assert( isset( $fired[ $hook ] ), "docs/hooks.md lists `{$hook}`, which the code no longer fires." );
}

preg_match( '/Keel has (\d+) filters and (\d+)\s+actions/', $reference, $counts );
$actions = (int) preg_match_all( '/^\|\s*`keel_[a-z0-9_]+`\s*\|\s*action\s*\|/m', $reference );
keel_hooks_assert( ! empty( $counts ), 'docs/hooks.md states how many filters and actions there are.' );
keel_hooks_assert( count( $documented ) - $actions === (int) $counts[1] && $actions === (int) $counts[2], 'The filter and action counts in docs/hooks.md match its rows.' );

echo 'Hooks reference: ' . count( $fired ) . " hooks documented.\n";
