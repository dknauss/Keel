<?php
/**
 * Plugin Name:       Keel Defaults
 * Plugin URI:        https://github.com/dknauss/keel
 * Description:        Sane, individually-toggleable defaults for every new WordPress site — security, updates, privacy, UX, and performance. Configure under Settings → Keel.
 * Version:           0.1.0-dev
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Dan Knauss
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       keel
 * Domain Path:       /languages
 *
 * Keel is a de-branded evolution of "Better by Default" (WPYEG,
 * https://github.com/WPYEG/Better-by-Default), used under the GPL-3.0-or-later.
 * Original copyright is retained; see LICENSE and CREDITS.md.
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

// Load the plugin's modules.
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/strings.php';
require_once __DIR__ . '/includes/updates.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/admin-ux.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/site-health.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings-page.php';

// Load translations shipped in /languages (e.g. the en_CA set).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'keel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

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
