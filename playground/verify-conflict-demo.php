<?php
/**
 * Assert what the conflict-diagnostics demo must show. Appended as a final
 * runPHP step by test-conflict-demo.py; never shipped in the hosted blueprint.
 */

require '/wordpress/wp-load.php';

wp_set_current_user( 1 );

/**
 * Fail loudly.
 *
 * @param bool   $ok      Condition.
 * @param string $message What went wrong.
 */
function keel_demo_assert( $ok, $message ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
	echo "ok - $message\n";
}

$report = keel_defaults_policy_overlap_report( true );

keel_demo_assert( array( 'disable-comments' ) === $report['structural']['comments_open'], 'Disable Comments is named on comments_open' );
keel_demo_assert( array( 'disable-comments' ) === $report['structural']['pings_open'], 'Disable Comments is named on pings_open' );
keel_demo_assert( array( 'classic-editor' ) === $report['structural']['use_block_editor_for_post'], 'Classic Editor is named on use_block_editor_for_post' );
keel_demo_assert( array( KEEL_DEFAULTS_UNATTRIBUTED ) === $report['unconfirmed']['xmlrpc_enabled'], 'Disable XML-RPC is untraceable on xmlrpc_enabled' );
keel_demo_assert( array( KEEL_DEFAULTS_MU_PLUGIN_PREFIX . 'host-revision-limit.php' ) === $report['unconfirmed']['wp_revisions_to_keep'], 'The host mu-plugin is listed on wp_revisions_to_keep' );
keel_demo_assert( array( 'xmlrpc_enabled' ) === array_keys( keel_defaults_policy_divergences() ), 'Remote publishing is recorded as not taking effect' );

$result = keel_defaults_site_health_conflicts();
keel_demo_assert( 'recommended' === $result['status'], 'Site Health files the finding as recommended, not passed' );
keel_demo_assert( false !== strpos( $result['description'], 'Classic Editor, Disable Comments' ), 'Site Health names both plugins' );
keel_demo_assert( false !== strpos( $result['description'], 'host-revision-limit.php' ), 'Site Health names the must-use file' );

require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'dashboard' );
ob_start();
keel_defaults_render_conflicts_notice();
$notice = ob_get_clean();
keel_demo_assert( false !== strpos( $notice, 'notice-warning' ), 'The Dashboard notice is a warning, because a setting is not taking effect' );
keel_demo_assert( false !== strpos( $notice, 'site-health.php' ), 'The Dashboard notice links to Site Health' );
