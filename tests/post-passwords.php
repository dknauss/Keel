<?php
/**
 * Lightweight regression test for disable_post_passwords.
 *
 * Run: php tests/post-passwords.php
 *
 * @package keel
 */

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
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

function keel_capture_password_ui() {
	ob_start();
	keel_defaults_hide_post_password_ui();
	return ob_get_clean();
}

// Schema: opt-in, in the Content group.
$schema = keel_defaults_schema();
keel_assert( 'no' === $schema['disable_post_passwords']['default'], 'disable_post_passwords defaults off.' );
keel_assert( 'content' === $schema['disable_post_passwords']['group'], 'disable_post_passwords is in the Content group.' );

// On a new post with no password, the option is hidden.
$GLOBALS['pagenow'] = 'post-new.php';
$GLOBALS['post']    = null;
$out = keel_capture_password_ui();
keel_assert( false !== strpos( $out, 'visibility-radio-password' ), 'Classic visibility option is hidden.' );
keel_assert( false !== strpos( $out, 'editor-post-password-0' ), 'Block editor password field is hidden.' );
keel_assert( false !== strpos( $out, 'display: none' ), 'The hide rule is emitted.' );

// Editing screen for post.php with no password also hides.
$GLOBALS['pagenow'] = 'post.php';
keel_assert( '' !== trim( keel_capture_password_ui() ), 'Hides on post.php too.' );

// Outside the editor, nothing is printed.
$GLOBALS['pagenow'] = 'edit.php';
keel_assert( '' === trim( keel_capture_password_ui() ), 'No output outside the post editor.' );

// A post that already has a password keeps its field (stays editable).
$GLOBALS['pagenow']            = 'post.php';
$post                          = new stdClass();
$post->post_password          = 'secret';
$GLOBALS['post']              = $post;
keel_assert( '' === trim( keel_capture_password_ui() ), 'A password-protected post keeps its field.' );

fwrite( STDOUT, "post passwords tests passed.\n" );
