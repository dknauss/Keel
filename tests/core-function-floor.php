<?php
/**
 * No core function newer than the declared floor is called unguarded.
 *
 * Keel declares `Requires at least: 6.4`. Calling a function core added later
 * is not a degraded check on those versions — it is a fatal error, a white
 * screen, on a site inside the supported range.
 *
 * That is not hypothetical. `wp_get_wp_version()` is 6.7+, and five unguarded
 * calls to it shipped to main and would have shipped in 0.6.0. The unit suite
 * could not catch it: every test file defines its own doubles, so the function
 * always exists there. The live backport matrix caught it on its first run,
 * and only because that run happened before the release rather than after.
 *
 * So the check is on the source, where the absence is visible. A call is
 * allowed when a function_exists() guard for the same name appears within a
 * few lines above it — which covers both the shim pattern and an inline guard.
 *
 * Add to the map whenever Keel adopts a core function newer than the floor.
 * The floor itself is read from the plugin header rather than repeated here,
 * so raising it is a one-line change that this check follows.
 *
 * Run: php tests/core-function-floor.php
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

// Core functions Keel calls that postdate some supported version, and the
// release that introduced each.
$gated = array(
	'wp_get_wp_version' => '6.7',
);

$header = (string) file_get_contents( $root . '/keel.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source, not a remote URL.
preg_match( '/Requires at least:\s*([0-9.]+)/', $header, $m );
$floor = isset( $m[1] ) ? $m[1] : '';

keel_assert( '' !== $floor, 'the plugin header declares a minimum WordPress version' );

$files = array_merge(
	array( $root . '/keel.php', $root . '/uninstall.php' ),
	glob( $root . '/includes/*.php' )
);

foreach ( $gated as $fn => $since ) {
	if ( '' !== $floor && version_compare( $floor, $since, '>=' ) ) {
		// The floor has caught up; the guard is no longer required.
		continue;
	}

	foreach ( $files as $file ) {
		if ( ! is_readable( $file ) ) {
			continue;
		}

		$lines = explode( "\n", (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source, not a remote URL.

		foreach ( $lines as $i => $line ) {
			// Skip the docblock and comment mentions that describe the problem.
			$trimmed = ltrim( $line );
			if ( '' === $trimmed || '*' === $trimmed[0] || 0 === strpos( $trimmed, '//' ) ) {
				continue;
			}

			if ( false === strpos( $line, $fn . '()' ) ) {
				continue;
			}

			$guarded = false;
			for ( $back = max( 0, $i - 4 ); $back <= $i; $back++ ) {
				if ( false !== strpos( $lines[ $back ], "function_exists( '" . $fn . "' )" ) ) {
					$guarded = true;
					break;
				}
			}

			keel_assert(
				$guarded,
				sprintf(
					'%s:%d calls %s() unguarded — core added it in %s and Keel supports %s, where that is a fatal error',
					basename( $file ),
					$i + 1,
					$fn,
					$since,
					$floor
				)
			);
		}
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "core function floor: OK\n";
