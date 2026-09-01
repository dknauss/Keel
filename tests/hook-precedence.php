<?php
/**
 * Hooks whose priority is load-bearing keep the priority they need.
 *
 * A registration can be on the right hook, with a correct callback, and still
 * never decide anything — because something else answered first, or answered
 * last. Two of Keel's own defaults shipped that way in 0.6.0:
 *
 *   - /?author=N kept disclosing the author nicename while /author/slug/
 *     redirected correctly, because core registers redirect_canonical on
 *     template_redirect at the default 10 in default-filters.php, during load
 *     and before any plugin file is read. An equal priority loses the tie on
 *     registration order, every time.
 *   - Attachment pages rendered instead of redirecting, for the same reason.
 *
 * The direction differs by hook and is not guessable, which is why this is a
 * map rather than a rule. A redirect has to be early to pre-empt core. A policy
 * clamp has to be late, or a plugin filtering at the default priority lands
 * after it and quietly wins — which is the case a site sets the policy to
 * prevent.
 *
 * The unit suite cannot see any of this: its add_action() is a no-op stub, so
 * registrations are not observable and only callbacks get tested. Where
 * correctness lives in *how* something is registered, the guard reads source.
 *
 * Add a hook here when its priority carries an argument. Say the direction and
 * say why; the "why" is printed when the assertion fails.
 *
 * Run: php tests/hook-precedence.php
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

$precedence = array(
	'template_redirect'      => array(
		'direction' => 'before',
		'than'      => 10,
		'why'       => "core's redirect_canonical is registered on this hook at 10, during load, so an equal priority loses the tie and core answers first",
	),
	'auth_cookie_expiration' => array(
		'direction' => 'after',
		'than'      => 10,
		'why'       => 'this is a policy clamp and has to be the last word, or a plugin filtering at the default priority lands after it and wins',
	),
);

/**
 * Split a call's arguments on top-level commas.
 *
 * The priority is the THIRD argument, not the last one: add_filter() takes
 * ( $hook, $callback, $priority, $accepted_args ), so reading the last number
 * finds accepted_args on any four-argument registration.
 *
 * @param string $args Argument text between the outer parentheses.
 * @return string[]
 */
function keel_split_args( $args ) {
	$out   = array();
	$depth = 0;
	$buf   = '';
	$len   = strlen( $args );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $args[ $i ];

		if ( '(' === $ch || '[' === $ch ) {
			++$depth;
		} elseif ( ')' === $ch || ']' === $ch ) {
			--$depth;
		}

		if ( ',' === $ch && 0 === $depth ) {
			$out[] = trim( $buf );
			$buf   = '';
			continue;
		}

		$buf .= $ch;
	}

	$out[] = trim( $buf );

	return $out;
}

$files   = glob( $root . '/includes/*.php' );
$checked = 0;

foreach ( $files as $file ) {
	$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source in a CLI test.

	foreach ( $precedence as $hook => $rule ) {
		$offset = 0;

		while ( true ) {
			$at = strpos( $text, "'" . $hook . "'", $offset );
			if ( false === $at ) {
				break;
			}
			$offset = $at + 1;

			// Walk back to the registering call, if this occurrence is one.
			$head = substr( $text, max( 0, $at - 200 ), min( 200, $at ) );
			if ( ! preg_match( '/(add_action|add_filter|keel_defaults_add_policy_filter)\s*\($/', preg_replace( '/\s+/', '', $head ) . '(' ) ) {
				if ( ! preg_match( '/(add_action|add_filter|keel_defaults_add_policy_filter)\s*\(\s*$/s', $head ) ) {
					continue;
				}
			}

			// Match parentheses from the opening one to find the whole call.
			$open = strrpos( substr( $text, 0, $at ), '(' );
			if ( false === $open ) {
				continue;
			}

			$depth = 0;
			$close = $open;
			$len   = strlen( $text );

			for ( $c = $open; $c < $len; $c++ ) {
				if ( '(' === $text[ $c ] ) {
					++$depth;
				} elseif ( ')' === $text[ $c ] ) {
					--$depth;
					if ( 0 === $depth ) {
						$close = $c;
						break;
					}
				}
			}

			$args = keel_split_args( preg_replace( '#//[^\n]*#', '', substr( $text, $open + 1, $close - $open - 1 ) ) );
			$line = substr_count( substr( $text, 0, $at ), "\n" ) + 1;
			++$checked;

			$has      = isset( $args[2] ) && preg_match( '/^\d+$/', trim( $args[2] ) );
			$priority = $has ? (int) trim( $args[2] ) : 10;

			keel_assert(
				$has,
				sprintf(
					'%s:%d registers on %s without an explicit priority, so it runs at 10 — %s',
					basename( $file ),
					$line,
					$hook,
					$rule['why']
				)
			);

			$ok = 'before' === $rule['direction']
				? $priority < $rule['than']
				: $priority > $rule['than'];

			keel_assert(
				$ok,
				sprintf(
					'%s:%d registers on %s at priority %d, which is not %s %d — %s',
					basename( $file ),
					$line,
					$hook,
					$priority,
					$rule['direction'],
					$rule['than'],
					$rule['why']
				)
			);
		}
	}
}

keel_assert( $checked > 0, 'the scan found registrations on the hooks it guards' );

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "hook precedence: OK ({$checked} registrations on " . count( $precedence ) . " load-bearing hooks)\n";
