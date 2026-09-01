<?php
/**
 * Every route that can expose a disabled thing is actually covered.
 *
 * `comments_pre_query` filters WP_Comment_Query, which is every listing path —
 * and is not the only way a comment reaches a reader.
 * WP_REST_Comments_Controller::get_comment() calls core's get_comment(), which
 * goes through WP_Comment::get_instance() straight to the row without building
 * a query. So /wp/v2/comments/123 answered on a site with comments switched
 * off, and the filter that was supposed to prevent it was correct, registered,
 * and simply not on that path.
 *
 * Nothing contested anything, so conflict detection could not see it. Nothing
 * diverged from a filtered value, so the divergence report could not see it.
 * The filter was never called, and a hook that is never called leaves no trace
 * to notice. What catches that is a list of the ways in, written down, checked
 * against what the plugin registers.
 *
 * This is a source check and it proves registration, not behaviour: it says the
 * exit is hooked, not that the hook returns the right thing. The unit suites
 * test the callbacks. What was missing was anybody asking whether the set of
 * callbacks covered the set of routes.
 *
 * Adding an exit to a policy means adding it here. If a route is deliberately
 * left open — as oEmbed is when the REST gate closes — say so in `open` with
 * the reason, so the decision is visible rather than absent.
 *
 * Run: php tests/route-coverage.php
 *
 * @package keel
 */

$root = dirname( __DIR__ );
$fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

$policies = array(
	'disable_comments'        => array(
		'label'  => 'comments are off',
		// The value is the registration itself, not the hook name. Matching a
		// hook name alone passes vacuously: 'get_comment' is a substring of
		// 'get_comments_number' and appears in half a dozen docblocks, so
		// deleting the registration this exists to require changed nothing.
		// Verified by deleting it — see the note at the top of this file.
		'routes' => array(
			'comment listing queries'      => "'comments_pre_query', 'keel_defaults_empty_comment_queries'",
			'a single comment by ID'       => "'get_comment', 'keel_defaults_hide_rest_comment'",
			'the comments array on a post' => "'comments_array', '__return_empty_array'",
			'the comment count'            => "'get_comments_number', 'keel_defaults_return_zero'",
			'whether the form is shown'    => "'comments_open', 'keel_defaults_return_false'",
			'whether pings are accepted'   => "'pings_open', 'keel_defaults_return_false'",
			'the comment feed'             => "'template_redirect', 'keel_defaults_block_comment_feeds'",
			'feed discovery links'         => "'feed_links_show_comments_feed', '__return_false'",
			'comment blocks in the editor' => "'allowed_block_types_all', 'keel_defaults_remove_comment_blocks'",
			'comment blocks already saved' => "'render_block', 'keel_defaults_suppress_comment_blocks'",
		),
	),
	'disable_author_archives' => array(
		'label'  => 'author archives are off',
		'routes' => array(
			'the archive URL and /?author=N' => "'template_redirect', 'keel_defaults_redirect_author_archive'",
			'the author feed'                => "'template_redirect', 'keel_defaults_block_author_feeds'",
			'the users sitemap'              => "'wp_sitemaps_add_provider', 'keel_defaults_drop_users_sitemap'",
		),
	),
);

$sources = '';
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	$sources .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source in a CLI test.
}

// Collapse whitespace so a registration split across lines still matches.
$normalized = preg_replace( '/\s+/', ' ', $sources );

$checked = 0;

foreach ( $policies as $setting => $policy ) {
	keel_assert(
		false !== strpos( $sources, "'" . $setting . "'" ),
		sprintf( 'the %s setting still exists', $setting )
	);

	foreach ( $policy['routes'] as $route => $token ) {
		++$checked;
		keel_assert(
			false !== strpos( $normalized, $token ),
			sprintf(
				'when %s, nothing covers %s — expected a registration of %s',
				$policy['label'],
				$route,
				$token
			)
		);
	}
}

keel_assert( $checked > 0, 'the checklist has routes in it' );

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "route coverage: OK ({$checked} routes across " . count( $policies ) . " policies)\n";
