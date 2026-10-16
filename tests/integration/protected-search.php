<?php
/**
 * Real-WordPress protected-search contracts. Run with wp eval-file on a disposable site.
 *
 * @package Keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_schema' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

$keel_search_users         = array();
$keel_search_posts         = array();
$keel_search_checks        = 0;
$keel_search_original_user = get_current_user_id();
$keel_search_assert        = static function ( $condition, $message ) use ( &$keel_search_checks ) {
	++$keel_search_checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$keel_search_query         = static function ( $args = array() ) {
	return new WP_Query(
		array_merge(
			array(
				's'              => 'keelprivacyprobe',
				'post_type'      => 'post',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => 20,
			),
			$args
		)
	);
};
$keel_search_ids           = static function ( $query ) {
	return array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
};

try {
	foreach ( array( 'subscriber', 'author', 'editor' ) as $role ) {
		$id = wp_insert_user(
			array(
				'user_login' => 'keel-search-' . $role . '-' . wp_generate_uuid4(),
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$keel_search_users[ $role ] = $id;
	}
	foreach ( array( 'public', 'own', 'other', 'public2' ) as $name ) {
		$id = wp_insert_post(
			array(
				'post_title'    => 'keelprivacyprobe ' . $name,
				'post_content'  => in_array( $name, array( 'own', 'other' ), true ) ? 'keelprivateterm' : 'Public fixture.',
				'post_excerpt'  => 'keelprivatesummary',
				'post_status'   => 'publish',
				'post_author'   => 'own' === $name ? $keel_search_users['author'] : $keel_search_users['editor'],
				'post_password' => in_array( $name, array( 'own', 'other' ), true ) ? 'probe-password' : '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		$keel_search_posts[ $name ] = $id;
	}
	$public = array( $keel_search_posts['public'], $keel_search_posts['public2'] );
	wp_set_current_user( 0 );
	$keel_search_assert( $public === $keel_search_ids( $keel_search_query() ), 'Anonymous search excludes protected posts.' );
	wp_set_current_user( $keel_search_users['subscriber'] );
	$keel_search_assert( $public === $keel_search_ids( $keel_search_query() ), 'Subscriber search must not disclose protected posts.' );
	$keel_search_assert( current_user_can( 'read_post', $keel_search_posts['own'] ), 'Core generic read_post grants published-post access to Subscribers; it is insufficient for this policy.' );
	$keel_search_assert( array() === $keel_search_query( array( 's' => 'keelprivateterm' ) )->posts, 'Protected-body-only terms cannot reveal matching posts to a Subscriber.' );
	$keel_search_assert( false === strpos( get_the_excerpt( $keel_search_posts['own'] ), 'keelprivatesummary' ), 'Standard core excerpt rendering masks the stored protected excerpt.' );
	$paged = $keel_search_query( array( 'posts_per_page' => 1 ) );
	$keel_search_assert( 2 === (int) $paged->found_posts && 2 === (int) $paged->max_num_pages, 'Counts and pagination exclude hidden posts.' );
	$keel_search_assert(
		array( $public[1] ) === $keel_search_ids(
			$keel_search_query(
				array(
					'posts_per_page' => 1,
					'paged'          => 2,
				)
			)
		),
		'Second page is filled with the next visible result.'
	);
	$keel_search_assert( array_map( 'intval', $keel_search_query( array( 'fields' => 'ids' ) )->posts ) === $public, 'ID-only queries preserve privacy.' );
	$keel_search_assert( array() === $keel_search_query( array( 'has_password' => true ) )->posts, 'Explicit protected search cannot bypass privacy.' );
	$keel_search_assert( array( $public[0] ) === $keel_search_ids( $keel_search_query( array( 's' => 'keelprivacyprobe -public2' ) ) ), 'Core negative-term parsing is preserved.' );
	wp_set_current_user( $keel_search_users['author'] );
	$keel_search_assert( array( $public[0], $keel_search_posts['own'], $public[1] ) === $keel_search_ids( $keel_search_query() ), 'Authors retain their own protected results, not another author\'s.' );
	wp_set_current_user( $keel_search_users['editor'] );
	$keel_search_assert( array_values( $keel_search_posts ) === $keel_search_ids( $keel_search_query() ), 'Editors retain editable protected results.' );

	// A plugin can grant or deny an individual post through real meta-cap mapping.
	$target     = $keel_search_posts['other'];
	$subscriber = $keel_search_users['subscriber'];
	$grant      = static function ( $caps, $cap, $user_id, $args ) use ( $target, $subscriber ) {
		return 'edit_post' === $cap && $subscriber === $user_id && (int) ( $args[0] ?? 0 ) === $target ? array( 'read' ) : $caps;
	};
	add_filter( 'map_meta_cap', $grant, 10, 4 );
	wp_set_current_user( $subscriber );
	$keel_search_assert( array( $public[0], $target, $public[1] ) === $keel_search_ids( $keel_search_query() ), 'Plugin-granted per-post edit access is honored.' );
	remove_filter( 'map_meta_cap', $grant );
	$deny = static function ( $caps, $cap, $user_id, $args ) use ( $target ) {
		return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $target ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny, 10, 4 );
	wp_set_current_user( $keel_search_users['editor'] );
	$keel_search_assert( ! in_array( $target, $keel_search_ids( $keel_search_query() ), true ), 'A per-post denial is honored even for editors.' );
	remove_filter( 'map_meta_cap', $deny );

	wp_set_current_user( $subscriber );
	require_once ABSPATH . WPINC . '/class-phpass.php';
	$hasher                                 = new PasswordHash( 8, true );
	$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'probe-password' );
	$keel_search_assert( array_values( $keel_search_posts ) === $keel_search_ids( $keel_search_query() ), 'A valid password cookie retains legitimate access.' );
	$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = 'invalid';
	$keel_search_assert( $public === $keel_search_ids( $keel_search_query() ), 'An invalid password cookie grants no access.' );
	unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	$read_grant = static function ( $required, $post ) use ( $target ) { return $target === $post->ID ? false : $required;
	};
	add_filter( 'post_password_required', $read_grant, 10, 2 );
	$keel_search_assert( in_array( $target, $keel_search_ids( $keel_search_query() ), true ), 'Plugin password-access grants are preserved.' );
	remove_filter( 'post_password_required', $read_grant );

	// Authorship alone is insufficient for a custom post type.
	$type = 'keel_search_note';
	register_post_type(
		$type,
		array(
			'public'          => true,
			'capability_type' => array( 'keel_note', 'keel_notes' ),
			'map_meta_cap'    => true,
		)
	);
	$note                      = wp_insert_post(
		array(
			'post_type'     => $type,
			'post_status'   => 'publish',
			'post_title'    => 'keelprivacyprobe custom',
			'post_author'   => $subscriber,
			'post_password' => 'note-password',
		)
	);
	$keel_search_posts['note'] = $note;
	$keel_search_assert( array() === $keel_search_query( array( 'post_type' => $type ) )->posts, 'Authorship alone does not grant custom post type edit access.' );
	$user = get_user_by( 'id', $subscriber );
	$user->add_cap( 'edit_keel_notes' );
	$user->add_cap( 'edit_published_keel_notes' );
	wp_set_current_user( 0 );
	wp_set_current_user( $subscriber );
	$keel_search_assert( array( $note ) === $keel_search_ids( $keel_search_query( array( 'post_type' => $type ) ) ), 'Custom post type capabilities grant legitimate access.' );
	$non_search = new WP_Query( array( 'post__in' => array_values( array_diff_key( $keel_search_posts, array( 'note' => true ) ) ) ) );
	$keel_search_assert( 4 === count( $non_search->posts ), 'Non-search queries remain unchanged.' );
	$admin_query = static function ( $query ) { $query->is_admin = true;
	};
	add_action( 'pre_get_posts', $admin_query );
	$keel_search_assert( 4 === count( $keel_search_query()->posts ), 'Admin searches remain unchanged.' );
	remove_action( 'pre_get_posts', $admin_query );
	unregister_post_type( $type );

	$keel_search_assert( 4 === count( $keel_search_query( array( 'suppress_filters' => true ) )->posts ), 'Explicitly suppressed filters retain WordPress behavior.' );
	remove_filter( 'posts_clauses', 'keel_defaults_protect_search', 100 );
	$keel_search_assert( 4 === count( $keel_search_query()->posts ), 'Disabling the policy restores the baseline disclosure.' );
	$keel_search_assert( 2 === count( $keel_search_query( array( 's' => 'keelprivateterm' ) )->posts ), 'Without the policy, body-only terms reveal matching protected posts.' );
	add_filter( 'posts_clauses', 'keel_defaults_protect_search', 100, 2 );
	echo 'Protected search: ' . $keel_search_checks . " real-WordPress assertions passed.\n";
} finally {
	unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	foreach ( $keel_search_posts as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $keel_search_users as $id ) {
		wp_delete_user( $id );
	}
	wp_set_current_user( $keel_search_original_user );
}
