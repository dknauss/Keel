<?php
/**
 * What the conflict observer costs an ordinary request, on a real install.
 *
 * The divergence observer is the only part of the conflict diagnostics that runs
 * on the front end, and it runs on every request, so its healthy-path cost is
 * the cost of the feature. The budget in docs/review/performance-budget.md says
 * that cost is zero: no queries, no reads of the divergence record, no writes.
 * tests/policy-divergence.php proves the comparison against stubs. This proves
 * it against real WordPress, through whatever database and object cache the
 * install has, which the stubs cannot see.
 *
 * Reads are counted at `pre_transient_keel_policy_divergence` as well as in
 * queries. Under a persistent object cache a transient read costs no query, so a
 * query count alone would pass a read that the budget forbids.
 *
 * The second half is the control: a rival forcing one setting the other way must
 * be seen, recorded once, and not rewritten on every later call. Without it, a
 * counter that never fired would pass the first half too.
 *
 * Run: wp eval-file tests/integration/conflict-hot-path.php
 * Changes no settings. It clears the divergence transient before and after and
 * writes one during the control, so lab only.
 *
 * @package keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_observe_policy_result' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

$assert = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};

// Hook => arguments after the value, as core passes them.
$arguments    = array(
	'xmlrpc_enabled'                        => array(),
	'comments_open'                         => array( 0 ),
	'pings_open'                            => array( 0 ),
	'wp_is_application_passwords_available' => array(),
	'wp_supports_ai'                        => array(),
);
$expectations = keel_defaults_policy_expectations();
$watched      = array_intersect_key( $expectations, $arguments );

$assert( array() !== $watched, 'No governed hook is watched with the current settings, so there is nothing to measure.' );
$assert( count( $watched ) === count( $expectations ), 'A watched hook has no argument list here: ' . implode( ', ', array_keys( array_diff_key( $expectations, $arguments ) ) ) );

foreach ( array_keys( $watched ) as $hook ) {
	$assert( false !== has_filter( $hook ) && ! empty( $GLOBALS['wp_filter'][ $hook ]->callbacks[ PHP_INT_MAX ] ), "No observer is registered on {$hook}." );
}

$count = array(
	'queries' => 0,
	'reads'   => 0,
	'writes'  => 0,
);

$on_query = static function ( $sql ) use ( &$count ) {
	++$count['queries'];
	return $sql;
};
$on_read  = static function ( $pre ) use ( &$count ) {
	++$count['reads'];
	return $pre;
};
$on_write = static function () use ( &$count ) {
	++$count['writes'];
};

$apply = static function ( $hook, $value ) use ( $arguments ) {
	return apply_filters( $hook, $value, ...$arguments[ $hook ] );
};

// Start from no record, so a divergence left by an earlier step cannot hide one.
delete_transient( 'keel_policy_divergence' );

$queries_before = $GLOBALS['wpdb']->num_queries;
add_filter( 'query', $on_query, -999 );
add_filter( 'pre_transient_keel_policy_divergence', $on_read, -999 );
add_action( 'setted_transient', $on_write );

try {
	/*
	 * Fifty passes, because comments_open and pings_open fire once per post in a
	 * loop. A cost that appeared only on a later call would hide in one pass.
	 */
	for ( $i = 0; $i < 50; $i++ ) {
		foreach ( $watched as $hook => $expected ) {
			$result = $apply( $hook, ! $expected );
			$assert( (bool) $expected === (bool) $result, "{$hook} did not settle on Keel's setting; something on this install overrides it, so the healthy path cannot be measured here." );
		}
	}

	$queries = max( $count['queries'], $GLOBALS['wpdb']->num_queries - $queries_before );
	$assert( 0 === $queries, "The healthy path ran {$queries} queries." );
	$assert( 0 === $count['reads'], "The healthy path read the divergence record {$count['reads']} times." );
	$assert( 0 === $count['writes'], "The healthy path wrote {$count['writes']} transients." );

	/*
	 * The control. One rival at the priority just below the observer, so it
	 * runs last of everything but Keel's own watcher.
	 */
	$hook     = array_key_first( $watched );
	$expected = (bool) $watched[ $hook ];
	$rival    = static function () use ( $expected ) {
		return ! $expected;
	};
	add_filter( $hook, $rival, PHP_INT_MAX - 1 );

	try {
		$apply( $hook, $expected );
		$assert( 1 === $count['writes'], "A divergence on {$hook} was written {$count['writes']} times; expected once." );
		$assert( $count['reads'] >= 1, "A divergence on {$hook} was recorded without reading the record first." );

		$record = get_transient( 'keel_policy_divergence' );
		$assert( is_array( $record ) && isset( $record['hooks'][ $hook ] ), "The divergence on {$hook} is not in the record." );

		for ( $i = 0; $i < 49; $i++ ) {
			$apply( $hook, $expected );
		}
		$assert( 1 === $count['writes'], "A standing divergence on {$hook} was rewritten: {$count['writes']} writes in 50 calls." );
	} finally {
		remove_filter( $hook, $rival, PHP_INT_MAX - 1 );
	}
} finally {
	remove_filter( 'query', $on_query, -999 );
	remove_filter( 'pre_transient_keel_policy_divergence', $on_read, -999 );
	remove_action( 'setted_transient', $on_write );
	delete_transient( 'keel_policy_divergence' );
}

$cache = wp_using_ext_object_cache() ? 'persistent object cache' : 'no persistent object cache';
$site  = is_multisite() ? 'multisite' : 'single site';
WP_CLI::success( sprintf( 'Conflict observer: %d watched hooks, zero queries, reads or writes on the healthy path (%s, %s).', count( $watched ), $site, $cache ) );
