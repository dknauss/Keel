<?php
/**
 * Lightweight regression test for lowercase uploads + admin menu width.
 *
 * Run: php tests/admin-ux-media.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// --- lowercase_upload_filenames ---
keel_assert( 'myfile.png' === keel_defaults_lowercase_filename( 'MyFile.PNG' ), 'ASCII filename is lowercased.' );
keel_assert( keel_defaults_lowercase_filename( 'CAFÉ.JPG' ) === mb_strtolower( 'CAFÉ.JPG', 'UTF-8' ), 'UTF-8 filename is lowercased with mbstring.' );

$schema = keel_defaults_schema();
keel_assert( 'yes' === $schema['lowercase_upload_filenames']['default'], 'lowercase_upload_filenames defaults on.' );
keel_assert( 'media' === $schema['lowercase_upload_filenames']['group'], 'lowercase_upload_filenames is in the Media group.' );

// --- admin_menu_width (index-based range: slider posts an index, sanitize maps to value) ---
keel_assert( 'default' === $schema['admin_menu_width']['default'], 'admin_menu_width defaults to WordPress default.' );
keel_assert( 'range' === $schema['admin_menu_width']['type'], 'admin_menu_width is a range slider.' );
keel_assert( in_array( '240', $schema['admin_menu_width']['values'], true ), 'admin_menu_width offers the 240px stop.' );

// Regression for the save bug: a posted slider index must map to its value and persist,
// not fall through to the default (numeric option keys used to break the strict compare).
$saved = keel_defaults_sanitize( array( 'admin_menu_width' => '4' ) ); // index 4 = 300
keel_assert( '300' === $saved['admin_menu_width'], 'Slider index 4 saves as 300, not the default.' );
$saved = keel_defaults_sanitize( array( 'admin_menu_width' => '0' ) ); // index 0 = default
keel_assert( 'default' === $saved['admin_menu_width'], 'Slider index 0 saves as default.' );
$saved = keel_defaults_sanitize( array( 'admin_menu_width' => '300' ) ); // direct value also accepted
keel_assert( '300' === $saved['admin_menu_width'], 'A direct width value is accepted too.' );
$saved = keel_defaults_sanitize( array( 'admin_menu_width' => '99' ) ); // out-of-range index → default
keel_assert( 'default' === $saved['admin_menu_width'], 'An out-of-range index falls back to default.' );

// Default width prints no CSS.
$GLOBALS['keel_options']['keel_settings'] = array( 'admin_menu_width' => 'default' );
ob_start();
keel_defaults_admin_menu_width_css();
keel_assert( '' === trim( ob_get_clean() ), 'Default admin menu width prints no CSS.' );

// A chosen width prints scoped CSS with that pixel value.
$GLOBALS['keel_options']['keel_settings'] = array( 'admin_menu_width' => '240' );
ob_start();
keel_defaults_admin_menu_width_css();
$css = ob_get_clean();
keel_assert( false !== strpos( $css, '240px' ), 'Chosen width appears in the CSS.' );
keel_assert( false !== strpos( $css, '#adminmenu' ), 'CSS targets the admin menu.' );
keel_assert( false !== strpos( $css, 'min-width: 783px' ), 'CSS is scoped to the non-collapsed breakpoint.' );

// The Media group is registered.
$groups = keel_defaults_groups();
keel_assert( isset( $groups['media'] ), 'Media group is registered.' );

fwrite( STDOUT, "admin ux + media tests passed.\n" );
