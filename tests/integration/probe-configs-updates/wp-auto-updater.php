<?php
/**
 * WP Auto Updater — core: minor (its own default), written explicitly.
 *
 * Its get_options() array_replace_recursive()s over $default_options, so the plugin
 * is fully configured the moment it is activated and 'minor' is already what it
 * would do. Writing the option anyway is what the settings screen does on the
 * first save, and it pins the value against a future change of default.
 *
 * Note for anyone reading the resulting numbers: this option is NOT where this
 * plugin's core policy takes effect during an ordinary request. The
 * allow_*_auto_core_updates filters are added inside its own cron callback,
 * between wp_auto_updater/before_auto_update/wordpress_core and the matching
 * after action, so a probe of the filters outside that callback correctly reads
 * them as untouched. What activation does change globally is auto_update_core_major,
 * which it sets to 'disable' as a site option.
 *
 * @package Keel
 */

$options = (array) get_option( 'wp_auto_updater_options', array() );

$options['core']        = 'minor';
$options['theme']       = false;
$options['plugin']      = false;
$options['translation'] = true;

update_option( 'wp_auto_updater_options', $options );

echo 'configured: core=minor';
