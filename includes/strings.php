<?php
/**
 * Translatable display strings for the settings schema.
 *
 * Kept separate from schema.php so the structural schema stays free of __() and
 * safe to build at plugins_loaded. Every function here runs only at render time
 * (settings page, Site Health, Help tab) — all post-init — so translations
 * resolve and no early text-domain load is triggered.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-setting display copy (label, statement, help, choice/range labels).
 *
 * @return array[]
 */
function keel_defaults_strings() {
	return array(
		'restrict_rest_user_discovery'    => array(
			'label'     => __( 'REST User Discovery', 'keel-defaults' ),
			'statement' => __( 'Hide users from anonymous REST requests', 'keel-defaults' ),
			'help'      => __( 'Stops the REST API from listing account login names to logged-out visitors. Without it, anyone can read <code>/wp/v2/users</code> and collect valid usernames — half of what a password-guessing attack needs, so the attacker no longer has to find the username first.', 'keel-defaults' ),
		),
		'disable_rest'                    => array(
			'label'     => __( 'REST Authentication', 'keel-defaults' ),
			'statement' => __( 'Require authentication for all REST requests', 'keel-defaults' ),
			'help'      => __( 'Blocks anonymous REST entirely and stops advertising the endpoint. The logged-in block editor still works, but public blocks, search, and integrations may not. <code>oembed/1.0</code> stays open so other sites can still embed your posts — it serves only what a published post already shows. Filter: <code>keel_public_rest_routes</code>.', 'keel-defaults' ),
		),
		'xmlrpc_allow_pingbacks'          => array(
			'label'     => __( 'XML-RPC Pingbacks', 'keel-defaults' ),
			'statement' => __( 'Accept incoming pingbacks', 'keel-defaults' ),
			'help'      => __( 'Off by default. Removes <code>pingback.ping</code> — a spam/reflection-DDoS vector — and the <code>X-Pingback</code> header.', 'keel-defaults' ),
		),
		'xmlrpc_allow_remote_publishing'  => array(
			'label'     => __( 'XML-RPC Remote Publishing', 'keel-defaults' ),
			'statement' => __( 'Allow remote publishing (blogging apps)', 'keel-defaults' ),
			'help'      => __( 'Off by default. Removes credential-authenticated <code>wp.*/metaWeblog/MT/blogger</code> methods and the RSD link. Leave it on while Jetpack is active unless connection and feature testing proves it unnecessary.', 'keel-defaults' ),
		),
		'xmlrpc_allow_multicall'          => array(
			'label'     => __( 'XML-RPC Multicall', 'keel-defaults' ),
			'statement' => __( 'Allow <code>system.multicall</code>', 'keel-defaults' ),
			'help'      => __( 'Off by default. <code>system.multicall</code> bundles many XML-RPC calls into one request. WordPress 4.4 removed its old use as a password-guessing multiplier, so refusing it today is minor attack-surface reduction, not a fix for a live threat — almost nothing legitimately uses it, so leaving it off is safe.', 'keel-defaults' ),
		),
		'block_xmlrpc_endpoint'           => array(
			'label'     => __( 'XML-RPC Endpoint', 'keel-defaults' ),
			'statement' => __( 'Block the endpoint entirely (returns 403)', 'keel-defaults' ),
			'help'      => __( 'Strictest tier — <code>xmlrpc.php</code> answers 403 for every request. Leave XML-RPC reachable while Jetpack is active, unless connection and feature testing proves Jetpack no longer needs it. See the XML-RPC help tab for why this runs inside WordPress and when blocking further out is better.', 'keel-defaults' ),
		),
		'disable_application_passwords'   => array(
			'label'     => __( 'Application Passwords', 'keel-defaults' ),
			'statement' => __( 'Prohibit application passwords', 'keel-defaults' ),
			'help'      => __( 'Off by default — application passwords are hashed, revocable, per-application credentials for REST and XML-RPC, and usually the safest way to grant API access. They carry the owning account\'s full access and skip interactive 2FA, so prohibit them only when policy requires every login to pass 2FA or SSO, or when no integration needs API access at all.', 'keel-defaults' ),
		),
		'require_strong_passwords'        => array(
			'label'     => __( 'Password Strength', 'keel-defaults' ),
			'statement' => __( 'Require strong passwords', 'keel-defaults' ),
			'help'      => __( '15+ characters, not your username or email name, not a common choice, and not found in a known breach. Every account is breach-screened, including the roles exempted below — the exemption covers the other rules only. See the Help tab for why the rules are shaped this way and what the breach check sends.', 'keel-defaults' ),
		),
		'password_exempt_roles'           => array(
			'label' => __( 'Password Policy Exemptions', 'keel-defaults' ),
			'help'  => __( 'Roles that skip the length, common-password and username rules; breach screening still applies to everyone. Only roles with no content or settings capabilities appear — which is why Contributor does not. A user is exempt only if every role they hold is listed here. Filter: <code>keel_weak_roles</code>.', 'keel-defaults' ),
		),
		'limit_unfiltered_html_to_admins' => array(
			'label'     => __( 'Unfiltered HTML', 'keel-defaults' ),
			'statement' => __( 'Limit raw HTML and JavaScript to Administrators', 'keel-defaults' ),
			'help'      => __( 'Removes the <code>unfiltered_html</code> capability from Editors and every non-Administrator, so only Administrators (and Super Admins on multisite) can save raw, unfiltered HTML and scripts. Cuts stored-XSS risk from lower-privileged editorial accounts. On by default.', 'keel-defaults' ),
		),
		'remove_version'                  => array(
			'label'     => __( 'Version Fingerprint', 'keel-defaults' ),
			'statement' => __( 'Remove the WordPress version fingerprint', 'keel-defaults' ),
			'help'      => __( 'Strips the generator meta tag. Obscurity, not hardening — it trims scanner noise but does not make an out-of-date site any safer, and the version still leaks from asset query strings and feeds.', 'keel-defaults' ),
		),
		'security_headers'                => array(
			'label'     => __( 'Security Headers', 'keel-defaults' ),
			'statement' => __( 'Send baseline security headers', 'keel-defaults' ),
			'help'      => __( '<code>X-Content-Type-Options</code>: <code>nosniff</code> and <code>Referrer-Policy</code>: <code>strict-origin-when-cross-origin</code>. Both are low-risk. Framing is controlled separately below, because that is the one that can break a site. Already-set headers are never overwritten.', 'keel-defaults' ),
		),
		'frame_options'                   => array(
			'label'   => __( 'Frame Options', 'keel-defaults' ),
			'help'    => __( 'Controls who may embed this site in an iframe. <code>SAMEORIGIN</code> blocks cross-origin framing, which stops clickjacking but also breaks legitimate embeds — a client intranet, a partner site, or a preview/proofing tool — usually as a silent blank frame. Leave unchanged if your host or CDN already sets this header, or if the site is meant to be embedded elsewhere.', 'keel-defaults' ),
			'choices' => array(
				'SAMEORIGIN' => __( 'SAMEORIGIN — only this site may frame it', 'keel-defaults' ),
				'DENY'       => __( 'DENY — nobody may frame it', 'keel-defaults' ),
				''           => __( 'Leave unchanged (host/CDN sets it, or the site is embedded elsewhere)', 'keel-defaults' ),
			),
		),
		'disable_ai_connectors'           => array(
			'label'     => __( 'AI Connectors', 'keel-defaults' ),
			'statement' => __( 'Disable AI provider connectors', 'keel-defaults' ),
			'help'      => __( 'Turns off WordPress 7.0 AI provider connectors via the <code>wp_supports_ai</code> gate and closes the core Connectors screen. Also fires <code>keel_disable_ai_connectors</code> for AI integrations core does not know about.', 'keel-defaults' ),
		),
		'core_update_policy'              => array(
			'label'   => __( 'Core Auto-Updates', 'keel-defaults' ),
			'help'    => __( 'Chooses which core updates install automatically. Minor releases are maintenance and security fixes; major releases are feature updates that can affect themes and plugins. An explicit <code>wp-config.php</code> policy takes precedence, and then this control is locked.', 'keel-defaults' ),
			'choices' => array(
				'minor'   => __( 'Maintenance/security releases only — recommended', 'keel-defaults' ),
				'all'     => __( 'All stable releases', 'keel-defaults' ),
				'manual'  => __( 'No automatic core releases', 'keel-defaults' ),
				'inherit' => __( 'Leave unchanged (WordPress, host, or another plugin decides)', 'keel-defaults' ),
			),
		),
		'auto_update_translations'        => array(
			'label'     => __( 'Translations', 'keel-defaults' ),
			'statement' => __( 'Automatically update translation files', 'keel-defaults' ),
			'help'      => __( 'Installs available WordPress, plugin, and theme language updates. Plugin and theme code updates remain controlled by WordPress\'s individual per-item choices.', 'keel-defaults' ),
		),
		'disable_comments'                => array(
			'label'     => __( 'Comments', 'keel-defaults' ),
			'statement' => __( 'Disable comments, trackbacks, and pingbacks', 'keel-defaults' ),
			'help'      => __( 'Closes comments everywhere, hides existing threads, defaults new content to closed, and reports a count of zero. Removes the admin menu, admin-bar node, Recent Comments dashboard widget, comment feeds, and the comment blocks — both from the inserter and from what a block theme renders. Nothing is deleted: turn this off and every comment comes back.', 'keel-defaults' ),
		),
		'disable_pingbacks'               => array(
			'label'     => __( 'Pingbacks On New Posts', 'keel-defaults' ),
			'statement' => __( 'Close pingbacks and trackbacks on new posts by default', 'keel-defaults' ),
			'help'      => __( 'Sets the "allow pingbacks" default to off for newly created content.', 'keel-defaults' ),
		),
		'disable_self_pingbacks'          => array(
			'label'     => __( 'Self-Pingbacks', 'keel-defaults' ),
			'statement' => __( 'Disable self-pingbacks', 'keel-defaults' ),
			'help'      => __( 'Stops internal links from generating pingback noise.', 'keel-defaults' ),
		),
		'disable_author_archives'         => array(
			'label'     => __( 'Author Archives', 'keel-defaults' ),
			'statement' => __( 'Disable public author archives', 'keel-defaults' ),
			'help'      => __( 'Redirects <code>/author/{slug}/</code> to the home page. Author pages leak usernames (like the REST list above) and are usually thin, duplicate content.', 'keel-defaults' ),
		),
		'redirect_attachment_pages'       => array(
			'label'     => __( 'Attachment Pages', 'keel-defaults' ),
			'statement' => __( 'Redirect attachment pages to the parent post', 'keel-defaults' ),
			'help'      => __( 'Sends thin attachment pages to the parent post, or to the file itself when the media is unattached. Skipped automatically when the theme provides <code>attachment.php</code> or <code>image.php</code>, since the theme means to render them. Core has its own switch since 6.4 (<code>wp_attachment_pages_enabled</code>); this prefers the parent post over the bare file.', 'keel-defaults' ),
		),
		'disable_emojis'                  => array(
			'label'     => __( 'Emoji Script', 'keel-defaults' ),
			'statement' => __( 'Disable the emoji detection script', 'keel-defaults' ),
			'help'      => __( 'Removes the emoji detection script and inline CSS from every page.', 'keel-defaults' ),
		),
		'disable_post_passwords'          => array(
			'label'     => __( 'Post Passwords', 'keel-defaults' ),
			'statement' => __( 'Hide post password protection in the editor', 'keel-defaults' ),
			'help'      => __( 'Hides the "Password protected" visibility option in the editor. WordPress post passwords are weak and are bypassed by full-page caching, which serves the same cached page regardless. Existing password-protected posts keep their field so they stay editable. Off by default.', 'keel-defaults' ),
		),
		'force_classic_editor'            => array(
			'label'     => __( 'Classic Editor', 'keel-defaults' ),
			'statement' => __( 'Use the Classic editor instead of the block editor', 'keel-defaults' ),
			'help'      => __( 'Restores the pre-block editing experience for posts, pages, and custom post types, plus the classic Widgets screen. Front-end display of existing block content is unaffected, and on a block theme the Site Editor stays available. Off by default.', 'keel-defaults' ),
		),
		'lowercase_upload_filenames'      => array(
			'label'     => __( 'Upload Filenames', 'keel-defaults' ),
			'statement' => __( 'Lowercase new upload filenames', 'keel-defaults' ),
			'help'      => __( 'Avoids case-sensitivity surprises when files move between case-insensitive local/staging and case-sensitive Linux production. On by default; only affects new uploads.', 'keel-defaults' ),
		),
		'media_sizes_panel'               => array(
			'label'     => __( 'Image Sizes', 'keel-defaults' ),
			'statement' => __( 'Show generated image sizes on attachments', 'keel-defaults' ),
			'help'      => __( 'Adds a read-only panel to the attachment edit screen listing each generated image size with its dimensions, file size, and URL — a quick way to confirm what WordPress produced. On by default.', 'keel-defaults' ),
		),
		'mail_failure_notice'             => array(
			'label'     => __( 'Email Deliverability', 'keel-defaults' ),
			'statement' => __( 'Warn when site email looks broken', 'keel-defaults' ),
			'help'      => __( 'Warns administrators when site email looks broken: a risky default From address (invalid, or an example/local/test domain) on a non-local site, and a bulk password reset that sent zero emails — replacing WordPress\'s misleading "success" notice. On by default.', 'keel-defaults' ),
		),
		'suppress_nonproduction_mail'     => array(
			'label'     => __( 'Non-Production Email', 'keel-defaults' ),
			'statement' => __( 'Stop outgoing email unless this is the production site', 'keel-defaults' ),
			'help'      => __( 'A database copied from production brings real addresses and whatever mail service production used. A cron run or a bulk action then emails real people from a staging site or a laptop. Does nothing on production, so it cannot be left on by mistake. Override per site with <code>KEEL_ALLOW_NONPRODUCTION_MAIL</code> in <code>wp-config.php</code> or the <code>keel_suppress_nonproduction_mail</code> filter.', 'keel-defaults' ),
		),
		'title_only_admin_search'         => array(
			'label'     => __( 'Admin Search', 'keel-defaults' ),
			'statement' => __( 'Search titles only in admin list tables', 'keel-defaults' ),
			'help'      => __( 'Speeds up admin list-table search on big sites by matching titles only.', 'keel-defaults' ),
		),
		'frontend_admin_bar_behavior'     => array(
			'label'   => __( 'Front-End Admin Bar', 'keel-defaults' ),
			'help'    => __( 'The WordPress toolbar shown across the top of the site for logged-in users. Hide it for non-admins or everyone, or auto-hide it so it slides out of the way and returns on hover or keyboard focus (desktop only).', 'keel-defaults' ),
			'choices' => array(
				''                => __( 'Leave unchanged (WordPress default)', 'keel-defaults' ),
				'hide_non_admins' => __( 'Hide for non-admins', 'keel-defaults' ),
				'hide_all'        => __( 'Hide for everyone', 'keel-defaults' ),
				'auto_hide'       => __( 'Auto-hide, reveal on hover or keyboard focus (desktop)', 'keel-defaults' ),
			),
		),
		'admin_menu_width'                => array(
			'label'  => __( 'Admin Menu Width', 'keel-defaults' ),
			'help'   => __( 'Widens the left admin menu, useful when plugin menu labels are long. WordPress default is 160px. Drag the slider.', 'keel-defaults' ),
			'labels' => array( __( 'WordPress default (160px)', 'keel-defaults' ), __( '200px', 'keel-defaults' ), __( '240px', 'keel-defaults' ), __( '280px', 'keel-defaults' ), __( '300px', 'keel-defaults' ) ),
		),
		'helper_list_columns'             => array(
			'label'     => __( 'Admin List Columns', 'keel-defaults' ),
			'statement' => __( 'Add helper columns to admin list tables', 'keel-defaults' ),
			'help'      => __( 'Adds at-a-glance columns to admin list tables: ID, featured image, and modified date on posts and pages; file size on the Media library; registration and last-login dates on Users. Last login is recorded from when this is enabled onward. Off by default.', 'keel-defaults' ),
		),
		'environment_indicator'           => array(
			'label'     => __( 'Environment Indicator', 'keel-defaults' ),
			'statement' => __( 'Show the current environment in the admin bar', 'keel-defaults' ),
			'help'      => __( 'Adds a color-coded label to the admin bar showing the current environment (Production, Staging, Development, or Local) from <code>wp_get_environment_type()</code> — a quick guard against acting on the wrong site. Common local-development hosts are recognized automatically. Off by default.', 'keel-defaults' ),
		),
		'disable_remember_me'             => array(
			'label'     => __( 'Remember Me', 'keel-defaults' ),
			'statement' => __( 'Disable Remember Me and remove the login checkbox', 'keel-defaults' ),
			'help'      => __( 'Removes the Remember Me checkbox from the login form, so every login uses the regular session length below. Useful for shared or kiosk machines.', 'keel-defaults' ),
		),
		'session_regular_days'            => array(
			'label' => __( 'Regular Session Length', 'keel-defaults' ),
			'unit'  => __( 'days', 'keel-defaults' ),
			'help'  => __( 'How long a normal (non-remembered) login stays signed in. WordPress\'s default is 2 days.', 'keel-defaults' ),
		),
		'remember_me_days'                => array(
			'label' => __( 'Remember Me Length', 'keel-defaults' ),
			'unit'  => __( 'days', 'keel-defaults' ),
			'help'  => __( 'How long a remembered login stays signed in. WordPress\'s default is 14 days. It cannot be shorter than the regular session length above.', 'keel-defaults' ),
		),
		'login_logo_behavior'             => array(
			'label'   => __( 'Login Logo', 'keel-defaults' ),
			'help'    => __( 'The default logo links to wordpress.org, off your site. Left untouched by default, since changing the login screen out of the box is intrusive. Removing, unlinking, or replacing the logo always points the header link at your home page. Replacing uses the logo set in the Customizer, or the site icon if there is no logo; with neither set, the WordPress logo stays.', 'keel-defaults' ),
			'choices' => array(
				'keep_default' => __( 'Keep the WordPress logo and wordpress.org link (WordPress default)', 'keel-defaults' ),
				'remove_logo'  => __( 'Remove the logo and the wordpress.org link', 'keel-defaults' ),
				'unlink_logo'  => __( 'Keep the logo, drop the wordpress.org link', 'keel-defaults' ),
				'replace_logo' => __( 'Replace the logo with your site logo', 'keel-defaults' ),
			),
		),
		'throttle_heartbeat'              => array(
			'label'     => __( 'Heartbeat API', 'keel-defaults' ),
			'statement' => __( 'Slow background admin polling', 'keel-defaults' ),
			'help'      => __( 'Slows admin polling to 60 seconds and drops it on the dashboard home. Off by default: Heartbeat also powers autosave and post-lock warnings, so enabling this will make them update less often.', 'keel-defaults' ),
		),
	);
}

/**
 * Group (fieldset) titles, in display order.
 *
 * @return string[]
 */
function keel_defaults_group_labels() {
	return array(
		'security'    => __( 'Security and Attack Surface', 'keel-defaults' ),
		'updates'     => __( 'Updates', 'keel-defaults' ),
		'content'     => __( 'Content and Public Surfaces', 'keel-defaults' ),
		'editor'      => __( 'Editor', 'keel-defaults' ),
		'media'       => __( 'Media', 'keel-defaults' ),
		'email'       => __( 'Email', 'keel-defaults' ),
		'ux'          => __( 'Admin and Front-End UX', 'keel-defaults' ),
		'login'       => __( 'Login and Sessions', 'keel-defaults' ),
		'branding'    => __( 'Branding', 'keel-defaults' ),
		'performance' => __( 'Performance', 'keel-defaults' ),
	);
}

/**
 * Section titles for grouped toggles.
 *
 * @return string[]
 */
function keel_defaults_section_labels() {
	return array(
		'rest'   => __( 'REST API', 'keel-defaults' ),
		'xmlrpc' => __( 'XML-RPC', 'keel-defaults' ),
	);
}
