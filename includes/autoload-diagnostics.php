<?php
/**
 * Bounded, per-site autoload attribution. Only names and byte lengths leave SQL.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'site_status_tests', 'keel_defaults_register_autoload_test' );
add_action( 'wp_ajax_health-check-keel-autoload', 'keel_defaults_autoload_ajax' );
add_action( 'keel_defaults_autoload_collect', 'keel_defaults_autoload_collect' );
add_action( 'deactivated_plugin', 'keel_defaults_autoload_deactivated', 10, 2 );

/**
 * Register an async test, never a synchronous scan during Site Health rendering.
 *
 * @param array $tests Site Health tests.
 * @return array Registered tests.
 */
function keel_defaults_register_autoload_test( $tests ) {
	if ( is_array( $tests ) ) {
		$tests['async']['keel_autoload'] = array(
			'label'             => __( 'Largest autoloaded options', 'keel-defaults' ),
			'test'              => 'keel-autoload',
			'async_direct_test' => 'keel_defaults_autoload_health',
		);
	}
	return $tests;
}

/**
 * Atomically acquire an expiring, non-autoloaded per-site collection lock.
 *
 * @return string|false Ownership token, or false when another worker owns it.
 */
function keel_defaults_autoload_lock() {
	global $wpdb;
	$token = wp_generate_uuid4() . ':' . ( time() + 30 );
	if ( keel_defaults_autoload_claim( $token ) ) {
		return $token;
	}
	$old = keel_defaults_autoload_lease();
	if ( ! is_string( $old ) || (int) substr( $old, strrpos( $old, ':' ) + 1 ) >= time() ) {
		return false;
	}
	keel_defaults_autoload_unlock( $old );
	return keel_defaults_autoload_claim( $token ) ? $token : false;
}

/**
 * Insert only: add_option() can update an existing row during a race.
 *
 * @param string $token Lease token.
 * @return bool Whether this worker inserted the private lock.
 */
function keel_defaults_autoload_claim( $token ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insert-only atomic private lease, explicitly non-autoloaded.
	$claimed = 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'keel_autoload_scan_lock', $token ) );
	wp_cache_delete( 'keel_autoload_scan_lock', 'options' );
	return $claimed;
}

/**
 * Bypass per-request and persistent caches when checking lease ownership.
 *
 * @return string|null Current database token.
 */
function keel_defaults_autoload_lease() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership must come from the authoritative row.
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'keel_autoload_scan_lock' ) );
}

/**
 * Release only this worker's lock, including after an expired lock was reclaimed.
 *
 * @param string $token Lock ownership token.
 * @return void
 */
function keel_defaults_autoload_unlock( $token ) {
	global $wpdb;
	// Compare-and-delete avoids releasing a replacement worker's lock. This is
	// exclusively Keel's housekeeping row, never an option discovered by the scan.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic ownership check on our private lock.
	$wpdb->delete(
		$wpdb->options,
		array(
			'option_name'  => 'keel_autoload_scan_lock',
			'option_value' => $token,
		),
		array( '%s', '%s' )
	);
	wp_cache_delete( 'keel_autoload_scan_lock', 'options' );
}

/**
 * Read a snapshot only for the current site and diagnostic schema.
 *
 * @param bool $fresh Bypass request-local caches after acquiring the lease.
 * @return array|false Cached metadata, or false when unavailable.
 */
function keel_defaults_autoload_snapshot( $fresh = false ) {
	if ( $fresh && wp_using_ext_object_cache() ) {
		$state = wp_cache_get( 'keel_autoload_report', 'transient', true );
	} else {
		if ( $fresh ) {
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( '_transient_keel_autoload_report', 'options' );
			wp_cache_delete( '_transient_timeout_keel_autoload_report', 'options' );
		}
		$state = get_transient( 'keel_autoload_report' );
	}
	if ( ! is_array( $state ) || 1 !== ( $state['schema'] ?? null ) || get_current_blog_id() !== ( $state['site'] ?? null )
		|| ! is_int( $state['started'] ?? null ) || $state['started'] > time() || $state['started'] < time() - 3600 ) {
		return false;
	}
	foreach ( array( 'checked', 'cursor', 'scanned', 'autoloaded' ) as $key ) {
		if ( ! isset( $state[ $key ] ) || ! is_int( $state[ $key ] ) || $state[ $key ] < 0 ) {
			return false;
		}
	}
	if ( ! is_bool( $state['done'] ?? null ) || ! is_bool( $state['limited'] ?? null )
		|| ! is_string( $state['error'] ?? null ) || ! is_array( $state['autoload'] ?? null )
		|| ! is_array( $state['top'] ?? null ) || count( $state['top'] ) > 10
		|| $state['scanned'] > 10000 || $state['autoloaded'] > $state['scanned'] ) {
		return false;
	}
	foreach ( $state['autoload'] as $flag ) {
		if ( ! is_string( $flag ) ) {
			return false;
		}
	}
	foreach ( $state['top'] as $row ) {
		if ( ! is_array( $row ) || ! is_string( $row['name'] ?? null ) || strlen( $row['name'] ) > 764
			|| ! is_int( $row['bytes'] ?? null ) || $row['bytes'] < 0 ) {
			return false;
		}
	}
	// Show a stopped partial result even if an external scheduler never ran.
	if ( ! $state['done'] && $state['started'] < time() - 600 ) {
		$state['done']    = true;
		$state['limited'] = true;
	}
	return $state;
}

/** Schedule only one continuation; an external scheduler may run it later. */
function keel_defaults_autoload_schedule() {
	if ( ! wp_next_scheduled( 'keel_defaults_autoload_collect' ) ) {
		wp_schedule_single_event( time() + 5, 'keel_defaults_autoload_collect' );
	}
}

/**
 * Collect one primary-key page, without sorting or returning option values.
 *
 * Work is bounded to 250 processed rows per invocation, plus one lookahead,
 * 10,000 rows per scan, and a ten-minute scan lifetime. A single SQL statement
 * cannot have a portable hard execution-time limit; these are row/work bounds.
 * No request, frontend visit or activation starts a scan: Site Health requests it.
 *
 * @return void
 */
function keel_defaults_autoload_collect() {
	global $wpdb;
	$token = keel_defaults_autoload_lock();
	if ( false === $token ) {
		keel_defaults_autoload_schedule();
		return;
	}
	try {
		$state = keel_defaults_autoload_snapshot( true );
		if ( false === $state || $state['done'] ) {
			return;
		}
		if ( $state['started'] < time() - 600 ) {
			$state['done']    = true;
			$state['limited'] = true;
		} else {
			$limit = min( 250, 10000 - (int) $state['scanned'] );
			// Scan primary-key pages of ALL rows. Filtering/sorting by size or
			// autoload in SQL can examine an unbounded number of rows before LIMIT.
			// OCTET_LENGTH measures bytes, including with SQLite's WP adapter.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicitly bounded background metadata scan; result is cached below.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, autoload, OCTET_LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d", (int) $state['cursor'], $limit + 1 ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				$state['error'] = 'query_failed';
				$state['done']  = true;
			} else {
				$more = count( $rows ) > $limit;
				foreach ( array_slice( $rows, 0, $limit ) as $row ) {
					$state['cursor'] = (int) $row['option_id'];
					++$state['scanned'];
					if ( ! in_array( $row['autoload'], $state['autoload'], true ) ) {
						continue;
					}
					++$state['autoloaded'];
					$state['top'][] = array(
						'name'  => (string) $row['option_name'],
						'bytes' => max( 0, (int) $row['bytes'] ),
					);
					usort(
						$state['top'],
						static function ( $a, $b ) {
							return $b['bytes'] <=> $a['bytes'];
						}
					);
					$state['top'] = array_slice( $state['top'], 0, 10 );
				}
				$state['limited'] = $more && $state['scanned'] >= 10000;
				$state['done']    = ! $more || $state['limited'];
			}
		}
		// A slow worker whose lease was reclaimed must not overwrite newer data.
		if ( time() >= (int) substr( $token, strrpos( $token, ':' ) + 1 ) || keel_defaults_autoload_lease() !== $token ) {
			return;
		}
		$state['checked'] = time();
		set_transient( 'keel_autoload_report', $state, empty( $state['error'] ) ? 3600 : 300 );
		if ( ! $state['done'] ) {
			keel_defaults_autoload_schedule();
		}
	} finally {
		keel_defaults_autoload_unlock( $token );
	}
}

/**
 * Attribute a name with explicit evidence; prefixes are not proof of ownership.
 *
 * Transient rows are matched by their key and kind, so a transient that shares
 * a core option's name is not mistaken for that option. Keel's own names are the
 * ones uninstall.php removes; core transient names were checked against the
 * literal keys core writes.
 *
 * @param string $name Per-site option-table row name.
 * @return array Owner label, confidence and evidence (never option values).
 */
function keel_defaults_autoload_owner( $name ) {
	$kind = 'option';
	$key  = $name;
	if ( preg_match( '/^_(site_)?transient_(?:timeout_)?(.+)$/', $name, $match ) ) {
		$kind = '' === $match[1] ? 'transient' : 'site_transient';
		$key  = $match[2];
	}
	// Network transients live in sitemeta on multisite, not this options table.
	if ( 'site_transient' === $kind && is_multisite() ) {
		return array(
			'owner'      => __( 'Unknown', 'keel-defaults' ),
			'confidence' => 'unknown',
			'evidence'   => __( 'No unambiguous name-based attribution.', 'keel-defaults' ),
		);
	}
	$keel = array(
		'option'         => array( 'keel_settings', 'keel_defaults', 'keel_settings_data_version', 'keel_environment_override_host', 'keel_hibp_cache_generation', 'keel_hibp_last_success', 'keel_autoload_scan_lock' ),
		'transient'      => array( 'keel_autoload_report', 'keel_cron_report', 'keel_shortcode_residue', 'keel_policy_divergence', 'keel_hibp_unavailable' ),
		'site_transient' => array( 'keel_stable_check', 'keel_stable_check_failed' ),
	);
	if ( in_array( $key, $keel[ $kind ], true ) || ( 'option' === $kind && preg_match( '/^keel_hibp_v2_[0-9]+_(?:state|slot_(?:[0-5][0-9]|6[0-3]))$/D', $key ) ) || ( 'transient' === $kind && preg_match( '/^keel_(?:hibp_[0-9a-f]{32}_[0-9a-f]{5}|backport_install_result_[0-9]+)$/i', $key ) ) ) {
		return array(
			'owner'      => __( 'Keel', 'keel-defaults' ),
			'confidence' => 'high',
			'evidence'   => __( 'A name Keel stores and removes on uninstall; other code can still modify it.', 'keel-defaults' ),
		);
	}
	global $wpdb;
	// Core names the roles option after this site's table prefix (blog-specific on multisite).
	$roles = ( is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? $wpdb->get_blog_prefix() : 'wp_' ) . 'user_roles';
	$core  = array(
		'option'         => array( 'siteurl', 'home', 'blogname', 'blogdescription', 'rewrite_rules', 'cron', 'active_plugins', 'sidebars_widgets', 'widget_block', $roles ),
		'transient'      => array( 'doing_cron', 'health-check-site-status-result', 'is_multi_author', 'random_seed', 'dirsize_cache', 'plugin_slugs', 'settings_errors', 'wp_core_block_css_files', 'wp_styles_for_blocks' ),
		'site_transient' => array( 'update_core', 'update_plugins', 'update_themes', 'theme_roots', 'available_translations', 'wp_plugin_dependencies_plugin_data', 'wporg_theme_feature_list' ),
	);
	if ( in_array( $key, $core[ $kind ], true ) ) {
		return array(
			'owner'      => __( 'WordPress core', 'keel-defaults' ),
			'confidence' => 'high',
			'evidence'   => 'option' === $kind ? __( 'Known core option name; other code can still modify it.', 'keel-defaults' ) : __( 'Known core transient name; other code can still modify it.', 'keel-defaults' ),
		);
	}
	if ( 'option' === $kind && preg_match( '/^theme_mods_([a-z0-9_-]{1,100})$/i', $name, $match ) ) {
		$theme = wp_get_theme( $match[1] );
		if ( $theme->exists() ) {
			return array(
				'owner'      => $theme->get( 'Name' ),
				'confidence' => 'high',
				'evidence'   => __( 'Core theme-mods naming identifies an installed theme.', 'keel-defaults' ),
			);
		}
	}
	return keel_defaults_plugin_prefix_owner( $key );
}

/**
 * Match only literal plugin-slug prefixes, without interpreting the name's kind.
 *
 * @param string $name Raw hook name or an already-normalized option key.
 * @return array Low-confidence plugin evidence or unknown attribution.
 */
function keel_defaults_plugin_prefix_owner( $name ) {
	$unknown = array(
		'owner'      => __( 'Unknown', 'keel-defaults' ),
		'confidence' => 'unknown',
		'evidence'   => __( 'No unambiguous name-based attribution.', 'keel-defaults' ),
	);
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$matches = array();
	foreach ( array_slice( get_plugins(), 0, 200, true ) as $file => $plugin ) {
		$parts  = explode( '/', $file );
		$prefix = str_replace( '-', '_', count( $parts ) > 1 ? $parts[0] : basename( $file, '.php' ) );
		if ( strlen( $prefix ) < 3 || in_array( $prefix, array( 'wordpress', 'plugin', 'theme', 'options' ), true ) ) {
			continue;
		}
		if ( 0 === strpos( $name, $prefix . '_' ) || $name === $prefix ) {
			$matches[] = $plugin['Name'];
		}
	}
	if ( 1 !== count( $matches ) ) {
		return $unknown;
	}
	return array(
		'owner'      => $matches[0],
		'confidence' => 'low',
		'evidence'   => __( 'Name matches an installed plugin slug, not a verified writer. At most 200 plugins are considered.', 'keel-defaults' ),
	);
}

/**
 * Asynchronous Site Health result. Reading a fresh report performs no scan.
 *
 * @return array Site Health result.
 */
function keel_defaults_autoload_health() {
	$result = array(
		'label'       => __( 'Largest autoloaded options', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_autoload',
	);
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		return $result;
	}
	$state = keel_defaults_autoload_snapshot();
	if ( false === $state ) {
		$token = keel_defaults_autoload_lock();
		if ( false !== $token ) {
			try {
				$state = keel_defaults_autoload_snapshot( true );
				if ( false === $state ) {
					$state = array(
						'schema'     => 1,
						'site'       => get_current_blog_id(),
						'started'    => time(),
						'checked'    => time(),
						'cursor'     => 0,
						'scanned'    => 0,
						'autoloaded' => 0,
						'top'        => array(),
						'autoload'   => function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' ),
						'done'       => false,
						'limited'    => false,
						'error'      => '',
					);
					set_transient( 'keel_autoload_report', $state, 3600 );
				}
			} finally {
				keel_defaults_autoload_unlock( $token );
			}
		}
	}
	if ( false === $state ) {
		$result['description'] = '<p>' . esc_html__( 'Another collector is starting. Recheck Site Health shortly.', 'keel-defaults' ) . '</p>';
		return $result;
	}
	if ( ! $state['done'] ) {
		keel_defaults_autoload_schedule();
		$result['label'] = __( 'Autoload attribution is being collected', 'keel-defaults' );
	} elseif ( ! empty( $state['error'] ) ) {
		$result['label']  = __( 'Autoload attribution could not be collected', 'keel-defaults' );
		$result['status'] = 'recommended';
	} elseif ( $state['limited'] ) {
		$result['label'] = __( 'Autoload attribution is partial', 'keel-defaults' );
	}
	$result['description']  = '<p>' . esc_html__( 'Metadata only: option names and byte sizes, never values. Size alone does not prove waste. These are the largest observed entries, not a list of options safe to delete.', 'keel-defaults' ) . '</p>';
	$result['description'] .= '<p>' . sprintf(
		/* translators: 1: option rows examined, 2: autoloaded rows seen, 3: snapshot timestamp in UTC. */
		esc_html__( 'Examined %1$d option rows; observed %2$d autoloaded options. Snapshot: %3$s UTC.', 'keel-defaults' ),
		(int) $state['scanned'],
		(int) $state['autoloaded'],
		esc_html( gmdate( 'Y-m-d H:i:s', (int) $state['checked'] ) )
	) . '</p>';
	if ( ! $state['done'] || $state['limited'] ) {
		$result['description'] .= '<p>' . esc_html__( 'This is a partial scan, not a whole-database ranking. Each job processes up to 250 rows plus one lookahead; a scan stops at 10,000 rows or ten minutes. Background scheduling may delay collection; a pending scan does not prove cron is broken. Recheck Site Health for progress.', 'keel-defaults' ) . '</p>';
	}
	if ( ! empty( $state['error'] ) ) {
		$result['description'] .= '<p>' . esc_html__( 'The metadata query failed. No option values were changed. Try again after the five-minute error cache expires.', 'keel-defaults' ) . '</p>';
	}
	if ( $state['top'] ) {
		$result['description'] .= '<table class="widefat striped" style="table-layout:fixed"><caption>' . esc_html__( 'Largest observed autoloaded options', 'keel-defaults' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Option', 'keel-defaults' ), __( 'Bytes', 'keel-defaults' ), __( 'Probable owner', 'keel-defaults' ), __( 'Confidence and evidence', 'keel-defaults' ) ) as $heading ) {
			$result['description'] .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$result['description'] .= '</tr></thead><tbody>';
		foreach ( array_slice( $state['top'], 0, 10 ) as $row ) {
			$owner                  = keel_defaults_autoload_owner( $row['name'] );
			$confidence             = array(
				'high'    => __( 'High', 'keel-defaults' ),
				'low'     => __( 'Low', 'keel-defaults' ),
				'unknown' => __( 'Unknown', 'keel-defaults' ),
			);
			$result['description'] .= '<tr><td style="overflow-wrap:anywhere"><code>' . esc_html( $row['name'] ) . '</code></td><td>' . (int) $row['bytes'] . '</td><td style="overflow-wrap:anywhere">' . esc_html( $owner['owner'] ) . '</td><td>' . esc_html( $confidence[ $owner['confidence'] ] ) . ': ' . esc_html( $owner['evidence'] ) . '</td></tr>';
		}
		$result['description'] .= '</tbody></table>';
	}
	$result['actions'] = '<p>' . esc_html__( 'Review the probable component’s settings or ask its maintainer before changing autoload behavior. Confirm ownership and purpose first. This report never deletes options or changes their autoload flags. Completed snapshots are cached for up to one hour; collection is not transactional, so concurrent writes can change the result.', 'keel-defaults' ) . '</p>';
	return $result;
}

/** Authenticated Site Health AJAX endpoint. */
function keel_defaults_autoload_ajax() {
	check_ajax_referer( 'health-check-site-status' );
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error( array(), 403 );
		return;
	}
	wp_send_json_success( keel_defaults_autoload_health() );
}

/**
 * Remove pending collection when this plugin is deactivated, including a network.
 *
 * @param string $plugin Plugin file being deactivated.
 * @param bool   $network_wide Whether the deactivation covers the network.
 * @return void
 */
function keel_defaults_autoload_deactivated( $plugin, $network_wide ) {
	if ( plugin_basename( KEEL_DEFAULTS_FILE ) !== $plugin ) {
		return;
	}
	if ( $network_wide && is_multisite() ) {
		$page = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $page * 100,
				)
			);
			++$page;
			$found = count( $sites );
			foreach ( $sites as $site ) {
				switch_to_blog( $site );
				wp_clear_scheduled_hook( 'keel_defaults_cron_collect' );
				delete_transient( 'keel_cron_report' );
				wp_clear_scheduled_hook( 'keel_defaults_autoload_collect' );
				delete_transient( 'keel_autoload_report' );
				delete_option( 'keel_autoload_scan_lock' );
				restore_current_blog();
			}
		} while ( 100 === $found );
	} else {
		wp_clear_scheduled_hook( 'keel_defaults_cron_collect' );
				delete_transient( 'keel_cron_report' );
				wp_clear_scheduled_hook( 'keel_defaults_autoload_collect' );
		delete_transient( 'keel_autoload_report' );
		delete_option( 'keel_autoload_scan_lock' );
	}
}
