<?php
/**
 * Configuration locks and displayed policy values, shared by settings consumers.
 *
 * Declaration-only: no settings cache, hook registration or option reads on load.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * If a wp-config.php constant supersedes a setting, return a short inline note
 * explaining it (the note may contain <code>). The control is then disabled and
 * the note shown, so the screen never offers a switch that cannot take effect.
 * Returns null when the setting is fully under the dashboard's control.
 *
 * @param string $key Schema key.
 * @return string|null
 */
function keel_defaults_config_lock( $key ) {
	$updates_off = keel_defaults_background_updates_off();

	switch ( $key ) {
		case 'core_update_policy':
			/*
			 * The switch before the policy. With background updates off, core never
			 * asks which releases to take, so WP_AUTO_UPDATE_CORE is not what
			 * decided. Naming it anyway sat above a control showing "No automatic
			 * core releases" and contradicted it.
			 */
			if ( $updates_off ) {

				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel-defaults' ), $updates_off );
			}
			if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
				return __( 'Locked by <code>WP_AUTO_UPDATE_CORE</code> in <code>wp-config.php</code>. Remove that constant to manage core releases here.', 'keel-defaults' );
			}
			break;

		case 'plugin_auto_update_policy':
		case 'theme_auto_update_policy':
			// Fall through: global configuration disables every background update.
		case 'auto_update_translations':
			if ( $updates_off ) {

				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel-defaults' ), $updates_off );
			}
			break;

		case 'limit_unfiltered_html_to_admins':
			if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
				return __( '<code>DISALLOW_UNFILTERED_HTML</code> in <code>wp-config.php</code> already removes unfiltered HTML from every role, so this restriction has no additional effect.', 'keel-defaults' );
			}
			break;

		case 'post_revisions_limit':
			/*
			 * Core supplies true itself when wp-config.php says nothing, so
			 * defined() alone would lock this control on every site. A false or
			 * numeric value is distinguishable from that default and therefore
			 * proves that the operator chose a policy outside Keel.
			 */
			if ( defined( 'WP_POST_REVISIONS' ) && true !== WP_POST_REVISIONS ) {
				return __( 'Locked by <code>WP_POST_REVISIONS</code> in <code>wp-config.php</code>. Remove that constant to manage revision retention here.', 'keel-defaults' );
			}
			break;
	}

	return null;
}

/**
 * The wp-config.php constant that turns off every background update, if any.
 *
 * Either one means WordPress performs no background updates at all, which
 * supersedes both the core-update policy and the translation-update toggle.
 * Shared by the lock note and the locked value, so the two cannot disagree
 * about whether updates are off.
 *
 * @return string|null The constant's name, or null when background updates run.
 */
function keel_defaults_background_updates_off() {
	if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
		return 'AUTOMATIC_UPDATER_DISABLED';
	}

	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return 'DISALLOW_FILE_MODS';
	}

	return null;
}

/**
 * The policy a WP_AUTO_UPDATE_CORE value enforces, as one of this setting's choices.
 *
 * Follows Core_Upgrader::should_update_to_version(), strict comparisons and all.
 * true and the four pre-release strings share one branch there — the pre-release
 * part only applies to a site already running a development build — so they are
 * "all" here. A value that branch does not recognise, the string 'true' included,
 * leaves WordPress on its own defaults, which is what "inherit" says.
 *
 * @param mixed $constant The value of WP_AUTO_UPDATE_CORE.
 * @return string One of core_update_policy's choices.
 */
function keel_defaults_core_policy_for_constant( $constant ) {
	if ( false === $constant ) {
		return 'manual';
	}

	if ( true === $constant || in_array( $constant, array( 'beta', 'rc', 'development', 'branch-development' ), true ) ) {
		return 'all';
	}

	return 'minor' === $constant ? 'minor' : 'inherit';
}

/**
 * What a locked control shows: the value wp-config.php enforces, in the
 * setting's own terms, rather than the preference stored underneath the lock.
 *
 * The lock note says who decided; only the control can say what they decided.
 * Showing the stored value there meant a site with WP_AUTO_UPDATE_CORE = true
 * read "Maintenance/security releases only" while installing every release.
 *
 * Resolved in the same order as keel_defaults_config_lock(), so the control never
 * disagrees with the note above it. A setting with nothing enforced to show —
 * unlocked, locked by network policy, or made moot by a constant rather than set
 * by one — shows what is stored.
 *
 * @param string $key   Schema key.
 * @param mixed  $value Stored value.
 * @return mixed
 */
function keel_defaults_config_locked_value( $key, $value ) {
	$updates_off = null !== keel_defaults_background_updates_off();

	switch ( $key ) {
		case 'core_update_policy':
			if ( $updates_off ) {
				return 'manual';
			}
			if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
				return keel_defaults_core_policy_for_constant( WP_AUTO_UPDATE_CORE );
			}
			break;

		case 'plugin_auto_update_policy':
		case 'theme_auto_update_policy':
			if ( $updates_off ) {
				return 'disabled';
			}
			if ( is_multisite() && ! is_main_site() ) {
				return keel_defaults_extension_update_policy( 'plugin_auto_update_policy' === $key ? 'plugin' : 'theme' );
			}
			break;

		case 'auto_update_translations':
			if ( $updates_off ) {
				return 'no';
			}
			break;

		case 'post_revisions_limit':
			// Core reads a non-true value as a count, the same way.
			if ( defined( 'WP_POST_REVISIONS' ) && true !== WP_POST_REVISIONS ) {
				return (int) WP_POST_REVISIONS;
			}
			break;
	}

	return $value;
}
