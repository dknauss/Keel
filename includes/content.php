<?php
/**
 * Content defaults: the parts of the comment teardown that need real logic.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replace the author name in feeds while author archives are disabled.
 *
 * `the_author` is what RSS puts in `<dc:creator>` and Atom in `<author><name>`,
 * so a feed lists the display name of every post's author whether or not the
 * author pages exist. Redirecting `/author/x/` closes the page and leaves the
 * list; this closes the list.
 *
 * Only in feeds. Bylines on the site itself are editorial, and a plugin that
 * silently renamed them everywhere would be doing something nobody asked for.
 *
 * @param string $author Display name.
 * @return string
 */
function keel_defaults_mask_feed_author( $author ) {
	if ( ! is_feed() ) {
		return $author;
	}

	return (string) apply_filters( 'keel_feed_author_name', __( 'Site Contributor', 'keel' ) );
}

/**
 * Comment types that keep working while comments are disabled.
 *
 * WordPress 6.9's Notes feature stores editorial notes as comments of type
 * `note`. They are not public commentary and have nothing to do with the
 * comment form, so switching comments off must not take them with it.
 *
 * Deliberately does NOT include `comment`: see keel_defaults_empty_comment_queries().
 *
 * @return string[]
 */
function keel_defaults_allowed_comment_types() {
	return array_map( 'strval', (array) apply_filters( 'keel_allowed_comment_types', array( 'note' ) ) );
}

/**
 * Short-circuit comment queries while comments are disabled.
 *
 * `comments_open` and `comments_array` only cover the theme's comment template.
 * Everything else that reads comments — `/wp/v2/comments`, a Recent Comments
 * widget, `wp_count_comments()`, a plugin's own WP_Comment_Query — goes straight
 * to the database and answers normally, so a site with comments "disabled" still
 * serves them to anyone who asks the API. This closes that by answering the
 * query itself.
 *
 * Note where this diverges from the Pixel Managed Platform implementation it is
 * adapted from. PX lets any query that explicitly asks for type `comment`
 * through, on the reasoning that code deliberately asking for comments should
 * get them. But core's REST controller declares `'type' => array( 'default' =>
 * 'comment' )`, so *every* `GET /wp/v2/comments` arrives asking for exactly that
 * type — the carve-out covers the main exposure it was meant to leave closed.
 * Keel allows only genuinely different comment types (Notes), and lets
 * `comment` be answered as empty, because that is what the toggle says.
 *
 * Nothing is deleted. Turn the default off and every comment is queryable again.
 *
 * @param array|int|null    $comment_data Short-circuit value, null to run the query.
 * @param \WP_Comment_Query $query     The query being run.
 * @return array|int|null
 */
function keel_defaults_empty_comment_queries( $comment_data, $query ) {
	// Without query vars there is no way to tell an allowed type from a
	// disallowed one, so answer empty rather than guess open.
	if ( ! is_object( $query ) || ! isset( $query->query_vars ) || ! is_array( $query->query_vars ) ) {
		return array();
	}

	$allowed = keel_defaults_allowed_comment_types();

	// Both query vars accept a string or an array.
	$requested = array();
	foreach ( array( 'type', 'type__in' ) as $var ) {
		$value = isset( $query->query_vars[ $var ] ) ? $query->query_vars[ $var ] : '';

		if ( '' === $value || null === $value || array() === $value ) {
			continue;
		}

		$requested = array_merge( $requested, array_map( 'strval', (array) $value ) );
	}

	if ( ! empty( $requested ) && ! empty( array_intersect( $requested, $allowed ) ) ) {
		return $comment_data;
	}

	// A count query expects a number, not a list.
	if ( ! empty( $query->query_vars['count'] ) ) {
		return 0;
	}

	return array();
}

/**
 * Comment blocks removed from the inserter while comments are disabled.
 *
 * @return string[]
 */
function keel_defaults_comment_blocks() {
	return (array) apply_filters(
		'keel_comment_blocks',
		array(
			'core/comment-author-name',
			'core/comment-content',
			'core/comment-date',
			'core/comment-edit-link',
			'core/comment-reply-link',
			'core/comment-template',
			'core/comments',
			'core/comments-pagination',
			'core/comments-pagination-next',
			'core/comments-pagination-numbers',
			'core/comments-pagination-previous',
			'core/comments-title',
			'core/latest-comments',
			'core/post-comments',
			'core/post-comments-form',
		)
	);
}

/**
 * Stop the comment blocks rendering on the front end while comments are disabled.
 *
 * The inserter filter, keel_defaults_remove_comment_blocks(), only decides what
 * an editor can add next. A block theme ships the comment blocks inside its
 * single/post templates already, so on its own that filter leaves every post
 * printing a "Comments" heading above an empty block wrapper — the site reads as
 * broken rather than as one that deliberately has no comments.
 *
 * Returning an empty string rather than unregistering the block types keeps this
 * reversible: the blocks stay registered, the template markup is untouched, and
 * turning the default off brings the whole thing back.
 *
 * @param string $block_content Rendered block markup.
 * @param array  $block         Parsed block, including its blockName.
 * @return string
 */
function keel_defaults_suppress_comment_blocks( $block_content, $block ) {
	if ( ! isset( $block['blockName'] ) ) {
		return $block_content;
	}

	if ( in_array( $block['blockName'], keel_defaults_comment_blocks(), true ) ) {
		return '';
	}

	return $block_content;
}

/**
 * Answer comment feed requests with a 404 while comments are disabled.
 *
 * Removing the <link rel="alternate"> markup only stops the feed being
 * advertised; /comments/feed/ and <post>/feed/ keep answering 200 for anyone who
 * types the URL. Because comment queries are already short-circuited the feed
 * comes back empty, which is arguably worse than either extreme: a live,
 * crawlable endpoint that exists solely to say nothing.
 *
 * set_404() re-runs init_query_flags(), which clears is_feed() as well — so the
 * template loader stops routing to do_feed() and renders the theme's 404
 * instead. That ordering is why this runs before the flags are read, and why
 * is_comment_feed() has to be tested first.
 *
 * redirect_canonical() has to go with it. It does not bail on a 404 — it calls
 * redirect_guess_404_permalink() and, on a query this one has just emptied,
 * answers /post-name/feed/ with a 301 to /post-name/feed/feed/. Leaving it in
 * place turns a clean 404 into a redirect to a URL that never existed.
 *
 * @return void
 */
function keel_defaults_block_comment_feeds() {
	if ( ! is_comment_feed() ) {
		return;
	}

	global $wp_query;

	if ( $wp_query instanceof WP_Query ) {
		$wp_query->set_404();
	}

	remove_action( 'template_redirect', 'redirect_canonical' );

	status_header( 404 );
	nocache_headers();
}

/**
 * Drop the comment blocks from the editor while comments are disabled.
 *
 * Offering a Comments block on a site with comments off produces a block that
 * renders nothing, which reads as a broken theme rather than a deliberate
 * setting.
 *
 * The two edge cases in the incoming value are the whole difficulty:
 *
 * - `false` means another filter has already disallowed every block. Expanding
 *   it to "all registered blocks minus comments" would re-enable everything that
 *   filter just turned off, so it is returned untouched.
 * - `array()` is also a deny-all — WordPress treats an empty list the same as
 *   `false` — so it must not be expanded either. The loop below returns it
 *   unchanged, which is correct by construction.
 *
 * @param bool|string[] $allowed_block_types Allowed block types, or true/false.
 * @return bool|string[]
 */
function keel_defaults_remove_comment_blocks( $allowed_block_types ) {
	if ( false === $allowed_block_types ) {
		return false;
	}

	// Expand to every registered block only when the value is not already an
	// explicit list (true, or something unexpected).
	if ( ! is_array( $allowed_block_types ) ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $allowed_block_types;
		}

		$allowed_block_types = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
	}

	$disallowed = keel_defaults_comment_blocks();
	$filtered   = array();

	foreach ( $allowed_block_types as $block ) {
		if ( ! in_array( $block, $disallowed, true ) ) {
			$filtered[] = $block;
		}
	}

	return $filtered;
}

/**
 * Drop the emoji plugin from the TinyMCE plugin list.
 *
 * Core registers `wpemoji` for the classic editor separately from the front-end
 * and admin-head emoji output, so removing those actions leaves this one
 * loading. Anything that is not an array is replaced rather than filtered: the
 * filter contract is a list, and a plugin returning something else has already
 * broken it.
 *
 * @param mixed $plugins TinyMCE plugin list.
 * @return array
 */
function keel_defaults_remove_emoji_tinymce_plugin( $plugins ) {
	return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : array();
}
