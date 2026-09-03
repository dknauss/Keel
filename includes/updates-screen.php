<?php
/**
 * The same-line patch, offered on the screen whose job it is.
 *
 * `get_core_updates()` drops every offer WordPress has flagged for automatic
 * installation — an unconditional `continue` in wp-admin/includes/update.php, before
 * the dismissed and available options are even read. So the Updates screen shows the
 * newest release and never mentions the patched release on the site's own line.
 *
 * Keel's Site Health panel names that patch and, in the common case, sends the reader
 * to a screen that will not show it. This renders the offer there instead, which
 * answers the omission where it happens rather than somewhere else.
 *
 * @package keel-defaults
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Updates screen offer.
 *
 * `after_core_auto_updates_settings` rather than `core_upgrade_preamble`. Core
 * documents the latter as firing "after the core, plugin, and theme update tables",
 * which puts the offer at the bottom of the page, a long way from the release it is
 * about. The former fires at the end of core_auto_updates_settings(), which core calls
 * immediately before it renders the core update block — so the offer lands directly
 * above the thing it is arguing with, and directly below the automatic-update settings
 * that decide which release this site would get. That is the same subject.
 *
 * core_upgrade_preamble() itself has no hooks, so rendering inside core's own table is
 * not available at all.
 *
 * @return void
 */
function keel_defaults_register_updates_screen() {
	add_action( 'after_core_auto_updates_settings', 'keel_defaults_render_updates_screen' );
}

/**
 * Render the offer, if there is one to make.
 *
 * @return void
 */
function keel_defaults_render_updates_screen() {
	echo keel_defaults_render_updates_screen_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction; this screen applies no kses.
		keel_defaults_version_status(),
		keel_defaults_branch_tip(),
		keel_defaults_latest_version(),
		keel_defaults_ladder_selection()
	);
}

/**
 * Everything this screen contributes: the result of the last install, then the offer.
 *
 * The result is rendered outside the offer rather than within it. A successful install
 * leaves the site no longer insecure, so the offer correctly stops being made -- and an
 * administrator returning from that install would be shown nothing at all, while the
 * result waited in its transient for their next visit to Site Health.
 *
 * @param string       $status   Result of keel_defaults_version_status().
 * @param string       $tip      Patched release on this line, or ''.
 * @param string       $latest   Newest release WordPress.org lists.
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @return string
 */
function keel_defaults_updates_screen_content( $status, $tip, $latest, $selected ) {
	$result = function_exists( 'keel_defaults_backport_result_markup' )
		? keel_defaults_backport_result_markup()
		: '';

	return $result . keel_defaults_updates_screen_markup( $status, $tip, $latest, $selected );
}

/**
 * The offer, gated on capability.
 *
 * @param string       $status   Result of keel_defaults_version_status().
 * @param string       $tip      Patched release on this line, or ''.
 * @param string       $latest   Newest release WordPress.org lists.
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @return string Markup, or '' when there is nothing to offer.
 */
function keel_defaults_render_updates_screen_markup( $status, $tip, $latest, $selected ) {
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	return keel_defaults_updates_screen_content( $status, $tip, $latest, $selected );
}

/**
 * The offer itself.
 *
 * Only for an insecure release with a patch on its own line. A merely outdated release
 * needs nothing said here: this screen is already offering the newer one, which is the
 * right advice. An undetermined status says nothing either — Site Health explains what
 * could not be determined, and a screen that offers actions is the wrong place to
 * speculate.
 *
 * @param string       $status   Result of keel_defaults_version_status().
 * @param string       $tip      Patched release on this line, or ''.
 * @param string       $latest   Newest release WordPress.org lists.
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @return string Markup, or '' when there is nothing to offer.
 */
function keel_defaults_updates_screen_markup( $status, $tip, $latest, $selected ) {
	if ( 'insecure' !== $status || ! is_string( $tip ) || '' === $tip ) {
		return '';
	}

	$patch = '<code>' . esc_html( $tip ) . '</code>';

	$lead = sprintf(
		/* translators: %s: patched WordPress version on the site's own release line. */
		esc_html__( 'This site is running a release with publicly known vulnerabilities. %s fixes them and stays on the same release line, so only the third number changes.', 'keel-defaults' ),
		$patch
	);

	/*
	 * What the screen is offering instead only matters when it differs. On a site core
	 * would move to the patch anyway, saying it is being skipped would be false.
	 */
	$compare = '';

	if ( is_string( $selected ) && '' !== $selected && $selected !== $tip ) {
		$compare = sprintf(
			/* translators: 1: release WordPress would install, 2: patched release on this line. */
			esc_html__( 'WordPress would install %1$s and skip %2$s. It takes the highest release your settings allow rather than the nearest, so the fix for the line you are on is passed over.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>',
			$patch
		);
	} elseif ( is_string( $latest ) && '' !== $latest && $latest !== $tip ) {
		$compare = sprintf(
			/* translators: 1: newest release, 2: patched release on this line. */
			esc_html__( 'The update offered above is %1$s. %2$s is the smaller change that closes the same hole.', 'keel-defaults' ),
			'<code>' . esc_html( $latest ) . '</code>',
			$patch
		);
	}

	/*
	 * The button alone, not keel_defaults_backport_actions().
	 *
	 * That function's prose is written for Site Health, where "the Updates screen will
	 * not offer 6.4.10, it is offering 7.1 instead" is the news. Printed here it tells
	 * a reader looking at the Updates screen that the Updates screen will not offer the
	 * release, directly above a button that installs it. The panel's explanation is
	 * what carries a reader to this screen; it has no job once they arrive.
	 */
	$actions = '';

	if ( function_exists( 'keel_defaults_backport_install_button' ) ) {
		$offer   = keel_defaults_updates_screen_offer( $tip );
		$actions = keel_defaults_backport_install_button( $tip, $offer['state'], keel_defaults_minor_update_state(), 'updates' );
	}

	return '<div class="notice notice-warning keel-defaults-patch-offer" style="padding:12px;">'
		. '<h3 style="margin-top:0;">' . esc_html__( 'A security release exists for your version line', 'keel-defaults' ) . '</h3>'
		. '<p>' . $lead . '</p>'
		. ( '' !== $compare ? '<p>' . $compare . '</p>' : '' )
		. $actions
		. '</div>';
}

keel_defaults_register_updates_screen();
