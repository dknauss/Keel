<?php
/**
 * Plugin Name: Lab Comment Reopener
 * Description: Disposable conflict fixture; never install on a real site. Stands in for any plugin or theme that answers comments_open/pings_open later than a comment-disabling plugin does. Uses core's __return_true, which leaves nothing that traces back to this plugin.
 *
 * Used by probe-conflict.sh. Copy this directory into wp-content/plugins/.
 *
 * @package Keel
 */

add_filter( 'comments_open', '__return_true', 99 );
add_filter( 'pings_open', '__return_true', 99 );
