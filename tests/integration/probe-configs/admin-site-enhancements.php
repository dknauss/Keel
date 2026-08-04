<?php
/**
 * Admin and Site Enhancements — comments, REST and XML-RPC off.
 *
 * Feeds are left on deliberately. This harness measures comment feeds
 * separately, and switching ASE's own feed-disabling on would make it impossible
 * to tell whether a 404 came from the comment teardown or from feeds being off
 * wholesale.
 *
 * @package Keel
 */

$options                         = (array) get_option( 'admin_site_enhancements', array() );
$options['disable_comments']     = true;
$options['disable_comments_for'] = array(
	'post'       => true,
	'page'       => true,
	'attachment' => true,
);
$options['disable_rest_api']     = true;
$options['disable_xmlrpc']       = true;
$options['disable_feeds']        = false;

update_option( 'admin_site_enhancements', $options );

echo 'configured';
