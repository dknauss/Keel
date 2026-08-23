<?php
/**
 * Admin and front-end UX defaults: editor, media, list columns, menu width, environment indicator.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stretch the admin Heartbeat interval.
 *
 * Filterable, and clamped to 15-120 seconds — which is narrower than core's own
 * limit, deliberately.
 *
 * Core accepts anything from 1 to 3600 seconds (`heartbeat.js` bounds
 * `options.interval` at initialization). The ceiling here is 120 because this
 * filter applies to every admin Heartbeat, including the post editor's, and post
 * locks expire after 150 seconds — `wp_check_post_lock_window` in core, and the
 * reason core's own idle slowdown stops at 120. An interval above that stops
 * refreshing the lock in time, and two people quietly overwrite each other.
 *
 * The floor is 15 to keep the throttle setting from becoming a way to generate
 * more admin-ajax traffic than core's default 60.
 *
 * @param array $settings Heartbeat settings.
 * @return array
 */
function keel_defaults_heartbeat_interval( $settings ) {
	$interval = (int) apply_filters( 'keel_heartbeat_interval', 60 );

	$settings['interval'] = max( 15, min( 120, $interval ) );

	return $settings;
}

/**
 * Drop Heartbeat on the dashboard home, where nothing depends on it.
 *
 * The post editor keeps its Heartbeat: that is where post locking and autosave
 * signalling live, and dropping it there would trade a little admin-ajax traffic
 * for two editors overwriting each other.
 *
 * @param string $hook_suffix Current admin screen.
 */
function keel_defaults_drop_dashboard_heartbeat( $hook_suffix ) {
	if ( 'index.php' !== $hook_suffix ) {
		return;
	}

	if ( true !== apply_filters( 'keel_heartbeat_drop_on_dashboard', true ) ) {
		return;
	}

	wp_deregister_script( 'heartbeat' );
}

/**
 * Force the classic editing experience.
 *
 * Registers the filters WordPress consults when choosing an editor. They fire
 * only in the admin editor-selection path, so registering them at load is
 * harmless on the front end, and front-end rendering of existing block content
 * is unaffected — do_blocks() still runs; only the editing experience changes.
 * On a block theme the Site Editor (a separate gate) stays available.
 */
function keel_defaults_force_classic_editor() {
	keel_defaults_add_policy_filter( 'use_block_editor_for_post', '__return_false' );
	keel_defaults_add_policy_filter( 'use_block_editor_for_post_type', '__return_false' );
	keel_defaults_add_policy_filter( 'gutenberg_can_edit_post', '__return_false' );  // Standalone Gutenberg feature plugin.
	keel_defaults_add_policy_filter( 'use_widgets_block_editor', '__return_false' ); // Classic Widgets screen.
}

/**
 * Hide the "Password protected" visibility option in the post editor.
 *
 * WordPress post passwords are weak and are bypassed by full-page caches, so this
 * steers editors away from them by removing the option from the editor. It is
 * cosmetic and non-destructive — it hides UI only, changes no data or behavior,
 * and leaves the field in place on a post that already has a password so that
 * post stays editable. It depends on admin DOM selectors, so re-check it after
 * major WordPress editor changes; if a selector goes stale the field simply
 * reappears — nothing breaks.
 */
function keel_defaults_hide_post_password_ui() {
	global $pagenow, $post;

	if ( empty( $pagenow ) || ( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow ) ) {
		return;
	}

	if ( ! empty( $post->post_password ) ) {
		return;
	}
	?>
	<style id="keel-hide-post-password">
		#visibility-radio-password,
		label[for="visibility-radio-password"],
		#editor-post-password-0,
		label[for="editor-post-password-0"],
		#editor-post-password-0-description,
		#editor-post-password-1,
		label[for="editor-post-password-1"],
		#editor-post-password-1-description {
			display: none;
		}
	</style>
	<?php
}

/**
 * Lowercase a new upload filename.
 *
 * Hooked to sanitize_file_name at priority 20 (after core sanitization), so
 * only new uploads are affected. UTF-8 aware where mbstring is available.
 *
 * @param string $filename Sanitised filename.
 * @return string
 */
function keel_defaults_lowercase_filename( $filename ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $filename, 'UTF-8' ) : strtolower( $filename );
}

/**
 * Print the CSS that widens the left admin menu.
 *
 * Registered only when a non-default width is selected. Widths come from a fixed
 * allowlist in the schema, so the stored value is a known integer.
 *
 * Two things are needed to actually override core (WordPress 6.x/7.x):
 * 1. `!important` — the base width lives in the color-scheme stylesheet
 *    (`#adminmenu,#adminmenuback,#adminmenuwrap{width:160px}`), and the `.auto-fold`
 *    rules that collapse the menu at 783–960px have higher specificity than a
 *    plain `#adminmenuwrap`. Without `!important` the widen is silently ignored —
 *    which is why the plain-selector version (and pixel-experience's) does nothing
 *    on current WordPress.
 * 2. `body:not(.folded)` — so a menu the user has manually collapsed with the
 *    core toggle still collapses; the widen only applies to the expanded menu.
 */
function keel_defaults_admin_menu_width_css() {
	$width = (int) keel_defaults_get( 'admin_menu_width' );

	if ( $width < 161 ) {
		return;
	}
	$w = (int) $width;
	?>
	<style id="keel-admin-menu-width">
		@media screen and (min-width: 783px) {
			#adminmenu,
			#adminmenuback,
			#adminmenuwrap,
			#adminmenu li.menu-top,
			#adminmenu .wp-submenu {
				width: <?php echo (int) $w; ?>px !important;
			}
			#adminmenuback {
				position: fixed;
				top: 0;
				bottom: -120px;
			}
			#adminmenu li.menu-top > a.menu-top,
			#adminmenu .wp-has-current-submenu a.wp-has-current-submenu,
			#adminmenu li.current a.menu-top {
				width: auto !important;
			}
			#wpcontent,
			#wpfooter {
				margin-left: <?php echo (int) $w; ?>px !important;
			}
			#adminmenu li.menu-top:not(.wp-has-current-submenu) .wp-submenu {
				left: <?php echo (int) $w; ?>px;
			}
			#adminmenu .wp-has-current-submenu .wp-submenu.wp-submenu-wrap {
				left: auto;
			}
			.rtl #wpcontent,
			.rtl #wpfooter {
				margin-right: <?php echo (int) $w; ?>px !important;
				margin-left: 0 !important;
			}
			.rtl #adminmenu li.menu-top:not(.wp-has-current-submenu) .wp-submenu {
				right: <?php echo (int) $w; ?>px;
				left: auto;
			}
			.rtl #adminmenu .wp-has-current-submenu .wp-submenu.wp-submenu-wrap {
				right: auto;
			}
			.folded #wpcontent,
			.folded #wpfooter {
				margin-left: 36px !important;
			}
			.rtl.folded #wpcontent,
			.rtl.folded #wpfooter {
				margin-right: 36px !important;
				margin-left: 0 !important;
			}
		}
	</style>
	<?php
}

/**
 * Register helper columns for the current post-type list table.
 *
 * Column hooks are per-post-type, so they can only be added once the screen is
 * known. Attachments are handled by their own Media filter.
 *
 * @param \WP_Screen $screen Current screen.
 */
function keel_defaults_register_helper_post_columns( $screen ) {
	if ( empty( $screen->post_type ) || 'edit' !== $screen->base ) {
		return;
	}

	$post_type = sanitize_key( $screen->post_type );
	if ( 'attachment' === $post_type ) {
		return;
	}

	if ( 'page' === $post_type ) {
		add_filter( 'manage_pages_columns', 'keel_defaults_filter_post_columns' );
		add_action( 'manage_pages_custom_column', 'keel_defaults_render_post_column', 10, 2 );
		return;
	}

	add_filter( "manage_{$post_type}_posts_columns", 'keel_defaults_filter_post_columns' );
	add_action( "manage_{$post_type}_posts_custom_column", 'keel_defaults_render_post_column', 10, 2 );

	if ( is_post_type_hierarchical( $post_type ) ) {
		add_action( 'manage_pages_custom_column', 'keel_defaults_render_post_column', 10, 2 );
	}
}

/**
 * Add the helper post columns.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function keel_defaults_filter_post_columns( $columns ) {
	$columns['keel_id']       = __( 'ID', 'keel-defaults' );
	$columns['keel_thumb']    = __( 'Image', 'keel-defaults' );
	$columns['keel_modified'] = __( 'Modified', 'keel-defaults' );
	return $columns;
}

/**
 * Render a helper post column.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function keel_defaults_render_post_column( $column, $post_id ) {
	if ( 'keel_id' === $column ) {
		echo esc_html( (string) (int) $post_id );
		return;
	}
	if ( 'keel_thumb' === $column ) {
		echo has_post_thumbnail( $post_id ) ? wp_kses_post( get_the_post_thumbnail( $post_id, array( 48, 48 ) ) ) : '&mdash;';
		return;
	}
	if ( 'keel_modified' === $column ) {
		echo esc_html( get_post_modified_time( get_option( 'date_format' ), false, $post_id, true ) );
	}
}

/**
 * Add the Media library file-size column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function keel_defaults_filter_media_columns( $columns ) {
	$columns['keel_file_size'] = __( 'File size', 'keel-defaults' );
	return $columns;
}

/**
 * Render the Media library file-size column.
 *
 * @param string $column        Column key.
 * @param int    $attachment_id Attachment ID.
 */
function keel_defaults_render_media_column( $column, $attachment_id ) {
	if ( 'keel_file_size' !== $column ) {
		return;
	}
	$bytes = keel_defaults_attachment_file_bytes( $attachment_id );
	echo $bytes ? esc_html( size_format( $bytes ) ) : '&mdash;';
}

/**
 * Add the Users registration + last-login columns.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function keel_defaults_filter_user_columns( $columns ) {
	$columns['keel_registered'] = __( 'Registered', 'keel-defaults' );
	$columns['keel_last_login'] = __( 'Last login', 'keel-defaults' );
	return $columns;
}

/**
 * Render a Users helper column. This is a filter, so it returns the cell value.
 *
 * @param string $value   Current cell value.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function keel_defaults_render_user_column( $value, $column, $user_id ) {
	if ( 'keel_registered' === $column ) {
		$user = get_userdata( $user_id );
		return $user ? esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered ) ) : '&mdash;';
	}
	if ( 'keel_last_login' === $column ) {
		$timestamp = keel_defaults_last_login_timestamp( $user_id );
		return $timestamp ? esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) ) : '&mdash;';
	}
	return $value;
}

/**
 * Record a user's last-login timestamp.
 *
 * Runs only while the helper columns are enabled, so it adds no storage cost
 * otherwise. Logins before enabling are unknown.
 *
 * @param string        $user_login User login (unused).
 * @param \WP_User|null $user       Authenticated user.
 */
function keel_defaults_record_last_login( $user_login, $user = null ) {
	if ( $user instanceof \WP_User ) {
		update_user_meta( $user->ID, 'keel_last_login', time() );
	}
}

/**
 * Read a last-login timestamp from Keel's key or common third-party keys.
 *
 * @param int $user_id User ID.
 * @return int Unix timestamp, or 0 when unknown.
 */
function keel_defaults_last_login_timestamp( $user_id ) {
	foreach ( array( 'keel_last_login', 'wp_last_login', 'last_login' ) as $key ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
	}
	return 0;
}

/**
 * Register the read-only "generated image sizes" meta box on image attachments.
 *
 * @param \WP_Post $post Attachment post.
 */
function keel_defaults_media_sizes_meta_box( $post ) {
	if ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $post ) ) {
		return;
	}
	add_meta_box( 'keel-media-sizes', __( 'Generated Image Sizes', 'keel-defaults' ), 'keel_defaults_render_media_sizes_meta_box', 'attachment', 'normal', 'low' );
}

/**
 * Render the generated-image-sizes table.
 *
 * @param \WP_Post $post Attachment post.
 */
function keel_defaults_render_media_sizes_meta_box( $post ) {
	$rows = keel_defaults_attachment_image_size_rows( $post->ID );

	if ( empty( $rows ) ) {
		echo '<p>' . esc_html__( 'No generated image sizes were found for this attachment.', 'keel-defaults' ) . '</p>';
		return;
	}

	echo '<p>' . esc_html__( 'Read-only view of the generated image files and URLs.', 'keel-defaults' ) . '</p>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Size', 'keel-defaults' ) . '</th><th>' . esc_html__( 'Dimensions', 'keel-defaults' ) . '</th><th>' . esc_html__( 'File size', 'keel-defaults' ) . '</th><th>' . esc_html__( 'URL', 'keel-defaults' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		echo '<td>' . esc_html( $row['size'] ) . '</td>';
		echo '<td>' . esc_html( $row['dimensions'] ) . '</td>';
		echo '<td>' . esc_html( $row['file_size'] ) . '</td>';
		echo '<td><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['url'] ) . '</a></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/**
 * Build the generated-image-size rows for an attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return array[]
 */
function keel_defaults_attachment_image_size_rows( $attachment_id ) {
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$rows     = array();
	$sizes    = array( 'full' => array() );

	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		$sizes = array_merge( $sizes, $metadata['sizes'] );
	}

	foreach ( array_keys( $sizes ) as $size ) {
		$image = wp_get_attachment_image_src( $attachment_id, $size );
		if ( empty( $image[0] ) ) {
			continue;
		}

		$bytes  = keel_defaults_attachment_size_bytes( $attachment_id, $size, $metadata );
		$rows[] = array(
			'size'       => (string) $size,
			'dimensions' => sprintf( '%d × %d', isset( $image[1] ) ? (int) $image[1] : 0, isset( $image[2] ) ? (int) $image[2] : 0 ),
			'file_size'  => $bytes ? size_format( $bytes ) : __( 'Unknown', 'keel-defaults' ),
			'url'        => $image[0],
		);
	}

	return $rows;
}

/**
 * Bytes of the original uploaded file.
 *
 * @param int $attachment_id Attachment ID.
 * @return int
 */
function keel_defaults_attachment_file_bytes( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	return ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
}

/**
 * Bytes of one generated image size (or the original for 'full').
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size          Size name.
 * @param array  $metadata      Attachment metadata.
 * @return int
 */
function keel_defaults_attachment_size_bytes( $attachment_id, $size, $metadata ) {
	if ( 'full' === $size ) {
		return keel_defaults_attachment_file_bytes( $attachment_id );
	}

	$full_file = get_attached_file( $attachment_id );
	if ( empty( $full_file ) || empty( $metadata['sizes'][ $size ]['file'] ) ) {
		return 0;
	}

	$file = trailingslashit( dirname( $full_file ) ) . $metadata['sizes'][ $size ]['file'];
	return file_exists( $file ) ? (int) filesize( $file ) : 0;
}

/**
 * Environment indicator definitions (label, dashicon, colors) keyed by the
 * WordPress environment type. Filterable via keel_environments.
 *
 * @return array
 */
function keel_environments() {
	$defaults = array(
		'production'  => array(
			'label'            => __( 'Production', 'keel-defaults' ),
			'icon'             => 'dashicons-admin-site',
			'background_color' => '#b92a2a',
			'text_color'       => '#fff',
		),
		'staging'     => array(
			'label'            => __( 'Staging', 'keel-defaults' ),
			'icon'             => 'dashicons-admin-generic',
			'background_color' => '#8f6800',
			'text_color'       => '#fff',
		),
		'development' => array(
			'label'            => __( 'Development', 'keel-defaults' ),
			'icon'             => 'dashicons-admin-tools',
			'background_color' => '#34863b',
			'text_color'       => '#fff',
		),
		'local'       => array(
			'label'            => __( 'Local', 'keel-defaults' ),
			'icon'             => 'dashicons-admin-home',
			'background_color' => '#0073aa',
			'text_color'       => '#fff',
		),
	);

	$environments = apply_filters( 'keel_environments', $defaults );

	return is_array( $environments ) ? wp_parse_args( $environments, $defaults ) : $defaults;
}

/**
 * Whether a host name belongs to a local development tool.
 *
 * Matched against the host alone, never the whole URL: a site served on an
 * explicit port has a home_url() ending in ":8080", so a suffix test against
 * the URL misses every ported local install.
 *
 * @param string $host Host name, without port or scheme.
 * @return bool
 */
function keel_defaults_is_local_host( $host ) {
	$host = strtolower( trim( (string) $host, '[]' ) );

	if ( '' === $host ) {
		return false;
	}

	// Loopback, however it was written. wp-env, MAMP, XAMPP, `wp server`.
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}

	/**
	 * Host suffixes that mark a site as a local development install.
	 *
	 * .test and .localhost are reserved for this by RFC 6761; .local is
	 * mDNS/Bonjour but is what Local by WP Engine ships. The rest are the
	 * default domains of specific tools.
	 *
	 * @param string[] $suffixes Host suffixes, each including the leading dot.
	 */
	$suffixes = apply_filters(
		'keel_local_host_suffixes',
		array(
			'.test',       // Valet, Herd, Laragon, VVV.
			'.local',      // Local by WP Engine.
			'.localhost',  // RFC 6761.
			'.ddev.site',  // DDEV.
			'.lndo.site',  // Lando.
		)
	);

	foreach ( (array) $suffixes as $suffix ) {
		$suffix = strtolower( (string) $suffix );
		if ( '' !== $suffix && substr( $host, - strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether this site declares its own environment type.
 *
 * Core resolves `WP_ENVIRONMENT_TYPE` from *either* an environment variable or
 * the constant, the constant winning — so testing only for the constant misses
 * every host that sets the variable, which is the documented way to do it and
 * what DDEV, Lando and wp-env generate by default.
 *
 * A declared value only counts when core would accept it. Core silently falls
 * back to production for anything outside its four names, and inheriting that
 * fallback would paint a red Production badge across somebody's laptop on the
 * strength of a typo.
 *
 * @return bool
 */
function keel_defaults_environment_is_declared() {
	$declared = '';

	if ( function_exists( 'getenv' ) ) {
		$env = getenv( 'WP_ENVIRONMENT_TYPE' );
		if ( false !== $env ) {
			$declared = (string) $env;
		}
	}

	if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE ) {
		$declared = (string) WP_ENVIRONMENT_TYPE;
	}

	return in_array( $declared, array( 'local', 'development', 'staging', 'production' ), true );
}

/**
 * The current environment, treating known local-tool hosts as local when the
 * site has not declared an environment type of its own.
 *
 * The host heuristic is a fallback, never an override. A site that says it is
 * staging is staging, even on a .ddev.site hostname — an indicator that
 * contradicts an explicit declaration is worse than no indicator, because the
 * whole point of it is to be believed at a glance.
 *
 * @return string
 */
function keel_defaults_current_environment() {
	$type = wp_get_environment_type();

	if ( ! keel_defaults_environment_is_declared() ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( keel_defaults_is_local_host( $host ) ) {
			return 'local';
		}
	}

	return $type;
}

/**
 * Add the environment indicator to the admin bar.
 *
 * @param \WP_Admin_Bar $admin_bar Admin bar.
 */
function keel_defaults_environment_toolbar_item( $admin_bar ) {
	$environments = keel_environments();
	if ( empty( $environments ) ) {
		return;
	}

	$type        = keel_defaults_current_environment();
	$environment = isset( $environments[ $type ] ) ? $environments[ $type ] : $environments['production'];

	$admin_bar->add_menu(
		array(
			'id'     => 'keel-environment-indicator',
			'parent' => 'top-secondary',
			'title'  => sprintf(
				'<span class="ab-icon dashicons %s" aria-hidden="true"></span><span class="ab-label">%s</span>',
				esc_attr( $environment['icon'] ),
				esc_html( $environment['label'] )
			),
			'meta'   => array(
				'class' => esc_attr( 'keel-environment-indicator keel-environment-indicator--' . $type ),
			),
		)
	);
}

/**
 * Reduce a filter-supplied color to something safe to interpolate into a CSS
 * declaration.
 *
 * The threat in a value position is not HTML injection but a value that
 * terminates its own declaration and opens a new rule, or opens a CSS comment.
 * This strips the characters that could do either (notably `;`, `}`, `:`, and
 * `*`) while leaving every documented color form intact: hex, named colors,
 * rgb/rgba/hsl functional notation, custom properties (`var(--x)`), and CSS
 * Color 4 slash notation. The slash is safe only while the asterisk is stripped
 * (no comment can open), which the test asserts directly.
 *
 * @param string $color Colour value from the keel_environments filter.
 * @return string
 */
function keel_defaults_sanitize_css_color( $color ) {
	return preg_replace( '/[^A-Za-z0-9#(),.%\s_\/-]/', '', (string) $color );
}

/**
 * Escape a URL for use inside a CSS url() value.
 *
 * Using esc_url() here is wrong. It entity-encodes for HTML — an `&` in a query
 * string becomes `&amp;` — and a <style> element is raw text, where entities
 * are not decoded. The browser would request the literal `&amp;`. esc_url_raw()
 * does the scheme validation without the entity encoding; the quote and
 * parenthesis characters are then percent-encoded so a URL cannot close the
 * url() token and start a new declaration.
 *
 * @param string $url URL to embed.
 * @return string Empty string if the URL did not survive validation.
 */
function keel_defaults_css_url( $url ) {
	return str_replace(
		array( "'", '"', '(', ')' ),
		array( '%27', '%22', '%28', '%29' ),
		esc_url_raw( (string) $url )
	);
}

/**
 * The image to put in place of the WordPress login logo.
 *
 * Prefers the theme's Customizer logo, because that is the image a site has
 * almost certainly already set and the one a client recognizes as their logo.
 * The site icon is the fallback: it exists on fewer sites, and it is a square
 * favicon rather than a wordmark.
 *
 * @return string Image URL, or '' when the site has set neither.
 */
function keel_defaults_login_logo_url() {
	$custom_logo_id = function_exists( 'get_theme_mod' ) ? (int) get_theme_mod( 'custom_logo' ) : 0;

	if ( $custom_logo_id && function_exists( 'wp_get_attachment_image_src' ) ) {
		$logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );

		if ( ! empty( $logo[0] ) ) {
			return (string) $logo[0];
		}
	}

	return function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 192 ) : '';
}

/**
 * Print the login-screen logo replacement styles.
 *
 * Nothing is printed when the site has no usable image: a rule with an empty
 * url() is worse than no rule, because an empty URL in CSS resolves against
 * the current document and the browser re-requests the login page to use as
 * an image.
 */
function keel_defaults_login_logo_styles() {
	$url = keel_defaults_login_logo_url();
	$css = keel_defaults_css_url( $url );

	// Both checks: css_url() collapses a rejected scheme to '', and '0' is a
	// falsy string that survives sanitization intact but is not a usable image.
	if ( empty( $url ) || '' === $css ) {
		return;
	}

	echo '<style id="keel-login-logo">#login h1 a, .login h1 a { background-image:url(\'' . $css . '\'); background-size:contain; background-position:center; background-repeat:no-repeat; width:320px; }</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- keel_defaults_css_url() escapes for this context; esc_url() would entity-encode into raw-text CSS.
}

/**
 * Print the environment-indicator inline styles.
 */
function keel_defaults_environment_styles() {
	if ( ! is_admin_bar_showing() ) {
		return;
	}

	$environments = keel_environments();
	if ( empty( $environments ) ) {
		return;
	}

	$css  = '.keel-environment-indicator { pointer-events: none; }';
	$css .= ' .keel-environment-indicator .ab-icon { top: 3px; }';

	foreach ( $environments as $type => $environment ) {
		$css .= sprintf(
			' .keel-environment-indicator--%s .ab-item { background-color: %s !important; color: %s !important; }',
			sanitize_html_class( $type ),
			keel_defaults_sanitize_css_color( $environment['background_color'] ),
			keel_defaults_sanitize_css_color( $environment['text_color'] )
		);
	}

	/*
	 * In the crowded 783–960px desktop band, drop the text label to just the
	 * color-coded icon. Clip (not display:none) keeps the label in the
	 * accessibility tree — the icon is aria-hidden, so the label is the node's
	 * only accessible name and must survive for screen-reader and zoom users.
	 */
	$css .= ' @media screen and (min-width: 783px) and (max-width: 960px) {';
	$css .= ' #wpadminbar .keel-environment-indicator .ab-label { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }';
	$css .= ' #wpadminbar .keel-environment-indicator .ab-icon { margin-right: 0; }';
	$css .= ' }';

	// CSS is raw text: values are sanitized per-interpolation above, and
	// wp_strip_all_tags guards against a </style> breakout.
	printf( '<style id="keel-environment-indicator">%s</style>', wp_strip_all_tags( $css ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS escaped per-value above.
}

/**
 * Auto-hide the front-end admin bar, revealing it on hover or keyboard focus.
 *
 * Scoped to hover-capable, fine-pointer devices so touch users keep the normal
 * bar; focus-within keeps it reachable by keyboard. A 4px sliver stays visible
 * as the affordance to reveal it.
 */
function keel_defaults_auto_hide_admin_bar_css() {
	if ( is_admin() || ! is_admin_bar_showing() ) {
		return;
	}
	?>
	<style id="keel-auto-hide-admin-bar">
		@media (hover: hover) and (pointer: fine) {
			html {
				margin-top: 0 !important;
			}
			/*
			 * Slide the bar fully off-screen with `top` (not `transform`), so it
			 * leaves no visible sliver. Keeping the bar transform-free lets its own
			 * fixed-position ::before below stay anchored to the viewport top as the
			 * reveal target.
			 */
			#wpadminbar {
				top: -50px !important;
				transition: top 160ms ease-in-out;
			}
			/*
			 * A transparent strip pinned to the top edge. It is part of #wpadminbar,
			 * so hovering it triggers the bar's :hover — but because the bar has no
			 * transform, this `position: fixed` resolves against the viewport, so the
			 * strip stays put while the bar itself is hidden above.
			 */
			#wpadminbar::before {
				content: "";
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				height: 5px;
			}
			#wpadminbar:hover,
			#wpadminbar:focus-within {
				top: 0 !important;
			}
		}
	</style>
	<?php
}

/**
 * Decide where an attachment page should send visitors, if anywhere.
 *
 * Kept separate from the redirect itself so the decision can be tested without
 * a request: the hook does the exiting, this answers the question.
 *
 * WordPress 6.4 added a `wp_attachment_pages_enabled` option — new installs get
 * `0` and core redirects attachment requests to the file, while sites upgraded
 * from earlier get `1` and keep rendering the pages. This default overrides that
 * destination, preferring the parent post, because landing on a real article
 * beats landing on a bare JPEG. Where there is no parent it matches core and
 * falls back to the file.
 *
 * @param int $attachment_id Attachment post ID.
 * @return string Redirect target, or '' to leave the page alone.
 */
function keel_defaults_attachment_redirect_target( $attachment_id ) {
	/**
	 * Let a theme that deliberately builds attachment pages keep them.
	 *
	 * A theme shipping attachment.php or image.php has opted into rendering
	 * these, and quietly redirecting past it would delete a feature its author
	 * wrote on purpose — the photography and portfolio case. Filter this to
	 * force the redirect anyway, or to skip it on your own terms.
	 *
	 * @param bool $keep          Whether to leave the attachment page alone.
	 * @param int  $attachment_id Attachment post ID.
	 */
	if ( (bool) apply_filters( 'keel_keep_attachment_page', (bool) locate_template( array( 'attachment.php', 'image.php' ) ), $attachment_id ) ) {
		return '';
	}

	$parent = wp_get_post_parent_id( $attachment_id );

	if ( $parent ) {
		return (string) get_permalink( $parent );
	}

	// Unattached media has no parent, and that is common — anything uploaded
	// straight into the Media Library lands here. Sending all of those to the
	// homepage is a soft-404 pattern search engines read badly, so fall back to
	// the file itself, which is what core does when attachment pages are off.
	return (string) wp_get_attachment_url( $attachment_id );
}
