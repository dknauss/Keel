<?php
/**
 * Which other active plugins are contesting the same settings.
 *
 * Two plugins that both set a session length do not error, do not log, and do
 * not look wrong. WordPress runs both filters and keeps whichever answered
 * last, so the losing plugin's settings screen goes on displaying a value the
 * site does not use. Nothing here changes that outcome — WordPress decides it —
 * this only makes it visible.
 *
 * Detection lives in its own file because it has two consumers now: the Site
 * Health report in site-health.php, and the admin notices in admin-ux.php. It
 * belongs to neither.
 *
 * The hook map is the only part needing human judgement, and it stays short on
 * purpose. A check that fires on every well-behaved plugin is one people learn
 * to ignore, so a hook earns an entry only when contention on it has a
 * consequence somebody can act on. A hook Keel touches but nobody can lose on
 * is deliberately absent rather than marked harmless.
 *
 * @package Keel
 */

// Bail if called directly.
defined( 'ABSPATH' ) || exit;

/**
 * The hooks Keel sets policy through, and whether their governed effect can be
 * evaluated safely. Registration alone is never a collision: every WordPress
 * filter callback runs in order and core examines the final value afterwards.
 * A mapped hook is either safely `probe`able, deliberately `unconfirmed`, or
 * structurally `additive` and omitted from overlap reporting.
 *
 * A hook earns an entry only when losing on it has a consequence somebody can
 * act on. `the_generator` is the shape of thing left out: two plugins both
 * emptying it reach the same place, so naming a winner would be noise. An
 * unmapped hook is not a gap.
 *
 * @return array<string, string>
 */
function keel_defaults_policy_hooks() {
	return apply_filters(
		'keel_policy_hooks',
		array(
			// Sessions, login and REST access.
			'auth_cookie_expiration'                => 'probe',
			'login_headerurl'                       => 'probe',
			// Authentication results are compositional and unsafe to replay with
			// a synthetic request on an admin page.
			'rest_authentication_errors'            => 'additive',
			'wp_is_application_passwords_available' => 'probe',

			// The editor. Contested by every plugin that forces the classic
			// one, which is why Keel's own force-classic default is opt-in.
			'use_block_editor_for_post'             => 'probe',
			'use_block_editor_for_post_type'        => 'probe',
			'gutenberg_can_edit_post'               => 'probe',
			'use_widgets_block_editor'              => 'probe',
			'allowed_block_types_all'               => 'probe',

			// Comments and pingbacks, contested by any disable-comments plugin.
			'comments_open'                         => 'probe',
			'pings_open'                            => 'probe',
			'get_comments_number'                   => 'probe',

			// Admin surface.
			'show_admin_bar'                        => 'probe',
			'wp_supports_ai'                        => 'probe',
			'wp_revisions_to_keep'                  => 'probe',

			// Both are final-value filters, but callbacks may send/log mail or run
			// database queries when replayed. Presence is informational only.
			'pre_wp_mail'                           => 'unconfirmed',
			'comments_pre_query'                    => 'unconfirmed',

			/*
			 * Structurally additive — callbacks add keys to an array rather
			 * than replacing a value — but the keys collide. Keel takes
			 * `unfiltered_html` away here, and a plugin that grants it back
			 * writes the same key, where the last writer wins exactly as it
			 * does on a filter returning a scalar.
			 */
			'user_has_cap'                          => 'unconfirmed',

			// Shared without harm; listed so the decision is recorded rather
			// than looking like an omission.
			'wp_headers'                            => 'additive',
			'rest_endpoints'                        => 'additive',
			'heartbeat_settings'                    => 'additive',
			'sanitize_file_name'                    => 'additive',
		)
	);
}

/**
 * Resolve a registered callback to the plugin directory it lives in.
 *
 * Reflection is what makes this general: it answers "which file is this code
 * in" for any callable, so a plugin nobody has heard of is attributed exactly
 * like a known one. No list of rival plugins to maintain — such a list only
 * ever knows yesterday's plugins.
 *
 * @param mixed $callback Registered callback.
 * @return string Plugin directory name, or '' when it cannot be attributed.
 */
function keel_defaults_callback_plugin_dir( $callback ) {
	$file = keel_defaults_callback_file( $callback );

	if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
		return '';
	}

	$real_file = realpath( $file );
	$file      = false !== $real_file ? $real_file : $file;
	$file      = wp_normalize_path( $file );

	foreach ( keel_defaults_active_plugin_roots() as $slug => $root ) {
		if ( $file === $root || 0 === strpos( $file, trailingslashit( $root ) ) ) {
			return $slug;
		}
	}

	// Runtime attribution must still work during activation and in test
	// harnesses whose active-plugin option is intentionally incomplete.
	$real_plugin_dir = realpath( WP_PLUGIN_DIR );
	$base            = trailingslashit( wp_normalize_path( false !== $real_plugin_dir ? $real_plugin_dir : WP_PLUGIN_DIR ) );
	if ( 0 !== strpos( $file, $base ) ) {
		return '';
	}

	$parts = explode( '/', substr( $file, strlen( $base ) ) );

	return isset( $parts[0] ) ? $parts[0] : '';
}

/**
 * Resolve every callable form WordPress accepts to its source file.
 *
 * @param mixed $callback Callback.
 * @return string Source file, or an empty string when unavailable.
 */
function keel_defaults_callback_file( $callback ) {
	$file = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$reflection = new ReflectionFunction( $callback );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			list( $class, $method ) = explode( '::', $callback, 2 );
			$reflection             = new ReflectionMethod( $class, $method );
			$file                   = (string) $reflection->getFileName();
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, (string) $callback[1] );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_object( $callback ) && is_callable( $callback ) ) {
			$reflection = new ReflectionMethod( $callback, '__invoke' );
			$file       = (string) $reflection->getFileName();
		}
	} catch ( Throwable $e ) {
		return '';
	}

	return $file;
}

/**
 * Canonical roots for active, network-active, symlinked, and single-file plugins.
 *
 * @return array<string,string> Display slug => normalized real path.
 */
function keel_defaults_active_plugin_roots() {
	$plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() && function_exists( 'get_site_option' ) ) {
		$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$roots = array();
	foreach ( array_unique( array_map( 'strval', $plugins ) ) as $plugin ) {
		$absolute       = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $plugin, '/' );
		$slug           = false !== strpos( $plugin, '/' ) ? strtok( $plugin, '/' ) : $plugin;
		$root           = false !== strpos( $plugin, '/' ) ? dirname( $absolute ) : $absolute;
		$real_root      = realpath( $root );
		$root           = false !== $real_root ? $real_root : $root;
		$roots[ $slug ] = wp_normalize_path( $root );
	}

	return $roots;
}

/**
 * Other plugins competing for the policies Keel sets.
 *
 * Compatibility is decided by replaying attributable callbacks on an isolated
 * clone of the live hook. Only a reproduced change to the governed effect is
 * actionable; compatible and unconfirmed overlaps remain informational.
 *
 * @return array<string, string[]> Hook => plugin directory names.
 */
function keel_defaults_competing_plugins() {
	$report = keel_defaults_policy_overlap_report();

	return $report['confirmed'];
}

/**
 * Stable identity for a callable within one request.
 *
 * @param mixed $callback Callback.
 * @return string
 */
function keel_defaults_callable_id( $callback ) {
	if ( is_string( $callback ) ) {
		return 'string:' . strtolower( $callback );
	}
	if ( $callback instanceof Closure ) {
		return 'closure:' . spl_object_hash( $callback );
	}
	if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
		$owner = is_object( $callback[0] ) ? 'object:' . spl_object_hash( $callback[0] ) : 'class:' . strtolower( (string) $callback[0] );
		return $owner . '::' . strtolower( (string) $callback[1] );
	}
	if ( is_object( $callback ) ) {
		return 'invokable:' . spl_object_hash( $callback );
	}

	return '';
}

/**
 * Safe representative inputs and governed projections for effect probes.
 *
 * A missing specification means callbacks are not replayed. That overlap is
 * informational, never actionable.
 *
 * @param string $hook Hook name.
 * @return array|null
 */
function keel_defaults_policy_probe_spec( $hook ) {
	$scalar = static function ( $value ) {
		$encoded = wp_json_encode( $value );

		return gettype( $value ) . ':' . ( false !== $encoded ? $encoded : '' );
	};

	$specs = array(
		'auth_cookie_expiration'                => array(
			'value'   => 14 * DAY_IN_SECONDS,
			'args'    => array( 1, true ),
			'project' => $scalar,
		),
		'login_headerurl'                       => array(
			'value'   => 'https://wordpress.org/',
			'args'    => array(),
			'project' => $scalar,
		),
		'wp_is_application_passwords_available' => array(
			'value'   => true,
			'args'    => array(),
			'project' => $scalar,
		),
		'use_block_editor_for_post'             => array(
			'value'   => true,
			'args'    => array( null ),
			'project' => $scalar,
		),
		'use_block_editor_for_post_type'        => array(
			'value'   => true,
			'args'    => array( 'post' ),
			'project' => $scalar,
		),
		'gutenberg_can_edit_post'               => array(
			'value'   => true,
			'args'    => array( null ),
			'project' => $scalar,
		),
		'use_widgets_block_editor'              => array(
			'value'   => true,
			'args'    => array(),
			'project' => $scalar,
		),
		'comments_open'                         => array(
			'value'   => true,
			'args'    => array( 1 ),
			'project' => $scalar,
		),
		'pings_open'                            => array(
			'value'   => true,
			'args'    => array( 1 ),
			'project' => $scalar,
		),
		'get_comments_number'                   => array(
			'value'   => 7,
			'args'    => array( 1 ),
			'project' => $scalar,
		),
		'show_admin_bar'                        => array(
			'value'   => true,
			'args'    => array(),
			'project' => $scalar,
		),
		'wp_supports_ai'                        => array(
			'value'   => true,
			'args'    => array(),
			'project' => $scalar,
		),
	);

	if ( 'allowed_block_types_all' === $hook ) {
		$specs[ $hook ] = array(
			'value'   => true,
			'args'    => array( null ),
			'project' => static function ( $value ) {
				if ( false === $value ) {
					return 'comments-blocked';
				}
				if ( ! is_array( $value ) ) {
					return 'comments-allowed';
				}
				return empty( array_intersect( $value, keel_defaults_comment_blocks() ) ) ? 'comments-blocked' : 'comments-allowed';
			},
		);
	}

	if ( 'wp_revisions_to_keep' === $hook || preg_match( '/^wp_.+_revisions_to_keep$/', $hook ) ) {
		$post = function_exists( 'get_post' ) ? get_post( 1 ) : null;
		if ( ! is_object( $post ) ) {
			return null;
		}
		$specs[ $hook ] = array(
			'value'   => -1,
			'args'    => array( $post ),
			'project' => $scalar,
		);
	}

	return isset( $specs[ $hook ] ) ? $specs[ $hook ] : null;
}

/**
 * Execute a filtered clone through WordPress's real filter API.
 *
 * @param string   $hook       Hook name.
 * @param object   $original   Live WP_Hook-compatible object.
 * @param callable $keep       Whether a registered callback stays in the clone.
 * @param array    $spec       Probe specification.
 * @return array{ok:bool,value:mixed}
 */
function keel_defaults_run_policy_probe( $hook, $original, $keep, $spec ) {
	global $wp_filter;

	if ( ! is_object( $original ) || ! isset( $original->callbacks ) || ! function_exists( 'apply_filters' ) ) {
		return array(
			'ok'    => false,
			'value' => null,
		);
	}

	$clone = clone $original;
	if ( ! method_exists( $clone, 'remove_filter' ) ) {
		return array(
			'ok'    => false,
			'value' => null,
		);
	}

	foreach ( $clone->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( empty( $registered['function'] ) || ! call_user_func( $keep, $registered['function'], (int) $priority, $registered ) ) {
				/*
				 * WP_Hook keeps a protected priority index alongside callbacks.
				 * Its API updates both; unsetting callbacks directly leaves stale
				 * priorities and makes apply_filters() read missing array keys.
				 */
				$clone->remove_filter( $hook, $registered['function'], (int) $priority );
			}
		}
	}

	$had = array_key_exists( $hook, $wp_filter );
	$old = $had ? $wp_filter[ $hook ] : null;

	try {
		// The isolated clone is installed only for the duration of this probe.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_filter[ $hook ] = $clone;
		$args               = array_merge( array( $hook, $spec['value'] ), $spec['args'] );
		$value              = call_user_func_array( 'apply_filters', $args );
	} catch ( Throwable $e ) {
		return array(
			'ok'    => false,
			'value' => null,
		);
	} finally {
		if ( $had ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter[ $hook ] = $old;
		} else {
			unset( $wp_filter[ $hook ] );
		}
	}

	return array(
		'ok'    => true,
		'value' => $value,
	);
}

/**
 * Confirmed, compatible, and unconfirmed policy overlaps.
 *
 * @return array<string,array<string,string[]>> State => hook => plugin labels.
 */
function keel_defaults_policy_overlap_report() {
	global $wp_filter;

	$report = array(
		'confirmed'   => array(),
		'compatible'  => array(),
		'unconfirmed' => array(),
	);
	$mine   = keel_defaults_registered_policy_hooks();
	$hooks  = keel_defaults_policy_hooks();

	// A post-type-specific revision filter runs after the general filter and can
	// override it. Discover every live one rather than naming only posts/pages.
	if ( isset( $mine['wp_revisions_to_keep'] ) ) {
		foreach ( array_keys( (array) $wp_filter ) as $live_hook ) {
			if ( preg_match( '/^wp_.+_revisions_to_keep$/', (string) $live_hook ) ) {
				$hooks[ $live_hook ] = 'probe';
			}
		}
	}

	foreach ( $hooks as $hook => $kind ) {
		if ( 'additive' === $kind || empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		$is_dynamic_revision = ( 'wp_revisions_to_keep' !== $hook && preg_match( '/^wp_.+_revisions_to_keep$/', $hook ) );
		$mine_for_hook       = isset( $mine[ $hook ] ) ? $mine[ $hook ] : array();
		$keel_present        = ! empty( $mine_for_hook ) || ( $is_dynamic_revision && isset( $mine['wp_revisions_to_keep'] ) );
		if ( ! $keel_present ) {
			continue;
		}

		$mine_ids        = array();
		$mine_signatures = array();
		foreach ( $mine_for_hook as $record ) {
			$mine_ids[ keel_defaults_callable_id( $record['callback'] ) ] = true;
			$mine_signatures[ (int) $record['priority'] . '|' . keel_defaults_callable_id( $record['callback'] ) . '|' . (int) $record['accepted_args'] ] = true;
		}

		$rivals       = array();
		$unattributed = false;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( empty( $registered['function'] ) ) {
					continue;
				}
				$id        = keel_defaults_callable_id( $registered['function'] );
				$signature = (int) $priority . '|' . $id . '|' . (int) ( isset( $registered['accepted_args'] ) ? $registered['accepted_args'] : 1 );
				if ( isset( $mine_signatures[ $signature ] ) ) {
					continue;
				}

				$plugin = keel_defaults_callback_plugin_dir( $registered['function'] );
				if ( '' !== $plugin ) {
					$rivals[ $plugin ] = true;
				} else {
					$file    = wp_normalize_path( keel_defaults_callback_file( $registered['function'] ) );
					$core    = trailingslashit( wp_normalize_path( ABSPATH ) );
					$wpinc   = defined( 'WPINC' ) ? trailingslashit( $core . WPINC ) : '';
					$wpadmin = $core . 'wp-admin/';
					$helper  = is_string( $registered['function'] ) && 0 === strpos( $registered['function'], '__return_' );

					// Named core callbacks are known platform behavior, not a rival.
					// Core helpers remain unconfirmed because a plugin may have
					// registered the shared callable and left no ownership trace.
					$is_named_core = ! $helper && '' !== $file && ( ( '' !== $wpinc && 0 === strpos( $file, $wpinc ) ) || 0 === strpos( $file, $wpadmin ) );
					if ( ! $is_named_core ) {
						$unattributed = true;
					}
				}
			}
		}

		if ( $unattributed ) {
			$report['unconfirmed'][ $hook ][] = __( 'Unattributed callback', 'keel-defaults' );
		}

		foreach ( array_keys( $rivals ) as $plugin ) {
			if ( 'probe' !== $kind ) {
				$report['unconfirmed'][ $hook ][] = $plugin;
				continue;
			}

			$spec = keel_defaults_policy_probe_spec( $hook );
			if ( null === $spec ) {
				$report['unconfirmed'][ $hook ][] = $plugin;
				continue;
			}

			$expected = $is_dynamic_revision
				? array(
					'ok'    => true,
					'value' => (int) keel_defaults_get( 'post_revisions_limit' ),
				)
				: keel_defaults_run_policy_probe(
					$hook,
					$wp_filter[ $hook ],
					static function ( $callback, $priority, $registered ) use ( $mine_signatures ) {
						$signature = (int) $priority . '|' . keel_defaults_callable_id( $callback ) . '|' . (int) ( isset( $registered['accepted_args'] ) ? $registered['accepted_args'] : 1 );
						return isset( $mine_signatures[ $signature ] );
					},
					$spec
				);

			$combined_spec = $is_dynamic_revision ? array_merge( $spec, array( 'value' => $expected['value'] ) ) : $spec;
			$combined      = keel_defaults_run_policy_probe(
				$hook,
				$wp_filter[ $hook ],
				static function ( $callback, $priority, $registered ) use ( $mine_signatures, $plugin ) {
					$signature = (int) $priority . '|' . keel_defaults_callable_id( $callback ) . '|' . (int) ( isset( $registered['accepted_args'] ) ? $registered['accepted_args'] : 1 );
						return isset( $mine_signatures[ $signature ] ) || keel_defaults_callback_plugin_dir( $callback ) === $plugin;
				},
				$combined_spec
			);

			if ( ! $expected['ok'] || ! $combined['ok'] ) {
				$state = 'unconfirmed';
			} else {
				$project = $spec['project'];
				$state   = call_user_func( $project, $expected['value'] ) === call_user_func( $project, $combined['value'] ) ? 'compatible' : 'confirmed';
			}
			$report[ $state ][ $hook ][] = $plugin;
		}
	}

	foreach ( $report as $state => $by_hook ) {
		foreach ( $by_hook as $hook => $plugins ) {
			$report[ $state ][ $hook ] = array_values( array_unique( $plugins ) );
		}
	}

	return $report;
}

/**
 * Register one of Keel's own callbacks on a contested hook, and remember it.
 *
 * Presence has to be asked of Keel, not inferred from the filter registry. Ten
 * of the mapped hooks are registered with a *core* callback — `__return_false`
 * on the editor and comment filters, `__return_zero` on the comment count,
 * `home_url` on the login logo link — and a core callback resolves to core, so
 * nothing can tell Keel's `__return_false` from another plugin's. Left to
 * reflection, Keel never looked present on those hooks and the check quietly
 * covered none of them.
 *
 * So this is the seam: everything Keel registers on a hook in
 * `keel_defaults_policy_hooks()` goes through here, and the map and this list
 * are asserted against each other in both directions. Reflection still does the
 * other half of the job, identifying *rivals*, where a plugin's own named
 * callback resolves to its own directory.
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of arguments.
 * @param string   $setting       Schema key whose outcome this callback governs.
 * @return void
 */
function keel_defaults_add_policy_filter( $hook, $callback, $priority = 10, $accepted_args = 1, $setting = '' ) {
	if ( '' === $setting ) {
		$setting = keel_defaults_policy_setting_for_hook( $hook );
	}
	keel_defaults_registered_policy_hooks( $hook, $callback, $priority, $accepted_args, $setting );
	add_filter( $hook, $callback, $priority, $accepted_args );
}

/**
 * Governing schema key for a mapped policy hook.
 *
 * @param string $hook Hook name.
 * @return string
 */
function keel_defaults_policy_setting_for_hook( $hook ) {
	$settings = array(
		'auth_cookie_expiration'                => 'session_regular_days',
		'login_headerurl'                       => 'login_logo_behavior',
		'rest_authentication_errors'            => 'disable_rest',
		'wp_is_application_passwords_available' => 'disable_application_passwords',
		'use_block_editor_for_post'             => 'force_classic_editor',
		'use_block_editor_for_post_type'        => 'force_classic_editor',
		'gutenberg_can_edit_post'               => 'force_classic_editor',
		'use_widgets_block_editor'              => 'force_classic_editor',
		'allowed_block_types_all'               => 'disable_comments',
		'comments_open'                         => 'disable_comments',
		'pings_open'                            => 'disable_comments',
		'get_comments_number'                   => 'disable_comments',
		'show_admin_bar'                        => 'frontend_admin_bar_behavior',
		'wp_supports_ai'                        => 'disable_ai_connectors',
		'wp_revisions_to_keep'                  => 'post_revisions_limit',
		'pre_wp_mail'                           => 'suppress_nonproduction_mail',
		'comments_pre_query'                    => 'disable_comments',
		'user_has_cap'                          => 'limit_unfiltered_html_to_admins',
	);

	return isset( $settings[ $hook ] ) ? $settings[ $hook ] : '';
}


/**
 * The contested hooks Keel has registered something on this request.
 *
 * Static rather than an option: it describes what this page load wired up, which
 * depends on the settings, the environment and the screen. Storing it would only
 * create something to go stale.
 *
 * @param string   $add           Hook to record. Omit to read the list.
 * @param callable $callback      Registered callback.
 * @param int      $priority      Registration priority.
 * @param int      $accepted_args Accepted argument count.
 * @param string   $setting       Governing schema key.
 * @return array<string, array<int, array<string,mixed>>> Hook registration records.
 */
function keel_defaults_registered_policy_hooks( $add = '', $callback = null, $priority = 10, $accepted_args = 1, $setting = '' ) {
	static $registered = array();

	if ( '' !== $add ) {
		if ( ! isset( $registered[ $add ] ) ) {
			$registered[ $add ] = array();
		}
		$registered[ $add ][] = array(
			'callback'      => $callback,
			'priority'      => (int) $priority,
			'accepted_args' => (int) $accepted_args,
			'setting'       => (string) $setting,
		);
	}

	return $registered;
}

/**
 * A stable identity for a set of conflicts.
 *
 * Dismissing the dashboard notice has to mean "I have seen these", not "never
 * tell me again": a second competing plugin activated next month is new
 * information, and somebody who dismissed the first notice has not seen it. So
 * what is stored is a fingerprint of the conflicts rather than a boolean, and
 * the notice returns whenever the set changes.
 *
 * Sorted on both axes before hashing. The registry hands hooks back in
 * registration order, which changes when any plugin on the site is activated,
 * deactivated or reordered — an unsorted hash would treat that as a new
 * conflict and bring the notice back for no reason.
 *
 * @param array<string, string[]> $conflicts Hook => plugin directory names.
 * @return string sha1 hash, or '' when there is nothing to fingerprint.
 */
function keel_defaults_conflicts_fingerprint( $conflicts ) {
	if ( empty( $conflicts ) ) {
		return '';
	}

	ksort( $conflicts );

	foreach ( $conflicts as $hook => $plugins ) {
		sort( $plugins );
		$conflicts[ $hook ] = $plugins;
	}

	return sha1( (string) wp_json_encode( $conflicts ) );
}

/**
 * Where the conflict notice is shown, and whether it can be dismissed there.
 *
 * The plugins screen is where somebody lands the moment they activate a
 * competing plugin, and Keel's settings screen is where they would go to do
 * something about it. On both, the notice is the point of the visit rather than
 * an interruption, so there is nothing to dismiss — hiding it there would hide
 * the reason for being there.
 *
 * The dashboard is daily-driver space, where a banner that cannot be dismissed
 * is an obstruction rather than information.
 *
 * @return array<string, bool> Screen id => whether dismissal is offered.
 */
function keel_defaults_conflict_notice_screens() {
	return apply_filters(
		'keel_conflict_notice_screens',
		array(
			'plugins'            => false,
			'settings_page_keel' => false,
			'dashboard'          => true,
		)
	);
}

/**
 * Print the notice, if this screen is one of the three and there is a conflict.
 *
 * @return void
 */
function keel_defaults_render_conflicts_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen  = get_current_screen();
	$screens = keel_defaults_conflict_notice_screens();
	$id      = is_object( $screen ) && isset( $screen->id ) ? $screen->id : '';

	if ( ! isset( $screens[ $id ] ) ) {
		return;
	}

	$conflicts = keel_defaults_competing_plugins();
	if ( empty( $conflicts ) ) {
		return;
	}

	$dismissible = $screens[ $id ];
	$fingerprint = keel_defaults_conflicts_fingerprint( $conflicts );

	if ( $dismissible && get_user_meta( get_current_user_id(), 'keel_conflicts_dismissed', true ) === $fingerprint ) {
		return;
	}

	$plugins = array();
	foreach ( $conflicts as $hook_plugins ) {
		foreach ( $hook_plugins as $plugin ) {
			$plugins[ $plugin ] = true;
		}
	}

	$health = admin_url( 'site-health.php' );
	?>
	<div class="notice notice-warning">
		<p>
			<strong>
			<?php
			/* translators: %s: comma-separated plugin directory names. */
			$heading = _n(
				'Another plugin controls some of the same settings as Keel: %s',
				'Other plugins control some of the same settings as Keel: %s',
				count( $plugins ),
				'keel-defaults'
			);

			printf(
				esc_html( $heading ),
				esc_html( implode( ', ', array_keys( $plugins ) ) )
			);
			?>
			</strong>
		</p>
		<p>
			<?php esc_html_e( 'A safe effect probe reproduced a different final value when these plugins ran together. Their settings screens can therefore disagree with the behavior WordPress actually uses.', 'keel-defaults' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( $health ); ?>"><?php esc_html_e( 'See which settings are contested, under Site Health', 'keel-defaults' ); ?></a>
			<?php if ( $dismissible ) : ?>
				&nbsp;|&nbsp;
				<a class="keel-dismiss-conflicts" href="<?php echo esc_url( wp_nonce_url( admin_url( 'index.php?keel-dismiss-conflicts=1' ), 'keel-dismiss-conflicts' ) ); ?>">
					<?php esc_html_e( 'Dismiss until this changes', 'keel-defaults' ); ?>
				</a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}

/**
 * Record a dismissal.
 *
 * Stores the fingerprint of what was on screen, not a flag, so the notice comes
 * back when the conflicts change rather than staying gone forever. Per user,
 * because dismissing is one person saying they have read it.
 *
 * Handled here rather than with core's `is-dismissible` class, which only hides
 * the notice for the current page view and stores nothing.
 *
 * @return void
 */
function keel_defaults_handle_conflicts_dismissal() {
	if ( ! isset( $_GET['keel-dismiss-conflicts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked immediately below; this is the presence test that decides whether to look.
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'keel-dismiss-conflicts' );

	update_user_meta(
		get_current_user_id(),
		'keel_conflicts_dismissed',
		keel_defaults_conflicts_fingerprint( keel_defaults_competing_plugins() )
	);

	wp_safe_redirect( admin_url( 'index.php' ) );
	exit;
}
