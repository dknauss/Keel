<?php
/**
 * Disable Comments RB — "remove everywhere", as its own settings screen saves it.
 *
 * MUST be run with --skip-plugins. Its "everywhere" radio does not store a flag
 * the plugin reads later; it stores a snapshot of every post type that supported
 * comments at the moment Save was pressed. So this file has to ask WordPress
 * which post types support comments — and if the plugin is already active with a
 * previous run's settings, it has removed that support before this file runs.
 *
 * The result is a config that works once and silently unconfigures the plugin on
 * every later run: an empty list, a plugin that does nothing, and a probe that
 * reports it closing nothing. That is not hypothetical. It is how the first
 * re-verification of the published matrix produced seven differences that were
 * all the harness's fault.
 *
 * @package Keel
 */

$types = array();

foreach ( get_post_types( array(), 'objects' ) as $type ) {
	if ( post_type_supports( $type->name, 'comments' ) ) {
		$types[] = $type->name;
	}
}

if ( empty( $types ) ) {
	fwrite( STDERR, "disable-comments-rb: no post type supports comments — run this with --skip-plugins.\n" );
	exit( 1 );
}

update_option(
	'rb_disable_comments',
	array(
		'remove_everywhere'   => true,
		'disabled_post_types' => $types,
		'db_version'          => 6,
	)
);

echo 'configured: ' . implode( ',', $types );
