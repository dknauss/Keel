<?php
/**
 * Security defaults: unfiltered HTML, response headers, and password policy.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
 * The Jetpack warning for the XML-RPC endpoint block, when it applies.
 *
 * Returned for the settings screen rather than written into the help text: it is
 * only true on some sites, and a warning that is always on teaches people to
 * stop reading warnings.
 *
 * @param string $key Schema key being rendered.
 * @return string Text, or '' when the warning does not apply.
 */
function keel_defaults_jetpack_warning( $key ) {
	if ( 'block_xmlrpc_endpoint' !== $key || ! keel_defaults_jetpack_active() ) {
		return '';
	}

	return __( 'Jetpack is active on this site. It uses XML-RPC for its WordPress.com connection, so blocking the endpoint will break it. Leave this off unless connection and feature testing proves Jetpack no longer needs XML-RPC.', 'keel' );
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
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_frame_option_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_x_frame_options', false ) ) {
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
 * Whether the strong-password policy is enforced for a given user.
 *
 * Scoped to privileged/editorial accounts by default: a user whose every role is
 * in the exempt list (default: subscriber) is skipped, since a hard length +
 * breach rule adds signup friction for low-privilege accounts on membership or
 * commerce sites without protecting anything valuable. Any privileged role — or
 * an unknown/empty role set — enforces. The exempt list comes from the
 * "Password policy exemptions" setting (limited to low-privilege roles) and can
 * be overridden in code with the keel_weak_roles filter.
 *
 * @param WP_User|stdClass|null $user User context, when available.
 * @return bool
 */
function keel_defaults_password_enforced_for_user( $user ) {
	$exempt     = (array) keel_defaults_get( 'password_exempt_roles' );
	$weak_roles = array_map( 'strval', (array) apply_filters( 'keel_weak_roles', $exempt ) );

	$roles = array();
	if ( isset( $user->roles ) && is_array( $user->roles ) ) {
		$roles = $user->roles;
	} elseif ( ! empty( $user->role ) && is_string( $user->role ) ) {
		$roles = array( $user->role );
	}

	// Unknown role set → enforce (the safe default).
	if ( empty( $roles ) ) {
		return true;
	}

	// Exempt only when every role the user holds is in the exempt list.
	foreach ( $roles as $role ) {
		if ( ! in_array( (string) $role, $weak_roles, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Roles that may be exempted from the strong-password policy on the settings
 * screen — the guardrail behind the "Password policy exemptions" control.
 *
 * A role is offered only when it holds none of the sensitive capabilities below,
 * so an administrator can never exempt an editor, author, contributor, shop
 * manager, or admin from the UI: privileged accounts are always enforced. The
 * keel_weak_roles filter remains an unrestricted, deliberate code-level escape.
 *
 * @return array<string,string> role slug => translated display name.
 */
function keel_defaults_exemptable_roles() {
	$sensitive = array(
		'edit_posts',
		'edit_pages',
		'publish_posts',
		'edit_others_posts',
		'upload_files',
		'moderate_comments',
		'manage_categories',
		'manage_options',
		'edit_theme_options',
		'list_users',
		'edit_users',
		'manage_woocommerce',
		'edit_shop_orders',
	);

	if ( ! function_exists( 'wp_roles' ) ) {
		return array();
	}

	$out = array();
	foreach ( wp_roles()->roles as $slug => $data ) {
		$caps = ( isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ) ? $data['capabilities'] : array();

		$privileged = false;
		foreach ( $sensitive as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				$privileged = true;
				break;
			}
		}

		if ( ! $privileged ) {
			$name         = isset( $data['name'] ) ? $data['name'] : $slug;
			$out[ $slug ] = function_exists( 'translate_user_role' ) ? translate_user_role( $name ) : $name;
		}
	}

	return $out;
}

/**
 * Validate a password against the policy.
 *
 * One reusable validator behind every entry point — profile screen, password
 * reset, and the REST users controller — so a password cannot get in through a
 * door the policy does not watch.
 *
 * @param string                $password Proposed password.
 * @param WP_User|stdClass|null $user     User context, when available.
 * @return true|WP_Error True when acceptable, WP_Error describing the failure.
 */
function keel_defaults_validate_password( $password, $user = null ) {
	/*
	 * Breach screening applies to every account, exempt or not, and runs first.
	 *
	 * The exemption exists so a hard length rule does not add signup friction for
	 * subscribers on a membership or commerce site. That reasoning covers length;
	 * it does not cover a password already published in a breach corpus. Those are
	 * the credentials that get stuffed, and a subscriber account is a foothold like
	 * any other. Skipping the check for exempt roles meant the one rule that costs
	 * the user nothing was the one they did not get.
	 */
	if ( keel_password_is_pwned( $password ) ) {
		return new WP_Error(
			'keel_password_pwned',
			__( '<strong>Error:</strong> Choose a password that has not appeared in a known data breach.', 'keel' )
		);
	}

	// Everything below is role-scoped: low-privilege accounts (see keel_weak_roles)
	// are exempt from the length, blocklist, and personal-context rules.
	if ( ! keel_defaults_password_enforced_for_user( $user ) ) {
		return true;
	}

	// NIST 800-63B / OWASP: favour length + screening over forced composition
	// rules (upper/lower/number/symbol), which push users toward predictable
	// patterns like Password1! without adding entropy.
	$minimum = (int) apply_filters( 'keel_minimum_password_length', 15 );

	// Count characters, not bytes: strlen() would read eight emoji as 32 and
	// wave through a password far shorter than the rule intends.
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $password ) : strlen( $password );

	if ( $length < $minimum ) {
		return new WP_Error(
			'keel_password_too_short',
			sprintf(
				/* translators: %d: minimum password length. */
				__( '<strong>Error:</strong> Password must be at least %d characters.', 'keel' ),
				$minimum
			)
		);
	}

	$normalize = static function ( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	};

	// A small local blocklist still catches the obvious cases when the breach
	// API below is unreachable (that check deliberately fails open).
	$blocklist = (array) apply_filters(
		'keel_password_blocklist',
		array( 'password', 'password123', '123456789012345', 'qwertyuiopasdfg', 'letmeinletmeinletmein', 'wordpresswordpress' )
	);

	if ( in_array( $normalize( $password ), array_map( $normalize, $blocklist ), true ) ) {
		return new WP_Error(
			'keel_password_common',
			__( '<strong>Error:</strong> Choose a password that is not commonly used.', 'keel' )
		);
	}

	// NIST also says to reject passwords derived from personal context.
	if ( $user ) {
		$context = array_filter(
			array(
				isset( $user->user_login ) ? $user->user_login : '',
				isset( $user->user_nicename ) ? $user->user_nicename : '',
				isset( $user->user_email ) ? strtok( $user->user_email, '@' ) : '',
			)
		);

		foreach ( $context as $value ) {
			$value = $normalize( $value );
			if ( strlen( $value ) >= 4 && false !== strpos( $normalize( $password ), $value ) ) {
				return new WP_Error(
					'keel_password_personal',
					__( '<strong>Error:</strong> Password must not contain your username or email name.', 'keel' )
				);
			}
		}
	}

	return true;
}

/**
 * Validate a password submitted from a user profile screen.
 *
 * @param WP_Error         $errors Validation errors.
 * @param bool             $update Whether this is an update.
 * @param WP_User|stdClass $user   User context.
 */
function keel_defaults_validate_profile_password( $errors, $update, $user ) {
	unset( $update );

	// Core's edit_user() trims the password and stores the TRIMMED value, but
	// fires this hook with $_POST untouched. Validate the untrimmed string and
	// "              a" sails past the length rule while core saves a
	// one-character password. Measure exactly what core will store.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.
	$password = isset( $_POST['pass1'] ) ? trim( (string) wp_unslash( $_POST['pass1'] ) ) : '';

	if ( '' === $password ) {
		return; // No password change requested (or whitespace-only).
	}

	$result = keel_defaults_validate_password( $password, $user );

	if ( is_wp_error( $result ) ) {
		$errors->add( $result->get_error_code(), $result->get_error_message() );
	}
}

/**
 * Validate a password submitted from the password-reset screen.
 *
 * @param WP_Error $errors Validation errors.
 * @param WP_User  $user   User context.
 */
function keel_defaults_validate_reset_password( $errors, $user ) {
	// wp-login.php already trims $_POST['pass1'] in place before firing this
	// hook; trimming again keeps both entry points measuring the same string.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.
	$password = isset( $_POST['pass1'] ) ? trim( (string) wp_unslash( $_POST['pass1'] ) ) : '';

	if ( '' === $password ) {
		return;
	}

	$result = keel_defaults_validate_password( $password, $user );

	if ( is_wp_error( $result ) ) {
		$errors->add( $result->get_error_code(), $result->get_error_message() );
	}
}

/**
 * Validate a password submitted through the core REST users controller.
 *
 * Backstop for any route the argument guard below does not reach.
 *
 * @param object          $prepared_user Prepared user object.
 * @param WP_REST_Request $request       REST request.
 * @return object|WP_Error
 */
function keel_defaults_validate_rest_password( $prepared_user, $request ) {
	$password = $request->get_param( 'password' );

	if ( null === $password || '' === $password ) {
		return $prepared_user;
	}

	$user   = ! empty( $prepared_user->ID ) ? get_userdata( $prepared_user->ID ) : $prepared_user;
	$result = keel_defaults_validate_password( (string) $password, $user );

	return is_wp_error( $result ) ? $result : $prepared_user;
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

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'REST API restricted to authenticated users.', 'keel' ),
			array( 'status' => 401 )
		);
	}

	return $result;
}

/**
 * Enforce the password policy on the users controller's `password` argument.
 *
 * The rest_pre_insert_user hook is the documented seam, but the controller never checks
 * its return for an error: update_item() assigns ID onto the WP_Error and hands
 * it to wp_update_user(), which finds no user_pass and answers 200 OK with the
 * user unchanged; create_item() casts it to an array with no user_login and
 * answers 500 "empty login name". Either way the policy message is lost.
 *
 * An argument-level error is different. WP_REST_Request::sanitize_params()
 * turns it into rest_invalid_param, which dispatch() returns as a 400 before
 * the callback runs, so the caller sees the actual reason.
 *
 * @param array $endpoints Registered REST endpoints, keyed by route.
 * @return array
 */
function keel_defaults_guard_rest_password_arg( $endpoints ) {
	foreach ( $endpoints as $route => $handlers ) {
		// Application Passwords are core-generated and carry a readonly password
		// field, so they are never in scope for a human password policy.
		if ( ! preg_match( '#^/wp/v2/users(?:/|$)#', $route ) || false !== strpos( $route, 'application-password' ) ) {
			continue;
		}

		if ( ! is_array( $handlers ) ) {
			continue;
		}

		foreach ( $handlers as $index => $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['args']['password'] ) ) {
				continue;
			}

			$inner = isset( $handler['args']['password']['sanitize_callback'] )
				? $handler['args']['password']['sanitize_callback']
				: null;

			$endpoints[ $route ][ $index ]['args']['password']['sanitize_callback'] = function ( $value, $request, $param ) use ( $inner ) {
				// Let core sanitize first; it rejects empty and backslashed passwords.
				if ( $inner ) {
					$value = call_user_func( $inner, $value, $request, $param );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
				}

				$result = keel_defaults_validate_password(
					(string) $value,
					keel_defaults_rest_password_context( $request )
				);

				if ( is_wp_error( $result ) ) {
					return new WP_Error(
						$result->get_error_code(),
						$result->get_error_message(),
						array( 'status' => 400 )
					);
				}

				return $value;
			};
		}
	}

	return $endpoints;
}

/**
 * Resolve the user a REST password change applies to.
 *
 * Argument sanitizing runs before the controller prepares a user, so the
 * context has to come from the request: update_item() takes the id from the
 * route, and update_current_item() only assigns it after dispatch. A create
 * has no stored user at all, so the submitted fields are the only context.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_User|stdClass
 */
function keel_defaults_rest_password_context( $request ) {
	$user_id = 0;

	if ( preg_match( '#/wp/v2/users/me$#', (string) $request->get_route() ) ) {
		$user_id = get_current_user_id();
	} elseif ( null !== $request['id'] ) {
		$user_id = (int) $request['id'];
	}

	if ( $user_id ) {
		$existing = get_userdata( $user_id );
		if ( $existing ) {
			return $existing;
		}
	}

	$context                = new stdClass();
	$context->user_login    = (string) $request['username'];
	$context->user_email    = (string) $request['email'];
	$context->user_nicename = (string) $request['slug'];

	return $context;
}

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
 * Look a password up in the Have I Been Pwned range API using k-anonymity.
 *
 * Only the first five characters of the SHA-1 hash ever leave the site. HIBP
 * returns every hash suffix sharing that prefix and the comparison happens
 * locally, so the password itself is never transmitted.
 *
 * Fails OPEN at every step — an unreachable, truncated, or malformed response
 * allows the password rather than locking everyone out of password changes
 * during an outage. Only a body that arrived whole and parsed cleanly is
 * trusted, and only such a body is cached: caching a truncated or garbage
 * response would turn one bad response into hours of false "not breached"
 * verdicts.
 *
 * @param string $password Plain-text password to screen.
 * @return bool True when the password appears in a known breach.
 */
function keel_hibp_lookup( $password ) {
	$hash   = strtoupper( sha1( $password ) );
	$prefix = substr( $hash, 0, 5 );
	$suffix = substr( $hash, 5 );
	$limit  = keel_hibp_response_limit();

	$cache_key = 'keel_hibp_' . $prefix;
	$body      = get_transient( $cache_key );
	$cached    = false !== $body;

	if ( ! $cached ) {
		$response = wp_remote_get(
			'https://api.pwnedpasswords.com/range/' . $prefix,
			array(
				'timeout'             => max( 1, (int) apply_filters( 'keel_hibp_request_timeout', 4 ) ),
				'limit_response_size' => $limit,
				'headers'             => array( 'Add-Padding' => 'true' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Boundary check before trimming (see keel_hibp_body_is_complete()).
		if ( ! keel_hibp_body_is_complete( $body, $limit ) ) {
			return false;
		}
	}

	$body = trim( (string) $body );

	if ( '' === $body || ! keel_hibp_body_is_valid( $body ) ) {
		return false;
	}

	if ( ! $cached ) {
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
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
	}

	/**
	 * Filter the breach-screening verdict (e.g. to consult a local blocklist).
	 *
	 * @param bool   $pwned    Whether the password appeared in a known breach.
	 * @param string $password The password being screened.
	 */
	return (bool) apply_filters( 'keel_password_is_pwned', $pwned, $password );
}
