<?php
/**
 * Plugin Name: Host revision limit (Keel demo stand-in)
 * Description: Playground-only. Stands in for the must-use code a managed host ships to cap post revisions. Keel lists it in Site Health and never raises a notice about it.
 */

defined( 'ABSPATH' ) || exit;

/**
 * A host cap of five revisions per post.
 *
 * Must-use plugins load before ordinary ones, so at the same priority this runs
 * before Keel's revision limit, and Keel's setting (10 by default) is the one
 * WordPress keeps. The filter registry cannot show that ordering as a problem
 * or as harmless, which is why Keel reports must-use code for information only.
 *
 * @param int     $num  Revisions to keep.
 * @param WP_Post $post Post being revised.
 * @return int
 */
function keel_demo_host_revision_limit( $num, $post ) {
	unset( $num, $post );
	return 5;
}
add_filter( 'wp_revisions_to_keep', 'keel_demo_host_revision_limit', 10, 2 );
