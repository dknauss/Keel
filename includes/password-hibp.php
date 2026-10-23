<?php
/**
 * Have I Been Pwned range lookup, response validation, cache and diagnostics.
 *
 * Only a five-character SHA-1 prefix is sent; suffix comparison stays local.
 * Unknown responses fail open and are reported separately from clean results.
 * Loaded by security.php; bootstrap.php retains hook registration.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/password-hibp-budget.php';

/**
 * Largest HIBP range response this site will accept, in bytes.
 *
 * A padded range response is ~30-50KB; the cap is the guard against a hijacked
 * or proxied endpoint streaming an unbounded body into memory. It is also the
 * truncation boundary — see keel_hibp_body_is_complete().
 *
 * @return int
 */
function keel_hibp_response_limit() {
	return max( 1024, (int) apply_filters( 'keel_hibp_max_response_bytes', 128 * 1024 ) );
}

/**
 * Whether a range body arrived complete rather than cut off at the transport cap.
 *
 * Checked BEFORE trimming: a capped response can end exactly on a CRLF, and
 * trimming first would hide those bytes and make a truncated range look whole.
 * A truncated range is the dangerous case — the missing tail is indistinguishable
 * from "not breached", so a real match silently reads as clean.
 *
 * @param string $body  Raw response body, untrimmed.
 * @param int    $limit Transport cap in bytes.
 * @return bool
 */
function keel_hibp_body_is_complete( $body, $limit ) {
	return strlen( (string) $body ) < (int) $limit;
}

/**
 * Whether a range body is a well-formed HIBP response.
 *
 * Every line is a 35-character hash suffix, a colon, and a count. Anything else
 * — an HTML error page served with a 200, a captive-portal interstitial, a
 * partial line — is treated as unavailable rather than parsed as "no match".
 *
 * @param string $body Trimmed response body.
 * @return bool
 */
function keel_hibp_body_is_valid( $body ) {
	return 1 === preg_match( '/\A[0-9A-F]{35}:[0-9]+(?:\r?\n[0-9A-F]{35}:[0-9]+)*\z/i', (string) $body );
}

/**
 * Whether a validated range body lists the given hash suffix as a real match.
 *
 * `Add-Padding` asks HIBP to pad the response so its size cannot reveal how many
 * real matches it held; padded rows carry a count of 0 and are not matches.
 *
 * @param string $body   Validated range body.
 * @param string $suffix SHA-1 suffix (everything after the 5-character prefix).
 * @return bool
 */
function keel_hibp_range_contains( $body, $suffix ) {
	foreach ( preg_split( '/\r\n|\n/', (string) $body ) as $line ) {
		$parts = array_pad( explode( ':', trim( $line ), 2 ), 2, '0' );

		if ( (int) $parts[1] > 0 && 0 === strcasecmp( $parts[0], $suffix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Record bounded, scope-shared evidence without storing credential material.
 *
 * @param string $kind Failure reason.
 * @return null
 */
function keel_hibp_unavailable( $kind ) {
	keel_hibp_budget_note( $kind );
	return null;
}

/**
 * Last validated live lookup, never a cache hit.
 *
 * @return int|null
 */
function keel_hibp_last_success() {
	return keel_hibp_budget_evidence()['success'];
}

/**
 * Recent sampled unavailability, not a denial counter.
 *
 * @return array{kind:string,first:int,seen:int}|null
 */
function keel_hibp_last_failure() {
	return keel_hibp_budget_evidence()['failure'];
}

/**
 * Perform a bounded, padded range lookup; compare the suffix only locally.
 *
 * @param string $password Password to screen.
 * @return bool|null Breached, verified clean, or unknown.
 */
function keel_hibp_lookup( $password ) {
	$scope = keel_hibp_budget_scope();
	if ( ! $scope ) {
		return null;
	}
	$hash      = strtoupper( sha1( $password ) );
	$admission = keel_hibp_budget_admit( $scope, substr( $hash, 0, 5 ) );
	if ( isset( $admission['body'] ) ) {
		return keel_hibp_range_contains( $admission['body'], substr( $hash, 5 ) );
	}
	if ( isset( $admission['error'] ) ) {
		return keel_hibp_unavailable( $admission['error'] );
	}
	$ticket = $admission['ticket'];
	if ( ! keel_hibp_budget_owned( $ticket ) ) {
		return keel_hibp_unavailable( 'budget_unavailable' );
	}
	$limit    = keel_hibp_response_limit();
	$response = wp_remote_get(
		'https://api.pwnedpasswords.com/range/' . substr( $hash, 0, 5 ),
		array(
			'timeout'             => max( 1, (int) apply_filters( 'keel_hibp_request_timeout', 4 ) ),
			'limit_response_size' => $limit,
			'headers'             => array( 'Add-Padding' => 'true' ),
		)
	);
	$kind     = '';
	$body     = null;
	if ( is_wp_error( $response ) ) {
		$kind = 'unreachable';
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$kind = 'http_' . (int) wp_remote_retrieve_response_code( $response );
		if ( ! keel_hibp_budget_kind( $kind ) ) {
			$kind = 'unreachable';
		}
	} else {
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( ! keel_hibp_body_is_complete( $raw, $limit ) ) {
			$kind = 'truncated';
		} elseif ( ! keel_hibp_body_is_valid( trim( $raw ) ) ) {
			$kind = 'malformed';
		} else {
			$body = trim( $raw );
		}
	}
	if ( ! keel_hibp_budget_finish( $ticket, $body, $kind ) ) {
		return keel_hibp_unavailable( 'budget_unavailable' );
	}
	if ( null === $body ) {
		return null;
	}
	return keel_hibp_range_contains( $body, substr( $hash, 5 ) );
}

/**
 * Check a password against known breach data.
 *
 * The network lookup can be switched off entirely — with the KEEL_DISABLE_HIBP
 * constant in wp-config.php, or the keel_disable_hibp filter — for air-gapped
 * sites or policies that forbid the outbound request. Screening then falls back
 * to whatever keel_password_is_pwned returns, so a site can substitute a local
 * breach list without the API call.
 *
 * @param string $password Plain-text password to screen.
 * @return bool True when the password appears in a known breach.
 */
function keel_password_is_pwned( $password ) {
	$pwned = false;

	/**
	 * Filter whether to skip the Have I Been Pwned network lookup.
	 *
	 * @param bool   $disabled Whether the lookup is disabled.
	 * @param string $password The password being screened.
	 */
	$disabled = ( defined( 'KEEL_DISABLE_HIBP' ) && KEEL_DISABLE_HIBP )
		|| (bool) apply_filters( 'keel_disable_hibp', false, $password );

	if ( ! $disabled ) {
		$pwned = keel_hibp_lookup( $password );

		/*
		 * Unknown is not the same as clean, and it still does not block.
		 *
		 * Refusing a password because a third-party API is down would lock people
		 * out of their own accounts during somebody else's outage, so screening
		 * fails open exactly as before. What changed is that it no longer fails
		 * *silently*: keel_hibp_lookup() has already recorded the failure, and
		 * Site Health reports it. The rest of the policy — length, the blocklist,
		 * the personal-context rules — is untouched by this and still applies.
		 */
		if ( null === $pwned ) {
			$pwned = false;
		}
	}

	/**
	 * Filter the breach-screening verdict (e.g. to consult a local blocklist).
	 *
	 * @param bool   $pwned    Whether the password appeared in a known breach.
	 * @param string $password The password being screened.
	 */
	return (bool) apply_filters( 'keel_password_is_pwned', $pwned, $password );
}
