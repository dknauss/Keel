<?php
/**
 * Real WordPress list-column contracts; run on a disposable site with Keel loaded.
 * This probe deliberately saves user preferences and removes all fixtures.
 *
 * @package Keel
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_register_list_table_columns' ) ) {
	throw new RuntimeException( 'Load WordPress and the Keel list-table module first.' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$keel_columns_original_get    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve request context in a CLI probe.
$keel_columns_original_type   = $GLOBALS['post_type_object'] ?? null;
$keel_columns_original_user   = get_current_user_id();
$keel_columns_original_screen = $GLOBALS['current_screen'] ?? null;
$keel_columns_option_exists   = false !== get_option( KEEL_DEFAULTS_OPTION, false );
$keel_columns_original_option = get_option( KEEL_DEFAULTS_OPTION );
$keel_columns_user            = 0;
$keel_columns_checks          = 0;
$keel_columns_assert          = static function ( $condition, $message ) use ( &$keel_columns_checks ) {
	++$keel_columns_checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$keel_columns_fixture         = static function ( $columns ) {
	return array_merge(
		$columns,
		array(
			'jetpack_seo_description' => 'SEO Description',
			'jetpack_seo_schema'      => 'SEO Schema',
			'jetpack_seo_search'      => 'SEO Search',
			'shortlink'               => 'Shortlink',
			'activitypub'             => 'ActivityPub Status',
			'keel_id'                 => 'ID',
			'keel_modified'           => 'Modified',
			'keel_thumb'              => 'Image',
			'unknown_seo'             => 'Unknown SEO',
		)
	);
};
$keel_columns_sortable        = static function ( $columns ) {
	$columns['shortlink'] = 'shortlink';
	return $columns;
};
$keel_columns_primary         = static function () { return 'shortlink';
};
// Restore hook state, including registrations made by current_screen.
$keel_columns_hooks = array();
foreach ( array( 'admin_enqueue_scripts', 'manage_edit-post_columns', 'manage_edit-page_columns', 'manage_edit-keel_column_probe_columns', 'manage_keel_column_probe_posts_columns', 'manage_posts_columns', 'manage_pages_columns', 'manage_post_posts_columns', 'manage_page_posts_columns', 'manage_edit-post_sortable_columns', 'default_hidden_columns', 'list_table_primary_column' ) as $hook ) {
	$keel_columns_hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? clone $GLOBALS['wp_filter'][ $hook ] : null;
}
try {
	register_post_type(
		'keel_column_probe',
		array(
			'public'          => false,
			'show_ui'         => true,
			'capability_type' => 'post',
		)
	);
	$keel_columns_user = wp_insert_user(
		array(
			'user_login' => 'keel-columns-' . wp_generate_uuid4(),
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => 'editor',
		)
	);
	if ( is_wp_error( $keel_columns_user ) ) {
		throw new RuntimeException( $keel_columns_user->get_error_message() );
	}
	wp_set_current_user( $keel_columns_user );
	update_option(
		KEEL_DEFAULTS_OPTION,
		array(
			'compact_column_labels' => 'yes',
			'fewer_list_columns'    => 'yes',
		)
	);
	add_filter( 'manage_posts_columns', $keel_columns_fixture, 20 );
	add_filter( 'manage_pages_columns', $keel_columns_fixture, 20 );
	add_filter( 'manage_edit-post_sortable_columns', $keel_columns_sortable );
	foreach ( array( 'post', 'page', 'keel_column_probe' ) as $post_type ) {
		set_current_screen( 'edit-' . $post_type );
		$screen = get_current_screen();
		keel_defaults_register_list_table_columns( $screen );
		$_GET['orderby'] = 'shortlink';
		$_GET['order']   = 'asc';
		$table           = new WP_Posts_List_Table( array( 'screen' => $screen ) );
		$columns         = $table->get_columns();
		$keel_columns_assert( false !== strpos( $columns['shortlink'], 'data-keel-column-label="Link"' ), 'Real post/page hooks compact labels.' );
		$keel_columns_assert( 1 === substr_count( $columns['shortlink'], 'class="screen-reader-text"' ), 'Page hooks retain one full accessible name.' );
		$keel_columns_assert( 'title' === $table->get_primary_column(), 'Core primary title column preserved.' );
		$key = 'manage' . $screen->id . 'columnshidden';
		delete_user_option( $keel_columns_user, $key );
		$hidden = get_hidden_columns( $screen );
		foreach ( array( 'jetpack_seo_description', 'jetpack_seo_schema', 'jetpack_seo_search', 'shortlink', 'keel_id', 'keel_modified' ) as $optional ) {
			$keel_columns_assert( in_array( $optional, $hidden, true ), 'Present optional column hidden: ' . $optional );
		}
		foreach ( array( 'title', 'cb', 'activitypub', 'keel_thumb', 'unknown_seo' ) as $visible ) {
			$keel_columns_assert( ! in_array( $visible, $hidden, true ), 'Protected or unknown column remains visible: ' . $visible );
		}
		update_user_option( $keel_columns_user, $key, array() );
		$keel_columns_assert( array() === get_hidden_columns( $screen ), 'Saved empty preferences override defaults.' );
		update_user_option( $keel_columns_user, $key, array( 'activitypub' ) );
		$keel_columns_assert( array( 'activitypub' ) === get_hidden_columns( $screen ), 'Saved nonempty preferences remain exact.' );
		$keel_columns_assert( array( 'activitypub' ) === get_user_option( $key ), 'Preference storage is untouched by reads.' );
		ob_start();
		$table->print_column_headers();
		$headers = ob_get_clean();
		$keel_columns_assert( false !== strpos( $headers, 'title="Shortlink"' ) && false !== strpos( $headers, 'aria-hidden="true" data-keel-column-label="Link"' ), 'Core renders escaped full tooltip and decorative abbreviation.' );
		ob_start();
		$screen->render_list_table_columns_preferences();
		$options = ob_get_clean();
		$keel_columns_assert( 1 === preg_match( '/id="shortlink-hide"[^>]*\/>Shortlink<\/label>/', $options ), 'Screen Options retains full accessible label.' );
		if ( 'post' === $post_type ) {
			$keel_columns_assert( false !== strpos( $headers, 'orderby=shortlink' ), 'Real sortable column retains its ordering link.' );
			$keel_columns_assert( 1 === preg_match( '/<th[^>]*id=[\"\']shortlink[\"\'][^>]*aria-sort="ascending"[^>]*>(.*?)<\/th>/s', $headers, $sorted ), 'Sorted header retains core aria-sort.' );
			$keel_columns_assert( 'Shortlink' === trim( wp_strip_all_tags( $sorted[1] ) ), 'Sorted link accessible label contains full name exactly once.' );
			unset( $_GET['orderby'], $_GET['order'] );
			ob_start();
			$table->print_column_headers();
			$unsorted = ob_get_clean();
			$keel_columns_assert( 1 === preg_match( '/<th[^>]*id=[\"\']shortlink[\"\'][^>]*>(.*?)<\/th>/s', $unsorted, $heading ), 'Unsorted heading exists.' );
			$accessible = trim( wp_strip_all_tags( $heading[1] ) );
			$keel_columns_assert( 1 === substr_count( $accessible, 'Shortlink' ) && false === strpos( $accessible, 'Link' ), 'Unsorted link announces full name once plus core sort direction.' );
		}
		delete_user_option( $keel_columns_user, $key );
		add_filter( 'list_table_primary_column', $keel_columns_primary );
		$keel_columns_assert( ! in_array( 'shortlink', get_hidden_columns( $screen ), true ), 'A plugin-selected primary column is never hidden by Keel.' );
		remove_filter( 'list_table_primary_column', $keel_columns_primary );
	}
	set_current_screen( 'edit-post' );
	wp_set_current_user( 0 );
	$keel_columns_assert( array() === keel_defaults_fewer_list_columns( array(), get_current_screen() ), 'Unauthorized context unchanged.' );
	wp_set_current_user( $keel_columns_user );
	foreach ( array( 'users', 'upload', 'dashboard', 'edit-post-network' ) as $screen_id ) {
		set_current_screen( $screen_id );
		$keel_columns_assert( array() === keel_defaults_fewer_list_columns( array(), get_current_screen() ), 'Unrelated screen unchanged: ' . $screen_id );
	}
	set_current_screen( 'edit-post' );
	update_option(
		KEEL_DEFAULTS_OPTION,
		array(
			'compact_column_labels' => 'no',
			'fewer_list_columns'    => 'no',
		)
	);
	$fixture = $keel_columns_fixture( array( 'title' => 'Title' ) );
	$keel_columns_assert( keel_defaults_compact_column_labels( $fixture ) === $fixture && array() === get_hidden_columns( get_current_screen() ), 'Disabled settings preserve plugin labels and core defaults.' );
} finally {
	unregister_post_type( 'keel_column_probe' );
	foreach ( $keel_columns_hooks as $hook => $original ) {
		if ( null === $original ) {
			unset( $GLOBALS['wp_filter'][ $hook ] );
		} else {
			$GLOBALS['wp_filter'][ $hook ] = $original;
		}
	}
	if ( $keel_columns_option_exists ) {
		update_option( KEEL_DEFAULTS_OPTION, $keel_columns_original_option );
	} else {
		delete_option( KEEL_DEFAULTS_OPTION );
	}
	wp_set_current_user( $keel_columns_original_user );
	if ( is_int( $keel_columns_user ) && $keel_columns_user > 0 ) {
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $keel_columns_user );
		} else {
			wp_delete_user( $keel_columns_user );
		}
		$keel_columns_assert( false === get_userdata( $keel_columns_user ), 'Fixture account removed, including network user.' );
	}
	$_GET                        = $keel_columns_original_get;
	$GLOBALS['post_type_object'] = $keel_columns_original_type;
	$GLOBALS['current_screen']   = $keel_columns_original_screen;
}

echo 'list table columns integration: OK (' . $keel_columns_checks . " checks)\n";
