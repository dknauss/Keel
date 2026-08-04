<?php
/**
 * Keel — comments off, REST closed, XML-RPC locked down but the endpoint left up.
 *
 * The endpoint block stays off on purpose. With it on, xmlrpc.php answers 403 to
 * everything and every XML-RPC probe returns the same value, which measures the
 * block rather than the per-method controls underneath it. Leaving it reachable
 * is what makes the method-level rows meaningful.
 *
 * @package Keel
 */

$settings = (array) get_option( 'keel_settings', array() );

$settings['disable_comments']               = 'yes';
$settings['disable_rest']                   = 'yes';
$settings['restrict_rest_user_discovery']   = 'yes';
$settings['disable_pingbacks']              = 'yes';
$settings['disable_self_pingbacks']         = 'yes';
$settings['xmlrpc_allow_pingbacks']         = 'no';
$settings['xmlrpc_allow_remote_publishing'] = 'no';
$settings['xmlrpc_allow_multicall']         = 'no';
$settings['block_xmlrpc_endpoint']          = 'no';

update_option( 'keel_settings', $settings );

echo 'configured';
