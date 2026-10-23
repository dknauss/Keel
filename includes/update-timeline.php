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
 * Render a plain admin table from cells that are already escaped HTML.
 *
 * @param string[]   $head Escaped column headings.
 * @param string[][] $rows Escaped cells; the first cell of each row is its header.
 * @param string[]   $widths Optional CSS widths for the leading columns.
 * @return string Escaped HTML.
 */
function keel_defaults_update_table_markup( array $head, array $rows, array $widths = array() ) {
	$html = '<table class="widefat striped" style="max-width:820px"><thead><tr>';
	foreach ( $head as $i => $cell ) {
		$html .= '<th scope="col"' . ( isset( $widths[ $i ] ) ? ' style="width:' . esc_attr( $widths[ $i ] ) . '"' : '' ) . '>' . $cell . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr><th scope="row" style="vertical-align:top">' . array_shift( $row ) . '</th><td>' . implode( '</td><td>', $row ) . '</td></tr>';
	}
	return $html . '</tbody></table>';
}

/**
 * Compose current selection evidence and a validated, per-site cron snapshot.
 *
 * Rows with nothing to report (no failure on record, no newer patch) are left out,
 * and the caveats every row shares live in one footnote.
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
	$rows      = array();

	// Blocker codes only; their longer texts stay with the notice above.
	$reasons = array(
		'file_mods'                   => __( 'file changes are turned off', 'keel-defaults' ),
		'automatic_disabled_constant' => __( 'automatic updates are turned off in wp-config.php', 'keel-defaults' ),
		'automatic_disabled_filter'   => __( 'a plugin or theme turns automatic updates off', 'keel-defaults' ),
		'automatic_disabled_unknown'  => __( 'automatic updates are turned off', 'keel-defaults' ),
		'credentials'                 => __( 'WordPress needs file credentials to update', 'keel-defaults' ),
		'previous_failure'            => __( 'an earlier update failed and WordPress won’t retry', 'keel-defaults' ),
		'vcs'                         => __( 'the site is under version control', 'keel-defaults' ),
	);
	if ( 'blocked' === $selection ) {
		$notes = array();
		foreach ( $state['blockers'] as $blocker ) {
			$code    = (string) $blocker['code'];
			$notes[] = ( isset( $reasons[ $code ] ) ? esc_html( $reasons[ $code ] ) . ' ' : '' ) . '(<code>' . esc_html( $code ) . '</code>)';
		}
		$rows[] = array( esc_html__( 'Next automatic install', 'keel-defaults' ), esc_html__( 'Blocked', 'keel-defaults' ), esc_html__( 'WordPress won’t install core updates on its own because', 'keel-defaults' ) . ' ' . implode( '; ', $notes ) . '.' );
	} elseif ( 'scheduled' === $selection ) {
		$rows[] = array( esc_html__( 'Next automatic install', 'keel-defaults' ), esc_html( $selected ), esc_html__( 'WordPress will try this release on its next update run.', 'keel-defaults' ) );
	} elseif ( 'none' === $selection ) {
		$rows[] = array( esc_html__( 'Next automatic install', 'keel-defaults' ), esc_html__( 'None', 'keel-defaults' ), esc_html__( 'Nothing is queued. That alone doesn’t mean the site is current; see the version status above.', 'keel-defaults' ) );
	} else {
		$rows[] = array( esc_html__( 'Next automatic install', 'keel-defaults' ), esc_html__( 'Unknown', 'keel-defaults' ), esc_html__( 'WordPress hasn’t reported which release it would install.', 'keel-defaults' ) );
	}
	if ( is_string( $tip ) && '' !== $tip && $tip !== $selected ) {
		$rows[] = array( esc_html__( 'Newer patch for this branch', 'keel-defaults' ), esc_html( $tip ), '' );
	}

	$age = $update['offer_age'];
	if ( null === $age ) {
		$rows[] = array( esc_html__( 'Last attempted check with WordPress.org', 'keel-defaults' ), esc_html__( 'Unknown', 'keel-defaults' ), '' );
	} else {
		/* translators: %s: human-readable time, such as "5 mins". */
		$ago  = sprintf( esc_html__( '%s ago', 'keel-defaults' ), esc_html( human_time_diff( $now - $age, $now ) ) );
		$note = esc_html__( 'WordPress records when it tried, not whether WordPress.org answered.', 'keel-defaults' );
		if ( $age > 86400 ) {
			$note = esc_html__( 'Over a day old, so it’s stale. WordPress normally checks twice a day.', 'keel-defaults' ) . ' ' . $note;
		}
		$rows[] = array( esc_html__( 'Last attempted check with WordPress.org', 'keel-defaults' ), $ago, $note );
	}

	if ( false === $snapshot || ! $snapshot['done'] || '' !== $snapshot['error'] ) {
		$rows[] = array( esc_html__( 'Scheduled update checks', 'keel-defaults' ), esc_html__( 'Not read yet', 'keel-defaults' ), esc_html__( 'Run Site Health to fill this in; its “Cron pressure and update delivery” check reads the schedule.', 'keel-defaults' ) );
	} else {
		$partial = $snapshot['data']['limited'] || $snapshot['data']['malformed'];
		$hooks   = array(
			'wp_version_check'     => __( 'Next update check', 'keel-defaults' ),
			'wp_maybe_auto_update' => __( 'Next automatic update run', 'keel-defaults' ),
		);
		foreach ( $hooks as $hook => $label ) {
			$at = $snapshot['data']['core'][ $hook ] ?? null;
			if ( null === $at ) {
				$status = $partial ? esc_html__( 'Unknown', 'keel-defaults' ) : esc_html__( 'Not scheduled', 'keel-defaults' );
				$note   = $partial ? esc_html__( 'Keel read only part of the schedule (partial coverage), so it may exist.', 'keel-defaults' ) : esc_html__( 'Not found when Keel last read this site’s schedule.', 'keel-defaults' );
			} else {
				$status = esc_html( gmdate( 'Y-m-d H:i', $at ) ) . ' UTC';
				$note   = '';
				if ( $at < $snapshot['checked'] ) {
					$note = esc_html__( 'Was already overdue when Keel last read the schedule.', 'keel-defaults' );
				} elseif ( $at <= $now ) {
					$note = esc_html__( 'Due now; Keel can’t see yet whether it ran.', 'keel-defaults' );
				}
			}
			$rows[] = array( esc_html( $label ), $status, $note . ( '' === $note ? '' : ' ' ) . '<code>' . esc_html( $hook ) . '</code>' );
		}
	}

	if ( $external ) {
		$rows[] = array( esc_html__( 'WP-Cron', 'keel-defaults' ), esc_html__( 'Turned off', 'keel-defaults' ), esc_html__( 'DISABLE_WP_CRON is set, so a server task (an external scheduler) has to run scheduled jobs. Keel can’t tell whether it does.', 'keel-defaults' ) );
	}

	$failure = $update['failure'];
	if ( null !== $failure ) {
		$when   = null === $failure['at'] ? esc_html__( 'time unknown', 'keel-defaults' ) : esc_html( gmdate( 'Y-m-d H:i', $failure['at'] ) ) . ' UTC';
		$rows[] = array( esc_html__( 'Last failed core update', 'keel-defaults' ), '<code>' . esc_html( $failure['code'] ) . '</code>', $when . '. ' . esc_html__( 'This may be from an earlier attempt, not the release above.', 'keel-defaults' ) );
	}

	return '<div class="keel-defaults-update-timeline"><h3>' . esc_html__( 'Automatic core updates', 'keel-defaults' ) . '</h3>'
		. keel_defaults_update_table_markup( array( esc_html__( 'Check', 'keel-defaults' ), esc_html__( 'Status', 'keel-defaults' ), esc_html__( 'Note', 'keel-defaults' ) ), $rows, array( '28%', '20%' ) )
		. '<p class="description">' . esc_html__( 'This is what WordPress knows right now. No installation time is promised. On multisite, update checks and failures are network-wide; the schedule is per site and can be up to an hour old.', 'keel-defaults' ) . '</p></div>';
}
