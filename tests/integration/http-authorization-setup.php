<?php
/** Run with WP-CLI eval-file on disposable labs; pass cleanup to restore. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'KEEL_HTTP_AUTH_LAB' ) ) {
	exit( 1 ); }
if ( ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Disposable localhost sites only.' );
}
$key    = 'keel_http_lab_backup';
$backup = get_option( $key );
if ( isset( $args[0] ) && 'cleanup' === $args[0] ) {
	if ( ! is_array( $backup ) ) {
		throw new RuntimeException( 'No lab backup.' ); }
	foreach ( $backup['state'] as $item ) {
		list( $scope, $name, $value, $exists ) = $item;
		if ( 'site' === $scope ) {
			$exists ? update_site_option( $name, $value ) : delete_site_option( $name );
		} else {
			$exists ? update_option( $name, $value ) : delete_option( $name );
		}
		$missing  = new stdClass();
		$observed = 'site' === $scope ? get_site_option( $name, $missing ) : get_option( $name, $missing );
		if ( $exists ? $observed !== $value : $observed !== $missing ) {
			throw new RuntimeException( 'Failed to restore scoped setting: ' . $name );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php'; }
	foreach ( $backup['users'] as $id ) {
		WP_Session_Tokens::get_instance( $id )->destroy_all();
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
		if ( get_userdata( $id ) ) {
			throw new RuntimeException( 'Fixture user cleanup failed.' ); }
	}
	delete_option( $key );
	echo "Restored scoped state; removed fixture users and sessions.\n";
	return;
}
if ( false !== $backup ) {
	throw new RuntimeException( 'Existing backup: clean up before another setup.' ); }
$backup = array(
	'users' => array(),
	'state' => array(),
);
foreach ( array( array( 'site', 'auto_update_core_minor' ), array( 'site', 'keel_network_settings' ), array( 'option', 'keel_settings' ) ) as $item ) {
	list( $scope, $name ) = $item;
	$missing              = new stdClass();
	$value                = 'site' === $scope ? get_site_option( $name, $missing ) : get_option( $name, $missing );
	$backup['state'][]    = array( $scope, $name, $value === $missing ? null : $value, $value !== $missing );
}
add_option( $key, $backup, '', false );
foreach ( array(
	'admin'      => 'administrator',
	'editor'     => 'editor',
	'subscriber' => 'subscriber',
	'delegate'   => 'administrator',
) as $suffix => $role ) {
	$login = 'keel_http_' . $suffix;
	if ( get_user_by( 'login', $login ) ) {
		throw new RuntimeException( 'Fixture login already exists.' ); }
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 40 ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() ); }
	$backup['users'][] = $id;
	update_option( $key, $backup );
}
update_site_option( 'auto_update_core_minor', 'disabled' );
echo "Fixture users created; scoped state backed up.\n";
