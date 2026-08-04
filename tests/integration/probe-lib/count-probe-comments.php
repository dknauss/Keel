<?php
/**
 * Count comments the probe created, straight from the table.
 *
 * Asked of $wpdb rather than get_comments() on purpose: the thing being
 * measured is often a plugin that filters comment queries to empty, and asking
 * through the filtered layer would report success for a comment that landed.
 *
 * @package Keel
 */

global $wpdb;

echo intval(
	$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author = 'KeelProbe'"
	)
);
