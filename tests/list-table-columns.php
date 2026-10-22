<?php
/** Standalone contracts for optional list-table presentation. @package Keel */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['settings']   = array();
$GLOBALS['hooks']      = array();
$GLOBALS['map_filter'] = null;
define( 'KEEL_DEFAULTS_OPTION', 'keel_defaults' );
define( 'KEEL_DEFAULTS_VERSION', 'test' );
function keel_defaults_asset_url( $file ) { return '/assets/' . $file; }
function wp_enqueue_style( ...$args ) { $GLOBALS['enqueued'][] = $args; }
function is_multisite() { return false; }
function get_option( $key, $default = false ) {
	$formats = array(
		'date_format' => 'F j, Y',
		'time_format' => 'g:i a',
	);
	return $formats[ $key ] ?? $GLOBALS['settings'];
}
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function get_post_time( $format, $gmt, $post, $translate ) { return 'F j, Y' === $format ? 'October 22, 2026' : '7:49 pm'; }
function get_post_modified_time( $format, $gmt, $post, $translate ) { return 'F j, Y' === $format ? 'October 23, 2026' : '9:05 am'; }
class WP_Post {
	public $post_status;
	public function __construct( $status ) { $this->post_status = $status; }
}
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Core stand-in.
}
function get_current_screen() { return $GLOBALS['screen']; }
function get_post_type_object( $type ) { return 'attachment' === $type ? null : (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts' ) ); }
function current_user_can( $cap ) { return $GLOBALS['allowed'] ?? true; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][ $hook ] = array( $callback, $priority ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = array( $callback, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) {
	if ( 'keel_defaults_column_labels' === $hook && is_callable( $GLOBALS['map_filter'] ) ) {
		return ( $GLOBALS['map_filter'] )( $value, ...$args ); }
	return 'list_table_primary_column' === $hook ? ( $GLOBALS['primary'] ?? $value ) : $value;
}
function get_column_headers( $screen ) { return $GLOBALS['columns']; }
class WP_Screen {
	public $base      = 'edit';
	public $post_type = 'post';
	public $id        = 'edit-post';
	public $network   = false;
	public function in_admin( $context ) { return 'network' === $context ? $this->network : true; }
}
function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/schema.php';
$schema = keel_defaults_schema();
foreach ( array( 'compact_column_labels', 'fewer_list_columns', 'tidy_date_columns' ) as $key ) {
	check( isset( $schema[ $key ] ) && 'no' === $schema[ $key ]['default'] && 'ux' === $schema[ $key ]['group'], "$key is independently opt-in in UX" );
}
require dirname( __DIR__ ) . '/includes/list-table-columns.php';
check( array() === $GLOBALS['hooks'], 'Module is declaration-only' );
$GLOBALS['screen']  = new WP_Screen();
$columns            = array(
	'cb'                      => 'Checkbox',
	'title'                   => 'Title',
	'jetpack_seo_description' => '<b>SEO & description</b>',
	'shortlink'               => 'Shortlink',
	'activitypub'             => 'Fediverse status',
	'activitypub_status'      => 'Unknown',
	'keel_id'                 => 'ID',
	'keel_modified'           => 'Modified',
	'keel_thumb'              => 'Image',
);
$GLOBALS['columns'] = $columns;
check( keel_defaults_compact_column_labels( $columns ) === $columns, 'Disabled labels unchanged' );
check( array( 'date' ) === keel_defaults_fewer_list_columns( array( 'date' ), $GLOBALS['screen'] ), 'Disabled defaults unchanged' );
keel_defaults_enqueue_compact_column_style();
check( empty( $GLOBALS['enqueued'] ), 'Disabled compact labels enqueue nothing' );
$GLOBALS['settings']['compact_column_labels'] = 'yes';
keel_defaults_enqueue_compact_column_style();
check( 'keel-defaults-list-table-columns' === $GLOBALS['enqueued'][0][0] && '/assets/list-table-columns.css' === $GLOBALS['enqueued'][0][1], 'Compact style enqueued from versioned static asset' );
keel_defaults_register_list_table_columns( $GLOBALS['screen'] );
check( isset( $GLOBALS['hooks']['manage_post_posts_columns'] ) && 1000 === $GLOBALS['hooks']['manage_post_posts_columns'][1], 'Late dynamic hook registered' );
$result = keel_defaults_compact_column_labels( $columns );
check( array_keys( $columns ) === array_keys( $result ), 'Keys and ordering preserved' );
check( false !== strpos( $result['jetpack_seo_description'], 'title="SEO &amp; description"' ), 'Stripped original escaped for tooltip' );
check( false !== strpos( $result['jetpack_seo_description'], 'aria-hidden="true" data-keel-column-label="Meta"></span>' ), 'Visible abbreviation excluded from announcement' );
check( false !== strpos( $result['jetpack_seo_description'], 'class="screen-reader-text">SEO &amp; description</span>' ), 'Original announced once' );
check( keel_defaults_compact_column_labels( $result ) === $result, 'Page plus dynamic filters do not duplicate labels' );
check( 'Unknown' === $result['activitypub_status'] && 'Title' === $result['title'], 'Only exact known keys renamed' );
check( 'Shortlink' === wp_strip_all_tags( $result['shortlink'] ), 'Core Screen Options stripping retains only full original label' );
check( false !== strpos( $result['shortlink'], 'data-keel-column-label="Link"' ) && false !== strpos( $result['activitypub'], 'data-keel-column-label="Fedi"' ), 'Link and Fedi compact labels' );
$GLOBALS['map_filter'] = static function ( $map, $type ) { check( 'post' === $type, 'Map receives post type' );
	return array(
		'shortlink' => '<img onerror="x">',
		'title'     => 'Bad',
		'cb'        => 'Bad',
		'keel_id'   => array(),
		'unknown'   => new stdClass(),
	);
};
$result                = keel_defaults_compact_column_labels( $columns );
check( false !== strpos( $result['shortlink'], '&lt;img' ) && 'Title' === $result['title'] && 'Checkbox' === $result['cb'], 'Filter strings escaped and protected columns unchanged' );
foreach ( array( null, false, 'broken', new stdClass() ) as $bad ) {
	$GLOBALS['map_filter'] = static function () use ( $bad ) { return $bad;
	};
	check( keel_defaults_compact_column_labels( $columns ) === $columns, 'Malformed map safely ignored' );
}
$GLOBALS['map_filter']                        = null;
$GLOBALS['settings']['compact_column_labels'] = 'no';
$GLOBALS['settings']['fewer_list_columns']    = 'yes';
check( array( 'date', 'jetpack_seo_description', 'shortlink', 'keel_id', 'keel_modified' ) === keel_defaults_fewer_list_columns( array( 'date' ), $GLOBALS['screen'] ), 'Only present allowlisted defaults hidden, independently of compaction' );
$GLOBALS['columns']['jetpack_seo_schema'] = 'Schema';
$GLOBALS['columns']['jetpack_seo_search'] = 'Search';
check( 6 === count( keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ) ), 'All six exact optional keys can be hidden' );
check( 6 === count( keel_defaults_fewer_list_columns( array( 'keel_id' ), $GLOBALS['screen'] ) ), 'No duplicate hidden keys' );
$GLOBALS['primary'] = 'shortlink';
check( ! in_array( 'shortlink', keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ), true ), 'Custom primary never hidden' );
unset( $GLOBALS['primary'] );
foreach ( array( 'users', 'upload', 'dashboard', 'post' ) as $base ) {
	$GLOBALS['screen']->base = $base;
	$GLOBALS['enqueued']     = array();
	keel_defaults_enqueue_compact_column_style();
	check( array() === $GLOBALS['enqueued'], 'Unrelated screen enqueues nothing' );
	check( keel_defaults_compact_column_labels( $columns ) === $columns && array() === keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ), 'Unrelated screens unchanged' );
}
$GLOBALS['screen']->base    = 'edit';
$GLOBALS['screen']->network = true;
check( array() === keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ), 'Network admin excluded' );
$GLOBALS['screen']->network = false;
$GLOBALS['allowed']         = false;
check( array() === keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ), 'No edit capability excluded' );
$GLOBALS['allowed']           = true;
$GLOBALS['screen']->post_type = 'attachment';
check( array() === keel_defaults_fewer_list_columns( array(), $GLOBALS['screen'] ), 'Attachment edit lists excluded' );
$GLOBALS['screen']->post_type = 'page';
$GLOBALS['screen']->id        = 'edit-page';
keel_defaults_register_list_table_columns( $GLOBALS['screen'] );
check( isset( $GLOBALS['hooks']['manage_pages_columns'], $GLOBALS['hooks']['manage_page_posts_columns'], $GLOBALS['hooks']['default_hidden_columns'] ), 'Page and defaults hooks registered' );
check( 'yes' === $schema['date_columns_show_time']['default'] && 'tidy_date_columns' === $schema['date_columns_show_time']['depends']['field'], 'Time defaults on and hides with tidy dates off' );
check( isset( $GLOBALS['hooks']['post_date_column_status'], $GLOBALS['hooks']['post_date_column_time'] ) && 3 === $GLOBALS['hooks']['post_date_column_time'][2], 'Date column filters registered' );
$published = new WP_Post( 'publish' );
$draft     = new WP_Post( 'draft' );
$scheduled = new WP_Post( 'future' );
$date_cols = array(
	'title' => 'Title',
	'date'  => 'Date',
);
check( $date_cols === keel_defaults_published_column_label( $date_cols ), 'Disabled tidy dates keep the Date heading' );
check( 'Published' === keel_defaults_date_column_status( 'Published', $published ) && 'core' === keel_defaults_date_column_time( 'core', $published ), 'Disabled tidy dates leave core cells alone' );
$GLOBALS['settings']['tidy_date_columns']      = 'yes';
$GLOBALS['settings']['date_columns_show_time'] = 'yes';
check( 'Published' === keel_defaults_published_column_label( $date_cols )['date'] && array( 'title' => 'Title' ) === keel_defaults_published_column_label( array( 'title' => 'Title' ) ), 'Date heading relabeled only when present' );
check( '' === keel_defaults_date_column_status( 'Published', $published ) && '' === keel_defaults_date_column_status( 'Last Modified', $draft ), 'Published and draft status lines dropped' );
check( 'Scheduled' === keel_defaults_date_column_status( 'Scheduled', $scheduled ), 'Scheduled keeps its label' );
check( 'Published' === keel_defaults_date_column_status( 'Published', $published, 'other' ), 'Other columns untouched' );
check( 'October 22, 2026 at 7:49 pm' === keel_defaults_date_column_time( 'core', $published ), 'Publish date uses site formats with time' );
check( 'October 23, 2026 at 9:05 am' === keel_defaults_list_date( $published, 'modified' ), 'Modified shares the format' );
check( false !== strpos( keel_defaults_date_column_time( 'core', $draft ), 'Not published' ) && 0 === strpos( keel_defaults_date_column_time( 'core', $draft ), '<span aria-hidden="true">&mdash;</span>' ), 'Drafts show a dash with a screen-reader label' );
$GLOBALS['settings']['date_columns_show_time'] = 'no';
check( 'October 22, 2026' === keel_defaults_date_column_time( 'core', $scheduled ) && 'October 23, 2026' === keel_defaults_list_date( $published, 'modified' ), 'Time toggle hides the time in both columns' );
echo "list table columns: OK\n";
