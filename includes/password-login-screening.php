<?php
/**
 * Re-check existing passwords against known breaches when people log in.
 *
 * Screening at the moment a password is set (password-policy.php) never sees a
 * password chosen before Keel was active, or one set while the lookup was
 * unavailable. This closes that gap without blocking anyone: a successful
 * interactive login re-checks the submitted password at most once per
 * re-screen interval, and a breached result only warns.
 *
 * What is stored, per user:
 *
 * - `keel_password_screened` — when the password last got a definite verdict.
 * - `keel_password_breached` — when it was first found in a breach, present only
 *   while that is still true.
 *
 * Both are deleted whenever the password changes, so the next login checks the
 * new one. Neither holds the password, its hash, or the hash prefix.
 *
 * Who sees what: the affected person sees a notice on every admin screen until
 * they change the password. Accounts that can list users see a count and a
 * linked list in Site Health, and a filtered view on the Users screen. Nothing
 * here is registered as REST meta, so breach status is never readable through
 * the API.
 *
 * Loaded by security.php; bootstrap.php retains hook registration.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimum time between two login re-checks of the same account, in seconds.
 *
 * Thirty days by default. Most logins therefore make no outbound request at
 * all, and a flood of logins to one account cannot spend the shared lookup
 * budget that password changes also depend on. The floor is one hour, so a
 * filter cannot turn the re-check into a lookup on every login.
 *
 * @return int
 */
function keel_defaults_password_rescreen_interval() {
	/**
	 * Filter how long a login re-check result stays valid, in seconds.
	 *
	 * @param int $interval Default 30 days.
	 */
	return max( HOUR_IN_SECONDS, (int) apply_filters( 'keel_password_rescreen_interval', 30 * DAY_IN_SECONDS ) );
}

/**
 * Hold, or hand back, the password from the login currently in progress.
 *
 * `authenticate` is the only place the plaintext is available, but it runs
 * before the login is known to have succeeded: a later filter, such as a
 * lockout or two-factor plugin, can still refuse it. So the password is held
 * here for the rest of the request and only screened from `wp_login`, which
 * fires once WordPress has actually signed the person in.
 *
 * Taking the value clears it, and it is only ever handed back for the account
 * it was captured for.
 *
 * @param int         $user_id  Account the password belongs to.
 * @param string|null $password Password to hold, or null to take the held one.
 * @return string|null The held password when taking; null otherwise.
 */
function keel_defaults_login_password_slot( $user_id, $password = null ) {
	static $slot = null;

	if ( null !== $password ) {
		$slot = array(
			'user'     => (int) $user_id,
			'password' => (string) $password,
		);
		return null;
	}

	$held = $slot;
	$slot = null;

	return ( is_array( $held ) && (int) $user_id === $held['user'] ) ? $held['password'] : null;
}

/**
 * Capture the submitted password once core has verified it. Filter on `authenticate`.
 *
 * Runs late (priority 999) so the user/password and email/password handlers have
 * already checked it. Cookie re-authentication arrives with no password and is
 * ignored. An application password is skipped explicitly; XML-RPC and REST
 * logins never reach `wp_login`, so nothing captured for them is ever screened.
 *
 * @param WP_User|WP_Error|null $user     Result so far.
 * @param string                $username Submitted username (unused).
 * @param mixed                 $password Submitted password; not always a string.
 * @return WP_User|WP_Error|null Unchanged.
 */
function keel_defaults_capture_login_password( $user, $username, $password ) {
	unset( $username );

	if ( $user instanceof WP_User
		&& is_string( $password )
		&& '' !== $password
		&& strlen( $password ) <= 4096
		&& ! did_action( 'application_password_did_authenticate' ) ) {
		keel_defaults_login_password_slot( $user->ID, $password );
	}

	return $user;
}

/**
 * Screen the password of a login that has just succeeded. Action on `wp_login`.
 *
 * @param string       $user_login User login (unused).
 * @param WP_User|null $user       The signed-in account.
 * @return void
 */
function keel_defaults_screen_login_password( $user_login, $user = null ) {
	unset( $user_login );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	$password = keel_defaults_login_password_slot( $user->ID );

	if ( null !== $password ) {
		keel_defaults_screen_user_password( $user, $password );
	}
}

/**
 * Re-check one account's current password and record the verdict.
 *
 * Every role is checked, like screening at the moment a password is set. A
 * site can leave roles out with the keel_password_rescreen_skip_roles filter;
 * see keel_defaults_password_rescreen_skipped().
 *
 * An unknown verdict records nothing, so the account is tried again at its next
 * login instead of being treated as checked for a whole interval.
 *
 * @param WP_User  $user     Account whose password this is.
 * @param string   $password The password just used to log in.
 * @param int|null $now      Current time, for tests.
 * @return string One of skipped, fresh, unknown, breached or clean.
 */
function keel_defaults_screen_user_password( $user, $password, $now = null ) {
	$now = null === $now ? time() : (int) $now;

	if ( keel_defaults_password_rescreen_skipped( $user ) ) {
		return 'skipped';
	}

	// A timestamp in the future (a clock moved back, a copied database) is stale.
	$last = (int) get_user_meta( $user->ID, 'keel_password_screened', true );
	if ( $last > 0 && $last <= $now && $now - $last < keel_defaults_password_rescreen_interval() ) {
		return 'fresh';
	}

	$verdict = keel_hibp_password_verdict( $password );

	if ( null === $verdict ) {
		return 'unknown';
	}

	update_user_meta( $user->ID, 'keel_password_screened', $now );

	if ( $verdict ) {
		// Keep the first sighting: re-checks of the same password do not reset it.
		if ( ! keel_defaults_password_breached_at( $user->ID ) ) {
			update_user_meta( $user->ID, 'keel_password_breached', $now );
		}
		return 'breached';
	}

	delete_user_meta( $user->ID, 'keel_password_breached' );
	return 'clean';
}

/**
 * Whether an account is left out of the login re-check.
 *
 * Nobody is, by default. A large membership or shop site may not want every
 * customer login to spend the shared lookup budget that password changes also
 * rely on, so the keel_password_rescreen_skip_roles filter can name roles to
 * leave out:
 *
 *     add_filter( 'keel_password_rescreen_skip_roles', fn() => array( 'subscriber' ) );
 *
 * Like the password policy exemptions, an account is skipped only when every
 * role it holds is listed, so a subscriber who is also an editor is still
 * checked. Skipped accounts are still screened whenever they set a password.
 *
 * @param WP_User|object $user Account.
 * @return bool
 */
function keel_defaults_password_rescreen_skipped( $user ) {
	/**
	 * Filter the roles left out of the login breach re-check.
	 *
	 * @param string[] $roles Role slugs. Default empty: every role is checked.
	 */
	$skip  = array_map( 'strval', (array) apply_filters( 'keel_password_rescreen_skip_roles', array() ) );
	$roles = ( isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();

	if ( empty( $skip ) || empty( $roles ) ) {
		return false;
	}

	foreach ( $roles as $role ) {
		if ( ! in_array( (string) $role, $skip, true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * When an account's password was found in a breach, or 0 when it is not flagged.
 *
 * @param int $user_id User ID.
 * @return int Unix timestamp, or 0.
 */
function keel_defaults_password_breached_at( $user_id ) {
	$value = get_user_meta( (int) $user_id, 'keel_password_breached', true );
	return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
}

/**
 * Forget an account's screening state, so its next login checks afresh.
 *
 * @param int $user_id User ID.
 * @return void
 */
function keel_defaults_forget_password_screening( $user_id ) {
	delete_user_meta( (int) $user_id, 'keel_password_breached' );
	delete_user_meta( (int) $user_id, 'keel_password_screened' );
}

/**
 * Clear the flag when a password is set directly. Action on `wp_set_password`.
 *
 * Covers the reset screen, `wp_set_password()` callers and WP-CLI.
 *
 * @param string $password New password (unused).
 * @param int    $user_id  User ID.
 * @return void
 */
function keel_defaults_password_set_forgets_screening( $password, $user_id ) {
	unset( $password );
	keel_defaults_forget_password_screening( $user_id );
}

/**
 * Clear the flag when a profile save changes the password. Action on `profile_update`.
 *
 * `wp_update_user()` stores a new hash without calling `wp_set_password()`, so
 * the profile screen and the REST users endpoint are only visible here. Any
 * other profile change leaves the flag alone.
 *
 * @param int                 $user_id       User ID.
 * @param WP_User|object|null $old_user_data The account before the update.
 * @param mixed               $userdata      The data that was saved (WP 5.8+).
 * @return void
 */
function keel_defaults_profile_update_forgets_screening( $user_id, $old_user_data = null, $userdata = array() ) {
	if ( ! is_object( $old_user_data ) || ! isset( $old_user_data->user_pass ) ) {
		return;
	}

	if ( is_array( $userdata ) && isset( $userdata['user_pass'] ) ) {
		$new_hash = (string) $userdata['user_pass'];
	} else {
		$current  = get_userdata( (int) $user_id );
		$new_hash = $current ? (string) $current->user_pass : '';
	}

	if ( '' !== $new_hash && (string) $old_user_data->user_pass !== $new_hash ) {
		keel_defaults_forget_password_screening( $user_id );
	}
}

/**
 * Warn the signed-in person that their own password is breached.
 *
 * Shown only to the account it is about, on every admin screen, and not
 * dismissible: the way to clear it is to change the password, which removes the
 * flag. The login itself is never blocked.
 *
 * @return void
 */
function keel_defaults_render_breached_password_notice() {
	$user_id = get_current_user_id();

	if ( ! $user_id || ! keel_defaults_password_breached_at( $user_id ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning keel-breached-password-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
		esc_html__( 'Your password has appeared in a known data breach.', 'keel-defaults' ),
		esc_html__( 'It still works here, but leaked passwords are the first ones attackers try. Choose a new one that you do not use anywhere else.', 'keel-defaults' ),
		esc_url( get_edit_profile_url( $user_id ) . '#password' ),
		esc_html__( 'Change your password', 'keel-defaults' )
	);
}

/**
 * Accounts on this site whose passwords are flagged, most recently flagged first.
 *
 * @param int $limit Maximum number of accounts to return.
 * @return array{total:int,users:WP_User[]}
 */
function keel_defaults_breached_users( $limit = 10 ) {
	$query = new WP_User_Query(
		array(
			'meta_key'    => 'keel_password_breached', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin report; the key exists only on flagged accounts.
			'orderby'     => 'meta_value_num',
			'order'       => 'DESC',
			'number'      => max( 1, (int) $limit ),
			'count_total' => true,
		)
	);

	return array(
		'total' => (int) $query->get_total(),
		'users' => (array) $query->get_results(),
	);
}

/**
 * How many accounts on this site have had a login re-check complete.
 *
 * @return int
 */
function keel_defaults_screened_user_count() {
	$query = new WP_User_Query(
		array(
			'meta_key'    => 'keel_password_screened', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One bounded count for Site Health.
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => true,
		)
	);

	return (int) $query->get_total();
}

/**
 * The Users screen URL that lists only flagged accounts.
 *
 * @return string Unescaped URL.
 */
function keel_defaults_breached_users_url() {
	return add_query_arg( 'keel_password', 'breached', admin_url( 'users.php' ) );
}

/**
 * Whether the current Users screen request asks for flagged accounts only.
 *
 * A read-only list filter, so it carries no nonce, the same as core's own role
 * views. It needs `list_users`, which users.php already requires.
 *
 * @return bool
 */
function keel_defaults_breached_users_requested() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter, like core's role views.
	$value = isset( $_GET['keel_password'] ) ? sanitize_key( wp_unslash( $_GET['keel_password'] ) ) : '';

	return 'breached' === $value && current_user_can( 'list_users' );
}

/**
 * Add a "Breached passwords" view to the Users screen. Filter on `views_users`.
 *
 * It appears only while at least one account is flagged, or while it is the
 * view being shown, so a clean site sees no change.
 *
 * @param array<string, string> $views Existing view links.
 * @return array<string, string>
 */
function keel_defaults_breached_users_view( $views ) {
	if ( ! current_user_can( 'list_users' ) ) {
		return $views;
	}

	$current = keel_defaults_breached_users_requested();
	$total   = keel_defaults_breached_users( 1 )['total'];

	if ( ! $total && ! $current ) {
		return $views;
	}

	$attributes = '';
	if ( $current ) {
		// Core marks "All" as current whenever no role is chosen; only one view is.
		foreach ( $views as $key => $view ) {
			$views[ $key ] = str_replace( array( ' class="current" aria-current="page"', 'class="current" aria-current="page"' ), '', $view );
		}
		$attributes = ' class="current" aria-current="page"';
	}

	$views['keel_breached'] = sprintf(
		'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
		esc_url( keel_defaults_breached_users_url() ),
		$attributes,
		esc_html__( 'Breached passwords', 'keel-defaults' ),
		esc_html( number_format_i18n( $total ) )
	);

	return $views;
}

/**
 * Limit the Users list table to flagged accounts. Action on `pre_get_users`.
 *
 * Only the list table's own query is changed. It is the one asking for
 * `all_with_meta`, which other user queries on the same screen do not.
 *
 * @param WP_User_Query $query The query about to run.
 * @return void
 */
function keel_defaults_filter_breached_users( $query ) {
	global $pagenow;

	if ( ! is_admin() || 'users.php' !== $pagenow || 'all_with_meta' !== $query->get( 'fields' ) || ! keel_defaults_breached_users_requested() ) {
		return;
	}

	$query->set( 'meta_key', 'keel_password_breached' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded, paginated admin list.

	// Newest first unless the person sorted by a column themselves.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort parameter.
	if ( empty( $_GET['orderby'] ) ) {
		$query->set( 'orderby', 'meta_value_num' );
		$query->set( 'order', 'DESC' );
	}
}

/**
 * Add a "Breach found" column while the flagged view is shown. Filter on `manage_users_columns`.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function keel_defaults_breached_users_columns( $columns ) {
	if ( keel_defaults_breached_users_requested() ) {
		$columns['keel_password_breached'] = __( 'Breach found', 'keel-defaults' );
	}
	return $columns;
}

/**
 * Render the "Breach found" cell. Filter on `manage_users_custom_column`.
 *
 * @param string $value   Current cell value.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function keel_defaults_render_breached_users_column( $value, $column, $user_id ) {
	if ( 'keel_password_breached' !== $column ) {
		return $value;
	}

	$at = keel_defaults_password_breached_at( $user_id );

	return $at ? keel_defaults_breach_time_html( $at ) : '&mdash;';
}

/**
 * A flagged time as an accessible, machine-readable "N days ago".
 *
 * @param int $timestamp Unix timestamp.
 * @return string HTML.
 */
function keel_defaults_breach_time_html( $timestamp ) {
	return sprintf(
		'<time datetime="%1$s" title="%2$s">%3$s</time>',
		esc_attr( gmdate( 'c', (int) $timestamp ) ),
		esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp ) ),
		esc_html(
			sprintf(
				/* translators: %s: human-readable time, such as "5 mins". */
				__( '%s ago', 'keel-defaults' ),
				human_time_diff( (int) $timestamp )
			)
		)
	);
}

/**
 * The re-screen interval in words: whole days where it is days, else core's wording.
 *
 * Core's human_time_diff() rounds 30 days to "1 month", which reads badly
 * after "at most once every".
 *
 * @return string Unescaped text, such as "30 days".
 */
function keel_defaults_rescreen_interval_text() {
	$interval = keel_defaults_password_rescreen_interval();

	if ( $interval >= DAY_IN_SECONDS ) {
		$days = (int) round( $interval / DAY_IN_SECONDS );
		/* translators: %s: number of days. */
		return sprintf( _n( '%s day', '%s days', $days, 'keel-defaults' ), number_format_i18n( $days ) );
	}

	return human_time_diff( 0, $interval );
}

/**
 * Translated role names for an account, comma-separated.
 *
 * @param WP_User $user Account.
 * @return string Unescaped text.
 */
function keel_defaults_breached_user_roles( $user ) {
	$names = array();
	$roles = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();

	foreach ( (array) $user->roles as $role ) {
		$name    = isset( $roles[ $role ]['name'] ) ? $roles[ $role ]['name'] : $role;
		$names[] = translate_user_role( $name );
	}

	return $names ? implode( ', ', $names ) : __( 'No role on this site', 'keel-defaults' );
}

/**
 * The table of flagged accounts shown in Site Health.
 *
 * Each row links to the profile, where a new password can be set and other
 * sessions ended, and to core's own "Send password reset" action, nonced the
 * way the Users screen nonces it. Links appear only where the viewer may edit
 * that account, and the reset link is never offered for the viewer's own
 * account, matching core's row actions.
 *
 * @param WP_User[] $users Flagged accounts.
 * @return string HTML.
 */
function keel_defaults_breached_users_table( $users ) {
	if ( empty( $users ) ) {
		return '';
	}

	$caption = __( 'Accounts with breached passwords, most recently found first', 'keel-defaults' );
	$html    = '<div role="region" tabindex="0" aria-label="' . esc_attr( $caption ) . '" style="max-width:100%;overflow-x:auto"><table class="widefat striped keel-breached-accounts" style="min-width:560px"><caption>' . esc_html( $caption ) . '</caption><thead><tr>';

	foreach ( array( __( 'Account', 'keel-defaults' ), __( 'Role', 'keel-defaults' ), __( 'Breach found', 'keel-defaults' ), __( 'Actions', 'keel-defaults' ) ) as $heading ) {
		$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';

	foreach ( $users as $user ) {
		$can_edit = current_user_can( 'edit_user', $user->ID );
		$name     = esc_html( '' !== (string) $user->display_name ? $user->display_name : $user->user_login );
		$account  = $can_edit ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . $name . '</a>' : $name;
		$account .= ' <span style="color:#646970;font-weight:400">(' . esc_html( $user->user_login ) . ')</span>';

		$actions = array();
		if ( $can_edit ) {
			$actions[] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( get_edit_user_link( $user->ID ) . '#password' ),
				/* translators: %s: account display name. */
				esc_attr( sprintf( __( 'Set a new password for %s', 'keel-defaults' ), $user->display_name ) ),
				esc_html__( 'Set new password', 'keel-defaults' )
			);
			if ( get_current_user_id() !== (int) $user->ID ) {
				$actions[] = sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( wp_nonce_url( admin_url( 'users.php?action=resetpassword&users=' . (int) $user->ID ), 'bulk-users' ) ),
					/* translators: %s: account display name. */
					esc_attr( sprintf( __( 'Send password reset to %s', 'keel-defaults' ), $user->display_name ) ),
					esc_html__( 'Send password reset', 'keel-defaults' )
				);
			}
		}

		$html .= '<tr><th scope="row" style="font-weight:600;overflow-wrap:anywhere">' . $account . '</th>'
			. '<td>' . esc_html( keel_defaults_breached_user_roles( $user ) ) . '</td>'
			. '<td>' . keel_defaults_breach_time_html( keel_defaults_password_breached_at( $user->ID ) ) . '</td>'
			. '<td>' . ( $actions ? implode( ' | ', $actions ) : '&mdash;' ) . '</td></tr>';
	}

	return $html . '</tbody></table></div>';
}

/**
 * Site Health: which accounts are logging in with breached passwords.
 *
 * Registered only while Password Strength is on. The count, the names and the
 * links need `list_users` as well as Site Health access, so an account that can
 * read Site Health but not list users learns nothing about anyone's password.
 * Any flagged account that can manage options makes this critical.
 *
 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string} Site Health result.
 */
function keel_defaults_site_health_breached_passwords() {
	$result = array(
		'label'       => __( 'No accounts are flagged for breached passwords', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Keel', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_defaults_breached_passwords',
	);

	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		return $result;
	}

	if ( ! current_user_can( 'list_users' ) ) {
		$result['label']       = __( 'Passwords are re-checked for breaches at login', 'keel-defaults' );
		$result['description'] = '<p>' . esc_html__( 'An account that can list users can review which accounts, if any, are flagged.', 'keel-defaults' ) . '</p>';
		return $result;
	}

	$found    = keel_defaults_breached_users( 10 );
	$total    = $found['total'];
	$screened = keel_defaults_screened_user_count();

	$intro = '<p>' . sprintf(
		/* translators: 1: re-check interval, such as "30 days". 2: number of accounts checked so far. */
		esc_html( _n( 'When someone logs in, Keel checks their password against known breaches, at most once every %1$s. So far %2$s account on this site has been checked this way.', 'When someone logs in, Keel checks their password against known breaches, at most once every %1$s. So far %2$s accounts on this site have been checked this way.', $screened, 'keel-defaults' ) ),
		esc_html( keel_defaults_rescreen_interval_text() ),
		esc_html( number_format_i18n( $screened ) )
	) . '</p>';

	if ( ! $total ) {
		$result['description'] = $intro . '<p>' . esc_html__( 'No account on this site is flagged. Accounts that have not logged in since the check began, and any roles a site has left out of the login check, have not been re-checked; their passwords are still screened whenever they are changed.', 'keel-defaults' ) . '</p>';
		return $result;
	}

	$privileged = false;
	foreach ( $found['users'] as $user ) {
		if ( user_can( $user, 'manage_options' ) ) {
			$privileged = true;
			break;
		}
	}

	$result['status'] = $privileged ? 'critical' : 'recommended';
	$result['label']  = sprintf(
		/* translators: %s: number of accounts. */
		_n( '%s account has a password found in a known breach', '%s accounts have passwords found in known breaches', $total, 'keel-defaults' ),
		number_format_i18n( $total )
	);

	$description = $intro
		. '<p>' . esc_html__( 'These passwords still work: Keel warns and does not block. Each person sees a notice asking them to change their password, and the flag clears as soon as they do.', 'keel-defaults' ) . '</p>'
		. '<p>' . esc_html__( 'To act sooner, set a new password on the account, which also lets you log it out everywhere else, or send a password reset. A reset email sends a link; the current password keeps working until it is used.', 'keel-defaults' ) . '</p>'
		. keel_defaults_breached_users_table( $found['users'] );

	if ( $total > count( $found['users'] ) ) {
		$description .= '<p>' . sprintf(
			/* translators: 1: number of accounts shown, 2: total number of flagged accounts. */
			esc_html__( 'Showing the %1$s most recently flagged of %2$s accounts.', 'keel-defaults' ),
			esc_html( number_format_i18n( count( $found['users'] ) ) ),
			esc_html( number_format_i18n( $total ) )
		) . '</p>';
	}

	$result['description'] = $description;
	$result['actions']     = '<p><a href="' . esc_url( keel_defaults_breached_users_url() ) . '">' . esc_html__( 'Review flagged accounts on the Users screen', 'keel-defaults' ) . '</a></p>'
		. '<p>' . esc_html__( 'There, select the flagged accounts and choose Send password reset from Bulk actions to email them all at once.', 'keel-defaults' ) . '</p>';

	return $result;
}
