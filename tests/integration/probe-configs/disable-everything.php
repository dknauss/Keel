<?php
/**
 * Disable Everything — the toggles that overlap what this harness measures.
 *
 * Its option keys are the setting slug prefixed with `disable-everything-options-`
 * and the value is the literal string YES, not a boolean. Guessed key names fail
 * silently: the option saves, the plugin reads nothing it recognises, and the
 * probe reports a plugin that does nothing.
 *
 * @package Keel
 */

$settings = (array) get_option( 'disable_everything_settings', array() );

foreach ( array( 'restapi', 'xmlrpc', 'rssfeeds', 'emojis', 'oembed', 'generator', 'rsdlink', 'manifest', 'shortlink', 'blockuserenumeration' ) as $key ) {
	$settings[ 'disable-everything-options-' . $key ] = 'YES';
}

update_option( 'disable_everything_settings', $settings );

echo 'configured';
