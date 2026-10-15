<?php
/**
 * Core backports: status.
 *
 * Loaded by backports.php; public callback names and behavior are unchanged.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

const KEEL_DEFAULTS_STABLE_CHECK_URL       = 'https://api.wordpress.org/core/stable-check/1.0/';
const KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT = 'keel_stable_check';
const KEEL_DEFAULTS_STABLE_CHECK_FAILED    = 'keel_stable_check_failed';
// Five minutes. Long enough that an outage cannot stall repeated admin loads,
// short enough that recovery is noticed promptly.
const KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL = 300;
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
 * Cached arrays are returned as stored; only fresh HTTP responses are filtered.
 * Do not assume cached keys or values have passed that validation.
 *
 * @return array<array-key,mixed> Version => status. Empty array if unavailable.
 */
function keel_defaults_stable_check() {
	$cached = get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	// A failed fetch caches a sentinel for a few minutes. Without it, an outage
	// turns every qualifying admin request into a fresh 10-second timeout,
	// because a miss is indistinguishable from a never-fetched state.
	if ( 'unreachable' === get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED ) ) {
		return array();
	}

	$response = wp_remote_get(
		KEEL_DEFAULTS_STABLE_CHECK_URL,
		array(
			// Short: this can run on a cold cache during a Site Health render.
			'timeout'    => 5,
			'user-agent' => 'Keel; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	$map = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $map ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	// Keep only well-formed version => known-status pairs. An unrecognised status
	// must not reach the caller, where it would fall through to a "good" verdict.
	$map = array_filter(
		$map,
		static function ( $status, $version ) {
			return is_string( $version )
				&& preg_match( '/^\d+\.\d+(\.\d+)?$/', $version )
				&& in_array( $status, array( 'latest', 'outdated', 'insecure' ), true );
		},
		ARRAY_FILTER_USE_BOTH
	);

	if ( empty( $map ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED, 'unreachable', KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
		return array();
	}

	set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, $map, KEEL_DEFAULTS_STABLE_CHECK_TTL );

	return $map;
}

/**
 * Whether the cached status map predates a release core is already offering.
 *
 * The daily cache is wrong on exactly one day that matters: the day a release
 * lands, when the version this site runs has just become insecure and a map
 * fetched yesterday still calls it latest. Core hears about the release on its
 * own schedule, through the update_core offers, and an offer naming a release
 * the map does not list is proof the map is older than that release.
 *
 * Any unlisted offer, not only one newer than the map's latest: a security
 * release can ship on an older line alone, and the map's latest is then still
 * offered and still current. Development offers carry a suffix and are never
 * listed, so they prove nothing and are skipped by the same pattern the map is
 * filtered with.
 *
 * @param array<array-key,mixed> $map         Cached version => status; only key presence is read.
 * @param mixed                  $update_core The update_core transient value.
 * @return bool
 */
function keel_defaults_stable_check_is_stale( array $map, $update_core ) {
	if ( empty( $map ) || ! is_object( $update_core ) || ! isset( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
		return false;
	}

	foreach ( $update_core->updates as $offer ) {
		if ( ! is_object( $offer ) || ! isset( $offer->current ) || ! is_string( $offer->current ) ) {
			continue;
		}

		if ( preg_match( '/^\d+\.\d+(\.\d+)?$/', $offer->current ) && ! isset( $map[ $offer->current ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Fetch the status map again when core's update check shows it is out of date.
 *
 * Runs when core stores its update offers, which is already a background or
 * update-screen request making calls to api.wordpress.org, so the extra request
 * lands where a network wait is expected rather than on an arbitrary admin page.
 * With nothing cached it does nothing: the first fetch stays lazy.
 *
 * A failed refresh keeps the old map, which is still right about every release
 * but the new one, rather than leaving Site Health with no answer. It is kept for
 * the failure interval only, so a map known to be stale is not granted another
 * full day.
 *
 * @param mixed $update_core The update_core value being stored.
 * @return void
 */
function keel_defaults_refresh_stale_stable_check( $update_core ) {
	$cached = get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );

	if ( ! is_array( $cached ) || ! keel_defaults_stable_check_is_stale( $cached, $update_core ) ) {
		return;
	}

	delete_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );

	if ( empty( keel_defaults_stable_check() ) ) {
		set_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, $cached, KEEL_DEFAULTS_STABLE_CHECK_FAIL_TTL );
	}
}

/**
 * The running WordPress version, on every version Keel supports.
 *
 * Core added wp_get_wp_version() in 6.7. Keel declares `Requires at least:
 * 6.4`, so
 * calling it unguarded is a fatal error on 6.4, 6.5 and 6.6 — not a degraded
 * check but a white screen, on three versions inside the supported range. The
 * live backport matrix caught it on its first run; nothing in the unit suite
 * could, because the test doubles define the function.
 *
 * The fallback reads version.php the way core's own implementation does, and
 * deliberately not get_bloginfo( 'version' ): that passes through the
 * `bloginfo` filters, and hiding the version is a thing plugins do — Keel has
 * its own `remove_version` default. A filtered value is the wrong input for a
 * check about which release is actually running.
 *
 * @return string
 */
function keel_defaults_wp_version() {
	if ( function_exists( 'wp_get_wp_version' ) ) {
		return wp_get_wp_version();
	}

	static $fallback = null;

	if ( null === $fallback ) {
		require ABSPATH . WPINC . '/version.php';
		$fallback = isset( $wp_version ) ? $wp_version : '';
	}

	return $fallback;
}

/**
 * Status of the version this site is running.
 *
 * @return 'latest'|'outdated'|'insecure'|'unknown' Validated running-version status.
 * @phpstan-return KeelVersionStatus
 */
function keel_defaults_version_status() {
	$map = keel_defaults_stable_check();

	if ( empty( $map ) ) {
		return 'unknown';
	}

	$version = keel_defaults_wp_version();

	// Development builds carry a suffix and are never listed.
	// strpos rather than str_contains: Keel supports PHP 7.4, where that
	// function does not exist.
	if ( false !== strpos( $version, '-' ) ) {
		return 'unknown';
	}

	if ( ! isset( $map[ $version ] ) ) {
		return 'unknown';
	}

	return in_array( $map[ $version ], array( 'latest', 'outdated', 'insecure' ), true )
		? $map[ $version ]
		: 'unknown';
}

/**
 * The patched tip of the branch this site is on.
 *
 * For a site on 6.4.9 this returns 6.4.10 — the highest release on the 6.4 line
 * that WordPress.org is not currently flagging as insecure. Returns an empty string when the branch has no
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

	$version = keel_defaults_wp_version();
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
 * The x.y line a version belongs to, for naming it in prose.
 *
 * @param string $version Full version.
 * @return string
 */
function keel_defaults_version_line( $version ) {
	return implode( '.', array_slice( preg_split( '/[.-]/', $version ), 0, 2 ) );
}
