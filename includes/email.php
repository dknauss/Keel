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
	if ( ! current_user_can( 'manage_options' ) || 'local' === wp_get_environment_type() || ! keel_defaults_mail_is_risky() ) {
		return;
	}

	$recommendation = apply_filters(
		'keel_smtp_plugin_recommendation',
		__( 'Use a trusted SMTP or transactional email plugin such as WP Mail SMTP, Post SMTP, or a host-provided mail plugin.', 'keel' )
	);
	?>
	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Site email may be misconfigured.', 'keel' ); ?></strong></p>
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
			<strong><?php esc_html_e( 'No password reset links were sent.', 'keel' ); ?></strong>
			<?php esc_html_e( 'Site email may not be configured, or the selected users may not have deliverable email addresses.', 'keel' ); ?>
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
