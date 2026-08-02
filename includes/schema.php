<?php
/**
 * Settings schema and value access — the data layer that drives the whole plugin.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth: every setting, its default, type, and label.
 *
 * Each entry declares a type ('toggle' yes/no, 'select', 'range', or 'number')
 * and the group (fieldset) it renders under on the settings screen.
 *
 * @return array[]
 */
function keel_defaults_schema() {
	// Pure, static data — no filters touch it — so a request-scoped memo is safe
	// and collapses the ~35 rebuilds/request (bootstrap + Site Health) into one.
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$schema = array(

		// --- Security ---------------------------------------------------
		'restrict_rest_user_discovery'    => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'rest',
			'label'     => 'REST user discovery',
			'statement' => 'Hide users from anonymous REST requests',
			'help'      => 'Stops the REST API from listing account login names to logged-out visitors. Without it, anyone can read <code>/wp/v2/users</code> and collect valid usernames — half of what a password-guessing attack needs, so the attacker no longer has to find the username first.',
		),
		'disable_rest'                    => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'rest',
			'label'     => 'REST authentication',
			'statement' => 'Require authentication for all REST requests',
			'help'      => 'Blocks anonymous REST entirely. The logged-in block editor still works, but public blocks, embeds, search, and integrations may not.',
		),
		'xmlrpc_allow_pingbacks'          => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'xmlrpc',
			'label'     => 'XML-RPC pingbacks',
			'statement' => 'Accept incoming pingbacks',
			'help'      => 'Off by default. Removes <code>pingback.ping</code> — a spam/reflection-DDoS vector — and the <code>X-Pingback</code> header.',
			'depends'   => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'xmlrpc_allow_remote_publishing'  => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'xmlrpc',
			'label'     => 'XML-RPC remote publishing',
			'statement' => 'Allow remote publishing (blogging apps)',
			'help'      => 'Off by default. Removes credential-authenticated <code>wp.*/metaWeblog/MT/blogger</code> methods and the RSD link. Leave it on while Jetpack is active unless connection and feature testing proves it unnecessary.',
			'depends'   => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'xmlrpc_allow_multicall'          => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'xmlrpc',
			'label'     => 'XML-RPC multicall',
			'statement' => 'Allow <code>system.multicall</code>',
			'help'      => 'Off by default. <code>system.multicall</code> bundles many XML-RPC calls into one request. WordPress 4.4 removed its old use as a password-guessing multiplier, so refusing it today is minor attack-surface reduction, not a fix for a live threat — almost nothing legitimately uses it, so leaving it off is safe.',
			'depends'   => array(
				'field'     => 'block_xmlrpc_endpoint',
				'hide_when' => 'yes',
			),
		),
		'block_xmlrpc_endpoint'           => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'section'   => 'xmlrpc',
			'label'     => 'XML-RPC endpoint',
			'statement' => 'Block the endpoint entirely (returns 403)',
			'help'      => 'Strictest tier — <code>xmlrpc.php</code> answers 403 for every request. Do not enable on a Jetpack site (it needs XML-RPC). This runs inside WordPress, so PHP still starts for each blocked request; blocking it further out — at your host, CDN, or firewall, before the request reaches WordPress — is lighter if that option exists.',
		),
		'disable_application_passwords'   => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Application Passwords',
			'statement' => 'Prohibit application passwords',
			'help'      => 'Off by default — application passwords are hashed, revocable, per-application credentials for REST and XML-RPC, and usually the safest way to grant API access. They carry the owning account\'s full access and skip interactive 2FA, so prohibit them only when policy requires every login to pass 2FA or SSO, or when no integration needs API access at all.',
		),
		'require_strong_passwords'        => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Password strength',
			'statement' => 'Require strong passwords',
			'help'      => 'Server-side rule: 15+ characters, screened against Have I Been Pwned breach data — length + screening, not forced composition (per NIST). Enforced for privileged/editorial accounts; low-privilege roles (default: subscriber) are exempt. Adjust with the <code>keel_weak_roles</code> filter.',
		),
		'limit_unfiltered_html_to_admins' => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Unfiltered HTML',
			'statement' => 'Limit raw HTML and JavaScript to Administrators',
			'help'      => 'Removes the <code>unfiltered_html</code> capability from Editors and every non-Administrator, so only Administrators (and Super Admins on multisite) can save raw, unfiltered HTML and scripts. Cuts stored-XSS risk from lower-privileged editorial accounts. On by default.',
		),
		'reserved_usernames'              => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Reserved usernames',
			'statement' => 'Reserve common and system usernames',
			'help'      => 'Refuses to create new accounts with common system/role names (admin, administrator, root, www, support, info, and more) using WordPress\'s <code>illegal_user_logins</code> list — covering registration, the admin Add User screen, REST, and multisite signup. Existing accounts are unaffected. Extend or trim the list with the <code>keel_reserved_usernames</code> filter.',
		),
		'remove_version'                  => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Version fingerprint',
			'statement' => 'Remove the WordPress version fingerprint',
			'help'      => 'Strips the generator meta tag. Obscurity, not hardening — it trims scanner noise but does not make an out-of-date site any safer, and the version still leaks from asset query strings and feeds.',
		),
		'security_headers'                => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'Security headers',
			'statement' => 'Send baseline security headers',
			'help'      => '<code>X-Content-Type-Options</code>: <code>nosniff</code> and <code>Referrer-Policy</code>: <code>strict-origin-when-cross-origin</code>. Both are low-risk. Framing is controlled separately below, because that is the one that can break a site. Already-set headers are never overwritten.',
		),
		'frame_options'                   => array(
			'default' => 'SAMEORIGIN',
			'type'    => 'select',
			'group'   => 'security',
			'label'   => 'Frame options',
			'help'    => 'Controls who may embed this site in an iframe. <code>SAMEORIGIN</code> blocks cross-origin framing, which stops clickjacking but also breaks legitimate embeds — a client intranet, a partner site, or a preview/proofing tool — usually as a silent blank frame. Leave unchanged if your host or CDN already sets this header, or if the site is meant to be embedded elsewhere.',
			'choices' => array(
				'SAMEORIGIN' => 'SAMEORIGIN — only this site may frame it',
				'DENY'       => 'DENY — nobody may frame it',
				''           => 'Leave unchanged (host/CDN sets it, or the site is embedded elsewhere)',
			),
		),
		'disable_ai_connectors'           => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'security',
			'label'     => 'AI connectors',
			'statement' => 'Disable AI provider connectors',
			'help'      => 'Turns off WordPress 7.0 AI provider connectors via the <code>wp_supports_ai</code> gate and closes the core Connectors screen. Also fires <code>keel_disable_ai_connectors</code> for AI integrations core does not know about.',
		),

		// --- Updates ----------------------------------------------------
		'core_update_policy'              => array(
			'default' => 'minor',
			'type'    => 'select',
			'group'   => 'updates',
			'label'   => 'Core auto-updates',
			'help'    => 'Chooses which core updates install automatically. Minor releases are maintenance and security fixes; major releases are feature updates that can affect themes and plugins. An explicit <code>wp-config.php</code> policy takes precedence, and then this control is locked.',
			'choices' => array(
				'minor'   => 'Maintenance/security releases only — recommended',
				'all'     => 'All stable releases',
				'manual'  => 'No automatic core releases',
				'inherit' => 'Leave unchanged (WordPress, host, or another plugin decides)',
			),
		),
		'auto_update_translations'        => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'updates',
			'label'     => 'Translations',
			'statement' => 'Automatically update translation files',
			'help'      => 'Installs available WordPress, plugin, and theme language updates. Plugin and theme code updates remain controlled by WordPress\'s individual per-item choices.',
		),

		// --- Content & public surfaces ---------------------------------
		'disable_comments'                => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Comments',
			'statement' => 'Disable comments, trackbacks, and pingbacks',
			'help'      => 'Closes comments everywhere, hides existing threads, defaults new content to closed, removes the admin menu, admin-bar node, Recent Comments dashboard widget, and comment feeds.',
		),
		'disable_pingbacks'               => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Pingbacks on new posts',
			'statement' => 'Close pingbacks and trackbacks on new posts by default',
			'help'      => 'Sets the "allow pingbacks" default to off for newly created content.',
		),
		'disable_self_pingbacks'          => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Self-pingbacks',
			'statement' => 'Disable self-pingbacks',
			'help'      => 'Stops internal links from generating pingback noise.',
		),
		'disable_author_archives'         => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Author archives',
			'statement' => 'Disable public author archives',
			'help'      => 'Redirects <code>/author/{slug}/</code> to the home page. Author pages leak usernames (like the REST list above) and are usually thin, duplicate content.',
		),
		'redirect_attachment_pages'       => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Attachment pages',
			'statement' => 'Redirect attachment pages to the parent post',
			'help'      => 'Sends thin attachment pages to the parent post, or to the file itself when the media is unattached. Skipped automatically when the theme provides <code>attachment.php</code> or <code>image.php</code>, since the theme means to render them. Core has its own switch since 6.4 (<code>wp_attachment_pages_enabled</code>); this prefers the parent post over the bare file.',
		),
		'disable_emojis'                  => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Emoji script',
			'statement' => 'Disable the emoji detection script',
			'help'      => 'Removes the emoji detection script and inline CSS from every page.',
		),
		'disable_post_passwords'          => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Post passwords',
			'statement' => 'Hide post password protection in the editor',
			'help'      => 'Hides the "Password protected" visibility option in the editor. WordPress post passwords are weak and are bypassed by full-page caching, which serves the same cached page regardless. Existing password-protected posts keep their field so they stay editable. Off by default.',
		),

		// --- Editor ----------------------------------------------------
		'force_classic_editor'            => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'editor',
			'label'     => 'Classic editor',
			'statement' => 'Use the Classic editor instead of the block editor',
			'help'      => 'Restores the pre-block editing experience for posts, pages, and custom post types, plus the classic Widgets screen. Front-end display of existing block content is unaffected, and on a block theme the Site Editor stays available. Off by default.',
		),

		// --- Media -----------------------------------------------------
		'lowercase_upload_filenames'      => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'media',
			'label'     => 'Upload filenames',
			'statement' => 'Lowercase new upload filenames',
			'help'      => 'Avoids case-sensitivity surprises when files move between case-insensitive local/staging and case-sensitive Linux production. On by default; only affects new uploads.',
		),
		'media_sizes_panel'               => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'media',
			'label'     => 'Image sizes',
			'statement' => 'Show generated image sizes on attachments',
			'help'      => 'Adds a read-only panel to the attachment edit screen listing each generated image size with its dimensions, file size, and URL — a quick way to confirm what WordPress produced. On by default.',
		),

		// --- Email -----------------------------------------------------
		'mail_failure_notice'             => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'email',
			'label'     => 'Email deliverability',
			'statement' => 'Warn when site email looks broken',
			'help'      => 'Warns administrators when site email looks broken: a risky default From address (invalid, or an example/local/test domain) on a non-local site, and a bulk password reset that sent zero emails — replacing WordPress\'s misleading "success" notice. On by default.',
		),

		// --- Admin & front-end UX --------------------------------------
		'title_only_admin_search'         => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'ux',
			'label'     => 'Admin search',
			'statement' => 'Search titles only in admin list tables',
			'help'      => 'Speeds up admin list-table search on big sites by matching titles only.',
		),
		'frontend_admin_bar_behavior'     => array(
			'default' => '',
			'type'    => 'select',
			'group'   => 'ux',
			'label'   => 'Front-end admin bar',
			'help'    => 'The WordPress toolbar shown across the top of the site for logged-in users. Hide it for non-admins or everyone, or auto-hide it so it slides out of the way and returns on hover or keyboard focus (desktop only).',
			'choices' => array(
				''                => 'Leave unchanged (WordPress default)',
				'hide_non_admins' => 'Hide for non-admins',
				'hide_all'        => 'Hide for everyone',
				'auto_hide'       => 'Auto-hide, reveal on hover or keyboard focus (desktop)',
			),
		),
		'admin_menu_width'                => array(
			'default' => 'default',
			'type'    => 'range',
			'group'   => 'ux',
			'label'   => 'Admin menu width',
			'help'    => 'Widens the left admin menu, useful when plugin menu labels are long. WordPress default is 160px. Drag the slider.',
			// Ordered stops. The slider posts an index (0–4) which sanitize maps back
			// to the value — this deliberately avoids numeric option keys.
			'values'  => array( 'default', '200', '240', '280', '300' ),
			'labels'  => array( 'WordPress default (160px)', '200px', '240px', '280px', '300px' ),
		),
		'helper_list_columns'             => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'ux',
			'label'     => 'Admin list columns',
			'statement' => 'Add helper columns to admin list tables',
			'help'      => 'Adds at-a-glance columns to admin list tables: ID, featured image, and modified date on posts and pages; file size on the Media library; registration and last-login dates on Users. Last login is recorded from when this is enabled onward. Off by default.',
		),
		'environment_indicator'           => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'ux',
			'label'     => 'Environment indicator',
			'statement' => 'Show the current environment in the admin bar',
			'help'      => 'Adds a color-coded label to the admin bar showing the current environment (Production, Staging, Development, or Local) from <code>wp_get_environment_type()</code> — a quick guard against acting on the wrong site. Hosts ending in .test/.local read as Local. Off by default.',
		),

		// --- Login & sessions ------------------------------------------
		'disable_remember_me'             => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'login',
			'label'     => 'Remember Me',
			'statement' => 'Disable Remember Me and remove the login checkbox',
			'help'      => 'Removes the Remember Me checkbox from the login form, so every login uses the regular session length below. Useful for shared or kiosk machines.',
		),
		'session_regular_days'            => array(
			'default' => 2,
			'type'    => 'number',
			'group'   => 'login',
			'label'   => 'Regular session length (days)',
			'help'    => 'How long a normal (non-remembered) login stays signed in. WordPress\'s default is 2 days.',
			'min'     => 1,
		),
		'remember_me_days'                => array(
			'default' => 14,
			'type'    => 'number',
			'group'   => 'login',
			'label'   => 'Remember Me length (days)',
			'help'    => 'How long a remembered login stays signed in. WordPress\'s default is 14 days. It cannot be shorter than the regular session length above.',
			'min'     => 1,
			'depends' => array(
				'field'     => 'disable_remember_me',
				'hide_when' => 'yes',
			),
		),

		// --- Branding ---------------------------------------------------
		'login_logo_behavior'             => array(
			'default' => 'keep_default',
			'type'    => 'select',
			'group'   => 'branding',
			'label'   => 'Login logo',
			'help'    => 'The default logo links to wordpress.org — a small trust leak. Left untouched by default, since changing the login screen out of the box is intrusive. Removing, unlinking, or replacing the logo always points the header link at your home page.',
			'choices' => array(
				'keep_default' => 'Keep the WordPress logo and wordpress.org link (WordPress default)',
				'remove_logo'  => 'Remove the logo and the wordpress.org link',
				'unlink_logo'  => 'Keep the logo, drop the wordpress.org link',
				'replace_logo' => 'Replace the logo with the site icon',
			),
		),

		// --- Performance ------------------------------------------------
		'throttle_heartbeat'              => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'performance',
			'label'     => 'Heartbeat API',
			'statement' => 'Slow background admin polling',
			'help'      => 'Slows admin polling to 60 seconds and drops it on the dashboard home. Off by default: Heartbeat also powers autosave and post-lock warnings, so enabling this will make them update less often.',
		),
	);

	return $schema;
}

/** Human-friendly group titles for the settings screen. */
function keel_defaults_groups() {
	return array(
		'security'    => 'Security & Attack Surface',
		'updates'     => 'Updates',
		'content'     => 'Content & Public Surfaces',
		'editor'      => 'Editor',
		'media'       => 'Media',
		'email'       => 'Email',
		'ux'          => 'Admin & Front-End UX',
		'login'       => 'Login & Sessions',
		'branding'    => 'Branding',
		'performance' => 'Performance',
	);
}

/**
 * Section titles. Toggles that share a section render as stacked checkboxes under
 * one table row (the WordPress-core pattern, e.g. Discussion's "Default post
 * settings"), instead of one row each.
 *
 * @return string[]
 */
function keel_defaults_sections() {
	return array(
		'rest'   => 'REST API',
		'xmlrpc' => 'XML-RPC',
	);
}

/**
 * Read one setting, falling back to its schema default.
 *
 * @param string $key Schema key (without the keel_ prefix).
 * @return mixed
 */
function keel_defaults_get( $key ) {
	$schema = keel_defaults_schema();
	if ( ! isset( $schema[ $key ] ) ) {
		return null;
	}

	// Deliberately uncached: the option is autoloaded, so get_option() answers
	// from the options cache without a query. A static here would only add a
	// second cache that goes stale the moment anything calls update_option()
	// — which is exactly what saving the settings screen does.
	$stored = get_option( KEEL_DEFAULTS_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_key_exists( $key, $stored ) ? $stored[ $key ] : $schema[ $key ]['default'];
}

/**
 * Convenience boolean check for toggle settings.
 *
 * @param string $key Schema key.
 * @return bool
 */
function keel_defaults_enabled( $key ) {
	return 'yes' === keel_defaults_get( $key );
}
