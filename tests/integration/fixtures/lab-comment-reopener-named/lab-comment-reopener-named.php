<?php
/**
 * Plugin Name: Lab Comment Reopener (own callback)
 * Description: Disposable conflict fixture; never install on a real site. Same as Lab Comment Reopener, but the callback is defined in this plugin's own file, the way most real plugins write it, so a diagnostic that attributes callbacks can name it.
 *
 * Used by probe-conflict.sh. Copy this directory into wp-content/plugins/.
 *
 * @package Keel
 */

/**
 * Keep comments open whatever an earlier filter decided.
 *
 * @return bool
 */
function lab_reopener_keep_comments_open() {
	return true;
}
add_filter( 'comments_open', 'lab_reopener_keep_comments_open', 99 );
add_filter( 'pings_open', 'lab_reopener_keep_comments_open', 99 );
