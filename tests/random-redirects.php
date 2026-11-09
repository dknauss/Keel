<?php
/**
 * WordPress 7.2 random-redirect tests: stale rules, setting changes and the
 * author guard.
 *
 * @package keel
 */

$GLOBALS['keel_filters']         = array();
$GLOBALS['keel_options']         = array();
$GLOBALS['keel_deleted_options'] = array();
$GLOBALS['keel_random_query']    = true;

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
function delete_option( $key ) {
	$GLOBALS['keel_deleted_options'][] = $key;
	return true;
}
function is_multisite() {
	return false;
}
function wp_is_random_content_query( $query ) {
	return (bool) $GLOBALS['keel_random_query'];
}
class WP {
	public $matched_query = '';
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

/*
 * Stale rules.
 *
 * Rules saved while the redirects were on still match /random/ after the
 * switch, and core ignores the random variable they set, so the URL renders the
 * blog home with a 200. A rule-matched request is a 404 and the saved rules go.
 */
$wp                = new WP();
$wp->matched_query = 'random=';
keel_assert( array( 'error' => '404' ) === keel_defaults_retire_random_rules( array( 'random' => '' ) ), 'A stale /random/ rule match is a 404.' );
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'A stale rule match deletes the saved rewrite rules.' );

$GLOBALS['keel_deleted_options'] = array();
$wp->matched_query               = 'category_name=news&random=';
keel_assert(
	array( 'error' => '404' ) === keel_defaults_retire_random_rules(
		array(
			'category_name' => 'news',
			'random'        => '',
		)
	),
	'A stale /random/category/x/ match is a 404, not the category archive.'
);

// A visitor's own ?random on an ordinary URL is not a rule match; core ignores it.
$GLOBALS['keel_deleted_options'] = array();
$wp->matched_query               = 'category_name=news';
$vars                            = array(
	'category_name' => 'news',
	'random'        => '',
);
keel_assert( keel_defaults_retire_random_rules( $vars ) === $vars, 'A query-string ?random is left for core to ignore.' );
keel_assert( array() === $GLOBALS['keel_deleted_options'], 'A query-string ?random does not delete the rules.' );

// A post or term whose slug merely contains "random" is not the random variable.
$wp->matched_query = 'name=not-random=';
$vars              = array( 'name' => 'not-random' );
keel_assert( keel_defaults_retire_random_rules( $vars ) === $vars, 'Requests without the random variable pass through.' );
$wp->matched_query = 'name=xrandom=';
$vars              = array(
	'name'   => 'xrandom',
	'random' => '',
);
keel_assert( keel_defaults_retire_random_rules( $vars ) === $vars, 'Only a whole random= pair counts as a rule match.' );

/*
 * Setting changes regenerate the rules, other saves do not.
 */
$GLOBALS['keel_deleted_options'] = array();
keel_defaults_random_setting_changed( array( 'disable_random_redirects' => 'yes' ), array( 'disable_random_redirects' => 'no' ) );
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'Turning the setting off deletes the saved rules.' );

$GLOBALS['keel_deleted_options'] = array();
keel_defaults_random_setting_changed( array(), array( 'disable_random_redirects' => 'no' ) );
keel_assert( array( 'rewrite_rules' ) === $GLOBALS['keel_deleted_options'], 'Leaving the default for "no" deletes the saved rules.' );

$GLOBALS['keel_deleted_options'] = array();
keel_defaults_random_setting_changed( array( 'disable_comments' => 'yes' ), array( 'disable_comments' => 'no' ) );
keel_assert( array() === $GLOBALS['keel_deleted_options'], 'Saving an unrelated setting leaves the rules alone.' );

keel_defaults_random_setting_changed( array(), array( 'disable_random_redirects' => 'yes' ) );
keel_assert( array() === $GLOBALS['keel_deleted_options'], 'Storing the default value is not a change.' );

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
