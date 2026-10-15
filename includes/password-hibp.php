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
 * Note that breach screening could not be completed, and report it as unknown.
 *
 * Returns null so the caller can tell "not in the corpus" from "the corpus was
 * not consulted". Recording is a side effect of an answer that was going to be
 * given anyway, so a working site never reaches this and never writes.
 *
 * What is stored is the kind of failure and when it last happened — never the
 * password, and never the hash prefix. The prefix is k-anonymous by design, but
 * a record of which prefixes a site has looked up is still a record of its
 * passwords' shape, and no diagnostic needs it.
 *
 * Bounded by its own TTL rather than by anything clearing it: a site that stops
 * failing stops being reported an hour later, without a cron job or a reader.
 *
 * @param string $kind Short machine tag: unreachable, http_429, truncated, malformed.
 * @return null
 */
function keel_hibp_unavailable( $kind ) {
	$stored = get_transient( 'keel_hibp_unavailable' );
	$now    = time();

	if ( is_array( $stored ) && isset( $stored['kind'] ) && $stored['kind'] === $kind ) {
		/*
		 * Same failure as last time. Re-arm the TTL so a standing outage keeps
		 * being reported, but do not rewrite on every attempt — a password screen
		 * under load would otherwise write once per keystroke-triggered check.
		 */
		if ( isset( $stored['seen'] ) && ( $now - (int) $stored['seen'] ) < 5 * MINUTE_IN_SECONDS ) {
			return null;
		}

		$stored['seen']  = $now;
		$stored['count'] = isset( $stored['count'] ) ? (int) $stored['count'] + 1 : 2;
		set_transient( 'keel_hibp_unavailable', $stored, HOUR_IN_SECONDS );

		return null;
	}

	set_transient(
		'keel_hibp_unavailable',
		array(
			'kind'  => (string) $kind,
			'first' => $now,
			'seen'  => $now,
			'count' => 1,
		),
		HOUR_IN_SECONDS
	);

	return null;
}

/**
 * Note that a lookup completed, so Site Health can say so from evidence.
 *
 * "No failure recorded" is not the same as "screening works": a site where no
 * password has ever been set, or whose hour-long failure record simply expired
 * without anything retrying, has no record either way. Storing the success
 * separately is what lets the difference be reported honestly.
 *
 * Throttled to once an hour. The value is a timestamp, so rewriting it on every
 * lookup would be a write per password check to move a number that nothing
 * reads at that resolution.
 *
 * @return void
 */
function keel_hibp_note_success() {
	$last = get_option( 'keel_hibp_last_success' );

	if ( is_numeric( $last ) && ( time() - (int) $last ) < HOUR_IN_SECONDS ) {
		return;
	}

	update_option( 'keel_hibp_last_success', time(), false );
}

/**
 * When breach screening last completed, if it ever has.
 *
 * @return int|null Unix timestamp, or null if no lookup has ever succeeded here.
 */
function keel_hibp_last_success() {
	$last = get_option( 'keel_hibp_last_success' );

	return is_numeric( $last ) ? (int) $last : null;
}

/**
 * The last recorded breach-screening failure, if it is still current.
 *
 * Only array-ness and a non-empty kind are checked. Timestamp/count fields and
 * third-party cached values remain unvalidated at this boundary.
 *
 * @return array<array-key,mixed>|null Raw failure record, or null.
 */
function keel_hibp_last_failure() {
	$stored = get_transient( 'keel_hibp_unavailable' );

	if ( ! is_array( $stored ) || empty( $stored['kind'] ) ) {
		return null;
	}

	return $stored;
}

/**
 * Return the cache generation for this installation.
 *
 * Persistent object-cache entries cannot always be enumerated or deleted on
 * uninstall. Giving every installation its own generation makes any entries
 * left by an older installation unreachable after the plugin is reinstalled.
 * add_option() resolves two first requests racing to create it: the winner's
 * value is read back and used by both.
 *
 * @return string Opaque per-installation cache generation.
 */
function keel_hibp_cache_generation() {
	$generation = get_option( 'keel_hibp_cache_generation' );

	if ( is_string( $generation ) && '' !== $generation ) {
		return $generation;
	}

	$candidate = str_replace( '-', '', wp_generate_uuid4() );

	if ( add_option( 'keel_hibp_cache_generation', $candidate, '', false ) ) {
		return $candidate;
	}

	$generation = get_option( 'keel_hibp_cache_generation' );

	return is_string( $generation ) && '' !== $generation ? $generation : $candidate;
}

/**
 * Ask Have I Been Pwned whether this password appears in a known breach.
 *
 * Three values, not two. `false` used to mean both "this password is not in the
 * corpus" and "the corpus could not be consulted", which are opposite facts
 * wearing the same answer — a site whose screening had been failing for a week
 * looked exactly like one where every password happened to be clean.
 *
 * `null` is the third: could not determine. It still does not block a password
 * change (see keel_password_is_pwned()) but it is recorded and reported, which
 * is the whole point of separating it.
 *
 * @param string $password The password to screen.
 * @return bool|null True if breached, false if verified clean, null if unknown.
 */
function keel_hibp_lookup( $password ) {
	$hash   = strtoupper( sha1( $password ) );
	$prefix = substr( $hash, 0, 5 );
	$suffix = substr( $hash, 5 );
	$limit  = keel_hibp_response_limit();

	$cache_key = 'keel_hibp_' . keel_hibp_cache_generation() . '_' . $prefix;

	/*
	 * Object cache first, transient second. A password change validates the same
	 * prefix more than once in a request — the profile screen and the REST guard
	 * both ask — and on a site with a persistent object cache the transient is a
	 * cache read anyway. Without one, the transient is a database read each time,
	 * and this makes it one read instead of several.
	 */
	$body   = wp_cache_get( $cache_key, 'keel_hibp' );
	$cached = false !== $body;

	if ( ! $cached ) {
		$body   = get_transient( $cache_key );
		$cached = false !== $body;

		if ( $cached ) {
			wp_cache_set( $cache_key, $body, 'keel_hibp', 12 * HOUR_IN_SECONDS );
		}
	}

	if ( ! $cached ) {
		$response = wp_remote_get(
			'https://api.pwnedpasswords.com/range/' . $prefix,
			array(
				'timeout'             => max( 1, (int) apply_filters( 'keel_hibp_request_timeout', 4 ) ),
				'limit_response_size' => $limit,
				'headers'             => array( 'Add-Padding' => 'true' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return keel_hibp_unavailable( 'unreachable' );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return keel_hibp_unavailable( 'http_' . (int) wp_remote_retrieve_response_code( $response ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Boundary check before trimming (see keel_hibp_body_is_complete()).
		if ( ! keel_hibp_body_is_complete( $body, $limit ) ) {
			return keel_hibp_unavailable( 'truncated' );
		}
	}

	$body = trim( (string) $body );

	if ( '' === $body || ! keel_hibp_body_is_valid( $body ) ) {
		return keel_hibp_unavailable( 'malformed' );
	}

	if ( ! $cached ) {
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
		wp_cache_set( $cache_key, $body, 'keel_hibp', 12 * HOUR_IN_SECONDS );

		/*
		 * A live response proves screening is working again, so the outage record
		 * goes. Only on the uncached path: a cache hit proves nothing about the
		 * service's current state, and clearing there would put a write on the
		 * common path to answer a question it had not asked.
		 *
		 * Read before delete, so an already-clean site does not write at all.
		 */
		if ( null !== keel_hibp_last_failure() ) {
			delete_transient( 'keel_hibp_unavailable' );
		}

		keel_hibp_note_success();
	}

	return keel_hibp_range_contains( $body, $suffix );
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
