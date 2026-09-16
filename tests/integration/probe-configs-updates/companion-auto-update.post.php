<?php
/**
 * Companion Auto Update — minor on, major off. Runs AFTER activation, and has to.
 *
 * This plugin stores nothing in options. Its settings are rows in a table of its
 * own, {$wpdb->prefix}auto_updates, with one `onoroff` column per switch — and
 * cau_remove(), its deactivation hook, runs `DROP TABLE IF EXISTS` on that table
 * and on its update log. So the table exists only while the plugin is active,
 * there is nothing for a pre-activation config to write to, and every
 * deactivation destroys the settings and the entire recorded update history with
 * them. That is worth knowing before deactivating it to test something.
 *
 * cau_setDefaultSettings() seeds exactly the policy this file wants — minor 'on',
 * major '' — so on a clean activation this writes what is already there. It is
 * here anyway: a release that changed the seeded default would otherwise
 * re-point the comparison silently, and the probe would report the new default
 * as a measurement of the same configuration.
 *
 * @package Keel
 */

global $wpdb;

$table = $wpdb->prefix . 'auto_updates';

// Existence is tested by querying the table, not by asking a catalogue.
// information_schema is MySQL's and the SQLite dropin answers 0 for every name;
// sqlite_master is SQLite's, and a double-quoted literal there parses as an
// identifier, so the obvious form of that query returns NULL whether the table
// exists or not. Both read as "missing" on a table that is present. A SELECT
// against the table itself is true on either engine and needs neither catalogue.
$wpdb->suppress_errors( true );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$probe = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$wpdb->suppress_errors( false );

if ( null === $probe ) {
	fwrite( STDERR, "companion-auto-update: {$table} is missing even with the plugin active; its activation hook did not run.\n" );
	exit( 1 );
}

foreach ( array(
	'minor' => 'on',
	'major' => '',
) as $name => $value ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s", $name ) );

	if ( $exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'onoroff' => $value ), array( 'name' => $name ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'name'    => $name,
				'onoroff' => $value,
			)
		);
	}
}

echo 'configured: minor=on, major=off';
