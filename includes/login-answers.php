<?php
/**
 * Uniform Login Answers: the login and lost-password forms give one answer
 * whether or not an account exists (#378).
 *
 * Declaration-only: bootstrap.php registers the two callbacks when the setting
 * is on.
 *
 * Core answers an unknown username, an unknown email address and a wrong
 * password for a real account with three different messages, refills the
 * username field only for a real account, and names the account in the
 * wrong-password message. Its lost-password form redirects to "Check your
 * email" for a real account and re-renders with "There is no account with that
 * username or email address" for an unknown one. Either form tells an
 * anonymous visitor which logins exist.
 *
 * Both callbacks act on what wp-login.php is about to show, never on what
 * authentication returns. `wp_signon()` callers (WooCommerce's My Account form,
 * REST Basic auth, XML-RPC) keep core's answers, and `wp_login_failed` still
 * fires once per failure with the submitted username, so a host's or another
 * plugin's limiter counts exactly what it counted before.
 *
 * Not covered, and said so in the setting's help and in Site Health:
 * registration, the profile and user-edit screens, the reset-link screens,
 * response time, attempt limiting, REST Basic auth, XML-RPC and third-party
 * login forms.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login error codes that tell an anonymous visitor whether an account exists.
 *
 * `invalid_username` and `invalid_email` say it doesn't; `incorrect_password`
 * says it does and names it. Everything else is left alone: empty fields say
 * nothing about any account, and a lockout, CAPTCHA or two-factor error belongs
 * to the plugin that added it.
 *
 * @return string[]
 */
function keel_defaults_login_account_error_codes() {
	return array( 'invalid_username', 'invalid_email', 'incorrect_password' );
}

/**
 * Lost-password error codes that tell an anonymous visitor whether an account exists.
 *
 * `invalid_email` and `invalidcombo` say it doesn't. `no_password_reset` and
 * `retrieve_password_email_failure` can only happen for a real account, so they
 * say it does.
 *
 * @return string[]
 */
function keel_defaults_lostpassword_account_error_codes() {
	return array( 'invalid_email', 'invalidcombo', 'no_password_reset', 'retrieve_password_email_failure' );
}

/**
 * The one message every failed sign-in gets.
 *
 * Never repeats what was typed. Links to the lost-password form, as core's
 * wrong-password message does.
 *
 * @return string HTML.
 */
function keel_defaults_uniform_login_message() {
	return __( '<strong>Error:</strong> The username, email address or password is incorrect.', 'keel-defaults' ) .
		' <a href="' . esc_url( wp_lostpassword_url() ) . '">' .
		__( 'Lost your password?', 'keel-defaults' ) .
		'</a>';
}

/**
 * Give an unknown account and a wrong password the same login answer.
 *
 * Hooked to `wp_login_errors`, which only wp-login.php applies, just before it
 * renders the form. Each account-revealing error is replaced, in place, by one
 * generic error under core's `incorrect_password` code. The code matters as
 * much as the words: wp-login.php refills the username field, moves focus to
 * the password field and shakes the form by error code, so the shared code
 * makes those the same for every input too.
 *
 * Errors with other codes keep their position, messages and data. A plugin
 * that adds a CAPTCHA error beside core's `invalid_username` still has its own
 * error shown, and the core one beside it no longer differs by account.
 *
 * @param WP_Error|mixed $errors      The errors wp-login.php is about to show.
 * @param string         $redirect_to Unused.
 * @return WP_Error|mixed
 */
function keel_defaults_uniform_login_errors( $errors, $redirect_to = '' ) {
	unset( $redirect_to );

	if ( ! ( $errors instanceof WP_Error ) ) {
		return $errors;
	}

	$codes = $errors->get_error_codes();
	if ( array() === array_intersect( $codes, keel_defaults_login_account_error_codes() ) ) {
		return $errors;
	}

	$uniform = new WP_Error();
	$added   = false;

	foreach ( $codes as $code ) {
		if ( in_array( $code, keel_defaults_login_account_error_codes(), true ) ) {
			if ( ! $added ) {
				$uniform->add( 'incorrect_password', keel_defaults_uniform_login_message() );
				$added = true;
			}
			continue;
		}

		foreach ( $errors->get_error_messages( $code ) as $message ) {
			$uniform->add( $code, $message );
		}

		$data = $errors->get_all_error_data( $code );
		foreach ( $data as $item ) {
			$uniform->add_data( $item, $code );
		}
	}

	return $uniform;
}

/**
 * Strip account-revealing errors from a failed lost-password request.
 *
 * Every account-revealing error is removed from `$errors`, in place. When
 * nothing else is left, the request is answered as a success. When another
 * plugin's error remains (a CAPTCHA, say), the form re-renders with only that
 * error, which an unknown account would have got just the same. An empty field
 * keeps core's "enter a username or email address" error.
 *
 * @param WP_Error $errors The errors wp-login.php is about to show. Modified in place.
 * @return string 'success' to answer as a sent reset link, 'show' to render
 *                what is left, or 'unchanged' when nothing revealed an account.
 */
function keel_defaults_uniform_lostpassword_outcome( WP_Error $errors ) {
	$revealing = array_intersect( $errors->get_error_codes(), keel_defaults_lostpassword_account_error_codes() );
	if ( array() === $revealing ) {
		return 'unchanged';
	}

	foreach ( $revealing as $code ) {
		$errors->remove( $code );
	}

	return $errors->has_errors() ? 'show' : 'success';
}

/**
 * Give an unknown account the same lost-password answer as a real one.
 *
 * Hooked to `lost_password`, which wp-login.php fires after `retrieve_password()`
 * has failed and before it renders the form. Nothing runs twice: the request
 * has already been handled once, by core, and mailed a reset link if there was
 * an account to mail. On a success outcome the visitor is sent where a
 * successful request goes, through the same `redirect_to` handling and
 * `wp_safe_redirect()` core uses there.
 *
 * A GET request (the form itself, or a reset-link error) is left alone.
 *
 * @param WP_Error|mixed $errors The errors wp-login.php is about to show.
 * @return void
 */
function keel_defaults_uniform_lostpassword( $errors ) {
	if ( ! ( $errors instanceof WP_Error ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}

	if ( 'success' !== keel_defaults_uniform_lostpassword_outcome( $errors ) ) {
		return;
	}

	// Core's success branch, word for word, so every input lands in the same place.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read exactly as core's success branch reads it, so the two cannot differ; wp_safe_redirect() validates it.
	$redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : 'wp-login.php?checkemail=confirm';
	wp_safe_redirect( $redirect_to );
	exit;
}
