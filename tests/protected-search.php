<?php
/**
 * Search privacy clause guards and per-object access contracts.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/content.php';
require dirname( __DIR__ ) . '/includes/schema.php';

class Keel_Search_Query {
	public $is_admin = false;
	public $search   = true;
	public function is_search() { return $this->search; }
}
class Keel_Search_DB {
	public $posts = 'wp_posts';
	public $calls = 0;
	public $rows  = array();
	public $sql   = '';
	public function get_results( $sql ) {
		++$this->calls;
		$this->sql = $sql;
		return $this->rows;
	}
}
function is_user_logged_in() { return $GLOBALS['keel_search_logged_in']; }
function current_user_can( $cap, $id ) {
	if ( 'edit_post' !== $cap ) {
		throw new RuntimeException( 'Must use per-object edit capability, not generic read or a role.' );
	}
	return 2 === $id;
}
function post_password_required( $post ) { return 3 !== (int) $post->ID; }
function update_post_cache( &$posts ) { $GLOBALS['keel_search_primed'] = $posts; }
function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$wpdb                             = new Keel_Search_DB();
$query                            = new Keel_Search_Query();
$clauses                          = array(
	'where'   => ' AND wp_posts.post_status = \'publish\'',
	'join'    => ' JOIN taxonomy ON taxonomy.object_id = wp_posts.ID',
	'groupby' => 'wp_posts.ID',
	'limits'  => 'LIMIT 0, 2',
	'orderby' => 'wp_posts.ID DESC',
	'fields'  => 'wp_posts.ID',
);
$GLOBALS['keel_search_logged_in'] = false;
$query->search                    = false;
keel_assert( keel_defaults_protect_search( $clauses, $query ) === $clauses, 'Non-search clauses stay unchanged.' );
$query->search   = true;
$query->is_admin = true;
keel_assert( keel_defaults_protect_search( $clauses, $query ) === $clauses, 'Admin clauses stay unchanged.' );
$query->is_admin = false;
$actual          = keel_defaults_protect_search( $clauses, $query );
keel_assert( 0 === $wpdb->calls, 'Anonymous searches need no extra database read.' );
keel_assert( $clauses['where'] . " AND (wp_posts.post_password = '')" === $actual['where'], 'Anonymous searches exclude protected rows.' );
$GLOBALS['keel_search_logged_in'] = true;
$wpdb->rows                       = array( (object) array( 'ID' => 1 ), (object) array( 'ID' => 2 ), (object) array( 'ID' => 3 ) );
$actual                           = keel_defaults_protect_search( $clauses, $query );
keel_assert( $clauses['where'] . " AND (wp_posts.post_password = '' OR wp_posts.ID IN (2,3))" === $actual['where'], 'Only per-post editing or password access retains a protected result.' );
keel_assert( false === strpos( $wpdb->sql, 'LIMIT' ), 'Access checks precede pagination.' );
keel_assert( false !== strpos( $wpdb->sql, $clauses['join'] ) && false !== strpos( $wpdb->sql, $clauses['where'] ) && false !== strpos( $wpdb->sql, 'GROUP BY wp_posts.ID' ), 'Candidates retain query constraints.' );
keel_assert( $wpdb->rows === $GLOBALS['keel_search_primed'], 'Candidate objects are primed together.' );
unset( $actual['where'], $clauses['where'] );
keel_assert( $clauses === $actual, 'Only the where clause changes; order, fields, pagination and joins remain intact.' );
$wpdb->rows = null;
$actual     = keel_defaults_protect_search(
	array(
		'where'   => '',
		'join'    => '',
		'groupby' => '',
	),
	$query
);
keel_assert( " AND (wp_posts.post_password = '')" === $actual['where'], 'A failed candidate read fails closed for protected results.' );
keel_assert( 'yes' === keel_defaults_schema()['protect_search']['default'], 'Search privacy defaults on.' );
echo "Protected search clause contracts passed.\n";
