<?php
/**
 * Optional presentation defaults for post-type list tables.
 *
 * Declaration only: the admin bootstrap registers current_screen.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a screen is an editable site-admin post-type list.
 *
 * @param mixed $screen Candidate screen.
 * @return bool
 */
function keel_defaults_is_post_list_screen( $screen ) {
	if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base || ! $screen->in_admin( 'site' ) || $screen->in_admin( 'network' ) || empty( $screen->post_type ) || 'attachment' === $screen->post_type ) {
		return false;
	}
	$type = get_post_type_object( $screen->post_type );
	return $type && current_user_can( $type->cap->edit_posts );
}

/**
 * Register late filters for the current post type, even when settings are off.
 *
 * @param WP_Screen $screen Current screen.
 */
function keel_defaults_register_list_table_columns( $screen ) {
	if ( ! keel_defaults_is_post_list_screen( $screen ) ) {
		return;
	}
	add_action( 'admin_enqueue_scripts', 'keel_defaults_enqueue_compact_column_style' );
	add_action( 'admin_enqueue_scripts', 'keel_defaults_enqueue_fewer_columns_style' );
	add_filter( "manage_{$screen->post_type}_posts_columns", 'keel_defaults_published_column_label', 1000 );
	add_filter( "manage_{$screen->post_type}_posts_columns", 'keel_defaults_compact_column_labels', 1000 );
	if ( 'page' === $screen->post_type ) {
		add_filter( 'manage_pages_columns', 'keel_defaults_published_column_label', 1000 );
		add_filter( 'manage_pages_columns', 'keel_defaults_compact_column_labels', 1000 );
	}
	// Plugins such as Hum add columns on the screen filter, which core runs after the post-type ones.
	add_filter( "manage_{$screen->id}_columns", 'keel_defaults_compact_column_labels', 1000 );
	add_filter( 'post_date_column_status', 'keel_defaults_date_column_status', 1000, 3 );
	add_filter( 'post_date_column_time', 'keel_defaults_date_column_time', 1000, 3 );
	add_filter( 'default_hidden_columns', 'keel_defaults_fewer_list_columns', 1000, 2 );
}

/**
 * Enqueue abbreviations without adding text to core's stripped labels.
 *
 * Screen Options and responsive cell labels strip tags, ignoring aria-hidden.
 * Generated content leaves those consumers with the original full label only.
 */
function keel_defaults_enqueue_compact_column_style() {
	if ( ! keel_defaults_list_tables_tidy() || ! keel_defaults_is_post_list_screen( get_current_screen() ) ) {
		return;
	}
	wp_enqueue_style( 'keel-defaults-list-table-columns', keel_defaults_asset_url( 'list-table-columns.css' ), array(), KEEL_DEFAULTS_VERSION );
}

/**
 * Enqueue the rules that size post-list columns to their content.
 *
 * Core's fixed table layout ignores content: Author, Date, Categories and Tags
 * keep about 54% between them, and every other column, empty or not, gets an
 * equal share of the rest, Title included. Measured with every column showing
 * at 1280px, Title went from 76px to 170px; with fewer columns showing it
 * roughly doubles. Setting widths on the small columns made Title narrower,
 * so the stylesheet switches to auto layout and only sets Title's share.
 */
function keel_defaults_enqueue_fewer_columns_style() {
	if ( ! keel_defaults_list_tables_tidy() || ! keel_defaults_is_post_list_screen( get_current_screen() ) ) {
		return;
	}
	wp_enqueue_style( 'keel-defaults-list-table-fewer-columns', keel_defaults_asset_url( 'list-table-fewer-columns.css' ), array(), KEEL_DEFAULTS_VERSION );
}

/**
 * Shorten known labels while retaining their full accessible names.
 *
 * @param array $columns Column keys and labels.
 * @return array
 */
function keel_defaults_compact_column_labels( $columns ) {
	$screen = get_current_screen();
	if ( ! keel_defaults_list_tables_tidy() || ! keel_defaults_is_post_list_screen( $screen ) || ! is_array( $columns ) ) {
		return $columns;
	}

	/**
	 * Filters compact column labels, keyed by exact column ID.
	 *
	 * @param string[] $labels    Compact labels.
	 * @param string   $post_type Current post type.
	 */
	$labels = apply_filters(
		'keel_defaults_column_labels',
		array(
			'jetpack_seo_description' => __( 'Meta', 'keel-defaults' ),
			'shortlink'               => __( 'Link', 'keel-defaults' ),
			'activitypub'             => __( 'Fedi', 'keel-defaults' ),
		),
		$screen->post_type
	);
	if ( ! is_array( $labels ) ) {
		return $columns;
	}
	foreach ( $labels as $key => $label ) {
		if ( in_array( $key, array( 'cb', 'title' ), true ) || ! is_string( $label ) || '' === trim( $label ) || ! isset( $columns[ $key ] ) || ! is_string( $columns[ $key ] ) ) {
			continue;
		}
		// Pages run both the page and dynamic filters. Never wrap twice.
		if ( false !== strpos( $columns[ $key ], 'class="keel-compact-column-label"' ) ) {
			continue;
		}
		$original        = wp_strip_all_tags( $columns[ $key ] );
		$columns[ $key ] = '<span class="keel-compact-column-label" title="' . esc_attr( $original ) . '"><span aria-hidden="true" data-keel-column-label="' . esc_attr( $label ) . '"></span><span class="screen-reader-text">' . esc_html( $original ) . '</span></span>';
	}
	return $columns;
}

/**
 * Hide only present optional columns when core is calculating defaults.
 *
 * Core does not call this filter for saved preferences, including empty arrays.
 * Never write user options or filter hidden_columns here.
 *
 * @param string[]  $hidden Existing default hidden keys.
 * @param WP_Screen $screen List screen.
 * @return string[]
 */
function keel_defaults_fewer_list_columns( $hidden, $screen ) {
	if ( ! keel_defaults_list_tables_tidy() || ! keel_defaults_is_post_list_screen( $screen ) || ! is_array( $hidden ) ) {
		return $hidden;
	}
	$columns = get_column_headers( $screen );
	$primary = apply_filters( 'list_table_primary_column', 'title', $screen->id );
	foreach ( array( 'jetpack_seo_description', 'jetpack_seo_schema', 'jetpack_seo_search', 'shortlink', 'keel_id', 'keel_modified' ) as $key ) {
		if ( $key !== $primary && array_key_exists( $key, $columns ) && ! in_array( $key, $hidden, true ) ) {
			$hidden[] = $key;
		}
	}
	return $hidden;
}

/**
 * Date and time for a post-list cell, in the site's own formats.
 *
 * Core's Date column uses its translatable 'Y/m/d' and 'g:i a', not the
 * Settings → General formats. Published and Modified share this one so the
 * two columns read alike.
 *
 * @param int|WP_Post $post  Post.
 * @param string      $field 'date' for the publish date, 'modified' for the last edit.
 * @return string Unescaped; empty when the post has no such date.
 */
function keel_defaults_list_date( $post, $field = 'date' ) {
	$read = 'modified' === $field ? 'get_post_modified_time' : 'get_post_time';
	$date = $read( (string) get_option( 'date_format' ), false, $post, true );
	if ( ! is_string( $date ) || '' === $date ) {
		return '';
	}
	if ( ! keel_defaults_enabled( 'date_columns_show_time' ) ) {
		return $date;
	}
	return sprintf(
		/* translators: 1: Post date, 2: Post time. */
		__( '%1$s at %2$s', 'keel-defaults' ),
		$date,
		$read( (string) get_option( 'time_format' ), false, $post, true )
	);
}

/**
 * Head core's Date column "Published" while tidy date columns are on.
 *
 * @param array $columns Column keys and labels.
 * @return array
 */
function keel_defaults_published_column_label( $columns ) {
	if ( keel_defaults_list_tables_tidy() && is_array( $columns ) && isset( $columns['date'] ) ) {
		$columns['date'] = __( 'Published', 'keel-defaults' );
	}
	return $columns;
}

/**
 * Drop the status line the "Published" heading now carries.
 *
 * Scheduled and Missed schedule stay: a future date with no label reads as a
 * mistake. Everything else is either published (the heading says so) or has
 * no publish date at all, which the time cell shows.
 *
 * @param string  $status Status line, possibly markup.
 * @param WP_Post $post   Post.
 * @param string  $column Column key.
 * @return string
 */
function keel_defaults_date_column_status( $status, $post, $column = 'date' ) {
	if ( ! keel_defaults_list_tables_tidy() || 'date' !== $column || ! $post instanceof WP_Post || 'future' === $post->post_status ) {
		return $status;
	}
	return '';
}

/**
 * Show the publish date in the site's formats, or a dash when there is none.
 *
 * Core echoes this filter's value unescaped, so everything returned is escaped
 * here.
 *
 * @param string  $t_time Core's date and time markup.
 * @param WP_Post $post   Post.
 * @param string  $column Column key.
 * @return string
 */
function keel_defaults_date_column_time( $t_time, $post, $column = 'date' ) {
	if ( ! keel_defaults_list_tables_tidy() || 'date' !== $column || ! $post instanceof WP_Post ) {
		return $t_time;
	}
	if ( in_array( $post->post_status, array( 'publish', 'private', 'future' ), true ) ) {
		$date = keel_defaults_list_date( $post );
		return '' === $date ? $t_time : esc_html( $date );
	}
	return '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'Not published', 'keel-defaults' ) . '</span>';
}
