<?php
/**
 * Passive policy-result observation, persistence, and invalidation.
 *
 * Loaded by conflicts.php. Bootstrap retains registration and timing.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
 * Null entries are removed after filtering. Other third-party values are not
 * validated here; observers compare their boolean meaning without rewriting them.
 *
 * @return array<array-key, mixed> Hook => filtered, non-null expectation.
 */
function keel_defaults_policy_expectations() {
	/*
	 * The observer rebuilds this on every watched call, and comments_open fires
	 * once per post. Resolving each key through keel_defaults_enabled() cost up to
	 * five option reads, 13–20 µs a call on CI and nearly all of the observer's
	 * cost (performance budget, 2026-10-28). One read, resolved per key, gives the
	 * same answer and keeps nothing between calls to go stale.
	 */
	$stored  = keel_defaults_stored_settings();
	$network = is_multisite() ? keel_defaults_network_settings() : array();
	$enabled = static function ( $key ) use ( $stored, $network ) {
		return 'yes' === keel_defaults_resolve( $key, $stored, $network );
	};

	$expectations = array(
		// Remote publishing allowed means the endpoint should answer.
		'xmlrpc_enabled'                        => $enabled( 'xmlrpc_allow_remote_publishing' ),
		// Comments off means closed, everywhere, below the theme. pings_open is
		// the same question about the same setting — one without the other was an
		// omission, not a distinction, and it left a plugin forcing pingbacks back
		// open with nothing to report it.
		'comments_open'                         => $enabled( 'disable_comments' ) ? false : null,
		'pings_open'                            => $enabled( 'disable_comments' ) ? false : null,
		'wp_is_application_passwords_available' => $enabled( 'disable_application_passwords' ) ? false : null,
		'wp_supports_ai'                        => keel_defaults_key_supported( 'disable_ai_connectors' ) && $enabled( 'disable_ai_connectors' ) ? false : null,
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
 * @template TValue
 * @phpstan-param TValue $value
 * @phpstan-return TValue
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
 * @return array<array-key, true> Hook => true; keys follow the filtered expectation map.
 * @phpstan-return KeelDivergenceMap
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
