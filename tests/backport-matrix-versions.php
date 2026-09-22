<?php
/**
 * The live backport matrix resolves each row's versions from the release list.
 *
 * The matrix used to pin a source and target per row, so every WordPress
 * security release turned the weekly run red with "expected tip 6.9.7, got
 * 6.9.8": the check working, against numbers that had gone stale. Each row now
 * names a branch, and tests/integration/resolve-backport-versions.sh takes the
 * branch's newest release as the target and the one before it as the source.
 *
 * The resolver must refuse, rather than guess, whenever the row would prove
 * nothing: a branch with no releases, a tip with no earlier patch, or a source
 * the release list does not call insecure. These cases run it against fixed
 * stable-check maps, never the live API.
 *
 * @package Keel
 */

$fail     = 0;
$resolver = dirname( __DIR__ ) . '/tests/integration/resolve-backport-versions.sh';

/**
 * Record a failed assertion.
 *
 * Collects rather than exiting, so one run names every failure.
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
 * Run the resolver for a branch against a fixed stable-check map.
 *
 * @param string               $resolver Resolver path.
 * @param string               $branch   Branch, e.g. "6.9".
 * @param array<string,string> $map      Version => status.
 * @return array{code:int,out:string,env:string}
 */
function keel_resolve( $resolver, $branch, $map ) {
	// The resolver is a shell script, so it is exercised through real files and a
	// real process; there is no WordPress here to route these through.
	$dir = sys_get_temp_dir() . '/keel-resolver-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- scratch directory for a CLI test.
	file_put_contents( "{$dir}/map.json", json_encode( $map ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fixture for a CLI test; no WordPress is loaded.
	touch( "{$dir}/env" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- scratch file for a CLI test.

	$cmd = sprintf(
		'KEEL_BRANCH=%s KEEL_STABLE_CHECK_FILE=%s GITHUB_ENV=%s bash %s 2>&1',
		escapeshellarg( $branch ),
		escapeshellarg( "{$dir}/map.json" ),
		escapeshellarg( "{$dir}/env" ),
		escapeshellarg( $resolver )
	);
	exec( $cmd, $lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- the subject of this test is a shell script.
	$env = (string) file_get_contents( "{$dir}/env" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local scratch file, not a URL.
	array_map( 'unlink', glob( "{$dir}/*" ) );
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the scratch directory.

	return array(
		'code' => $code,
		'out'  => implode( "\n", $lines ),
		'env'  => $env,
	);
}

keel_assert( is_file( $resolver ), 'The resolver script exists.' );

$map = array(
	'6.9'    => 'insecure',
	'6.9.1'  => 'insecure',
	'6.9.8'  => 'insecure',
	'6.9.9'  => 'outdated',
	'6.9.10' => 'outdated',
	'7.0.5'  => 'insecure',
	'7.0.6'  => 'outdated',
	'7.0.1'  => 'outdated',
	'7.1'    => 'insecure',
	'7.1.1'  => 'outdated',
	'7.1.2'  => 'latest',
);

// The target is the branch's newest release, compared as versions, not strings:
// 6.9.10 is newer than 6.9.9.
$r = keel_resolve(
	$resolver,
	'6.9',
	array(
		'6.9.2'  => 'insecure',
		'6.9.9'  => 'insecure',
		'6.9.10' => 'latest',
	)
);
keel_assert( 0 === $r['code'], "A branch with an insecure previous patch resolves (got exit {$r['code']}: {$r['out']})." );
keel_assert( false !== strpos( $r['env'], "KEEL_TARGET=6.9.10\n" ) && false !== strpos( $r['env'], "KEEL_SOURCE=6.9.9\n" ), 'The target is the newest release compared as a version (6.9.10 over 6.9.9 and 6.9.2), and the source the patch before it.' );

// A normal branch: source is the patch before the tip, and it is insecure.
$r = keel_resolve( $resolver, '7.0', $map );
keel_assert( 0 === $r['code'], "The 7.0 branch resolves (got exit {$r['code']}: {$r['out']})." );
keel_assert( false !== strpos( $r['env'], "KEEL_SOURCE=7.0.5\n" ) && false !== strpos( $r['env'], "KEEL_TARGET=7.0.6\n" ), 'The source is the patch before the tip, and both are written for later steps.' );
keel_assert( false !== strpos( $r['out'], '7.0.5' ) && false !== strpos( $r['out'], '7.0.6' ), 'The resolved versions are printed, so the log names what the row tested.' );

// A tip at patch 1 starts from the bare x.y release, which WordPress names "7.1", not "7.1.0".
$r = keel_resolve(
	$resolver,
	'7.1',
	array(
		'7.1'   => 'insecure',
		'7.1.1' => 'latest',
	)
);
keel_assert( 0 === $r['code'] && false !== strpos( $r['env'], "KEEL_SOURCE=7.1\n" ) && false !== strpos( $r['env'], "KEEL_TARGET=7.1.1\n" ), "A tip at patch 1 starts from the bare x.y release (got exit {$r['code']}: {$r['out']} / {$r['env']})." );

// Refusals: each names why, and writes nothing.
foreach (
	array(
		'an unknown branch'              => array( '5.0', $map ),
		'a branch whose tip is its .0'   => array( '7.2', array( '7.2' => 'latest' ) ),
		'an outdated source'             => array( '6.9', $map ),
		'a source missing from the list' => array( '6.8', array( '6.8.10' => 'latest' ) ),
	) as $case => $args
) {
	$r = keel_resolve( $resolver, $args[0], $args[1] );
	keel_assert( 0 !== $r['code'], "The resolver refuses {$case}." );
	keel_assert( '' === trim( $r['env'] ), "Refusing {$case} writes no versions for later steps." );
	keel_assert( '' !== trim( $r['out'] ), "Refusing {$case} says why." );
}

// A branch argument that is not a version is refused before any lookup.
$r = keel_resolve( $resolver, '6.9; rm -rf /', $map );
keel_assert( 0 !== $r['code'] && '' === trim( $r['env'] ), 'A branch that is not an x.y version is refused.' );

if ( $fail > 0 ) {
	fwrite( STDERR, "backport-matrix-versions: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "backport-matrix-versions: OK\n" );
