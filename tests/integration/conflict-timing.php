<?php
/**
 * How long the conflict diagnostics take, on whatever install runs this.
 *
 * Two numbers, the same two the performance budget tracks:
 *
 *   - Observer overhead. The only diagnostic code on the front end is the
 *     divergence observer on each watched hook. This times each hook through
 *     its real filter chain with the observer and again without it. The
 *     difference is what the observer costs a call. comments_open and
 *     pings_open fire once per post in a loop, so this is a per-call figure,
 *     not a per-page one. It also times keel_defaults_policy_expectations() on
 *     its own: the observer rebuilds that array on every call, and this says
 *     whether caching it is worth the staleness a static copy would bring
 *     across switch_to_blog() and mid-request saves.
 *   - Crowded overlap report. Forty fixture plugins each put a passthrough
 *     callback on five governed hooks, as in the 2026-10-24 release-candidate
 *     pass. Then this times a forced keel_defaults_policy_overlap_report( true ),
 *     the admin-only scan that names them. The budget is under 20 ms.
 *
 * Report only. A shared CI runner is too noisy for a timing gate, and the budget
 * says timings are investigation thresholds. The one assertion is that the
 * fixture really produced the crowd, so a run cannot report a fast scan of
 * nothing.
 *
 * Run: wp eval-file tests/integration/conflict-timing.php
 * Writes fixture plugins into wp-content/plugins and adds them to the active
 * list for the duration of the run, then removes both. Lab only.
 *
 * @package keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_policy_overlap_report' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

$median = static function ( array $samples ) {
	sort( $samples );
	$n = count( $samples );
	return $n % 2 ? $samples[ intdiv( $n, 2 ) ] : ( $samples[ $n / 2 - 1 ] + $samples[ $n / 2 ] ) / 2;
};

$arguments = array(
	'xmlrpc_enabled'                        => array(),
	'comments_open'                         => array( 0 ),
	'pings_open'                            => array( 0 ),
	'wp_is_application_passwords_available' => array(),
	'wp_supports_ai'                        => array(),
);
$watched   = array_intersect_key( keel_defaults_policy_expectations(), $arguments );
$calls     = 20000;
$rounds    = 7;
$result    = array(
	'site'         => is_multisite() ? 'multisite' : 'single',
	'object_cache' => wp_using_ext_object_cache() ? 'persistent' : 'default',
	'db'           => $GLOBALS['wpdb']->db_server_info(),
	'php'          => PHP_VERSION,
	'calls'        => $calls,
	'rounds'       => $rounds,
	'observer_ns'  => array(),
);

/*
 * Observer overhead, in nanoseconds per call. The observers are the closures
 * Keel added at PHP_INT_MAX; they are taken off and put back by identity, so
 * nothing else on the hook moves.
 */
foreach ( $watched as $hook => $expected ) {
	$observers = array();
	foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ PHP_INT_MAX ] ?? array() as $entry ) {
		if ( $entry['function'] instanceof Closure ) {
			$observers[] = $entry['function'];
		}
	}
	if ( array() === $observers ) {
		throw new RuntimeException( "No observer is registered on {$hook}." );
	}

	$time = static function () use ( $hook, $expected, $arguments, $calls ) {
		$start = hrtime( true );
		for ( $i = 0; $i < $calls; $i++ ) {
			apply_filters( $hook, ! $expected, ...$arguments[ $hook ] );
		}
		return ( hrtime( true ) - $start ) / $calls;
	};

	$with    = array();
	$without = array();
	$time(); // Warm-up.
	for ( $r = 0; $r < $rounds; $r++ ) {
		$with[] = $time();
		foreach ( $observers as $observer ) {
			remove_filter( $hook, $observer, PHP_INT_MAX );
		}
		$without[] = $time();
		foreach ( $observers as $observer ) {
			add_filter( $hook, $observer, PHP_INT_MAX, 1 );
		}
	}

	$result['observer_ns'][ $hook ] = array(
		'with'    => round( $median( $with ) ),
		'without' => round( $median( $without ) ),
		'added'   => round( $median( $with ) - $median( $without ) ),
	);
}

$expectations = array();
for ( $r = 0; $r < $rounds; $r++ ) {
	$start = hrtime( true );
	for ( $i = 0; $i < $calls; $i++ ) {
		keel_defaults_policy_expectations();
	}
	$expectations[] = ( hrtime( true ) - $start ) / $calls;
}
$result['expectations_ns'] = round( $median( $expectations ) );

/*
 * Where the rebuild goes: one settings read, and one full setting lookup. If the
 * read is most of it, a cache keyed on the stored values saves little; if the
 * lookup is, it saves most.
 */
foreach ( array(
	'get_option_ns'      => static function () {
		return get_option( KEEL_DEFAULTS_OPTION, array() );
	},
	'enabled_ns'         => static function () {
		return keel_defaults_enabled( 'disable_comments' );
	},
	'apply_filters_ns'   => static function () {
		return apply_filters( 'keel_policy_expectations', array() );
	},
) as $label => $probe ) {
	$samples = array();
	for ( $r = 0; $r < $rounds; $r++ ) {
		$start = hrtime( true );
		for ( $i = 0; $i < $calls; $i++ ) {
			$probe();
		}
		$samples[] = ( hrtime( true ) - $start ) / $calls;
	}
	$result[ $label ] = round( $median( $samples ) );
}

/*
 * The crowd. Each fixture is a real plugin file with its own named callbacks,
 * so attribution resolves it through the same Reflection and plugin roots it
 * would use for a real rival.
 */
$crowd   = 40;
$hooks   = array( 'xmlrpc_enabled', 'comments_open', 'pings_open', 'get_comments_number', 'wp_revisions_to_keep' );
$made    = array();
$option  = is_multisite() ? null : get_option( 'active_plugins', array() );
$active  = is_array( $option ) ? $option : array();
$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : null;

try {
	for ( $p = 1; $p <= $crowd; $p++ ) {
		$slug = "keel-timing-rival-{$p}";
		$dir  = WP_PLUGIN_DIR . "/{$slug}";
		$file = "{$dir}/{$slug}.php";
		$code = "<?php\n/**\n * Plugin Name: Keel timing rival {$p}\n */\n";
		foreach ( $hooks as $hook ) {
			$code .= sprintf( "function keel_timing_rival_%1\$d_%2\$s( \$value ) { return \$value; }\nadd_filter( '%2\$s', 'keel_timing_rival_%1\$d_%2\$s', 10, 1 );\n", $p, $hook );
		}
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			throw new RuntimeException( "Could not create {$dir}." );
		}
		file_put_contents( $file, $code );
		$made[] = "{$slug}/{$slug}.php";
		require $file;
	}

	if ( is_multisite() ) {
		update_site_option( 'active_sitewide_plugins', array_merge( (array) $network, array_fill_keys( $made, time() ) ) );
	} else {
		update_option( 'active_plugins', array_merge( $active, $made ) );
	}

	$report = keel_defaults_policy_overlap_report( true );
	$named  = 0;
	foreach ( $report['structural'] as $hook_plugins ) {
		foreach ( $hook_plugins as $plugin ) {
			if ( 0 === strpos( $plugin, 'keel-timing-rival-' ) ) {
				++$named;
			}
		}
	}
	if ( $named < $crowd ) {
		throw new RuntimeException( "The overlap report named {$named} fixture rivals; expected at least {$crowd}. The crowd did not form, so its timing would mean nothing." );
	}

	$scan = array();
	for ( $r = 0; $r < 12; $r++ ) {
		$start  = hrtime( true );
		keel_defaults_policy_overlap_report( true );
		$scan[] = ( hrtime( true ) - $start ) / 1e6;
	}
	$scan = array_slice( $scan, 2 ); // Two warm-ups, ten measured.

	$result['crowd'] = array(
		'plugins'      => $crowd,
		'hooks'        => count( $hooks ),
		'named_rivals' => $named,
		'report_ms'    => array(
			'median' => round( $median( $scan ), 2 ),
			'max'    => round( max( $scan ), 2 ),
		),
		'budget_ms'    => 20,
	);
} finally {
	if ( is_multisite() ) {
		update_site_option( 'active_sitewide_plugins', $network );
	} else {
		update_option( 'active_plugins', $active );
	}
	foreach ( $made as $plugin ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( $plugin );
		wp_delete_file( WP_PLUGIN_DIR . '/' . $plugin );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the fixture directory this run created.
	}
}

WP_CLI::log( 'conflict timing: ' . wp_json_encode( $result ) );
WP_CLI::success( sprintf( 'Conflict timing recorded (%s, %s object cache). Report only; no timing assertions.', $result['site'], $result['object_cache'] ) );
