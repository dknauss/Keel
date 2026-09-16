<?php
/**
 * Easy Updates Manager — core: automatic minor releases only.
 *
 * One key decides this whole plugin's core policy in 9.0.x: MPSUM['core']['core_updates'],
 * whose values are 'automatic' (everything), 'automatic_minor', 'on' (manual only)
 * and 'off' (nothing). MPSUM_Disable_Updates reads it directly and
 * MPSUM_Admin_Ajax writes it, so this is what the settings screen stores.
 *
 * Not the legacy automatic_minor_updates / automatic_major_updates pair. Those
 * are 8.x names; main.php still migrates them, so setting them does reach the
 * right place — it arrives via maybe_migrate_ui_options() as
 * core_updates = 'automatic_minor' — but only until that migration is removed,
 * and a config that works through a compatibility shim is a config that will
 * quietly stop working.
 *
 * The default matters and is not this. 'core_updates' defaults to 'on', and
 * MPSUM_Disable_Updates reads 'on' as "manually update": it sets
 * is_core_updating_allowed = false and hooks that onto auto_update_core at
 * PHP_INT_MAX - 10, above anything else on the site. Activating this plugin and
 * changing nothing therefore switches core automatic updates off, including
 * security releases. That is a row in the matrix, not something to configure away.
 *
 * @package Keel
 */

$mpsum = (array) get_option( 'MPSUM', array() );
$core  = isset( $mpsum['core'] ) && is_array( $mpsum['core'] ) ? $mpsum['core'] : array();

$core['core_updates']        = 'automatic_minor';
$core['all_updates']         = 'on';
$core['translation_updates'] = 'automatic';

// The legacy pair would be migrated back over the value above on the next load,
// so a lab that has ever held them has to be cleared of them.
unset( $core['automatic_minor_updates'], $core['automatic_major_updates'] );

$mpsum['core']                = $core;
$mpsum['migrated_from_9_0_9'] = true;

update_option( 'MPSUM', $mpsum );

echo 'configured: core_updates=automatic_minor';
