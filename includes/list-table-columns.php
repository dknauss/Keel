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
	add_filter( "manage_{$screen->post_type}_posts_columns", 'keel_defaults_compact_column_labels', 1000 );
	if ( 'page' === $screen->post_type ) {
		add_filter( 'manage_pages_columns', 'keel_defaults_compact_column_labels', 1000 );
	}
	add_filter( 'default_hidden_columns', 'keel_defaults_fewer_list_columns', 1000, 2 );
}

/**
 * Enqueue abbreviations without adding text to core's stripped labels.
 *
 * Screen Options and responsive cell labels strip tags, ignoring aria-hidden.
 * Generated content leaves those consumers with the original full label only.
 */
function keel_defaults_enqueue_compact_column_style() {
	if ( ! keel_defaults_enabled( 'compact_column_labels' ) || ! keel_defaults_is_post_list_screen( get_current_screen() ) ) {
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
	if ( ! keel_defaults_enabled( 'fewer_list_columns' ) || ! keel_defaults_is_post_list_screen( get_current_screen() ) ) {
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
	if ( ! keel_defaults_enabled( 'compact_column_labels' ) || ! keel_defaults_is_post_list_screen( $screen ) || ! is_array( $columns ) ) {
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
	if ( ! keel_defaults_enabled( 'fewer_list_columns' ) || ! keel_defaults_is_post_list_screen( $screen ) || ! is_array( $hidden ) ) {
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
