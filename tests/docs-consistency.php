<?php
/**
 * Keep TODO.md and ROADMAP.md from disagreeing about the same item.
 *
 * Both files track work, in different granularity — ROADMAP by release, TODO by
 * task — and several items appear in both under the same bolded title. Nothing
 * connected them, so `Reference doc coverage` and `Schema-key reconcile` sat
 * ticked in one file and open in the other for a day after the work merged.
 *
 * That is the same failure the feature matrix documents about itself: an entry
 * recording "X is not done yet" is never revisited when the work closing it
 * lands, because whoever finished the work was reading a different file.
 *
 * The check is deliberately narrow. It does not require the two files to cover
 * the same items — ROADMAP holds long-range items TODO never mentions, and TODO
 * holds day-to-day work that never reaches ROADMAP. It only fails when a title
 * appears in both and they disagree about whether it is finished.
 *
 * Run: php tests/docs-consistency.php
 *
 * @package keel
 */

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Reduce a checklist line to a comparable title.
 *
 * Entries are written inconsistently across the two files — bolded or not,
 * followed by an em-dash clause, a parenthetical, or a full stop. Only the
 * leading name is stable enough to match on, so everything after the first of
 * those separators is dropped, along with markdown emphasis and code ticks.
 *
 * @param string $line Text after the checkbox.
 * @return string Normalized title, or '' when nothing usable remains.
 */
function keel_checklist_title( $line ) {
	$title = (string) $line;

	// Cut at the first separator that starts a description.
	$title = preg_split( '/\s+[—–-]\s+|\s*\(|\.\s|:\s/u', $title )[0];

	$title = str_replace( array( '**', '`', '~~' ), '', $title );
	$title = strtolower( trim( $title, " \t\n.,:;" ) );

	// Too short to be distinctive; matching on it would invent agreements.
	return ( strlen( $title ) >= 8 ) ? $title : '';
}

/**
 * Map checklist titles to their done state.
 *
 * @param string $markdown File contents.
 * @return array<string, bool> Title => done.
 */
function keel_checklist_state( $markdown ) {
	$state = array();

	// Bolded titles and plain ones both count.
	//
	// The first version of this only matched `**Bolded**` entries, and passed
	// while TODO.md carried "Site Health surface" as open — twice, once ticked
	// and once not — against a ROADMAP that had it closed and a codebase that
	// had shipped it. A guard that reports success on the case it was built for
	// is worse than no guard, because it reads as verified.
	if ( ! preg_match_all( '/^\s*-\s\[( |x|~)\]\s(.+)$/mu', $markdown, $matches, PREG_SET_ORDER ) ) {
		return $state;
	}

	foreach ( $matches as $match ) {
		$title = keel_checklist_title( $match[2] );

		if ( '' === $title ) {
			continue;
		}

		// `[~]` means partly done. Treated as not finished, which is the safe
		// reading: it never lets one file claim completion the other denies.
		$done = ( 'x' === $match[1] );

		// A title appearing twice in one file is done only if every copy is.
		$state[ $title ] = isset( $state[ $title ] ) ? ( $state[ $title ] && $done ) : $done;
	}

	return $state;
}

$root    = dirname( __DIR__ );
$todo    = keel_checklist_state( (string) file_get_contents( $root . '/TODO.md' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo file in a CLI test, not a remote request.
$roadmap = keel_checklist_state( (string) file_get_contents( $root . '/ROADMAP.md' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a repo file in a CLI test, not a remote request.

keel_assert( ! empty( $todo ), 'TODO.md parsed at least one checklist item.' );
keel_assert( ! empty( $roadmap ), 'ROADMAP.md parsed at least one checklist item.' );

$shared = array_intersect_key( $todo, $roadmap );
keel_assert( ! empty( $shared ), 'At least one item appears in both files — otherwise this test is watching nothing.' );

foreach ( $shared as $title => $todo_done ) {
	keel_assert(
		$todo_done === $roadmap[ $title ],
		sprintf(
			"'%s' is %s in TODO.md but %s in ROADMAP.md — tick it in both, or reword one so they are not claiming to be the same item.",
			$title,
			$todo_done ? 'done' : 'open',
			$roadmap[ $title ] ? 'done' : 'open'
		)
	);
}

printf( "docs consistency: OK (%d shared item%s)\n", count( $shared ), 1 === count( $shared ) ? '' : 's' );
