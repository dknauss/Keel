<?php
/**
 * Email deliverability and password-reset notices.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The effective "From" address WordPress will use for site mail.
 *
 * @return string
 */
function keel_defaults_mail_from_address() {
	$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
	$sitename = $sitename ? strtolower( $sitename ) : '';

	if ( 0 === strpos( $sitename, 'www.' ) ) {
		$sitename = substr( $sitename, 4 );
	}

	$default_from = $sitename ? 'wordpress@' . $sitename : '';

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally reading core's wp_mail_from to compute the effective From address.
	return (string) apply_filters( 'wp_mail_from', $default_from );
}

/**
 * Whether the site's default From address looks undeliverable.
 *
 * @return bool
 */
function keel_defaults_mail_is_risky() {
	$email       = keel_defaults_mail_from_address();
	$domain_part = strrchr( $email, '@' );
	$domain      = $domain_part ? strtolower( substr( $domain_part, 1 ) ) : '';

	if ( ! is_email( $email ) ) {
		return true;
	}
	if ( '' === $domain || in_array( $domain, array( 'example.com', 'localhost', 'local' ), true ) ) {
		return true;
	}
	return (bool) preg_match( '/\.(local|test|invalid)$/i', $domain );
}

/**
 * Warn admins when a non-local site's default From address looks undeliverable.
 */
function keel_defaults_render_mail_config_notice() {
	/*
	 * Nothing to warn about when nothing is being sent. Uses the same resolver
	 * as the suppression itself rather than wp_get_environment_type(), which
	 * has no host fallback and would nag on a local install that never set the
	 * constant.
	 */
	if ( ! current_user_can( 'manage_options' ) || 'production' !== keel_defaults_current_environment()
		|| keel_defaults_suppresses_mail() || ! keel_defaults_mail_is_risky() ) {
		return;
	}

	$recommendation = apply_filters(
		'keel_smtp_plugin_recommendation',
		__( 'Use a trusted SMTP or transactional email plugin such as WP Mail SMTP, Post SMTP, or a host-provided mail plugin.', 'keel-defaults' )
	);
	?>
	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Site email may be misconfigured.', 'keel-defaults' ); ?></strong></p>
		<p><?php echo esc_html( $recommendation ); ?></p>
	</div>
	<?php
}

/**
 * Whether the current request is a Users-screen bulk reset that sent zero emails.
 *
 * @return bool
 */
function keel_defaults_is_zero_reset_result() {
	global $pagenow;

	if ( 'users.php' !== $pagenow ) {
		return false;
	}

	// Reading WordPress's own post-action redirect params, for display only.
	$update      = isset( $_GET['update'] ) ? sanitize_key( wp_unslash( $_GET['update'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$reset_count = isset( $_GET['reset_count'] ) ? absint( wp_unslash( $_GET['reset_count'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return 'resetpassword' === $update && 0 === $reset_count;
}

/**
 * Show an error when a bulk password reset sent zero emails.
 */
function keel_defaults_render_reset_failure_notice() {
	if ( ! keel_defaults_is_zero_reset_result() ) {
		return;
	}
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<strong><?php esc_html_e( 'No password reset links were sent.', 'keel-defaults' ); ?></strong>
			<?php esc_html_e( 'Site email may not be configured, or the selected users may not have deliverable email addresses.', 'keel-defaults' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Hide WordPress core's misleading "success" notice when zero resets were sent.
 */
function keel_defaults_hide_zero_reset_notice() {
	if ( ! keel_defaults_is_zero_reset_result() ) {
		return;
	}
	?>
	<style id="keel-zero-reset-notice-fix">
		#message.updated { display: none; }
	</style>
	<?php
}

/**
 * Say so, on screen, when outgoing mail is being suppressed.
 *
 * The setting is visible on the settings screen, which is most of the job — but
 * the switch being on and mail actually being off are different facts. The
 * setting does nothing on production, so "on" does not mean "acting". Somebody
 * requesting a password reset on staging needs to know why it never arrived,
 * and they are not going to read the settings screen to find out.
 *
 * Not dismissible: on the environments where this fires, mail being off is a
 * standing condition rather than a one-time message.
 *
 * @return void
 */
function keel_defaults_render_mail_suppressed_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! keel_defaults_suppresses_mail() ) {
		return;
	}

	?>
	<div class="notice notice-info">
		<p>
			<strong><?php esc_html_e( 'Outgoing email is switched off on this site.', 'keel-defaults' ); ?></strong>
			<?php
			printf(
				/* translators: %s: environment name, such as staging or local. */
				esc_html__( 'This is the %s environment, not production, so nothing is delivered — including password resets. WordPress still reports each message as sent, so code that depends on the result behaves as it would in production.', 'keel-defaults' ),
				'<strong>' . esc_html( keel_defaults_current_environment() ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
			);
			?>
		</p>
		<p><?php esc_html_e( 'Turn off "Non-Production Email" under Settings → Keel to send from here anyway.', 'keel-defaults' ); ?></p>
	</div>
	<?php
}

/**
 * Whether outgoing mail should be suppressed on this site.
 *
 * True when the setting is on and this is not the production environment. The
 * case it exists for is a database restored from production onto staging or a
 * laptop: it carries real customer addresses *and* whatever mail service
 * production was using. A cron run, a bulk action, or a migration routine then
 * emails real people from a copy.
 *
 * "A local site cannot send mail anyway" is not a safeguard. Measured on a
 * stock local install: the only thing preventing delivery was the default From
 * address `wordpress@localhost` being invalid — an accident of the hostname,
 * and one a production `siteurl` removes by definition. With a valid From,
 * `wp_mail()` returned true having handed the message to the local transport.
 * An SMTP plugin, which a production copy also carries, skips that question
 * entirely and connects to the real provider.
 *
 * Environment is read through `keel_defaults_current_environment()`, the same resolver
 * behind the admin-bar indicator, so the label and this behaviour cannot
 * disagree — including its host fallback, which catches a local install that
 * never set `WP_ENVIRONMENT_TYPE` and therefore reports itself to core as
 * production.
 *
 * @return bool
 */
function keel_defaults_suppresses_mail() {
	$suppress = keel_defaults_enabled( 'suppress_nonproduction_mail' )
		&& 'production' !== keel_defaults_current_environment();

	if ( defined( 'KEEL_ALLOW_NONPRODUCTION_MAIL' ) && KEEL_ALLOW_NONPRODUCTION_MAIL ) {
		$suppress = false;
	}

	/**
	 * Filter whether outgoing mail is suppressed.
	 *
	 * For the staging site that genuinely has to send — a client review round,
	 * a deliverability test against a seed list.
	 *
	 * @param bool $suppress Whether to suppress.
	 */
	return (bool) apply_filters( 'keel_suppress_nonproduction_mail', $suppress );
}

/**
 * Short-circuit `wp_mail()` without sending.
 *
 * Returns true, not false. A non-null return from `pre_wp_mail` is taken as the
 * result of the send, and callers branch on it — "we have emailed you a link",
 * an order marked notified, a queue row completed. Answering false would make a
 * staging site behave differently from production in the direction that hides
 * bugs: the failure path gets exercised and the success path never does.
 *
 * @param null|bool $pre  Short-circuit value.
 * @param array     $atts Arguments passed to wp_mail().
 * @return bool
 */
function keel_defaults_suppress_mail( $pre, $atts ) {
	unset( $pre );

	/**
	 * Fires instead of sending, so a mail catcher can still record the message.
	 *
	 * @param array $atts The wp_mail() arguments that were suppressed.
	 */
	do_action( 'keel_outgoing_mail_suppressed', $atts );

	return true;
}
