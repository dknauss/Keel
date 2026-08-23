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
 * The hooks Keel sets policy through, and whether sharing one matters.
 *
 * `authoritative` — the callback returns a value that replaces its input, so
 * two callbacks cannot both win and the later registration silently decides.
 * `additive` — callbacks contribute to a structure, so coexisting is normal.
 *
 * This map is the only part of the check needing human judgement, and it stays
 * short because it covers only the hooks Keel writes policy through.
 *
 * @return array<string, string>
 */
function keel_defaults_policy_hooks() {
	return apply_filters(
		'keel_policy_hooks',
		array(
			'auth_cookie_expiration'     => 'authoritative',
			'login_headerurl'            => 'authoritative',
			'rest_authentication_errors' => 'authoritative',
			'wp_headers'                 => 'additive',
			'user_has_cap'               => 'additive',
			'rest_endpoints'             => 'additive',
			'heartbeat_settings'         => 'additive',
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
		if ( 'authoritative' !== $kind || empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		$plugins  = array();
		$keel_too = false;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( ! isset( $registered['function'] ) ) {
					continue;
				}

				$dir = keel_defaults_callback_plugin_dir( $registered['function'] );

				if ( '' === $dir ) {
					continue;
				}

				if ( $dir === $self ) {
					$keel_too = true;
				} else {
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
