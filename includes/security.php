<?php
/**
 * Security defaults: capabilities, sessions, Jetpack/XML-RPC, headers and REST.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

// Preserve the security entry point for existing consumers; declarations only.
require_once __DIR__ . '/password-hibp.php';
require_once __DIR__ . '/password-policy.php';
require_once __DIR__ . '/password-login-screening.php';

/**
 * Remove `unfiltered_html` from non-Administrators when the hardening default is on.
 *
 * Runs on `user_has_cap`, so it sees the capability map WordPress resolved for a
 * user and drops `unfiltered_html` for anyone who is not an Administrator (or, on
 * multisite, a Super Admin). Editors hold `unfiltered_html` by default on
 * single-site installs — enough to save a raw `<script>` — so this closes that.
 *
 * Recursion trap — the reason this reads roles/caps directly, and why the
 * `is_super_admin()` call is guarded by `is_multisite()`: this runs *inside* the
 * `user_has_cap` filter, so any capability check it performs re-enters the same
 * filter and recurses until the stack blows. Decide only from `$user->roles` and
 * the already-resolved `$allcaps` — never `current_user_can()` / `user_can()`.
 * `is_super_admin()` is safe *only* on multisite, where it consults the network
 * super-admin list; on single-site it internally calls `has_cap( 'delete_users' )`,
 * which would recurse — hence the `is_multisite()` guard in front of it.
 *
 * @param array    $allcaps User's resolved capabilities.
 * @param array    $caps    Required primitive caps (unused).
 * @param array    $args    Context args (unused).
 * @param \WP_User $user    The user being checked.
 * @return array
 */
function keel_defaults_limit_unfiltered_html( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps['unfiltered_html'] ) ) {
		return $allcaps;
	}

	$roles = ( isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();

	// Administrators keep it. `manage_options` is already in $allcaps (the
	// resolved-cap proxy for "is an admin"), so reading it here does not recurse.
	if ( in_array( 'administrator', $roles, true ) || ! empty( $allcaps['manage_options'] ) ) {
		return $allcaps;
	}

	// Super Admins keep it on multisite. See the recursion note above for why
	// is_super_admin() is only called behind is_multisite().
	if ( is_multisite() && isset( $user->ID ) && is_super_admin( $user->ID ) ) {
		return $allcaps;
	}

	$allcaps['unfiltered_html'] = false;

	return $allcaps;
}

/**
 * Whether the session policy says anything WordPress does not already do.
 *
 * `auth_cookie_expiration` composes every callback in priority order, but a
 * callback may replace the incoming number with its own. The final callback is
 * not necessarily the sole decision-maker—a later callback can preserve or
 * transform an earlier result. Keel's overlap report therefore describes shared
 * registration without inferring which configured outcome wins.
 *
 * Keel's defaults are WordPress's own values, so the honest move is to stay out
 * of a policy decision it does not need to make. On a site that has not changed them,
 * so on a site that has not changed them, registering would assert core's answer
 * over another plugin's deliberate one.
 *
 * The test is asked of the callback rather than of the stored options: hand it
 * core's own numbers and see whether it hands back something different. That
 * stays correct if the schema defaults change, if a new setting starts
 * influencing the result, or if the clamp logic is rewritten — none of which a
 * hardcoded comparison against 2 and 14 would survive.
 *
 * @return bool
 */
function keel_defaults_session_policy_is_custom() {
	$core_regular    = 2 * DAY_IN_SECONDS;
	$core_remembered = 14 * DAY_IN_SECONDS;

	return keel_defaults_session_length( $core_regular, 0, false ) !== $core_regular
		|| keel_defaults_session_length( $core_remembered, 0, true ) !== $core_remembered;
}

/**
 * The session settings that currently change how long a login lasts.
 *
 * One filter, auth_cookie_expiration, carries all three, and the overlap report
 * names Keel's side of it from what is recorded here. A setting counts when
 * putting it back to its default would change either length. That is asked of
 * the lengths rather than the stored values because the three interact: the
 * regular length floors the remembered one, a non-positive length means core's,
 * and turning Remember Me off can be moot. Special-casing each of those one at
 * a time is how this kept getting them wrong.
 *
 * @return string[] Schema keys, in settings-screen order.
 */
function keel_defaults_session_settings_in_effect() {
	$actual = array(
		'session_regular_days' => (int) keel_defaults_get( 'session_regular_days' ),
		'disable_remember_me'  => keel_defaults_enabled( 'disable_remember_me' ),
		'remember_me_days'     => (int) keel_defaults_get( 'remember_me_days' ),
	);
	$core   = array(
		'session_regular_days' => 2,
		'disable_remember_me'  => false,
		'remember_me_days'     => 14,
	);
	$now    = keel_defaults_session_lengths( $actual );

	$settings = array();
	foreach ( $core as $key => $default ) {
		if ( keel_defaults_session_lengths( array_merge( $actual, array( $key => $default ) ) ) !== $now ) {
			$settings[] = $key;
		}
	}

	return $settings;
}

/**
 * Ordinary and remembered login lengths, in days, for a set of session values.
 *
 * Mirrors keel_defaults_session_length() with core's own 2 and 14 days as the
 * incoming lengths, which is what a non-positive stored value falls back to.
 * Kept separate rather than shared: that function is the security clamp, and
 * this one only labels a diagnostic.
 *
 * @param array $values session_regular_days, disable_remember_me, remember_me_days.
 * @return int[] Ordinary days, remembered days.
 */
function keel_defaults_session_lengths( array $values ) {
	$regular  = $values['session_regular_days'];
	$ordinary = $regular > 0 ? $regular : 2;
	$floor    = $regular > 0 ? $regular : 14;

	if ( $values['disable_remember_me'] ) {
		return array( $ordinary, $floor );
	}

	$remembered = $values['remember_me_days'] > 0 ? $values['remember_me_days'] : 14;

	return array( $ordinary, max( $floor, $remembered ) );
}

/**
 * Decide how long a login lasts.
 *
 * Both lengths are stored in days. Registered at priority 50, not the default
 * 10: this is a policy clamp, and a clamp has to be the last word. At 10 any
 * plugin filtering `auth_cookie_expiration` at a default priority lands after
 * it and quietly wins — which is the case a site sets a session length to
 * prevent.
 *
 * The max() is a second belt. Sanitize already keeps a stored remembered length
 * at or above the regular one, so ticking Remember Me can never shorten a
 * login — but sanitize only runs when the settings form is saved. An option
 * written by WP-CLI, a migration, or another plugin never passes through it,
 * and this is where that would otherwise surface: a remembered login expiring
 * sooner than an ordinary one.
 *
 * `$expiration` is deliberately not consulted, and that is not an inconsistency
 * with keel_defaults_set_frame_option_header(), which does consult what it was
 * handed. The difference is in what an incoming value proves. Core always passes
 * a number here — 2 or 14 days, from wp_set_auth_cookie() — so "there is an
 * incoming value" carries no information at all, and nothing distinguishes core's
 * own default from another plugin's deliberate one. Comparing would therefore
 * mean capping a site's explicit 30-day setting at core's 14 and displaying 30 on
 * a settings screen that no longer describes the site.
 *
 * The rule the two filters share: compare where an incoming value is evidence of
 * a decision, assert where it is not. What differs is the hook, not the
 * philosophy. A refactor that "harmonises" these into one style will break
 * whichever hook it was not written for; tests/policy-conflicts.php pins both.
 *
 * @param int  $expiration WordPress's own length, in seconds.
 * @param int  $user_id    User ID (unused).
 * @param bool $remember   Whether Remember Me was ticked.
 * @return int
 */
function keel_defaults_session_length( $expiration, $user_id, $remember ) {
	unset( $user_id );

	$regular_days = (int) keel_defaults_get( 'session_regular_days' );
	$regular      = $regular_days > 0 ? $regular_days * DAY_IN_SECONDS : $expiration;

	if ( keel_defaults_enabled( 'disable_remember_me' ) ) {
		return $regular;
	}

	if ( $remember ) {
		$remember_days = (int) keel_defaults_get( 'remember_me_days' );
		$remembered    = $remember_days > 0 ? $remember_days * DAY_IN_SECONDS : $expiration;

		return max( $regular, $remembered );
	}

	return $regular;
}

/**
 * Whether Jetpack is active on this site.
 *
 * Jetpack reaches WordPress.com over XML-RPC, so blocking the endpoint breaks
 * the connection and everything downstream of it. Nothing here acts on that by
 * itself: a toggle that quietly refuses to do what it says is worse than one
 * that warns and then obeys. This only decides whether the warning is shown.
 *
 * @return bool
 */
function keel_defaults_jetpack_active() {
	return defined( 'JETPACK__VERSION' ) || class_exists( 'Automattic\\Jetpack\\Connection\\Manager' );
}

/**
 * Fault code for a refused system.multicall.
 *
 * XML-RPC's "requested method not found". Not 405: with remote publishing off,
 * core sends the fault code as the HTTP status, and a real status code turned
 * an ordinary refusal into a transport failure.
 */
const KEEL_DEFAULTS_MULTICALL_FAULT = -32601;

/**
 * Jetpack's connection manager, when this Jetpack has one Keel can use.
 *
 * @return object|null
 */
function keel_defaults_jetpack_manager() {
	$class = 'Automattic\\Jetpack\\Connection\\Manager';

	return class_exists( $class ) ? new $class() : null;
}

/**
 * Whether Jetpack verifies the current request as signed by WordPress.com.
 *
 * This is what lets a Jetpack multicall through, and nothing weaker does. The
 * for=jetpack query argument is not evidence of anything — anyone can send it.
 * Jetpack's own check verifies the signature, body hash, timestamp and nonce
 * against the token this site holds.
 *
 * Asking again inside one request is safe, although Jetpack has already
 * verified it by the time a method runs and verification uses the nonce up.
 * The used-nonce list is a static on Jetpack's Nonce_Handler, kept "to keep the
 * nonces accessible between the Nonce_Handler instances", so a second manager
 * gets the first answer back rather than an invalid-nonce error. Jetpack relies
 * on that itself: Jetpack_XMLRPC_Methods::json_api() builds a new manager and
 * verifies again from inside a method handler. A Jetpack old enough to lack
 * that list would fail the second check, and multicall would stay refused.
 *
 * Fails closed. If the method is missing, returns an error, or throws, the
 * request is not verified and multicall stays refused; Site Health reports the
 * case where Keel cannot ask at all.
 *
 * @return bool
 */
function keel_defaults_jetpack_request_verified() {
	$manager = keel_defaults_jetpack_manager();

	if ( null === $manager || ! method_exists( $manager, 'verify_xml_rpc_signature' ) ) {
		return false;
	}

	try {
		$verified = $manager->verify_xml_rpc_signature();
	} catch ( Throwable $e ) {
		return false;
	}

	return is_array( $verified ) && ! empty( $verified );
}

/**
 * What Keel's XML-RPC settings mean for a Jetpack connection on this site.
 *
 * - `''`         no connected Jetpack, so nothing to report
 * - `'blocked'`  the endpoint is blocked: WordPress.com cannot reach the site
 * - `'open'`     multicall is allowed for everyone
 * - `'verified'` multicall is refused, except requests Jetpack verifies
 * - `'refused'`  multicall is refused and this Jetpack cannot verify requests
 *
 * @return string
 */
function keel_defaults_jetpack_xmlrpc_state() {
	if ( ! keel_defaults_jetpack_active() ) {
		return '';
	}

	$manager = keel_defaults_jetpack_manager();

	if ( null !== $manager && method_exists( $manager, 'is_connected' ) ) {
		try {
			if ( ! $manager->is_connected() ) {
				return '';
			}
		} catch ( Throwable $e ) {
			unset( $e ); // Unknown is treated as connected: the report errs towards saying something.
		}
	}

	if ( keel_defaults_enabled( 'block_xmlrpc_endpoint' ) ) {
		return 'blocked';
	}

	if ( keel_defaults_enabled( 'xmlrpc_allow_multicall' ) ) {
		return 'open';
	}

	return ( null !== $manager && method_exists( $manager, 'verify_xml_rpc_signature' ) ) ? 'verified' : 'refused';
}

/**
 * The Jetpack warning for a setting, when one applies on this site.
 *
 * Returned for the settings screen rather than written into the help text: it is
 * only true on some sites, and a warning that is always on teaches people to
 * stop reading warnings.
 *
 * Three settings have one. The XML-RPC endpoint block warns wherever Jetpack is
 * active. Admin Menu Width warns only while Jetpack's WordPress.com menu
 * stylesheet is actually loading and Keel's width is left unchanged: that is the
 * state where the menu is 272px wide and Keel appears to be doing nothing.
 *
 * AI Connectors warns wherever Jetpack is active, because Jetpack reads the
 * same `wp_supports_ai` gate: with it closed Jetpack reports its AI extensions
 * unavailable ("ai_disabled"), leaves its AI Assistant block unregistered and
 * drops its jetpack-ai REST routes. The setting is named for core's connectors,
 * so without the note nothing says it reaches a paid Jetpack feature.
 *
 * @param string $key Schema key being rendered.
 * @return string Text, or '' when the warning does not apply.
 */
function keel_defaults_jetpack_warning( $key ) {
	if ( 'admin_menu_width' === $key ) {
		return keel_defaults_jetpack_sets_menu_width()
			? __( 'Jetpack\'s WordPress.com menu styling is setting the admin menu to 272px on this site, and Keel is leaving it alone. Choose 160px to restore WordPress\'s own width.', 'keel-defaults' )
			: '';
	}

	if ( 'disable_ai_connectors' === $key ) {
		return keel_defaults_jetpack_active()
			? __( 'Jetpack is active on this site and follows this setting. While it is on, Jetpack\'s AI Assistant and its other AI features are switched off too.', 'keel-defaults' )
			: '';
	}

	if ( 'block_xmlrpc_endpoint' !== $key || ! keel_defaults_jetpack_active() ) {
		return '';
	}

	return __( 'Jetpack is active on this site. It uses XML-RPC for its WordPress.com connection, so blocking the endpoint will break it. Leave this off unless connection and feature testing proves Jetpack no longer needs XML-RPC.', 'keel-defaults' );
}

/**
 * Find a header's actual array key, matching case-insensitively, so a caller can
 * overwrite in place instead of adding a second key that differs only in case.
 *
 * @param mixed  $headers Headers array from the wp_headers filter.
 * @param string $name    Header name.
 * @return string|null
 */
function keel_defaults_find_header_key( $headers, $name ) {
	if ( ! is_array( $headers ) ) {
		return null;
	}
	foreach ( array_keys( $headers ) as $key ) {
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return (string) $key;
		}
	}
	return null;
}

/**
 * Relative strength of an X-Frame-Options value. Only the two values browsers
 * honour are ranked; anything else returns null so callers leave the response
 * alone (this keeps a deprecated ALLOW-FROM's permissive intent intact).
 *
 * @param mixed $value Header value.
 * @return int|null 2 = DENY, 1 = SAMEORIGIN, null = unrecognized.
 */
function keel_defaults_frame_option_strength( $value ) {
	switch ( strtoupper( trim( (string) $value ) ) ) {
		case 'DENY':
			return 2;
		case 'SAMEORIGIN':
			return 1;
		default:
			return null;
	}
}

/**
 * Set X-Frame-Options, deferring to a header another layer already set unless the
 * configured value is strictly stricter — so a host's DENY is never downgraded to
 * SAMEORIGIN, and a deliberately configured DENY still tightens a weaker existing
 * value. Writes back to the existing key so a differently cased key does not emit
 * a second header line. Opt out with keel_disable_x_frame_options.
 *
 * Comparing is right *here* specifically because core never seeds this key:
 * `send_frame_options_header()` emits the header with `header()` and never touches
 * the `wp_headers` array, so a value present in it is evidence that some layer
 * decided on one. That is what makes ranking it meaningful, and it is exactly what
 * `auth_cookie_expiration` cannot offer — see keel_defaults_session_length().
 *
 * The Customizer is the one place that evidence misleads, and it is core itself.
 * `WP_Customize_Manager::filter_iframe_security_headers()` sets SAMEORIGIN on the
 * previewed front end at priority 10 so the preview will load in its iframe; Keel
 * runs at 99, so a site configured DENY escalated core's value and the preview
 * survived only because core sets `frame-ancestors 'self'` in the same call and
 * CSP wins over X-Frame-Options wherever both appear. A functional header that
 * lives behind another header's protection is not something to keep leaning on,
 * and "stronger" is the wrong question when the incoming value was set to make a
 * feature work rather than to state a posture. So leave the preview alone.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_frame_option_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_x_frame_options', false ) ) {
		return $headers;
	}

	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return $headers;
	}

	$value        = apply_filters( 'keel_x_frame_options', keel_defaults_get( 'frame_options' ) );
	$existing_key = keel_defaults_find_header_key( $headers, 'X-Frame-Options' );

	if ( null !== $existing_key ) {
		$existing_strength   = keel_defaults_frame_option_strength( $headers[ $existing_key ] );
		$configured_strength = keel_defaults_frame_option_strength( $value );

		if ( null === $existing_strength || null === $configured_strength ) {
			return $headers;
		}
		if ( $configured_strength <= $existing_strength ) {
			return $headers;
		}

		$headers[ $existing_key ] = $value;
		return $headers;
	}

	$headers['X-Frame-Options'] = $value;
	return $headers;
}

/**
 * Set X-Content-Type-Options: nosniff. `nosniff` is this header's only effective
 * value, so any other existing value (empty, off, a typo) is corrected in place
 * rather than deferred to. Opt out with keel_disable_x_content_type_options.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_content_type_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_x_content_type_options', false ) ) {
		return $headers;
	}

	$existing_key = keel_defaults_find_header_key( $headers, 'X-Content-Type-Options' );

	if ( null !== $existing_key ) {
		if ( 'nosniff' === strtolower( trim( (string) $headers[ $existing_key ] ) ) ) {
			return $headers;
		}
		$headers[ $existing_key ] = 'nosniff';
		return $headers;
	}

	$headers['X-Content-Type-Options'] = 'nosniff';
	return $headers;
}

/**
 * Set a baseline Referrer-Policy. Unlike the other two headers, Referrer-Policy
 * has no single strictness axis across its tokens, so an existing policy is
 * deferred to rather than second-guessed. Value filterable via
 * keel_referrer_policy; opt out with keel_disable_referrer_policy.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_referrer_policy_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_referrer_policy', false ) ) {
		return $headers;
	}

	if ( null !== keel_defaults_find_header_key( $headers, 'Referrer-Policy' ) ) {
		return $headers;
	}

	$value = apply_filters( 'keel_referrer_policy', 'strict-origin-when-cross-origin' );
	if ( '' === trim( (string) $value ) ) {
		return $headers;
	}

	$headers['Referrer-Policy'] = $value;
	return $headers;
}

/**
 * REST route prefixes that stay reachable when anonymous REST is blocked.
 *
 * The oEmbed route is the carve-out worth making. It is served over REST, so closing REST
 * closes it — and the visible consequence lands on *other people's* sites:
 * every post of yours they have embedded degrades to a bare link, silently,
 * with nothing on this site to show it happened.
 *
 * Measuring the field found nobody handling this. Of the four plugins that
 * close REST outright, none allowlists `oembed/1.0` — see
 * docs/competitive-teardown-matrix.md, finding 7. The trade was always
 * defensible; being unable to opt out of it was not.
 *
 * The carve-out is only safe because of what sits in front of it. oEmbed
 * *does* return author_name and author_url by default, and author_url carries
 * the account nicename — opening this route without that fix would reopen the
 * username enumeration `restrict_rest_user_discovery` exists to close.
 *
 * So `keel_defaults_strip_oembed_author()` is registered by the REST gate
 * itself, not only by hidden author archives: whenever this carve-out is in
 * play, both fields are removed before the response is built, and what is left
 * is the title, provider and embed markup of a single published post. Hiding
 * author archives registers the same filter for its own reasons, which is
 * harmless — it unsets keys that are already gone.
 *
 * The two are deliberately independent. Tying the strip to the archive setting
 * would mean a site that closed REST but left author archives public handed the
 * anonymous caller, through oEmbed, the very nicenames the gate had just
 * refused it at /wp/v2/users.
 *
 * @return string[] Route prefixes, each with a leading slash.
 */
function keel_defaults_public_rest_routes() {
	return (array) apply_filters( 'keel_public_rest_routes', array( '/oembed/1.0' ) );
}

/**
 * Whether the route being requested is one of the public carve-outs.
 *
 * `rest_authentication_errors` fires before dispatch, so the route is not on
 * the server object yet — but `rest_api_loaded()` has already parsed it into
 * the query vars by then, which is where this reads it. REQUEST_URI is
 * deliberately not used: it carries whatever the client sent, traversal
 * included, and a gate that trusts it can be walked around.
 *
 * @return bool
 */
function keel_defaults_rest_route_is_public() {
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

	if ( '' === $route ) {
		return false;
	}

	$route = '/' . ltrim( $route, '/' );

	foreach ( keel_defaults_public_rest_routes() as $prefix ) {
		$prefix = '/' . trim( (string) $prefix, '/' );

		// Match on a path boundary, so /oembed/1.0 does not also admit a route
		// called /oembed/1.0-internal.
		if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Require an authenticated user for every REST request.
 *
 * Registered at PHP_INT_MAX on purpose. Core resolves Application Password auth
 * at priority 90 and cookie auth at 100, and rest_cookie_check_errors() returns
 * true after calling wp_set_current_user( 0 ) when a cookie carries no
 * X-WP-Nonce. Deciding before core has finished — or treating any truthy
 * $result as success — would read that true as "authenticated" and let the
 * request dispatch as user 0. Only an existing WP_Error short-circuits.
 *
 * @param WP_Error|true|null $result Authentication result so far.
 * @return WP_Error|true|null
 */
function keel_defaults_require_rest_auth( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( keel_defaults_rest_route_is_public() ) {
		return $result;
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'REST API restricted to authenticated users.', 'keel-defaults' ),
			array( 'status' => 401 )
		);
	}

	return $result;
}
