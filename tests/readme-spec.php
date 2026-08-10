<?php
/**
 * Conformance checks for readme.txt.
 *
 * The wordpress.org readme is not prose with a header on top: the header is
 * parsed, and a plugin whose `Stable tag` disagrees with its own `Version`
 * ships the wrong code to every existing install. That failure is silent,
 * happens at release, and is exactly the kind of thing a person checks once and
 * then never again.
 *
 * These assertions are about the contract, not the writing. Nothing here says
 * what the description should argue; it says the fields Review parses are
 * present and agree with the plugin, that the sections a user looks for exist,
 * and that the external-services disclosure still names the host, the opt-out
 * and the fail-open behaviour — because that disclosure has drifted three times
 * already, once out of the field help and into here, and it is a review blocker
 * if it goes missing.
 *
 * Run: php tests/readme-spec.php
 *
 * @package keel
 */

// Reading files off disk in a CLI test; wp_remote_get() is for HTTP, not this.
$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$plugin = file_get_contents( dirname( __DIR__ ) . '/keel.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

$fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_readme_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * Read a readme header field.
 *
 * @param string $readme Readme contents.
 * @param string $field  Field name.
 * @return string
 */
function keel_readme_field( $readme, $field ) {
	return preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $readme, $m ) ? trim( $m[1] ) : '';
}

// --- header fields Review parses ---
foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ) as $field ) {
	keel_readme_assert( '' !== keel_readme_field( $readme, $field ), "readme.txt has a {$field} header." );
}

// wordpress.org indexes at most five tags and ignores the rest, so a sixth is
// not a small overrun — it is a tag that silently does nothing.
$tags = array_filter( array_map( 'trim', explode( ',', keel_readme_field( $readme, 'Tags' ) ) ) );
keel_readme_assert( count( $tags ) <= 5, 'readme.txt lists no more than five tags (found ' . count( $tags ) . ').' );

// The one that ships the wrong code if it drifts.
preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $plugin, $vm );
$version = isset( $vm[1] ) ? trim( $vm[1] ) : '';
keel_readme_assert( '' !== $version, 'keel.php declares a Version header.' );
$readme_stable = keel_readme_field( $readme, 'Stable tag' );
keel_readme_assert(
	$readme_stable === $version,
	"readme.txt Stable tag ({$readme_stable}) matches the plugin Version ({$version})."
);

/*
 * --- the floors, in both places ---
 *
 * `Requires at least` and `Requires PHP` exist in the plugin header and again in
 * readme.txt, and nothing compared them. They agree today by luck rather than by
 * guard, which is the state the Stable tag assertion above exists to prevent.
 *
 * Which copy matters depends on where the plugin came from. WordPress reads the
 * *header* to decide whether to activate, and wordpress.org reads *readme.txt* to
 * decide whether to offer the plugin and its updates at all. A readme floor lower
 * than the header's therefore offers an update to a site that then cannot run it;
 * a readme floor higher hides the plugin from sites that could. Neither shows up
 * on the install this suite runs on.
 */
foreach ( array( 'Requires at least', 'Requires PHP' ) as $floor ) {
	preg_match( '/^\s*\*\s*' . preg_quote( $floor, '/' ) . ':\s*(.+)$/mi', $plugin, $fm );
	$header_floor = isset( $fm[1] ) ? trim( $fm[1] ) : '';
	$readme_floor = keel_readme_field( $readme, $floor );

	keel_readme_assert( '' !== $header_floor, "keel.php declares a {$floor} header." );
	keel_readme_assert(
		$header_floor === $readme_floor,
		"{$floor} agrees: keel.php says {$header_floor}, readme.txt says {$readme_floor}."
	);
}

// Licence must agree in both places, or the two say different things about the
// same code.
keel_readme_assert(
	false !== strpos( $plugin, keel_readme_field( $readme, 'License' ) ),
	'The licence in readme.txt matches the one in the plugin header.'
);

// The short description is the line under the header block; wordpress.org
// truncates past 150 characters mid-sentence.
$lines = preg_split( '/\R/', $readme );
$short = '';
foreach ( $lines as $i => $line ) {
	if ( $i > 0 && '' !== trim( $line ) && false === strpos( $line, ':' ) && 0 !== strpos( $line, '=' ) ) {
		$short = trim( $line );
		break;
	}
}
keel_readme_assert( '' !== $short, 'readme.txt has a short description.' );
keel_readme_assert( strlen( $short ) <= 150, 'The short description is 150 characters or fewer (found ' . strlen( $short ) . ').' );

// --- sections a reader looks for ---
foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog', 'Upgrade Notice' ) as $section ) {
	keel_readme_assert(
		1 === preg_match( '/^==\s*' . preg_quote( $section, '/' ) . '\s*==$/mi', $readme ),
		"readme.txt has a == {$section} == section."
	);
}

// --- the disclosure that blocks review if it goes missing ---
// Named parts, not a word count: each is a separate promise, and losing any one
// of them turns an accurate disclosure into a partial one.
$external = strstr( $readme, '== External services ==' );
$external = $external ? substr( $external, 0, (int) strpos( $external, "\n== ", 4 ) ) : '';
keel_readme_assert( '' !== $external, 'readme.txt has an External services section.' );

// Case-sensitively for the identifiers. The constant and the filter differ only
// by case — KEEL_DISABLE_HIBP against keel_disable_hibp — so a case-insensitive
// search cannot tell them apart, and asserting the constant would pass on the
// filter alone. That is not hypothetical: the first version of this test did
// exactly that and reported the disclosure intact after the constant was removed.
foreach ( array(
	'api.pwnedpasswords.com'     => 'names the host contacted',
	'KEEL_DISABLE_HIBP'          => 'names the opt-out constant',
	'keel_disable_hibp'          => 'names the opt-out filter',
	'haveibeenpwned.com/Privacy' => 'links the operator privacy policy',
) as $needle => $why ) {
	keel_readme_assert(
		false !== strpos( $external, $needle ),
		"The external-services disclosure {$why} ({$needle})."
	);
}

// Prose, so matched case-insensitively — but the fact has to be there.
foreach ( array(
	'first five'  => 'says how much of the hash is sent',
	'unreachable' => 'says what happens when the API cannot be reached',
) as $needle => $why ) {
	keel_readme_assert(
		false !== stripos( $external, $needle ),
		"The external-services disclosure {$why}."
	);
}

/*
 * --- the screenshots exist, and both readmes agree on how many ---
 *
 * README.md now shows the listing screenshots rather than only readme.txt naming
 * them. That is two files referencing the same images with no connection between
 * them, and a broken <img> in a GitHub README is invisible from inside the repo.
 *
 * The numbered captions in readme.txt are what wordpress.org pairs with
 * screenshot-N.png; the README embeds the same files. So assert three things: the
 * files exist, readme.txt captions one per file, and README.md shows one per file.
 */
$shots = glob( dirname( __DIR__ ) . '/.wordpress-org/screenshot-*.png' );
sort( $shots );

keel_readme_assert( count( $shots ) > 0, 'The listing screenshots exist in .wordpress-org/.' );

preg_match( '/^== Screenshots ==(.*?)^== /ms', $readme, $shot_block );
preg_match_all( '/^\d+\.\s+\S/m', isset( $shot_block[1] ) ? $shot_block[1] : '', $captions );

keel_readme_assert(
	count( $captions[0] ) === count( $shots ),
	'readme.txt captions every screenshot: ' . count( $captions[0] ) . ' captions for ' . count( $shots ) . ' files.'
);

$project_readme = file_get_contents( dirname( __DIR__ ) . '/README.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

foreach ( $shots as $shot ) {
	$name = basename( $shot );
	keel_readme_assert(
		false !== strpos( $project_readme, '.wordpress-org/' . $name ),
		"README.md shows {$name}, so a screenshot cannot be added to the listing and left off the project page."
	);
}

if ( $fail > 0 ) {
	fwrite( STDERR, "readme spec: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "readme spec: OK\n" );
