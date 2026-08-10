<?php
/**
 * The two workflows that build the shipped zip agree, and the rolling one rolls.
 *
 * `release.yml` builds the zip a tag publishes; `ci.yml` builds the zip the
 * Playground demo installs. They had a copy of the build each. The copies were
 * identical when written, which is the only time copies ever are — and the demo
 * silently serving a differently-built plugin from the release is the kind of
 * difference nobody would look for.
 *
 * The rolling asset was worse than duplicated: it was written only by
 * `release.yml`, which fires on version tags, so "the rolling latest build" was
 * the last tagged release and the hosted blueprint served the same zip as the
 * stable one. Work merged to main was invisible in the demo.
 *
 * Run: php tests/release-workflows.php
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

$script  = $root . '/bin/build-zip.sh';
$ci      = $root . '/.github/workflows/ci.yml';
$release = $root . '/.github/workflows/release.yml';

foreach ( array(
	'bin/build-zip.sh' => $script,
	'ci.yml'           => $ci,
	'release.yml'      => $release,
) as $name => $path ) {
	keel_assert( is_file( $path ), "{$name} exists." );
}

$ci_src      = file_get_contents( $ci );      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$release_src = file_get_contents( $release ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$script_src  = file_get_contents( $script );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// --- one build, called twice ---
foreach ( array(
	'ci.yml'      => $ci_src,
	'release.yml' => $release_src,
) as $name => $src ) {
	keel_assert(
		false !== strpos( $src, 'bin/build-zip.sh' ),
		"{$name} builds the zip with bin/build-zip.sh rather than its own copy."
	);

	/*
	 * And it must not *also* carry an inline build. Calling the script while
	 * keeping the old steps around is how a workflow ends up building twice and
	 * uploading whichever ran last.
	 */
	keel_assert(
		false === strpos( $src, 'zip -rq keel.zip' ),
		"{$name} has no inline zip build left beside the call to the script."
	);
}

// The script is the only place that reads .distignore, which is what makes
// .distignore the single answer to "what ships".
keel_assert(
	false !== strpos( $script_src, '.distignore' ),
	'bin/build-zip.sh takes its file list from .distignore.'
);
keel_assert(
	false === strpos( $ci_src, '.distignore' ) && false === strpos( $release_src, '.distignore' ),
	'Neither workflow reads .distignore itself.'
);

// The build script excludes itself: bin/ is tooling, not runtime.
keel_assert(
	1 === preg_match( '/^\/bin$/m', file_get_contents( $root . '/.distignore' ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	'.distignore excludes /bin, so the build script does not ship inside the plugin.'
);

/*
 * --- the rolling build actually rolls ---
 *
 * This is the assertion that would have caught the demo being a week stale. The
 * job has to exist in the workflow that runs on pushes to main, and it has to be
 * gated to main so a pull request never republishes the public demo.
 */
keel_assert(
	false !== strpos( $ci_src, 'gh release upload latest' ),
	'ci.yml republishes the rolling latest asset, so the Playground demo tracks main.'
);
keel_assert(
	false === strpos( $release_src, 'gh release upload latest' ),
	'release.yml no longer owns the rolling asset; one publisher, not two.'
);
keel_assert(
	false !== strpos( $ci_src, "github.ref == 'refs/heads/main'" ),
	'The rolling job is gated to main, so a pull request cannot republish the demo.'
);
keel_assert(
	false !== strpos( $ci_src, 'needs: [ test, compat ]' ),
	'The rolling job waits for the suite, so a red build is never published as the demo.'
);

/*
 * --- the blueprint points at the asset that is now being refreshed ---
 *
 * The two URLs differ by one path segment and mean different things:
 * releases/download/latest/  is the rolling tag; releases/latest/download/ is
 * whatever GitHub considers the newest non-prerelease. Swapping them silently
 * turns the rolling demo into a second copy of the stable one.
 */
$hosted = file_get_contents( $root . '/playground/blueprint-hosted.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$stable = file_get_contents( $root . '/playground/blueprint-stable.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

keel_assert(
	false !== strpos( $hosted, 'releases/download/latest/keel.zip' ),
	'The hosted blueprint installs the rolling asset.'
);
keel_assert(
	false !== strpos( $stable, 'releases/latest/download/keel.zip' ),
	'The stable blueprint installs the newest non-prerelease release.'
);
keel_assert(
	strpos( $hosted, 'releases/latest/download/keel.zip' ) === false,
	'The hosted blueprint is not a second copy of the stable one.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "release workflows: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "release workflows: OK\n" );
