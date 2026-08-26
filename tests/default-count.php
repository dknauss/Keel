<?php
/**
 * Keep every prose statement of "how many defaults" in step with the schema.
 *
 * This is the drift that actually happened. `suppress_nonproduction_mail` was
 * merged, the schema went to 38, and README.md, readme.txt, TODO.md and
 * ROADMAP.md went on saying 37 — one of them *edited* after the feature landed,
 * by somebody reading a neighbouring paragraph rather than the array. Nothing
 * caught it because nothing was looking: `tests/docs-consistency.php` only
 * reconciles two checklists against each other, and `tests/readme-spec.php`
 * pins the header fields Review parses, not the body copy.
 *
 * A count in prose is a claim about the code, so it belongs where the other
 * claims about the code are tested. The check derives every number from
 * `keel_defaults_schema()` and never from another document, so the documents
 * cannot agree with each other and all be wrong together — which is exactly
 * the shape the four stale files were in.
 *
 * Two directions of failure, both covered:
 *
 * - A count that disagrees with the schema fails, naming the file and the line.
 * - A file that has *stopped* making a count claim fails too. Otherwise the
 *   cheapest way to get green is to delete the sentence, and a guard that
 *   rewards deleting the thing it guards is worse than no guard.
 *
 * Run: php tests/default-count.php
 *
 * @package keel
 */

define( 'ABSPATH', __DIR__ . '/' );

// schema.php is pure data — no WordPress calls in keel_defaults_schema() — so
// it loads with no stubs. Requiring the whole plugin here would pull in the
// bootstrap and need a dozen fakes to assert one number.
require dirname( __DIR__ ) . '/includes/schema.php';

$fail = 0;

/**
 * Assert helper. Counts failures rather than exiting, so one stale file does
 * not hide the other four — the case this test exists for had four at once.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_count_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * The 1-indexed line a byte offset falls on, so a failure points somewhere.
 *
 * @param string $text   File contents.
 * @param int    $offset Byte offset.
 * @return int
 */
function keel_line_at( $text, $offset ) {
	return substr_count( $text, "\n", 0, $offset ) + 1;
}

// --- what the schema actually says -------------------------------------

$schema = keel_defaults_schema();
$total  = count( $schema );

$on_by_default = 0;
$opt_in        = 0;
$non_toggle    = 0;

foreach ( $schema as $field ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'toggle';

	if ( 'toggle' !== $type ) {
		++$non_toggle;
		continue;
	}

	if ( 'yes' === $field['default'] ) {
		++$on_by_default;
	} else {
		++$opt_in;
	}
}

keel_count_assert( $total > 0, 'The schema loaded and has entries.' );
keel_count_assert(
	( $on_by_default + $opt_in + $non_toggle ) === $total,
	sprintf( 'The three buckets account for every setting (%d + %d + %d vs %d).', $on_by_default, $opt_in, $non_toggle, $total )
);

// --- exact-count claims in prose ---------------------------------------

/*
 * Deliberately not a search for the bare number. "38" also appears in dates,
 * versions, image dimensions and word counts, and a guard that matched those
 * would either fire on unrelated edits or have to be loosened until it caught
 * nothing. These match the number *together with the noun it counts*, which is
 * what makes a sentence a claim about the schema.
 *
 * `\s+` spans newlines on purpose: SECURITY.md wraps "Keel ships 38 /
 * independent defaults" across two lines, and the first version of this test
 * passed that file vacuously because the pattern stopped at the line end.
 */
$claim_patterns = array(
	'/\b(\d+)\s+independent\s+defaults\b/',
	'/\b(\d+)\s+defaults\b/',
	'/\b(\d+)\s+schema\s+keys\b/',
	'/\bfrozen at\s+(\d+)\b/',
);

// Every file that states a count. A file may state it more than once; all of
// them have to agree, because a half-updated file is how this started.
$guarded = array( 'README.md', 'readme.txt', 'TODO.md', 'ROADMAP.md', 'SECURITY.md' );

$root = dirname( __DIR__ );

foreach ( $guarded as $name ) {
	// Reading repo files in a CLI test; wp_remote_get() is for HTTP, not this.
	$text  = (string) file_get_contents( $root . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$found = 0;

	foreach ( $claim_patterns as $pattern ) {
		if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $matches as $match ) {
			++$found;
			$claimed = (int) $match[1][0];
			$line    = keel_line_at( $text, (int) $match[0][1] );

			keel_count_assert(
				$claimed === $total,
				sprintf(
					'%s:%d claims %d, but the schema has %d — "%s".',
					$name,
					$line,
					$claimed,
					$total,
					trim( $match[0][0] )
				)
			);
		}
	}

	keel_count_assert(
		$found > 0,
		sprintf(
			'%s still states how many defaults there are. If the sentence was reworded, update $claim_patterns rather than dropping the claim.',
			$name
		)
	);
}

// --- the breakdown in SECURITY.md --------------------------------------

/*
 * This one is worth pinning separately. A reporter uses it to say which
 * defaults were on when they found the problem, so "16 on out of the box" being
 * wrong sends triage down the wrong path — and unlike the total, nothing else
 * in the repo would contradict it.
 */
$security = (string) file_get_contents( $root . '/SECURITY.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$matched = preg_match(
	'/(\d+)\s+toggles on out of the box,\s+(\d+)\s+off and opt-in, and\s+(\d+)\s+settings that are not toggles/',
	$security,
	$m
);

keel_count_assert( 1 === $matched, 'SECURITY.md still breaks the defaults down by how they ship.' );

if ( 1 === $matched ) {
	keel_count_assert( (int) $m[1] === $on_by_default, sprintf( 'SECURITY.md says %d toggles on out of the box; the schema has %d.', (int) $m[1], $on_by_default ) );
	keel_count_assert( (int) $m[2] === $opt_in, sprintf( 'SECURITY.md says %d off and opt-in; the schema has %d.', (int) $m[2], $opt_in ) );
	keel_count_assert( (int) $m[3] === $non_toggle, sprintf( 'SECURITY.md says %d non-toggle settings; the schema has %d.', (int) $m[3], $non_toggle ) );
}

// --- the exact count in the description --------------------------------

/*
 * The plugin header and the readme short description name the exact number of
 * defaults. They used to round down — "More than 30" — which never needed
 * editing and could only be broken by *removing* defaults. Naming the real
 * figure is the more useful claim and the more fragile one: it now goes stale
 * the first time anybody adds a default, in the two strings a reader sees
 * before they see anything else, and in the copy wordpress.org shows in search
 * results. So it is asserted rather than trusted.
 *
 * If this fails, the schema is right and the sentence is out of date. Update
 * both files to the number in the message.
 */
foreach ( array( 'readme.txt', 'keel.php' ) as $name ) {
	$text = (string) file_get_contents( $root . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	if ( ! preg_match( '/(\d+) sane WordPress defaults/', $text, $m ) ) {
		keel_count_assert( false, sprintf( '%s still carries its "N sane WordPress defaults" description.', $name ) );
		continue;
	}

	keel_count_assert(
		$total === (int) $m[1],
		sprintf( '%s says %d sane WordPress defaults; the schema has %d.', $name, (int) $m[1], $total )
	);
}

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "default count: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

printf(
	"default count: OK (%d settings — %d on, %d opt-in, %d non-toggle; %d files checked)\n",
	$total,
	$on_by_default,
	$opt_in,
	$non_toggle,
	count( $guarded )
);
