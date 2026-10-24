<?php
/**
 * Keel — comments off, and every other door open.
 *
 * Used with probe-comment-paths.sh. REST, XML-RPC publishing and pingbacks stay
 * available so each write path reaches the comment guard instead of being
 * refused earlier by a different setting, which would measure that setting.
 *
 * Pass KEEL_PROBE_COMMENTS=no in the environment for the control run with
 * comments left on.
 *
 * @package Keel
 */

$settings = (array) get_option( 'keel_settings', array() );

$settings['disable_comments']               = 'no' === getenv( 'KEEL_PROBE_COMMENTS' ) ? 'no' : 'yes';
$settings['disable_rest']                   = 'no';
$settings['disable_pingbacks']              = 'no';
$settings['xmlrpc_allow_pingbacks']         = 'yes';
$settings['xmlrpc_allow_remote_publishing'] = 'yes';
$settings['block_xmlrpc_endpoint']          = 'no';

update_option( 'keel_settings', $settings );

echo 'configured';
