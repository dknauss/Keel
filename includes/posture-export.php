<?php
/**
 * Explicit, authenticated support download. Only allowlisted evidence leaves.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check authority to export the current site’s posture.
 *
 * @return bool Whether this user may export.
 */
function keel_defaults_can_export_posture() {
	return current_user_can( 'manage_options' ) && current_user_can( 'view_site_health_checks' )
		&& ( ! is_multisite() || current_user_can( 'manage_network_options' ) );
}

/**
 * Validate a configured policy against its closed schema domain.
 *
 * @param array $field Schema entry.
 * @param mixed $value Resolved policy, never blindly serialized.
 * @return string|int|null Validated value; roles/free-form values are withheld.
 */
function keel_defaults_export_policy_value( array $field, $value ) {
	if ( 'toggle' === $field['type'] ) {
		return in_array( $value, array( 'yes', 'no' ), true ) ? $value : null;
	}
	if ( in_array( $field['type'], array( 'select', 'range' ), true ) ) {
		$choices = $field['choices'] ?? $field['values'] ?? array();
		return is_string( $value ) && in_array( $value, $choices, true ) ? $value : null;
	}
	if ( 'number' === $field['type'] && is_int( $value ) && $value >= ( $field['min'] ?? 0 ) && $value <= ( $field['max'] ?? PHP_INT_MAX ) ) {
		return $value;
	}
	return null;
}

/**
 * Only real observation timestamps, never future/fabricated collection times.
 *
 * @param mixed $at Candidate timestamp.
 * @param int   $now Export time.
 * @return int|null Safe timestamp.
 */
function keel_defaults_export_observed_at( $at, $now ) {
	return is_int( $at ) && $at > 0 && $at <= $now ? $at : null;
}

/**
 * Build a closed support schema from configuration and already-cached evidence.
 *
 * No health/render callbacks, selector, filesystem probes, collectors, overlap
 * reflection, item filters or remote status functions are invoked here.
 *
 * @return array JSON-safe report. No raw option/cache arrays are returned.
 */
function keel_defaults_posture_export() {
	$now      = time();
	$policies = array();
	foreach ( keel_defaults_schema() as $key => $field ) {
		$value  = keel_defaults_get( $key );
		$source = 'site_or_default';
		if ( is_multisite() && keel_defaults_network_manages( $key ) ) {
			$source = 'network';
		}
		if ( in_array( $key, array( 'plugin_auto_update_policy', 'theme_auto_update_policy' ), true ) ) {
			$value = keel_defaults_extension_update_policy( 'plugin_auto_update_policy' === $key ? 'plugin' : 'theme' );
			if ( is_multisite() && 'network' !== $source ) {
				$source = 'main_site';
			}
		}
		if ( null !== keel_defaults_config_lock( $key ) ) {
			$source = 'configuration';
		}
		$value            = keel_defaults_config_locked_value( $key, $value );
		$policies[ $key ] = array(
			'value'     => keel_defaults_export_policy_value( $field, $value ),
			'source'    => $source,
			'supported' => keel_defaults_key_supported( $key ),
		);
	}
	$version  = keel_defaults_wp_version();
	$version  = is_string( $version ) && preg_match( '/^\d{1,3}\.\d{1,3}(?:\.\d{1,3})?$/D', $version ) ? $version : null;
	$map      = get_site_transient( 'keel_stable_check' );
	$status   = is_array( $map ) && null !== $version ? ( $map[ $version ] ?? null ) : null;
	$status   = in_array( $status, array( 'latest', 'outdated', 'insecure' ), true ) ? $status : 'unknown';
	$offers   = get_site_transient( 'update_core' );
	$failure  = get_site_option( 'auto_core_update_failed' );
	$blocked  = null !== keel_defaults_background_updates_off();
	$core     = array(
		'version'              => $version,
		'status'               => $status,
		'status_scope'         => is_multisite() ? 'network' : 'current_site',
		'observed_at'          => null,
		'freshness'            => 'unknown_no_cached_timestamp',
		'operability'          => $blocked ? 'configuration_blocked' : 'unknown',
		'operability_coverage' => 'configuration_only_no_probes',
		'offer_checked_at'     => keel_defaults_export_observed_at( is_object( $offers ) ? ( $offers->last_checked ?? null ) : null, $now ),
		'failure_observed'     => is_array( $failure ) && ! empty( $failure ) ? true : null,
		'failure_critical'     => is_array( $failure ) && isset( $failure['critical'] ) && is_bool( $failure['critical'] ) ? $failure['critical'] : null,
		'failure_at'           => keel_defaults_export_observed_at( is_array( $failure ) ? ( $failure['timestamp'] ?? null ) : null, $now ),
		'success_history'      => 'unknown',
	);
	$autoload = keel_defaults_autoload_snapshot();
	$cron     = keel_defaults_cron_snapshot();
	$diag     = array();
	foreach ( array(
		'autoload' => $autoload,
		'cron'     => $cron,
	) as $kind => $snapshot ) {
		$checked       = is_array( $snapshot ) ? keel_defaults_export_observed_at( $snapshot['checked'] ?? null, $now ) : null;
		$diag[ $kind ] = array(
			'scope'       => 'current_site',
			'observed_at' => null,
			'coverage'    => 'unavailable',
		);
		if ( false === $snapshot || null === $checked ) {
			continue;
		}
		if ( ! $snapshot['done'] ) {
			$diag[ $kind ]['coverage'] = 'pending';
			continue;
		}
		if ( '' !== $snapshot['error'] ) {
			continue;
		}
		$diag[ $kind ]['observed_at'] = $checked;
		if ( 'autoload' === $kind ) {
			$diag[ $kind ]['coverage']      = $snapshot['limited'] ? 'partial' : 'complete_at_collection';
			$diag[ $kind ]['scanned_rows']  = $snapshot['scanned'];
			$diag[ $kind ]['autoload_rows'] = $snapshot['autoloaded'];
			$diag[ $kind ]['reported_rows'] = count( $snapshot['top'] );
			$diag[ $kind ]['largest_bytes'] = empty( $snapshot['top'] ) ? null : max( array_column( $snapshot['top'], 'bytes' ) );
		} else {
			$data                           = $snapshot['data'];
			$diag[ $kind ]['coverage']      = $data['limited'] || $data['malformed'] ? 'partial' : 'complete_at_collection';
			$diag[ $kind ]['examined']      = $data['examined'];
			$diag[ $kind ]['overdue_rows']  = count( $data['overdue'] );
			$diag[ $kind ]['frequent_rows'] = count( $data['frequent'] );
		}
	}
	// This cache has no observation timestamp. Do not turn absent/invalid cache
	// into an assertion that the site is conflict-free, or refresh attribution.
	$divergence = get_transient( 'keel_policy_divergence' );
	$conflicts  = array(
		'scope'              => 'current_site',
		'observed_at'        => null,
		'coverage'           => 'unknown',
		'observed_hooks'     => null,
		'structural_overlap' => 'unknown_not_scanned',
	);
	if ( is_array( $divergence ) && is_array( $divergence['hooks'] ?? null ) && count( $divergence['hooks'] ) <= 100
		&& is_string( $divergence['plugins'] ?? null ) && keel_defaults_active_plugin_fingerprint() === $divergence['plugins'] ) {
		$valid = array_filter( $divergence['hooks'], 'is_bool' );
		if ( count( $valid ) === count( $divergence['hooks'] ) ) {
			$conflicts['coverage']       = 'cached_observations_not_revalidated';
			$conflicts['observed_hooks'] = count( $valid );
		}
	}
	return array(
		'schema'            => 1,
		'generated_at'      => $now,
		'scope'             => is_multisite() ? 'current_site_with_network_core_evidence' : 'current_site',
		'policies'          => $policies,
		'core'              => $core,
		'diagnostics'       => $diag,
		'conflicts'         => $conflicts,
		'collection_limits' => array(
			'autoload_scanned_rows'   => 10000,
			'cron_examined_instances' => 2000,
		),
		'limitations'       => array( 'configured_policies_not_runtime_filter_verdicts', 'nontransactional_cached_evidence', 'null_means_unknown_or_withheld', 'no_inventory_or_identifiers', 'no_installation_time_guarantee', 'offer_last_checked_not_success_or_availability_time', 'retained_failure_may_be_historical', 'cached_conflicts_not_revalidated' ),
	);
}

/**
 * Dispatch this one admin-post request before core's init scheduling, cron spawn
 * and admin_init update refreshes. Capabilities/nonces are available at init.
 *
 * @return void
 */
function keel_defaults_dispatch_posture_export() {
	global $pagenow;
	if ( 'admin-post.php' !== $pagenow ) {
		return;
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- dispatch only; handler verifies method, authority and nonce before collecting.
	if ( 'keel_posture_export' === ( $_POST['action'] ?? $_GET['action'] ?? '' ) ) {
		keel_defaults_handle_posture_export();
	}
}

/** Serve only an explicit nonce-protected POST; never register a public endpoint. */
function keel_defaults_handle_posture_export() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- exact allowlist comparison; never output or passed on.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( esc_html__( 'Use the Download JSON button on the Keel settings screen.', 'keel-defaults' ), '', array( 'response' => 405 ) );
		return;
	}
	if ( ! keel_defaults_can_export_posture() ) {
		wp_die( esc_html__( 'You do not have permission to export this posture report.', 'keel-defaults' ), '', array( 'response' => 403 ) );
		return;
	}
	check_admin_referer( 'keel-posture-export-' . get_current_blog_id() );
	$json = wp_json_encode( keel_defaults_posture_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		wp_die( esc_html__( 'The posture report could not be encoded.', 'keel-defaults' ), '', array( 'response' => 500 ) );
		return;
	}
	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="keel-posture.json"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, never HTML; fields projected above.
	exit;
}

/** Render the explicit download form outside the settings-save form. */
function keel_defaults_render_posture_export() {
	if ( ! keel_defaults_can_export_posture() ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Export Settings and Diagnostics', 'keel-defaults' ); ?></h2>
	<p><?php esc_html_e( 'Download validated policy choices and cached diagnostic summaries. No site URL, user data, plugin inventory, raw option records, callback paths, or failure payloads are included. Unknown evidence remains unknown. Review the JSON before sharing it.', 'keel-defaults' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="keel_posture_export" />
		<?php wp_nonce_field( 'keel-posture-export-' . get_current_blog_id() ); ?>
		<?php submit_button( __( 'Download JSON', 'keel-defaults' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php
}

add_action( 'init', 'keel_defaults_dispatch_posture_export', 0 );
add_action( 'admin_post_keel_posture_export', 'keel_defaults_handle_posture_export' );
