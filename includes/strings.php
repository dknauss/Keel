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
			'label'     => __( 'REST user discovery', 'keel' ),
			'statement' => __( 'Hide users from anonymous REST requests', 'keel' ),
			'help'      => __( 'Stops the REST API from listing account login names to logged-out visitors. Without it, anyone can read <code>/wp/v2/users</code> and collect valid usernames — half of what a password-guessing attack needs, so the attacker no longer has to find the username first.', 'keel' ),
		),
		'disable_rest'                    => array(
			'label'     => __( 'REST authentication', 'keel' ),
			'statement' => __( 'Require authentication for all REST requests', 'keel' ),
			'help'      => __( 'Blocks anonymous REST entirely and stops advertising the endpoint. The logged-in block editor still works, but public blocks, search, and integrations may not — and because oEmbed is served over REST, other sites can no longer embed your posts.', 'keel' ),
		),
		'xmlrpc_allow_pingbacks'          => array(
			'label'     => __( 'XML-RPC pingbacks', 'keel' ),
			'statement' => __( 'Accept incoming pingbacks', 'keel' ),
			'help'      => __( 'Off by default. Removes <code>pingback.ping</code> — a spam/reflection-DDoS vector — and the <code>X-Pingback</code> header.', 'keel' ),
		),
		'xmlrpc_allow_remote_publishing'  => array(
			'label'     => __( 'XML-RPC remote publishing', 'keel' ),
			'statement' => __( 'Allow remote publishing (blogging apps)', 'keel' ),
			'help'      => __( 'Off by default. Removes credential-authenticated <code>wp.*/metaWeblog/MT/blogger</code> methods and the RSD link. Leave it on while Jetpack is active unless connection and feature testing proves it unnecessary.', 'keel' ),
		),
		'xmlrpc_allow_multicall'          => array(
			'label'     => __( 'XML-RPC multicall', 'keel' ),
			'statement' => __( 'Allow <code>system.multicall</code>', 'keel' ),
			'help'      => __( 'Off by default. <code>system.multicall</code> bundles many XML-RPC calls into one request. WordPress 4.4 removed its old use as a password-guessing multiplier, so refusing it today is minor attack-surface reduction, not a fix for a live threat — almost nothing legitimately uses it, so leaving it off is safe.', 'keel' ),
		),
		'block_xmlrpc_endpoint'           => array(
			'label'     => __( 'XML-RPC endpoint', 'keel' ),
			'statement' => __( 'Block the endpoint entirely (returns 403)', 'keel' ),
			'help'      => __( 'Strictest tier — <code>xmlrpc.php</code> answers 403 for every request. Leave XML-RPC reachable while Jetpack is active, unless connection and feature testing proves Jetpack no longer needs it. This runs inside WordPress, so PHP still starts for each blocked request; blocking it further out — at your host, CDN, or firewall, before the request reaches WordPress — is lighter if that option exists.', 'keel' ),
		),
		'disable_application_passwords'   => array(
			'label'     => __( 'Application Passwords', 'keel' ),
			'statement' => __( 'Prohibit application passwords', 'keel' ),
			'help'      => __( 'Off by default — application passwords are hashed, revocable, per-application credentials for REST and XML-RPC, and usually the safest way to grant API access. They carry the owning account\'s full access and skip interactive 2FA, so prohibit them only when policy requires every login to pass 2FA or SSO, or when no integration needs API access at all.', 'keel' ),
		),
		'require_strong_passwords'        => array(
			'label'     => __( 'Password strength', 'keel' ),
			'statement' => __( 'Require strong passwords', 'keel' ),
			'help'      => __( '15+ characters, not your username or email name, not a common choice, and not found in a known breach. Length and breach screening instead of uppercase or symbol rules, following <a href="https://pages.nist.gov/800-63-4/sp800-63b/authenticators/#passwordver" target="_blank" rel="noopener noreferrer">NIST SP 800-63B-4 § 3.1.1.2</a>; there is no strength meter. The breach check sends <a href="https://haveibeenpwned.com/API/v3#SearchingPwnedPasswordsByRange" target="_blank" rel="noopener noreferrer">Have I Been Pwned</a> only the first five characters of a SHA-1 hash computed here and matches the returned suffixes locally, so neither the password nor its full hash leaves the site; an outage or malformed response fails open. Every account is breach-screened — the rest can be waived below.', 'keel' ),
		),
		'password_exempt_roles'           => array(
			'label' => __( 'Password policy exemptions', 'keel' ),
			'help'  => __( 'Roles that skip the length, common-password and username rules; breach screening still applies to everyone. Only roles with no content or settings capabilities appear — which is why Contributor does not. With several roles, all must be exempt. Filter: <code>keel_weak_roles</code>.', 'keel' ),
		),
		'limit_unfiltered_html_to_admins' => array(
			'label'     => __( 'Unfiltered HTML', 'keel' ),
			'statement' => __( 'Limit raw HTML and JavaScript to Administrators', 'keel' ),
			'help'      => __( 'Removes the <code>unfiltered_html</code> capability from Editors and every non-Administrator, so only Administrators (and Super Admins on multisite) can save raw, unfiltered HTML and scripts. Cuts stored-XSS risk from lower-privileged editorial accounts. On by default.', 'keel' ),
		),
		'remove_version'                  => array(
			'label'     => __( 'Version fingerprint', 'keel' ),
			'statement' => __( 'Remove the WordPress version fingerprint', 'keel' ),
			'help'      => __( 'Strips the generator meta tag. Obscurity, not hardening — it trims scanner noise but does not make an out-of-date site any safer, and the version still leaks from asset query strings and feeds.', 'keel' ),
		),
		'security_headers'                => array(
			'label'     => __( 'Security headers', 'keel' ),
			'statement' => __( 'Send baseline security headers', 'keel' ),
			'help'      => __( '<code>X-Content-Type-Options</code>: <code>nosniff</code> and <code>Referrer-Policy</code>: <code>strict-origin-when-cross-origin</code>. Both are low-risk. Framing is controlled separately below, because that is the one that can break a site. Already-set headers are never overwritten.', 'keel' ),
		),
		'frame_options'                   => array(
			'label'   => __( 'Frame options', 'keel' ),
			'help'    => __( 'Controls who may embed this site in an iframe. <code>SAMEORIGIN</code> blocks cross-origin framing, which stops clickjacking but also breaks legitimate embeds — a client intranet, a partner site, or a preview/proofing tool — usually as a silent blank frame. Leave unchanged if your host or CDN already sets this header, or if the site is meant to be embedded elsewhere.', 'keel' ),
			'choices' => array(
				'SAMEORIGIN' => __( 'SAMEORIGIN — only this site may frame it', 'keel' ),
				'DENY'       => __( 'DENY — nobody may frame it', 'keel' ),
				''           => __( 'Leave unchanged (host/CDN sets it, or the site is embedded elsewhere)', 'keel' ),
			),
		),
		'disable_ai_connectors'           => array(
			'label'     => __( 'AI connectors', 'keel' ),
			'statement' => __( 'Disable AI provider connectors', 'keel' ),
			'help'      => __( 'Turns off WordPress 7.0 AI provider connectors via the <code>wp_supports_ai</code> gate and closes the core Connectors screen. Also fires <code>keel_disable_ai_connectors</code> for AI integrations core does not know about.', 'keel' ),
		),
		'core_update_policy'              => array(
			'label'   => __( 'Core auto-updates', 'keel' ),
			'help'    => __( 'Chooses which core updates install automatically. Minor releases are maintenance and security fixes; major releases are feature updates that can affect themes and plugins. An explicit <code>wp-config.php</code> policy takes precedence, and then this control is locked.', 'keel' ),
			'choices' => array(
				'minor'   => __( 'Maintenance/security releases only — recommended', 'keel' ),
				'all'     => __( 'All stable releases', 'keel' ),
				'manual'  => __( 'No automatic core releases', 'keel' ),
				'inherit' => __( 'Leave unchanged (WordPress, host, or another plugin decides)', 'keel' ),
			),
		),
		'auto_update_translations'        => array(
			'label'     => __( 'Translations', 'keel' ),
			'statement' => __( 'Automatically update translation files', 'keel' ),
			'help'      => __( 'Installs available WordPress, plugin, and theme language updates. Plugin and theme code updates remain controlled by WordPress\'s individual per-item choices.', 'keel' ),
		),
		'disable_comments'                => array(
			'label'     => __( 'Comments', 'keel' ),
			'statement' => __( 'Disable comments, trackbacks, and pingbacks', 'keel' ),
			'help'      => __( 'Closes comments everywhere, hides existing threads, defaults new content to closed, and reports a count of zero. Removes the admin menu, admin-bar node, Recent Comments dashboard widget, comment feeds, and the comment blocks — both from the inserter and from what a block theme renders. Nothing is deleted: turn this off and every comment comes back.', 'keel' ),
		),
		'disable_pingbacks'               => array(
			'label'     => __( 'Pingbacks on new posts', 'keel' ),
			'statement' => __( 'Close pingbacks and trackbacks on new posts by default', 'keel' ),
			'help'      => __( 'Sets the "allow pingbacks" default to off for newly created content.', 'keel' ),
		),
		'disable_self_pingbacks'          => array(
			'label'     => __( 'Self-pingbacks', 'keel' ),
			'statement' => __( 'Disable self-pingbacks', 'keel' ),
			'help'      => __( 'Stops internal links from generating pingback noise.', 'keel' ),
		),
		'disable_author_archives'         => array(
			'label'     => __( 'Author archives', 'keel' ),
			'statement' => __( 'Disable public author archives', 'keel' ),
			'help'      => __( 'Redirects <code>/author/{slug}/</code> to the home page. Author pages leak usernames (like the REST list above) and are usually thin, duplicate content.', 'keel' ),
		),
		'redirect_attachment_pages'       => array(
			'label'     => __( 'Attachment pages', 'keel' ),
			'statement' => __( 'Redirect attachment pages to the parent post', 'keel' ),
			'help'      => __( 'Sends thin attachment pages to the parent post, or to the file itself when the media is unattached. Skipped automatically when the theme provides <code>attachment.php</code> or <code>image.php</code>, since the theme means to render them. Core has its own switch since 6.4 (<code>wp_attachment_pages_enabled</code>); this prefers the parent post over the bare file.', 'keel' ),
		),
		'disable_emojis'                  => array(
			'label'     => __( 'Emoji script', 'keel' ),
			'statement' => __( 'Disable the emoji detection script', 'keel' ),
			'help'      => __( 'Removes the emoji detection script and inline CSS from every page.', 'keel' ),
		),
		'disable_post_passwords'          => array(
			'label'     => __( 'Post passwords', 'keel' ),
			'statement' => __( 'Hide post password protection in the editor', 'keel' ),
			'help'      => __( 'Hides the "Password protected" visibility option in the editor. WordPress post passwords are weak and are bypassed by full-page caching, which serves the same cached page regardless. Existing password-protected posts keep their field so they stay editable. Off by default.', 'keel' ),
		),
		'force_classic_editor'            => array(
			'label'     => __( 'Classic editor', 'keel' ),
			'statement' => __( 'Use the Classic editor instead of the block editor', 'keel' ),
			'help'      => __( 'Restores the pre-block editing experience for posts, pages, and custom post types, plus the classic Widgets screen. Front-end display of existing block content is unaffected, and on a block theme the Site Editor stays available. Off by default.', 'keel' ),
		),
		'lowercase_upload_filenames'      => array(
			'label'     => __( 'Upload filenames', 'keel' ),
			'statement' => __( 'Lowercase new upload filenames', 'keel' ),
			'help'      => __( 'Avoids case-sensitivity surprises when files move between case-insensitive local/staging and case-sensitive Linux production. On by default; only affects new uploads.', 'keel' ),
		),
		'media_sizes_panel'               => array(
			'label'     => __( 'Image sizes', 'keel' ),
			'statement' => __( 'Show generated image sizes on attachments', 'keel' ),
			'help'      => __( 'Adds a read-only panel to the attachment edit screen listing each generated image size with its dimensions, file size, and URL — a quick way to confirm what WordPress produced. On by default.', 'keel' ),
		),
		'mail_failure_notice'             => array(
			'label'     => __( 'Email deliverability', 'keel' ),
			'statement' => __( 'Warn when site email looks broken', 'keel' ),
			'help'      => __( 'Warns administrators when site email looks broken: a risky default From address (invalid, or an example/local/test domain) on a non-local site, and a bulk password reset that sent zero emails — replacing WordPress\'s misleading "success" notice. On by default.', 'keel' ),
		),
		'title_only_admin_search'         => array(
			'label'     => __( 'Admin search', 'keel' ),
			'statement' => __( 'Search titles only in admin list tables', 'keel' ),
			'help'      => __( 'Speeds up admin list-table search on big sites by matching titles only.', 'keel' ),
		),
		'frontend_admin_bar_behavior'     => array(
			'label'   => __( 'Front-end admin bar', 'keel' ),
			'help'    => __( 'The WordPress toolbar shown across the top of the site for logged-in users. Hide it for non-admins or everyone, or auto-hide it so it slides out of the way and returns on hover or keyboard focus (desktop only).', 'keel' ),
			'choices' => array(
				''                => __( 'Leave unchanged (WordPress default)', 'keel' ),
				'hide_non_admins' => __( 'Hide for non-admins', 'keel' ),
				'hide_all'        => __( 'Hide for everyone', 'keel' ),
				'auto_hide'       => __( 'Auto-hide, reveal on hover or keyboard focus (desktop)', 'keel' ),
			),
		),
		'admin_menu_width'                => array(
			'label'  => __( 'Admin menu width', 'keel' ),
			'help'   => __( 'Widens the left admin menu, useful when plugin menu labels are long. WordPress default is 160px. Drag the slider.', 'keel' ),
			'labels' => array( __( 'WordPress default (160px)', 'keel' ), __( '200px', 'keel' ), __( '240px', 'keel' ), __( '280px', 'keel' ), __( '300px', 'keel' ) ),
		),
		'helper_list_columns'             => array(
			'label'     => __( 'Admin list columns', 'keel' ),
			'statement' => __( 'Add helper columns to admin list tables', 'keel' ),
			'help'      => __( 'Adds at-a-glance columns to admin list tables: ID, featured image, and modified date on posts and pages; file size on the Media library; registration and last-login dates on Users. Last login is recorded from when this is enabled onward. Off by default.', 'keel' ),
		),
		'environment_indicator'           => array(
			'label'     => __( 'Environment indicator', 'keel' ),
			'statement' => __( 'Show the current environment in the admin bar', 'keel' ),
			'help'      => __( 'Adds a color-coded label to the admin bar showing the current environment (Production, Staging, Development, or Local) from <code>wp_get_environment_type()</code> — a quick guard against acting on the wrong site. Common local-development hosts are recognized automatically. Off by default.', 'keel' ),
		),
		'disable_remember_me'             => array(
			'label'     => __( 'Remember Me', 'keel' ),
			'statement' => __( 'Disable Remember Me and remove the login checkbox', 'keel' ),
			'help'      => __( 'Removes the Remember Me checkbox from the login form, so every login uses the regular session length below. Useful for shared or kiosk machines.', 'keel' ),
		),
		'session_regular_days'            => array(
			'label' => __( 'Regular session length', 'keel' ),
			'unit'  => __( 'days', 'keel' ),
			'help'  => __( 'How long a normal (non-remembered) login stays signed in. WordPress\'s default is 2 days.', 'keel' ),
		),
		'remember_me_days'                => array(
			'label' => __( 'Remember Me length', 'keel' ),
			'unit'  => __( 'days', 'keel' ),
			'help'  => __( 'How long a remembered login stays signed in. WordPress\'s default is 14 days. It cannot be shorter than the regular session length above.', 'keel' ),
		),
		'login_logo_behavior'             => array(
			'label'   => __( 'Login logo', 'keel' ),
			'help'    => __( 'The default logo links to wordpress.org — a small trust leak. Left untouched by default, since changing the login screen out of the box is intrusive. Removing, unlinking, or replacing the logo always points the header link at your home page.', 'keel' ),
			'choices' => array(
				'keep_default' => __( 'Keep the WordPress logo and wordpress.org link (WordPress default)', 'keel' ),
				'remove_logo'  => __( 'Remove the logo and the wordpress.org link', 'keel' ),
				'unlink_logo'  => __( 'Keep the logo, drop the wordpress.org link', 'keel' ),
				'replace_logo' => __( 'Replace the logo with the site icon', 'keel' ),
			),
		),
		'throttle_heartbeat'              => array(
			'label'     => __( 'Heartbeat API', 'keel' ),
			'statement' => __( 'Slow background admin polling', 'keel' ),
			'help'      => __( 'Slows admin polling to 60 seconds and drops it on the dashboard home. Off by default: Heartbeat also powers autosave and post-lock warnings, so enabling this will make them update less often.', 'keel' ),
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
		'security'    => __( 'Security and Attack Surface', 'keel' ),
		'updates'     => __( 'Updates', 'keel' ),
		'content'     => __( 'Content and Public Surfaces', 'keel' ),
		'editor'      => __( 'Editor', 'keel' ),
		'media'       => __( 'Media', 'keel' ),
		'email'       => __( 'Email', 'keel' ),
		'ux'          => __( 'Admin and Front-End UX', 'keel' ),
		'login'       => __( 'Login and Sessions', 'keel' ),
		'branding'    => __( 'Branding', 'keel' ),
		'performance' => __( 'Performance', 'keel' ),
	);
}

/**
 * Section titles for grouped toggles.
 *
 * @return string[]
 */
function keel_defaults_section_labels() {
	return array(
		'rest'   => __( 'REST API', 'keel' ),
		'xmlrpc' => __( 'XML-RPC', 'keel' ),
	);
}
