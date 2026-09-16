<?php
/**
 * Update Control — active, core: minor.
 *
 * Its own defaults, written out. get_options() merges update_control_options
 * over a defaults array that already says active => 'yes', core => 'minor', so
 * activation alone configures it; the settings screen writes these same keys.
 *
 * It has no menu entry: the fields are registered against the 'general' page, so
 * the screen the probe has to read for this plugin is options-general.php. The
 * harness fetches that for every run, which is why a plugin with no screen of
 * its own is still measurable here.
 *
 * @package Keel
 */

$options = (array) get_option( 'update_control_options', array() );

$options['active']      = 'yes';
$options['core']        = 'minor';
$options['translation'] = true;

update_option( 'update_control_options', $options );

echo 'configured: active=yes, core=minor';
