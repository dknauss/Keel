<?php
/**
 * Which other active plugins are contesting the same settings.
 *
 * Two plugins registered on the same policy hook do not error or log. Their
 * callbacks may agree, may apply only in different contexts, or may produce a
 * different final value. The registry proves shared participation, not which
 * configured outcome WordPress will use. Nothing here invokes those callbacks;
 * this only makes the structural overlap visible.
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
 * The hooks Keel sets policy through, classified by what registration proves.
 * An `authoritative` hook returns one final policy value, so another plugin on
 * that hook is a structural overlap worth showing to an administrator. It does
 * not prove the plugins disagree. `unconfirmed` hooks are too compositional to
 * make even that inference, while `additive` hooks are shared without harm and
 * omitted from overlap reporting.
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
			'auth_cookie_expiration'                => 'authoritative',
			'login_headerurl'                       => 'authoritative',
			// Authentication results are compositional, so registration alone
			// does not identify a competing policy.
			'rest_authentication_errors'            => 'additive',
			'wp_is_application_passwords_available' => 'authoritative',

			// The editor. Contested by every plugin that forces the classic
			// one, which is why Keel's own force-classic default is opt-in.
			'use_block_editor_for_post'             => 'authoritative',
			'use_block_editor_for_post_type'        => 'authoritative',
			'gutenberg_can_edit_post'               => 'authoritative',
			'use_widgets_block_editor'              => 'authoritative',

			/*
			 * Subtractive, so it composes. The callback drops the comment
			 * blocks from whatever list it is handed and keeps the rest, and it
			 * registers at PHP_INT_MAX so it runs after everything registered at a
			 * lower priority, and after anything at PHP_INT_MAX that registered
			 * before it — whether another plugin
			 * passed `false`, `true` or an explicit array, Keel operates on that
			 * decision rather than discarding it. Two plugins restricting the
			 * inserter both get their way.
			 *
			 * Reported as a collision it would have sent an administrator to
			 * deactivate a plugin that works alongside this one.
			 */
			'allowed_block_types_all'               => 'additive',

			// Comments and pingbacks, contested by any disable-comments plugin.
			'comments_open'                         => 'authoritative',
			'pings_open'                            => 'authoritative',
			'get_comments_number'                   => 'authoritative',

			// Admin surface.
			'show_admin_bar'                        => 'authoritative',
			'wp_supports_ai'                        => 'authoritative',

			/*
			 * XML-RPC. Both were missed until somebody installed Disable XML-RPC
			 * next to Keel, set the two to disagree, and was told nothing.
			 *
			 * `xmlrpc_enabled` returns a bool, so it is the ordinary
			 * winner-takes-all shape. `xmlrpc_methods` is subtractive — the
			 * callback unsets the methods it objects to and returns the rest —
			 * so two plugins pruning the list both get their way, exactly like
			 * `allowed_block_types_all`.
			 */
			'xmlrpc_enabled'                        => 'authoritative',
			'xmlrpc_methods'                        => 'additive',
			'wp_revisions_to_keep'                  => 'authoritative',

			// Both are final-value filters whose callbacks may perform independent
			// work. Shared registration is informational only.
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
 * Marker for a callback no plugin can be attributed to.
 *
 * A token, not a sentence. This used to be the translated string "Unattributed
 * callback", pushed into the same array that otherwise holds plugin directory
 * names — so Site Health rendered "comments_open — callbacks from Unattributed
 * callback", which reads as the name of a plugin, and several such rows read as
 * several plugins. Reported as exactly that: the notice named one plugin while
 * Site Health appeared to name five others.
 *
 * Keeping the marker out of the display layer is what fixes it. The list stays a
 * list of plugin names, this says "and something here has no name", and the
 * wording is decided once, where the rendering is.
 *
 * Prefixed so it cannot collide with a real plugin directory.
 */
const KEEL_DEFAULTS_UNATTRIBUTED = '__keel_unattributed__';

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
 * Only structural overlaps on authoritative hooks are returned. No registered
 * callback is invoked: this check must not cause the conflict it is reporting.
 *
 * @param bool $refresh Rebuild the request-local report cache.
 * @return array<string, string[]> Hook => plugin directory names.
 */
function keel_defaults_competing_plugins( $refresh = false ) {
	$report = keel_defaults_policy_overlap_report( $refresh );

	return $report['structural'];
}

/**
 * The directory Keel itself lives in.
 *
 * Derived from this file rather than from KEEL_DEFAULTS_FILE. The constant is
 * defined by the bootstrap and not by anything that loads this file on its own,
 * and a guard that quietly does nothing when a constant is missing is worse than
 * no guard: it reads as protection. This file is inside the plugin by
 * construction, in every context that can call the function at all.
 *
 * @return string Directory name.
 */
function keel_defaults_own_plugin_dir() {
	return basename( dirname( dirname( wp_normalize_path( __FILE__ ) ) ) );
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
 * Structural and unconfirmed policy overlaps.
 *
 * The callback registry is complete before any production caller reaches this
 * function. Cache the result for the rest of that request so a notice, Site
 * Health, or dismissal fingerprint does not repeat the same reflection scan.
 * Tests that deliberately rewrite the registry can request a refresh.
 *
 * @param bool $refresh Rebuild the request-local report cache.
 * @return array<string,array<string,string[]>> State => hook => plugin labels.
 */
function keel_defaults_policy_overlap_report( $refresh = false ) {
	global $wp_filter;
	static $cached = null;

	if ( ! $refresh && is_array( $cached ) ) {
		return $cached;
	}

	$report = array(
		'structural'  => array(),
		'unconfirmed' => array(),
	);
	$mine   = keel_defaults_registered_policy_hooks();
	$hooks  = keel_defaults_policy_hooks();

	// A post-type-specific revision filter runs after the general filter and can
	// override it. Discover every live one rather than naming only posts/pages.
	if ( isset( $mine['wp_revisions_to_keep'] ) ) {
		foreach ( array_keys( (array) $wp_filter ) as $live_hook ) {
			if ( preg_match( '/^wp_.+_revisions_to_keep$/', (string) $live_hook ) ) {
				$hooks[ $live_hook ] = 'authoritative';
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

		$mine_signatures = array();
		foreach ( $mine_for_hook as $record ) {
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

				/*
				 * A plugin is never its own rival.
				 *
				 * Self-exclusion was by signature — priority, callable identity,
				 * argument count — against what Keel recorded when it registered.
				 * Anything Keel adds with a bare add_filter() is absent from that
				 * record and resolves, correctly, to the keel-defaults directory,
				 * so Keel reported itself as a plugin competing with Keel. The
				 * divergence observers did exactly this, on every hook they watch.
				 * Matching on the directory holds regardless of how a callback
				 * came to be registered, which the signature match cannot.
				 */
				if ( '' !== $plugin && keel_defaults_own_plugin_dir() === $plugin ) {
					continue;
				}

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
			$report['unconfirmed'][ $hook ][] = KEEL_DEFAULTS_UNATTRIBUTED;
		}

		foreach ( array_keys( $rivals ) as $plugin ) {
			$state                       = 'authoritative' === $kind ? 'structural' : 'unconfirmed';
			$report[ $state ][ $hook ][] = $plugin;
		}
	}

	foreach ( $report as $state => $by_hook ) {
		foreach ( $by_hook as $hook => $plugins ) {
			$report[ $state ][ $hook ] = array_values( array_unique( $plugins ) );
		}
	}

	$cached = $report;

	return $cached;
}

/*
 * ---------------------------------------------------------------------
 * Policy callbacks Keel owns
 * ---------------------------------------------------------------------
 *
 * These mirror `__return_false` and friends, and exist for one reason: identity.
 *
 * WordPress keys a hook's callbacks by callable identity, so two plugins calling
 * `add_filter( 'comments_open', '__return_false', 20 )` produce ONE entry — the
 * second registration overwrites the first under the same key. The overlap
 * report then excludes that entry as Keel's own, and the other plugin does not
 * appear as unattributable; it does not appear at all.
 *
 * That was measured, not theorised: on an install with three comment-disabling
 * plugins active, simply-disable-comments registers precisely that callable at
 * precisely that priority and was reported nowhere. disable-gutenberg was hidden
 * the same way on `use_block_editor_for_post`.
 *
 * A callable only Keel registers cannot collapse into anyone else's, so a rival
 * always keeps an entry of its own for the report to find. Swapping one of these
 * back to the core helper it mirrors re-opens the blind spot silently, which is
 * what tests/policy-callback-identity.php is there to prevent.
 *
 * Behaviour is identical to the core helpers. Only the identity differs.
 */

/**
 * Return false, under a name only Keel registers.
 *
 * @return false
 */
function keel_defaults_return_false() {
	return false;
}

/**
 * A comment count of zero, as the string core would have returned.
 *
 * The type matters and is not a detail. core's get_comments_number() returns
 * `$post->comment_count`, which arrives from the database as a string, and its
 * own docblock advertises `string|int` — so most consumers cast before using
 * it. Not all of them: wp-includes/blocks/comments-title.php compares strictly,
 *
 *     if ( '0' === $comments_count ) { return; }
 *
 * and `'0' === 0` is false. Returning an int meant the Comments Title block
 * skipped its early return and rendered a comments heading on a post Keel had
 * just reported as having none — on a block theme, which is the default. The
 * `__return_zero` this replaced had the same flaw, so it predates the wrapper.
 *
 * '0' is falsy and casts to 0, so the consumers that cast or test truthiness
 * are unaffected.
 *
 * @return string
 */
function keel_defaults_return_zero() {
	return '0';
}

/**
 * The site home, for the login screen's header link.
 *
 * Replaces `add_filter( 'login_headerurl', 'home_url' )`, which was wrong twice
 * over. Beyond the identity problem above, home_url() takes a *path* as its
 * first argument, and a filter hands its callback the value being filtered — so
 * the incoming `https://wordpress.org/` was appended to the site URL and the
 * logo linked to `https://example.com/https://wordpress.org/`. Every install
 * with the logo removed, unlinked or replaced had a broken link.
 *
 * @return string
 */
function keel_defaults_login_header_url() {
	return home_url();
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
 * Hooks carrying a callback that could not be traced to a plugin.
 *
 * Separated from the plugin names so a caller can say how many hooks are
 * affected without having to know what the marker looks like.
 *
 * @return string[] Hook names.
 */
function keel_defaults_unattributed_hooks() {
	$report = keel_defaults_policy_overlap_report();
	$hooks  = array();

	foreach ( $report['unconfirmed'] as $hook => $names ) {
		if ( in_array( KEEL_DEFAULTS_UNATTRIBUTED, $names, true ) ) {
			$hooks[] = $hook;
		}
	}

	return $hooks;
}

/**
 * Fingerprint of everything the notice reports, both halves.
 *
 * Dismissing has to mean "I have seen this", and what there is to see is the
 * attributable overlaps *and* the settings that are not taking effect. Hashing
 * only the overlaps meant a dismissal taken while two plugins were contesting a
 * hook also silenced a later, unrelated divergence — and a site with nothing but
 * divergences produced an empty fingerprint, which no dismissal could ever match.
 *
 * Divergences go in under a key no hook can occupy, so the two cannot be
 * confused for one another in the hash.
 *
 * @return string
 */
function keel_defaults_notice_fingerprint() {
	$state       = keel_defaults_competing_plugins();
	$divergences = keel_defaults_policy_divergences();

	if ( ! empty( $divergences ) ) {
		$state['keel:divergence'] = array_keys( $divergences );
	}

	$unnamed = keel_defaults_unattributed_hooks();

	if ( ! empty( $unnamed ) ) {
		$state['keel:unattributed'] = $unnamed;
	}

	return keel_defaults_conflicts_fingerprint( $state );
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

	$conflicts   = keel_defaults_competing_plugins();
	$divergences = keel_defaults_policy_divergences();
	$unnamed     = keel_defaults_unattributed_hooks();

	if ( empty( $conflicts ) && empty( $divergences ) && empty( $unnamed ) ) {
		return;
	}

	$dismissible = $screens[ $id ];
	$fingerprint = keel_defaults_notice_fingerprint();

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

	/*
	 * A divergence outranks an overlap, so it goes first and sets the notice
	 * colour. An overlap says two plugins are registered on the same hook, which
	 * may be perfectly fine; a divergence says a setting configured on this site
	 * is measurably not doing what it says. Only one of those needs acting on.
	 */
	$class = empty( $divergences ) ? 'notice-info' : 'notice-warning';
	?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
		<?php if ( ! empty( $divergences ) ) : ?>
		<p>
			<strong>
			<?php
			/* translators: %s: comma-separated setting/hook names. */
			$not_taking_effect = _n(
				'A Keel setting is not taking effect on this site: %s',
				'Some Keel settings are not taking effect on this site: %s',
				count( $divergences ),
				'keel-defaults'
			);

			printf(
				esc_html( $not_taking_effect ),
				esc_html( implode( ', ', array_keys( $divergences ) ) )
			);
			?>
			</strong>
		</p>
		<p>
			<?php esc_html_e( 'These were last seen producing a different value from the one configured here, so something else on this site is deciding them. Keel cannot say what: a plugin that switches a feature off using one of WordPress\'s own helper functions leaves nothing to trace it back by. Your active plugins are the place to look.', 'keel-defaults' ); ?>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $plugins ) ) : ?>
		<p>
			<strong>
			<?php
			/* translators: %s: comma-separated plugin directory names. */
			$heading = _n(
				'Another plugin may influence some of the same settings as Keel: %s',
				'Other plugins may influence some of the same settings as Keel: %s',
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
				<?php esc_html_e( 'These plugins are registered on authoritative policy hooks that Keel also uses. Compare their settings: this confirms an overlap, not that their configured outcomes disagree.', 'keel-defaults' ); ?>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $unnamed ) ) : ?>
		<p>
			<?php
			/* translators: %d: number of hooks. */
			$untraceable = _n(
				'%d more setting is shared with a callback that cannot be traced to any plugin, so there is no name to give you.',
				'%d more settings are shared with callbacks that cannot be traced to any plugin, so there are no names to give you.',
				count( $unnamed ),
				'keel-defaults'
			);

			printf( esc_html( $untraceable ), count( $unnamed ) );
			?>
			<?php esc_html_e( 'A plugin that switches a feature off using one of WordPress\'s own helper functions leaves nothing behind to identify it. Site Health lists which settings.', 'keel-defaults' ); ?>
		</p>
		<?php endif; ?>
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
		keel_defaults_notice_fingerprint()
	);

	wp_safe_redirect( admin_url( 'index.php' ) );
	exit;
}

/**
 * Hooks whose effective value Keel's own settings can predict.
 *
 * The overlap check names plugins. This is the other half: when a plugin
 * overrides a setting through one of WordPress's own helpers there is nobody to
 * name, because nothing records which plugin called `add_filter()`. Keel can
 * still tell that its setting is not producing the value it asks for, and saying
 * so is worth more than silence.
 *
 * Each entry maps a hook to the value Keel's configuration expects the filter to
 * settle on, or null where Keel is not asking for anything in particular — a
 * setting left at "leave unchanged" has no expectation to be disappointed.
 *
 * Deliberately short. A hook belongs here only when the answer is a boolean and
 * one setting decides it; anything needing interpretation would be a guess
 * wearing a measurement's clothes.
 *
 * @return array<string, bool|null> Hook => expected value, or null for no expectation.
 */
function keel_defaults_policy_expectations() {
	$expectations = array(
		// Remote publishing allowed means the endpoint should answer.
		'xmlrpc_enabled'                        => keel_defaults_enabled( 'xmlrpc_allow_remote_publishing' ),
		// Comments off means closed, everywhere, below the theme. pings_open is
		// the same question about the same setting — one without the other was an
		// omission, not a distinction, and it left a plugin forcing pingbacks back
		// open with nothing to report it.
		'comments_open'                         => keel_defaults_enabled( 'disable_comments' ) ? false : null,
		'pings_open'                            => keel_defaults_enabled( 'disable_comments' ) ? false : null,
		'wp_is_application_passwords_available' => keel_defaults_enabled( 'disable_application_passwords' ) ? false : null,
		'wp_supports_ai'                        => keel_defaults_key_supported( 'disable_ai_connectors' ) && keel_defaults_enabled( 'disable_ai_connectors' ) ? false : null,
	);

	/**
	 * Filters the hooks whose effective value Keel can predict.
	 *
	 * @param array<string, bool|null> $expectations Hook => expected value.
	 */
	return array_filter(
		(array) apply_filters( 'keel_policy_expectations', $expectations ),
		static function ( $expected ) {
			return null !== $expected;
		}
	);
}

/**
 * Note what a governed filter actually settled on.
 *
 * Called from an observer registered at PHP_INT_MAX, so it runs after everything
 * at a lower priority — but not necessarily after a callback that registered at
 * PHP_INT_MAX first; see keel_defaults_watch_policy_results() for why that is a
 * limit of the filter model rather than something to fix here. It reads a value
 * WordPress produced on its own; it does not call the filter, and it invokes
 * nothing. That distinction
 * is the whole design — running other people's callbacks to see what they do was
 * built, shipped and withdrawn in 0.5.1, because a check that reports collisions
 * must not cause them.
 *
 * Writes only when the answer changes. A site with nothing overriding it never
 * writes at all, and a site with a standing override writes once.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value The value the filter chain settled on.
 * @return mixed The value, untouched.
 */
function keel_defaults_observe_policy_result( $hook, $value ) {
	$expectations = keel_defaults_policy_expectations();

	if ( ! array_key_exists( $hook, $expectations ) ) {
		return $value;
	}

	/*
	 * The comparison happens before any storage is touched, and a site where
	 * everything is working never touches it at all.
	 *
	 * This runs on ordinary front-end requests, so the cost of the ordinary case
	 * is the cost of the feature. Reading first — to find out whether a previous
	 * divergence needed clearing — added a query to every page load of a plugin
	 * whose point is being light. Measured: one query per request, constant,
	 * whether or not anything was wrong.
	 *
	 * So nothing is read unless there is a divergence to record, and clearing is
	 * handled by the record expiring rather than by anybody looking. While the
	 * override persists something re-records it; when it stops, the record ages
	 * out on its own. A diagnostic that is at most an hour stale is worth a page
	 * load that costs nothing.
	 */
	if ( (bool) $expectations[ $hook ] === (bool) $value ) {
		return $value;
	}

	/*
	 * The raw record, not the filtered view.
	 *
	 * keel_defaults_policy_divergences() normalises every live entry to true
	 * before returning it, which is right for a caller asking "what is
	 * diverging" and wrong for one about to write the record back: a hook
	 * expected to be false was rewritten as true, and the next read discarded it
	 * for disagreeing with its own expectation. Recording one divergence erased
	 * another.
	 */
	$stored = get_transient( 'keel_policy_divergence' );
	$known  = array();

	/*
	 * The fingerprint has to be checked here too, not only on the way out.
	 *
	 * keel_defaults_policy_divergences() discards a record made under a
	 * different set of active plugins. Checking only there left the two halves
	 * disagreeing: the reader ignored the stale record while the writer, seeing
	 * the hook already present in it, declined to replace it. A divergence that
	 * was still happening on every request went unreported until the hour ran
	 * out. The activation hooks hide that on the site which served the change; a
	 * network-wide activation leaves every other subsite in exactly this state,
	 * which is the case the fingerprint exists for.
	 */
	if ( is_array( $stored ) && isset( $stored['hooks'], $stored['plugins'] ) && is_array( $stored['hooks'] )
		&& keel_defaults_active_plugin_fingerprint() === $stored['plugins'] ) {
		$known = $stored['hooks'];
	}

	if ( array_key_exists( $hook, $known ) ) {
		return $value;
	}

	// The expectation is stored beside the hook, not just the fact of a
	// divergence. A setting changed afterwards then invalidates the record on
	// the way out, at no cost — see keel_defaults_policy_divergences(). The TTL
	// cannot catch that case at all: flipping the setting to match what the other
	// plugin was doing would otherwise leave "not taking effect" standing for an
	// hour, about a setting that is now being honoured.
	$known[ $hook ] = (bool) $expectations[ $hook ];

	set_transient(
		'keel_policy_divergence',
		array(
			'plugins' => keel_defaults_active_plugin_fingerprint(),
			'hooks'   => $known,
		),
		HOUR_IN_SECONDS
	);

	return $value;
}

/**
 * Settings that were last seen not producing the value they ask for.
 *
 * @return array<string, bool> Hook => true.
 */
function keel_defaults_policy_divergences() {
	$stored = get_transient( 'keel_policy_divergence' );

	if ( ! is_array( $stored ) || empty( $stored['hooks'] ) || ! is_array( $stored['hooks'] ) ) {
		return array();
	}

	/*
	 * A record does not outlive the plugin set that produced it.
	 *
	 * Deactivating network-wide clears the transient on the site the request ran
	 * on and nowhere else, so every other subsite went on reporting until the
	 * TTL. The activation hooks stay, because they clear promptly — but
	 * correctness cannot depend on which site happened to serve the request, and
	 * iterating a network to delete a diagnostic would cost more than the
	 * diagnostic is worth.
	 *
	 * So the record carries a fingerprint of what was active when it was made,
	 * and is discarded when that no longer matches. Free on the healthy path,
	 * which never reads this at all.
	 */
	if ( ! isset( $stored['plugins'] ) || keel_defaults_active_plugin_fingerprint() !== $stored['plugins'] ) {
		return array();
	}

	$stored = $stored['hooks'];

	/*
	 * A hook that has left the expectation map, or a setting since changed, must
	 * not keep reporting from a stale record. The record also expires on its own
	 * — see keel_defaults_observe_policy_result() — so a divergence that stops
	 * happening stops being reported without anybody clearing it.
	 */
	$expectations = keel_defaults_policy_expectations();
	$live         = array();

	foreach ( array_intersect_key( $stored, $expectations ) as $hook => $expected ) {
		// Recorded under the expectation that still applies, or not at all.
		if ( (bool) $expected === (bool) $expectations[ $hook ] ) {
			$live[ $hook ] = true;
		}
	}

	return $live;
}

/**
 * A cheap stand-in for "the plugins that were active".
 *
 * Sorted so activation order cannot pass for a change, and hashed so the record
 * stays small. Network-active plugins are included: on multisite they are
 * exactly the ones whose deactivation happens somewhere other than the site
 * holding the record.
 *
 * @return string
 */
function keel_defaults_active_plugin_fingerprint() {
	$active = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() && function_exists( 'get_site_option' ) ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$active = array_map( 'strval', $active );
	sort( $active );

	return md5( implode( '|', $active ) );
}

/**
 * Drop every recorded divergence.
 *
 * Called when the plugin set changes and when Keel's own settings are saved —
 * the only moments a divergence can start or stop. Clearing there keeps the
 * record no staler than the last thing that could have changed it, and costs
 * nothing on an ordinary request, which is the property the observer was
 * restructured to get.
 *
 * Deliberately a forget rather than a re-check: re-checking would mean asking
 * what the filters produce, and the only honest way to know that is to wait for
 * WordPress to run them. The next front-end request re-records anything still
 * true.
 *
 * @return void
 */
function keel_defaults_forget_policy_divergences() {
	delete_transient( 'keel_policy_divergence' );
}

/**
 * Register the observers.
 *
 * One per predictable hook, at PHP_INT_MAX, so the value read is the one
 * WordPress hands to whatever asked for it in all but one case.
 *
 * That case is worth naming rather than glossing: PHP_INT_MAX is the last
 * priority, not a position after everyone. WordPress preserves registration
 * order within a priority, so a plugin that also registers at PHP_INT_MAX and
 * loads after Keel runs *after* this observer, and what gets recorded is the
 * value before that plugin changed it. The failure is a divergence going
 * unreported — the observer sees Keel's own value and finds nothing wrong —
 * rather than a false one, so the diagnostic under-reports instead of accusing
 * anybody wrongly, which is the right way round for it to be wrong.
 *
 * Registering later does not fix it. Whenever Keel registers, a plugin can
 * register after that at the same priority; there is no position in WordPress's
 * filter model that is guaranteed last. This is a limit of the mechanism, and
 * the reason the divergence report says a setting "was last seen" producing a
 * different value rather than claiming to know the final one.
 *
 * @return void
 */
function keel_defaults_watch_policy_results() {
	foreach ( array_keys( keel_defaults_policy_expectations() ) as $hook ) {
		add_filter(
			$hook,
			static function ( $value ) use ( $hook ) {
				return keel_defaults_observe_policy_result( $hook, $value );
			},
			PHP_INT_MAX,
			1
		);
	}
}
