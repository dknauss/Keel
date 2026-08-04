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

	return true;
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
 * Seed a subsite created after the plugin was network-activated.
 *
 * Without this, Keel is active on a new subsite but has never stored anything
 * there, so every default falls back to its schema value at read time. That
 * mostly looks fine — which is the problem. The settings screen shows the
 * documented defaults, the site behaves as documented, and nothing is stored;
 * then the schema changes in a later release and that subsite silently moves
 * with it while every other site keeps what it was given.
 *
 * @param \WP_Site $new_site The site that was just created.
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
