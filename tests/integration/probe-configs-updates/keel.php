<?php
/**
 * Keel — core update policy: minor, translations on.
 *
 * Separate from probe-configs/keel.php on purpose. That file configures the
 * comment, REST and XML-RPC teardowns and says nothing about updates; this one
 * configures the update policy and touches nothing else. Running the update
 * probe against the teardown configuration would close the REST API underneath
 * an admin-screen measurement for no reason, and running the teardown probe
 * against this one would measure a plugin with every teardown off.
 *
 * 'minor' is the policy every other file in this directory is also set to: take
 * maintenance and security releases automatically, do not take a major.
 *
 * @package Keel
 */

$settings = (array) get_option( 'keel_settings', array() );

$settings['core_update_policy']       = 'minor';
$settings['auto_update_translations'] = 'yes';

update_option( 'keel_settings', $settings );

echo 'configured: core_update_policy=minor';
