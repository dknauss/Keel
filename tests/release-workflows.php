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

/*
 * --- the Playground contract ---
 *
 * A blueprint that installs the wrong folder, fetches a filename the build no
 * longer emits, or lands on a menu slug that has moved does not fail loudly. It
 * opens a WordPress with the plugin missing or a blank settings screen, which
 * looks like the plugin being broken rather than the demo being stale. The
 * directory's Live Preview is the first thing most people see.
 *
 * Every value below is derived from the thing it has to agree with — the menu
 * slug from the source that registers it, the folder and archive names from the
 * script that writes them — so this cannot drift into agreeing with a stale copy
 * of itself. All of it was hand-checked once during the rename, which is exactly
 * the kind of verification that stops being true the next time somebody edits.
 */
$settings_src = file_get_contents( $root . '/includes/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$build_src    = file_get_contents( $root . '/bin/build-zip.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

// Anchored on the render callback: the slug is the argument before it, and
// counting commas would have picked up the capability instead — it did.
preg_match( '/add_options_page\(.*?\'([a-z0-9_-]+)\',\s*\'(keel_defaults_render_settings_page)\'/s', $settings_src, $slug_m );
$menu_slug = isset( $slug_m[1] ) ? $slug_m[1] : '';

preg_match( '/mkdir -p "\$OUT\/([a-z0-9-]+)"/', $build_src, $dir_m );
$built_folder = isset( $dir_m[1] ) ? $dir_m[1] : '';

preg_match( '/zip -rq ([a-z0-9-]+\.zip)/', $build_src, $zip_m );
$built_zip = isset( $zip_m[1] ) ? $zip_m[1] : '';

keel_assert( '' !== $menu_slug, 'The settings page registers a menu slug (' . $menu_slug . ').' );
keel_assert( '' !== $built_folder, 'build-zip.sh names the folder it assembles (' . $built_folder . ').' );
keel_assert( '' !== $built_zip, 'build-zip.sh names the archive it writes (' . $built_zip . ').' );

$blueprints = array(
	'.wordpress-org/blueprints/blueprint.json' => 'directory',
	'playground/blueprint-stable.json'         => 'github',
	'playground/blueprint-hosted.json'         => 'github',
);

foreach ( $blueprints as $file => $kind ) {
	$raw  = file_get_contents( $root . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$json = json_decode( $raw, true );

	keel_assert( is_array( $json ), "{$file} is valid JSON." );

	if ( ! is_array( $json ) ) {
		continue;
	}

	$landing = isset( $json['landingPage'] ) ? $json['landingPage'] : '';

	/*
	 * Whole slug. `page=keel` is a substring of `page=keel-defaults`, so a
	 * strpos() here passes on a landing page pointing at a screen that does not
	 * exist — which is precisely the rename that made this test worth writing.
	 * The first draft of this assertion had that bug.
	 */
	keel_assert(
		1 === preg_match( '/[?&]page=' . preg_quote( $menu_slug, '/' ) . '(?:&|$)/', $landing ),
		"{$file} lands on the slug the settings page actually registers ({$menu_slug}); it opens \"{$landing}\"."
	);

	$installs = array();

	foreach ( isset( $json['steps'] ) ? $json['steps'] : array() as $step ) {
		if ( isset( $step['step'] ) && 'installPlugin' === $step['step'] ) {
			$installs[] = $step;
		}
	}

	if ( 'directory' === $kind ) {
		/*
		 * wordpress.org mounts the plugin it is serving. A self-install here
		 * would fetch a build nobody reviewed, and the directory restricts
		 * blueprint resources to wordpress.org anyway, so it would more likely
		 * just fail.
		 */
		keel_assert(
			array() === $installs,
			"{$file} does not install the plugin; the directory supplies it."
		);

		continue;
	}

	keel_assert( 1 === count( $installs ), "{$file} installs the plugin exactly once." );

	if ( 1 !== count( $installs ) ) {
		continue;
	}

	$target = isset( $installs[0]['options']['targetFolderName'] ) ? $installs[0]['options']['targetFolderName'] : '';
	$url    = isset( $installs[0]['pluginData']['url'] ) ? $installs[0]['pluginData']['url'] : '';

	keel_assert(
		$target === $built_folder,
		"{$file} installs into \"{$target}\"; build-zip.sh assembles \"{$built_folder}\". A mismatch installs a plugin WordPress will not find."
	);

	keel_assert(
		false !== strpos( $url, '/' . $built_zip ),
		"{$file} fetches an archive named for \"{$built_zip}\"; it asks for \"{$url}\"."
	);
}

/*
 * --- the wordpress.org deploy is wired to something that fires ---
 *
 * Nothing in this suite knew wp-deploy.yml existed, and the deploy is the one
 * step whose result cannot be taken back: a version pushed to the directory is
 * the version every site updates to, and an SVN tag is permanent.
 *
 * The failure this guards against is the one written into the workflow's own
 * header comment. `release: types: [published]` looks like the obvious trigger
 * and never fires, because release.yml publishes with the default GITHUB_TOKEN
 * and GitHub does not start runs from that token's events. In the sibling repo
 * it was carried for six releases, fired zero times, and every other signal —
 * tag, Release, green CI — said the release had shipped.
 */
$deploy_path = $root . '/.github/workflows/wp-deploy.yml';
keel_assert( is_file( $deploy_path ), 'wp-deploy.yml exists.' );

$deploy_src = is_file( $deploy_path ) ? file_get_contents( $deploy_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

keel_assert(
	1 === preg_match( '#uses:\s*\./\.github/workflows/wp-deploy\.yml#', $release_src ),
	'release.yml calls the deploy workflow directly, rather than relying on a release event to start it.'
);
keel_assert(
	1 === preg_match( '/^\s*needs:\s*release\s*$/m', $release_src ),
	'The deploy job needs the release job, so a failed lint, build or publish stops it before wordpress.org.'
);
keel_assert(
	0 === preg_match( '/^\s*release:\s*$/m', $deploy_src ),
	'wp-deploy.yml has no release: trigger — the one that looks obvious and never fires.'
);

/*
 * The human gate. GitHub auto-creates a missing environment with no protection
 * rules, so a typo here does not error — the job simply stops asking for
 * approval and deploys. The environment's existence cannot be asserted from
 * inside the repository; that it is requested at all can.
 */
keel_assert(
	1 === preg_match( '/^\s*environment:\s*wordpress-org\s*$/m', $deploy_src ),
	'The deploy job runs in the wordpress-org environment, which is where the approval gate hangs.'
);

// One builder, so what is uploaded to the directory is what the release and the
// demo were built from.
keel_assert(
	false !== strpos( $deploy_src, 'bin/build-zip.sh' ),
	'wp-deploy.yml builds with bin/build-zip.sh rather than a third copy of the build.'
);

/*
 * Working material that must not be published. Everything left in
 * .wordpress-org is uploaded verbatim to the plugin's public assets directory,
 * so these two excludes are the only thing keeping the blueprint sources and
 * the brand working files off a public URL.
 */
foreach ( array( 'blueprints/', 'brand/' ) as $private ) {
	keel_assert(
		false !== strpos( $deploy_src, "--exclude '{$private}'" ),
		"The asset staging excludes {$private}, which is source material, not a directory-page asset."
	);
}

/*
 * --- the slug and the built folder are the same name ---
 *
 * Both are derived, not restated. SLUG decides where on wordpress.org this
 * lands and BUILD_DIR decides what is uploaded; the folder name inside the
 * package is what WordPress identifies the plugin by on a real install. A
 * disagreement between them deploys the wrong tree, or the right tree under a
 * slug nobody is watching.
 */
preg_match( '/^\s*SLUG:\s*([a-z0-9-]+)\s*$/m', $deploy_src, $slug_dm );
preg_match( '/^\s*BUILD_DIR:\s*(\S+)\s*$/m', $deploy_src, $bd_m );

$deploy_slug = isset( $slug_dm[1] ) ? $slug_dm[1] : '';
$deploy_dir  = isset( $bd_m[1] ) ? $bd_m[1] : '';

keel_assert( '' !== $deploy_slug, 'wp-deploy.yml names the wordpress.org slug (' . $deploy_slug . ').' );
keel_assert(
	$deploy_slug === $built_folder,
	"The wordpress.org slug \"{$deploy_slug}\" is the folder build-zip.sh assembles (\"{$built_folder}\"); a site installs the plugin under the slug, so these are one name."
);
keel_assert(
	'build/' . $built_folder === $deploy_dir,
	"wp-deploy.yml uploads \"{$deploy_dir}\"; the build writes \"build/{$built_folder}\"."
);

/*
 * --- the tag check refuses everything but a stable release ---
 *
 * Run, not read. The pattern is lifted out of the workflow and executed by the
 * same shell that will execute it there, because the property that matters is
 * what it accepts. wordpress.org has no pre-release concept: a `-rc1` tag
 * reaching the directory would become the stable version every user updates to,
 * and release.yml deliberately creates those tags.
 */
preg_match( '/if \[\[ ! "\$REF" =~ (\S+) \]\]/', $deploy_src, $re_m );
$ref_pattern = isset( $re_m[1] ) ? $re_m[1] : '';

keel_assert( '' !== $ref_pattern, 'wp-deploy.yml checks the ref against a pattern before deploying.' );

if ( '' !== $ref_pattern ) {
	$cases = array(
		'v0.6.0'      => true,
		'v10.20.30'   => true,
		'v0.6.0-rc1'  => false,
		'v0.6.0-beta' => false,
		'v0.6.0-dev'  => false,
		'v0.6'        => false,
		'main'        => false,
		'latest'      => false,
	);

	foreach ( $cases as $ref => $should_accept ) {
		$script = 'REF=' . escapeshellarg( $ref ) . '; if [[ ! "$REF" =~ ' . $ref_pattern . ' ]]; then exit 1; fi';
		$out    = array();
		$code   = 0;
		exec( 'bash -c ' . escapeshellarg( $script ) . ' 2>/dev/null', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the question is what bash accepts, so bash answers it.

		$accepted = ( 0 === $code );

		keel_assert(
			$accepted === $should_accept,
			$should_accept
				? "The deploy accepts the stable tag {$ref}."
				: "The deploy refuses {$ref}; wordpress.org would publish it as the version every site updates to."
		);
	}
}

// The tag is not trusted to describe itself: the version in the plugin header
// and the Stable tag in readme.txt are checked against it before the upload.
keel_assert(
	false !== strpos( $deploy_src, 'Stable tag:' ) && false !== strpos( $deploy_src, 'Version:' ),
	'wp-deploy.yml verifies the tag against the plugin header and readme.txt Stable tag before uploading.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "release workflows: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "release workflows: OK\n" );
