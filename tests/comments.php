<?php
/**
 * Lightweight regression test for the comment teardown.
 *
 * The interesting case is the one that motivated this: core's REST comments
 * controller declares `'type' => array( 'default' => 'comment' )`, so every
 * GET /wp/v2/comments arrives asking for type `comment`. Any implementation
 * that waves `comment`-typed queries through therefore leaves the API exposure
 * open — which is exactly what the upstream implementation this is adapted from
 * does. Notes (type `note`) must keep working regardless.
 *
 * Run: php tests/comments.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }

/*
 * wp_is_rest_endpoint(), the core function (6.5+). Not wp_is_rest_request():
 * WordPress defines no such function, and the guard was written against it
 * behind function_exists(), so on every real site it silently never fired.
 */
function wp_is_rest_endpoint() { return ! empty( $GLOBALS['keel_is_rest'] ); }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
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

/**
 * Stand-in for WP_Comment_Query: only query_vars is consulted.
 *
 * @param array $vars Query vars.
 * @return object
 */
function keel_comment_query( $vars ) {
	$query             = new stdClass();
	$query->query_vars = $vars;
	return $query;
}

// --- allowed types ---
keel_assert( array( 'note' ) === keel_defaults_allowed_comment_types(), 'Notes are allowed through by default.' );
$GLOBALS['keel_filters']['keel_allowed_comment_types'] = array( 'note', 'workflow' );
keel_assert( in_array( 'workflow', keel_defaults_allowed_comment_types(), true ), 'The allowed-type list is filterable.' );
unset( $GLOBALS['keel_filters']['keel_allowed_comment_types'] );

// --- the REST case: type=comment must NOT be waved through ---
$rest_like = keel_comment_query( array( 'type' => 'comment' ) );
keel_assert(
	array() === keel_defaults_empty_comment_queries( null, $rest_like ),
	'A type=comment query is answered empty — this is every GET /wp/v2/comments, since core defaults the type param to "comment".'
);

// --- a plain query, no type at all ---
keel_assert( array() === keel_defaults_empty_comment_queries( null, keel_comment_query( array() ) ), 'An untyped comment query is answered empty.' );

// --- notes keep working ---
$note_query = keel_comment_query( array( 'type' => 'note' ) );
keel_assert( null === keel_defaults_empty_comment_queries( null, $note_query ), 'A note query runs normally (WordPress 6.9 Notes).' );

$note_in = keel_comment_query( array( 'type__in' => array( 'note' ) ) );
keel_assert( null === keel_defaults_empty_comment_queries( null, $note_in ), 'type__in is honoured as well as type.' );

$mixed = keel_comment_query( array( 'type__in' => array( 'comment', 'note' ) ) );
keel_assert( null === keel_defaults_empty_comment_queries( null, $mixed ), 'A query asking for notes among other types still runs.' );

// --- count queries expect a number ---
$count = keel_comment_query( array( 'count' => true ) );
keel_assert( 0 === keel_defaults_empty_comment_queries( null, $count ), 'A count query is answered with 0, not an array.' );

$typed_count = keel_comment_query(
	array(
		'type'  => 'comment',
		'count' => true,
	)
);
keel_assert( 0 === keel_defaults_empty_comment_queries( null, $typed_count ), 'A type=comment count query is answered with 0.' );

// --- an unusable query object fails closed ---
keel_assert( array() === keel_defaults_empty_comment_queries( null, null ), 'A query object without query vars is answered empty rather than let through.' );

// --- block removal ---
keel_assert( false === keel_defaults_remove_comment_blocks( false ), 'A prior deny-all (false) is preserved, not expanded.' );
keel_assert( array() === keel_defaults_remove_comment_blocks( array() ), 'An empty allowlist is a deliberate deny-all and stays empty.' );

$allowed = keel_defaults_remove_comment_blocks( array( 'core/paragraph', 'core/comments', 'core/latest-comments', 'core/image' ) );
keel_assert( array( 'core/paragraph', 'core/image' ) === array_values( $allowed ), 'Comment blocks are removed from an explicit allowlist; everything else survives.' );

$GLOBALS['keel_filters']['keel_comment_blocks'] = array( 'core/image' );
$custom = keel_defaults_remove_comment_blocks( array( 'core/paragraph', 'core/image' ) );
keel_assert( array( 'core/paragraph' ) === array_values( $custom ), 'The disallowed-block list is filterable.' );
unset( $GLOBALS['keel_filters']['keel_comment_blocks'] );

// true expands from the registry; with no registry available the value passes through.
keel_assert( true === keel_defaults_remove_comment_blocks( true ), 'Without a block registry the value is left alone rather than emptied.' );

/*
 * --- the zero comment count keeps core's string type ---
 *
 * core's get_comments_number() normally returns $post->comment_count, which
 * comes off the database as a *string*. Its own docblock says string|int, and
 * most consumers cast — but not all of them.
 *
 * wp-includes/blocks/comments-title.php does:
 *
 *     $comments_count = get_comments_number();
 *     if ( '0' === $comments_count ) { return; }
 *
 * a strict comparison. Returning int 0 there is not equal to '0', so the block
 * did not take its early return and rendered a comments title on a post Keel
 * had just reported as having no comments. Measured on WordPress 7.1 with a
 * block theme, which is the default.
 *
 * '0' is falsy and casts to 0, so every other core consumer — the (int) casts
 * and the truthiness checks — is unaffected.
 */
keel_assert( '0' === keel_defaults_return_zero(), 'The comment count is the string "0", the type core returns and compares against.' );
keel_assert( 0 === (int) keel_defaults_return_zero(), 'It still casts to int 0 for the consumers that cast.' );
keel_assert( ! keel_defaults_return_zero(), 'And it is still falsy, for the consumers that test truthiness.' );

/*
 * --- the REST single-comment route ---
 *
 * GET /wp/v2/comments/123 reads through get_comment(), not a comment query, so
 * keel_defaults_hide_rest_comment() guards it. It asked wp_is_rest_request(),
 * a function WordPress does not define, behind function_exists(): the check was
 * always false and the route kept serving comments with comments off. Measured
 * on WordPress 7.1 before this change: filter registered, disable_comments on,
 * and the route still answered 200. The registration test in route-coverage.php
 * could not see it, because the filter was registered; it just never did anything.
 */
$rest_comment               = new stdClass();
$rest_comment->comment_type = 'comment';
$untyped                    = new stdClass();
$untyped->comment_type      = '';
$rest_note                  = new stdClass();
$rest_note->comment_type    = 'note';

$GLOBALS['keel_is_rest'] = true;
keel_assert( null === keel_defaults_hide_rest_comment( $rest_comment ), 'A comment read over REST is hidden, so the route answers 404.' );
keel_assert( null === keel_defaults_hide_rest_comment( $untyped ), 'An untyped comment is a comment, and is hidden too.' );
keel_assert( keel_defaults_hide_rest_comment( $rest_note ) === $rest_note, 'A Note is still readable over REST.' );
keel_assert( null === keel_defaults_hide_rest_comment( null ), 'A missing comment passes through untouched.' );

$GLOBALS['keel_is_rest'] = false;
keel_assert( keel_defaults_hide_rest_comment( $rest_comment ) === $rest_comment, 'Outside REST the comment is returned, so admin and notification paths still see it.' );

$keel_content_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/content.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
keel_assert( false === strpos( $keel_content_source, 'wp_is_rest_request' ), 'The REST guard does not depend on wp_is_rest_request(), which WordPress does not define.' );

echo "comments tests passed.\n";
