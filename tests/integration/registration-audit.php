<?php
/** Real WP_Hook registration-only inspection on a disposable WordPress install. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_registration_audit' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
global $wp_filter;
$site        = get_current_blog_id();
$user        = get_current_user_id();
$baseline    = keel_defaults_registration_baseline();
$hooks       = array();
$second      = 0;
$assert      = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
};
$deny        = static function () { throw new RuntimeException( 'Audit must not execute callbacks, read policy, perform HTTP or write options.' );
};
$guard_hooks = array( 'pre_http_request', 'pre_option_keel_settings', 'pre_update_option', 'keel_policy_expectations' );
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	$assert( $site === $baseline['site'], 'Bootstrap captured this site.' );
	$initial = keel_defaults_registration_audit();
	foreach ( $baseline['expected'] as $id => $registration ) {
		$assert( in_array( $initial['rows'][ $id ]['status'], array( 'registered', 'registered_no_comparator' ), true ), 'Actual bootstrap wrappers preserve each audited registration.' );
	}
	foreach ( array( 'template_redirect', 'auth_cookie_expiration' ) as $hook ) {
		$hooks[ $hook ]     = $wp_filter[ $hook ] ?? null;
		$wp_filter[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : new WP_Hook();
	}
	// Tests mutate cloned hook objects; the audit itself must not change them.
	foreach ( $wp_filter['template_redirect']->callbacks as $priority => $bucket ) {
		if ( isset( $bucket['redirect_canonical'] ) ) {
			remove_action( 'template_redirect', 'redirect_canonical', $priority ); }
	}
	add_action( 'template_redirect', 'redirect_canonical', 10 );
	keel_defaults_registration_baseline( true );
	keel_defaults_record_registration( 'author_archives', $deny, 9 );
	add_action( 'template_redirect', $deny, 9 );
	foreach ( $guard_hooks as $hook ) {
		add_filter( $hook, $deny ); }
	$before = $wp_filter['template_redirect']->callbacks;
	$report = keel_defaults_registration_audit();
	$assert( 'registered' === $report['rows']['author_archives']['status'] && 'inactive' === $report['rows']['attachment_pages']['status'], 'Expected and intentionally absent contracts stay distinct.' );
	$assert( $before === $wp_filter['template_redirect']->callbacks, 'Audit leaves registrations unchanged.' );
	remove_action( 'template_redirect', $deny, 9 );
	$assert( 'missing' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Removed real callback is detected.' );
	$assert( false === has_action( 'template_redirect', $deny ), 'Audit does not repair removed callback.' );
	add_action( 'template_redirect', $deny, 10 );
	$assert( 'precedence_lost' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Equal priority loses canonical redirect ordering.' );
	remove_action( 'template_redirect', $deny, 10 );
	add_action( 'template_redirect', $deny, -1 );
	remove_action( 'template_redirect', 'redirect_canonical', 10 );
	add_action( 'template_redirect', 'redirect_canonical', 0 );
	$assert( 'registered' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Core priority zero remains a comparator.' );
	remove_action( 'template_redirect', 'redirect_canonical', 0 );
	$assert( 'registered_no_comparator' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Absent core comparator limits the claim.' );
	keel_defaults_record_registration( 'session_length', $deny, 50 );
	add_filter( 'auth_cookie_expiration', $deny, 50 );
	add_filter( 'auth_cookie_expiration', $deny, 10 );
	$assert( 'registered' === keel_defaults_registration_audit()['rows']['session_length']['status'], 'Duplicate early registration cannot hide valid late clamp.' );
	remove_filter( 'auth_cookie_expiration', $deny, 50 );
	$assert( 'precedence_lost' === keel_defaults_registration_audit()['rows']['session_length']['status'], 'Late clamp moved to default priority is detected.' );
	$assert( 'recommended' === keel_defaults_registration_health()['status'], 'Site Health reports a structural finding without executing callbacks.' );
	foreach ( $guard_hooks as $hook ) {
		remove_filter( $hook, $deny ); }
	if ( is_multisite() ) {
		$installing = wp_installing();
		$second     = wpmu_create_blog( get_network()->domain, '/keel-audit-' . wp_rand() . '/', 'Audit probe', $admins[0]->ID );
		wp_installing( $installing );
		$assert( ! is_wp_error( $second ), 'Create disposable second site.' );
		switch_to_blog( $second );
		try {
			$assert( 'context_mismatch' === keel_defaults_registration_audit()['coverage'], 'Switching blogs does not imply callbacks were rebootstrapped for its policies.' );
		} finally {
			restore_current_blog(); }
		$assert( 'complete' === keel_defaults_registration_audit()['coverage'], 'Restoring bootstrap context restores inspection.' );
	}
	wp_set_current_user( 0 );
	$assert( '' === keel_defaults_registration_health()['description'], 'Unauthorized Site Health callers receive no inspection details.' );
} finally {
	foreach ( $guard_hooks as $hook ) {
		remove_filter( $hook, $deny ); }
	foreach ( $hooks as $hook => $original ) {
		if ( null === $original ) {
			unset( $wp_filter[ $hook ] );
		} else {
			$wp_filter[ $hook ] = $original; }
	}
	keel_defaults_registration_baseline( true );
	foreach ( $baseline['expected'] as $id => $registration ) {
		keel_defaults_record_registration( $id, $registration['callback'], $registration['priority'] ); }
	if ( is_int( $second ) && $second > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $second, true ); }
	wp_set_current_user( $user );
}
echo "OK: real registration audit, inactive policies, no callbacks/repairs and multisite context.\n";
