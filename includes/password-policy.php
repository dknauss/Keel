<?php
/**
 * Password role scoping, validation, and profile/reset/REST adapters.
 *
 * Loaded by security.php; bootstrap.php retains hook registration.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
 * @param object|false|null $user User context, or a failed user lookup.
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
 * @param string            $password Proposed password.
 * @param object|false|null $user User context, or a failed user lookup.
 * @return true|WP_Error True when acceptable, WP_Error describing the failure.
 * @phpstan-return KeelPasswordVerdict
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

	/*
	 * Two rejections that cost nothing come before the network call.
	 *
	 * The review flagged that breach screening runs ahead of every cheap check,
	 * so anyone who can reach a password field can drive an outbound request and
	 * a cache entry per submission, with the prefix of their choosing.
	 *
	 * The obvious remedy — put the role gate first — is not taken, deliberately.
	 * The comment above says why breach screening is universal, and moving the
	 * gate ahead of it would exempt low-privilege accounts from the one rule that
	 * costs them nothing. Trading a security property for a rate-limiting problem
	 * is the wrong trade.
	 *
	 * What is safe to move ahead of it is anything that can never be a valid
	 * password whatever the role: nothing at all, and something longer than
	 * bcrypt will even read. Neither needs WordPress.org's opinion, and both
	 * short-circuit the cheapest way to abuse this.
	 *
	 * That narrows the amplification without closing it. The real answer is a
	 * rate limit, which is a decision rather than a patch — see ROADMAP.
	 */
	if ( '' === (string) $password ) {
		return new WP_Error(
			'keel_password_empty',
			__( '<strong>Error:</strong> Enter a password.', 'keel-defaults' )
		);
	}

	// Core caps what it will hash; anything past that is refused before it can
	// become a lookup. 4096 is WordPress's own PHP_PASSWORD_MAX limit.
	if ( strlen( (string) $password ) > 4096 ) {
		return new WP_Error(
			'keel_password_too_long',
			__( '<strong>Error:</strong> That password is too long.', 'keel-defaults' )
		);
	}

	if ( keel_password_is_pwned( $password ) ) {
		return new WP_Error(
			'keel_password_pwned',
			__( '<strong>Error:</strong> Choose a password that has not appeared in a known data breach.', 'keel-defaults' )
		);
	}

	// Everything below is role-scoped: low-privilege accounts (see keel_weak_roles)
	// are exempt from the length, blocklist, and personal-context rules.
	if ( ! keel_defaults_password_enforced_for_user( $user ) ) {
		return true;
	}

	/*
	 * NIST SP 800-63B-4 § 3.1.1.2 / OWASP: favour length and breach screening
	 * over forced composition rules, which push users toward predictable shapes
	 * like Password1!.
	 *
	 * "Without adding entropy" is the phrasing this comment used to carry (keel-retired-ok), and it
	 * concedes the argument. Demanding a symbol *does* enlarge the alphabet, so
	 * on paper it adds entropy; what it fails to add is guessing resistance,
	 * because people satisfy the rule the same few ways. Entropy is also the term
	 * NIST itself stopped using for user-chosen secrets — it does not appear in
	 * § 3.1.1.2 at all.
	 */
	$minimum = (int) apply_filters( 'keel_minimum_password_length', 15 );

	// Count characters, not bytes: strlen() would read eight emoji as 32 and
	// wave through a password far shorter than the rule intends.
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $password ) : strlen( $password );

	if ( $length < $minimum ) {
		return new WP_Error(
			'keel_password_too_short',
			sprintf(
				/* translators: %d: minimum password length. */
				__( '<strong>Error:</strong> Password must be at least %d characters.', 'keel-defaults' ),
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
			__( '<strong>Error:</strong> Choose a password that is not commonly used.', 'keel-defaults' )
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
					__( '<strong>Error:</strong> Password must not contain your username or email name.', 'keel-defaults' )
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
 * @return void
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
 * @return void
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
 * @template TPrepared of object
 * @phpstan-param TPrepared $prepared_user
 * @phpstan-param WP_REST_Request<covariant array<string,mixed>> $request
 * @phpstan-return TPrepared|WP_Error
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
 * Endpoint handlers and metadata are not validated as a full registry schema.
 * Only matching password arguments are wrapped; all other entries pass through.
 *
 * @param array<array-key,mixed> $endpoints Registered REST endpoints and metadata.
 * @return array<array-key,mixed>
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
 * @phpstan-param WP_REST_Request<covariant array<string,mixed>> $request
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
		/*
		 * Only resolve a user the caller may actually edit.
		 *
		 * Sanitize callbacks run BEFORE authorization. WP_REST_Server calls
		 * sanitize_params() and only afterwards consults the route's
		 * permission_callback, so by the time core decides a subscriber cannot
		 * touch /wp/v2/users/123, this function has already been handed 123.
		 *
		 * Resolving it regardless would run the password policy against another
		 * account's login, email and nicename, and hand the answer back in the
		 * validation error — an oracle for whether a guessed string appears in
		 * someone else's user record. Anyone who can reach the route could ask.
		 *
		 * Falling through instead uses only the fields the request itself
		 * supplied, which is the caller's own input and discloses nothing.
		 */
		$is_self = get_current_user_id() === $user_id;

		if ( $is_self || current_user_can( 'edit_user', $user_id ) ) {
			$existing = get_userdata( $user_id );
			if ( $existing ) {
				return $existing;
			}
		}
	}

	$context                = new stdClass();
	$context->user_login    = (string) $request['username'];
	$context->user_email    = (string) $request['email'];
	$context->user_nicename = (string) $request['slug'];

	return $context;
}
