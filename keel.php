<?php
/**
 * Plugin Name:       Keel Defaults
 * Plugin URI:        https://github.com/dknauss/keel
 * Description:       More than 30 sane WordPress defaults, each one a switch you can see and turn off — security, updates, privacy, UX, and performance.
 * Version:           0.1.0-dev
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Dan Knauss
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keel
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
 * Architecture: every default is one entry in keel_defaults_schema() plus one
 * bootstrap `if`-block — a default is an opinionated filter behind a toggle.
 * Read the schema array first; it is the map.
 *
 * @package Keel
 */

// Bail if called directly.
defined( 'ABSPATH' ) || exit;

/** The single option name that stores all settings as one array. */
const KEEL_DEFAULTS_OPTION = 'keel_settings';

/** This file, for plugin_basename() — the Plugins-screen action links need it. */
define( 'KEEL_DEFAULTS_FILE', __FILE__ );

// Load the plugin's modules.
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/strings.php';
require_once __DIR__ . '/includes/updates.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/admin-ux.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/site-health.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings-page.php';

/**
 * On activation, seed the option with schema defaults so a fresh install
 * behaves as documented out of the box.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( KEEL_DEFAULTS_OPTION, false ) ) {
			$schema   = keel_defaults_schema();
			$defaults = array();
			foreach ( $schema as $key => $field ) {
				$defaults[ $key ] = $field['default'];
			}
			add_option( KEEL_DEFAULTS_OPTION, $defaults );
		}
	}
);
