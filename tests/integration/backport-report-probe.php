<?php
/**
 * Render the reporting half of the backport check and report what came out.
 *
 * The install matrix proved target derivation, offer selection and the install
 * itself. It never rendered the verdict, the ladder or the actions, so the
 * reporting path had no live coverage at all — and on a localized site it never
 * ran in any form.
 *
 * That gap is not theoretical. WordPress.org describes the same release
 * differently by locale: 6.8.8 carries new_files = false for en_US and true for
 * fr_FR, and keel_defaults_relaxed_ownership_allowed() derives relaxed file
 * ownership from exactly that field. So a localized site probes for filesystem
 * credentials under stricter rules than an English one, for the identical
 * release, which feeds operability, the credentials blocker, and the exception
 * in keel_defaults_selection_state(). None of it had ever been rendered.
 *
 * Emits JSON rather than asserting, so the shell can assert the parts that must
 * hold everywhere and print the parts that legitimately differ by locale.
 *
 * Run only through WP-CLI in a disposable integration site.
 *
 * @package keel
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$user = get_user_by( 'login', 'admin' );
wp_set_current_user( $user->ID );
set_current_screen( 'site-health' );

$state  = keel_defaults_minor_update_state();
$tip    = keel_defaults_branch_tip();
$result = keel_defaults_backport_test();
$ladder = keel_defaults_ladder_markup();
$action = keel_defaults_backport_actions( $tip );

echo wp_json_encode(
	array(
		'locale'         => get_locale(),
		'version'        => keel_defaults_wp_version(),
		'version_status' => keel_defaults_version_status(),
		'tip'            => $tip,
		'status'         => $result['status'],
		'label'          => wp_strip_all_tags( $result['label'] ),
		'description'    => wp_strip_all_tags( $result['description'] ),
		'ladder_len'     => strlen( $ladder ),
		'ladder'         => wp_strip_all_tags( $ladder ),
		'actions_len'    => strlen( $action ),
		'actions'        => wp_strip_all_tags( $action ),
		'selection'      => keel_defaults_selection_state( $state, keel_defaults_ladder_selection() ),
		'operable'       => (bool) $state['operable'],
		'policy'         => (bool) $state['policy'],
		'relaxed'        => (bool) keel_defaults_relaxed_ownership_allowed(),
		'blockers'       => keel_defaults_blocker_codes( $state['blockers'] ),
	)
);
