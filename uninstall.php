<?php
/**
 * Remove everything Keel stored, when the plugin is deleted.
 *
 * Deleting a plugin should leave the database as it found it. Until now Keel
 * left all of it behind: there was no uninstall file at all, so `keel_settings`,
 * every user's `keel_last_login`, and up to 1,048,576 possible breach-cache
 * transients survived deletion and reinstallation.
 *
 * Deactivation is untouched and non-destructive — deactivate, and every setting
 * is still there when the plugin comes back. Only deletion clears it, which is
 * what a user asking WordPress to delete a plugin has asked for.
 *
 * @package Keel
 */

// Only ever run through WordPress's own uninstall path.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Everything this plugin persists on one site.
 *
 * Keel writes in three places, and each needs different handling:
 *
 * - `keel_settings` — one autoloaded option holding every default's value.
 * - `keel_defaults` — what that option was called before the rename. Nothing
 *   in the running code writes it any more, which is exactly why it has to be
 *   named here: a site that ran both the old plugin and this one keeps the old
 *   row, and no scan of the current source can find a key the current source
 *   never mentions.
 * - `keel_settings_data_version` — the per-site migration version.
 * - `keel_last_login` — user meta, written on each login by the Last login
 *   column. On multisite the user table is shared across the network, so this
 *   is deleted once for the whole network rather than per site.
 * - `keel_conflicts_dismissed` — user meta, the fingerprint of the competing
 *   plugins a person last dismissed the notice for. Network-wide for the same
 *   reason as the above: it belongs to the user, not the site.
 * - `keel_policy_divergence` — which governed settings were last seen not
 *   producing the value they ask for. An observation about this site, and
 *   meaningless once the plugin making it is gone.
 * - `keel_hibp_*` — breach-lookup response cache, one transient per five-hex
 *   prefix. Deleted with a LIKE query because there is no key to enumerate:
 *   which prefixes exist depends entirely on which passwords have been checked.
 *
 * @return void
 */
function keel_defaults_uninstall_site() {
	global $wpdb;

	delete_option( 'keel_settings' );
	delete_option( 'keel_settings_data_version' );
	delete_transient( 'keel_policy_divergence' );

	/*
	 * The pre-rename settings option.
	 *
	 * Deleted only when it still looks like Keel's — an array of scalars keyed
	 * by names this plugin uses. `keel_defaults` is a plainer name than the rest
	 * of these and belongs to a plugin slug that no longer exists, so the one
	 * thing an uninstaller must not do is delete somebody else's row that
	 * happens to share it. Checking the shape costs nothing and makes the
	 * deletion defensible; deleting by name alone would not be.
	 */
	$keel_legacy = get_option( 'keel_defaults' );

	if ( is_array( $keel_legacy ) ) {
		$keel_known = array_intersect(
			array_keys( $keel_legacy ),
			array( 'disable_comments', 'disable_rest', 'core_update_policy', 'login_logo_behavior', 'admin_menu_width' )
		);

		if ( ! empty( $keel_known ) ) {
			delete_option( 'keel_defaults' );
		}
	}

	// Transients are options with a known prefix, and the timeout row is a
	// second option that outlives the value if only the value is deleted.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_keel_hibp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_keel_hibp_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	/*
	 * Network policy first, and unconditionally: it is a single site option that
	 * belongs to no subsite, so the per-site loop below would never reach it and
	 * it would outlive the plugin as an orphan.
	 */
	delete_site_option( 'keel_network_settings' );

	/*
	 * Options are per site, so every site needs clearing — including ones this
	 * plugin was never active on, because a network-activated plugin seeded
	 * them all.
	 *
	 * get_sites() is capped rather than unbounded: a large network would
	 * otherwise load every site object into memory at once during an uninstall
	 * that has no progress indicator and no way to resume.
	 */
	$keel_paged = 0;

	do {
		$keel_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
				'offset' => $keel_paged * 200,
			)
		);

		foreach ( $keel_sites as $keel_site_id ) {
			switch_to_blog( $keel_site_id );
			keel_defaults_uninstall_site();
			restore_current_blog();
		}

		$keel_found = count( $keel_sites );
		++$keel_paged;
	} while ( 200 === $keel_found );

	// The user table is network-wide, so these are deleted once rather than once
	// per site. Doing it inside the loop would repeat the same queries for every
	// site on the network.
	delete_metadata( 'user', 0, 'keel_last_login', '', true );
	delete_metadata( 'user', 0, 'keel_conflicts_dismissed', '', true );
} else {
	keel_defaults_uninstall_site();
	delete_metadata( 'user', 0, 'keel_last_login', '', true );
	delete_metadata( 'user', 0, 'keel_conflicts_dismissed', '', true );
}
