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

/*
 * --- no document still describes a release that has shipped past ---
 *
 * The version drifted in four places at once and nothing noticed, because the
 * only version assertion in the suite compares readme.txt's `Stable tag` against
 * the plugin header (tests/readme-spec.php). Both were correct. Prose was never
 * in scope, so ROADMAP, README, SECURITY and the matrix all went on calling a
 * released plugin a `0.1.0-dev` pre-release, and readme.txt's Upgrade Notice —
 * which WordPress shows on the update screen — still described the release
 * before the one being shipped.
 *
 * The banned set is derived from readme.txt's own Changelog headings rather than
 * hardcoded, so it maintains itself: the day 0.3.0 ships, 0.2.0 joins the
 * changelog and becomes a stale string everywhere else in the same commit.
 *
 * Scope is the status-claiming documents only. docs/ is reference material full
 * of third-party version numbers that will eventually collide with one of ours,
 * and a guard with false positives gets switched off.
 */
$root   = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/keel.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$readme = file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

preg_match( '/^ \* Version:\s*(\S+)/m', $plugin, $vm );
$version = isset( $vm[1] ) ? $vm[1] : '';
keel_assert( '' !== $version, 'The plugin header states a version.' );

// Everything from "== Changelog ==" on is history and is supposed to name old
// releases. Everything before it describes the plugin as it is now.
$changelog_at = strpos( $readme, '== Changelog ==' );
keel_assert( false !== $changelog_at, 'readme.txt has a Changelog section.' );

preg_match_all( '/^= ([0-9][^ =]*) =$/m', substr( $readme, $changelog_at ), $cm );
$released = array_values( array_diff( $cm[1], array( $version ) ) );

keel_assert( array() !== $released, 'The changelog records at least one earlier release to check against.' );
keel_assert( in_array( $version, $cm[1], true ), "The changelog has an entry for the current version ({$version})." );

/*
 * And it has to be the NEWEST entry, not merely present somewhere.
 *
 * The assertion above passes if the version appears anywhere in the changelog, so
 * a `= 0.3.0 =` heading written above the current one — a release drafted but
 * never shipped, or a version bumped in the changelog and nowhere else — reads as
 * fine. The sibling plugin drifted exactly that way: a version in its CHANGELOG
 * that was never tagged.
 */
keel_assert(
	isset( $cm[1][0] ) && $version === $cm[1][0],
	'The newest changelog entry is the current version (' . $version . '), not ' . ( isset( $cm[1][0] ) ? $cm[1][0] : 'nothing' ) . '.'
);

$status_docs = array( 'README.md', 'ROADMAP.md', 'SECURITY.md', 'CONTRIBUTING.md', 'TODO.md' );

foreach ( $status_docs as $doc ) {
	if ( ! is_file( $root . '/' . $doc ) ) {
		continue;
	}

	$text = file_get_contents( $root . '/' . $doc ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	foreach ( $released as $old ) {
		keel_assert(
			false === strpos( $text, $old ),
			"{$doc} still refers to {$old}; the current version is {$version}."
		);
	}
}

// readme.txt above the changelog, same rule.
foreach ( $released as $old ) {
	keel_assert(
		false === strpos( substr( $readme, 0, $changelog_at ), $old ),
		"readme.txt refers to {$old} outside the changelog; the current version is {$version}."
	);
}

/*
 * The Upgrade Notice is the one users are actually shown, on the plugins screen,
 * for the version they are being offered. Naming any other release means the
 * notice for this one does not exist.
 */
$notice_at = strpos( $readme, '== Upgrade Notice ==' );
keel_assert( false !== $notice_at, 'readme.txt has an Upgrade Notice section.' );

preg_match( '/^= (\S+) =$/m', substr( $readme, $notice_at ), $nm );
keel_assert(
	isset( $nm[1] ) && $version === $nm[1],
	'The Upgrade Notice describes the current version (' . $version . '), not ' . ( isset( $nm[1] ) ? $nm[1] : 'nothing' ) . '.'
);

// The matrix names the version its Keel row was measured against. It is reference
// material rather than a status claim, so it is checked by name instead of by the
// blanket scan above.
$matrix_path = $root . '/docs/competitive-teardown-matrix.md';

if ( is_file( $matrix_path ) ) {
	$matrix = file_get_contents( $matrix_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	keel_assert(
		false !== strpos( $matrix, '**Keel** ' . $version ),
		"The teardown matrix does not name Keel {$version} in its own row; re-measure or re-label it."
	);
}

printf( "docs consistency: OK (%d shared item%s, %d superseded version%s)\n", count( $shared ), 1 === count( $shared ) ? '' : 's', count( $released ), 1 === count( $released ) ? '' : 's' );
