<?php
/**
 * Real-WordPress shortcode residue probe, for disposable localhost sites only.
 *
 * Run using WP-CLI eval-file after activating this checkout. Creates synthetic
 * users and one classic-content post. HTTP checks require the site to be served.
 * Destroy the disposable site after use; do not run on an existing content site.
 *
 * @package Keel
 */
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Local only' );
}
function lab_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS $message\n";
}
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
$users = array();
foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
	$login          = 'shortcode_' . $role;
	$user           = get_user_by( 'login', $login );
	$users[ $role ] = $user ? $user->ID : wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.test',
			'user_pass'  => wp_generate_password( 32 ),
			'role'       => $role,
		)
	);
}
$body = 'START [gone id="12"] [wrap]Kept <strong>text</strong> [nested /][/wrap] [sic] [unknown] [[escaped /]] <code>[example /]</code> [live key="ok"] END';
$id   = (int) get_option( 'lab_shortcode_post' );
if ( ! $id ) {
	$id = wp_insert_post(
		array(
			'post_title'   => 'Shortcode Lab',
			'post_status'  => 'publish',
			'post_content' => $body,
			'post_author'  => $users['author'],
		)
	);
	update_option( 'lab_shortcode_post', $id );
}
$GLOBALS['post'] = get_post( $id );
setup_postdata( $GLOBALS['post'] );
add_shortcode(
	'live',
	static function ( $atts ) {
		return 'LIVE-' . ( $atts['key'] ?? '' );
	}
);
$writes = 0;
add_action(
	'set_transient_keel_shortcode_residue',
	static function () use ( &$writes ) {
		++$writes;
	}
);
delete_transient( 'keel_shortcode_residue' );
wp_set_current_user( 0 );
$clean = apply_filters( 'the_content', 'Healthy [sic] content' );
lab_assert( false === get_transient( 'keel_shortcode_residue' ), 'healthy content leaves no observation' );
foreach ( array( 0, $users['subscriber'] ) as $uid ) {
	wp_set_current_user( $uid );
	$out = apply_filters( 'the_content', $body );
	lab_assert( false === strpos( $out, '[gone' ) && false === strpos( $out, '[wrap' ) && false === strpos( $out, '[nested' ), 'reader residue hidden' );
	lab_assert( false !== strpos( $out, 'Kept <strong>text</strong>' ), 'enclosed markup and text retained' );
	lab_assert( false !== strpos( $out, '[sic]' ) && false !== strpos( $out, '[unknown]' ) && false !== strpos( $out, '[escaped /]' ) && false !== strpos( $out, '[example /]' ), 'prose and escaped/code examples retained' );
	lab_assert( false !== strpos( $out, 'LIVE-ok' ), 'registered shortcode executes normally' );
}
lab_assert( 1 === $writes, 'repeated identical observations write only once' );
$callback_calls = 0;
add_shortcode(
	'ns:live',
	static function ( $atts, $content ) use ( &$callback_calls ) {
		++$callback_calls;
		return 'NAMESPACE-' . $content;
	}
);
$namespaced = apply_filters( 'the_content', '[ns:live][gone /][/ns:live]' );
lab_assert( 1 === $callback_calls && false !== strpos( $namespaced, 'NAMESPACE-[gone /]' ), 'registered non-ASCII-grammar wrapper receives unchanged input and executes once' );
remove_shortcode( 'ns:live' );
foreach ( array( 'administrator', 'editor', 'author' ) as $role ) {
	wp_set_current_user( $users[ $role ] );
	$out = apply_filters( 'the_content', $body );
	lab_assert( false !== strpos( $out, '[gone' ), 'edit capability retains residue for ' . $role );
}
wp_set_current_user( $users['subscriber'] );
$cap_filter = static function ( $caps, $cap, $uid, $args ) use ( $id ) {
	return 'edit_post' === $cap && isset( $args[0] ) && $id === $args[0] ? array( 'read' ) : $caps;
};
add_filter( 'map_meta_cap', $cap_filter, 99, 4 );
lab_assert( false !== strpos( apply_filters( 'the_content', $body ), '[gone' ), 'custom per-post edit capability honored' );
remove_filter( 'map_meta_cap', $cap_filter, 99 );
lab_assert( 0 === strcmp( $body, get_post( $id )->post_content ), 'stored content remains byte-identical' );
wp_set_current_user( $users['administrator'] );
$health = keel_defaults_site_health_shortcodes();
lab_assert( 'recommended' === $health['status'] && false !== strpos( $health['description'], 'gone' ) && false !== strpos( $health['description'], 'post=' . $id ), 'Site Health provides affected-post edit link and tag' );
lab_assert( false === strpos( wp_json_encode( keel_defaults_shortcode_observations() ), 'id=' ) && false === strpos( wp_json_encode( keel_defaults_shortcode_observations() ), 'Kept' ), 'record stores no content or attributes' );
add_shortcode( 'gone', '__return_empty_string' );
wp_set_current_user( 0 );
lab_assert( false === strpos( apply_filters( 'the_content', $body ), '[gone' ), 'restored handler is respected' );
remove_shortcode( 'gone' );
// Exercise actual HTTP including REST, feed and public singular rendering.
foreach ( array( get_permalink( $id ), add_query_arg( 'feed', 'rss2', home_url( '/' ) ) ) as $url ) {
	$response = wp_remote_get( $url );
	lab_assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'HTTP page/feed responds' );
	$html = wp_remote_retrieve_body( $response );
	lab_assert( false === strpos( $html, '[gone' ), 'HTTP page/feed hides residue' );
}
$response = wp_remote_get( rest_url( 'wp/v2/posts/' . $id ) );
lab_assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'anonymous REST responds' );
$data = json_decode( wp_remote_retrieve_body( $response ), true );
lab_assert( false !== strpos( $data['content']['rendered'], '[gone' ), 'REST content stays unchanged' );
echo 'POST_ID=' . $id . ' URL=' . get_permalink( $id ) . "\n";
