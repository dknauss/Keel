<?php
/**
 * Content defaults: the parts of the comment teardown that need real logic.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
