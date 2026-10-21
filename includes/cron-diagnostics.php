<?php
/**
 * Bounded cron-pressure and update-delivery observations, never job execution.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;
add_filter( 'site_status_tests', 'keel_defaults_register_cron_test' );
add_action( 'wp_ajax_health-check-keel-cron', 'keel_defaults_cron_ajax' );
add_action( 'keel_defaults_cron_collect', 'keel_defaults_cron_collect' );

/**
 * Register an asynchronous diagnostic.
 *
 * @param array $tests Tests.
 * @return array Tests.
 */
function keel_defaults_register_cron_test( $tests ) {
	$tests['async']['keel_cron'] = array(
		'label'             => __( 'Cron pressure and update delivery', 'keel-defaults' ),
		'test'              => 'keel-cron',
		'async_direct_test' => 'keel_defaults_cron_health',
	);
	return $tests;
}

/**
 * Extract metadata only, with explicit traversal and instance limits.
 *
 * @param array $cron Decoded core cron option. Never retained.
 * @param int   $now Observation time.
 * @return array Bounded observations, never arguments or argument hashes.
 */
function keel_defaults_cron_extract( array $cron, $now ) {
	$report = array(
		'examined'  => 0,
		'limited'   => false,
		'malformed' => false,
		'overdue'   => array(),
		'frequent'  => array(),
		'core'      => array(),
	);
	$steps  = 0;
	foreach ( $cron as $at => $hooks ) {
		if ( ++$steps > 6000 ) {
			$report['limited'] = true;
			break;
		}
		if ( 'version' === $at || 'VERSION' === $at ) {
			continue;
		}
		if ( ! is_numeric( $at ) || (int) $at <= 0 || ! is_array( $hooks ) ) {
			$report['malformed'] = true;
			continue;
		}
		foreach ( $hooks as $hook => $events ) {
			if ( ++$steps > 6000 ) {
				$report['limited'] = true;
				break 2;
			}
			if ( ! is_string( $hook ) || strlen( $hook ) > 191 || ! is_array( $events ) ) {
				$report['malformed'] = true;
				continue;
			}
			foreach ( $events as $event ) {
				if ( ++$steps > 6000 || $report['examined'] >= 2000 ) {
					$report['limited'] = true;
					break 3;
				}
				if ( ! is_array( $event ) || ! array_key_exists( 'schedule', $event ) ) {
					$report['malformed'] = true;
					continue;
				}
				++$report['examined'];
				$interval = isset( $event['interval'] ) && is_numeric( $event['interval'] ) ? max( 0, (int) $event['interval'] ) : 0;
				$row      = array(
					'hook'     => $hook,
					'at'       => (int) $at,
					'delay'    => max( 0, $now - (int) $at ),
					'interval' => $interval,
					'schedule' => is_string( $event['schedule'] ) ? substr( $event['schedule'], 0, 80 ) : '',
				);
				if ( in_array( $hook, array( 'wp_version_check', 'wp_maybe_auto_update' ), true ) ) {
					$report['core'][ $hook ] = min( $report['core'][ $hook ] ?? PHP_INT_MAX, (int) $at );
				}
				if ( $row['delay'] > 900 ) {
					$report['overdue'][] = $row;
					usort(
						$report['overdue'],
						static function ( $a, $b ) {
							return $b['delay'] <=> $a['delay'];
						}
					);
					$report['overdue'] = array_slice( $report['overdue'], 0, 5 );
				}
				if ( '' !== $row['schedule'] && $interval > 0 && $interval < 300 ) {
					$report['frequent'][] = $row;
					usort(
						$report['frequent'],
						static function ( $a, $b ) {
							return $a['interval'] <=> $b['interval'];
						}
					);
					$report['frequent'] = array_slice( $report['frequent'], 0, 5 );
				}
			}
		}
	}
	return $report;
}

/**
 * Name-based attribution is evidence, never proof of the scheduling component.
 *
 * @param string $hook Hook name.
 * @return array Owner, confidence, evidence.
 */
function keel_defaults_cron_owner( $hook ) {
	if ( in_array( $hook, array( 'wp_version_check', 'wp_maybe_auto_update', 'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_delete', 'delete_expired_transients', 'wp_scheduled_auto_draft_delete', 'wp_site_health_scheduled_check', 'wp_update_user_counts', 'wp_privacy_delete_old_export_files', 'wp_privacy_personal_data_cleanup_requests', 'recovery_mode_clean_expired_keys', 'wp_delete_temp_updater_backups', 'update_network_counts', 'publish_future_post', 'do_pings', 'wp_split_shared_term_batch', 'wp_update_comment_type_batch' ), true ) ) {
		return array(
			'owner'      => __( 'WordPress core', 'keel-defaults' ),
			'confidence' => 'high',
			'evidence'   => __( 'Known core hook name; other code can also attach callbacks or schedule it.', 'keel-defaults' ),
		);
	}
	if ( in_array( $hook, array( 'keel_defaults_cron_collect', 'keel_defaults_autoload_collect' ), true ) ) {
		return array(
			'owner'      => 'Keel',
			'confidence' => 'high',
			'evidence'   => __( 'Known Keel diagnostic hook.', 'keel-defaults' ),
		);
	}
	$owner = keel_defaults_autoload_owner( $hook );
	// Reuse only plugin-prefix evidence, never core-option/theme-mods rules.
	if ( 'low' === $owner['confidence'] ) {
		return $owner;
	}
	return array(
		'owner'      => __( 'Unknown', 'keel-defaults' ),
		'confidence' => 'unknown',
		'evidence'   => __( 'No unambiguous hook-name attribution; the scheduling component is unverified.', 'keel-defaults' ),
	);
}

/**
 * Reduce network-wide core update state to non-sensitive evidence only.
 *
 * @param mixed $offers Existing core offers, without fetching.
 * @param mixed $failure Existing core failure record.
 * @param int   $now Snapshot time.
 * @return array Safe update evidence.
 */
function keel_defaults_cron_updates( $offers, $failure, $now ) {
	$checked = is_object( $offers ) && isset( $offers->last_checked ) && is_numeric( $offers->last_checked ) ? (int) $offers->last_checked : 0;
	$out     = array(
		'offer_age' => $checked > 0 && $checked <= $now ? $now - $checked : null,
		'failure'   => null,
	);
	if ( is_array( $failure ) && ! empty( $failure ) ) {
		$at             = isset( $failure['timestamp'] ) && is_numeric( $failure['timestamp'] ) ? (int) $failure['timestamp'] : 0;
		$code           = isset( $failure['error_code'] ) && is_string( $failure['error_code'] ) && preg_match( '/^[a-z0-9_-]{1,80}$/i', $failure['error_code'] ) ? $failure['error_code'] : 'unknown';
		$out['failure'] = array(
			'at'       => $at > 0 && $at <= $now ? $at : null,
			'code'     => $code,
			'critical' => ! empty( $failure['critical'] ),
		);
	}
	return $out;
}

/**
 * Read a per-site cached result, bypassing stale request-local state under lease.
 *
 * @param bool $fresh Force backend read after claiming the diagnostic lease.
 * @return array|false Snapshot.
 */
function keel_defaults_cron_snapshot( $fresh = false ) {
	if ( $fresh && wp_using_ext_object_cache() ) {
		$state = wp_cache_get( 'keel_cron_report', 'transient', true );
	} else {
		if ( $fresh ) {
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( '_transient_keel_cron_report', 'options' );
			wp_cache_delete( '_transient_timeout_keel_cron_report', 'options' );
		}
		$state = get_transient( 'keel_cron_report' );
	}
	if ( ! is_array( $state ) || 1 !== ( $state['schema'] ?? null ) || get_current_blog_id() !== ( $state['site'] ?? null )
		|| ! is_int( $state['checked'] ?? null ) || $state['checked'] > time() || $state['checked'] < time() - 3600
		|| ! is_string( $state['error'] ?? null ) || ! is_bool( $state['done'] ?? null ) ) {
		return false;
	}
	if ( $state['done'] && '' === $state['error'] && ( ! is_array( $state['data'] ?? null ) || ! is_array( $state['update'] ?? null ) ) ) {
		return false;
	}
	if ( isset( $state['data'] ) ) {
		$data = $state['data'];
		if ( ! is_array( $data ) || ! is_int( $data['examined'] ?? null ) || $data['examined'] < 0 || $data['examined'] > 2000
			|| ! is_bool( $data['limited'] ?? null ) || ! is_bool( $data['malformed'] ?? null ) || ! is_array( $data['core'] ?? null ) || count( $data['core'] ) > 2 ) {
			return false;
		}
		foreach ( $data['core'] as $hook => $at ) {
			if ( ! in_array( $hook, array( 'wp_version_check', 'wp_maybe_auto_update' ), true ) || ! is_int( $at ) || $at <= 0 ) {
				return false;
			}
		}
		foreach ( array( 'overdue', 'frequent' ) as $key ) {
			if ( ! is_array( $data[ $key ] ?? null ) || count( $data[ $key ] ) > 5 ) {
				return false;
			}
			foreach ( $data[ $key ] as $row ) {
				if ( ! is_array( $row ) || ! is_string( $row['hook'] ?? null ) || strlen( $row['hook'] ) > 191
					|| ! is_string( $row['schedule'] ?? null ) || strlen( $row['schedule'] ) > 80 ) {
					return false;
				}
				foreach ( array( 'at', 'delay', 'interval' ) as $number ) {
					if ( ! is_int( $row[ $number ] ?? null ) || $row[ $number ] < 0 ) {
						return false;
					}
				}
			}
		}
	}
	if ( isset( $state['update'] ) ) {
		$update = $state['update'];
		if ( ! is_array( $update ) || ! array_key_exists( 'offer_age', $update ) || ! array_key_exists( 'failure', $update )
			|| ( null !== $update['offer_age'] && ( ! is_int( $update['offer_age'] ) || $update['offer_age'] < 0 ) ) ) {
			return false;
		}
		if ( null !== $update['failure'] ) {
			$f = $update['failure'];
			if ( ! is_array( $f ) || ! is_string( $f['code'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,80}$/i', $f['code'] )
				|| ! array_key_exists( 'at', $f ) || ( null !== $f['at'] && ( ! is_int( $f['at'] ) || $f['at'] <= 0 ) ) ) {
				return false;
			}
		}
	}
	if ( ! $state['done'] && $state['checked'] < time() - 600 ) {
		$state['done']  = true;
		$state['error'] = 'scheduling_delayed';
	}
	return $state;
}

/** Schedule only Keel's collector; never run or reschedule any observed job. */
function keel_defaults_cron_schedule() {
	if ( ! wp_next_scheduled( 'keel_defaults_cron_collect' ) ) {
		wp_schedule_single_event( time() + 5, 'keel_defaults_cron_collect' );
	}
}

/** Background-only, size-guarded read of the serialized cron option. */
function keel_defaults_cron_collect() {
	global $wpdb;
	$token = keel_defaults_autoload_lock();
	if ( false === $token ) {
		keel_defaults_cron_schedule();
		return;
	}
	try {
		$state = keel_defaults_cron_snapshot( true );
		if ( false === $state || $state['done'] ) {
			return;
		}
		// The CASE guards the returned payload in this same statement, avoiding
		// a size-check/read race. This reads cron arguments internally, then
		// discards them; arguments never enter the report, cache or HTML.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one size-guarded primary-key read in background; cached below.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT OCTET_LENGTH(option_value) AS bytes, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS payload FROM {$wpdb->options} WHERE option_name = %s", 1048576, 'cron' ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			$state['error'] = 'query_failed';
		} elseif ( null === $row ) {
			$state['data'] = keel_defaults_cron_extract( array(), time() );
		} elseif ( ! is_array( $row ) || (int) $row['bytes'] > 1048576 ) {
			$state['error'] = 'cron_too_large';
		} else {
			// Never instantiate classes from serialized event arguments.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- core cron format, bounded payload, classes disabled.
			$cron = is_string( $row['payload'] ) ? @unserialize(
				$row['payload'],
				array(
					'allowed_classes' => false,
					'max_depth'       => 32,
				)
			) : false;
			if ( ! is_array( $cron ) ) {
				$state['error'] = 'malformed_cron';
			} else {
				$state['data'] = keel_defaults_cron_extract( $cron, time() );
			}
		}
		$state['update']   = keel_defaults_cron_updates( get_site_transient( 'update_core' ), get_site_option( 'auto_core_update_failed' ), time() );
		$state['external'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$state['checked']  = time();
		$state['done']     = true;
		if ( time() >= (int) substr( $token, strrpos( $token, ':' ) + 1 ) || keel_defaults_autoload_lease() !== $token ) {
			return;
		}
		set_transient( 'keel_cron_report', $state, '' === $state['error'] ? 3600 : 300 );
	} finally {
		keel_defaults_autoload_unlock( $token );
	}
}

/** Authenticated Site Health AJAX. */
function keel_defaults_cron_ajax() {
	check_ajax_referer( 'health-check-site-status' );
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error( array(), 403 );
		return;
	}
	wp_send_json_success( keel_defaults_cron_health() );
}

require_once __DIR__ . '/cron-presentation.php';
