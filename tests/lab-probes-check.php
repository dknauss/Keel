<?php
/**
 * The separate-tester gate reads a branch's history correctly.
 *
 * For risky changes, a separate tester commits a probe file to
 * docs/review/probes/ before any code exists, and the implementer has to pass
 * probes it never wrote. bin/check-lab-probes is what makes "the tester went
 * first" checkable: lab-probes.yml runs it on every pull request, and it fails a
 * branch whose probes landed after the build started or changed afterward.
 *
 * Exercised as a black box on throwaway git repositories, the way the workflow
 * runs it. See docs/review/probes/README.md.
 *
 * Run: php tests/lab-probes-check.php
 *
 * @package keel
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.WP.AlternativeFunctions -- builds throwaway git repositories and runs the checker as a CLI subprocess, outside WordPress, the way the workflow does.

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
 * Run a shell command in a directory, or die.
 *
 * @param string $dir     Working directory.
 * @param string $command Command.
 */
function keel_probe_sh( $dir, $command ) {
	exec( 'cd ' . escapeshellarg( $dir ) . ' && ' . $command . ' 2>&1', $out, $status );
	if ( 0 !== $status ) {
		fwrite( STDERR, "Setup failed: {$command}\n" . implode( "\n", $out ) . "\n" );
		exit( 1 );
	}
}

/**
 * Write a file and commit it, or delete it when $content is null.
 *
 * @param string      $dir     Repository.
 * @param array       $files   Path => content (null deletes).
 * @param string      $message Commit message.
 */
function keel_probe_commit( $dir, array $files, $message ) {
	foreach ( $files as $path => $content ) {
		if ( null === $content ) {
			keel_probe_sh( $dir, 'git rm -q ' . escapeshellarg( $path ) );
			continue;
		}
		if ( ! is_dir( dirname( "{$dir}/{$path}" ) ) ) {
			mkdir( dirname( "{$dir}/{$path}" ), 0777, true );
		}
		file_put_contents( "{$dir}/{$path}", $content );
		keel_probe_sh( $dir, 'git add ' . escapeshellarg( $path ) );
	}
	keel_probe_sh( $dir, 'git commit -q -m ' . escapeshellarg( $message ) );
}

/**
 * A fresh repository with one commit on main and a feature branch checked out.
 *
 * @return string Repository path.
 */
function keel_probe_repo() {
	$dir = sys_get_temp_dir() . '/keel-probes-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir );
	keel_probe_sh( $dir, 'git init -q -b main && git config user.email lab@example.test && git config user.name Lab && git config commit.gpgsign false' );
	keel_probe_commit(
		$dir,
		array(
			'keel.php'                     => "<?php\n",
			'docs/review/probes/README.md' => "# Probes\n",
			'docs/review/probes/merged.md' => "Tester: Codex\nImplementer: Claude\n\n| P1 | old | admin | x | y |\n",
		),
		'Base'
	);
	keel_probe_sh( $dir, 'git checkout -q -b feature' );
	return $dir;
}

/**
 * Run the checker against main..feature.
 *
 * @param string $dir   Repository.
 * @param string $flags Extra checker flags, such as --require-probe.
 * @return array{0:int,1:string} Exit code and combined output.
 */
function keel_probe_run( $dir, $flags = '' ) {
	$script = dirname( __DIR__ ) . '/bin/check-lab-probes';
	exec( 'cd ' . escapeshellarg( $dir ) . ' && ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . $flags . ' main feature 2>&1', $out, $status );
	return array( $status, implode( "\n", $out ) );
}

$probes = "# Probes: user export\n\nTester: Codex (GPT)\nImplementer: Claude\nSaw: issue #1\n\n| ID | Behaviour | Role | Artefact | Expected |\n| --- | --- | --- | --- | --- |\n| P1 | Editors are refused | editor | HTTP status | 403 |\n| P2 | Admins get a file | administrator | response | 200 |\n";
$tester = "Add tester probes for user export\n\nLab-Role: tester\n";
$path   = 'docs/review/probes/user-export.md';

$cases = array(
	'no probe file on the branch passes'               => array(
		0,
		'nothing to check',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
	),
	'probes first, build after, unchanged'             => array(
		0,
		'2 tester probe(s) by Codex (GPT)',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
	),
	'merging main in does not trip the ordering check' => array(
		0,
		'unchanged since',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
			keel_probe_sh( $dir, 'git checkout -q main' );
			keel_probe_commit( $dir, array( 'other.php' => "<?php\n" ), 'Main moves on' );
			keel_probe_sh( $dir, 'git checkout -q feature && git merge -q --no-edit main' );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
	),
	'build before probes fails'                        => array(
		1,
		'before the tester',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
		},
	),
	'probe edited after the build fails'               => array(
		1,
		'changed after the tester added it',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
			keel_probe_commit( $dir, array( $path => str_replace( '403', '200', $probes ) ), 'Clarify P1' );
		},
	),
	'probe deleted after the build fails'              => array(
		1,
		'changed after the tester added it',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
			keel_probe_commit( $dir, array( $path => null ), 'Drop probes' );
		},
	),
	'tester commit that also touches code fails'       => array(
		1,
		'also touches keel.php',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit(
				$dir,
				array(
					$path      => $probes,
					'keel.php' => "<?php // change\n",
				),
				$tester
			);
		},
	),
	'missing Lab-Role trailer fails'                   => array(
		1,
		'Lab-Role: tester',
		static function ( $dir ) use ( $probes, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), 'Add probes' );
		},
	),
	'same agent as tester and implementer fails'       => array(
		1,
		'same agent',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => str_replace( 'Codex (GPT)', ' claude ', $probes ) ), $tester );
		},
	),
	'template placeholder fails'                       => array(
		1,
		'placeholder',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => str_replace( 'Implementer: Claude', 'Implementer: <agent>', $probes ) ), $tester );
		},
	),
	'no probe rows fails'                              => array(
		1,
		'no probe rows',
		static function ( $dir ) use ( $tester, $path ) {
			keel_probe_commit( $dir, array( $path => "Tester: Codex\nImplementer: Claude\n\nThe settings work.\n" ), $tester );
		},
	),
	'editing a probe file already on main fails'       => array(
		1,
		'already on the base branch',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'docs/review/probes/merged.md' => "Tester: Codex\nImplementer: Claude\n\n| P1 | new | admin | x | y |\n" ), "Tweak\n\nLab-Role: tester\n" );
		},
	),
	'README edits are not probes'                      => array(
		0,
		'nothing to check',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'docs/review/probes/README.md' => "# Probes, revised\n" ), 'Docs' );
		},
	),
	'tier-3: valid probes pass'                        => array(
		0,
		'2 tester probe(s) by Codex (GPT)',
		static function ( $dir ) use ( $probes, $tester, $path ) {
			keel_probe_commit( $dir, array( $path => $probes ), $tester );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
		'--require-probe',
	),
	'tier-3: no probe file fails'                      => array(
		1,
		'adds no probe file',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
		'--require-probe',
	),
	'tier-3: a non-.md file is not a probe'            => array(
		1,
		'adds no probe file',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'docs/review/probes/placeholder.txt' => "x\n" ), "Placeholder\n\nLab-Role: tester\n" );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
		'--require-probe',
	),
	'tier-3: a nested README is not a probe'           => array(
		1,
		'adds no probe file',
		static function ( $dir ) {
			keel_probe_commit( $dir, array( 'docs/review/probes/sub/README.md' => "Tester: Codex\nImplementer: Claude\n\n| P1 | a | b | c | d |\n" ), "Readme\n\nLab-Role: tester\n" );
			keel_probe_commit( $dir, array( 'keel.php' => "<?php // change\n" ), 'Build' );
		},
		'--require-probe',
	),
);

$made = array();
foreach ( $cases as $label => $case ) {
	list( $expected_code, $expected_text, $build ) = $case;
	$flags  = isset( $case[3] ) ? $case[3] : '';
	$dir    = keel_probe_repo();
	$made[] = $dir;
	$build( $dir );
	list( $code, $output ) = keel_probe_run( $dir, $flags );
	keel_assert( $expected_code === $code, "{$label}: expected exit {$expected_code}, got {$code}. Output: {$output}" );
	keel_assert( false !== stripos( $output, $expected_text ), "{$label}: expected output to mention \"{$expected_text}\". Output: {$output}" );
}

foreach ( $made as $dir ) {
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

if ( $fail ) {
	fwrite( STDERR, "{$fail} lab probes check assertion(s) failed.\n" );
	exit( 1 );
}

echo 'Lab probes check: ' . count( $cases ) . " cases passed.\n";
