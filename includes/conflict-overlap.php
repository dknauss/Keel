<?php
/**
 * Policy registration, structural overlap classification, and fingerprints.
 *
 * Loaded by conflicts.php. Bootstrap retains registration and timing.
 *
 * @package Keel
 */

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
 * The Keel settings a policy hook carries.
 *
 * The settings recorded at registration come first, because they are what this
 * request actually wired up; the static map covers anything not recorded. The
 * per-post-type revision filters are found live rather than mapped, so they are
 * attributed here to the one setting that produces them.
 *
 * @param string $hook Hook name.
 * @return string[] Schema keys.
 */
function keel_defaults_hook_settings( $hook ) {
	$registered = keel_defaults_registered_policy_hooks();
	$settings   = array();

	if ( isset( $registered[ $hook ] ) ) {
		foreach ( $registered[ $hook ] as $record ) {
			foreach ( (array) $record['setting'] as $setting ) {
				if ( '' !== $setting ) {
					$settings[ $setting ] = true;
				}
			}
		}
	}

	if ( empty( $settings ) ) {
		$setting = keel_defaults_policy_setting_for_hook( $hook );

		if ( '' === $setting && preg_match( '/^wp_.+_revisions_to_keep$/', $hook ) ) {
			$setting = 'post_revisions_limit';
		}

		if ( '' !== $setting ) {
			$settings[ $setting ] = true;
		}
	}

	return array_keys( $settings );
}

/**
 * Hooks named by the Keel setting they carry, with the hook kept for developers.
 *
 * "Comments (<code>comments_open</code>)" rather than a bare filter name, which
 * means nothing to most people reading Site Health. A hook with no mapped
 * setting is printed on its own.
 *
 * @param string[] $hooks Hook names.
 * @return string Escaped HTML, comma-separated.
 */
function keel_defaults_hook_setting_names( array $hooks ) {
	$strings = keel_defaults_strings();
	$out     = array();

	foreach ( $hooks as $hook ) {
		$labels = array();
		foreach ( keel_defaults_hook_settings( $hook ) as $setting ) {
			if ( ! empty( $strings[ $setting ]['label'] ) ) {
				$labels[] = $strings[ $setting ]['label'];
			}
		}

		$code  = '<code>' . esc_html( $hook ) . '</code>';
		$out[] = empty( $labels ) ? $code : esc_html( implode( ', ', $labels ) ) . ' (' . $code . ')';
	}

	return implode( ', ', $out );
}

/**
 * Other plugins competing for the policies Keel sets.
 *
 * Only structural overlaps on authoritative hooks are returned. No registered
 * callback is invoked: this check must not cause the conflict it is reporting.
 *
 * @param bool $refresh Rebuild the request-local report cache.
 * @return array<string, string[]> Hook => plugin directory names.
 * @phpstan-return array<string, list<string>>
 */
function keel_defaults_competing_plugins( $refresh = false ) {
	$report = keel_defaults_policy_overlap_report( $refresh );

	return $report['structural'];
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
 * @return array{structural:array<string,list<string>>,unconfirmed:array<string,list<string>>}
 * @phpstan-return KeelOverlapReport
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
	$own    = keel_defaults_own_plugin_dir();

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
		$must_use     = array();
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
				if ( '' !== $plugin && $own === $plugin ) {
					continue;
				}

				if ( '' !== $plugin ) {
					$rivals[ $plugin ] = true;
				} else {
					$theme = keel_defaults_callback_theme( $registered['function'] );

					if ( '' !== $theme ) {
						$rivals[ KEEL_DEFAULTS_THEME_PREFIX . $theme ] = true;
						continue;
					}

					$mu_plugin = keel_defaults_callback_mu_plugin( $registered['function'] );

					if ( '' !== $mu_plugin ) {
						$must_use[ $mu_plugin ] = true;
						continue;
					}

					$file   = wp_normalize_path( keel_defaults_callback_file( $registered['function'] ) );
					$helper = is_string( $registered['function'] ) && 0 === strpos( $registered['function'], '__return_' );

					// Named core callbacks are known platform behavior, not a rival.
					// Core helpers remain unconfirmed because a plugin may have
					// registered the shared callable and left no ownership trace.
					$is_named_core = ! $helper && '' !== $file && keel_defaults_path_is_under( $file, keel_defaults_core_roots() );
					if ( ! $is_named_core ) {
						$unattributed = true;
					}
				}
			}
		}

		if ( $unattributed ) {
			$report['unconfirmed'][ $hook ][] = KEEL_DEFAULTS_UNATTRIBUTED;
		}

		// Whatever the hook, must-use code is information: it is not a plugin an
		// administrator can deactivate or reconfigure from the Plugins screen.
		foreach ( array_keys( $must_use ) as $mu_plugin ) {
			$report['unconfirmed'][ $hook ][] = KEEL_DEFAULTS_MU_PLUGIN_PREFIX . $mu_plugin;
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
 * @param string          $hook          Hook name.
 * @param callable        $callback      Callback.
 * @param int             $priority      Priority.
 * @param int             $accepted_args Number of arguments.
 * @param string|string[] $setting Schema key(s) whose outcome this callback governs.
 * @phpstan-param string|array<array-key, string> $setting
 * @return void
 */
function keel_defaults_add_policy_filter( $hook, $callback, $priority = 10, $accepted_args = 1, $setting = '' ) {
	if ( '' === $setting || array() === $setting ) {
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
		'xmlrpc_enabled'                        => 'xmlrpc_allow_remote_publishing',
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
 * @param string          $add           Hook to record. Omit to read the list.
 * @param callable|null   $callback      Registered callback, or null when reading only.
 * @param int             $priority      Registration priority.
 * @param int             $accepted_args Accepted argument count.
 * @param string|string[] $setting Governing schema key(s).
 * @phpstan-param string|array<array-key, string> $setting
 * @return array<string,list<array{callback:callable|null,priority:int,accepted_args:int,setting:string|array<array-key,string>}>>
 * @phpstan-return array<string, list<KeelPolicyRegistration>> Hook registration records.
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
			'setting'       => is_array( $setting ) ? array_map( 'strval', $setting ) : (string) $setting,
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
