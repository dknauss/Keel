<?php
/**
 * Runtime bootstrap: wires every enabled default to WordPress on plugins_loaded.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/*
 * =====================================================================
 * BOOTSTRAP — wire each enabled policy to its hook.
 * =====================================================================
 */

add_action( 'plugins_loaded', 'keel_defaults_bootstrap' );

/**
 * Wire every enabled default to its WordPress hook. Runs on plugins_loaded.
 */
function keel_defaults_bootstrap() {

	// Read-only Site Health posture surface — always registered (not a toggle).
	add_filter( 'site_status_tests', 'keel_defaults_site_health_tests' );

	/*
	 * The same summary in Site Health → Info, which is where it belongs. A
	 * passing Status test lands inside the collapsed "Passed tests" accordion,
	 * so on a correctly configured site the summary was behind a fold nobody
	 * was told about. Info is always expanded and copyable, which is what a
	 * support thread actually needs.
	 */
	add_filter( 'debug_information', 'keel_defaults_debug_information' );

	/*
	 * Network policy, on multisite only. The screen lives in Network Admin and is
	 * gated on manage_network_options; the save handler runs on admin_init because
	 * Network Admin has no options.php to post to.
	 */
	if ( is_multisite() ) {
		add_action( 'network_admin_menu', 'keel_defaults_network_menu' );
		add_action( 'admin_init', 'keel_defaults_handle_network_save' );
	}
	keel_defaults_add_style( 'admin', 'keel_defaults_site_health_info_css' );

	/* ----- Updates ----- */

	/*
	 * wp-config.php is the operator's highest-level declaration. Do not make a
	 * settings-screen choice silently overrule it. Without that constant, these
	 * documented filters make the site's policy independent of its install age
	 * and of the major-update choice previously stored by core.
	 */
	if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) && 'inherit' !== keel_defaults_get( 'core_update_policy' ) ) {
		add_filter( 'allow_minor_auto_core_updates', 'keel_defaults_allow_minor_core_updates' );
		add_filter( 'allow_major_auto_core_updates', 'keel_defaults_allow_major_core_updates' );
		add_filter( 'allow_dev_auto_core_updates', 'keel_defaults_allow_dev_core_updates' );
	}

	add_filter( 'auto_update_translation', 'keel_defaults_allow_translation_updates' );

	/* ----- Security ----- */

	if ( keel_defaults_enabled( 'restrict_rest_user_discovery' ) ) {
		keel_defaults_add_policy_filter(
			'rest_endpoints',
			function ( $endpoints ) {
				if ( is_user_logged_in() ) {
					return $endpoints;
				}

				/*
				 * Match the route pattern rather than naming two keys. The keys are
				 * core's own regexes — '/wp/v2/users/(?P<id>[\d]+)' today — and a
				 * literal list silently stops protecting anything the day core edits
				 * one of them. Nothing announces that; the endpoint just answers again.
				 */
				foreach ( preg_grep( '#^/wp/v2/users\b#', array_keys( $endpoints ) ) as $route ) {
					unset( $endpoints[ $route ] );
				}

				return $endpoints;
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_rest' ) ) {
		keel_defaults_add_policy_filter( 'rest_authentication_errors', 'keel_defaults_require_rest_auth', PHP_INT_MAX );

		/*
		 * oEmbed stays reachable past the gate so other sites can still embed
		 * this one — but it must not answer with what the gate was closed to
		 * protect. Left alone it returns author_name and an author_url carrying
		 * the account nicename, to exactly the anonymous caller who has just
		 * been refused /wp/v2/users.
		 *
		 * The same filter runs for hidden author archives (below). Registering
		 * it twice is harmless — it unsets keys that are already gone — and the
		 * two reasons are genuinely independent: one is about the archive, this
		 * one is about the gate.
		 */
		add_filter( 'oembed_response_data', 'keel_defaults_strip_oembed_author' );

		// Stop advertising an endpoint that now answers 401. Core prints the
		// discovery link three ways: a <link rel> in the head, a Link: header,
		// and an entry in the RSD document.
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
	}

	/*
	 * XML-RPC is per-category, not all-or-nothing. Each category is off by
	 * default (locked down) and opt-in to re-enable — the same shape PMP uses.
	 * pingbacks and remote publishing come off via the xmlrpc_methods filter;
	 * system.multicall and a full endpoint block need a server-class swap,
	 * because IXR re-adds multicall after the filter runs.
	 */
	keel_defaults_add_policy_filter(
		'xmlrpc_methods',
		function ( $methods ) {
			// demo.* are inert core test methods with no legitimate use. Always drop
			// them (no toggle) so a locked-down endpoint stops answering scanner
			// probes like demo.sayHello with a cheerful "Hello!".
			unset( $methods['demo.sayHello'], $methods['demo.addTwoNumbers'] );

			if ( ! keel_defaults_enabled( 'xmlrpc_allow_pingbacks' ) ) {
				unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
			}
			if ( ! keel_defaults_enabled( 'xmlrpc_allow_remote_publishing' ) ) {
				foreach ( array_keys( $methods ) as $name ) {
					if ( preg_match( '/^(wp|metaWeblog|mt|blogger)\./', (string) $name ) ) {
						unset( $methods[ $name ] );
					}
				}
			}
			return $methods;
		},
		PHP_INT_MAX
	);

	// Disable core methods that require authentication when remote publishing is
	// off. Despite its name, xmlrpc_enabled does not disable the endpoint,
	// pingbacks, or custom unauthenticated methods.

	/*
	 * Through the wrapper, so the overlap check knows Keel holds this hook.
	 * Registered whichever way the setting is set: the callback passes the
	 * incoming value through when remote publishing is allowed, and that is
	 * precisely the case worth reporting — Keel's screen says XML-RPC works
	 * while another plugin has switched it off, which is the losing-plugin lie
	 * this check exists for.
	 */
	keel_defaults_add_policy_filter(
		'xmlrpc_enabled',
		function ( $enabled ) {
			return keel_defaults_enabled( 'xmlrpc_allow_remote_publishing' ) ? $enabled : false;
		}
	);

	// Strip the pingback discovery header when pingbacks are off.
	keel_defaults_add_policy_filter(
		'wp_headers',
		function ( $headers ) {
			if ( ! keel_defaults_enabled( 'xmlrpc_allow_pingbacks' ) ) {
				unset( $headers['X-Pingback'] );
			}
			return $headers;
		}
	);

	// Drop the RSD link (blogging-client discovery) when remote publishing is off.
	if ( ! keel_defaults_enabled( 'xmlrpc_allow_remote_publishing' ) ) {
		remove_action( 'wp_head', 'rsd_link' );
	}

	/*
	 * The replacement server class is defined lazily inside this filter: it only
	 * runs from xmlrpc.php, where the parent wp_xmlrpc_server is already loaded,
	 * so extending it at plugin-load time (on every ordinary request) is avoided.
	 */
	add_filter(
		'wp_xmlrpc_server_class',
		function ( $server_class ) {
			if ( keel_defaults_enabled( 'block_xmlrpc_endpoint' ) ) {
				if ( ! class_exists( 'Keel_Blocked_XMLRPC_Server' ) ) {
					/**
					 * Drop-in XML-RPC server that 403s every request.
					 */
					class Keel_Blocked_XMLRPC_Server {
						/**
						 * Refuse the whole endpoint.
						 */
						public function serve_request() {
							status_header( 403 );
							exit( 'XML-RPC services are disabled on this site.' );
						}
					}
				}
				return 'Keel_Blocked_XMLRPC_Server';
			}

			if ( ! keel_defaults_enabled( 'xmlrpc_allow_multicall' ) ) {
				if ( ! class_exists( 'Keel_Multicall_Disabled_Server' ) ) {
					/**
					 * Drop-in that refuses only system.multicall.
					 *
					 * WordPress 4.4 prevented it from being used as a password-guessing
					 * multiplier, so refusing it now is modest defence-in-depth against
					 * general batching, not a password control.
					 */
					class Keel_Multicall_Disabled_Server extends wp_xmlrpc_server {
						/**
						 * Refuse batched (multicall) requests.
						 *
						 * @param array $methodcalls Boxcarred method calls.
						 * @return IXR_Error
						 */
						public function multiCall( $methodcalls ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides a core method name.
							return new IXR_Error( 405, 'system.multicall is disabled on this site.' );
						}
					}
				}
				return 'Keel_Multicall_Disabled_Server';
			}

			return $server_class;
		}
	);

	if ( keel_defaults_enabled( 'disable_application_passwords' ) ) {
		keel_defaults_add_policy_filter( 'wp_is_application_passwords_available', 'keel_defaults_return_false' );
	}

	if ( keel_defaults_enabled( 'require_strong_passwords' ) ) {
		add_action( 'user_profile_update_errors', 'keel_defaults_validate_profile_password', 10, 3 );
		add_action( 'validate_password_reset', 'keel_defaults_validate_reset_password', 10, 2 );
		keel_defaults_add_policy_filter( 'rest_endpoints', 'keel_defaults_guard_rest_password_arg' );
		add_filter( 'rest_pre_insert_user', 'keel_defaults_validate_rest_password', 10, 2 );
	}

	if ( keel_defaults_enabled( 'limit_unfiltered_html_to_admins' ) ) {
		// Very late, so it has the final say over other user_has_cap filters.
		keel_defaults_add_policy_filter( 'user_has_cap', 'keel_defaults_limit_unfiltered_html', PHP_INT_MAX - 1, 4 );
	}

	if ( keel_defaults_enabled( 'remove_version' ) ) {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	if ( keel_defaults_enabled( 'security_headers' ) ) {
		keel_defaults_add_policy_filter( 'wp_headers', 'keel_defaults_set_content_type_header', 99 );
		keel_defaults_add_policy_filter( 'wp_headers', 'keel_defaults_set_referrer_policy_header', 99 );
	}

	// Framing is its own setting: it is the only one of the three that can break
	// a working site, so it must be switchable without also giving up nosniff.
	if ( '' !== keel_defaults_get( 'frame_options' ) ) {
		keel_defaults_add_policy_filter( 'wp_headers', 'keel_defaults_set_frame_option_header', 99 );
	}

	if ( keel_defaults_enabled( 'disable_ai_connectors' ) && keel_defaults_key_supported( 'disable_ai_connectors' ) ) {
		// WordPress 7.0 gates AI provider connectors behind wp_supports_ai
		// (default true). Returning false stops them registering.
		keel_defaults_add_policy_filter( 'wp_supports_ai', 'keel_defaults_return_false' );

		// Settings → Connectors is where those providers get configured.
		add_action(
			'admin_menu',
			function () {
				remove_submenu_page( 'options-general.php', 'options-connectors.php' );
			},
			11
		);

		// Hiding a menu is not access control — the URL still resolves — so
		// close the screen itself, not just the link to it.
		add_action(
			'admin_init',
			function () {
				global $pagenow;
				if ( 'options-connectors.php' === $pagenow ) {
					wp_die(
						esc_html__( 'AI connectors are disabled on this site.', 'keel-defaults' ),
						'',
						array( 'response' => 403 )
					);
				}
			}
		);

		/**
		 * Seam for AI integrations core does not know about — hook this to
		 * unregister your own providers or hide their UI.
		 */
		do_action( 'keel_disable_ai_connectors' );
	}

	/* ----- Content and public surfaces ----- */

	if ( keel_defaults_enabled( 'disable_comments' ) ) {
		keel_defaults_add_policy_filter( 'comments_open', 'keel_defaults_return_false', 20 );
		keel_defaults_add_policy_filter( 'pings_open', 'keel_defaults_return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 20 );

		add_action(
			'init',
			function () {
				foreach ( get_post_types() as $type ) {
					if ( post_type_supports( $type, 'comments' ) ) {
						remove_post_type_support( $type, 'comments' );
						remove_post_type_support( $type, 'trackbacks' );
					}
				}
			}
		);

		add_action(
			'admin_menu',
			function () {
				remove_menu_page( 'edit-comments.php' );
			}
		);

		add_action(
			'wp_before_admin_bar_render',
			function () {
				global $wp_admin_bar;
				if ( $wp_admin_bar ) {
					$wp_admin_bar->remove_node( 'comments' );
				}
			}
		);

		// New content defaults to comments closed, not just filtered closed.
		add_filter(
			'get_default_comment_status',
			function () {
				return 'closed';
			}
		);

		// Report zero comments. Without this, wp_count_comments() answers zero
		// while get_comments_number() still answers from the post's cached
		// comment_count, so a theme prints "1 Comment" above a thread that no
		// longer exists.
		keel_defaults_add_policy_filter( 'get_comments_number', 'keel_defaults_return_zero', 20 );

		// Drop the comment feeds from the head and feed-link markup, then stop
		// serving the feeds themselves. Removing only the links leaves
		// /comments/feed/ answering 200 for anyone who guesses the URL.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
		add_action( 'template_redirect', 'keel_defaults_block_comment_feeds', 9 );

		// Remove the "Recent Comments" dashboard widget.
		add_action(
			'wp_dashboard_setup',
			function () {
				remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
			}
		);

		// Answer comment queries as empty. comments_array only covers the theme's
		// comment template; without this, /wp/v2/comments still serves every
		// comment on a site that says comments are off.
		keel_defaults_add_policy_filter( 'comments_pre_query', 'keel_defaults_empty_comment_queries', 10, 2 );

		// Take the comment blocks out of the inserter, so the editor stops
		// offering blocks that can only render nothing.
		keel_defaults_add_policy_filter( 'allowed_block_types_all', 'keel_defaults_remove_comment_blocks', PHP_INT_MAX );

		// The inserter only governs what can be added next. A block theme's
		// templates already contain the comment blocks, so without this the
		// front end still prints a "Comments" heading and an empty block shell
		// on every post.
		add_filter( 'render_block', 'keel_defaults_suppress_comment_blocks', 10, 2 );
	}

	if ( keel_defaults_enabled( 'disable_pingbacks' ) ) {
		add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
		add_filter(
			'pre_option_default_ping_status',
			function () {
				return 'closed';
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_self_pingbacks' ) ) {
		add_action(
			'pre_ping',
			function ( &$links ) {
				$home = home_url();
				foreach ( (array) $links as $key => $link ) {
					if ( 0 === strpos( $link, $home ) ) {
						unset( $links[ $key ] );
					}
				}
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_author_archives' ) ) {
		add_action( 'template_redirect', 'keel_defaults_block_author_feeds', 9 );
		add_action( 'template_redirect', 'keel_defaults_redirect_author_archive' );

		/*
		 * Feeds publish author names too, and the redirect above never touches
		 * them. <dc:creator> in RSS and <author><name> in Atom carry the display
		 * name of every post's author, so a site that closed its author archives
		 * to stop enumeration was still handing the same list to anyone who
		 * fetched /feed/. The redirect closed the front door.
		 */
		add_filter( 'the_author', 'keel_defaults_mask_feed_author' );

		/*
		 * Two more routes publish the same name the redirect just closed.
		 *
		 * oEmbed returns author_name and author_url for every post, and the URL
		 * carries the account nicename — so `/wp-json/oembed/1.0/embed` handed
		 * out the login to anyone who asked, on a site that had hidden its
		 * authors. Core's users sitemap is blunter still: it lists every author
		 * archive URL by nicename, which is an enumeration list by construction.
		 *
		 * Both measured live before the fix, with the archive correctly 301ing.
		 */
		add_filter( 'oembed_response_data', 'keel_defaults_strip_oembed_author' );
		add_filter( 'wp_sitemaps_add_provider', 'keel_defaults_drop_users_sitemap', 10, 2 );
	}

	/* ----- Revisions ----- */

	// Core defines WP_POST_REVISIONS=true when wp-config.php does not. Only a
	// non-true value proves that an operator supplied a distinct policy; in that
	// case Keel stands down and the settings control is locked.
	if ( null === keel_defaults_config_lock( 'post_revisions_limit' ) ) {
		keel_defaults_add_policy_filter( 'wp_revisions_to_keep', 'keel_defaults_revision_limit', 10, 2, 'post_revisions_limit' );
	}

	if ( keel_defaults_enabled( 'redirect_attachment_pages' ) ) {
		add_action(
			'template_redirect',
			function () {
				if ( ! is_attachment() ) {
					return;
				}

				$target = keel_defaults_attachment_redirect_target( get_queried_object_id() );

				if ( '' === $target ) {
					return; // Nothing better to offer — let WordPress render the page.
				}

				// wp_safe_redirect() bounces to wp-admin when the host is not on the
				// allowlist, which offloaded media (S3, a CDN) would trigger. Allow
				// this one host rather than dumping visitors on the dashboard.
				add_filter(
					'allowed_redirect_hosts',
					function ( $hosts ) use ( $target ) {
						$host = wp_parse_url( $target, PHP_URL_HOST );
						if ( $host ) {
							$hosts[] = $host;
						}
						return $hosts;
					}
				);

				wp_safe_redirect( $target, 301 );
				exit;
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_emojis' ) ) {
		add_action(
			'init',
			function () {
				remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
				remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
				remove_action( 'wp_print_styles', 'print_emoji_styles' );
				remove_action( 'admin_print_styles', 'print_emoji_styles' );
				remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
				remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
				remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
				add_filter( 'emoji_svg_url', '__return_false' );
				// The classic editor loads emoji support as a TinyMCE plugin, which
				// none of the removals above touch: they cover the front end, the
				// admin head, feeds and mail. Without this, "Disable emojis" still
				// leaves wp-emoji-release.min.js loading inside the editor — the one
				// place a site running the Classic editor default spends its time.
				add_filter( 'tiny_mce_plugins', 'keel_defaults_remove_emoji_tinymce_plugin' );
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_post_passwords' ) ) {
		keel_defaults_add_style( 'admin', 'keel_defaults_hide_post_password_css' );
	}

	/* ----- Editor ----- */

	if ( keel_defaults_enabled( 'force_classic_editor' ) ) {
		keel_defaults_force_classic_editor();
	}

	/* ----- Admin and front-end UX ----- */

	if ( keel_defaults_enabled( 'title_only_admin_search' ) ) {
		// Narrow the search COLUMNS, don't rewrite the whole clause. The
		// post_search_columns filter (WP 6.2+) keeps core's term parsing,
		// -exclusions, and the logged-out post_password guard intact — the
		// blunt posts_search rewrite throws all of that away.
		add_filter(
			'post_search_columns',
			function ( $columns, $search, $query ) {
				if ( is_admin() && $query->is_main_query() ) {
					return array( 'post_title' );
				}
				return $columns;
			},
			10,
			3
		);
	}

	$bar = keel_defaults_get( 'frontend_admin_bar_behavior' );
	if ( 'hide_all' === $bar ) {
		keel_defaults_add_policy_filter( 'show_admin_bar', 'keel_defaults_return_false' );
	} elseif ( 'hide_non_admins' === $bar ) {
		keel_defaults_add_policy_filter(
			'show_admin_bar',
			function ( $show ) {
				return current_user_can( 'manage_options' ) ? $show : false;
			}
		);
	} elseif ( 'auto_hide' === $bar ) {
		keel_defaults_add_style( 'front', 'keel_defaults_auto_hide_admin_bar_css' );
	}

	if ( 'default' !== keel_defaults_get( 'admin_menu_width' ) ) {
		keel_defaults_add_style( 'admin', 'keel_defaults_admin_menu_width_css' );
	}

	if ( keel_defaults_enabled( 'helper_list_columns' ) ) {
		add_action( 'current_screen', 'keel_defaults_register_helper_post_columns' );
		add_filter( 'manage_media_columns', 'keel_defaults_filter_media_columns' );
		add_action( 'manage_media_custom_column', 'keel_defaults_render_media_column', 10, 2 );
		add_filter( 'manage_users_columns', 'keel_defaults_filter_user_columns' );
		add_filter( 'manage_users_custom_column', 'keel_defaults_render_user_column', 10, 3 );
		add_action( 'wp_login', 'keel_defaults_record_last_login', 10, 2 );
	}

	if ( keel_defaults_enabled( 'environment_indicator' ) ) {
		add_action( 'admin_bar_menu', 'keel_defaults_environment_toolbar_item', 7 );
		keel_defaults_add_style( 'admin', 'keel_defaults_environment_css' );
		keel_defaults_add_style( 'front', 'keel_defaults_environment_css' );
	}

	/* ----- Media ----- */

	if ( keel_defaults_enabled( 'lowercase_upload_filenames' ) ) {
		keel_defaults_add_policy_filter( 'sanitize_file_name', 'keel_defaults_lowercase_filename', 20 );
	}

	if ( keel_defaults_enabled( 'media_sizes_panel' ) ) {
		add_action( 'add_meta_boxes_attachment', 'keel_defaults_media_sizes_meta_box' );
	}

	/* ----- Email ----- */

	/*
	 * Registered before the notices, because whether mail is suppressed decides
	 * what those notices should say.
	 */
	if ( keel_defaults_suppresses_mail() ) {
		keel_defaults_add_policy_filter( 'pre_wp_mail', 'keel_defaults_suppress_mail', PHP_INT_MAX, 2 );
		add_action( 'admin_notices', 'keel_defaults_render_mail_suppressed_notice' );
	}

	if ( keel_defaults_enabled( 'mail_failure_notice' ) ) {
		add_action( 'admin_notices', 'keel_defaults_render_mail_config_notice' );
		add_action( 'admin_notices', 'keel_defaults_render_reset_failure_notice' );
		keel_defaults_add_style( 'admin', 'keel_defaults_hide_zero_reset_css' );
	}

	/* ----- Competing plugins ----- */

	/*
	 * Not behind a toggle. Every other entry in this function is a default the
	 * site chose; this is the plugin reporting that something else is
	 * overriding it, which is true whether or not anybody asked. A switch here
	 * would only offer to turn off the bad news.
	 */

	/*
	 * Watch what the governed filters actually settle on.
	 *
	 * Registered everywhere rather than in the admin, because the filters worth
	 * watching mostly fire on the front end and on xmlrpc.php — a setting that
	 * is not taking effect is not going to demonstrate that on a settings
	 * screen. The observers read a value WordPress produced and write only when
	 * the answer changes, so a site with nothing overriding it never writes.
	 */
	keel_defaults_watch_policy_results();

	/*
	 * Forget what was observed when the thing that could have caused it changes.
	 *
	 * A divergence only starts or stops when the plugin set changes or the
	 * setting is saved. Clearing on those events keeps the record no staler than
	 * the last thing that could have altered it, and they fire rarely enough
	 * that an ordinary request pays nothing — which is the whole point of the
	 * observer not reading storage on the healthy path.
	 */
	add_action( 'activated_plugin', 'keel_defaults_forget_policy_divergences' );
	add_action( 'deactivated_plugin', 'keel_defaults_forget_policy_divergences' );
	add_action( 'update_option_' . KEEL_DEFAULTS_OPTION, 'keel_defaults_forget_policy_divergences' );

	add_action( 'admin_notices', 'keel_defaults_render_conflicts_notice' );
	add_action( 'admin_init', 'keel_defaults_handle_conflicts_dismissal' );

	/* ----- Login and sessions ----- */

	if ( keel_defaults_enabled( 'disable_remember_me' ) ) {
		// Strip the submitted value server-side as well as hiding the checkbox, so
		// a forged POST cannot opt back into a persistent session. login_init fires
		// before wp-login.php reads $_POST['rememberme'].
		add_action(
			'login_init',
			function () {
				unset( $_POST['rememberme'], $_REQUEST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			}
		);

		/*
		 * Hide the checkbox with CSS, not script. The server-side strip above is
		 * what disables the feature; this is only about not offering a control the
		 * site will refuse. An inline <script> fails with JavaScript off and is
		 * blocked outright by a strict script-src Content-Security-Policy, which
		 * would leave the checkbox visible and apparently working.
		 */
		keel_defaults_add_style(
			'login',
			static function () {
				return '.login form .forgetmenot { display: none; }';
			}
		);
	}

	/*
	 * One filter sets both session lengths, both in days.
	 *
	 * Priority 50, not 10. This is a policy clamp, and a clamp has to be the last
	 * word: at 10 any plugin filtering `auth_cookie_expiration` at a default
	 * priority lands after it and quietly wins, which is exactly the case a site
	 * sets a session length to prevent.
	 *
	 * The max() at the end is a second belt. Sanitize already keeps a stored
	 * remembered length at or above the regular one, so ticking Remember Me can
	 * never shorten a login — but sanitize only runs when the form is saved. An
	 * option written by WP-CLI, a migration, or another plugin never passes
	 * through it, and this is where that would otherwise show up: a remembered
	 * login that expires sooner than an ordinary one.
	 */
	if ( keel_defaults_session_policy_is_custom() ) {
		keel_defaults_add_policy_filter( 'auth_cookie_expiration', 'keel_defaults_session_length', 50, 3 );
	}

	/* ----- Branding ----- */

	$login_logo = keel_defaults_get( 'login_logo_behavior' );

	if ( 'remove_logo' === $login_logo ) {
		keel_defaults_add_style(
			'login',
			static function () {
				return '#login h1 a, .login h1 a { display:none; }';
			}
		);
	} elseif ( 'replace_logo' === $login_logo ) {
		keel_defaults_add_style( 'login', 'keel_defaults_login_logo_css' );
	}

	// Removing, unlinking, or replacing the logo all repoint the header link
	// at the site home instead of wordpress.org. There is no separate toggle:
	// a replacement/removed logo linking back to wp.org makes no sense.
	if ( in_array( $login_logo, array( 'remove_logo', 'unlink_logo', 'replace_logo' ), true ) ) {
		keel_defaults_add_policy_filter( 'login_headerurl', 'keel_defaults_login_header_url' );
		add_filter(
			'login_headertext',
			function () {
				return get_bloginfo( 'name' );
			}
		);
	}

	/* ----- Performance ----- */

	if ( keel_defaults_enabled( 'throttle_heartbeat' ) ) {
		keel_defaults_add_policy_filter( 'heartbeat_settings', 'keel_defaults_heartbeat_interval' );

		/*
		 * admin_enqueue_scripts, not init. Deregistering at init forces WP_Scripts
		 * to instantiate early — wp_deregister_script() calls wp_scripts(), which
		 * fires wp_default_scripts and registers the whole core script list — on
		 * every dashboard request, well before anything needs it. By
		 * admin_enqueue_scripts the registry exists anyway and the removal is free.
		 */
		add_action( 'admin_enqueue_scripts', 'keel_defaults_drop_dashboard_heartbeat' );
	}
}
