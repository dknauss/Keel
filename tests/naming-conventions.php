<?php
/**
 * Conformance guard for the plugin's own naming and structural conventions.
 *
 * Keel's readability rests on there being one shape for everything: one prefix
 * for functions, one guard at the top of every include, one helper for reading a
 * toggle. None of that is enforced by PHP, and none of it survives on discipline
 * alone — every deviation arrives as a locally sensible choice in an unrelated
 * change, and by the time there are five of them the convention is gone and
 * nobody can say when it went.
 *
 * A review found three. This is what stops the fourth.
 *
 * Run: php tests/naming-conventions.php
 *
 * @package keel
 */

$root    = dirname( __DIR__ );
$sources = array_merge( array( $root . '/keel.php', $root . '/uninstall.php' ), glob( $root . '/includes/*.php' ) );
$fail    = 0;

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

$code = array();
foreach ( $sources as $file ) {
	$code[ $file ] = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
$all = implode( "\n", $code );

// A scan that finds nothing would pass everything.
preg_match_all( '/^function (keel[a-z0-9_]*)\(/m', $all, $m );
$functions = $m[1];
keel_assert( count( $functions ) > 50, 'The scan found the plugin\'s functions (' . count( $functions ) . ').' );

/*
 * --- function prefixes ---
 *
 * The rule, which was implicit until this test made it explicit:
 *
 *   1. keel_defaults_*  — everything, by default.
 *   2. keel_hibp_*      — the breach-lookup subsystem, whose helpers are only
 *                         meaningful together and read better under one prefix.
 *   3. A function whose entire job is to expose a filterable value is named
 *      after that filter, so keel_environments() applies 'keel_environments'.
 *      The name is the hook; giving it a second, different name would mean the
 *      thing a site filters and the thing that reads it look unrelated.
 *
 * Anything else is a deviation. keel_current_environment() was one — it matched
 * no filter and belonged to no subsystem, while its neighbour in the same file
 * used the standard prefix.
 */
foreach ( $functions as $fn ) {
	if ( 0 === strpos( $fn, 'keel_defaults_' ) || 0 === strpos( $fn, 'keel_hibp_' ) ) {
		continue;
	}

	// Rule 3: named after a filter it actually applies.
	$named_for_its_filter = false !== strpos( $all, "apply_filters( '" . $fn . "'" );

	keel_assert(
		$named_for_its_filter,
		"Function {$fn}() does not follow the naming convention: use the keel_defaults_ prefix, "
			. 'or name it after a filter of the same name that it applies.'
	);
}

// The converse, so the rule above cannot pass by being vacuous: the two
// filter-named functions really do apply their own filter.
foreach ( array( 'keel_environments', 'keel_password_is_pwned' ) as $fn ) {
	keel_assert(
		in_array( $fn, $functions, true ) && false !== strpos( $all, "apply_filters( '" . $fn . "'" ),
		"{$fn}() exists and applies the filter it is named for."
	);
}

// --- every include refuses to run outside WordPress ---
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	keel_assert(
		false !== strpos( $code[ $file ], "defined( 'ABSPATH' ) || exit;" ),
		basename( $file ) . ' guards against direct access.'
	);
}

// --- every function is documented ---
foreach ( $sources as $file ) {
	$lines = explode( "\n", $code[ $file ] );
	foreach ( $lines as $i => $line ) {
		if ( 0 !== strpos( $line, 'function keel' ) ) {
			continue;
		}
		$prev = ( $i > 0 ) ? trim( $lines[ $i - 1 ] ) : '';
		keel_assert(
			'*/' === substr( $prev, -2 ),
			basename( $file ) . ':' . ( $i + 1 ) . ' — ' . trim( $line ) . ' has no docblock.'
		);
	}
}

/*
 * --- one way to read a toggle ---
 *
 * keel_defaults_enabled() exists and is used throughout. Two callers in
 * site-health.php had re-implemented it as 'yes' === keel_defaults_get( … ),
 * which is not wrong, just a second spelling of the same idea — and a second
 * spelling is how a stored value's representation ends up asserted in places
 * that would not be updated if it ever changed.
 *
 * The helper's own definition is the one legitimate occurrence.
 */
foreach ( $code as $file => $src ) {
	if ( 'schema.php' === basename( $file ) ) {
		continue; // Defines keel_defaults_enabled().
	}

	keel_assert(
		! preg_match( "/'yes'\s*===\s*keel_defaults_get\(/", $src )
			&& ! preg_match( "/keel_defaults_get\(\s*'[a-z_]+'\s*\)\s*===\s*'yes'/", $src ),
		basename( $file ) . ' reads toggles through keel_defaults_enabled(), not by comparing to \'yes\'.'
	);
}

// And the helper really is what that convention points at.
keel_assert(
	false !== strpos( $code[ $root . '/includes/schema.php' ], 'function keel_defaults_enabled' ),
	'keel_defaults_enabled() is defined in schema.php.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "naming conventions: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'naming conventions: OK (' . count( $functions ) . " functions checked)\n" );
