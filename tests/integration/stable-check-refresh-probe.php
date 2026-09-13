<?php
/**
 * Stage release day on a real site and report whether Keel noticed.
 *
 * The status map is cached for a day, and the one day that matters is the day a
 * release lands: the version the site runs has just become insecure while the
 * cached map, fetched the day before, still calls it latest. Keel refreshes the
 * map when core stores update offers that name a release the map does not list.
 * The unit test pins that decision against a stubbed transient store; only a real
 * site can show that core's own wp_version_check() actually fires the hook, with
 * the transient shape the decision expects, and that the refetch lands.
 *
 * Release day is staged, not waited for. The real map is fetched, then every
 * release core is currently offering is removed from it and the running version
 * is marked latest — which is exactly what the map looked like before those
 * releases existed. The site then runs core's update check, unmodified.
 *
 * Emits JSON rather than asserting, so the shell owns the verdict.
 *
 * Run only through WP-CLI in a disposable integration site.
 *
 * @package keel
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/update.php';

$keel_requests = 0;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$keel_requests ) {
		if ( 0 === strpos( $url, KEEL_DEFAULTS_STABLE_CHECK_URL ) ) {
			++$keel_requests;
		}
		return $pre;
	},
	10,
	3
);

// Start from nothing Keel cached, and let core fetch current offers. With no map
// cached, Keel must stay out of core's update check entirely.
delete_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );
delete_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED );
wp_version_check( array(), true );
$uncached_requests = $keel_requests;

$real    = keel_defaults_stable_check();
$current = get_site_transient( 'update_core' );
$offered = array();

if ( is_object( $current ) && isset( $current->updates ) && is_array( $current->updates ) ) {
	foreach ( $current->updates as $offer ) {
		if ( isset( $offer->current ) ) {
			$offered[] = $offer->current;
		}
	}
}

$yesterday = array_diff_key( $real, array_flip( $offered ) );

$yesterday[ keel_defaults_wp_version() ] = 'latest';
set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, $yesterday, KEEL_DEFAULTS_STABLE_CHECK_TTL );

$staged_status = keel_defaults_version_status();
$staged_tip    = keel_defaults_branch_tip();

// Release day: core's own update check, unmodified.
$keel_requests = 0;
wp_version_check( array(), true );
$refresh_requests = $keel_requests;
$refreshed        = get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );
$after_status     = keel_defaults_version_status();
$after_tip        = keel_defaults_branch_tip();

// The day after: the map lists every offer, so core's next check costs nothing.
$keel_requests = 0;
wp_version_check( array(), true );
$quiet_requests = $keel_requests;

echo wp_json_encode(
	array(
		'version'           => keel_defaults_wp_version(),
		'offered'           => $offered,
		'real_count'        => count( $real ),
		'uncached_requests' => $uncached_requests,
		'staged_status'     => $staged_status,
		'staged_tip'        => $staged_tip,
		'refresh_requests'  => $refresh_requests,
		'refreshed_matches' => $refreshed === $real,
		'after_status'      => $after_status,
		'after_tip'         => $after_tip,
		'quiet_requests'    => $quiet_requests,
	)
);
