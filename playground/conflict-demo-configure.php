<?php
/**
 * Keel conflict-diagnostics demo: put each plugin in the configuration its own
 * settings screen would save. Runs once, as a blueprint runPHP step.
 */

require '/wordpress/wp-load.php';

// Disable Comments 2.9: "Everywhere" mode, as its settings screen saves it.
// db_version must match the plugin's DB_VERSION (8), or its upgrade routine
// rewrites remove_everywhere from a legacy key on the next load.
update_option(
	'disable_comments_options',
	array(
		'remove_everywhere'        => true,
		'disabled_post_types'      => array( 'post', 'page', 'attachment' ),
		'extra_post_types'         => array(),
		'remove_xmlrpc_comments'   => 0,
		'remove_rest_API_comments' => 0,
		'show_existing_comments'   => false,
		'settings_saved'           => true,
		'db_version'               => 8,
	)
);

// Classic Editor 1.7: "Allow users to switch editors: Yes". That registers its
// named choose_editor() callback; the default registers only __return_false.
update_option( 'classic-editor-replace', 'classic' );
update_option( 'classic-editor-allow-users', 'allow' );

// Keel: turn on the two settings these plugins contest. Force Classic Editor
// meets Classic Editor; allowing XML-RPC remote publishing meets Disable XML-RPC,
// which switches the whole endpoint off behind Keel's back.
$keel                                   = (array) get_option( 'keel_settings', array() );
$keel['force_classic_editor']           = 'yes';
$keel['block_xmlrpc_endpoint']          = 'no';
$keel['xmlrpc_allow_remote_publishing'] = 'yes';
update_option( 'keel_settings', $keel );

// Keep the Dashboard on Keel's notice rather than core's welcome banner.
update_user_meta( 1, 'show_welcome_panel', 0 );
