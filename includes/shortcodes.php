<?php
/**
 * Conservative shortcode residue cleanup and passive, bounded diagnostics.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip strong shortcode syntax, preserving enclosed text and literal examples.
 *
 * Registered callbacks are never invoked here or given different input. Bare
 * bracketed words are ambiguous and stay unless explicitly identified by a site.
 * HTML attributes, comments and code-like elements are opaque to this parser.
 *
 * @param string        $content Rendered content before core's do_shortcode().
 * @param array<string> $tags    Missing tags found, passed by reference.
 * @param int           $depth   Bounded nesting depth.
 * @return string
 */
function keel_defaults_strip_shortcode_residue( $content, &$tags, $depth = 0 ) {
	if ( false === strpos( $content, '[' ) || $depth >= 8 ) {
		return $content;
	}

	// Tokens cannot collide with any part of the input, including literal code.
	$prefix = "\x1Akeel";
	while ( false !== strpos( $content, $prefix ) ) {
		$prefix .= '_';
	}
	$opaque = array();
	$masked = preg_replace_callback(
		'~<!--.*?(?:-->|$)|<(pre|code|script|style|textarea)\b[^>]*>.*?(?:</\1\s*>|$)|<[^>"\']*(?:"[^"]*"[^>"\']*|\'[^\']*\'[^>"\']*)*>|`+[^`]*`+~is',
		static function ( $piece ) use ( &$opaque, $prefix ) {
			$key            = $prefix . count( $opaque ) . "\x1A";
			$opaque[ $key ] = $piece[0];
			return $key;
		},
		$content
	);
	if ( null === $masked ) {
		return $content;
	}
	/**
	 * Identify missing bare tags that would otherwise be indistinguishable from prose.
	 *
	 * Registered tags always win. No callbacks are registered or executed.
	 *
	 * @param string[] $tags Missing bare tag names confirmed by the site owner.
	 */
	$confirmed = (array) apply_filters( 'keel_shortcode_residue_tags', array() );
	// Ordinary citations need no enclosing-tag parser or candidate allocations.
	if ( ! $confirmed && ! preg_match( '~\[/[A-Za-z_][A-Za-z0-9_-]{0,63}\]|\[[^\[\]]*(?:/\]|\s[A-Za-z_][A-Za-z0-9_-]*\s*=)~', $masked ) ) {
		return $content;
	}
	if ( ! preg_match_all( '/\[\[?([A-Za-z_][A-Za-z0-9_-]{0,63})(?=[\s\]\/])([^\[\]]*)\]/', $masked, $captures, PREG_SET_ORDER )
		|| count( $captures ) > 100 ) {
		return $content;
	}
	preg_match_all( '/\[\/([A-Za-z_][A-Za-z0-9_-]{0,63})\]/', $masked, $closings );
	$paired  = array_fill_keys( $closings[1], true );
	$names   = array();
	$missing = false;
	foreach ( $captures as $capture ) {
		$name       = $capture[1];
		$registered = shortcode_exists( $name );
		$strong     = '/' === substr( rtrim( $capture[2] ), -1 )
			|| preg_match( '/\s[A-Za-z_][A-Za-z0-9_-]*\s*=/', $capture[2] )
			|| isset( $paired[ $name ] )
			|| in_array( $name, $confirmed, true );
		if ( $registered || $strong ) {
			$names[ $name ] = $name;
			$missing        = $missing || ! $registered;
		}
	}
	// Do not ask core's enclosing-tag regex to search for thousands of nonexistent
	// closing tags in ordinary citations. Only strong candidates need that pass.
	if ( ! $missing ) {
		return $content;
	}
	// Core permits registered names beyond this feature's conservative ASCII
	// grammar (for example namespace:tag). Protect those handlers' input too.
	global $shortcode_tags;
	preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $masked, $registered_names );
	if ( count( $registered_names[1] ) > 100 ) {
		return $content;
	}
	foreach ( array_intersect( array_keys( (array) $shortcode_tags ), $registered_names[1] ) as $registered_name ) {
		$names[ $registered_name ] = $registered_name;
	}
	$found  = array();
	$result = preg_replace_callback(
		'/' . get_shortcode_regex( $names ) . '/s',
		static function ( $piece ) use ( &$found, $confirmed, $depth ) {
			if ( shortcode_exists( $piece[2] ) || '[' === $piece[1] || ']' === $piece[6] ) {
				return $piece[0];
			}
			$paired = isset( $piece[5] ) && '' !== $piece[5];
			// Empty enclosing tags also carry unambiguous closing syntax.
			$paired = $paired || false !== strpos( $piece[0], '[/' . $piece[2] . ']' );
			if ( ! $paired && '/' !== $piece[4] && ! preg_match( '/\s[A-Za-z_][A-Za-z0-9_-]*\s*=/', $piece[3] )
				&& ! in_array( $piece[2], $confirmed, true ) ) {
				return $piece[0];
			}
			$found[] = $piece[2];
			return $paired ? keel_defaults_strip_shortcode_residue( $piece[5], $found, $depth + 1 ) : '';
		},
		$masked
	);
	if ( null === $result ) {
		return $content;
	}
	$tags = array_values( array_unique( array_merge( $tags, $found ) ) );
	return strtr( $result, $opaque );
}

/**
 * Read a small observation record, never search or render stored posts.
 *
 * Each entry ages independently: visiting one broken page cannot keep an old
 * observation about a different page alive. A healthy render never reads this.
 *
 * @return array<int, array{tags: list<string>, seen: int}>
 */
function keel_defaults_shortcode_observations() {
	$stored = get_transient( 'keel_shortcode_residue' );
	$live   = array();
	$now    = time();
	if ( ! is_array( $stored ) ) {
		return $live;
	}
	foreach ( $stored as $id => $entry ) {
		if ( ! is_int( $id ) || $id < 1 || ! is_array( $entry ) || ! isset( $entry['tags'], $entry['seen'] )
			|| ! is_array( $entry['tags'] ) || ! is_int( $entry['seen'] )
			|| $entry['seen'] <= $now - HOUR_IN_SECONDS || $entry['seen'] > $now ) {
			continue;
		}
		$tags = array();
		foreach ( $entry['tags'] as $tag ) {
			if ( is_string( $tag ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $tag ) ) {
				$tags[] = $tag;
			}
			if ( count( $tags ) >= 10 ) {
				break;
			}
		}
		if ( $tags ) {
			$live[ $id ] = array(
				'tags' => array_values( array_unique( $tags ) ),
				'seen' => $entry['seen'],
			);
		}
		if ( count( $live ) >= 20 ) {
			break;
		}
	}
	return $live;
}

/**
 * Persist a changed answer only; do not refresh a standing observation's TTL.
 *
 * @param int           $post_id Affected post ID.
 * @param array<string> $tags    Missing tags, without attributes or content.
 * @return void
 */
function keel_defaults_record_shortcode_residue( $post_id, $tags ) {
	$tags = array_slice( array_values( array_unique( $tags ) ), 0, 10 );
	sort( $tags );
	if ( $post_id < 1 || ! $tags ) {
		return;
	}
	$known = keel_defaults_shortcode_observations();
	if ( isset( $known[ $post_id ] ) && $known[ $post_id ]['tags'] === $tags ) {
		return;
	}
	if ( ! isset( $known[ $post_id ] ) && count( $known ) >= 20 ) {
		return;
	}
	$known[ $post_id ] = array(
		'tags' => $tags,
		'seen' => time(),
	);
	set_transient( 'keel_shortcode_residue', $known, HOUR_IN_SECONDS );
}

/**
 * Hide public post-content residue while retaining editors' evidence.
 *
 * REST is left unchanged, including editor previews. This is presentation cleanup,
 * not a privacy boundary: caches may serve an editor's version to other visitors.
 *
 * @param string $content Filtered post content.
 * @return string
 */
function keel_defaults_hide_shortcode_residue( $content ) {
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || false === strpos( $content, '[' ) ) {
		return $content;
	}
	$post = get_post();
	if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
		return $content;
	}
	$tags   = array();
	$result = keel_defaults_strip_shortcode_residue( $content, $tags );
	if ( ! $tags ) {
		return $content;
	}
	keel_defaults_record_shortcode_residue( $post->ID, $tags );
	return current_user_can( 'edit_post', $post->ID ) ? $content : $result;
}

/**
 * Report recent rendered-page evidence without scanning or executing content.
 *
 * @return array{label: string, status: "good"|"recommended", badge: array{label: string, color: "blue"}, description: string, test: string}
 */
function keel_defaults_site_health_shortcodes() {
	$result = array(
		'label'       => __( 'No recent shortcode residue observed by Keel', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Keel', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Keel observes visited front-end post content, not every stored post. It keeps up to 20 post IDs and 10 missing tag names per post for an hour. Fixed pages can remain listed until that observation expires. Bare bracketed words, code examples and REST content are left unchanged.', 'keel-defaults' ) . '</p>',
		'test'        => 'keel_defaults_shortcodes',
	);
	if ( ! keel_defaults_enabled( 'hide_shortcode_residue' ) ) {
		$result['label'] = __( 'Keel shortcode cleanup is disabled', 'keel-defaults' );
		return $result;
	}
	$items = '';
	foreach ( keel_defaults_shortcode_observations() as $id => $entry ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $id ) || 'trash' === $post->post_status ) {
			continue;
		}
		$items .= '<li><a href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">'
			/* translators: %d: affected post ID. */
			. esc_html( sprintf( __( 'Post #%d', 'keel-defaults' ), $id ) ) . '</a>: <code>'
			. esc_html( implode( ', ', $entry['tags'] ) ) . '</code></li>';
	}
	if ( '' !== $items ) {
		$result['status']       = 'recommended';
		$result['label']        = __( 'Recent pages contain missing shortcode handlers', 'keel-defaults' );
		$result['description'] .= '<p>' . esc_html__( 'Keel hides recognizable residue from readers while keeping enclosed text. People who can edit a post still see its residue. Restore the missing plugin or update the content; hiding markup does not restore the missing output. These are observations, not proof that a plugin supplied each tag.', 'keel-defaults' ) . '</p><ul>' . $items . '</ul>';
	}
	return $result;
}
