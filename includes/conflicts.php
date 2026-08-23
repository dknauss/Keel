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
 * The hooks Keel sets policy through, and what sharing one costs.
 *
 * Three shapes, classified by what contention *does* rather than by what kind
 * of hook it is:
 *
 * `authoritative` — the callback returns a value that replaces its input, so
 * two callbacks cannot both win and the later registration silently decides.
 * The loser keeps showing its own number on its own settings screen.
 *
 * `short_circuit` — core reads the filtered value and returns the moment it is
 * not null. The loser does not have its value overwritten; its callback never
 * runs at all, and neither does whatever it existed to do. Worth telling apart
 * from the above, because "your setting is being ignored" and "your plugin is
 * not running" are different problems with different answers.
 *
 * `additive` — callbacks contribute to a structure or transform a value in
 * turn, so coexisting is normal and is never reported.
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
			'rest_authentication_errors'            => 'authoritative',
			'wp_is_application_passwords_available' => 'authoritative',

			// The editor. Contested by every plugin that forces the classic
			// one, which is why Keel's own force-classic default is opt-in.
			'use_block_editor_for_post'             => 'authoritative',
			'use_block_editor_for_post_type'        => 'authoritative',
			'gutenberg_can_edit_post'               => 'authoritative',
			'use_widgets_block_editor'              => 'authoritative',
			'allowed_block_types_all'               => 'authoritative',

			// Comments and pingbacks, contested by any disable-comments plugin.
			'comments_open'                         => 'authoritative',
			'pings_open'                            => 'authoritative',
			'get_comments_number'                   => 'authoritative',

			// Admin surface.
			'show_admin_bar'                        => 'authoritative',
			'wp_supports_ai'                        => 'authoritative',

			// Core returns on the first non-null: the loser never runs.
			'pre_wp_mail'                           => 'short_circuit',
			'comments_pre_query'                    => 'short_circuit',

			// Shared without harm; listed so the decision is recorded rather
			// than looking like an omission.
			'wp_headers'                            => 'additive',
			'user_has_cap'                          => 'additive',
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
	$file = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$reflection = new ReflectionFunction( $callback );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, (string) $callback[1] );
			$file       = (string) $reflection->getFileName();
		}
	} catch ( Throwable $e ) {
		return '';
	}

	if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
		return '';
	}

	$base = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$file = wp_normalize_path( $file );

	if ( 0 !== strpos( $file, $base ) ) {
		return '';
	}

	$parts = explode( '/', substr( $file, strlen( $base ) ) );

	return isset( $parts[0] ) ? $parts[0] : '';
}

/**
 * Other plugins competing for the policies Keel sets.
 *
 * Only `authoritative` hooks are reported. On those, callbacks discard the
 * value they were handed, so WordPress keeps whichever ran last and the losing
 * plugin's settings screen goes on displaying a value the site does not use.
 * Themes, mu-plugins and core resolve to '' and are skipped: this reports
 * plugins competing to own a setting, which is what an admin can act on.
 *
 * @return array<string, string[]> Hook => plugin directory names.
 */
function keel_defaults_competing_plugins() {
	global $wp_filter;

	$self     = defined( 'KEEL_DEFAULTS_FILE' ) ? basename( dirname( KEEL_DEFAULTS_FILE ) ) : 'keel-defaults';
	$conflict = array();

	foreach ( keel_defaults_policy_hooks() as $hook => $kind ) {
		if ( 'additive' === $kind || empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		$mine     = keel_defaults_registered_policy_hooks();
		$plugins  = array();
		$keel_too = isset( $mine[ $hook ] );

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( ! isset( $registered['function'] ) ) {
					continue;
				}

				$dir = keel_defaults_callback_plugin_dir( $registered['function'] );

				if ( '' === $dir ) {
					continue;
				}

				// Still excluded from the rival list by directory: a callback of
				// Keel's own that does resolve must not be reported as competition
				// with itself.
				if ( $dir !== $self ) {
					$plugins[ $dir ] = true;
				}
			}
		}

		/*
		 * Both sides, or it is not a contest.
		 *
		 * Keel stands down on several of these — `auth_cookie_expiration` is
		 * registered only when the session policy differs from WordPress's own
		 * — and a hook Keel is not on is another plugin doing its job. Reported
		 * without this, a site that left session length alone was told that
		 * "more than one plugin is setting the same defaults" when exactly one
		 * was, and the advice attached to it was to go and deactivate
		 * something.
		 */
		if ( $keel_too && ! empty( $plugins ) ) {
			$conflict[ $hook ] = array_keys( $plugins );
		}
	}

	return $conflict;
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
 * @return void
 */
function keel_defaults_add_policy_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	keel_defaults_registered_policy_hooks( $hook );
	add_filter( $hook, $callback, $priority, $accepted_args );
}

/**
 * The contested hooks Keel has registered something on this request.
 *
 * Static rather than an option: it describes what this page load wired up, which
 * depends on the settings, the environment and the screen. Storing it would only
 * create something to go stale.
 *
 * @param string $add Hook to record. Omit to read the list.
 * @return array<string, bool> Hook name => true.
 */
function keel_defaults_registered_policy_hooks( $add = '' ) {
	static $registered = array();

	if ( '' !== $add ) {
		$registered[ $add ] = true;
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
			printf(
				/* translators: %s: comma-separated plugin directory names. */
				esc_html__( 'Another plugin is setting the same defaults as Keel: %s', 'keel-defaults' ),
				esc_html( implode( ', ', array_keys( $plugins ) ) )
			);
			?>
			</strong>
		</p>
		<p>
			<?php esc_html_e( 'These settings are applied through WordPress filters that return a single value. When two plugins set the same one, WordPress keeps whichever ran last and there is no error — the plugin that lost goes on showing the value it believes it applied.', 'keel-defaults' ); ?>
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
