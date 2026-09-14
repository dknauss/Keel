<?php
/**
 * The Keel <-> PX sync gate reads a pull request body correctly.
 *
 * Keel and Pixel Managed Platform share code paths, so a change to one usually needs
 * the same change in the other, or a recorded reason it does not. Ports used to
 * happen when somebody remembered to ask. bin/check-counterpart is what makes the
 * record mechanical: counterpart.yml runs it on every pull request event, and it
 * fails a body without exactly one valid `Counterpart:` line.
 *
 * Exercised as a black box, over stdin, the way the workflow runs it. See
 * docs/keel-px-sync.md.
 *
 * Run: php tests/counterpart-check.php
 *
 * @package keel
 */

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
 * Run the checker on a body.
 *
 * @param string $body Pull request body.
 * @return array{0:int,1:string} Exit code and combined output.
 */
function keel_counterpart_run( $body ) {
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- runs the checker as a CLI subprocess, outside WordPress, the way the workflow does.
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/check-counterpart' );
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	fwrite( $pipes[0], $body );
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	// phpcs:enable

	return array( proc_close( $process ), $output );
}

$template_comment = "<!--\nCounterpart: ported — owner/repo#123\nCounterpart: not applicable — why\n-->\n";

$valid = array(
	'ported, with an owner/repo reference' => "## Counterpart\n\nCounterpart: ported — we-are-pixel/pixel-experience#344\n",
	'pending, with a GitHub link'          => "Counterpart: pending — https://github.com/dknauss/Keel/issues/200 (needs the floor first)\n",
	'not applicable, with a reason'        => "Counterpart: not applicable — Keel-only test harness\n",
	'any case, and an ASCII hyphen'        => "Counterpart: Not applicable - docs only\n",
	'the template examples in a comment'   => $template_comment . "Counterpart: ported — dknauss/Keel#196\n",
);

foreach ( $valid as $label => $body ) {
	list( $code, $output ) = keel_counterpart_run( $body );
	keel_assert( 0 === $code, "A body with {$label} passes. Output: " . trim( $output ) );
}

$invalid = array(
	'an empty body'                   => array( '', 'No "Counterpart:" line' ),
	'only the template examples'      => array( $template_comment, 'No "Counterpart:" line' ),
	'two counterpart lines'           => array( "Counterpart: ported — dknauss/Keel#1\nCounterpart: not applicable — docs only\n", 'exactly one' ),
	'an unknown kind'                 => array( "Counterpart: maybe — later\n", '"ported", "pending", or "not applicable"' ),
	'ported without a reference'      => array( "Counterpart: ported — the PX one\n", 'owner/repo#N' ),
	'pending without a tracker'       => array( "Counterpart: pending — later\n", 'owner/repo#N' ),
	'not applicable without a reason' => array( "Counterpart: not applicable — n/a\n", 'reason' ),
	'a template placeholder left in'  => array( "Counterpart: ported — <owner/repo#N>\n", 'placeholder' ),
);

foreach ( $invalid as $label => $case ) {
	list( $body, $expected ) = $case;
	list( $code, $output )   = keel_counterpart_run( $body );
	keel_assert( 1 === $code, "A body with {$label} fails. Exit: {$code}" );
	keel_assert( false !== strpos( $output, $expected ), "The failure for {$label} says why ({$expected}). Output: " . trim( $output ) );
}

if ( $fail > 0 ) {
	fwrite( STDERR, "counterpart check: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'counterpart check: OK (' . count( $valid ) . ' valid, ' . count( $invalid ) . " invalid bodies)\n" );
