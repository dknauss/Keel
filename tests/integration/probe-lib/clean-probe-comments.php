<?php
/**
 * Remove the probe's comments so consecutive runs start from the same state.
 *
 * Deletes by the probe's own author name, never by date or ID range: this runs
 * against a throwaway install, but a harness that could delete a real comment
 * on a mistargeted run is not one to leave lying around.
 *
 * @package Keel
 */

global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"DELETE FROM {$wpdb->comments} WHERE comment_author = 'KeelProbe'"
);

echo 'cleaned';
