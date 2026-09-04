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

	keel_guard_run( 'git init -q -b main && git config user.email t@example.test && git config user.name Test && git add -A && git commit -q -m "initial"', $root );

	return $root;
}

$root = keel_guard_fixture();

/*
---------------------------------------------------------------------------
 * The two cases it already got right.
 * ------------------------------------------------------------------------
 */

list( $head ) = array( trim( keel_guard_run( 'git rev-parse HEAD', $root )[1] ) );
file_put_contents( $root . '/.wordpress-org/.screenshots-reviewed', $head . "\n" );
keel_guard_run( 'git add -A && git commit -q -m stamp', $root );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 0 === $code, "A stamp at HEAD with no UI changes passes. Got {$code}: {$out}" );

file_put_contents( $root . '/includes/settings-page.php', "<?php\n// v2 — the screen changed\n" );
keel_guard_run( 'git add -A && git commit -q -m "change the screen"', $root );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root );
keel_guard_assert( 1 === $code, "A UI change after the stamp fails. Got {$code}: {$out}" );
keel_guard_assert( false !== strpos( $out, 'change the screen' ), 'The failure names the commit that changed the screen.' );

/*
---------------------------------------------------------------------------
 * A stamp naming a commit that is not in this history.
 *
 * This is what a squash merge leaves behind: the stamp records the branch commit,
 * the squash replaces it, the branch is deleted. In a clone that still has the
 * object the commit resolves, so the unresolvable-commit guard does not fire --
 * and `git log <sibling>..HEAD` computes a range that means nothing here and can
 * easily be empty. Reporting "current" from an empty meaningless range is the
 * failure mode this whole check exists to prevent.
 * ------------------------------------------------------------------------
 */

$root2 = keel_guard_fixture();
$base  = trim( keel_guard_run( 'git rev-parse HEAD', $root2 )[1] );

// A sibling commit, reachable in the object store but not from main.
keel_guard_run( 'git checkout -q -b sidebranch && git commit -q --allow-empty -m "branch tip"', $root2 );
$sibling = trim( keel_guard_run( 'git rev-parse HEAD', $root2 )[1] );
keel_guard_run( 'git checkout -q main', $root2 );

// The screen changes on main, after the sibling was created.
file_put_contents( $root2 . '/includes/settings-page.php', "<?php\n// v2 — the screen changed on main\n" );
file_put_contents( $root2 . '/.wordpress-org/.screenshots-reviewed', $sibling . "\n" );
keel_guard_run( 'git add -A && git commit -q -m "change the screen on main"', $root2 );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root2 );
keel_guard_assert(
	0 !== $code,
	"A stamp outside this history does not report clean. Got {$code}: {$out}"
);
keel_guard_assert(
	false === stripos( $out, 'screenshots: current' ),
	"A stamp outside this history is never answered with 'current'. Got: {$out}"
);
keel_guard_assert(
	false !== stripos( $out, 'not in this branch' ) || false !== stripos( $out, 'falling back' ),
	"The reason is stated rather than left as a bare failure. Got: {$out}"
);

/*
---------------------------------------------------------------------------
 * And it should still be usable: falling back has to reach an answer, not just
 * refuse. A repository whose pictures are current must pass even when the stamp
 * has been orphaned by a squash.
 * ------------------------------------------------------------------------
 */

$root3 = keel_guard_fixture();
keel_guard_run( 'git checkout -q -b sidebranch && git commit -q --allow-empty -m "branch tip"', $root3 );
$orphan = trim( keel_guard_run( 'git rev-parse HEAD', $root3 )[1] );
keel_guard_run( 'git checkout -q main', $root3 );

// Pictures retaken on main; nothing has touched a screen since.
file_put_contents( $root3 . '/.wordpress-org/screenshot-1.png', 'a newer picture' );
file_put_contents( $root3 . '/.wordpress-org/.screenshots-reviewed', $orphan . "\n" );
keel_guard_run( 'git add -A && git commit -q -m "retake the pictures"', $root3 );

list( $code, $out ) = keel_guard_run( 'bash bin/verify-screenshots.sh', $root3 );
keel_guard_assert(
	0 === $code,
	"An orphaned stamp over current pictures falls back to an answer rather than refusing. Got {$code}: {$out}"
);

// A pass arrived at by falling back is not the same claim as a pass from the stamp,
// and a reader has to be able to tell which one they got.
keel_guard_assert(
	false !== stripos( $out, 'falling back' ),
	"A fallback says so even when the answer is clean. Got: {$out}"
);
keel_guard_assert(
	false !== stripos( $out, 'not in this branch' ),
	"The fallback names why the stamp was unusable. Got: {$out}"
);

foreach ( array( $root, $root2, $root3 ) as $dir ) {
	keel_guard_run( 'rm -rf ' . escapeshellarg( $dir ), sys_get_temp_dir() );
}

if ( $GLOBALS['failures'] > 0 ) {
	echo "screenshot guard: {$GLOBALS['failures']} assertion(s) failed\n";
	exit( 1 );
}

echo "screenshot guard: all assertions passed\n";
