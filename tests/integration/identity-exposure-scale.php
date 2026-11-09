<?php
/**
 * Report-only cost run for the email-in-public-name check (#355, probes P16/P17).
 *
 * Run against a disposable site seeded with a large user table. Prints the
 * candidate scan's query count, the SQL it ran, its plan, elapsed time and
 * memory, cold and then warm, and the named-list cap. Fails only on contract
 * breaks: more than three queries cold, any exposure query warm, a named list
 * over the cap, or an address in the result.
 *
 * Run: wp eval-file tests/integration/identity-exposure-scale.php
 *
 * @package Keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_identity_exposure_scan' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

global $wpdb;
$keel_scale_log   = array();
$keel_scale_tap   = static function ( $sql ) use ( &$keel_scale_log ) {
	$keel_scale_log[] = $sql;
	return $sql;
};
$keel_scale_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $keel_scale_admin[0] );

$keel_scale_run = static function ( $label ) use ( &$keel_scale_log, $keel_scale_tap, $wpdb ) {
	$keel_scale_log = array();
	add_filter( 'query', $keel_scale_tap );
	$start  = microtime( true );
	$mem    = memory_get_usage();
	$health = keel_defaults_identity_exposure_health();
	$ms     = ( microtime( true ) - $start ) * 1000;
	remove_filter( 'query', $keel_scale_tap );
	$scan = array_values(
		array_filter(
			$keel_scale_log,
			static function ( $sql ) use ( $wpdb ) {
				return false !== strpos( $sql, "{$wpdb->users} u" ) || false !== strpos( $sql, 'post_author IN' ) || false !== strpos( $sql, 'comment_approved' );
			}
		)
	);
	printf( "%s: %.1f ms, %d exposure queries of %d total, memory +%.1f MB (peak %.1f MB)\n", $label, $ms, count( $scan ), count( $keel_scale_log ), ( memory_get_usage() - $mem ) / 1048576, memory_get_peak_usage() / 1048576 );
	return array( $health, $scan );
};

delete_transient( 'keel_identity_exposure' );
list( $keel_scale_cold, $keel_scale_sql )  = $keel_scale_run( 'cold' );
list( $keel_scale_warm, $keel_scale_wsql ) = $keel_scale_run( 'warm' );
$keel_scale_report                         = get_transient( 'keel_identity_exposure' );

printf( "users in table: %d\n", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
printf( "strong %d (%s), weak %d, named %d, more %d, limited %s\n", $keel_scale_report['total']['strong'], wp_json_encode( $keel_scale_report['strong'] ), $keel_scale_report['total']['weak'], count( $keel_scale_report['named'] ), $keel_scale_report['more'], $keel_scale_report['limited'] ? 'yes' : 'no' );
foreach ( $keel_scale_sql as $sql ) {
	echo "\nSQL: " . preg_replace( '/\s+/', ' ', $sql ) . "\n";
	foreach ( (array) $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
		echo 'PLAN: ' . wp_json_encode( $row ) . "\n";
	}
}

$keel_scale_check = static function ( $ok, $message ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $message . "\n";
	if ( ! $ok ) {
		$GLOBALS['keel_scale_failed'] = true;
	}
};
$keel_scale_check( count( $keel_scale_sql ) <= 3, 'P17: cold run uses one candidate query plus at most two grouped activity queries.' );
$keel_scale_check( 0 === count( $keel_scale_wsql ), 'P17: warm run performs no exposure query.' );
$keel_scale_check( $keel_scale_cold['description'] === $keel_scale_warm['description'], 'P17: warm report matches cold.' );
$keel_scale_check( count( $keel_scale_report['named'] ) <= KEEL_IDENTITY_NAMED_LIMIT, 'P16: named rows capped at ' . KEEL_IDENTITY_NAMED_LIMIT . '.' );
$keel_scale_check( ! $keel_scale_report['limited'] || false !== strpos( $keel_scale_cold['description'], 'Partial count' ), 'P16: a partial count says so.' );
$keel_scale_check( ! preg_match( '/[a-z0-9._-]+@[a-z0-9-]+\.[a-z]+/i', wp_json_encode( $keel_scale_cold ) ), 'P13: no address in the result.' );
delete_transient( 'keel_identity_exposure' );
if ( ! empty( $GLOBALS['keel_scale_failed'] ) ) {
	throw new RuntimeException( 'Scale contract failed.' );
}
