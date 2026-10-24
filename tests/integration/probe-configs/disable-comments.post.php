<?php
/**
 * Disable Comments — re-apply the configuration after activation.
 *
 * On a site where the plugin has never run, its first load finds no
 * `db_version` in the options and runs every upgrade step from version 0. The
 * step for options older than version 5 rewrites `remove_everywhere` from
 * `remove_admin_menu_comments`, which a fresh configuration does not have, so
 * "everywhere" is silently reset to false and the plugin closes almost nothing.
 * A run measured that way reports Disable Comments leaving the comment form up
 * and accepting comments; it is the harness's fault, not the plugin's. A real
 * administrator saves the settings screen after activation, so this does too.
 *
 * @package Keel
 */

$options                             = get_option( 'disable_comments_options', array() );
$options['remove_everywhere']        = true;
$options['remove_xmlrpc_comments']   = 1;
$options['remove_rest_API_comments'] = 1;
$options['disabled_post_types']      = array();

update_option( 'disable_comments_options', $options );

echo 'remove_everywhere=', wp_json_encode( $options['remove_everywhere'] ), ' db_version=', $options['db_version'] ?? 'none', "\n";
