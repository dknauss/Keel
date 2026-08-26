<?php
/**
 * Plugin Name:       Keel Defaults
 * Plugin URI:        https://github.com/dknauss/keel
 * Description:       39 sane WordPress defaults, each one a switch you can see and turn off — security, updates, privacy, UX, and performance.
 * Version:           0.5.7
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Dan Knauss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keel-defaults
 * Domain Path:       /languages
 *
 * Keel is a de-branded evolution of "Better by Default" (WPYEG,
 * https://github.com/WPYEG/Better-by-Default), whose sole author also licenses the
 * portions carried over here under the GPL-2.0-or-later, with further defaults
 * adapted from the Pixel Managed Platform plugin — itself a hard fork of
 * "10up Experience" by 10up (https://github.com/10up/10up-experience),
 * GPL-2.0-or-later. Original copyright is retained by the respective authors; see
 * LICENSE and the Credits section of readme.txt.
 *
 * Architecture: a default is usually one entry in keel_defaults_schema(), one
 * bootstrap `if`-block, and its display copy in includes/strings.php under the
 * same key — a default is an opinionated filter behind a toggle. Some share a
 * bootstrap block where they belong together, so that is the shape rather than a
 * rule. Read the schema array first; it is the map.
 *
 * @package Keel
 */

// Bail if called directly.
defined( 'ABSPATH' ) || exit;

/** The single option name that stores all settings as one array. */
const KEEL_DEFAULTS_OPTION = 'keel_settings';

/**
 * Our key in the Site Health → Info array.
 *
 * Named rather than inlined because WordPress builds the section's DOM id from
 * it — `health-check-accordion-block-{key}` — and the stylesheet for that section
 * has to target the same string. Two literals that must agree and sit in
 * different files are a silent breakage waiting to happen: change one and the
 * styling stops applying with nothing failing.
 */
const KEEL_DEFAULTS_INFO_SECTION = 'keel';

/**
 * Where network-scoped policy lives, on multisite.
 *
 * A *site* option — `get_site_option()` — so one value serves the whole network
 * rather than being copied into every subsite. A key present here is decided by
 * a Super Admin; a key absent is each site's own business.
 */
const KEEL_DEFAULTS_NETWORK_OPTION = 'keel_network_settings';

/** Data-schema version, stored per site so new defaults can migrate safely. */
const KEEL_DEFAULTS_DATA_VERSION_OPTION = 'keel_settings_data_version';

/** Current data-schema version. */
const KEEL_DEFAULTS_DATA_VERSION = 1;

/** This file, for plugin_basename() — the Plugins-screen action links need it. */
define( 'KEEL_DEFAULTS_FILE', __FILE__ );

/**
 * Plugin version, for asset cache-busting.
 *
 * Kept in step with the header above by tests/release-workflows.php, which reads
 * both and fails when they disagree — an asset version that lags the plugin is a
 * stylesheet a browser goes on serving from cache after it changed.
 */
const KEEL_DEFAULTS_VERSION = '0.5.7';

// Load the plugin's modules.
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/network.php';
require_once __DIR__ . '/includes/lifecycle.php';
require_once __DIR__ . '/includes/strings.php';
require_once __DIR__ . '/includes/updates.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/admin-ux.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/conflicts.php';
require_once __DIR__ . '/includes/site-health.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings-page.php';

/*
 * Seeding, and the two moments it has to happen.
 *
 * Activation covers every site that exists. wp_initialize_site covers the ones
 * created afterwards — without it, a subsite added to a network next month runs
 * Keel with nothing stored, which looks correct until the schema changes under
 * it. Both live in includes/lifecycle.php so they can be tested without
 * activating a plugin.
 */
register_activation_hook( __FILE__, 'keel_defaults_activate' );
add_action( 'wp_initialize_site', 'keel_defaults_seed_new_site', 100 );
// Migrate before the default-priority bootstrap reads any newly added key.
add_action( 'plugins_loaded', 'keel_defaults_maybe_upgrade', 0 );
