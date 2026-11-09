<?php
/**
 * WordPress 7.2 random-redirect tests: keeping the saved rewrite rules in step
 * with the effective answer, and the author guard.
 *
 * @package keel
 */

$GLOBALS['keel_filters']         = array();
$GLOBALS['keel_options']         = array();
$GLOBALS['keel_deleted_options'] = array();
$GLOBALS['keel_updated_options'] = array();
$GLOBALS['keel_random_query']    = true;
$GLOBALS['keel_random_enabled']  = false;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr( $s ) { return $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['keel_options'][ $key ]   = $value;
	$GLOBALS['keel_updated_options'][] = $key;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['keel_options'][ $key ] );
	$GLOBALS['keel_deleted_options'][] = $key;
	return true;
}
function is_multisite() {
	return false;
}
function wp_is_random_content_redirect_enabled() {
	return (bool) $GLOBALS['keel_random_enabled'];
}
function wp_is_random_content_query( $query ) {
	return (bool) $GLOBALS['keel_random_query'];
}
class WP_Query {
	public $author = true;
	public $vars   = array();
	public function is_author() {
		return $this->author;
	}
	public function set( $key, $value ) {
		$this->vars[ $key ] = $value;
	}
}
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

$failed = 0;
function keel_assert( $cond, $msg ) {
	global $failed;
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		++$failed;
	}
}

function keel_reset_options( array $options ) {
	$GLOBALS['keel_options']         = $options;
	$GLOBALS['keel_deleted_options'] = array();
	$GLOBALS['keel_updated_options'] = array();
}

$rules = array( '([^/]+)/?$' => 'index.php?name=$matches[1]' );

/*
 * Noting what the rules were built under. The filter's return value is the
 * rules, untouched; the option is written only when the answer changes.
 */
keel_reset_options( array() );
keel_assert( keel_defaults_note_random_rules( $rules ) === $rules, 'Noting the state leaves the rules unchanged.' );
keel_assert( 'off' === get_option( 'keel_random_rules_state' ), 'Rules built with the redirects off are noted as off.' );

keel_reset_options( array( 'keel_random_rules_state' => 'off' ) );
keel_defaults_note_random_rules( $rules );
keel_assert( array() === $GLOBALS['keel_updated_options'], 'An unchanged state is not written again.' );

$GLOBALS['keel_random_enabled'] = true;
keel_defaults_note_random_rules( $rules );
keel_assert( 'on' === get_option( 'keel_random_rules_state' ), 'Rules built with the redirects on are noted as on.' );

/*
 * Checking on each request. Saved rules that disagree with the effective
 * answer are deleted, whichever direction it changed and whoever changed it:
 * this site's setting, network policy, a constant or another plugin.
 */
$GLOBALS['keel_random_enabled'] = false;
keel_reset_options(
	array(
		'rewrite_rules'           => $rules,
		'keel_random_rules_state' => 'on',
	)
);
keel_defaults_check_random_rules();
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'Rules built with the redirects on are rebuilt once they are off, so /random/ is not the blog home with a 200.' );

$GLOBALS['keel_random_enabled'] = true;
keel_reset_options(
	array(
		'rewrite_rules'           => $rules,
		'keel_random_rules_state' => 'off',
	)
);
keel_defaults_check_random_rules();
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'Rules built with the redirects off are rebuilt once a network policy or another plugin turns them on.' );

keel_reset_options(
	array(
		'rewrite_rules'           => $rules,
		'keel_random_rules_state' => 'on',
	)
);
keel_defaults_check_random_rules();
keel_assert( array() === $GLOBALS['keel_deleted_options'], 'Rules that match the answer are left alone.' );

// Saved by an earlier Keel, or before Keel was active: rebuilt once, then noted.
keel_reset_options( array( 'rewrite_rules' => $rules ) );
keel_defaults_check_random_rules();
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'Rules with no noted state are rebuilt once.' );

// Plain permalinks save no rules: nothing to rebuild, and no write per request.
$GLOBALS['keel_random_enabled'] = false;
keel_reset_options( array( 'keel_random_rules_state' => 'on' ) );
keel_defaults_check_random_rules();
keel_assert( array() === $GLOBALS['keel_deleted_options'], 'A site without saved rules deletes nothing.' );

/*
 * The author guard: a random redirect scoped to an author finds nothing, so
 * core answers 404 for real and unknown authors alike.
 */
$query = new WP_Query();
keel_defaults_block_random_author( $query );
keel_assert( array( 'post__in' => array( 0 ) ) === $query->vars, 'A random author query selects no post.' );

$query         = new WP_Query();
$query->author = false;
keel_defaults_block_random_author( $query );
keel_assert( array() === $query->vars, 'A random query not scoped to an author is untouched.' );

$GLOBALS['keel_random_query'] = false;
$query                        = new WP_Query();
keel_defaults_block_random_author( $query );
keel_assert( array() === $query->vars, 'An ordinary author query is untouched.' );

if ( $failed ) {
	fwrite( STDERR, "random redirects: {$failed} failed\n" );
	exit( 1 );
}

echo "random redirects: OK\n";
