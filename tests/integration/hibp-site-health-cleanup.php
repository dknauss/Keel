<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Marked disposable local fixture; trusted canonical identifiers, fixed filesystem paths and scalar-only digests (never deserialized).
/** Restore only this marked local browser lab's saved budget row. @package Keel */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'KEEL_HIBP_BROWSER_LAB' ) || true !== KEEL_HIBP_BROWSER_LAB || ! function_exists( 'keel_hibp_browser_config' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Explicit local HIBP browser lab required.' );
}
$file = WP_CONTENT_DIR . '/keel-hibp-browser-original.json';
if ( ! is_file( $file ) ) {
	throw new RuntimeException( 'Original snapshot missing; do not guess cleanup.' ); }
$original = json_decode( file_get_contents( $file ), true );
$scope    = keel_hibp_budget_scope();
$key      = keel_hibp_budget_key( $scope, 'state' );
if ( $scope['table'] !== $original['table'] || $key !== $original['key'] ) {
	throw new RuntimeException( 'Canonical scope changed.' ); }
global $wpdb;
if ( null === $original['raw'] ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$scope['table']}` WHERE option_name=%s", $key ) );
} elseif ( is_string( $original['raw'] ) ) {
	$wpdb->query( $wpdb->prepare( "UPDATE `{$scope['table']}` SET option_value=%s WHERE option_name=%s", $original['raw'], $key ) );
} else {
	throw new RuntimeException( 'Original authority was unreadable.' ); }
if ( keel_hibp_budget_read( $scope, $key ) !== $original['raw'] ) {
	throw new RuntimeException( 'Original authority was not restored.' ); }
foreach ( array( 'keel-hibp-browser-original.json', 'keel-hibp-browser.json', 'keel-hibp-browser.jsonl' ) as $name ) {
	unlink( WP_CONTENT_DIR . '/' . $name ); }
echo 'Original budget authority restored; browser fixture files removed.';
