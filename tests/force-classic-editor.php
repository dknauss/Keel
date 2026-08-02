<?php
/**
 * Lightweight regression test for the force-classic-editor policy.
 *
 * Run: php tests/force-classic-editor.php
 *
 * @package keel
 */

$GLOBALS['keel_added_filters'] = array();

function add_action( ...$args ) {}
function add_filter( $hook, $cb = null, ...$rest ) { $GLOBALS['keel_added_filters'][] = $hook; }
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Requiring the plugin must not register these editor filters on its own.
foreach ( array( 'use_block_editor_for_post', 'use_widgets_block_editor' ) as $hook ) {
	keel_assert( ! in_array( $hook, $GLOBALS['keel_added_filters'], true ), "Editor filter '{$hook}' is not registered at load." );
}

// Invoking the policy registers the full classic-editor filter set.
$GLOBALS['keel_added_filters'] = array();
keel_defaults_force_classic_editor();

$expected = array(
	'use_block_editor_for_post',
	'use_block_editor_for_post_type',
	'gutenberg_can_edit_post',
	'use_widgets_block_editor',
);
foreach ( $expected as $hook ) {
	keel_assert( in_array( $hook, $GLOBALS['keel_added_filters'], true ), "force_classic registers '{$hook}'." );
}
keel_assert( count( $GLOBALS['keel_added_filters'] ) === count( $expected ), 'force_classic registers exactly the expected filters.' );

// Default is off (opt-in).
$schema = keel_defaults_schema();
keel_assert( isset( $schema['force_classic_editor'] ), 'force_classic_editor is in the schema.' );
keel_assert( 'no' === $schema['force_classic_editor']['default'], 'force_classic_editor defaults to off (opt-in).' );
keel_assert( 'editor' === $schema['force_classic_editor']['group'], 'force_classic_editor is in the Editor group.' );

fwrite( STDOUT, "force classic editor tests passed.\n" );
