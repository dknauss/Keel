<?php
/**
 * Core delivery timeline: existing evidence, not an installation schedule.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render only for administrators allowed to update this installation.
 *
 * @param string|false $selected Already resolved by the Updates screen.
 * @param string|false $tip Already resolved by the Updates screen.
 */
function keel_defaults_render_update_timeline( $selected, $tip ) {
	if ( ! current_user_can( 'update_core' ) || ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) ) {
		return;
	}
	$now = time();
	// Reuse screen selection/status; no cron scan, collection request or result storage.
	echo keel_defaults_update_timeline_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction.
		$selected,
		$tip,
		keel_defaults_minor_update_state(),
		keel_defaults_cron_updates( get_site_transient( 'update_core' ), get_site_option( 'auto_core_update_failed' ), $now ),
		keel_defaults_cron_snapshot(),
		$now,
		defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
	);
}

/**
 * Compose current selection evidence and a validated, per-site cron snapshot.
 *
 * @param string|false $selected Core's current selection.
 * @param string|false $tip Branch tip.
 * @param array        $state Existing updater operability and blocker codes.
 * @param array        $update Sanitized offer age and failure from cron_updates().
 * @param array|false  $snapshot Validated cron_snapshot(), never a whole cron array.
 * @param int          $now Current timestamp.
 * @param bool         $external Whether traffic-triggered cron is disabled.
 * @return string Escaped HTML.
 */
function keel_defaults_update_timeline_markup( $selected, $tip, array $state, array $update, $snapshot, $now, $external ) {
	$selection = keel_defaults_selection_state( $state, $selected );
	$labels    = array(
		'blocked'   => __( 'Automatic delivery is blocked', 'keel-defaults' ),
		'unknown'   => __( 'Selected release: unknown', 'keel-defaults' ),
		'none'      => __( 'No automatic release selected; this does not establish that core is current.', 'keel-defaults' ),
		'scheduled' => __( 'An automatic release is selected; execution remains unconfirmed.', 'keel-defaults' ),
	);
	$html      = '<div class="keel-defaults-update-timeline"><h3>' . esc_html__( 'Core-update delivery timeline', 'keel-defaults' ) . '</h3><p>' . esc_html( $labels[ $selection ] ) . '</p><ul>';
	$html     .= '<li>' . esc_html__( 'Selected release:', 'keel-defaults' ) . ' ' . esc_html( is_string( $selected ) && '' !== $selected ? $selected : __( 'Unknown or none; see selection status above.', 'keel-defaults' ) ) . '</li>';
	$html     .= '<li>' . esc_html__( 'Available same-line patch:', 'keel-defaults' ) . ' ' . esc_html( is_string( $tip ) && '' !== $tip ? $tip : __( 'None identified or unavailable', 'keel-defaults' ) ) . '</li>';
	if ( 'blocked' === $selection ) {
		foreach ( $state['blockers'] as $blocker ) {
			$html .= '<li>' . esc_html__( 'Blocker code:', 'keel-defaults' ) . ' <code>' . esc_html( $blocker['code'] ) . '</code></li>';
		}
	}
	$age   = $update['offer_age'];
	$html .= '<li>' . ( null === $age ? esc_html__( 'Offer freshness: unknown (missing or future timestamp).', 'keel-defaults' ) : sprintf( /* translators: %d: age in seconds. */ esc_html__( 'Offer last_checked age: %d seconds.', 'keel-defaults' ), $age ) );
	if ( null !== $age && $age > 86400 ) {
		$html .= ' ' . esc_html__( 'This marker is stale (over one day).', 'keel-defaults' );
	}
	$html .= ' ' . esc_html__( 'This marker does not prove a successful response or when a release became available.', 'keel-defaults' ) . '</li>';
	if ( false === $snapshot || ! $snapshot['done'] || '' !== $snapshot['error'] ) {
		$html .= '<li>' . esc_html__( 'Schedule observations: unavailable or pending. Recheck the cron report in Site Health; this does not prove a broken scheduler.', 'keel-defaults' ) . '</li>';
	} else {
		$html .= '<li>' . esc_html__( 'Site-local schedule snapshot:', 'keel-defaults' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', $snapshot['checked'] ) ) . ' UTC';
		if ( $snapshot['data']['limited'] || $snapshot['data']['malformed'] ) {
			$html .= ' ' . esc_html__( 'Partial coverage; unobserved hooks may exist.', 'keel-defaults' );
		}
		$html .= '<ul>';
		foreach ( array( 'wp_version_check', 'wp_maybe_auto_update' ) as $hook ) {
			$at    = $snapshot['data']['core'][ $hook ] ?? null;
			$html .= '<li><code>' . esc_html( $hook ) . '</code>: ';
			if ( null === $at ) {
				$html .= esc_html__( 'Not observed; no next-run claim is possible.', 'keel-defaults' );
			} else {
				$timing = esc_html__( 'scheduled check, not an installation time.', 'keel-defaults' );
				if ( $at < $snapshot['checked'] ) {
					$timing = esc_html__( 'overdue when collected; current execution status is unknown.', 'keel-defaults' );
				} elseif ( $at <= $now ) {
					$timing = esc_html__( 'due since collection; current execution status is unknown.', 'keel-defaults' );
				}
				$html .= esc_html( gmdate( 'Y-m-d H:i:s', $at ) ) . ' UTC — ' . $timing;
			}
			$html .= '</li>';
		}
		$html .= '</ul></li>';
	}
	$failure = $update['failure'];
	$html   .= '<li>' . esc_html__( 'Retained core failure:', 'keel-defaults' ) . ' ';
	if ( null === $failure ) {
		$html .= esc_html__( 'None observed; this is not proof of success.', 'keel-defaults' );
	} else {
		$html .= '<code>' . esc_html( $failure['code'] ) . '</code> — ' . ( null === $failure['at'] ? esc_html__( 'time unknown', 'keel-defaults' ) : esc_html( gmdate( 'Y-m-d H:i:s', $failure['at'] ) ) . ' UTC' ) . '. ' . esc_html__( 'This record may be historical; it is not proof the selected release failed.', 'keel-defaults' );
	}
	$html .= '</li><li>' . esc_html__( 'Success history: unknown. No durable success history is collected by this view.', 'keel-defaults' ) . '</li></ul>';
	if ( $external ) {
		$html .= '<p>' . esc_html__( 'DISABLE_WP_CRON is enabled. An external scheduler may be intentional; whether it is running is unknown.', 'keel-defaults' ) . '</p>';
	}
	return $html . '<p>' . esc_html__( 'Offers and failure records are network-wide on multisite; schedule observations belong to this site and may be cached for up to one hour. Selection, branch status and schedule evidence are nontransactional and can change independently. No installation time is promised.', 'keel-defaults' ) . '</p></div>';
}
