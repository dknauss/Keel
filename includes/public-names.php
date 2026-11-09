<?php
/**
 * Public names: keep logins and email addresses out of display names,
 * nicknames and author slugs (#355, Enforced tier).
 *
 * Two parts, both opt-in.
 *
 * 1. Enforcement. With `protect_public_names` on, every account create or update
 *    that goes through wp_insert_user() — registration, Add New, profile screens,
 *    REST, WP-CLI, plugins that provision customers — gets a safe display name,
 *    nickname and slug. A value is replaced only when it repeats the login, the
 *    email address or its local part, or holds an email address; anything a
 *    person chose that is already safe is kept. Logins, email addresses and
 *    passwords are never touched.
 *
 * 2. A one-time cleanup of existing accounts, run only by an explicit,
 *    nonce-checked action from Users → Public Names (Network Admin → Users on
 *    multisite, Super Admins only). It walks the members in ID order in short
 *    batches that can be resumed, and only rewrites past comments when a
 *    separate checkbox is ticked.
 *
 * Old author slugs are not redirected: a redirect from an old slug would
 * confirm that an account with that email address exists (ASVS 6.3.8).
 *
 * On multisite there is one user table, so a per-site switch would make the
 * result depend on which dashboard someone saved from. Network policy decides,
 * otherwise the main site's setting does, the same rule Keel uses for shared
 * plugin and theme files.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/** Rows read per cleanup query. */
const KEEL_PUBLIC_NAMES_BATCH = 500;

/** Seconds of work per cleanup request before it saves its place and returns. */
const KEEL_PUBLIC_NAMES_BUDGET = 4;

/** Option (site option on multisite) holding the cleanup's progress. */
const KEEL_PUBLIC_NAMES_RUN = 'keel_public_names_run';

add_filter( 'wp_pre_insert_user_data', 'keel_defaults_public_names_filter_user', 20, 4 );
add_filter( 'insert_user_meta', 'keel_defaults_public_names_filter_meta', 20, 4 );
add_action( 'admin_menu', 'keel_defaults_public_names_menu' );
add_action( 'network_admin_menu', 'keel_defaults_public_names_menu' );
add_action( 'admin_post_keel_public_names', 'keel_defaults_public_names_post' );
add_action( 'wp_ajax_keel_public_names', 'keel_defaults_public_names_ajax' );

/**
 * Whether enforcement is on for writes made in this request.
 *
 * @return bool
 */
function keel_defaults_public_names_enforced() {
	return 'yes' === keel_defaults_public_names_policy();
}

/**
 * The effective setting. On multisite: network policy, else the main site's
 * stored choice, so every site's dashboard enforces the same thing.
 *
 * @return string 'yes' or 'no'.
 */
function keel_defaults_public_names_policy() {
	if ( ! is_multisite() || is_main_site() ) {
		return keel_defaults_enabled( 'protect_public_names' ) ? 'yes' : 'no';
	}
	$network = keel_defaults_network_settings();
	if ( array_key_exists( 'protect_public_names', $network ) ) {
		return 'yes' === $network['protect_public_names'] ? 'yes' : 'no';
	}
	$stored = get_blog_option( get_main_site_id(), KEEL_DEFAULTS_OPTION, array() );
	return is_array( $stored ) && 'yes' === ( $stored['protect_public_names'] ?? 'no' ) ? 'yes' : 'no';
}

/**
 * The private values a public field must not repeat: the login, the email
 * address and the local part of each address, lower-cased.
 *
 * @param string $login Login.
 * @param string $email Contact email.
 * @return string[] Lower-cased values, non-empty, unique.
 */
function keel_defaults_public_names_secrets( $login, $email ) {
	$values = array();
	foreach ( array( (string) $login, (string) $email ) as $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			continue;
		}
		$values[] = $value;
		if ( keel_defaults_identity_is_email( $value ) ) {
			$local = substr( $value, 0, strrpos( $value, '@' ) );
			if ( '' !== $local ) {
				$values[] = $local;
			}
		}
	}
	$lower = array();
	foreach ( $values as $value ) {
		$lower[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}
	return array_values( array_unique( $lower ) );
}

/**
 * Whether a display name or nickname is safe to publish.
 *
 * Unsafe: empty, equal (ignoring case) to the login, the email or a local
 * part, or holding an email address anywhere. An at-sign that is not part of
 * an address ("Studio @ Night") is fine.
 *
 * @param string $name  Candidate.
 * @param string $login Login.
 * @param string $email Contact email.
 * @return bool
 */
function keel_defaults_public_name_is_safe( $name, $login, $email ) {
	$name = trim( (string) $name );
	if ( '' === $name || keel_defaults_identity_has_email_token( $name ) ) {
		return false;
	}
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
	return ! in_array( $lower, keel_defaults_public_names_secrets( $login, $email ), true );
}

/**
 * Whether an author slug is safe to publish: not core's slug of the login, the
 * email or a local part, with or without core's -N collision suffix.
 *
 * @param string $slug  Candidate nicename.
 * @param string $login Login.
 * @param string $email Contact email.
 * @return bool
 */
function keel_defaults_public_slug_is_safe( $slug, $login, $email ) {
	$slug = (string) $slug;
	if ( '' === $slug ) {
		return false;
	}
	foreach ( keel_defaults_public_names_secrets( $login, $email ) as $secret ) {
		if ( keel_defaults_identity_slug_from( $slug, $secret ) ) {
			return false;
		}
	}
	return true;
}

/**
 * A neutral display name that says nothing about the account: not built from
 * the login, the email or the user ID.
 *
 * @return string
 */
function keel_defaults_public_names_handle() {
	/* translators: %s: six random letters and digits, e.g. "3f9a1c". */
	return sprintf( __( 'Member %s', 'keel-defaults' ), substr( bin2hex( random_bytes( 3 ) ), 0, 6 ) );
}

/**
 * An opaque slug: ten lowercase hex characters.
 *
 * @return string
 */
function keel_defaults_public_names_token() {
	return bin2hex( random_bytes( 5 ) );
}

/**
 * The display name to store. Keeps the current one when it is safe, otherwise
 * the first safe of: nickname, first and last name, first name, last name, the
 * account's previous display name (on update), a neutral handle.
 *
 * @param array $fields display_name, nickname, first_name, last_name, user_login, user_email, and optionally previous.
 * @return string
 */
function keel_defaults_public_names_display( array $fields ) {
	$login = (string) ( $fields['user_login'] ?? '' );
	$email = (string) ( $fields['user_email'] ?? '' );
	if ( keel_defaults_public_name_is_safe( $fields['display_name'] ?? '', $login, $email ) ) {
		return (string) $fields['display_name'];
	}
	$first = trim( (string) ( $fields['first_name'] ?? '' ) );
	$last  = trim( (string) ( $fields['last_name'] ?? '' ) );
	$full  = '' !== $first && '' !== $last
		// Core's own translation of its "first last" format, so name order follows the locale.
		? sprintf( translate_with_gettext_context( '%1$s %2$s', 'Display name based on first name and last name', 'default' ), $first, $last ) // phpcs:ignore WordPress.WP.I18n -- core's string, read from core's catalog on purpose.
		: '';
	foreach ( array( $fields['nickname'] ?? '', $full, $first, $last, $fields['previous'] ?? '' ) as $candidate ) {
		if ( keel_defaults_public_name_is_safe( $candidate, $login, $email ) ) {
			return trim( (string) $candidate );
		}
	}
	return keel_defaults_public_names_handle();
}

/**
 * The slug to store. Keeps the current one when it is safe, otherwise the slug
 * of the (safe) display name, otherwise an opaque token. The caller makes it
 * unique.
 *
 * @param string $slug    Current nicename.
 * @param string $display Safe display name.
 * @param string $login   Login.
 * @param string $email   Contact email.
 * @return string
 */
function keel_defaults_public_names_slug( $slug, $display, $login, $email ) {
	if ( keel_defaults_public_slug_is_safe( $slug, $login, $email ) ) {
		return (string) $slug;
	}
	$base = trim( mb_substr( (string) sanitize_title( $display ), 0, 50 ), '-' );
	if ( '' !== $base && keel_defaults_public_slug_is_safe( $base, $login, $email ) ) {
		return $base;
	}
	return keel_defaults_public_names_token();
}

/**
 * Make a replacement slug unique the way core does, excluding the account's own
 * row, and fall back to a token if core's suffix would make it unsafe.
 *
 * @param string $slug    Candidate.
 * @param int    $user_id Account being saved, 0 when new.
 * @param string $login   Login.
 * @param string $email   Contact email.
 * @return string
 */
function keel_defaults_public_names_unique_slug( $slug, $user_id, $login, $email ) {
	global $wpdb;
	$taken = static function ( $candidate ) use ( $wpdb, $user_id, $login ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the same uniqueness check core runs in wp_insert_user().
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s AND ID != %d AND user_login != %s LIMIT 1", $candidate, (int) $user_id, (string) $login ) );
	};
	for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		$candidate = $slug;
		$suffix    = 2;
		while ( $taken( $candidate ) ) {
			$candidate = mb_substr( $slug, 0, 49 - strlen( (string) $suffix ) ) . '-' . $suffix;
			++$suffix;
		}
		if ( keel_defaults_public_slug_is_safe( $candidate, $login, $email ) ) {
			return $candidate;
		}
		$slug = keel_defaults_public_names_token();
	}
	return $slug;
}

/**
 * Enforcement on the users-table fields: display name and slug.
 *
 * @param array    $data     Users-table values about to be saved.
 * @param bool     $update   Whether this is an update.
 * @param int|null $user_id  Account ID on update.
 * @param array    $userdata Raw wp_insert_user() input.
 * @return array
 */
function keel_defaults_public_names_filter_user( $data, $update, $user_id, $userdata ) {
	if ( ! is_array( $data ) || ! keel_defaults_public_names_enforced() ) {
		return $data;
	}
	$login  = (string) ( $data['user_login'] ?? '' );
	$stored = $update ? get_userdata( (int) $user_id ) : false;
	if ( $stored ) {
		// Core leaves the login out of $data on update; read the stored one.
		$login = (string) $stored->user_login;
	}
	$email    = (string) ( $data['user_email'] ?? '' );
	$userdata = is_array( $userdata ) ? $userdata : array();
	$nickname = ! empty( $userdata['nickname'] ) ? (string) $userdata['nickname'] : $login;

	// On update, an unsafe submission falls back through the person's own names
	// and then to the name the account already had, before any generated handle.
	$display = keel_defaults_public_names_display(
		array(
			'previous'     => $stored ? (string) $stored->display_name : '',
			'display_name' => (string) ( $data['display_name'] ?? '' ),
			'nickname'     => $nickname,
			'first_name'   => $userdata['first_name'] ?? '',
			'last_name'    => $userdata['last_name'] ?? '',
			'user_login'   => $login,
			'user_email'   => $email,
		)
	);
	$slug    = (string) ( $data['user_nicename'] ?? '' );
	if ( $stored && empty( $userdata['user_nicename'] ) && ! keel_defaults_public_slug_is_safe( $slug, $login, $email ) && keel_defaults_public_slug_is_safe( $stored->user_nicename, $login, $email ) ) {
		$slug = (string) $stored->user_nicename;
	}
	if ( ! keel_defaults_public_slug_is_safe( $slug, $login, $email ) ) {
		$slug = keel_defaults_public_names_unique_slug(
			keel_defaults_public_names_slug( $slug, $display, $login, $email ),
			$update ? (int) $user_id : 0,
			$login,
			$email
		);
	}

	$data['display_name']  = $display;
	$data['user_nicename'] = $slug;
	keel_defaults_public_names_chosen( $login, $display );
	return $data;
}

/**
 * Enforcement on the nickname, which core stores as user meta after the row.
 *
 * @param array   $meta     Core user meta about to be saved.
 * @param WP_User $user     Saved account.
 * @param bool    $update   Whether this is an update.
 * @param array   $userdata Raw wp_insert_user() input.
 * @return array
 */
function keel_defaults_public_names_filter_meta( $meta, $user, $update, $userdata ) {
	unset( $update, $userdata );
	if ( ! is_array( $meta ) || ! ( $user instanceof WP_User ) || ! keel_defaults_public_names_enforced() ) {
		return $meta;
	}
	if ( ! keel_defaults_public_name_is_safe( $meta['nickname'] ?? '', $user->user_login, $user->user_email ) ) {
		// The display name chosen moments ago in this save; the object core passes
		// here can still hold the old cached row.
		$display          = keel_defaults_public_names_chosen( $user->user_login );
		$meta['nickname'] = keel_defaults_public_name_is_safe( $display, $user->user_login, $user->user_email )
			? $display
			: keel_defaults_public_names_handle();
	}
	return $meta;
}

/**
 * Remember, for the rest of this request, the display name enforcement chose
 * for a login; or read it back.
 *
 * @param string      $login   Login.
 * @param string|null $display Name to remember, or null to read.
 * @return string
 */
function keel_defaults_public_names_chosen( $login, $display = null ) {
	static $chosen = array();
	if ( null !== $display ) {
		$chosen[ (string) $login ] = (string) $display;
	}
	return $chosen[ (string) $login ] ?? '';
}

/*
 * ----- One-time cleanup ------------------------------------------------------
 */

/**
 * Whether the current user may run the cleanup.
 *
 * @return bool
 */
function keel_defaults_public_names_can_run() {
	if ( is_multisite() ) {
		return current_user_can( 'manage_network_users' );
	}
	return current_user_can( 'edit_users' ) && current_user_can( 'manage_options' );
}

/**
 * Stored cleanup progress, or null.
 *
 * @return array|null
 */
function keel_defaults_public_names_state() {
	$state = is_multisite() ? get_site_option( KEEL_PUBLIC_NAMES_RUN ) : get_option( KEEL_PUBLIC_NAMES_RUN );
	return is_array( $state ) && isset( $state['cursor'], $state['target'] ) ? $state : null;
}

/**
 * Save cleanup progress.
 *
 * @param array $state Progress.
 * @return void
 */
function keel_defaults_public_names_save_state( array $state ) {
	if ( is_multisite() ) {
		update_site_option( KEEL_PUBLIC_NAMES_RUN, $state );
	} else {
		update_option( KEEL_PUBLIC_NAMES_RUN, $state, false );
	}
}

/**
 * Register the cleanup screen: Users → Public Names on a single site, Network
 * Admin → Users → Public Names on multisite.
 *
 * @return void
 */
function keel_defaults_public_names_menu() {
	if ( is_multisite() !== is_network_admin() ) {
		return;
	}
	add_submenu_page(
		'users.php',
		__( 'Public Names', 'keel-defaults' ),
		__( 'Public Names', 'keel-defaults' ),
		is_multisite() ? 'manage_network_users' : 'edit_users',
		'keel-public-names',
		'keel_defaults_public_names_render'
	);
}

/**
 * Sites a Super Admin can target, for the select.
 *
 * @return array<int,string> Blog ID => label.
 */
function keel_defaults_public_names_sites() {
	$sites = array();
	foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
		$details = get_blog_details( (int) $site->blog_id );
		$name    = $details && '' !== $details->blogname ? $details->blogname : $site->domain . $site->path;
		/* translators: 1: site name, 2: site address. */
		$sites[ (int) $site->blog_id ] = sprintf( __( '%1$s (%2$s)', 'keel-defaults' ), $name, untrailingslashit( $site->domain . $site->path ) );
	}
	return $sites;
}

/**
 * The cleanup screen. Rendering it changes nothing.
 *
 * @return void
 */
function keel_defaults_public_names_render() {
	if ( ! keel_defaults_public_names_can_run() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'keel-defaults' ), 403 );
	}
	$state  = keel_defaults_public_names_state();
	$action = admin_url( 'admin-post.php' );
	$status = isset( $_GET['keel-public-names'] ) ? sanitize_key( wp_unslash( $_GET['keel-public-names'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after a redirect.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Public Names', 'keel-defaults' ); ?></h1>
		<p><?php esc_html_e( 'This cleanup gives existing accounts safe public names. A display name, nickname or author slug that repeats the login, the email address or its local part is replaced: by the nickname, then first and last name, then a neutral handle; slugs follow the new name or become a random token. Names that are already safe are kept. Logins, email addresses and passwords do not change, so everyone signs in as before.', 'keel-defaults' ); ?></p>
		<p><?php esc_html_e( 'Old author addresses are not redirected to the new ones: a redirect would confirm which email address belongs to an account. Links to an old author address stop working.', 'keel-defaults' ); ?></p>
		<?php if ( 'refused' === $status ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'The cleanup did not run. Reload this page and try again.', 'keel-defaults' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $state ) : ?>
			<?php keel_defaults_public_names_render_state( $state ); ?>
		<?php endif; ?>
		<?php if ( ! $state || ! empty( $state['done'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( $action ); ?>" id="keel-public-names-form">
				<input type="hidden" name="action" value="keel_public_names">
				<input type="hidden" name="mode" value="start">
				<?php wp_nonce_field( 'keel_public_names_run', 'keel_public_names_nonce' ); ?>
				<?php if ( is_multisite() ) : ?>
					<p>
						<label for="keel-public-names-site"><?php esc_html_e( 'Site', 'keel-defaults' ); ?></label><br>
						<select name="site" id="keel-public-names-site" aria-describedby="keel-public-names-site-help">
							<?php foreach ( keel_defaults_public_names_sites() as $id => $label ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( get_main_site_id(), $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description" id="keel-public-names-site-help"><?php esc_html_e( 'Only members of this site are changed. Their accounts are shared, so their new names show on every site they belong to. Comments are changed on this site only.', 'keel-defaults' ); ?></span>
					</p>
				<?php endif; ?>
				<p>
					<label>
						<input type="checkbox" name="comments" value="1">
						<?php esc_html_e( 'Also update past comments that still show an account’s old name', 'keel-defaults' ); ?>
					</label>
					<br><span class="description"><?php esc_html_e( 'Only comments written while signed in to that account, whose stored name is exactly the old one. Guest comments and edited names stay as they are.', 'keel-defaults' ); ?></span>
				</p>
				<?php submit_button( __( 'Run cleanup', 'keel-defaults' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
	keel_defaults_public_names_script();
}

/**
 * Progress or result of the current run. Counts only; never a name or address.
 *
 * @param array $state Progress.
 * @return void
 */
function keel_defaults_public_names_render_state( array $state ) {
	$done = ! empty( $state['done'] );
	?>
	<div class="notice <?php echo $done ? 'notice-success' : 'notice-warning'; ?> inline" id="keel-public-names-status" role="status">
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: accounts checked, 2: accounts changed, 3: comments changed. */
					$done ? __( 'Cleanup finished. Checked %1$d accounts, changed %2$d, updated %3$d comments.', 'keel-defaults' ) : __( 'Cleanup paused. Checked %1$d accounts so far, changed %2$d, updated %3$d comments.', 'keel-defaults' ),
					(int) $state['scanned'],
					(int) $state['changed'],
					(int) $state['comments_changed']
				)
			);
			?>
		</p>
		<?php if ( is_multisite() ) : ?>
			<p>
				<?php
				$sites = keel_defaults_public_names_sites();
				/* translators: %s: site name and address. */
				echo esc_html( sprintf( __( 'Site: %s', 'keel-defaults' ), $sites[ (int) $state['target'] ] ?? '#' . (int) $state['target'] ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! $done ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="keel-public-names-form">
				<input type="hidden" name="action" value="keel_public_names">
				<input type="hidden" name="mode" value="continue">
				<?php wp_nonce_field( 'keel_public_names_run', 'keel_public_names_nonce' ); ?>
				<?php submit_button( __( 'Continue cleanup', 'keel-defaults' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Progressive enhancement: keep submitting batches over admin-ajax until done.
 * Without JavaScript, each submit runs one batch and comes back here.
 *
 * @return void
 */
function keel_defaults_public_names_script() {
	?>
	<script>
	( function () {
		var form = document.getElementById( 'keel-public-names-form' );
		if ( ! form || ! window.fetch || ! window.FormData ) {
			return;
		}
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var button = form.querySelector( '[type=submit]' );
			var data = new FormData( form );
			data.set( 'action', 'keel_public_names' );
			if ( button ) {
				button.disabled = true;
			}
			var step = function () {
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( result && result.success && result.data && ! result.data.done ) {
							data.set( 'mode', 'continue' );
							step();
							return;
						}
						window.location.reload();
					} )
					.catch( function () { window.location.reload(); } );
			};
			step();
		} );
	}() );
	</script>
	<?php
}

/**
 * Check the request. Returns the mode, or null when it must be refused.
 *
 * @return string|null 'start' or 'continue'.
 */
function keel_defaults_public_names_authorize() {
	if ( ! keel_defaults_public_names_can_run() ) {
		return null;
	}
	$nonce = isset( $_POST['keel_public_names_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['keel_public_names_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'keel_public_names_run' ) ) {
		return null;
	}
	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
	return in_array( $mode, array( 'start', 'continue' ), true ) ? $mode : null;
}

/**
 * Start or continue a run from an authorized request.
 *
 * @param string $mode 'start' or 'continue'.
 * @return array|null Progress, or null when there is nothing to continue or the target is invalid.
 */
function keel_defaults_public_names_step( $mode ) {
	$state = keel_defaults_public_names_state();
	if ( 'start' === $mode ) {
		if ( $state && empty( $state['done'] ) ) {
			// One run at a time: an unfinished run is continued, not restarted.
			$mode = 'continue';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in keel_defaults_public_names_authorize().
			$target = is_multisite() ? ( isset( $_POST['site'] ) ? absint( $_POST['site'] ) : 0 ) : get_current_blog_id();
			if ( is_multisite() && ( ! $target || ! get_site( $target ) ) ) {
				return null;
			}
			$state = array(
				'id'               => wp_generate_uuid4(),
				'target'           => (int) $target,
				'comments'         => ! empty( $_POST['comments'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in keel_defaults_public_names_authorize().
				'cursor'           => 0,
				'scanned'          => 0,
				'changed'          => 0,
				'comments_changed' => 0,
				'done'             => false,
				'started'          => time(),
			);
			keel_defaults_public_names_save_state( $state );
		}
	}
	if ( ! $state || ! empty( $state['done'] ) ) {
		return $state;
	}
	return keel_defaults_public_names_batch( $state );
}

/**
 * The no-JavaScript path: one batch, then back to the screen.
 *
 * @return void
 */
function keel_defaults_public_names_post() {
	$mode = keel_defaults_public_names_authorize();
	if ( null === $mode ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'keel-defaults' ), 403 );
	}
	$state = keel_defaults_public_names_step( $mode );
	$base  = is_multisite() ? network_admin_url( 'users.php' ) : admin_url( 'users.php' );
	$args  = array( 'page' => 'keel-public-names' );
	if ( null === $state ) {
		$args['keel-public-names'] = 'refused';
	}
	wp_safe_redirect( add_query_arg( $args, $base ) );
	exit;
}

/**
 * The JavaScript path: one batch, progress as JSON. Counts only.
 *
 * @return void
 */
function keel_defaults_public_names_ajax() {
	$mode = keel_defaults_public_names_authorize();
	if ( null === $mode ) {
		wp_send_json_error( array(), 403 );
		return;
	}
	$state = keel_defaults_public_names_step( $mode );
	if ( null === $state ) {
		wp_send_json_error( array(), 400 );
		return;
	}
	wp_send_json_success(
		array(
			'done'     => ! empty( $state['done'] ),
			'scanned'  => (int) $state['scanned'],
			'changed'  => (int) $state['changed'],
			'comments' => (int) $state['comments_changed'],
		)
	);
}

/**
 * Work through the target site's members from the cursor until the batch or
 * the time budget runs out. Progress is saved after every change, so a request
 * that dies part way loses nothing and counts nothing twice.
 *
 * @param array $state Progress.
 * @return array Updated progress.
 */
function keel_defaults_public_names_batch( array $state ) {
	global $wpdb;

	$switched = is_multisite() && get_current_blog_id() !== (int) $state['target'];
	if ( $switched ) {
		switch_to_blog( (int) $state['target'] );
	}
	$started = microtime( true );

	// A write the previous request made but did not live to record.
	if ( ! empty( $state['pending']['id'] ) ) {
		$user = get_userdata( (int) $state['pending']['id'] );
		if ( $user && ! keel_defaults_public_names_fix( keel_defaults_public_names_fields( $user ) ) ) {
			$state = keel_defaults_public_names_settle( $state );
		}
		unset( $state['pending'] );
		keel_defaults_public_names_save_state( $state );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded batch reads for an explicit admin action.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_nicename
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
			WHERE u.ID > %d
			ORDER BY u.ID
			LIMIT %d",
			$wpdb->get_blog_prefix() . 'capabilities',
			(int) $state['cursor'],
			KEEL_PUBLIC_NAMES_BATCH
		),
		ARRAY_A
	);
	$rows = is_array( $rows ) ? $rows : array();

	$meta = array();
	if ( $rows ) {
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated to match the array.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('nickname','first_name','last_name') AND user_id IN ($in)", $ids ), ARRAY_A );
		foreach ( (array) $found as $item ) {
			$meta[ (int) $item['user_id'] ][ $item['meta_key'] ] = $item['meta_value'];
		}
	}
	// phpcs:enable

	foreach ( $rows as $row ) {
		$id     = (int) $row['ID'];
		$fields = array_merge(
			array(
				'nickname'   => '',
				'first_name' => '',
				'last_name'  => '',
			),
			$meta[ $id ] ?? array(),
			$row
		);
		$change = keel_defaults_public_names_fix( $fields );
		if ( $change ) {
			// Record the write before making it, so a request that dies between the
			// write and the save is finished, and counted once, by the next one.
			$state['pending'] = array(
				'id'  => $id,
				'old' => isset( $change['display_name'] ) ? (string) $row['display_name'] : '',
			);
			keel_defaults_public_names_save_state( $state );
			$result = wp_update_user( array_merge( array( 'ID' => $id ), $change ) );
			if ( ! is_wp_error( $result ) ) {
				$state = keel_defaults_public_names_settle( $state );
			}
			unset( $state['pending'] );
		}
		++$state['scanned'];
		$state['cursor'] = $id;
		if ( $change ) {
			keel_defaults_public_names_save_state( $state );
		}
		if ( microtime( true ) - $started > KEEL_PUBLIC_NAMES_BUDGET ) {
			break;
		}
	}

	if ( count( $rows ) < KEEL_PUBLIC_NAMES_BATCH && ( ! $rows || (int) end( $rows )['ID'] === (int) $state['cursor'] ) ) {
		$state['done']     = true;
		$state['finished'] = time();
	}
	keel_defaults_public_names_save_state( $state );

	if ( $switched ) {
		restore_current_blog();
	}
	return $state;
}

/**
 * Count a completed write and, when asked, give the account's comments its new
 * name. Safe to repeat: comments only change while they still hold the old name.
 *
 * @param array $state Progress with a pending write.
 * @return array
 */
function keel_defaults_public_names_settle( array $state ) {
	$id = (int) $state['pending']['id'];
	++$state['changed'];
	if ( $state['comments'] && '' !== $state['pending']['old'] ) {
		clean_user_cache( $id );
		$user = get_userdata( $id );
		if ( $user ) {
			$state['comments_changed'] += keel_defaults_public_names_comments( $id, $state['pending']['old'], (string) $user->display_name );
		}
	}
	return $state;
}

/**
 * An account's fields in the shape keel_defaults_public_names_fix() reads.
 *
 * @param WP_User $user Account.
 * @return array
 */
function keel_defaults_public_names_fields( WP_User $user ) {
	return array(
		'ID'            => (int) $user->ID,
		'user_login'    => (string) $user->user_login,
		'user_email'    => (string) $user->user_email,
		'display_name'  => (string) $user->display_name,
		'user_nicename' => (string) $user->user_nicename,
		'nickname'      => (string) get_user_meta( $user->ID, 'nickname', true ),
		'first_name'    => (string) get_user_meta( $user->ID, 'first_name', true ),
		'last_name'     => (string) get_user_meta( $user->ID, 'last_name', true ),
	);
}

/**
 * What a cleanup would change on one account: only its unsafe public fields.
 *
 * @param array $fields display_name, nickname, first_name, last_name, user_login, user_email, user_nicename.
 * @return array Fields to pass to wp_update_user(); empty when nothing is unsafe.
 */
function keel_defaults_public_names_fix( array $fields ) {
	$login   = (string) $fields['user_login'];
	$email   = (string) $fields['user_email'];
	$change  = array();
	$display = keel_defaults_public_names_display( $fields );
	if ( $display !== (string) $fields['display_name'] ) {
		$change['display_name'] = $display;
	}
	if ( ! keel_defaults_public_name_is_safe( $fields['nickname'], $login, $email ) ) {
		$change['nickname'] = $display;
	}
	if ( ! keel_defaults_public_slug_is_safe( $fields['user_nicename'], $login, $email ) ) {
		$change['user_nicename'] = keel_defaults_public_names_unique_slug(
			keel_defaults_public_names_slug( $fields['user_nicename'], $display, $login, $email ),
			(int) $fields['ID'],
			$login,
			$email
		);
	}
	return $change;
}

/**
 * Give an account's past comments its new name, on the current site only: the
 * account's own comments whose stored author name is exactly the old one.
 *
 * @param int    $user_id Account.
 * @param string $old     Old display name, exactly as stored.
 * @param string $renamed New display name.
 * @return int Comments changed.
 */
function keel_defaults_public_names_comments( $user_id, $old, $renamed ) {
	global $wpdb;
	if ( '' === $old || $old === $renamed ) {
		return 0;
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicit admin cleanup; caches are cleared below.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, comment_author FROM {$wpdb->comments} WHERE user_id = %d AND comment_author = %s", (int) $user_id, $old ), ARRAY_A );
	$ids  = array();
	foreach ( (array) $rows as $row ) {
		// The column's collation may ignore case; only the exact old name counts.
		if ( $row['comment_author'] === $old ) {
			$ids[] = (int) $row['comment_ID'];
		}
	}
	if ( ! $ids ) {
		return 0;
	}
	$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated to match the array.
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_author = %s WHERE comment_ID IN ($in)", array_merge( array( $renamed ), $ids ) ) );
	// phpcs:enable
	clean_comment_cache( $ids );
	return count( $ids );
}
