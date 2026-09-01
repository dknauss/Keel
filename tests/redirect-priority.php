<?php
/**
 * Nothing races core's redirect_canonical on equal footing.
 *
 * Core registers redirect_canonical on template_redirect at the default
 * priority 10, and it does so in default-filters.php during load — before any
 * plugin file is read. A plugin that registers on the same hook at the same
 * priority therefore loses the tie on registration order, every time, and its
 * callback never gets to answer.
 *
 * That is not a theoretical ordering nicety. Two of Keel's redirects shipped at
 * the default priority and were silently pre-empted:
 *
 *   - /?author=N kept disclosing the author nicename, while /author/slug/
 *     redirected correctly, because core answered the query-string form first.
 *   - Attachment pages rendered instead of redirecting.
 *
 * Both were documented behaviours that did not happen, and the unit suite could
 * not see either: its add_action() is a no-op stub, so registrations are not
 * observable and only the callbacks themselves get tested. A source check is
 * what is left.
 *
 * The rule: every template_redirect registration in includes/ declares an
 * explicit priority below 10. If a future hook genuinely belongs after
 * redirect_canonical, that is a deliberate decision — make it here, in this
 * list, rather than by leaving the argument off.
 *
 * Run: php tests/redirect-priority.php
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

// Callbacks that are deliberately allowed to run at or after core's priority.
// Empty on purpose: nothing needs it yet, and adding a name here is a decision
// somebody has to write down.
$after_canonical = array();

$files = glob( $root . '/includes/*.php' );
$found = 0;

foreach ( $files as $file ) {
	$text  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source in a CLI test.
	$lines = explode( "\n", $text );

	foreach ( $lines as $i => $line ) {
		if ( false === strpos( $line, "'template_redirect'" ) ) {
			continue;
		}

		if ( false === strpos( $line, 'add_action' ) && false === strpos( $lines[ max( 0, $i - 1 ) ], 'add_action' ) ) {
			continue;
		}

		++$found;

		// Find the end of the add_action() call by matching parentheses, not by
		// looking for the first ');'. These registrations pass closures, whose
		// bodies contain calls of their own — a naive search stops inside the
		// closure and reports a missing priority that is several lines further
		// down. That is exactly the false positive this check must not produce.
		$open  = false === strpos( $line, 'add_action' ) ? $i - 1 : $i;
		$rest  = implode( "\n", array_slice( $lines, $open, 120 ) );
		$start = strpos( $rest, '(' );
		$depth = 0;
		$call  = $rest;

		$len = strlen( $rest );

		for ( $c = $start; $c < $len; $c++ ) {
			if ( '(' === $rest[ $c ] ) {
				++$depth;
			} elseif ( ')' === $rest[ $c ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$call = substr( $rest, 0, $c );
					break;
				}
			}
		}

		// Comments may sit between the last argument and the closing paren.
		$call = preg_replace( '#//[^\n]*#', '', $call );

		$has_priority = (bool) preg_match( '/,\s*(\d+)\s*$/m', rtrim( $call ) );
		$priority     = $has_priority ? (int) preg_replace( '/.*,\s*(\d+)\s*$/s', '$1', rtrim( $call ) ) : 10;

		keel_assert(
			$has_priority,
			sprintf(
				'%s:%d registers on template_redirect without an explicit priority, so it runs at 10 and loses to core\'s redirect_canonical',
				basename( $file ),
				$i + 1
			)
		);

		keel_assert(
			$priority < 10,
			sprintf(
				'%s:%d registers on template_redirect at priority %d; core\'s redirect_canonical is at 10 and was registered first',
				basename( $file ),
				$i + 1,
				$priority
			)
		);
	}
}

keel_assert( $found > 0, 'the scan found template_redirect registrations to check' );

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "redirect priority: OK ({$found} template_redirect registrations, all below 10)\n";
