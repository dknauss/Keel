<?php
/**
 * Better by Default — the sibling teardown, configured to match keel.php.
 *
 * Same posture as Keel's config so the two are comparable row for row: comments
 * off, REST closed, author archives hidden, XML-RPC methods locked down but the
 * endpoint left reachable.
 *
 * The endpoint block stays off for the same reason it does in keel.php. With it
 * on, xmlrpc.php answers 403 to everything and every XML-RPC row measures the
 * block rather than the per-method controls underneath it.
 *
 * @package Keel
 */

$settings = (array) get_option( 'wpyeg_better_by_default', array() );

$settings['disable_comments']               = 'yes';
$settings['disable_rest']                   = 'yes';
$settings['restrict_rest_user_discovery']   = 'yes';
$settings['disable_author_archives']        = 'yes';
$settings['disable_pingbacks']              = 'yes';
$settings['disable_self_pingbacks']         = 'yes';
$settings['xmlrpc_allow_pingbacks']         = 'no';
$settings['xmlrpc_allow_remote_publishing'] = 'no';
$settings['xmlrpc_allow_multicall']         = 'no';
$settings['block_xmlrpc_endpoint']          = 'no';

update_option( 'wpyeg_better_by_default', $settings );

echo "configured sane-defaults\n";
