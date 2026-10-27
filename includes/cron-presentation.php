<?php
/**
 * Site Health presentation for cached cron/update observations.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a bounded table. Names and owner labels are always escaped.
 *
 * @param array  $rows Observations.
 * @param string $caption Table caption.
 * @return string HTML.
 */
function keel_defaults_cron_table( $rows, $caption ) {
	if ( empty( $rows ) ) {
		return '';
	}
	$html = '<div role="region" tabindex="0" aria-label="' . esc_attr( $caption ) . '" style="max-width:100%;overflow-x:auto"><table class="widefat striped" style="table-layout:fixed;min-width:640px"><caption>' . esc_html( $caption ) . '</caption><thead><tr>';
	foreach ( array( __( 'Hook', 'keel-defaults' ), __( 'Schedule / interval (seconds)', 'keel-defaults' ), __( 'Late by (seconds)', 'keel-defaults' ), __( 'Probable owner and confidence', 'keel-defaults' ) ) as $heading ) {
		$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( array_slice( $rows, 0, 5 ) as $row ) {
		$owner      = keel_defaults_cron_owner( $row['hook'] );
		$confidence = array(
			'high'    => __( 'High', 'keel-defaults' ),
			'low'     => __( 'Low', 'keel-defaults' ),
			'unknown' => __( 'Unknown', 'keel-defaults' ),
		);
		$html      .= '<tr><td style="overflow-wrap:anywhere"><code>' . esc_html( $row['hook'] ) . '</code></td><td style="overflow-wrap:anywhere">' . esc_html( $row['schedule'] ) . ' / ' . (int) $row['interval'] . '</td><td>' . (int) $row['delay'] . '</td><td style="overflow-wrap:anywhere">' . esc_html( $owner['owner'] ) . ' — ' . esc_html( $confidence[ $owner['confidence'] ] ) . ': ' . esc_html( $owner['evidence'] ) . '</td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * Request collection without inspecting cron data in the async result request.
 *
 * @return array Core Site Health result.
 */
function keel_defaults_cron_health() {
	$result = array(
		'label'       => __( 'Cron pressure and update delivery', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_cron',
	);
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		return $result;
	}
	$state = keel_defaults_cron_snapshot();
	if ( false === $state ) {
		$token = keel_defaults_autoload_lock();
		if ( false !== $token ) {
			try {
				$state = keel_defaults_cron_snapshot( true );
				if ( false === $state ) {
					$state = array(
						'schema'  => 1,
						'site'    => get_current_blog_id(),
						'checked' => time(),
						'done'    => false,
						'error'   => '',
					);
					set_transient( 'keel_cron_report', $state, 3600 );
				}
			} finally {
				keel_defaults_autoload_unlock( $token );
			}
		}
	}
	if ( false === $state || ! $state['done'] ) {
		keel_defaults_cron_schedule();
		$result['label']       = __( 'Cron attribution is awaiting background collection', 'keel-defaults' );
		$result['description'] = '<p>' . esc_html__( 'Background scheduling may be delayed or externally managed. Recheck Site Health for the cached report; pending collection does not prove cron is broken.', 'keel-defaults' ) . '</p>';
		return $result;
	}
	$result['description'] = '<p>' . esc_html__( 'These observations show scheduling pressure, not job runtime, CPU load or a proven cause of update delay. No arguments, callback paths, failure messages or error data are retained. No jobs, schedules, offers or update policy are changed.', 'keel-defaults' ) . '</p><p>' . esc_html( gmdate( 'Y-m-d H:i:s', $state['checked'] ) ) . ' UTC.</p>';
	if ( '' !== $state['error'] ) {
		$errors                 = array(
			'cron_too_large'     => __( 'The serialized cron option exceeds the 1 MiB collection limit. No cron payload was returned to the collector. Coverage is unavailable, not an empty schedule.', 'keel-defaults' ),
			'malformed_cron'     => __( 'The cron payload could not be decoded safely. Coverage is unavailable.', 'keel-defaults' ),
			'query_failed'       => __( 'The cron metadata query failed. Coverage is unavailable; retry after the five-minute error cache expires.', 'keel-defaults' ),
			'scheduling_delayed' => __( 'Background collection did not complete within ten minutes. An external scheduler may be intentional; this does not establish a cron failure. Recheck after the cached snapshot expires.', 'keel-defaults' ),
		);
		$result['label']        = __( 'Cron attribution has incomplete coverage', 'keel-defaults' );
		$result['status']       = 'recommended';
		$result['description'] .= '<p>' . esc_html( $errors[ $state['error'] ] ?? __( 'Collection unavailable.', 'keel-defaults' ) ) . '</p>';
	} else {
		$data = $state['data'];
		if ( $data['limited'] || $data['malformed'] ) {
			$result['description'] .= '<p>' . esc_html__( 'Partial coverage: collection reached its event/traversal limit or skipped malformed entries. Unobserved hooks may exist; this is not a whole-schedule ranking.', 'keel-defaults' ) . '</p>';
		}
		$result['description'] .= '<p>' . sprintf( /* translators: %d: examined event count. */ esc_html__( 'Examined %d event instances. Up to five observations per table: over fifteen minutes late, or recurring more often than every five minutes. These are review thresholds, not failures or evidence of unnecessary work.', 'keel-defaults' ), (int) $data['examined'] ) . '</p>';
		$result['description'] .= keel_defaults_cron_table( $data['overdue'], __( 'Most overdue observed events', 'keel-defaults' ) );
		$result['description'] .= keel_defaults_cron_table( $data['frequent'], __( 'Most frequent observed recurring events', 'keel-defaults' ) );
		foreach ( array( 'wp_version_check', 'wp_maybe_auto_update' ) as $hook ) {
			$next                   = $data['core'][ $hook ] ?? null;
			$result['description'] .= '<p><code>' . esc_html( $hook ) . '</code>: ' . ( null === $next ? esc_html__( 'Not observed in this snapshot; no next-run claim is possible.', 'keel-defaults' ) : esc_html( gmdate( 'Y-m-d H:i:s', $next ) ) . ' UTC' ) . '</p>';
		}
		if ( $data['overdue'] || $data['frequent'] ) {
			$result['status'] = 'recommended';
		}
	}
	if ( isset( $state['external'] ) && $state['external'] ) {
		$result['description'] .= '<p>' . sprintf(
			/* translators: %s: DISABLE_WP_CRON. */
			esc_html__( '%s is enabled. An external scheduler may be intentional; Keel cannot verify whether it is running.', 'keel-defaults' ),
			'<code>DISABLE_WP_CRON</code>'
		) . '</p>';
	}
	if ( isset( $state['update'] ) ) {
		$update                 = $state['update'];
		$result['description'] .= '<p>' . sprintf(
			/* translators: %s: last_checked, the update transient's timestamp field. */
			esc_html__( 'Core offers and the core failure record are network-wide on multisite; cron events above belong to this site. Offer age uses WordPress’s %s marker, which does not prove a successful response or that an update was available then.', 'keel-defaults' ),
			'<code>last_checked</code>'
		) . '</p>';
		$result['description'] .= '<p>' . ( null === $update['offer_age'] ? esc_html__( 'Core offer-cache age: unknown (missing or future timestamp).', 'keel-defaults' ) : sprintf( /* translators: %d: age in seconds. */ esc_html__( 'Core offer-cache age at snapshot: %d seconds.', 'keel-defaults' ), $update['offer_age'] ) ) . '</p>';
		if ( null !== $update['offer_age'] && $update['offer_age'] > 86400 ) {
			$result['status'] = 'recommended';
		}
		if ( null !== $update['failure'] ) {
			$failure                = $update['failure'];
			$result['status']       = 'recommended';
			$result['description'] .= '<p>' . esc_html__( 'WordPress retains a core-update failure record; it may be historical. Failure code:', 'keel-defaults' ) . ' <code>' . esc_html( $failure['code'] ) . '</code>; ' . ( null === $failure['at'] ? esc_html__( 'time unknown', 'keel-defaults' ) : esc_html( gmdate( 'Y-m-d H:i:s', $failure['at'] ) ) . ' UTC' ) . '.</p>';
		} else {
			$result['description'] .= '<p>' . esc_html__( 'No retained core-update failure record was observed. This does not prove every update attempt succeeded.', 'keel-defaults' ) . '</p>';
		}
	}
	$result['actions'] = '<p>' . esc_html__( 'Confirm hook ownership and purpose with the component maintainer before changing a schedule. Review the external scheduler and WordPress Updates screen for delivery problems. Collection is nontransactional and cached for up to one hour; clock changes and concurrent scheduling can affect observations. No installation time is promised.', 'keel-defaults' ) . '</p>';
	return $result;
}
