<?php
/**
 * Plugin Name:       Keel Defaults
 * Plugin URI:        https://github.com/dknauss/keel
 * Description:        Sane, individually-toggleable defaults for every new WordPress site — security, updates, privacy, UX, and performance. Configure under Settings → Keel.
 * Version:           0.1.0-dev
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Dan Knauss
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       keel
 * Domain Path:       /languages
 *
 * Keel is a de-branded evolution of "Better by Default" (WPYEG,
 * https://github.com/WPYEG/Better-by-Default), used under the GPL-3.0-or-later.
 * Original copyright is retained; see LICENSE and CREDITS.md.
 *
 * Architecture: every default is one entry in keel_defaults_schema() plus one
 * bootstrap `if`-block — a default is an opinionated filter behind a toggle.
 * Read the schema array first; it is the map.
 *
 * @package Keel
 */

// Bail if called directly.
defined( 'ABSPATH' ) || exit;

// Load translations shipped in /languages (e.g. the en_CA set).
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'keel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

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
			'help'    => 'Maintenance and security releases install automatically by default. Major releases should be tested and deployed within 30 days. An explicit <code>wp-config.php</code> policy takes precedence.',
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
			'label'     => 'Default ping status',
			'statement' => 'Close pings on new content by default',
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
			'help'      => 'Redirects <code>/author/{slug}/</code> to home (another enumeration + thin-content fix).',
		),
		'redirect_attachment_pages'       => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Attachment pages',
			'statement' => 'Redirect attachment pages to the parent post',
			'help'      => 'Sends thin attachment pages to the parent post, or to the file itself when the media is unattached. Skipped automatically when the theme provides <code>attachment.php</code> or <code>image.php</code>, since that theme meant to render them. Core has its own switch since 6.4 (<code>wp_attachment_pages_enabled</code>); this prefers the parent post over the bare file.',
		),
		'disable_emojis'                  => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Emoji script',
			'statement' => 'Disable the emoji detection script',
			'help'      => 'Removes the emoji detection script + inline CSS from every page.',
		),
		'disable_post_passwords'          => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'content',
			'label'     => 'Post passwords',
			'statement' => 'Hide post password protection in the editor',
			'help'      => 'Hides the "Password protected" visibility option in the editor. WordPress post passwords are weak and are bypassed by full-page caching, which serves the same cached page regardless. Existing password-protected posts keep their field so they stay editable. Off by default. Hides editor UI only — re-check after major WordPress editor changes.',
		),

		// --- Editor ----------------------------------------------------
		'force_classic_editor'            => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'editor',
			'label'     => 'Classic editor',
			'statement' => 'Force the Classic editor',
			'help'      => 'Restores the pre-block editing experience for posts, pages, and custom post types, plus the classic Widgets screen. Front-end display of existing block content is unaffected, and on a block theme the Site Editor stays available. Off by default.',
		),

		// --- Media -----------------------------------------------------
		'lowercase_upload_filenames'      => array(
			'default'   => 'yes',
			'type'      => 'toggle',
			'group'     => 'media',
			'label'     => 'Upload filenames',
			'statement' => 'Lowercase new upload filenames',
			'help'      => 'Lowercases new upload filenames, avoiding case-sensitivity surprises when files move between local/staging (case-insensitive) and Linux production (case-sensitive). On by default; only affects new uploads.',
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
			'help'    => 'Control the floating admin bar on the front of the site.',
			'choices' => array(
				''                => 'Leave unchanged (WordPress default)',
				'hide_non_admins' => 'Hide for non-admins',
				'hide_all'        => 'Hide for everyone',
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
			'help'      => 'Adds a colour-coded label to the admin bar showing the current environment (Production, Staging, Development, or Local) from <code>wp_get_environment_type</code>() — a quick guard against acting on the wrong site. Hosts ending in .test/.local read as Local. Off by default.',
		),

		// --- Login & sessions ------------------------------------------
		'disable_remember_me'             => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'login',
			'label'     => 'Remember Me',
			'statement' => 'Disable Remember Me and remove the login checkbox',
			'help'      => 'Hides the checkbox and caps sessions short. Good for shared/kiosk machines.',
		),
		'remember_me_days'                => array(
			'default' => 5,
			'type'    => 'number',
			'group'   => 'login',
			'label'   => 'Remember Me length (days)',
			'help'    => 'Caps the persistent session. Core default is 14. Set 0 to leave core alone.',
			'depends' => array(
				'field'     => 'disable_remember_me',
				'hide_when' => 'yes',
			),
		),
		'session_regular_hours'           => array(
			'default' => 0,
			'type'    => 'number',
			'group'   => 'login',
			'label'   => 'Regular session length (hours)',
			'help'    => 'Length of a non-remembered login. 0 = leave the core default (2 days).',
		),

		// --- Branding ---------------------------------------------------
		'login_logo_behavior'             => array(
			'default' => 'keep_default',
			'type'    => 'select',
			'group'   => 'branding',
			'label'   => 'Login logo',
			'help'    => 'The default logo links to wordpress.org — a small trust leak. Left untouched by default, since changing the login screen out of the box is intrusive. Removing, unlinking, or replacing the logo always points the header link at your home page.',
			'choices' => array(
				'keep_default' => 'Keep the WordPress logo and wp.org link (WordPress default)',
				'remove_logo'  => 'Remove the logo and the wp.org link',
				'unlink_logo'  => 'Keep the logo, drop the wp.org link',
				'replace_logo' => 'Replace the logo with the site icon',
			),
		),

		// --- Performance ------------------------------------------------
		'throttle_heartbeat'              => array(
			'default'   => 'no',
			'type'      => 'toggle',
			'group'     => 'performance',
			'label'     => 'Heartbeat API',
			'statement' => 'Throttle the Heartbeat API',
			'help'      => 'Slows admin polling to 60s and drops it on the dashboard home.',
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

/** The single option name that stores all settings as one array. */
const KEEL_DEFAULTS_OPTION = 'keel_settings';

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

/**
 * Apply Keel's maintenance/security core update policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_minor_core_updates( $enabled ) {
	$policy = keel_defaults_get( 'core_update_policy' );

	if ( 'inherit' === $policy ) {
		return $enabled;
	}

	return in_array( $policy, array( 'minor', 'all' ), true );
}

/**
 * Apply Keel's stable major core update policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_major_core_updates( $enabled ) {
	$policy = keel_defaults_get( 'core_update_policy' );

	if ( 'inherit' === $policy ) {
		return $enabled;
	}

	return 'all' === $policy;
}

/**
 * Keep development builds out of every explicit stable-release policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_dev_core_updates( $enabled ) {
	return 'inherit' === keel_defaults_get( 'core_update_policy' ) ? $enabled : false;
}

/**
 * Apply the translation update toggle.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_translation_updates( $enabled ) {
	unset( $enabled );
	return keel_defaults_enabled( 'auto_update_translations' );
}


/**
 * Remove `unfiltered_html` from non-Administrators when the hardening default is on.
 *
 * Runs on `user_has_cap`, so it sees the capability map WordPress resolved for a
 * user and drops `unfiltered_html` for anyone who is not an Administrator (or, on
 * multisite, a Super Admin). Editors hold `unfiltered_html` by default on
 * single-site installs — enough to save a raw `<script>` — so this closes that.
 *
 * Recursion trap — the reason this reads roles/caps directly, and why the
 * `is_super_admin()` call is guarded by `is_multisite()`: this runs *inside* the
 * `user_has_cap` filter, so any capability check it performs re-enters the same
 * filter and recurses until the stack blows. Decide only from `$user->roles` and
 * the already-resolved `$allcaps` — never `current_user_can()` / `user_can()`.
 * `is_super_admin()` is safe *only* on multisite, where it consults the network
 * super-admin list; on single-site it internally calls `has_cap( 'delete_users' )`,
 * which would recurse — hence the `is_multisite()` guard in front of it.
 *
 * @param array    $allcaps User's resolved capabilities.
 * @param array    $caps    Required primitive caps (unused).
 * @param array    $args    Context args (unused).
 * @param \WP_User $user    The user being checked.
 * @return array
 */
function keel_defaults_limit_unfiltered_html( $allcaps, $caps, $args, $user ) {
	if ( empty( $allcaps['unfiltered_html'] ) ) {
		return $allcaps;
	}

	$roles = ( isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();

	// Administrators keep it. `manage_options` is already in $allcaps (the
	// resolved-cap proxy for "is an admin"), so reading it here does not recurse.
	if ( in_array( 'administrator', $roles, true ) || ! empty( $allcaps['manage_options'] ) ) {
		return $allcaps;
	}

	// Super Admins keep it on multisite. See the recursion note above for why
	// is_super_admin() is only called behind is_multisite().
	if ( is_multisite() && isset( $user->ID ) && is_super_admin( $user->ID ) ) {
		return $allcaps;
	}

	$allcaps['unfiltered_html'] = false;

	return $allcaps;
}

/**
 * Common system, role, and generic usernames reserved from new-account creation.
 *
 * Returned through the `illegal_user_logins` core filter, so WordPress refuses to
 * *create* an account with any of these names — across registration, the admin
 * Add User screen, the REST users endpoint, and multisite signup (core lower-cases
 * both sides, so entries are lower-case here). Existing accounts are never touched.
 * The names attackers guess first (`admin`, `administrator`, `root`) sit alongside
 * generic role mailboxes that make weak, predictable logins. Filter
 * `keel_reserved_usernames` to extend or trim the list.
 *
 * @return string[] Reserved usernames.
 */
function keel_reserved_usernames_list() {
	$reserved = array(
		'abuse',
		'access',
		'admin',
		'administrator',
		'backup',
		'billing',
		'blog',
		'business',
		'client',
		'compliance',
		'contact',
		'data',
		'demo',
		'devnull',
		'dns',
		'doctor',
		'ftp',
		'guest',
		'hostmaster',
		'info',
		'information',
		'inoc',
		'internet',
		'ispfeedback',
		'ispsupport',
		'list',
		'list-request',
		'login',
		'maildaemon',
		'manager',
		'marketing',
		'master',
		'mysql',
		'noc',
		'no-reply',
		'noreply',
		'null',
		'number',
		'office',
		'pass',
		'password',
		'phish',
		'phishing',
		'postmaster',
		'privacy',
		'public',
		'registrar',
		'root',
		'sales',
		'security',
		'server',
		'service',
		'spam',
		'sql',
		'support',
		'sysadmin',
		'tech',
		'test',
		'tester',
		'undisclosed-recipients',
		'unsubscribe',
		'user',
		'user2',
		'username',
		'usenet',
		'uucp',
		'webmaster',
		'www',
	);

	return apply_filters( 'keel_reserved_usernames', $reserved );
}

/**
 * Merge the reserved list into core's `illegal_user_logins`.
 *
 * Merges rather than replaces, so a host or another plugin that already reserves
 * names keeps theirs.
 *
 * @param array $logins Illegal logins gathered from other sources.
 * @return array
 */
function keel_defaults_reserved_usernames( $logins ) {
	return array_merge( (array) $logins, keel_reserved_usernames_list() );
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
	add_filter( 'use_block_editor_for_post', '__return_false' );
	add_filter( 'use_block_editor_for_post_type', '__return_false' );
	add_filter( 'gutenberg_can_edit_post', '__return_false' );  // Standalone Gutenberg feature plugin.
	add_filter( 'use_widgets_block_editor', '__return_false' ); // Classic Widgets screen.
}

/**
 * Hide the "Password protected" visibility option in the post editor.
 *
 * WordPress post passwords are weak and are bypassed by full-page caches, so this
 * steers editors away from them by removing the option from the editor. It is
 * cosmetic and non-destructive — it hides UI only, changes no data or behaviour,
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
 * Hooked to sanitize_file_name at priority 20 (after core sanitisation), so
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
 * 1. `!important` — the base width lives in the colour-scheme stylesheet
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
	$columns['keel_id']       = __( 'ID', 'keel' );
	$columns['keel_thumb']    = __( 'Image', 'keel' );
	$columns['keel_modified'] = __( 'Modified', 'keel' );
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
	$columns['keel_file_size'] = __( 'File size', 'keel' );
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
	$columns['keel_registered'] = __( 'Registered', 'keel' );
	$columns['keel_last_login'] = __( 'Last login', 'keel' );
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
	add_meta_box( 'keel-media-sizes', __( 'Generated Image Sizes', 'keel' ), 'keel_defaults_render_media_sizes_meta_box', 'attachment', 'normal', 'low' );
}

/**
 * Render the generated-image-sizes table.
 *
 * @param \WP_Post $post Attachment post.
 */
function keel_defaults_render_media_sizes_meta_box( $post ) {
	$rows = keel_defaults_attachment_image_size_rows( $post->ID );

	if ( empty( $rows ) ) {
		echo '<p>' . esc_html__( 'No generated image sizes were found for this attachment.', 'keel' ) . '</p>';
		return;
	}

	echo '<p>' . esc_html__( 'Read-only view of the generated image files and URLs.', 'keel' ) . '</p>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Size', 'keel' ) . '</th><th>' . esc_html__( 'Dimensions', 'keel' ) . '</th><th>' . esc_html__( 'File size', 'keel' ) . '</th><th>' . esc_html__( 'URL', 'keel' ) . '</th></tr></thead><tbody>';
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
			'file_size'  => $bytes ? size_format( $bytes ) : __( 'Unknown', 'keel' ),
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

/**
 * Environment indicator definitions (label, dashicon, colours) keyed by the
 * WordPress environment type. Filterable via keel_environments.
 *
 * @return array
 */
function keel_environments() {
	$defaults = array(
		'production'  => array(
			'label'            => __( 'Production', 'keel' ),
			'icon'             => 'dashicons-admin-site',
			'background_color' => '#b92a2a',
			'text_color'       => '#fff',
		),
		'staging'     => array(
			'label'            => __( 'Staging', 'keel' ),
			'icon'             => 'dashicons-admin-generic',
			'background_color' => '#d79d00',
			'text_color'       => '#fff',
		),
		'development' => array(
			'label'            => __( 'Development', 'keel' ),
			'icon'             => 'dashicons-admin-tools',
			'background_color' => '#34863b',
			'text_color'       => '#fff',
		),
		'local'       => array(
			'label'            => __( 'Local', 'keel' ),
			'icon'             => 'dashicons-admin-home',
			'background_color' => '#0073aa',
			'text_color'       => '#fff',
		),
	);

	$environments = apply_filters( 'keel_environments', $defaults );

	return is_array( $environments ) ? wp_parse_args( $environments, $defaults ) : $defaults;
}

/**
 * The current environment, treating .test/.local hosts as local when no
 * WP_ENVIRONMENT_TYPE constant is set.
 *
 * @return string
 */
function keel_current_environment() {
	$type = wp_get_environment_type();

	if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
		$home = untrailingslashit( home_url() );
		foreach ( array( '.test', '.local' ) as $suffix ) {
			if ( substr( $home, - strlen( $suffix ) ) === $suffix ) {
				return 'local';
			}
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

	$type        = keel_current_environment();
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
 * Reduce a filter-supplied colour to something safe to interpolate into a CSS
 * declaration.
 *
 * The threat in a value position is not HTML injection but a value that
 * terminates its own declaration and opens a new rule, or opens a CSS comment.
 * This strips the characters that could do either (notably `;`, `}`, `:`, and
 * `*`) while leaving every documented colour form intact: hex, named colours,
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
	 * colour-coded icon. Clip (not display:none) keeps the label in the
	 * accessibility tree — the icon is aria-hidden, so the label is the node's
	 * only accessible name and must survive for screen-reader and zoom users.
	 */
	$css .= ' @media screen and (min-width: 783px) and (max-width: 960px) {';
	$css .= ' #wpadminbar .keel-environment-indicator .ab-label { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }';
	$css .= ' #wpadminbar .keel-environment-indicator .ab-icon { margin-right: 0; }';
	$css .= ' }';

	// CSS is raw text: values are sanitised per-interpolation above, and
	// wp_strip_all_tags guards against a </style> breakout.
	printf( '<style id="keel-environment-indicator">%s</style>', wp_strip_all_tags( $css ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS escaped per-value above.
}

/**
 * Find a header's actual array key, matching case-insensitively, so a caller can
 * overwrite in place instead of adding a second key that differs only in case.
 *
 * @param mixed  $headers Headers array from the wp_headers filter.
 * @param string $name    Header name.
 * @return string|null
 */
function keel_defaults_find_header_key( $headers, $name ) {
	if ( ! is_array( $headers ) ) {
		return null;
	}
	foreach ( array_keys( $headers ) as $key ) {
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return (string) $key;
		}
	}
	return null;
}

/**
 * Relative strength of an X-Frame-Options value. Only the two values browsers
 * honour are ranked; anything else returns null so callers leave the response
 * alone (this keeps a deprecated ALLOW-FROM's permissive intent intact).
 *
 * @param mixed $value Header value.
 * @return int|null 2 = DENY, 1 = SAMEORIGIN, null = unrecognised.
 */
function keel_defaults_frame_option_strength( $value ) {
	switch ( strtoupper( trim( (string) $value ) ) ) {
		case 'DENY':
			return 2;
		case 'SAMEORIGIN':
			return 1;
		default:
			return null;
	}
}

/**
 * Set X-Frame-Options, deferring to a header another layer already set unless the
 * configured value is strictly stricter — so a host's DENY is never downgraded to
 * SAMEORIGIN, and a deliberately configured DENY still tightens a weaker existing
 * value. Writes back to the existing key so a differently cased key does not emit
 * a second header line. Opt out with keel_disable_x_frame_options.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_frame_option_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_x_frame_options', false ) ) {
		return $headers;
	}

	$value        = apply_filters( 'keel_x_frame_options', keel_defaults_get( 'frame_options' ) );
	$existing_key = keel_defaults_find_header_key( $headers, 'X-Frame-Options' );

	if ( null !== $existing_key ) {
		$existing_strength   = keel_defaults_frame_option_strength( $headers[ $existing_key ] );
		$configured_strength = keel_defaults_frame_option_strength( $value );

		if ( null === $existing_strength || null === $configured_strength ) {
			return $headers;
		}
		if ( $configured_strength <= $existing_strength ) {
			return $headers;
		}

		$headers[ $existing_key ] = $value;
		return $headers;
	}

	$headers['X-Frame-Options'] = $value;
	return $headers;
}

/**
 * Set X-Content-Type-Options: nosniff. `nosniff` is this header's only effective
 * value, so any other existing value (empty, off, a typo) is corrected in place
 * rather than deferred to. Opt out with keel_disable_x_content_type_options.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_content_type_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_x_content_type_options', false ) ) {
		return $headers;
	}

	$existing_key = keel_defaults_find_header_key( $headers, 'X-Content-Type-Options' );

	if ( null !== $existing_key ) {
		if ( 'nosniff' === strtolower( trim( (string) $headers[ $existing_key ] ) ) ) {
			return $headers;
		}
		$headers[ $existing_key ] = 'nosniff';
		return $headers;
	}

	$headers['X-Content-Type-Options'] = 'nosniff';
	return $headers;
}

/**
 * Set a baseline Referrer-Policy. Unlike the other two headers, Referrer-Policy
 * has no single strictness axis across its tokens, so an existing policy is
 * deferred to rather than second-guessed. Value filterable via
 * keel_referrer_policy; opt out with keel_disable_referrer_policy.
 *
 * @param array $headers Headers.
 * @return array
 */
function keel_defaults_set_referrer_policy_header( $headers ) {
	if ( true === apply_filters( 'keel_disable_referrer_policy', false ) ) {
		return $headers;
	}

	if ( null !== keel_defaults_find_header_key( $headers, 'Referrer-Policy' ) ) {
		return $headers;
	}

	$value = apply_filters( 'keel_referrer_policy', 'strict-origin-when-cross-origin' );
	if ( '' === trim( (string) $value ) ) {
		return $headers;
	}

	$headers['Referrer-Policy'] = $value;
	return $headers;
}

/**
 * Register Keel's read-only Site Health posture test.
 *
 * @param array $tests Registered Site Health tests.
 * @return array
 */
function keel_defaults_site_health_tests( $tests ) {
	if ( ! is_array( $tests ) ) {
		return $tests;
	}
	$tests['direct']['keel_defaults_posture'] = array(
		'label' => __( 'Keel Defaults', 'keel' ),
		'test'  => 'keel_defaults_site_health_posture',
	);
	return $tests;
}

/**
 * Human-readable current state of one setting, for the posture summary.
 *
 * @param array $field Schema field.
 * @param mixed $value Current value.
 * @return string
 */
function keel_defaults_state_label( $field, $value ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'toggle';

	if ( 'toggle' === $type ) {
		return ( 'yes' === $value ) ? __( 'On', 'keel' ) : __( 'Off', 'keel' );
	}
	if ( 'select' === $type ) {
		if ( '' === $value ) {
			return __( 'Unchanged', 'keel' );
		}
		return isset( $field['choices'][ $value ] ) ? (string) $field['choices'][ $value ] : (string) $value;
	}
	if ( 'range' === $type ) {
		$values = isset( $field['values'] ) ? array_map( 'strval', array_values( $field['values'] ) ) : array();
		$labels = isset( $field['labels'] ) ? array_values( $field['labels'] ) : array();
		$idx    = array_search( (string) $value, $values, true );
		return ( false !== $idx && isset( $labels[ $idx ] ) ) ? (string) $labels[ $idx ] : (string) $value;
	}
	return (string) $value;
}

/**
 * Keel's Site Health posture result: a read-only, grouped summary of every
 * default's current state.
 *
 * Intentionally informational — a toggle turned off is reported, not nagged,
 * because these are the site owner's deliberate choices. The overall status
 * escalates to "recommended" only for a couple of unambiguous security items
 * (strong passwords, REST user-discovery restriction), never for the opinionated
 * UX or content toggles.
 *
 * @return array
 */
function keel_defaults_site_health_posture() {
	$schema = keel_defaults_schema();
	$groups = keel_defaults_groups();

	$by_group = array();
	foreach ( $schema as $key => $field ) {
		$group                = isset( $field['group'] ) ? $field['group'] : 'other';
		$by_group[ $group ][] = array(
			'label' => isset( $field['label'] ) ? $field['label'] : $key,
			'state' => keel_defaults_state_label( $field, keel_defaults_get( $key ) ),
		);
	}

	$strong_ok = 'yes' === keel_defaults_get( 'require_strong_passwords' );
	$rest_ok   = 'yes' === keel_defaults_get( 'restrict_rest_user_discovery' );
	$status    = ( $strong_ok && $rest_ok ) ? 'good' : 'recommended';

	$description = '<p>' . esc_html__( 'Current state of the defaults Keel manages on this site — a read-only summary of your choices under Settings → Keel, not a warning.', 'keel' ) . '</p>';

	foreach ( $groups as $group_key => $group_label ) {
		if ( empty( $by_group[ $group_key ] ) ) {
			continue;
		}
		$description .= '<p><strong>' . esc_html( $group_label ) . '</strong></p><ul>';
		foreach ( $by_group[ $group_key ] as $item ) {
			$description .= '<li>' . esc_html( $item['label'] ) . ' — ' . esc_html( $item['state'] ) . '</li>';
		}
		$description .= '</ul>';
	}

	if ( 'recommended' === $status ) {
		$notes = array();
		if ( ! $strong_ok ) {
			$notes[] = esc_html__( 'Strong passwords are off, so privileged accounts can set weak or breached passwords.', 'keel' );
		}
		if ( ! $rest_ok ) {
			$notes[] = esc_html__( 'REST user discovery is open, so anonymous visitors can enumerate usernames.', 'keel' );
		}
		$description .= '<p><strong>' . esc_html__( 'Worth a look:', 'keel' ) . '</strong> ' . esc_html( implode( ' ', $notes ) ) . '</p>';
	}

	return array(
		'label'       => ( 'good' === $status )
			? __( 'Keel defaults are in place', 'keel' )
			: __( 'Some Keel security defaults are off', 'keel' ),
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Security', 'keel' ),
			'color' => ( 'good' === $status ) ? 'green' : 'orange',
		),
		'description' => $description,
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=keel' ) ),
			esc_html__( 'Review Keel settings', 'keel' )
		),
		'test'        => 'keel_defaults_posture',
	);
}

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
		add_filter(
			'rest_endpoints',
			function ( $endpoints ) {
				if ( ! is_user_logged_in() ) {
					unset( $endpoints['/wp/v2/users'] );
					unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
				}
				return $endpoints;
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_rest' ) ) {
		add_filter( 'rest_authentication_errors', 'keel_defaults_require_rest_auth', PHP_INT_MAX );
	}

	/*
	 * XML-RPC is per-category, not all-or-nothing. Each category is off by
	 * default (locked down) and opt-in to re-enable — the same shape PMP uses.
	 * pingbacks and remote publishing come off via the xmlrpc_methods filter;
	 * system.multicall and a full endpoint block need a server-class swap,
	 * because IXR re-adds multicall after the filter runs.
	 */
	add_filter(
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
	add_filter(
		'xmlrpc_enabled',
		function ( $enabled ) {
			return keel_defaults_enabled( 'xmlrpc_allow_remote_publishing' ) ? $enabled : false;
		}
	);

	// Strip the pingback discovery header when pingbacks are off.
	add_filter(
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
					 * WordPress 4.4 stopped testing credentials after the first failed
					 * authentication in one XML-RPC request. Refusing multicall is now
					 * modest defense-in-depth against general batching, not a fix for
					 * the obsolete "thousands of password guesses" claim.
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
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	}

	if ( keel_defaults_enabled( 'require_strong_passwords' ) ) {
		add_action( 'user_profile_update_errors', 'keel_defaults_validate_profile_password', 10, 3 );
		add_action( 'validate_password_reset', 'keel_defaults_validate_reset_password', 10, 2 );
		add_filter( 'rest_endpoints', 'keel_defaults_guard_rest_password_arg' );
		add_filter( 'rest_pre_insert_user', 'keel_defaults_validate_rest_password', 10, 2 );
	}

	if ( keel_defaults_enabled( 'limit_unfiltered_html_to_admins' ) ) {
		// Very late, so it has the final say over other user_has_cap filters.
		add_filter( 'user_has_cap', 'keel_defaults_limit_unfiltered_html', PHP_INT_MAX - 1, 4 );
	}

	if ( keel_defaults_enabled( 'reserved_usernames' ) ) {
		add_filter( 'illegal_user_logins', 'keel_defaults_reserved_usernames' );
	}

	if ( keel_defaults_enabled( 'remove_version' ) ) {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
	}

	if ( keel_defaults_enabled( 'security_headers' ) ) {
		add_filter( 'wp_headers', 'keel_defaults_set_content_type_header', 99 );
		add_filter( 'wp_headers', 'keel_defaults_set_referrer_policy_header', 99 );
	}

	// Framing is its own setting: it is the only one of the three that can break
	// a working site, so it must be switchable without also giving up nosniff.
	if ( '' !== keel_defaults_get( 'frame_options' ) ) {
		add_filter( 'wp_headers', 'keel_defaults_set_frame_option_header', 99 );
	}

	if ( keel_defaults_enabled( 'disable_ai_connectors' ) ) {
		// WordPress 7.0 gates AI provider connectors behind wp_supports_ai
		// (default true). Returning false stops them registering.
		add_filter( 'wp_supports_ai', '__return_false' );

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
						esc_html__( 'AI connectors are disabled on this site.', 'keel' ),
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

	/* ----- Content & public surfaces ----- */

	if ( keel_defaults_enabled( 'disable_comments' ) ) {
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
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

		// Drop the comment feeds from the head and feed-link markup.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );

		// Remove the "Recent Comments" dashboard widget.
		add_action(
			'wp_dashboard_setup',
			function () {
				remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
			}
		);
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
		add_action(
			'template_redirect',
			function () {
				if ( is_author() ) {
					wp_safe_redirect( home_url( '/' ), 301 );
					exit;
				}
			}
		);
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
			}
		);
	}

	if ( keel_defaults_enabled( 'disable_post_passwords' ) ) {
		add_action( 'admin_print_footer_scripts', 'keel_defaults_hide_post_password_ui' );
	}

	/* ----- Editor ----- */

	if ( keel_defaults_enabled( 'force_classic_editor' ) ) {
		keel_defaults_force_classic_editor();
	}

	/* ----- Admin & front-end UX ----- */

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
		add_filter( 'show_admin_bar', '__return_false' );
	} elseif ( 'hide_non_admins' === $bar ) {
		add_filter(
			'show_admin_bar',
			function ( $show ) {
				return current_user_can( 'manage_options' ) ? $show : false;
			}
		);
	}

	if ( 'default' !== keel_defaults_get( 'admin_menu_width' ) ) {
		add_action( 'admin_head', 'keel_defaults_admin_menu_width_css' );
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
		add_action( 'admin_head', 'keel_defaults_environment_styles' );
		add_action( 'wp_head', 'keel_defaults_environment_styles' );
	}

	/* ----- Media ----- */

	if ( keel_defaults_enabled( 'lowercase_upload_filenames' ) ) {
		add_filter( 'sanitize_file_name', 'keel_defaults_lowercase_filename', 20 );
	}

	if ( keel_defaults_enabled( 'media_sizes_panel' ) ) {
		add_action( 'add_meta_boxes_attachment', 'keel_defaults_media_sizes_meta_box' );
	}

	/* ----- Email ----- */

	if ( keel_defaults_enabled( 'mail_failure_notice' ) ) {
		add_action( 'admin_notices', 'keel_defaults_render_mail_config_notice' );
		add_action( 'admin_notices', 'keel_defaults_render_reset_failure_notice' );
		add_action( 'admin_head-users.php', 'keel_defaults_hide_zero_reset_notice' );
	}

	/* ----- Login & sessions ----- */

	if ( keel_defaults_enabled( 'disable_remember_me' ) ) {
		add_action(
			'login_footer',
			function () {
				echo "<script>(function(){var c=document.getElementById('rememberme');if(c&&c.closest('p')){c.closest('p').style.display='none';}})();</script>";
			}
		);
	}

	// One filter handles both the remembered and regular session lengths. They are
	// independent: disabling Remember Me caps the *remembered* branch but must not
	// swallow the regular-session length, which applies to non-remembered logins.
	add_filter(
		'auth_cookie_expiration',
		function ( $expiration, $user_id, $remember ) {
			if ( $remember ) {
				if ( keel_defaults_enabled( 'disable_remember_me' ) ) {
					return 2 * DAY_IN_SECONDS; // Remember Me disabled → cap the persistent session.
				}
				$days = (int) keel_defaults_get( 'remember_me_days' );
				if ( $days > 0 ) {
					return $days * DAY_IN_SECONDS;
				}
			} else {
				$hours = (int) keel_defaults_get( 'session_regular_hours' );
				if ( $hours > 0 ) {
					return $hours * HOUR_IN_SECONDS;
				}
			}
			return $expiration;
		},
		10,
		3
	);

	/* ----- Branding ----- */

	$login_logo = keel_defaults_get( 'login_logo_behavior' );

	if ( 'remove_logo' === $login_logo ) {
		add_action(
			'login_head',
			function () {
				echo '<style>#login h1 a, .login h1 a { display:none; }</style>';
			}
		);
	} elseif ( 'replace_logo' === $login_logo ) {
		add_action(
			'login_head',
			function () {
				$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 84 ) : '';
				if ( $icon ) {
					echo '<style>#login h1 a, .login h1 a { background-image:url(' . esc_url( $icon ) . '); background-size:contain; }</style>';
				}
			}
		);
	}

	// Removing, unlinking, or replacing the logo all repoint the header link
	// at the site home instead of wordpress.org. There is no separate toggle:
	// a replacement/removed logo linking back to wp.org makes no sense.
	if ( in_array( $login_logo, array( 'remove_logo', 'unlink_logo', 'replace_logo' ), true ) ) {
		add_filter( 'login_headerurl', 'home_url' );
		add_filter(
			'login_headertext',
			function () {
				return get_bloginfo( 'name' );
			}
		);
	}

	/* ----- Performance ----- */

	if ( keel_defaults_enabled( 'throttle_heartbeat' ) ) {
		add_filter(
			'heartbeat_settings',
			function ( $settings ) {
				$settings['interval'] = 60;
				return $settings;
			}
		);
		add_action(
			'init',
			function () {
				if ( is_admin() ) {
					global $pagenow;
					if ( 'index.php' === $pagenow ) {
						wp_deregister_script( 'heartbeat' );
					}
				}
			}
		);
	}
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

/**
 * Whether the strong-password policy is enforced for a given user.
 *
 * Scoped to privileged/editorial accounts by default: a user whose every role is
 * in the weak-role list (default: subscriber) is exempt, since a hard length +
 * breach rule adds signup friction for low-privilege accounts on membership or
 * commerce sites without protecting anything valuable. Any privileged role — or
 * an unknown/empty role set — enforces. Extend the exemption (e.g. a WooCommerce
 * 'customer') or clear it to enforce for everyone via keel_weak_roles.
 *
 * @param WP_User|stdClass|null $user User context, when available.
 * @return bool
 */
function keel_defaults_password_enforced_for_user( $user ) {
	$weak_roles = (array) apply_filters( 'keel_weak_roles', array( 'subscriber' ) );

	$roles = array();
	if ( isset( $user->roles ) && is_array( $user->roles ) ) {
		$roles = $user->roles;
	} elseif ( ! empty( $user->role ) && is_string( $user->role ) ) {
		$roles = array( $user->role );
	}

	// Unknown role set → enforce (the safe default).
	if ( empty( $roles ) ) {
		return true;
	}

	// Exempt only when every role the user holds is a weak role.
	foreach ( $roles as $role ) {
		if ( ! in_array( (string) $role, $weak_roles, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Validate a password against the policy.
 *
 * One reusable validator behind every entry point — profile screen, password
 * reset, and the REST users controller — so a password cannot get in through a
 * door the policy does not watch.
 *
 * @param string                $password Proposed password.
 * @param WP_User|stdClass|null $user     User context, when available.
 * @return true|WP_Error True when acceptable, WP_Error describing the failure.
 */
function keel_defaults_validate_password( $password, $user = null ) {
	// Role-scoped: low-privilege accounts (see keel_weak_roles) are exempt.
	if ( ! keel_defaults_password_enforced_for_user( $user ) ) {
		return true;
	}

	// NIST 800-63B / OWASP: favour length + screening over forced composition
	// rules (upper/lower/number/symbol), which push users toward predictable
	// patterns like Password1! without adding entropy.
	$minimum = (int) apply_filters( 'keel_minimum_password_length', 15 );

	// Count characters, not bytes: strlen() would read eight emoji as 32 and
	// wave through a password far shorter than the rule intends.
	$length = function_exists( 'mb_strlen' ) ? mb_strlen( $password ) : strlen( $password );

	if ( $length < $minimum ) {
		return new WP_Error(
			'keel_password_too_short',
			sprintf(
				/* translators: %d: minimum password length. */
				__( '<strong>Error:</strong> Password must be at least %d characters.', 'keel' ),
				$minimum
			)
		);
	}

	$normalize = static function ( $value ) {
		$value = (string) $value;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	};

	// A small local blocklist still catches the obvious cases when the breach
	// API below is unreachable (that check deliberately fails open).
	$blocklist = (array) apply_filters(
		'keel_password_blocklist',
		array( 'password', 'password123', '123456789012345', 'qwertyuiopasdfg', 'letmeinletmeinletmein', 'wordpresswordpress' )
	);

	if ( in_array( $normalize( $password ), array_map( $normalize, $blocklist ), true ) ) {
		return new WP_Error(
			'keel_password_common',
			__( '<strong>Error:</strong> Choose a password that is not commonly used.', 'keel' )
		);
	}

	// NIST also says to reject passwords derived from personal context.
	if ( $user ) {
		$context = array_filter(
			array(
				isset( $user->user_login ) ? $user->user_login : '',
				isset( $user->user_nicename ) ? $user->user_nicename : '',
				isset( $user->user_email ) ? strtok( $user->user_email, '@' ) : '',
			)
		);

		foreach ( $context as $value ) {
			$value = $normalize( $value );
			if ( strlen( $value ) >= 4 && false !== strpos( $normalize( $password ), $value ) ) {
				return new WP_Error(
					'keel_password_personal',
					__( '<strong>Error:</strong> Password must not contain your username or email name.', 'keel' )
				);
			}
		}
	}

	if ( keel_password_is_pwned( $password ) ) {
		return new WP_Error(
			'keel_password_pwned',
			__( '<strong>Error:</strong> Choose a password that has not appeared in a known data breach.', 'keel' )
		);
	}

	return true;
}

/**
 * Validate a password submitted from a user profile screen.
 *
 * @param WP_Error         $errors Validation errors.
 * @param bool             $update Whether this is an update.
 * @param WP_User|stdClass $user   User context.
 */
function keel_defaults_validate_profile_password( $errors, $update, $user ) {
	unset( $update );

	// Core's edit_user() trims the password and stores the TRIMMED value, but
	// fires this hook with $_POST untouched. Validate the untrimmed string and
	// "              a" sails past the length rule while core saves a
	// one-character password. Measure exactly what core will store.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.
	$password = isset( $_POST['pass1'] ) ? trim( (string) wp_unslash( $_POST['pass1'] ) ) : '';

	if ( '' === $password ) {
		return; // No password change requested (or whitespace-only).
	}

	$result = keel_defaults_validate_password( $password, $user );

	if ( is_wp_error( $result ) ) {
		$errors->add( $result->get_error_code(), $result->get_error_message() );
	}
}

/**
 * Validate a password submitted from the password-reset screen.
 *
 * @param WP_Error $errors Validation errors.
 * @param WP_User  $user   User context.
 */
function keel_defaults_validate_reset_password( $errors, $user ) {
	// wp-login.php already trims $_POST['pass1'] in place before firing this
	// hook; trimming again keeps both entry points measuring the same string.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be sanitized.
	$password = isset( $_POST['pass1'] ) ? trim( (string) wp_unslash( $_POST['pass1'] ) ) : '';

	if ( '' === $password ) {
		return;
	}

	$result = keel_defaults_validate_password( $password, $user );

	if ( is_wp_error( $result ) ) {
		$errors->add( $result->get_error_code(), $result->get_error_message() );
	}
}

/**
 * Validate a password submitted through the core REST users controller.
 *
 * Backstop for any route the argument guard below does not reach.
 *
 * @param object          $prepared_user Prepared user object.
 * @param WP_REST_Request $request       REST request.
 * @return object|WP_Error
 */
function keel_defaults_validate_rest_password( $prepared_user, $request ) {
	$password = $request->get_param( 'password' );

	if ( null === $password || '' === $password ) {
		return $prepared_user;
	}

	$user   = ! empty( $prepared_user->ID ) ? get_userdata( $prepared_user->ID ) : $prepared_user;
	$result = keel_defaults_validate_password( (string) $password, $user );

	return is_wp_error( $result ) ? $result : $prepared_user;
}

/**
 * Require an authenticated user for every REST request.
 *
 * Registered at PHP_INT_MAX on purpose. Core resolves Application Password auth
 * at priority 90 and cookie auth at 100, and rest_cookie_check_errors() returns
 * true after calling wp_set_current_user( 0 ) when a cookie carries no
 * X-WP-Nonce. Deciding before core has finished — or treating any truthy
 * $result as success — would read that true as "authenticated" and let the
 * request dispatch as user 0. Only an existing WP_Error short-circuits.
 *
 * @param WP_Error|true|null $result Authentication result so far.
 * @return WP_Error|true|null
 */
function keel_defaults_require_rest_auth( $result ) {
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'REST API restricted to authenticated users.', 'keel' ),
			array( 'status' => 401 )
		);
	}

	return $result;
}

/**
 * Enforce the password policy on the users controller's `password` argument.
 *
 * The rest_pre_insert_user hook is the documented seam, but the controller never checks
 * its return for an error: update_item() assigns ID onto the WP_Error and hands
 * it to wp_update_user(), which finds no user_pass and answers 200 OK with the
 * user unchanged; create_item() casts it to an array with no user_login and
 * answers 500 "empty login name". Either way the policy message is lost.
 *
 * An argument-level error is different. WP_REST_Request::sanitize_params()
 * turns it into rest_invalid_param, which dispatch() returns as a 400 before
 * the callback runs, so the caller sees the actual reason.
 *
 * @param array $endpoints Registered REST endpoints, keyed by route.
 * @return array
 */
function keel_defaults_guard_rest_password_arg( $endpoints ) {
	foreach ( $endpoints as $route => $handlers ) {
		// Application Passwords are core-generated and carry a readonly password
		// field, so they are never in scope for a human password policy.
		if ( ! preg_match( '#^/wp/v2/users(?:/|$)#', $route ) || false !== strpos( $route, 'application-password' ) ) {
			continue;
		}

		if ( ! is_array( $handlers ) ) {
			continue;
		}

		foreach ( $handlers as $index => $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['args']['password'] ) ) {
				continue;
			}

			$inner = isset( $handler['args']['password']['sanitize_callback'] )
				? $handler['args']['password']['sanitize_callback']
				: null;

			$endpoints[ $route ][ $index ]['args']['password']['sanitize_callback'] = function ( $value, $request, $param ) use ( $inner ) {
				// Let core sanitize first; it rejects empty and backslashed passwords.
				if ( $inner ) {
					$value = call_user_func( $inner, $value, $request, $param );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
				}

				$result = keel_defaults_validate_password(
					(string) $value,
					keel_defaults_rest_password_context( $request )
				);

				if ( is_wp_error( $result ) ) {
					return new WP_Error(
						$result->get_error_code(),
						$result->get_error_message(),
						array( 'status' => 400 )
					);
				}

				return $value;
			};
		}
	}

	return $endpoints;
}

/**
 * Resolve the user a REST password change applies to.
 *
 * Argument sanitizing runs before the controller prepares a user, so the
 * context has to come from the request: update_item() takes the id from the
 * route, and update_current_item() only assigns it after dispatch. A create
 * has no stored user at all, so the submitted fields are the only context.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_User|stdClass
 */
function keel_defaults_rest_password_context( $request ) {
	$user_id = 0;

	if ( preg_match( '#/wp/v2/users/me$#', (string) $request->get_route() ) ) {
		$user_id = get_current_user_id();
	} elseif ( null !== $request['id'] ) {
		$user_id = (int) $request['id'];
	}

	if ( $user_id ) {
		$existing = get_userdata( $user_id );
		if ( $existing ) {
			return $existing;
		}
	}

	$context                = new stdClass();
	$context->user_login    = (string) $request['username'];
	$context->user_email    = (string) $request['email'];
	$context->user_nicename = (string) $request['slug'];

	return $context;
}

/**
 * Check a password against the Have I Been Pwned range API using k-anonymity.
 *
 * Only the first five characters of the SHA-1 hash ever leave the site. HIBP
 * returns every hash suffix sharing that prefix and the comparison happens
 * locally, so the password itself is never transmitted. `Add-Padding` asks HIBP
 * to pad the response so its size cannot reveal how many real matches it held;
 * padded rows carry a count of 0 and are ignored.
 *
 * Fails OPEN: if HIBP is unreachable the password is allowed, rather than
 * locking everyone out of password changes during an outage.
 *
 * @param string $password Plain-text password to screen.
 * @return bool True when the password appears in a known breach.
 */
function keel_password_is_pwned( $password ) {
	$hash   = strtoupper( sha1( $password ) );
	$prefix = substr( $hash, 0, 5 );
	$suffix = substr( $hash, 5 );

	$cache_key = 'keel_hibp_' . $prefix;
	$body      = get_transient( $cache_key );

	if ( false === $body ) {
		$response = wp_remote_get(
			'https://api.pwnedpasswords.com/range/' . $prefix,
			array(
				'timeout'             => 4,
				'limit_response_size' => 65536, // Range responses are ~20KB; cap against a hijacked oversized body.
				'headers'             => array( 'Add-Padding' => 'true' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Fail open — never block a password change because HIBP is down.
			return (bool) apply_filters( 'keel_password_is_pwned', false, $password );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );
	}

	$pwned = false;

	foreach ( preg_split( '/\r\n|\n/', (string) $body ) as $line ) {
		$parts = array_pad( explode( ':', trim( $line ), 2 ), 2, '0' );

		// Padded rows report a count of 0 and are not real matches.
		if ( (int) $parts[1] > 0 && 0 === strcasecmp( $parts[0], $suffix ) ) {
			$pwned = true;
			break;
		}
	}

	/**
	 * Filter the breach-screening verdict (e.g. to use a local blocklist, or to
	 * skip the network call on an air-gapped site).
	 *
	 * @param bool   $pwned    Whether the password appeared in a known breach.
	 * @param string $password The password being screened.
	 */
	return (bool) apply_filters( 'keel_password_is_pwned', $pwned, $password );
}


/*
 * =====================================================================
 * SETTINGS SCREEN — Settings → Keel
 * =====================================================================
 */

add_action(
	'admin_menu',
	function () {
		$hook = add_options_page(
			__( 'Keel', 'keel' ),
			__( 'Keel', 'keel' ),
			'manage_options',
			'keel',
			'keel_defaults_render_settings_page'
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, 'keel_defaults_add_help_tab' );
		}
	}
);

/**
 * Add a short Overview Help tab to the Keel settings screen.
 */
function keel_defaults_add_help_tab() {
	$screen = get_current_screen();
	if ( ! $screen || ! method_exists( $screen, 'add_help_tab' ) ) {
		return;
	}

	$screen->add_help_tab(
		array(
			'id'      => 'keel-overview',
			'title'   => __( 'Overview', 'keel' ),
			'content' =>
				'<p>' . esc_html__( 'Keel applies a menu of sensible security, privacy, UX, and performance defaults to WordPress. Each switch on this page is one default: flip only what you want, and the rest of WordPress is untouched.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'The defaults that are on out of the box are low-risk and safe for nearly any site. Anything that can change behaviour or break an integration — cross-origin framing, requiring authentication for all REST requests, the Classic editor — is off by default and opt-in.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'A setting that cannot take effect given another choice is hidden automatically: the XML-RPC method controls disappear when the whole endpoint is blocked, and Remember Me length hides when Remember Me is disabled.', 'keel' ) . '</p>',
		)
	);

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Current posture', 'keel' ) . '</strong></p>' .
		'<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of the Site Health screen. */
				__( 'See <a href="%s">Site Health</a> for a read-only summary of every default and its state.', 'keel' ),
				esc_url( admin_url( 'site-health.php' ) )
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>'
	);
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'keel_settings_group',
			KEEL_DEFAULTS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'keel_defaults_sanitize',
				'default'           => array(),
			)
		);
	}
);

/**
 * Sanitize the whole settings array against the schema.
 *
 * @param mixed $input Raw submitted settings (untrusted).
 * @return array
 */
function keel_defaults_sanitize( $input ) {
	$schema = keel_defaults_schema();
	$clean  = array();
	$input  = is_array( $input ) ? $input : array();

	foreach ( $schema as $key => $field ) {
		switch ( $field['type'] ) {
			case 'toggle':
				$clean[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : 'no';
				break;

			case 'number':
				$clean[ $key ] = isset( $input[ $key ] ) ? max( 0, absint( $input[ $key ] ) ) : $field['default'];
				break;

			case 'select':
				// array_keys() coerces numeric-string keys to ints (e.g. '300' => 300),
				// so cast back to strings before a strict compare against the posted
				// (string) value — otherwise numeric choices silently fail validation
				// and revert to the default.
				$choices       = isset( $field['choices'] ) ? array_map( 'strval', array_keys( $field['choices'] ) ) : array();
				$value         = isset( $input[ $key ] ) ? (string) $input[ $key ] : (string) $field['default'];
				$clean[ $key ] = in_array( $value, $choices, true ) ? $value : (string) $field['default'];
				break;

			case 'range':
				// The slider posts an index into the ordered values list; map it back
				// to the stored value. Also accept a direct value (e.g. seeded default
				// or a filter) so the store is robust either way.
				$values = isset( $field['values'] ) ? array_values( $field['values'] ) : array();
				$posted = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
				if ( '' !== $posted && ctype_digit( $posted ) && isset( $values[ (int) $posted ] ) ) {
					$clean[ $key ] = (string) $values[ (int) $posted ];
				} elseif ( in_array( $posted, array_map( 'strval', $values ), true ) ) {
					$clean[ $key ] = $posted;
				} else {
					$clean[ $key ] = (string) $field['default'];
				}
				break;
		}
	}

	return $clean;
}

/**
 * Cross-setting dependency state for a field: an attribute string and whether it
 * starts hidden. Applied to the row (single field) or the checkbox wrapper
 * (sectioned field). JS syncs on change; this sets the initial server-side state.
 *
 * @param array $field Schema field.
 * @return array{0:string,1:bool} [ attribute string, hidden-now ]
 */
function keel_defaults_dep_state( $field ) {
	if ( empty( $field['depends']['field'] ) ) {
		return array( '', false );
	}
	$attr   = sprintf(
		' data-keel-dep-field="%s" data-keel-dep-hide="%s"',
		esc_attr( $field['depends']['field'] ),
		esc_attr( (string) $field['depends']['hide_when'] )
	);
	$hidden = ( (string) keel_defaults_get( $field['depends']['field'] ) === (string) $field['depends']['hide_when'] );
	return array( $attr, $hidden );
}

/**
 * Echo a field's description paragraph (allows <code> and links).
 *
 * @param array $field Schema field.
 */
function keel_defaults_render_help( $field ) {
	if ( empty( $field['help'] ) ) {
		return;
	}
	echo '<p class="description">' . wp_kses(
		$field['help'],
		array(
			'code' => array(),
			'a'    => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		)
	) . '</p>';
}

/**
 * Echo a checkbox + its "true when checked" statement (the <label> only).
 *
 * @param string $name  Field input name.
 * @param mixed  $value Current value.
 * @param array  $field Schema field.
 */
function keel_defaults_render_checkbox( $name, $value, $field ) {
	printf(
		'<label><input type="checkbox" name="%s" value="yes" %s /> %s</label>',
		esc_attr( $name ),
		checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
		wp_kses( isset( $field['statement'] ) ? $field['statement'] : $field['label'], array( 'code' => array() ) )
	);
}

/** Render the settings page. */
function keel_defaults_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$schema = keel_defaults_schema();
	$groups = keel_defaults_groups();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Keel', 'keel' ); ?></h1>
		<p><?php esc_html_e( 'Each switch below is one opinionated default. Flip what you want; the rest of WordPress is untouched.', 'keel' ); ?></p>

		<style>
			/* Vertical separation between stacked checkboxes in a grouped row (REST, XML-RPC). */
			.form-table .keel-dep-item {
				margin-bottom: 14px;
			}
			.form-table .keel-dep-item:last-child {
				margin-bottom: 0;
			}
			.form-table .keel-dep-item .description {
				margin-top: 2px;
			}
		</style>

		<?php if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) : ?>
			<div class="notice notice-error inline"><p>
				<?php esc_html_e( 'Update policy overridden: AUTOMATIC_UPDATER_DISABLED disables every WordPress background update.', 'keel' ); ?>
			</p></div>
		<?php elseif ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) : ?>
			<div class="notice notice-error inline"><p>
				<?php esc_html_e( 'Update policy overridden: DISALLOW_FILE_MODS prevents WordPress from installing updates.', 'keel' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Core update policy is locked by WP_AUTO_UPDATE_CORE in wp-config.php. Remove that constant to manage core releases here.', 'keel' ); ?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'keel_settings_group' ); ?>

			<?php foreach ( $groups as $group_key => $group_label ) : ?>
				<h2><?php echo esc_html( $group_label ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<?php
					$section_open = null;
					foreach ( $schema as $key => $field ) :
						if ( $field['group'] !== $group_key ) {
							continue;
						}

						$sec = isset( $field['section'] ) ? $field['section'] : null;

						// Close an open section once the run of same-section fields ends.
						if ( null !== $section_open && $section_open !== $sec ) {
							echo '</fieldset></td></tr>';
							$section_open = null;
						}

						$name  = KEEL_DEFAULTS_OPTION . '[' . $key . ']';
						$value = keel_defaults_get( $key );

						// Sectioned toggles stack as checkboxes under one shared row (core pattern).
						if ( null !== $sec ) {
							if ( null === $section_open ) {
								$sections = keel_defaults_sections();
								$stitle   = isset( $sections[ $sec ] ) ? $sections[ $sec ] : $sec;
								echo '<tr><th scope="row">' . esc_html( $stitle ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html( $stitle ) . '</span></legend>';
								$section_open = $sec;
							}
							list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field );
							echo '<div class="keel-dep-item"' . $dep_attr . ( $dep_hidden ? ' style="display:none;"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dep_attr is built from esc_attr().
							keel_defaults_render_checkbox( $name, $value, $field );
							keel_defaults_render_help( $field );
							echo '</div>';
							continue;
						}

						list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field );
						?>
						<tr<?php echo $dep_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(). ?><?php echo $dep_hidden ? ' style="display:none;"' : ''; ?>>
							<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
							<td>
								<?php if ( 'toggle' === $field['type'] ) : ?>
									<fieldset>
										<legend class="screen-reader-text"><span><?php echo esc_html( $field['label'] ); ?></span></legend>
										<?php keel_defaults_render_checkbox( $name, $value, $field ); ?>
									</fieldset>
								<?php elseif ( 'select' === $field['type'] ) : ?>
									<?php $locked = 'core_update_policy' === $key && defined( 'WP_AUTO_UPDATE_CORE' ); ?>
									<?php if ( $locked ) : ?>
										<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
									<?php endif; ?>
									<select name="<?php echo esc_attr( $name ); ?>" <?php disabled( $locked ); ?>>
										<?php foreach ( $field['choices'] as $ck => $cl ) : ?>
											<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $ck, $value ); ?>>
												<?php echo esc_html( $cl ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'range' === $field['type'] ) : ?>
									<?php
									$rvalues = array_map( 'strval', array_values( $field['values'] ) );
									$rlabels = array_values( $field['labels'] );
									$rcur    = array_search( (string) $value, $rvalues, true );
									$rcur    = ( false === $rcur ) ? 0 : (int) $rcur;
									$rpx     = array_map(
										static function ( $v ) {
											return ( 'default' === $v ) ? 160 : (int) $v;
										},
										$rvalues
									);
									$rid     = $key; // raw schema slug; escaped per output context below.
									?>
									<fieldset>
										<input type="range" min="0" max="<?php echo (int) ( count( $rvalues ) - 1 ); ?>" step="1"
											name="<?php echo esc_attr( $name ); ?>"
											id="<?php echo esc_attr( $rid ); ?>-range"
											value="<?php echo (int) $rcur; ?>"
											list="<?php echo esc_attr( $rid ); ?>-stops"
											aria-describedby="<?php echo esc_attr( $rid ); ?>-output" style="vertical-align:middle;max-width:240px;" />
										<datalist id="<?php echo esc_attr( $rid ); ?>-stops">
											<?php foreach ( $rlabels as $ri => $rl ) : ?>
												<option value="<?php echo (int) $ri; ?>" label="<?php echo esc_attr( $rl ); ?>"></option>
											<?php endforeach; ?>
										</datalist>
										<output for="<?php echo esc_attr( $rid ); ?>-range" id="<?php echo esc_attr( $rid ); ?>-output" style="margin-inline-start:10px;font-weight:600;"><?php echo esc_html( $rlabels[ $rcur ] ); ?></output>
										<style id="<?php echo esc_attr( $rid ); ?>-preview">
											@media screen and (min-width: 783px) {
												body.keel-menu-width-preview #adminmenu,
												body.keel-menu-width-preview #adminmenuback,
												body.keel-menu-width-preview #adminmenuwrap,
												body.keel-menu-width-preview #adminmenu li.menu-top,
												body.keel-menu-width-preview #adminmenu .wp-submenu {
													width: var(--keel-menu-preview-width);
												}
												body.keel-menu-width-preview #adminmenuback {
													position: fixed;
													top: 0;
													bottom: -120px;
													background: var(--keel-menu-preview-bg, #1d2327);
												}
												body.keel-menu-width-preview #adminmenu li.menu-top > a.menu-top,
												body.keel-menu-width-preview #adminmenu .wp-has-current-submenu a.wp-has-current-submenu,
												body.keel-menu-width-preview #adminmenu li.current a.menu-top {
													width: auto;
												}
												body.keel-menu-width-preview #adminmenu li.menu-top:not(.wp-has-current-submenu) .wp-submenu {
													left: var(--keel-menu-preview-width);
												}
												body.keel-menu-width-preview #adminmenu .wp-has-current-submenu .wp-submenu.wp-submenu-wrap {
													left: auto;
												}
												body.keel-menu-width-preview #wpcontent,
												body.keel-menu-width-preview #wpfooter {
													margin-left: var(--keel-menu-preview-width);
												}
												body.rtl.keel-menu-width-preview #adminmenu li.menu-top:not(.wp-has-current-submenu) .wp-submenu {
													right: var(--keel-menu-preview-width);
													left: auto;
												}
												body.rtl.keel-menu-width-preview #adminmenu .wp-has-current-submenu .wp-submenu.wp-submenu-wrap {
													right: auto;
												}
												body.rtl.keel-menu-width-preview #wpcontent,
												body.rtl.keel-menu-width-preview #wpfooter {
													margin-right: var(--keel-menu-preview-width);
													margin-left: 0;
												}
												body.folded.keel-menu-width-preview #wpcontent,
												body.folded.keel-menu-width-preview #wpfooter {
													margin-left: 36px;
												}
											}
										</style>
										<script>
										( function () {
											var input  = document.getElementById( '<?php echo esc_js( $rid ); ?>-range' );
											var output = document.getElementById( '<?php echo esc_js( $rid ); ?>-output' );
											var labels = <?php echo wp_json_encode( $rlabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON escaped for inline <script>. ?>;
											var widths = <?php echo wp_json_encode( array_values( $rpx ), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON escaped for inline <script>. ?>;
											if ( ! input || ! output || ! document.body ) { return; }
											function pos() { return parseInt( input.value, 10 ) || 0; }
											function apply() {
												output.textContent = labels[ pos() ] || labels[0];
												document.body.style.setProperty( '--keel-menu-preview-width', ( widths[ pos() ] || widths[0] ) + 'px' );
												var am = document.getElementById( 'adminmenu' );
												if ( am ) { document.body.style.setProperty( '--keel-menu-preview-bg', window.getComputedStyle( am ).backgroundColor ); }
												document.body.classList.add( 'keel-menu-width-preview' );
											}
											input.addEventListener( 'input', apply );
											input.addEventListener( 'change', apply );
										} )();
										</script>
									</fieldset>
								<?php elseif ( 'number' === $field['type'] ) : ?>
									<input type="number" min="0" step="1"
										name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
										class="small-text" />
								<?php endif; ?>

								<?php keel_defaults_render_help( $field ); ?>
							</td>
						</tr>
						<?php
					endforeach;
					// Close a section still open at the end of the group.
					if ( null !== $section_open ) {
						echo '</fieldset></td></tr>';
					}
					?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php submit_button(); ?>
		</form>

		<script>
		( function () {
			// Show/hide rows whose setting is moot given a controlling setting's value.
			function controllerValue( name ) {
				var els = document.querySelectorAll( '[name="keel_settings[' + name + ']"]' );
				if ( ! els.length ) { return null; }
				var el = els[0];
				if ( 'checkbox' === el.type ) { return el.checked ? el.value : 'no'; }
				if ( 'radio' === el.type ) {
					var picked = document.querySelector( '[name="keel_settings[' + name + ']"]:checked' );
					return picked ? picked.value : '';
				}
				return el.value;
			}
			document.querySelectorAll( 'tr[data-keel-dep-field]' ).forEach( function ( row ) {
				var field = row.getAttribute( 'data-keel-dep-field' );
				var hide  = row.getAttribute( 'data-keel-dep-hide' );
				var ctrls = document.querySelectorAll( '[name="keel_settings[' + field + ']"]' );
				if ( ! ctrls.length ) { return; }
				function sync() { row.style.display = ( controllerValue( field ) === hide ) ? 'none' : ''; }
				ctrls.forEach( function ( c ) { c.addEventListener( 'change', sync ); } );
				sync();
			} );
		} )();
		</script>

		<hr />
		<p><em><?php esc_html_e( 'Three hardening moves live in wp-config.php and cannot be toggled here:', 'keel' ); ?></em></p>
		<pre style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;max-width:640px;">define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOSAVE_INTERVAL', 120 );
define( 'WP_POST_REVISIONS', 10 );</pre>
	</div>
	<?php
}

/**
 * On activation, seed the option with schema defaults so a fresh install
 * behaves as documented out of the box.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( false === get_option( KEEL_DEFAULTS_OPTION, false ) ) {
			$schema   = keel_defaults_schema();
			$defaults = array();
			foreach ( $schema as $key => $field ) {
				$defaults[ $key ] = $field['default'];
			}
			add_option( KEEL_DEFAULTS_OPTION, $defaults );
		}
	}
);
