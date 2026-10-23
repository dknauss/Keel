<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Marked disposable local fixture; trusted canonical identifiers, fixed filesystem paths and scalar-only digests (never deserialized).
/** Seed only a marked local browser lab; never invoke against production. @package Keel */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'KEEL_HIBP_BROWSER_LAB' ) || true !== KEEL_HIBP_BROWSER_LAB || ! function_exists( 'keel_hibp_browser_config' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Explicit local HIBP browser lab required.' );
}
$case = $args[0] ?? 'none';
if ( ! in_array( $case, array( 'none', 'exhausted', 'busy', 'transport', 'later_success', 'success', 'unavailable', 'optout', 'setting_off' ), true ) ) {
	throw new RuntimeException( 'Unknown synthetic case.' );
}
$scope = keel_hibp_budget_scope();
$key   = keel_hibp_budget_key( $scope, 'state' );
$user  = get_user_by( 'login', 'hibp_browser_admin' );
if ( ! $user ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'hibp_browser_admin',
			'user_pass'  => wp_generate_password( 40 ),
			'user_email' => 'hibp-browser@example.test',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture user creation failed.' ); }
	$user = get_user_by( 'id', $id );
}
$old    = keel_hibp_budget_read( $scope, $key );
$backup = WP_CONTENT_DIR . '/keel-hibp-browser-original.json';
if ( ! is_file( $backup ) ) {
	file_put_contents(
		$backup,
		wp_json_encode(
			array(
				'raw'   => $old,
				'key'   => $key,
				'table' => $scope['table'],
			)
		)
	); }
global $wpdb;
if ( 'none' === $case ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$scope['table']}` WHERE option_name=%s", $key ) );
} else {
	$state           = keel_hibp_budget_initial( keel_hibp_budget_policy(), time() );
	$state['credit'] = 0;
	$reason          = array(
		'exhausted'     => 'budget_exhausted',
		'busy'          => 'lookup_busy',
		'transport'     => 'http_429',
		'later_success' => 'http_429',
	)[ $case ] ?? null;
	if ( $reason ) {
		$state['sample'] = array(
			'kind'  => $reason,
			'first' => time() - 300,
			'seen'  => time() - 300,
		); }
	if ( in_array( $case, array( 'later_success', 'success' ), true ) ) {
		$state['success'] = time() - 60; }
	$value = 'unavailable' === $case ? '{synthetic-corruption' : wp_json_encode( $state );
	$wpdb->query( $wpdb->prepare( "INSERT INTO `{$scope['table']}` (option_name,option_value,autoload) VALUES(%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'", $key, $value ) );
}
$config = array(
	'case'      => $case,
	'multisite' => is_multisite(),
	'user'      => $user->ID,
	'digest'    => hash( 'sha256', serialize( keel_hibp_budget_read( $scope, $key ) ) ),
);
file_put_contents( WP_CONTENT_DIR . '/keel-hibp-browser.json', wp_json_encode( $config ) );
file_put_contents( WP_CONTENT_DIR . '/keel-hibp-browser.jsonl', '' );
echo wp_json_encode(
	array(
		'case'      => $case,
		'multisite' => is_multisite(),
		'branch'    => function_exists( 'keel_hibp_budget_evidence' ),
		'version'   => get_bloginfo( 'version' ),
	)
);
