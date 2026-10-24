<?php
/**
 * Security workflow contract: audit the locked graph, and only ask CodeQL for
 * languages it supports. A runner has no vendor directory before this job.
 *
 * Run: php tests/security-workflow.php
 *
 * @package keel
 */

$workflow = dirname( __DIR__ ) . '/.github/workflows/security.yml';
$fail     = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Whether the assertion passed.
 * @param string $message   Failure description.
 */
function keel_security_workflow_assert( $condition, $message ) {
	global $fail;

	if ( ! $condition ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$message}\n" );
	}
}

keel_security_workflow_assert( is_file( $workflow ), 'security.yml exists.' );
$source = is_file( $workflow ) ? file_get_contents( $workflow ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

keel_security_workflow_assert(
	false !== strpos( $source, 'composer audit --locked --no-interaction' ),
	'Composer audit reads composer.lock rather than requiring a vendor directory.'
);
keel_security_workflow_assert(
	false !== strpos( $source, 'npm audit --omit=dev --audit-level=high' ),
	'Production npm dependencies are audited at high severity or above.'
);
keel_security_workflow_assert(
	false !== strpos( $source, 'languages: javascript-typescript' ),
	'CodeQL analyzes browser JavaScript.'
);
keel_security_workflow_assert(
	false === strpos( $source, '- php' ),
	'CodeQL is not configured for unsupported PHP analysis.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "security workflow: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "security workflow: OK\n" );
