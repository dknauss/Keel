<?php
/**
 * Deliberate installation of a patched same-line WordPress release.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

// Five minutes. Results need to survive one redirect, not become admin history.
const KEEL_DEFAULTS_BACKPORT_RESULT_TTL = 300;

/**
 * Whether this user may perform a network-wide core update.
 *
 * @return bool
 */
function keel_defaults_can_install_backport() {
	return current_user_can( 'update_core' )
		&& ( ! is_multisite() || current_user_can( 'manage_network_options' ) );
}

/**
 * Stable nonce action for one exact target version.
 *
 * @param string $version Target version.
 * @return string
 */
function keel_defaults_backport_nonce_action( $version ) {
	return sprintf( 'keel_defaults_install_backport_%s', $version );
}

/**
 * Validate request method and capabilities without touching request globals.
 *
 * @param string $method      HTTP method.
 * @param bool   $can_update  Whether the user can update core.
 * @param bool   $multisite   Whether this is a network.
 * @param bool   $can_network Whether the user can govern the network.
 * @return true|WP_Error
 */
function keel_defaults_validate_backport_request( $method, $can_update, $multisite, $can_network ) {
	if ( 'POST' !== $method ) {
		return new WP_Error( 'method_not_allowed', __( 'This action requires a POST request.', 'keel-defaults' ) );
	}

	if ( ! $can_update || ( $multisite && ! $can_network ) ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to update WordPress on this site.', 'keel-defaults' ) );
	}

	return true;
}

/**
 * Select the raw core offer for a version.
 *
 * Exact-locale offers win. Within the same locale, the automatic offer wins:
 * the version-check response can contain both a manual and automatic offer for
 * the newest release, while this route is specifically for the latter.
 *
 * @param string $version Version to find.
 * @return object|null
 */
function keel_defaults_install_offer_for_version( $version ) {
	if ( '' === $version ) {
		return null;
	}

	$updates = get_site_transient( 'update_core' );

	if ( ! is_object( $updates ) || ! isset( $updates->updates ) || ! is_array( $updates->updates ) ) {
		return null;
	}

	$locale     = get_locale();
	$candidates = array();

	foreach ( $updates->updates as $offer ) {
		if ( is_object( $offer ) && isset( $offer->current ) && $version === $offer->current ) {
			$candidates[] = $offer;
		}
	}

	usort(
		$candidates,
		static function ( $left, $right ) use ( $locale ) {
			$left_score  = ( isset( $left->locale ) && $locale === $left->locale ? 2 : 0 )
				+ ( isset( $left->response ) && 'autoupdate' === $left->response ? 1 : 0 );
			$right_score = ( isset( $right->locale ) && $locale === $right->locale ? 2 : 0 )
				+ ( isset( $right->response ) && 'autoupdate' === $right->response ? 1 : 0 );

			return $right_score <=> $left_score;
		}
	);

	return isset( $candidates[0] ) ? $candidates[0] : null;
}

/**
 * Validate that an offer has the shape Core_Upgrader reads.
 *
 * @param object|null $offer Update offer.
 * @return true|WP_Error
 */
function keel_defaults_validate_install_offer( $offer ) {
	if ( ! is_object( $offer )
		|| ! isset( $offer->current, $offer->response, $offer->packages, $offer->partial_version, $offer->new_bundled )
		|| 'autoupdate' !== $offer->response
		|| ! is_object( $offer->packages )
	) {
		return new WP_Error( 'invalid_offer', __( 'WordPress did not provide a usable automatic-update offer for this patch.', 'keel-defaults' ) );
	}

	$package_fields = array( 'partial', 'new_bundled', 'no_content', 'full', 'rollback' );

	// Core reads each of these properties directly while choosing a package.
	foreach ( $package_fields as $field ) {
		if ( ! property_exists( $offer->packages, $field ) ) {
			return new WP_Error( 'invalid_offer', __( 'The cached update offer is incomplete. Check for updates again, then retry.', 'keel-defaults' ) );
		}
	}

	// Every core offer carries a full package, and it is the upgrader's fallback
	// whenever none of the optimized packages applies.
	if ( ! is_string( $offer->packages->full ) || '' === $offer->packages->full ) {
		return new WP_Error( 'no_package', __( 'WordPress did not provide a download package for this patch.', 'keel-defaults' ) );
	}

	return true;
}

/**
 * Preflight requirements available in the update offer.
 *
 * Core's manual update UI checks the PHP and database versions from these same
 * fields. Current version-check offers do not expose required PHP extensions;
 * if that changes, or a filtered offer supplies them, check those too. Core's
 * post-unpack check remains authoritative for requirements known only to the
 * downloaded release.
 *
 * @param object $offer Update offer.
 * @return true|WP_Error
 */
function keel_defaults_check_install_compatibility( $offer ) {
	global $wpdb;

	if ( ! isset( $offer->php_version, $offer->mysql_version )
		|| ! is_string( $offer->php_version )
		|| ! is_string( $offer->mysql_version )
	) {
		return new WP_Error( 'invalid_offer', __( 'The cached update offer does not include its system requirements.', 'keel-defaults' ) );
	}

	if ( version_compare( PHP_VERSION, $offer->php_version, '<' ) ) {
		return new WP_Error(
			'php_not_compatible',
			sprintf(
				/* translators: 1: target WordPress version, 2: required PHP version, 3: installed PHP version. */
				__( 'WordPress %1$s requires PHP %2$s or newer. This site is running PHP %3$s.', 'keel-defaults' ),
				$offer->current,
				$offer->php_version,
				PHP_VERSION
			)
		);
	}

	$db_dropin  = defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) && empty( $wpdb->is_mysql );
	$db_version = is_object( $wpdb ) && is_callable( array( $wpdb, 'db_version' ) ) ? $wpdb->db_version() : '';

	if ( ! $db_dropin && ( '' === $db_version || version_compare( $db_version, $offer->mysql_version, '<' ) ) ) {
		return new WP_Error(
			'mysql_not_compatible',
			sprintf(
				/* translators: 1: target WordPress version, 2: required database version, 3: installed database version. */
				__( 'WordPress %1$s requires MySQL %2$s or newer. This site is running %3$s.', 'keel-defaults' ),
				$offer->current,
				$offer->mysql_version,
				$db_version
			)
		);
	}

	$extensions = array();
	if ( isset( $offer->required_php_extensions ) && is_array( $offer->required_php_extensions ) ) {
		$extensions = $offer->required_php_extensions;
	} elseif ( isset( $offer->php_extensions ) && is_array( $offer->php_extensions ) ) {
		$extensions = $offer->php_extensions;
	}

	$missing = array();
	foreach ( $extensions as $extension ) {
		if ( is_string( $extension ) && '' !== $extension && ! extension_loaded( $extension ) ) {
			$missing[] = $extension;
		}
	}

	if ( ! empty( $missing ) ) {
		return new WP_Error(
			'php_extensions_not_compatible',
			sprintf(
				/* translators: 1: target WordPress version, 2: comma-separated PHP extension names. */
				__( 'WordPress %1$s requires these missing PHP extensions: %2$s.', 'keel-defaults' ),
				$offer->current,
				implode( ', ', $missing )
			)
		);
	}

	return true;
}

/**
 * Validate a requested target and assemble the exact upgrader inputs.
 *
 * @param string $version Posted target version.
 * @return array{offer:object,args:array<string,bool>}|WP_Error
 */
function keel_defaults_prepare_backport_install( $version ) {
	$tip = keel_defaults_branch_tip();

	if ( '' === $tip || $version !== $tip ) {
		return new WP_Error( 'stale_target', __( 'That patch is no longer the current target for this release line. Refresh Site Health and try again.', 'keel-defaults' ) );
	}

	$state    = keel_defaults_minor_update_state();
	$codes    = keel_defaults_blocker_codes( $state['blockers'] );
	$refusing = array_intersect( $codes, array( 'file_mods', 'credentials', 'vcs' ) );

	if ( ! empty( $refusing ) ) {
		$texts = array();
		foreach ( $state['blockers'] as $blocker ) {
			if ( in_array( $blocker['code'], $refusing, true ) ) {
				$texts[] = $blocker['text'];
			}
		}

		return new WP_Error( 'filesystem_blocked', implode( '; ', $texts ) );
	}

	$offer = keel_defaults_install_offer_for_version( $version );
	$valid = keel_defaults_validate_install_offer( $offer );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$compatible = keel_defaults_check_install_compatibility( $offer );
	if ( is_wp_error( $compatible ) ) {
		return $compatible;
	}

	return array(
		'offer' => $offer,
		'args'  => array(
			'allow_relaxed_file_ownership' => keel_defaults_relaxed_ownership_allowed(),
			'attempt_rollback'             => true,
		),
	);
}

/**
 * Invoke core's upgrader with a no-output skin.
 *
 * @param array{offer:object,args:array<string,bool>} $plan Validated plan.
 * @return string|false|WP_Error
 */
function keel_defaults_run_backport_install( array $plan ) {
	$upgrader_file = ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! class_exists( 'Core_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
		require_once $upgrader_file;
	}

	$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );

	return $upgrader->upgrade( $plan['offer'], $plan['args'] );
}

/**
 * Per-user transient key for one redirect result.
 *
 * @param int $user_id User ID.
 * @return string
 */
function keel_defaults_backport_result_key( $user_id ) {
	$user_id = (int) $user_id;

	return 'keel_backport_install_result_' . $user_id;
}

/**
 * Store one redirect result, scoped to its acting user.
 *
 * @param int      $user_id User ID.
 * @param string   $code    Stable result code.
 * @param string[] $messages Messages to show.
 * @param string   $type    Notice type.
 * @return void
 */
function keel_defaults_store_backport_result( $user_id, $code, array $messages, $type = 'error' ) {
	set_transient(
		keel_defaults_backport_result_key( $user_id ),
		array(
			'code'     => $code,
			'messages' => array_values( $messages ),
			'type'     => 'success' === $type ? 'success' : 'error',
		),
		KEEL_DEFAULTS_BACKPORT_RESULT_TTL
	);
}

/**
 * Consume this user's redirect result without exposing another user's result.
 *
 * @param int $user_id User ID.
 * @return array{code:string,messages:array<int,string>,type:string}|null
 */
function keel_defaults_take_backport_result( $user_id ) {
	$key    = keel_defaults_backport_result_key( $user_id );
	$result = get_transient( $key );

	if ( ! is_array( $result ) ) {
		return null;
	}

	delete_transient( $key );

	return $result;
}

/**
 * Render and consume the acting user's install result.
 *
 * @return string Escaped notice markup.
 */
function keel_defaults_backport_result_markup() {
	$result = keel_defaults_take_backport_result( get_current_user_id() );

	if ( null === $result || empty( $result['messages'] ) ) {
		return '';
	}

	$class = 'success' === $result['type'] ? 'notice-success' : 'notice-error';
	$out   = '<div class="notice ' . esc_attr( $class ) . ' inline"><p>';
	$out  .= implode( '<br>', array_map( 'wp_kses_post', $result['messages'] ) );
	$out  .= '</p></div>';

	return $out;
}

/**
 * Build the deliberate-install form when core is not already showing it.
 *
 * @param string $version Target patch version.
 * @param string $screen  Updates-screen state.
 * @param array  $state   Minor-update state.
 * @return string
 */
function keel_defaults_backport_install_button( $version, $screen, array $state ) {
	if ( 'visible' === $screen || ! keel_defaults_can_install_backport() ) {
		return '';
	}

	$codes = keel_defaults_blocker_codes( $state['blockers'] );
	if ( array_intersect( $codes, array( 'file_mods', 'credentials', 'vcs' ) ) ) {
		return '';
	}

	$out = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="keel_defaults_install_backport">'
		. '<input type="hidden" name="version" value="' . esc_attr( $version ) . '">'
		. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( keel_defaults_backport_nonce_action( $version ) ) ) . '">'
		. '<button type="submit" class="button">'
		. sprintf(
			/* translators: %s: target WordPress version. */
			esc_html__( 'Install WordPress %s now', 'keel-defaults' ),
			esc_html( $version )
		)
		. '</button></form>';

	if ( in_array( 'previous_failure', $codes, true ) ) {
		$out .= '<p class="description">' . esc_html__( 'A previous core update failed critically. This deliberate attempt may repair that state, but back up the site first.', 'keel-defaults' ) . '</p>';
	}

	return $out;
}

/**
 * Redirect to Site Health after storing an install result.
 *
 * @param string|WP_Error|false $result Core upgrader result.
 * @param string                $version Target version.
 * @return void
 */
function keel_defaults_finish_backport_install( $result, $version ) {
	if ( is_wp_error( $result ) ) {
		keel_defaults_store_backport_result(
			get_current_user_id(),
			(string) $result->get_error_code(),
			$result->get_error_messages()
		);
	} elseif ( false === $result ) {
		keel_defaults_store_backport_result(
			get_current_user_id(),
			'filesystem_unavailable',
			array( __( 'WordPress could not access the filesystem.', 'keel-defaults' ) )
		);
	} else {
		keel_defaults_store_backport_result(
			get_current_user_id(),
			'updated',
			array(
				sprintf(
					/* translators: %s: installed WordPress version. */
					__( 'WordPress %s was installed successfully.', 'keel-defaults' ),
					$version
				),
			),
			'success'
		);
	}

	wp_safe_redirect( admin_url( 'site-health.php' ) );
	exit;
}

/**
 * Handle the deliberate same-line patch request.
 *
 * @return void
 */
function keel_defaults_handle_install_backport() {
	$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	$request = keel_defaults_validate_backport_request(
		$method,
		current_user_can( 'update_core' ),
		is_multisite(),
		current_user_can( 'manage_network_options' )
	);

	if ( is_wp_error( $request ) ) {
		if ( 'method_not_allowed' === $request->get_error_code() ) {
			wp_die( esc_html( $request->get_error_message() ), '', array( 'response' => 405 ) );
		}

		wp_die( esc_html( $request->get_error_message() ), '', array( 'response' => 403 ) );
	}

	$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
	check_admin_referer( keel_defaults_backport_nonce_action( $version ) );

	$plan = keel_defaults_prepare_backport_install( $version );
	if ( is_wp_error( $plan ) ) {
		keel_defaults_finish_backport_install( $plan, $version );
	}

	$result = keel_defaults_run_backport_install( $plan );

	keel_defaults_finish_backport_install( $result, $version );
}
add_action( 'admin_post_keel_defaults_install_backport', 'keel_defaults_handle_install_backport' );
