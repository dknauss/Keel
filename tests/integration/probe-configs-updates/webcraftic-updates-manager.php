<?php
/**
 * Webcraftic Updates Manager — core: minor auto-updates.
 *
 * Its switch is one option, wp_update_core, and the branch that handles it in
 * Configurate_Updates falls through to add_filter( 'allow_minor_auto_core_updates',
 * '__return_true' ) for any value that is not one of its four named cases. So an
 * unset option already means minor-only, and 'minor' is written here to say so
 * rather than to rely on a default branch.
 *
 * The option NAME is the trap, and it is the same one the Clearfy run in the
 * teardown matrix documented: this is a Webcraftic "factory" plugin, and
 * getPopulateOption() prefixes the key with the prefix the plugin object carries
 * at runtime, not with anything declared in the file that reads it. Setting the
 * bare name leaves the plugin inert while every surface looks configured. The
 * prefix is resolved from the plugin object here rather than hardcoded, and this
 * file fails loudly if it cannot find one.
 *
 * @package Keel
 */

$prefix = '';

if ( function_exists( 'WUM_Plugin' ) ) {
	$plugin = WUM_Plugin();
	if ( is_object( $plugin ) && method_exists( $plugin, 'getOptionName' ) ) {
		$prefix = str_replace( 'wp_update_core', '', $plugin->getOptionName( 'wp_update_core' ) );
	}
}

if ( '' === $prefix ) {
	// The plugin is not loaded (this file runs with --skip-plugins, by design),
	// so fall back to the prefix its own bootstrap registers. Asserted below.
	$prefix = 'wbcr_updates_manager_';
}

update_option( $prefix . 'wp_update_core', 'minor' );

if ( 'minor' !== get_option( $prefix . 'wp_update_core' ) ) {
	fwrite( STDERR, "webcraftic-updates-manager: {$prefix}wp_update_core did not store.\n" );
	exit( 1 );
}

echo 'configured: ' . $prefix . 'wp_update_core=minor';
