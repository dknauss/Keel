<?php
/**
 * Disable Comments — everything off, including its REST and XML-RPC toggles.
 *
 * Matches what its settings screen writes for "everywhere", plus the two extra
 * checkboxes. Without those, the plugin leaves /wp/v2/comments and wp.newComment
 * untouched, and a probe would report a gap the plugin does not claim to close.
 *
 * @package Keel
 */

$options                             = get_option( 'disable_comments_options', array() );
$options['remove_everywhere']        = true;
$options['remove_xmlrpc_comments']   = 1;
$options['remove_rest_API_comments'] = 1;
$options['disabled_post_types']      = array();

update_option( 'disable_comments_options', $options );

echo 'configured';
