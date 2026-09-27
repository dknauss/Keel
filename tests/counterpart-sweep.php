<?php
/**
 * The counterpart sweep finds a pending port wherever the line sits in the body.
 *
 * The filter anchored its regex with a bare ^, which in jq matched only at the
 * very start of the body, so it caught only a body that *began* with
 * "Counterpart: pending". The template puts the line after "What changed" and
 * "Verification", so the release gate reported "No pending counterparts" for
 * every release whatever the PRs said. Found when marking a merged PR pending
 * made no difference to the sweep.
 *
 * The script reads a fixture instead of calling gh when COUNTERPART_SWEEP_JSON
 * is set, so this runs offline.
 *
 * @package Keel
 */

function keel_sweep_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

/**
 * Run the sweep against fixture PR bodies.
 *
 * @param string[] $bodies PR bodies.
 * @return array{0:int,1:string} Exit code and output.
 */
function keel_sweep_run( array $bodies ) {
	$prs = array();
	foreach ( $bodies as $i => $body ) {
		$prs[] = array(
			'number' => 900 + $i,
			'title'  => "Fixture {$i}",
			'url'    => "https://example.test/pull/{$i}",
			'body'   => $body,
		);
	}

	$fixture = tempnam( sys_get_temp_dir(), 'sweep' );
	file_put_contents( $fixture, json_encode( $prs ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI test, no WordPress loaded.

	$script = escapeshellarg( dirname( __DIR__ ) . '/bin/counterpart-sweep' );
	exec( 'COUNTERPART_SWEEP_JSON=' . escapeshellarg( $fixture ) . " bash {$script} v0.0.0-fixture 2>&1", $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- runs the script under test.
	unlink( $fixture ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- CLI test, no WordPress loaded.

	return array( $code, implode( "\n", $out ) );
}

$template = "## What changed\n\nStuff.\n\n## Verification\n\nRan it.\n\n## Counterpart\n\n<!--\n  Counterpart: pending — dknauss/Keel#123 (example)\n-->\n\n";

list( $code, $out ) = keel_sweep_run( array( $template . 'Counterpart: pending — we-are-pixel/pixel-experience#1 (left to do)' ) );
keel_sweep_assert( 1 === $code, 'A pending line after the other sections fails the sweep.' );
keel_sweep_assert( false !== strpos( $out, 'we-are-pixel/pixel-experience#1' ), 'And the sweep names the pending port.' );

// The reported line is the real one, not a mid-sentence mention earlier on.
list( $code, $out ) = keel_sweep_run( array( "## What changed\n\nThis used to read Counterpart: pending — decoy#9 in prose.\n\n## Counterpart\n\nCounterpart: pending — real#7 (left to do)" ) );
keel_sweep_assert( 1 === $code, 'A body with a real pending line fails the sweep.' );
keel_sweep_assert(
	false !== strpos( $out, 'real#7' ) && false === strpos( $out, 'decoy#9' ),
	'The sweep reports the line that starts a line, not a mention inside a sentence: ' . $out
);

list( $code, ) = keel_sweep_run( array( 'Counterpart: pending — x#1' ) );
keel_sweep_assert( 1 === $code, 'A pending line at the very start still fails the sweep.' );

list( $code, $out ) = keel_sweep_run( array( $template . 'Counterpart: not applicable — no such path.', $template . 'Counterpart: ported — x#2' ) );
keel_sweep_assert( 0 === $code, 'Only the template\'s commented example says pending, so the sweep passes: ' . $out );

fwrite( STDOUT, "counterpart sweep tests passed.\n" );
