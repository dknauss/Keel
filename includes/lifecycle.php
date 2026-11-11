<?php
/**
 * Activation and site-creation seeding.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The documented defaults, as an option-shaped array.
 *
 * @return array<string,mixed>
 */
function keel_defaults_seed_values() {
	$seed = array();

	foreach ( keel_defaults_schema() as $key => $field ) {
		$seed[ $key ] = $field['default'];
	}

	return $seed;
}

/**
 * Seed one site's settings, if it has none.
 *
 * Never overwrites. A site that already holds settings has been configured by
 * somebody, and re-seeding it on reactivation would silently undo that — which
 * is the difference between "deactivate and reactivate to fix a glitch" and
 * "deactivate and reactivate to lose your configuration".
 *
 * @return bool True when values were written, false when settings already existed.
 */
function keel_defaults_seed_site() {
	if ( false !== get_option( KEEL_DEFAULTS_OPTION, false ) ) {
		return false;
	}

	add_option( KEEL_DEFAULTS_OPTION, keel_defaults_seed_values() );
	update_option( KEEL_DEFAULTS_DATA_VERSION_OPTION, KEEL_DEFAULTS_DATA_VERSION );

	return true;
}

/**
 * Upgrade one site's stored settings without moving established choices.
 *
 * Version 1 introduces `post_revisions_limit`. New activations seed the recommended
 * limit of 10, but an existing installation previously left WordPress's
 * unlimited default alone. Store -1 before bootstrap reads the new schema key,
 * so merely updating Keel does not turn that old behaviour into a cap.
 *
 * Version 2 stops the breach check keeping replies. Earlier versions kept each
 * reply for twelve hours, so the rows they wrote are deleted here, on the first
 * request after the update rather than whenever they would next have been read.
 *
 * The migration is deliberately lazy on multisite. `plugins_loaded` runs in
 * each site's context before Keel reads the setting, so a large network does
 * not need one unbounded upgrade request and every subsite is safe on its first
 * request after the update.
 *
 * @return bool True when this request advanced the data version.
 */
function keel_defaults_maybe_upgrade() {
	$version = (int) get_option( KEEL_DEFAULTS_DATA_VERSION_OPTION, 0 );
	if ( $version >= KEEL_DEFAULTS_DATA_VERSION ) {
		return false;
	}

	$stored = get_option( KEEL_DEFAULTS_OPTION, false );

	// An absent settings option belongs to activation/seeding, not migration.
	if ( false === $stored ) {
		return false;
	}

	$stored = is_array( $stored ) ? $stored : array();

	if ( $version < 1 && ! array_key_exists( 'post_revisions_limit', $stored ) ) {
		$stored['post_revisions_limit'] = -1;
		update_option( KEEL_DEFAULTS_OPTION, $stored );
	}

	// A refused delete leaves the version where it was, so the next request tries again.
	if ( $version < 2 && ! keel_defaults_forget_breach_replies() ) {
		return false;
	}

	update_option( KEEL_DEFAULTS_DATA_VERSION_OPTION, KEEL_DEFAULTS_DATA_VERSION );

	return true;
}

/**
 * Delete what an earlier version's breach check left in the database.
 *
 * Runs once when a site first loads this version, and again with WordPress's
 * own daily clean-up of expired cached data. The second covers a request that
 * was still running the earlier code while the plugin was being replaced, and
 * a site that went back to an earlier version for a while: either can write a
 * row after the first clean-up has been and gone.
 *
 * On multisite the rows belong to the network, so whichever site runs this
 * clears them for all of them.
 *
 * @return bool False only when the database refused the delete.
 */
function keel_defaults_forget_breach_replies() {
	require_once __DIR__ . '/password-hibp-store.php';

	$scope = keel_hibp_budget_scope();

	return ! $scope || keel_hibp_budget_forget_replies( $scope );
}

/**
 * Whether Keel is active across the whole network.
 *
 * A subsite created later should only be seeded when the plugin is
 * network-active. When it is activated per site instead, a new subsite does not
 * have Keel, and writing settings into it would leave an orphaned option behind
 * for a plugin that never ran there.
 *
 * @return bool
 */
function keel_defaults_is_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active_for_network( plugin_basename( KEEL_DEFAULTS_FILE ) );
}

/**
 * Seed on activation.
 *
 * Single site is one call. A network activation has to cover every existing
 * site, because `register_activation_hook` fires once — on whichever site the
 * network admin happened to be on — and every other subsite would otherwise run
 * with no stored settings at all until someone visited its settings screen.
 *
 * Sites are paged rather than fetched at once. `get_sites()` with no limit loads
 * every site object into memory, and a network large enough for that to matter
 * is exactly the network where an activation timing out halfway leaves an
 * inconsistent result nobody can see.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 * @return void
 */
function keel_defaults_activate( $network_wide = false ) {
	if ( ! is_multisite() || ! $network_wide ) {
		keel_defaults_seed_site();

		return;
	}

	$paged = 0;

	do {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $paged * 100,
			)
		);

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			keel_defaults_seed_site();
			restore_current_blog();
		}

		$found = count( $sites );
		++$paged;
	} while ( 100 === $found );
}

/**
 * Hand WordPress 7.2's /random/ rules back to core on deactivation.
 *
 * While Keel turns the redirects off, the saved rewrite rules leave them out,
 * and nothing rebuilds them once Keel is gone. Deleting the saved rules lets
 * each site's next request rebuild them under core's own answer. A network
 * deactivation fires this once, so it covers every site, paged like
 * keel_defaults_activate().
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 * @return void
 */
function keel_defaults_deactivate( $network_wide = false ) {
	if ( ! is_multisite() || ! $network_wide ) {
		keel_defaults_forget_random_rules();

		return;
	}

	$paged = 0;

	do {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $paged * 100,
			)
		);

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			keel_defaults_forget_random_rules();
			restore_current_blog();
		}

		$found = count( $sites );
		++$paged;
	} while ( 100 === $found );
}

/**
 * Delete one site's saved rewrite rules and the random-redirect note.
 *
 * Only where the note exists: a site whose rules Keel never built under its
 * random-redirect answer has nothing of Keel's to undo.
 *
 * @return void
 */
function keel_defaults_forget_random_rules() {
	if ( false === get_option( 'keel_random_rules_state' ) ) {
		return;
	}

	delete_option( 'keel_random_rules_state' );
	delete_option( 'rewrite_rules' );
}

/**
 * Seed a subsite created after the plugin was network-activated.
 *
 * Without this, Keel is active on a new subsite but has never stored anything
 * there, so every default falls back to its schema value at read time. That
 * mostly looks fine — which is the problem. The settings screen shows the
 * documented defaults, the site behaves as documented, and nothing is stored;
 * then the schema changes in a later release and that subsite silently moves
 * with it while every other site keeps what it was given.
 *
 * @param mixed $new_site Raw hook input; normally WP_Site, validated before use.
 * @return void
 */
function keel_defaults_seed_new_site( $new_site ) {
	if ( ! keel_defaults_is_network_active() ) {
		return;
	}

	$site_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : 0;

	if ( ! $site_id ) {
		return;
	}

	switch_to_blog( $site_id );
	keel_defaults_seed_site();
	restore_current_blog();
}
