<?php
/**
 * Content defaults: the parts of the comment teardown that need real logic.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply Keel's stored revision limit.
 *
 * @param int      $number Current revision limit.
 * @param \WP_Post $post   Post being revisioned.
 * @return int
 */
function keel_defaults_revision_limit( $number, $post ) {
	unset( $number, $post );

	return (int) keel_defaults_get( 'post_revisions_limit' );
}

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

	return (string) apply_filters( 'keel_feed_author_name', __( 'Site Contributor', 'keel-defaults' ) );
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
 * Hide disallowed comment types from the REST single-item route.
 *
 * The comments_pre_query filter covers WP_Comment_Query, every listing path —
 * but not this one. WP_REST_Comments_Controller::get_comment() calls core's
 * get_comment(), which goes through WP_Comment::get_instance() straight to the
 * row. No query object is built, so the filter that empties comment queries is
 * never consulted, and /wp/v2/comments/123 returned a comment on a site whose
 * comments are switched off.
 *
 * That mattered more than the usual missed hook: "disabling something means it
 * is actually disabled" is the claim this plugin leads with, and this was a
 * documented route where it was not true.
 *
 * Scoped to REST rather than filtering get_comment() globally. The admin,
 * wp_notify_postauthor(), and every other caller still get real comments; only
 * the public API is answered according to the policy. Type-aware for the same
 * reason the query filter is: a site may disable comments while still accepting
 * pingbacks, and those must keep working.
 *
 * @param WP_Comment|null $comment The comment, or null.
 * @return WP_Comment|null
 */
function keel_defaults_hide_rest_comment( $comment ) {
	if ( ! is_object( $comment ) || ! isset( $comment->comment_type ) ) {
		return $comment;
	}

	if ( ! function_exists( 'wp_is_rest_request' ) || ! wp_is_rest_request() ) {
		return $comment;
	}

	$type = '' === (string) $comment->comment_type ? 'comment' : (string) $comment->comment_type;

	if ( in_array( $type, keel_defaults_allowed_comment_types(), true ) ) {
		return $comment;
	}

	// The controller turns an empty comment into its own 404, which is the
	// honest answer: on this site that route has nothing to return.
	return null;
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
 * Core's set_404() clears the other query flags but deliberately restores
 * is_feed afterwards. Keel clears it explicitly so the template loader stops
 * routing to do_feed() and renders the theme's 404 instead. That ordering is why
 * this runs before the flags are read, and why is_comment_feed() has to be tested
 * first.
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
		$wp_query->is_feed = false;
	}

	remove_action( 'template_redirect', 'redirect_canonical' );

	status_header( 404 );
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	}
	nocache_headers();
}

/**
 * Answer an author-feed request with a real 404 while author archives are off.
 *
 * WordPress marks `/author/{slug}/feed/` as both an author query and a feed.
 * Keel's existing `is_author()` redirect therefore already closed it with the
 * same 301 used for the HTML archive. A feed is not a useful redirect target,
 * though: it is a machine endpoint mirroring a public surface the site disabled.
 * Return 404 explicitly before the archive redirect runs.
 *
 * @return void
 */
function keel_defaults_block_author_feeds() {
	if ( ! is_author() || ! is_feed() ) {
		return;
	}

	global $wp_query;

	if ( $wp_query instanceof WP_Query ) {
		$wp_query->set_404();
		// WP_Query::set_404() preserves the feed flag; the 404 template needs it off.
		$wp_query->is_feed = false;
	}

	remove_action( 'template_redirect', 'redirect_canonical' );

	status_header( 404 );
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	}
	nocache_headers();
}

/**
 * Redirect a disabled HTML author archive to the site home page.
 *
 * Author feeds are converted to a 404 at priority 9, whose `set_404()` call
 * clears the author flag before this priority-10 callback runs.
 *
 * @return void
 */
function keel_defaults_redirect_author_archive() {
	if ( ! is_author() ) {
		return;
	}

	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
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

/**
 * Remove author identity from oEmbed responses.
 *
 * `oembed/1.0/embed` returns `author_name` and `author_url` for every post, and
 * the URL carries the account's nicename. Redirecting `/author/` archives does
 * nothing about it, so a site that had hidden its authors was still handing out
 * login names through a route nobody thinks of as a user endpoint.
 *
 * Measured on a live install before the fix: with `disable_author_archives` on
 * and `/author/admin/` answering 301, oEmbed still returned `admin` and
 * `http://example.test/author/admin/`.
 *
 * The rest of the response — title, provider, and the embed markup — is left
 * alone, so embeds on other sites keep working.
 *
 * @param mixed $data oEmbed response data.
 * @return mixed
 */
function keel_defaults_strip_oembed_author( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	unset( $data['author_name'], $data['author_url'] );

	return $data;
}

/**
 * Drop core's users sitemap when author archives are hidden.
 *
 * `wp-sitemap-users-1.xml` lists every author's archive URL by nicename. With
 * the archives redirected those URLs go nowhere, but the sitemap is still a
 * published list of account names — an enumeration source by construction, and
 * one that search engines are explicitly invited to read.
 *
 * Removing the provider is the whole fix; the rest of the sitemap index is
 * untouched, so posts and pages stay listed.
 *
 * @param mixed  $provider Sitemap provider instance, or false.
 * @param string $name     Provider name.
 * @return mixed
 */
function keel_defaults_drop_users_sitemap( $provider, $name ) {
	return ( 'users' === $name ) ? false : $provider;
}
