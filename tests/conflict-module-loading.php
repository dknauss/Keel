<?php
/**
 * The conflict facade exposes its API without evaluating or registering policy.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );

function get_option( ...$args ) {
	throw new RuntimeException( 'Diagnostic loading must not read site state.' );
}
function get_transient( ...$args ) {
	throw new RuntimeException( 'Diagnostic loading must not read persisted observations.' );
}
function set_transient( ...$args ) {
	throw new RuntimeException( 'Diagnostic loading must not write observations.' );
}
function apply_filters( ...$args ) {
	throw new RuntimeException( 'Diagnostic loading must not execute policy filters.' );
}
function add_filter( ...$args ) {
	throw new RuntimeException( 'Bootstrap owns observer and policy registration.' );
}
function add_action( ...$args ) {
	throw new RuntimeException( 'Bootstrap owns notice and invalidation registration.' );
}

require_once dirname( __DIR__ ) . '/includes/conflicts.php';
require_once dirname( __DIR__ ) . '/includes/conflicts.php';

$callbacks = array(
	'policy_hooks',
	'callback_plugin_dir',
	'callback_theme',
	'theme_name',
	'core_roots',
	'path_is_under',
	'mu_plugin_files',
	'mu_plugin_package_dirs',
	'callback_mu_plugin',
	'callback_file',
	'active_plugin_roots',
	'plugin_name',
	'hook_settings',
	'hook_setting_names',
	'competing_plugins',
	'own_plugin_dir',
	'callable_id',
	'policy_overlap_report',
	'return_false',
	'return_zero',
	'login_header_url',
	'add_policy_filter',
	'policy_setting_for_hook',
	'registered_policy_hooks',
	'conflicts_fingerprint',
	'unattributed_hooks',
	'notice_fingerprint',
	'conflict_notice_screens',
	'render_conflicts_notice',
	'handle_conflicts_dismissal',
	'policy_expectations',
	'observe_policy_result',
	'policy_divergences',
	'active_plugin_fingerprint',
	'forget_policy_divergences',
	'watch_policy_results',
);
foreach ( $callbacks as $callback ) {
	if ( ! is_callable( 'keel_defaults_' . $callback ) ) {
		fwrite( STDERR, "Conflict entry point no longer exposes {$callback}.\n" );
		exit( 1 );
	}
}
if ( '__keel_unattributed__' !== KEEL_DEFAULTS_UNATTRIBUTED || 'theme:' !== KEEL_DEFAULTS_THEME_PREFIX || 'mu-plugin:' !== KEEL_DEFAULTS_MU_PLUGIN_PREFIX ) {
	fwrite( STDERR, "Diagnostic identity markers must remain compatible.\n" );
	exit( 1 );
}

fwrite( STDOUT, "conflict module loading: OK (36 callbacks, no runtime work on load)\n" );
