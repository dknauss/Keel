<?php
/**
 * Security backport visibility: is this site actually patched, and if not, can it be?
 *
 * WordPress core never asks this question. It knows whether an update is
 * available, which is not the same thing: a site can be on a release with known
 * vulnerabilities while every admin screen looks perfectly calm. The answer
 * lives at api.wordpress.org/core/stable-check/1.0/, which core does not query.
 *
 * This file surfaces that answer, and — only on an explicit click — offers to
 * act on it.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

const KEEL_DEFAULTS_STABLE_CHECK_URL       = 'https://api.wordpress.org/core/stable-check/1.0/';
const KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT = 'keel_stable_check';
// One day. Written as a literal rather than DAY_IN_SECONDS because this file
// is loaded by the test suite without WordPress, where that constant does not
// exist and a file-scope reference to it is a fatal at require time.
const KEEL_DEFAULTS_STABLE_CHECK_TTL = 86400;

/**
 * Fetch the version status map, cached for a day.
 *
 * The endpoint returns every version WordPress has ever shipped, mapped to
 * 'latest', 'outdated' or 'insecure'. It changes only when a release happens,
 * so a daily cache is generous. A failed request caches nothing, so a network
 * blip does not pin a wrong answer for 24 hours.
 *
 * @return array<string,string> Version => status. Empty array if unavailable.
 */
function keel_defaults_stable_check() {
	$cached = get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		KEEL_DEFAULTS_STABLE_CHECK_URL,
		array(
			'timeout'    => 10,
			'user-agent' => 'Keel; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$map = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $map ) ) {
		return array();
	}

	set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, $map, KEEL_DEFAULTS_STABLE_CHECK_TTL );

	return $map;
}

/**
 * Status of the version this site is running.
 *
 * @return string 'latest', 'outdated', 'insecure', or 'unknown'.
 */
function keel_defaults_version_status() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return 'unknown';
	}

	$version = wp_get_wp_version();

	// Development builds carry a suffix and are never listed.
	// strpos rather than str_contains: Keel supports PHP 7.4, where that
	// function does not exist.
	if ( false !== strpos( $version, '-' ) ) {
		return 'unknown';
	}

	return isset( $map[ $version ] ) ? $map[ $version ] : 'unknown';
}

/**
 * The patched tip of the branch this site is on.
 *
 * For a site on 6.4.9 this returns 6.4.10 — the release carrying every known
 * security fix for the 6.4 line. Returns an empty string when the branch has no
 * secure release at all, which is the situation for every line below 4.7: there
 * is no patch to move to, and no configuration will produce one.
 *
 * @return string Version string, or '' if none.
 */
function keel_defaults_branch_tip() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return '';
	}

	$version = wp_get_wp_version();
	$parts   = explode( '.', $version );

	if ( count( $parts ) < 2 ) {
		return '';
	}

	$branch = $parts[0] . '.' . $parts[1] . '.';
	$tip    = '';

	foreach ( $map as $candidate => $status ) {
		if ( 'insecure' === $status || 0 !== strpos( $candidate, $branch ) ) {
			continue;
		}
		if ( '' === $tip || version_compare( $candidate, $tip, '>' ) ) {
			$tip = $candidate;
		}
	}

	return ( '' !== $tip && version_compare( $tip, $version, '>' ) ) ? $tip : '';
}

/**
 * The version WordPress.org currently marks as latest.
 *
 * Used to tell a patched-but-behind site how far behind it is, which is the
 * fact that turns "outdated" from a label into a decision.
 *
 * @return string Version string, or '' if unavailable.
 */
function keel_defaults_latest_version() {
	foreach ( keel_defaults_stable_check() as $version => $status ) {
		if ( 'latest' === $status ) {
			return $version;
		}
	}

	return '';
}

/**
 * Whether core will install same-branch updates by itself.
 *
 * Security backports are ordinary minor releases — there is no separate
 * security channel — so this single answer decides whether a patch arrives on
 * its own. See Core_Upgrader::should_update_to_version().
 *
 * @return bool
 */
function keel_defaults_minor_updates_enabled() {
	if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
		return false;
	}

	if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
		return false;
	}

	$enabled = 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' );

	if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$enabled = ( false !== WP_AUTO_UPDATE_CORE );
	}

	/** This filter is documented in wp-admin/includes/class-core-upgrader.php */
	return (bool) apply_filters( 'allow_minor_auto_core_updates', $enabled );
}

/**
 * Register the Site Health test.
 *
 * Direct rather than async: the network call is cached for a day, and core sets
 * the precedent with its own dotorg-communication test.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function keel_defaults_register_backport_test( $tests ) {
	$tests['direct']['keel_defaults_backport'] = array(
		'label' => __( 'Security patch status', 'keel-defaults' ),
		'test'  => 'keel_defaults_backport_test',
	);

	return $tests;
}
add_filter( 'site_status_tests', 'keel_defaults_register_backport_test' );

/**
 * Report whether this site is running a version with known vulnerabilities.
 *
 * Deliberately distinguishes "behind" from "vulnerable". Being outdated is a
 * legitimate, deliberate state; being insecure is not. Core's update nag cannot
 * tell the difference and reports both identically.
 *
 * @return array Site Health result.
 */
function keel_defaults_backport_test() {
	$status  = keel_defaults_version_status();
	$version = wp_get_wp_version();
	$tip     = keel_defaults_branch_tip();
	$auto    = keel_defaults_minor_updates_enabled();

	$result = array(
		'label'       => __( 'This site is running a patched version of WordPress', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_defaults_backport',
	);

	if ( 'unknown' === $status ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'Patch status could not be determined', 'keel-defaults' );
		$result['description'] = '<p>' . sprintf(
			/* translators: %s: WordPress version. */
			esc_html__( 'Keel could not reach WordPress.org to check whether %s carries known vulnerabilities. This is also worth noting on its own: a site that cannot reach the API over HTTPS receives no automatic updates at all, silently.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>'
		) . '</p>';

		return $result;
	}

	if ( 'insecure' === $status ) {
		$result['status'] = 'critical';
		$result['label']  = __( 'This version of WordPress has known vulnerabilities', 'keel-defaults' );

		if ( '' === $tip ) {
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: WordPress version. */
				esc_html__( 'WordPress.org classifies %s as insecure, and no patched release exists on this release line. No setting will produce one. The only remedy is moving to a line that is still maintained.', 'keel-defaults' ),
				'<code>' . esc_html( $version ) . '</code>'
			) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress.org classifies %1$s as insecure. The fix for this release line is %2$s, which carries every known security fix without changing major version.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		) . '</p>';

		$result['description'] .= $auto
			? '<p>' . esc_html__( 'Automatic minor updates are enabled, so this should install itself on the next scheduled check. If it has not after a day, something is preventing it.', 'keel-defaults' ) . '</p>'
			: '<p><strong>' . esc_html__( 'Automatic minor updates are switched off on this site, so this patch will not arrive on its own.', 'keel-defaults' ) . '</strong> '
				. esc_html__( 'Security backports travel on the same channel as ordinary maintenance releases; there is no separate security channel to leave open.', 'keel-defaults' ) . '</p>';

		$result['actions'] = keel_defaults_backport_actions( $tip, $auto );

		return $result;
	}

	if ( 'outdated' === $status ) {
		// 'recommended', not 'good'. Staying on a maintained older line is a
		// legitimate choice and this must not nag as though it were a fault.
		// But it is not a settled state either: lines stop being patched, and
		// when yours does, this check flips to critical with no patch to offer.
		// Reporting that as 'good' would hide a decision worth revisiting.
		$result['status']      = 'recommended';
		$result['label']       = __( 'This site is patched, but on an older release line', 'keel-defaults' );
		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: latest version. */
			esc_html__( '%1$s carries every known security fix for its release line, so this site is not vulnerable. The current release is %2$s.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<code>' . esc_html( keel_defaults_latest_version() ) . '</code>'
		) . '</p>';
		$result['description'] .= '<p>' . esc_html__( 'Staying here is a reasonable choice, and Keel will not nag about it. It is worth knowing what it commits you to: release lines are eventually retired, and when this one is, the next vulnerability will have no patch on this line at all. The longer the wait, the larger the eventual move.', 'keel-defaults' ) . '</p>';

		return $result;
	}

	$result['description'] = '<p>' . sprintf(
		/* translators: %s: WordPress version. */
		esc_html__( '%s is the current release.', 'keel-defaults' ),
		'<code>' . esc_html( $version ) . '</code>'
	) . '</p>';

	return $result;
}

/**
 * Offer a way to act, preferring the mechanism core already has.
 *
 * Two routes, in order of preference:
 *
 * 1. Switch minor auto-updates back on. This fixes the cause rather than the
 *    symptom: the patch installs itself, and so does the next one.
 * 2. Install the patch now, once, through core's own upgrader.
 *
 * Neither happens without a click. Keel's job is to make the decision visible,
 * not to make it.
 *
 * @param string $tip  Target version.
 * @param bool   $auto Whether minor auto-updates are already enabled.
 * @return string Markup.
 */
function keel_defaults_backport_actions( $tip, $auto ) {
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	$out = '';

	if ( ! $auto && ! defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$out .= sprintf(
			'<p><a class="button button-primary" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=keel_defaults_enable_minor_updates' ),
					'keel_defaults_enable_minor_updates'
				)
			),
			esc_html__( 'Turn automatic security updates back on', 'keel-defaults' ),
			esc_html__( 'Recommended. This patch, and every future one on this release line, will install without being asked for.', 'keel-defaults' )
		);
	}

	if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
		$out .= '<p class="description">' . sprintf(
			/* translators: %s: constant name. */
			esc_html__( 'Automatic updates are governed by the %s constant, which Keel will not override. Change it in wp-config.php, or install the patch once below.', 'keel-defaults' ),
			'<code>WP_AUTO_UPDATE_CORE</code>'
		) . '</p>';
	}

	$out .= sprintf(
		'<p><a class="button" href="%s">%s</a></p>',
		esc_url( admin_url( 'update-core.php' ) ),
		sprintf(
			/* translators: %s: target version. */
			esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
			esc_html( $tip )
		)
	);

	return $out;
}

/**
 * Re-enable minor auto-updates on request.
 *
 * Writes the option only. It does not touch constants or filters, so a site
 * whose policy is set in code stays set in code — and the Site Health test says
 * so rather than pretending the click worked.
 */
function keel_defaults_handle_enable_minor_updates() {
	if ( ! current_user_can( 'update_core' ) ) {
		wp_die( esc_html__( 'You are not allowed to change update settings on this site.', 'keel-defaults' ) );
	}

	check_admin_referer( 'keel_defaults_enable_minor_updates' );

	update_site_option( 'auto_update_core_minor', 'enabled' );
	delete_site_transient( 'update_core' );

	wp_safe_redirect( add_query_arg( 'keel-minor-updates', 'enabled', admin_url( 'site-health.php' ) ) );
	exit;
}
add_action( 'admin_post_keel_defaults_enable_minor_updates', 'keel_defaults_handle_enable_minor_updates' );

/**
 * Show an admin notice when the running version has known vulnerabilities.
 *
 * Deliberately narrow. It fires only for `insecure` — never for `outdated`,
 * which is a legitimate place to be — and only for users who could act on it.
 * A notice that cannot be acted on is just noise wearing a warning's clothes.
 *
 * It is dismissible per user, and the dismissal is keyed to the version, so
 * dismissing it on 6.8.7 does not hide it again on 6.8.9. That is the failure
 * mode of most "remind me later" notices: they are dismissed once, during the
 * one incident that mattered, and never seen again.
 */
function keel_defaults_backport_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Update-related screens already say their piece; do not stack on them.
	if ( $screen && in_array( $screen->id, array( 'update-core', 'update-core-network', 'site-health' ), true ) ) {
		return;
	}

	if ( 'insecure' !== keel_defaults_version_status() ) {
		return;
	}

	$version = wp_get_wp_version();

	// One key holding the version it was dismissed at, rather than one key per
	// version. Same behaviour — dismissing on 6.8.7 does not hide it on 6.8.9 —
	// but it leaves a single row to remove on uninstall instead of an unbounded
	// set that only a LIKE query could find.
	if ( get_user_meta( get_current_user_id(), 'keel_backport_dismissed', true ) === $version ) {
		return;
	}

	$tip  = keel_defaults_branch_tip();
	$auto = keel_defaults_minor_updates_enabled();

	if ( '' === $tip ) {
		$body = sprintf(
			/* translators: %s: WordPress version. */
			esc_html__( 'WordPress %s has known vulnerabilities, and no patched release exists on this release line. Moving to a maintained version is the only remedy.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>'
		);
	} elseif ( $auto ) {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The patch for this release line is %2$s, and automatic updates should install it shortly.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	} else {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The patch is %2$s, but automatic updates are switched off on this site, so it will not arrive on its own.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	}

	printf(
		'<div class="notice notice-error is-dismissible keel-defaults-backport-notice" data-keel-version="%s"><p>%s</p><p><a href="%s">%s</a></p></div>',
		esc_attr( $version ),
		wp_kses_post( $body ),
		esc_url( admin_url( 'site-health.php' ) ),
		esc_html__( 'See what is preventing it — Site Health', 'keel-defaults' )
	);
}
add_action( 'admin_notices', 'keel_defaults_backport_notice' );

/**
 * Record a per-user, per-version dismissal.
 */
function keel_defaults_dismiss_backport_notice() {
	check_ajax_referer( 'keel_defaults_dismiss_backport', 'nonce' );

	$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

	if ( '' !== $version ) {
		update_user_meta( get_current_user_id(), 'keel_backport_dismissed', $version );
	}

	wp_die();
}
add_action( 'wp_ajax_keel_defaults_dismiss_backport', 'keel_defaults_dismiss_backport_notice' );

/**
 * Make the dismissal stick, rather than only hiding the notice until reload.
 */
function keel_defaults_backport_notice_script() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$script = sprintf(
		'document.addEventListener("click",function(e){' .
			'var b=e.target.closest(".keel-defaults-backport-notice .notice-dismiss");if(!b)return;' .
			'var n=b.closest(".keel-defaults-backport-notice");' .
			'var d=new FormData();d.append("action","keel_defaults_dismiss_backport");' .
			'd.append("nonce",%s);d.append("version",n.dataset.keelVersion);' .
			'fetch(%s,{method:"POST",body:d,credentials:"same-origin"});});',
		wp_json_encode( wp_create_nonce( 'keel_defaults_dismiss_backport' ) ),
		wp_json_encode( admin_url( 'admin-ajax.php' ) )
	);

	wp_add_inline_script( 'common', $script );
}
add_action( 'admin_enqueue_scripts', 'keel_defaults_backport_notice_script' );
