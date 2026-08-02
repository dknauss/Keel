<?php
/**
 * Lightweight regression test for keel_defaults_limit_unfiltered_html().
 *
 * Pure-logic test: stubs the two WordPress functions the callback may touch and
 * asserts the capability outcomes. The important guarantee it pins down is the
 * P4 recursion trap — is_super_admin() must NEVER be called on single-site,
 * because there it resolves a capability and would re-enter user_has_cap.
 *
 * Run: php tests/unfiltered-html.php
 *
 * @package keel
 */

// --- Test doubles -----------------------------------------------------------

$GLOBALS['keel_is_multisite']        = false;
$GLOBALS['keel_super_admins']        = array();   // user IDs that are super admins
$GLOBALS['keel_is_super_admin_hits'] = 0;

function is_multisite() {
	return (bool) $GLOBALS['keel_is_multisite'];
}

function is_super_admin( $user_id = 0 ) {
	++$GLOBALS['keel_is_super_admin_hits'];
	// Mirror core's real hazard: on single-site is_super_admin() resolves a
	// capability (has_cap), which inside user_has_cap would recurse. If the
	// callback ever reaches here on single-site, the guard is broken — fail loud.
	if ( ! is_multisite() ) {
		fwrite( STDERR, "RECURSION BUG: is_super_admin() called on single-site inside user_has_cap.\n" );
		exit( 1 );
	}
	return in_array( $user_id, $GLOBALS['keel_super_admins'], true );
}

// Load only the function under test — define a stub bootstrap guard so requiring
// the plugin file does not try to run WordPress. We instead extract the function
// by including the file with ABSPATH defined and the add_action stubbed.
$GLOBALS['keel_actions'] = array();
function add_action( ...$args ) { $GLOBALS['keel_actions'][] = $args; }
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

// --- Helpers ----------------------------------------------------------------

function keel_test_user( array $roles, $id = 5 ) {
	return (object) array(
		'roles' => $roles,
		'ID'    => $id,
	);
}

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$fn = 'keel_defaults_limit_unfiltered_html';

// --- Single-site cases ------------------------------------------------------
$GLOBALS['keel_is_multisite'] = false;

// Editor with the cap loses it.
$out = $fn(
	array(
		'unfiltered_html' => true,
		'edit_posts'      => true,
	),
	array(),
	array(),
	keel_test_user( array( 'editor' ) )
);
keel_assert( empty( $out['unfiltered_html'] ), 'Editor loses unfiltered_html on single-site.' );

// Administrator (role) keeps it.
$out = $fn(
	array(
		'unfiltered_html' => true,
		'manage_options'  => true,
	),
	array(),
	array(),
	keel_test_user( array( 'administrator' ) )
);
keel_assert( ! empty( $out['unfiltered_html'] ), 'Administrator keeps unfiltered_html.' );

// Non-"administrator" role but with manage_options resolved (custom admin) keeps it.
$out = $fn(
	array(
		'unfiltered_html' => true,
		'manage_options'  => true,
	),
	array(),
	array(),
	keel_test_user( array( 'custom_admin' ) )
);
keel_assert( ! empty( $out['unfiltered_html'] ), 'manage_options holder keeps unfiltered_html.' );

// User without the cap is returned untouched (early exit, no role work).
$out = $fn( array( 'edit_posts' => true ), array(), array(), keel_test_user( array( 'author' ) ) );
keel_assert( ! isset( $out['unfiltered_html'] ), 'User without unfiltered_html is unchanged.' );

// The recursion guard held: is_super_admin() was never called on single-site.
keel_assert( 0 === $GLOBALS['keel_is_super_admin_hits'], 'is_super_admin() is never called on single-site.' );

// --- Multisite cases --------------------------------------------------------
$GLOBALS['keel_is_multisite'] = true;
$GLOBALS['keel_super_admins'] = array( 5 );

// Super admin (id 5) keeps it even as a bare editor.
$out = $fn( array( 'unfiltered_html' => true ), array(), array(), keel_test_user( array( 'editor' ), 5 ) );
keel_assert( ! empty( $out['unfiltered_html'] ), 'Super Admin keeps unfiltered_html on multisite.' );

// Non-super-admin editor (id 9) loses it.
$out = $fn( array( 'unfiltered_html' => true ), array(), array(), keel_test_user( array( 'editor' ), 9 ) );
keel_assert( empty( $out['unfiltered_html'] ), 'Non-super-admin editor loses unfiltered_html on multisite.' );

keel_assert( $GLOBALS['keel_is_super_admin_hits'] > 0, 'is_super_admin() is consulted on multisite.' );

fwrite( STDOUT, "unfiltered_html policy tests passed.\n" );
