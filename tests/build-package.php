<?php
/**
 * What bin/build-zip.sh actually produces, by running it.
 *
 * Reading a shell script is not the same as watching what it writes to disk.
 * tests/release-workflows.php reads this one as text and checks that both
 * workflows call it. Nothing ran it. The script is the one thing between the
 * repository and wordpress.org, where a published version can never be
 * withdrawn.
 *
 * It was not producing what the text says. `rsync --exclude="$OUT"` only hides
 * the output directory when the pattern matches a path relative to the transfer
 * root, so an absolute output path — the natural thing to pass when building
 * somewhere outside the tree — excluded nothing, and a `build/` left over from
 * an earlier run was copied into the package. That build was a 1MB zip with a
 * previous build nested inside it, and every other signal said it was fine.
 *
 * Each case below runs the real script in a throwaway copy of the repository.
 * The copy is what makes a stale `build/` safe to stage: the developer's own
 * build directory is never read, written, or deleted by this file.
 *
 * Run: php tests/build-package.php
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

/**
 * Run a command, returning array( exit code, combined output ).
 *
 * @param string $cmd Shell command.
 * @return array
 */
function keel_run( $cmd ) {
	$out  = array();
	$code = 0;
	exec( $cmd . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the subject of this test is a shell script; there is no way to test what it writes without running it.

	return array( $code, implode( "\n", $out ) );
}

/*
 * The build needs rsync, zip and bash. A missing one is a failure, not a skip:
 * a test that passes because it could not run reports the same green as a test
 * that ran, and this is the file standing between a bad package and a permanent
 * upload.
 */
foreach ( array( 'rsync', 'zip', 'unzip', 'bash' ) as $tool ) {
	list( $code ) = keel_run( 'command -v ' . escapeshellarg( $tool ) );
	keel_assert( 0 === $code, "{$tool} is available; bin/build-zip.sh cannot be verified without it." );
}

if ( $fail > 0 ) {
	fwrite( STDERR, "build package: {$fail} failed\n" );
	exit( 1 );
}

/**
 * A throwaway copy of the repository to build in.
 *
 * Excludes the heavy directories the package drops anyway, and the repository's
 * own build/ — the stale directory each case needs is staged deliberately, not
 * inherited from whatever the developer last ran.
 *
 * @param string $root Repository root.
 * @return string Sandbox path.
 */
function keel_sandbox( $root ) {
	$dir = sys_get_temp_dir() . '/keel-build-' . getmypid() . '-' . count( $GLOBALS['keel_sandboxes'] );

	keel_run( 'rm -rf ' . escapeshellarg( $dir ) );
	mkdir( $dir, 0700, true );

	keel_run(
		'rsync -a --exclude=.git --exclude=node_modules --exclude=vendor --exclude=build '
		. escapeshellarg( $root . '/' ) . ' ' . escapeshellarg( $dir . '/' )
	);

	$GLOBALS['keel_sandboxes'][] = $dir;

	return $dir;
}

$GLOBALS['keel_sandboxes'] = array();

/**
 * Top-level entries of a built package.
 *
 * @param string $package Path to the assembled keel-defaults directory.
 * @return string[]
 */
function keel_package_entries( $package ) {
	if ( ! is_dir( $package ) ) {
		return array();
	}

	$entries = array_values( array_diff( scandir( $package ), array( '.', '..' ) ) );
	sort( $entries );

	return $entries;
}

// --- the package is the runtime tree and nothing else ---

$sandbox = keel_sandbox( $root );
$out     = $sandbox . '/build';

list( $code, $output ) = keel_run( 'cd ' . escapeshellarg( $sandbox ) . ' && bash bin/build-zip.sh build' );

keel_assert( 0 === $code, "The build succeeds with the relative output directory both workflows pass.\n{$output}" );

/*
 * Named rather than derived. Deriving this from .distignore would only restate
 * the script's own logic in PHP and agree with it however it behaves; the point
 * of the list is to be an independent statement of what a site is supposed to
 * receive, so that adding a directory to the repository and forgetting to
 * exclude it fails here.
 */
$expected = array(
	'CONTRIBUTING.md',
	'LICENSE',
	'SECURITY.md',
	'assets',
	'includes',
	'keel.php',
	'languages',
	'readme.txt',
	'uninstall.php',
);

$entries = keel_package_entries( $out . '/keel-defaults' );

keel_assert(
	$expected === $entries,
	"The package contains exactly the runtime tree.\n  expected: " . implode( ', ', $expected ) . "\n  actual:   " . implode( ', ', $entries )
);

keel_assert( is_file( $out . '/keel.zip' ), 'The build writes keel.zip beside the assembled directory.' );

// The .pot is what translate.wordpress.org starts from; the compiled catalogs
// are generated there and must not ship as a second source.
$catalogs = array_merge(
	glob( $out . '/keel-defaults/languages/*.po' ),
	glob( $out . '/keel-defaults/languages/*.mo' )
);

keel_assert( array() === $catalogs, 'No compiled translation catalogs ship: ' . implode( ', ', $catalogs ) );
keel_assert( is_file( $out . '/keel-defaults/languages/keel-defaults.pot' ), 'The translation template does ship.' );

// --- a stale build directory does not ship, whatever path the output takes ---

$sandbox = keel_sandbox( $root );

// Exactly the state a developer is in after any previous build: an output
// directory sitting in the tree, holding a package and a zip of its own.
mkdir( $sandbox . '/build/keel-defaults', 0700, true );
file_put_contents( $sandbox . '/build/keel-defaults/keel.php', "<?php // stale\n" );
file_put_contents( $sandbox . '/build/keel.zip', "stale\n" );

$elsewhere = sys_get_temp_dir() . '/keel-build-out-' . getmypid();
keel_run( 'rm -rf ' . escapeshellarg( $elsewhere ) );

list( $code, $output ) = keel_run(
	'cd ' . escapeshellarg( $sandbox ) . ' && bash bin/build-zip.sh ' . escapeshellarg( $elsewhere )
);

keel_assert( 0 === $code, "The build succeeds with an output directory outside the tree.\n{$output}" );

$entries = keel_package_entries( $elsewhere . '/keel-defaults' );

keel_assert(
	! in_array( 'build', $entries, true ),
	'A stale build/ is not copied into the package built to an out-of-tree path: ' . implode( ', ', $entries )
);

keel_assert(
	$expected === $entries,
	"The out-of-tree build produces the same runtime tree as the in-tree one.\n  expected: " . implode( ', ', $expected ) . "\n  actual:   " . implode( ', ', $entries )
);

// --- an in-tree output directory excludes itself, by any name and any path ---

/*
 * The case .distignore cannot cover. A leftover `build/` is named there, so it
 * is dropped however the build is invoked — but an output directory called
 * anything else, given as an absolute path inside the tree, is caught only by
 * the exclude being anchored and relative. Without that it copies itself.
 */
$sandbox = keel_sandbox( $root );

list( $code, $output ) = keel_run(
	'cd ' . escapeshellarg( $sandbox ) . ' && bash bin/build-zip.sh ' . escapeshellarg( $sandbox . '/dist' )
);

keel_assert( 0 === $code, "The build succeeds with an absolute output path inside the tree.\n{$output}" );

$entries = keel_package_entries( $sandbox . '/dist/keel-defaults' );

keel_assert(
	$expected === $entries,
	"An output directory inside the tree does not copy itself into the package.\n  expected: " . implode( ', ', $expected ) . "\n  actual:   " . implode( ', ', $entries )
);

// --- the script refuses an output path that would delete the source ---

/*
 * `rm -rf "$OUT"` is the second line of the script. Passing the repository root
 * as the output directory deletes the repository, and the argument is a plain
 * positional one — a wrapper resolving a path wrongly is enough. Tested in a
 * sandbox for the obvious reason.
 */
$sandbox = keel_sandbox( $root );

list( $code, $output ) = keel_run(
	'cd ' . escapeshellarg( $sandbox ) . ' && bash bin/build-zip.sh ' . escapeshellarg( $sandbox )
);

keel_assert( 0 !== $code, "The build refuses an output directory that is the source tree itself.\n{$output}" );
keel_assert( is_file( $sandbox . '/keel.php' ), 'Refusing it leaves the source tree intact.' );

foreach ( $GLOBALS['keel_sandboxes'] as $dir ) {
	keel_run( 'rm -rf ' . escapeshellarg( $dir ) );
}
keel_run( 'rm -rf ' . escapeshellarg( $elsewhere ) );

if ( $fail > 0 ) {
	fwrite( STDERR, "build package: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "build package: OK\n" );
