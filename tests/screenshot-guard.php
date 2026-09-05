<?php
/**
 * Tests for bin/verify-screenshots.sh.
 *
 * The guard has now been wrong twice in the same way, and both times it was wrong
 * in the direction that reports clean. It is the only thing standing between a copy
 * change and a stale wordpress.org listing image, so its decision logic is worth
 * testing rather than reasoning about.
 *
 * Each case builds a throwaway git repository, because what is under test is a
 * judgement about history: which commit the stamp names, whether that commit is in
 * this branch's history, and what the range query means when it is not.
 *
 * @package keel-defaults
 */

// This test drives a shell script and builds throwaway git repositories, so it runs
// commands and touches files directly. WP_Filesystem is not available here and would
// not help: the subject is what bash and git do.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink

$GLOBALS['failures'] = 0;

/**
 * Assert truthy.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 */
function keel_guard_assert( $condition, $message ) {
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		++$GLOBALS['failures'];
	}
}

/**
 * Run a shell command in a directory and return [ exit code, output ].
 *
 * @param string $cmd Command.
 * @param string $cwd Working directory.
 * @return array
 */
function keel_guard_run( $cmd, $cwd ) {
	$out  = array();
	$code = 0;
	exec( 'cd ' . escapeshellarg( $cwd ) . ' && ' . $cmd . ' 2>&1', $out, $code );

	return array( $code, implode( "\n", $out ) );
}

/**
 * Build a scratch repository carrying the guard, a picture and a UI file.
 *
 * @return string Repository path.
 */
function keel_guard_fixture() {
	$root = sys_get_temp_dir() . '/keel-guard-' . bin2hex( random_bytes( 6 ) );
	mkdir( $root . '/bin', 0777, true );
	mkdir( $root . '/.wordpress-org', 0777, true );
	mkdir( $root . '/includes', 0777, true );

	copy( dirname( __DIR__ ) . '/bin/verify-screenshots.sh', $root . '/bin/verify-screenshots.sh' );
	chmod( $root . '/bin/verify-screenshots.sh', 0755 );
	file_put_contents( $root . '/.wordpress-org/screenshot-1.png', 'not really a png' );
	file_put_contents( $root . '/includes/settings-page.php', "<?php\n// v1\n" );

	/*
	 * Signing off, deliberately. This repository signs commits, and a fixture that
	 * inherits that depends on the developer's key being present and readable -- which
	 * fails in a sandbox and would fail in CI. The subject here is what the guard does
	 * with a repository, not how its commits are attested.
	 */
	keel_guard_run( 'git init -q -b main && git config user.email t@example.test && git config user.name Test && git config commit.gpgsign false && git add -A && git commit -q -m "initial"', $root );

	return $root;
}

$root = keel_guard_fixture();

/*
---------------------------------------------------------------------------
 * Record, then check. The whole contract.
 * ------------------------------------------------------------------------
 */

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh --record', $root );
keel_guard_assert( 0 === $code, "Recording succeeds. Got {$code}: {$out}" );
keel_guard_assert(
	is_file( $root . '/.wordpress-org/.screenshots-reviewed' ),
	'Recording writes the review file.'
);

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 0 === $code, "A fresh recording passes. Got {$code}: {$out}" );

// A screen moved. The pictures may or may not need retaking, but somebody has to look.
file_put_contents( $root . '/includes/settings-page.php', "<?php\n// v2 — the screen changed\n" );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 1 === $code, "A UI change after recording fails. Got {$code}: {$out}" );
keel_guard_assert(
	false !== stripos( $out, 'screens' ),
	"The failure says the screens moved, which is the half that decides what to do. Got: {$out}"
);

keel_guard_run( 'bash bin/verify-screenshots.sh --record', $root );
list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 0 === $code, "Re-recording after looking clears it. Got {$code}: {$out}" );

// New pictures are also unreviewed pictures.
file_put_contents( $root . '/.wordpress-org/screenshot-1.png', 'a different picture' );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 1 === $code, "Changed pictures fail until recorded. Got {$code}: {$out}" );
keel_guard_assert(
	false !== stripos( $out, 'pictures' ),
	"The failure distinguishes moved pictures from moved screens. Got: {$out}"
);

/*
---------------------------------------------------------------------------
 * The reason this stopped being a commit SHA.
 *
 * Record on a branch, squash it onto main, delete the branch — the shape every
 * screenshot change in this repository actually takes. A SHA recorded on the branch
 * does not survive that, and the guard spent two releases either failing for
 * everyone but the merger or needing a follow-up commit to re-stamp on main. A hash
 * of the files is not a fact about history, so history cannot invalidate it.
 * ------------------------------------------------------------------------
 */

$squash = keel_guard_fixture();

keel_guard_run( 'git checkout -q -b feature', $squash );
file_put_contents( $squash . '/includes/settings-page.php', "<?php\n// v2\n" );
file_put_contents( $squash . '/.wordpress-org/screenshot-1.png', 'retaken for v2' );
keel_guard_run( 'bash bin/verify-screenshots.sh --record', $squash );
keel_guard_run( 'git add -A && git commit -q -m "retake for v2"', $squash );

// Squash onto main: a new commit, and the branch commit discarded.
keel_guard_run( 'git checkout -q main && git merge --squash -q feature && git commit -q -m "retake for v2 (#1)" && git branch -q -D feature', $squash );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $squash );
keel_guard_assert(
	0 === $code,
	"A recording made on a branch survives the squash that discards that branch's commit. Got {$code}: {$out}"
);
keel_guard_assert(
	false === stripos( $out, 'falling back' ),
	"Surviving the squash is the normal path, not a fallback. Got: {$out}"
);

/*
---------------------------------------------------------------------------
 * A shallow clone can answer this now.
 *
 * The old check refused outright, because a depth-1 clone cannot resolve a range —
 * which meant CI could never run it, and it says so in its own header.
 * ------------------------------------------------------------------------
 */

$shallow = $root . '-shallow';
keel_guard_run( 'git clone -q --depth 1 file://' . $root . ' ' . escapeshellarg( $shallow ), sys_get_temp_dir() );

if ( is_dir( $shallow . '/.git' ) ) {
	keel_guard_run( 'bash bin/verify-screenshots.sh --record', $shallow );
	list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $shallow );
	keel_guard_assert(
		0 === $code,
		"A shallow clone can verify, having no history to consult. Got {$code}: {$out}"
	);
	keel_guard_assert(
		false === stripos( $out, 'shallow' ),
		"A shallow clone is no longer a special case. Got: {$out}"
	);
}

/*
---------------------------------------------------------------------------
 * The old format, which is still on disk in every existing checkout.
 *
 * A bare SHA must be reported as needing one re-record, not crash and not quietly
 * pass. Reading it as a hash would compare a commit id to a digest and fail with a
 * message about the pictures, which is true but useless.
 * ------------------------------------------------------------------------
 */

$legacy = keel_guard_fixture();
file_put_contents( $legacy . '/.wordpress-org/.screenshots-reviewed', "ff9af8adb5f236926a0c97685f3c87bac29654fc\n" );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $legacy );
keel_guard_assert( 1 === $code, "A legacy stamp does not pass. Got {$code}: {$out}" );
keel_guard_assert(
	false !== strpos( $out, 'ff9af8ad' ),
	"A legacy stamp is named as the commit id it holds -- the generic advice mentions commits too, so only the id distinguishes the two messages. Got: {$out}"
);
keel_guard_assert(
	false !== stripos( $out, '--record' ),
	"A legacy stamp says what to run once. Got: {$out}"
);

/*
---------------------------------------------------------------------------
 * A watched file that disappears is a change.
 *
 * A watched source that is not there hashes as an explicit marker rather than as
 * nothing, so removing one cannot quietly shrink what is being checked.
 * ------------------------------------------------------------------------
 */

$removed = keel_guard_fixture();
keel_guard_run( 'bash bin/verify-screenshots.sh --record', $removed );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $removed );
keel_guard_assert( 0 === $code, "Baseline before the deletion passes. Got {$code}: {$out}" );

unlink( $removed . '/includes/settings-page.php' );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $removed );
keel_guard_assert(
	1 === $code,
	"Deleting a watched source registers as a change rather than shrinking the check. Got {$code}: {$out}"
);

foreach ( array( $root, $squash, $legacy, $shallow, $removed ) as $dir ) {
	keel_guard_run( 'rm -rf ' . escapeshellarg( $dir ), sys_get_temp_dir() );
}

if ( $GLOBALS['failures'] > 0 ) {
	echo "screenshot guard: {$GLOBALS['failures']} assertion(s) failed\n";
	exit( 1 );
}

echo "screenshot guard: all assertions passed\n";
