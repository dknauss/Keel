<?php
/**
 * Keel conflict-diagnostics demo: let WordPress run the contested filters once,
 * the way real traffic would, so Keel's passive observers have a result to read.
 *
 * Nothing here calls apply_filters() on a policy hook. Rendering a post asks
 * comments_open() and pings_open(); a request to xmlrpc.php builds the server
 * class, whose constructor asks xmlrpc_enabled. Keel records any setting whose
 * final value differs from what it configured, for an hour.
 */

require '/wordpress/wp-load.php';

$keel_demo_posts = get_posts( array( 'numberposts' => 1 ) );
if ( $keel_demo_posts ) {
	comments_open( $keel_demo_posts[0] );
	pings_open( $keel_demo_posts[0] );
}

require_once ABSPATH . WPINC . '/class-IXR.php';
require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
$keel_demo_class = apply_filters( 'wp_xmlrpc_server_class', 'wp_xmlrpc_server' );
new $keel_demo_class();
