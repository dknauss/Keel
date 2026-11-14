<?php
/**
 * The maintainer sign-off gate trusts only a hand-made label from a maintainer.
 *
 * bin/check-maintainer-signoff backs two required jobs in lab-probes.yml:
 * "Guardrail changes reviewed" and "Maintainer sign-off". It decides from
 * GitHub's own records (issue events for who added a label and through which
 * app, repository activity for who pushed and when) whether a maintainer
 * approved what is on the branch now. An agent account with Write can add
 * labels and push, and cloud agent sessions act as the maintainer through a
 * GitHub App, so neither may count.
 *
 * Exercised as a black box: a throwaway git repository for the diffs and a stub
 * `gh` on PATH that serves the API answers for each case.
 *
 * Run: php tests/maintainer-signoff-check.php
 *
 * @package keel
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec, WordPress.WP.AlternativeFunctions, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- builds a throwaway git repository and a stub gh, and runs the checker as a CLI subprocess outside WordPress, the way the workflow does.

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
 * Run a shell command in a directory and return its trimmed output, or die.
 *
 * @param string $dir     Working directory.
 * @param string $command Command.
 * @return string
 */
function keel_signoff_sh( $dir, $command ) {
	exec( 'cd ' . escapeshellarg( $dir ) . ' && ' . $command . ' 2>&1', $out, $status );
	if ( 0 !== $status ) {
		fwrite( STDERR, "Setup failed: {$command}\n" . implode( "\n", $out ) . "\n" );
		exit( 1 );
	}
	return trim( implode( "\n", $out ) );
}

$root    = dirname( __DIR__ );
$checker = $root . '/bin/check-maintainer-signoff';
$tmp     = sys_get_temp_dir() . '/keel-signoff-' . getmypid();
$repo    = $tmp . '/repo';
$fx      = $tmp . '/fx';
$bin     = $tmp . '/bin';
mkdir( $repo, 0700, true );
mkdir( $fx, 0700, true );
mkdir( $bin, 0700, true );

// History: B (base) -> C1 code -> C2 guardrail file -> C3 code only.
keel_signoff_sh( $repo, 'git init -q && git config user.email t@example.com && git config user.name T' );
file_put_contents( $repo . '/plugin.php', "<?php\n" );
keel_signoff_sh( $repo, 'git add -A && git commit -qm base' );
$sha = array( 'B' => keel_signoff_sh( $repo, 'git rev-parse HEAD' ) );
file_put_contents( $repo . '/plugin.php', "<?php // one\n" );
keel_signoff_sh( $repo, 'git commit -qam c1' );
$sha['C1'] = keel_signoff_sh( $repo, 'git rev-parse HEAD' );
mkdir( $repo . '/.github/workflows', 0700, true );
file_put_contents( $repo . '/.github/workflows/ci.yml', "on: push\n" );
keel_signoff_sh( $repo, 'git add -A && git commit -qm c2' );
$sha['C2'] = keel_signoff_sh( $repo, 'git rev-parse HEAD' );
file_put_contents( $repo . '/plugin.php', "<?php // three\n" );
keel_signoff_sh( $repo, 'git commit -qam c3' );
$sha['C3'] = keel_signoff_sh( $repo, 'git rev-parse HEAD' );

// Stub gh: serves fixture files by API path and logs label removals.
file_put_contents(
	$bin . '/gh',
	'#!/usr/bin/env bash
fx="' . $fx . '"
args="$*"
case "$args" in
  *"-X DELETE"*) echo "$args" >> "$fx/deleted"; exit 0 ;;
  *"/activity"*) cat "$fx/activity.json" ;;
  *"/events"*) cat "$fx/events.json" ;;
  *"/pulls/"*) cat "$fx/pr.json" ;;
  *) echo "stub gh: unexpected $args" >&2; exit 1 ;;
esac
'
);
chmod( $bin . '/gh', 0755 );

/**
 * A label event.
 *
 * @param int    $id    Event ID (ordering).
 * @param string $event labeled or unlabeled.
 * @param string $label Label name.
 * @param string $actor Login.
 * @param string $at    Timestamp.
 * @param string $app   App slug, or '' for a hand-made event.
 * @return array
 */
function keel_label_event( $id, $event, $label, $actor, $at, $app = '' ) {
	return array(
		'id'                       => $id,
		'event'                    => $event,
		'label'                    => array( 'name' => $label ),
		'actor'                    => array( 'login' => $actor ),
		'created_at'               => $at,
		'performed_via_github_app' => '' === $app ? null : array( 'slug' => $app ),
	);
}

/**
 * A push record from the activity API.
 *
 * @param string $type   push or branch_creation.
 * @param string $actor  Login.
 * @param string $at     Timestamp.
 * @param string $before SHA before.
 * @param string $after  SHA after.
 * @return array
 */
function keel_push( $type, $actor, $at, $before, $after ) {
	return array(
		'activity_type' => $type,
		'actor'         => array( 'login' => $actor ),
		'timestamp'     => $at,
		'before'        => $before,
		'after'         => $after,
	);
}

$z  = str_repeat( '0', 40 );
$gr = 'guardrail-reviewed';
$ma = 'maintainer-approved';

// The usual branch: created at B, then C1, C2, C3 pushed by the maintainer.
$own = array(
	keel_push( 'branch_creation', 'dknauss', '2026-11-14T10:00:00Z', $z, $sha['B'] ),
	keel_push( 'push', 'dknauss', '2026-11-14T10:01:00Z', $sha['B'], $sha['C1'] ),
	keel_push( 'push', 'dknauss', '2026-11-14T10:02:00Z', $sha['C1'], $sha['C2'] ),
);
$bot_c2 = array(
	keel_push( 'branch_creation', 'dknauss', '2026-11-14T10:00:00Z', $z, $sha['B'] ),
	keel_push( 'push', 'dknauss', '2026-11-14T10:01:00Z', $sha['B'], $sha['C1'] ),
	keel_push( 'push', 'dknauss-bot', '2026-11-14T10:02:00Z', $sha['C1'], $sha['C2'] ),
);

// mode, head, events, activity, expected exit, expected text, [fork, action].
$cases = array(
	'no guardrail file changed' => array( 'guardrail', 'C1', array(), $own, 0, 'No guardrail files changed' ),
	'guardrail change, no label' => array( 'guardrail', 'C2', array(), $own, 1, 'nobody has added' ),
	'guardrail label added by the agent account' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss-bot', '2026-11-14T10:05:00Z' ) ), $own, 1, 'not a maintainer' ),
	'guardrail label added by an agent session acting as the maintainer' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:05:00Z', 'claude' ) ), $own, 1, 'through the claude app' ),
	'guardrail label added by hand after the last push' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:05:00Z' ) ), $own, 0, 'by hand' ),
	'guardrail label removed again' => array(
		'guardrail',
		'C2',
		array(
			keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:05:00Z' ),
			keel_label_event( 2, 'unlabeled', $gr, 'dknauss-bot', '2026-11-14T10:06:00Z' ),
		),
		$own,
		1,
		'was removed',
	),
	'agent re-adds the label after the maintainer removed it' => array(
		'guardrail',
		'C2',
		array(
			keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:05:00Z' ),
			keel_label_event( 2, 'unlabeled', $gr, 'dknauss', '2026-11-14T10:06:00Z' ),
			keel_label_event( 3, 'labeled', $gr, 'dknauss-bot', '2026-11-14T10:07:00Z' ),
		),
		$own,
		1,
		'not a maintainer',
	),
	'guardrail file pushed after the label (even if that run was cancelled)' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:01:30Z' ) ), $own, 1, 'changed after the label' ),
	'push in the same second as the label counts as after it' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:02:00Z' ) ), $own, 1, 'changed after the label' ),
	'code-only push after the label keeps it' => array(
		'guardrail',
		'C3',
		array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:03:00Z' ) ),
		array_merge( $own, array( keel_push( 'push', 'dknauss', '2026-11-14T10:04:00Z', $sha['C2'], $sha['C3'] ) ) ),
		0,
		'no guardrail file has changed since',
	),
	'label older than every recorded push' => array( 'guardrail', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T09:00:00Z' ) ), $own, 1, "can't tell which commit" ),
	'only maintainer pushes need no sign-off' => array( 'pushes', 'C2', array(), $own, 0, 'No extra sign-off needed' ),
	'agent push with no sign-off' => array( 'pushes', 'C2', array(), $bot_c2, 1, 'dknauss-bot' ),
	'agent push signed off by an agent session as the maintainer' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss', '2026-11-14T10:05:00Z', 'claude' ) ), $bot_c2, 1, 'through the claude app' ),
	'agent push signed off by the agent itself' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss-bot', '2026-11-14T10:05:00Z' ) ), $bot_c2, 1, 'not a maintainer' ),
	'agent push signed off by hand afterwards' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss', '2026-11-14T10:05:00Z' ) ), $bot_c2, 0, 'after the last push by anyone else' ),
	'agent push after the sign-off' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss', '2026-11-14T10:01:30Z' ) ), $bot_c2, 1, 'predates a push' ),
	'guardrail-reviewed does not stand in for maintainer-approved' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $gr, 'dknauss', '2026-11-14T10:05:00Z' ) ), $bot_c2, 1, 'nobody has added' ),
	'push record does not reach the branch creation' => array( 'pushes', 'C2', array(), array_slice( $own, 1 ), 1, "doesn't reach back" ),
	'fork pull request without sign-off' => array( 'pushes', 'C2', array(), array(), 1, 'nobody has added', true, 'opened' ),
	'fork pushes after the sign-off' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss', '2026-11-14T10:05:00Z' ) ), array(), 1, 'fork pushed new commits', true, 'synchronize' ),
	'fork signed off by hand' => array( 'pushes', 'C2', array( keel_label_event( 1, 'labeled', $ma, 'dknauss', '2026-11-14T10:05:00Z' ) ), array(), 0, 'Signed off', true, 'labeled' ),
);

foreach ( $cases as $name => $case ) {
	list( $mode, $head, $events, $activity, $expected_code, $expected_text ) = $case;
	$fork   = isset( $case[6] ) ? $case[6] : false;
	$action = isset( $case[7] ) ? $case[7] : 'labeled';
	if ( file_exists( $fx . '/deleted' ) ) {
		unlink( $fx . '/deleted' );
	}
	file_put_contents( $fx . '/events.json', json_encode( $events ) );
	file_put_contents( $fx . '/activity.json', json_encode( $activity ) );
	file_put_contents(
		$fx . '/pr.json',
		json_encode(
			array(
				'head' => array(
					'ref'  => 'claude/topic',
					'repo' => array( 'full_name' => $fork ? 'someone/fork' : 'owner/repo' ),
				),
			)
		)
	);
	$env    = array(
		'PATH'                => $bin . ':' . getenv( 'PATH' ),
		'GH_REPO'             => 'owner/repo',
		'PR_NUMBER'           => '7',
		'BASE_SHA'            => $sha['B'],
		'HEAD_SHA'            => $sha[ $head ],
		'MAINTAINERS'         => 'dknauss',
		'ACTION'              => $action,
		'GITHUB_STEP_SUMMARY' => '/dev/null',
	);
	$prefix = '';
	foreach ( $env as $k => $v ) {
		$prefix .= $k . '=' . escapeshellarg( $v ) . ' ';
	}
	exec( 'cd ' . escapeshellarg( $repo ) . ' && env ' . $prefix . escapeshellarg( $checker ) . ' ' . $mode . ' 2>&1', $out, $code );
	$output = implode( "\n", $out );
	$out    = array();
	keel_assert( $expected_code === $code, "{$name}: expected exit {$expected_code}, got {$code}. Output: {$output}" );
	keel_assert( false !== stripos( $output, $expected_text ), "{$name}: expected output to mention \"{$expected_text}\". Output: {$output}" );
	// A stale or forged approval must never be left in place to satisfy a later run.
	if ( 1 === $expected_code && ( false !== stripos( $output, 'after the label' ) || false !== stripos( $output, 'predates' ) || false !== stripos( $output, 'fork pushed' ) ) ) {
		keel_assert( file_exists( $fx . '/deleted' ), "{$name}: expected the stale label to be removed." );
	}
}

exec( 'rm -rf ' . escapeshellarg( $tmp ) );

if ( $fail ) {
	fwrite( STDERR, "{$fail} maintainer sign-off assertion(s) failed.\n" );
	exit( 1 );
}

echo 'Maintainer sign-off check: ' . count( $cases ) . " cases passed.\n";
