<?php
/**
 * Lightweight regression test for helper list columns + media sizes panel.
 *
 * Run: php tests/helper-columns.php
 *
 * @package keel
 */

$GLOBALS['keel_usermeta']     = array();
$GLOBALS['keel_current_user'] = 3;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_user_meta( $uid, $key, $single = false ) {
	return isset( $GLOBALS['keel_usermeta'][ $uid ][ $key ] ) ? $GLOBALS['keel_usermeta'][ $uid ][ $key ] : '';
}
function update_user_meta( $uid, $key, $val ) {
	$GLOBALS['keel_usermeta'][ $uid ][ $key ] = $val;
	return true;
}
function get_current_user_id() { return $GLOBALS['keel_current_user']; }
function get_user_option( $key, $uid ) {
	$v = get_user_meta( $uid, $key, true );
	return '' === $v ? false : $v;
}
class WP_User {
	public $ID;
	public function __construct( $id ) { $this->ID = $id; }
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Column filters add their keys and preserve existing columns.
$cols = keel_defaults_filter_post_columns( array( 'title' => 'Title' ) );
foreach ( array( 'title', 'keel_id', 'keel_thumb', 'keel_modified' ) as $k ) {
	keel_assert( isset( $cols[ $k ] ), "Post columns include '{$k}'." );
}
$cols = keel_defaults_filter_media_columns( array( 'title' => 'Title' ) );
keel_assert( isset( $cols['keel_file_size'] ), 'Media columns include file size.' );
$cols = keel_defaults_filter_user_columns( array( 'username' => 'Username' ) );
keel_assert( isset( $cols['keel_registered'] ) && isset( $cols['keel_last_login'] ), 'User columns include registered + last login.' );

// ID starts hidden on post-type lists, and only there.
$edit = (object) array( 'base' => 'edit' );
keel_assert( array( 'keel_id' ) === keel_defaults_hide_helper_id_column( array(), $edit ), 'ID starts hidden on post lists.' );
keel_assert( array( 'core', 'keel_id' ) === keel_defaults_hide_helper_id_column( array( 'core' ), $edit ), "Core's defaults are kept, ID added after them." );
keel_assert( array( 'keel_id' ) === keel_defaults_hide_helper_id_column( array( 'keel_id' ), $edit ), 'ID is not listed twice.' );
keel_assert( array( 'keel_id' ) === keel_defaults_hide_helper_id_column( null, $edit ), 'A non-array from core is treated as empty.' );
foreach ( array( 'users', 'upload', 'edit-tags' ) as $base ) {
	keel_assert( array() === keel_defaults_hide_helper_id_column( array(), (object) array( 'base' => $base ) ), "Nothing is hidden on '{$base}'." );
}
keel_assert( array() === keel_defaults_hide_helper_id_column( array() ), 'Nothing is hidden without a screen.' );
foreach ( array( 'keel_thumb', 'keel_modified' ) as $k ) {
	keel_assert( ! in_array( $k, keel_defaults_hide_helper_id_column( array(), $edit ), true ), "'{$k}' still shows by default." );
}

// Existing users: ID is hidden once per screen, never again after they show it.
$posts                       = (object) array(
	'id'   => 'edit-post',
	'base' => 'edit',
);
$pages                       = (object) array(
	'id'   => 'edit-page',
	'base' => 'edit',
);
$GLOBALS['keel_usermeta'][3] = array( 'manageedit-postcolumnshidden' => array( 'jetpack_seo_schema' ) );
keel_defaults_backfill_hidden_id_column( $posts );
keel_assert( array( 'jetpack_seo_schema', 'keel_id' ) === $GLOBALS['keel_usermeta'][3]['manageedit-postcolumnshidden'], 'A saved list gets keel_id added once, keeping its own entries.' );
keel_assert( array( 'edit-post' ) === $GLOBALS['keel_usermeta'][3]['keel_id_column_backfilled'], 'The screen is recorded.' );

// The user ticks ID back on; the next visit must not hide it again.
$GLOBALS['keel_usermeta'][3]['manageedit-postcolumnshidden'] = array( 'jetpack_seo_schema' );
keel_defaults_backfill_hidden_id_column( $posts );
keel_assert( array( 'jetpack_seo_schema' ) === $GLOBALS['keel_usermeta'][3]['manageedit-postcolumnshidden'], 'A user who showed ID again keeps it.' );

// Nothing saved for Pages: only recorded, core's default does the hiding.
keel_defaults_backfill_hidden_id_column( $pages );
keel_assert( ! isset( $GLOBALS['keel_usermeta'][3]['manageedit-pagecolumnshidden'] ), 'No Screen Options are created for a user who never saved any.' );
keel_assert( array( 'edit-post', 'edit-page' ) === $GLOBALS['keel_usermeta'][3]['keel_id_column_backfilled'], 'Each screen is recorded once.' );

// An empty saved list (every column shown on purpose) still counts as saved.
$GLOBALS['keel_current_user']                                = 4;
$GLOBALS['keel_usermeta'][4]['manageedit-postcolumnshidden'] = array();
keel_defaults_backfill_hidden_id_column( $posts );
keel_assert( array( 'keel_id' ) === $GLOBALS['keel_usermeta'][4]['manageedit-postcolumnshidden'], 'An empty saved list gets keel_id once.' );

// Not a post list, or nobody logged in: nothing is written.
$GLOBALS['keel_current_user'] = 5;
keel_defaults_backfill_hidden_id_column(
	(object) array(
		'id'   => 'users',
		'base' => 'users',
	)
);
keel_assert( ! isset( $GLOBALS['keel_usermeta'][5] ), 'Nothing is written off post lists.' );
$GLOBALS['keel_current_user'] = 0;
keel_defaults_backfill_hidden_id_column( $posts );
keel_assert( ! isset( $GLOBALS['keel_usermeta'][0] ), 'Nothing is written without a user.' );

// Registered with the helper columns, both arguments, only when enabled.
$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source in a CLI test.
keel_assert(
	1 === preg_match( "/keel_defaults_enabled\\( 'helper_list_columns' \\) \\) \\{[^}]*add_filter\\( 'default_hidden_columns', 'keel_defaults_hide_helper_id_column', 10, 2 \\);/", $bootstrap ),
	'The ID default is hooked inside the helper-columns block with both arguments.'
);
keel_assert(
	1 === preg_match( "/keel_defaults_enabled\\( 'helper_list_columns' \\) \\) \\{[^}]*add_action\\( 'current_screen', 'keel_defaults_backfill_hidden_id_column' \\);/", $bootstrap ),
	'The one-time backfill is hooked inside the helper-columns block.'
);

// Last-login: unknown is 0; record then read round-trips.
keel_assert( 0 === keel_defaults_last_login_timestamp( 7 ), 'Unknown last login is 0.' );
keel_defaults_record_last_login( 'bob', new WP_User( 7 ) );
keel_assert( keel_defaults_last_login_timestamp( 7 ) > 0, 'Recorded last login reads back.' );

// A non-WP_User (e.g. a failed auth passing WP_Error/null) must not fatal or record.
keel_defaults_record_last_login( 'x', null );
keel_assert( true, 'Null user is handled without fatal.' );

// Falls back to common third-party meta keys.
$GLOBALS['keel_usermeta'][9] = array( 'last_login' => 1600000000 );
keel_assert( 1600000000 === keel_defaults_last_login_timestamp( 9 ), 'Reads a third-party last_login key.' );

// Schema defaults.
$schema = keel_defaults_schema();
keel_assert( 'yes' === $schema['media_sizes_panel']['default'], 'media_sizes_panel defaults on.' );
keel_assert( 'media' === $schema['media_sizes_panel']['group'], 'media_sizes_panel is in the Media group.' );
keel_assert( 'no' === $schema['helper_list_columns']['default'], 'helper_list_columns defaults off.' );
keel_assert( 'ux' === $schema['helper_list_columns']['group'], 'helper_list_columns is in the UX group.' );

fwrite( STDOUT, "helper columns tests passed.\n" );
