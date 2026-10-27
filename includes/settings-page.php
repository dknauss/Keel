<?php
/**
 * Settings screen: registration, sanitize, render helpers, and the page itself.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

// Preserve policy helpers for direct consumers of the settings entry point.
require_once __DIR__ . '/settings-policy.php';


/*
 * =====================================================================
 * SETTINGS SCREEN — Settings → Site Defaults
 * =====================================================================
 */

add_action(
	'admin_menu',
	function () {
		$hook = add_options_page(
			__( 'Keel', 'keel-defaults' ),
			__( 'Site Defaults', 'keel-defaults' ),
			'manage_options',
			'keel',
			'keel_defaults_render_settings_page'
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, 'keel_defaults_add_help_tab' );
			keel_defaults_enqueue_on_screen( $hook, 'keel_defaults_enqueue_settings_assets' );
		}
	}
);


/*
 * A Settings link beside Deactivate on the Plugins screen. Every other route to
 * this page — the plugin description, the readme — is text the reader has to act
 * on; this one is where they are already looking.
 */
add_action(
	'admin_init',
	function () {
		// Built here rather than at load: plugin_basename() is a WordPress call,
		// and nothing in this file should need WordPress before admin_init. The
		// links are applied when the Plugins table renders, long after this.
		add_filter( 'plugin_action_links_' . plugin_basename( KEEL_DEFAULTS_FILE ), 'keel_defaults_action_links' );
	}
);

/**
 * Prepend a Settings link to the plugin's action links.
 *
 * First in the row, before Deactivate: WordPress orders these by usefulness, not
 * by destructiveness, and configuring is what someone does far more often than
 * deactivating.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function keel_defaults_action_links( $links ) {
	$settings = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=keel' ) ),
		esc_html__( 'Settings', 'keel-defaults' )
	);

	array_unshift( $links, $settings );

	return $links;
}

/**
 * Add the Help tabs and the Help sidebar to the Keel settings screen.
 */
function keel_defaults_add_help_tab() {
	$screen = get_current_screen();
	if ( ! $screen || ! method_exists( $screen, 'add_help_tab' ) ) {
		return;
	}

	$screen->add_help_tab(
		array(
			'id'      => 'keel-overview',
			'title'   => __( 'Overview', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'Keel applies a set of sensible security, privacy, usability and performance defaults to WordPress. Each control on this page sets one default. Turning one on never turns on another, and a switch left off, or a choice left at Leave unchanged, means Keel does nothing there.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'The defaults that are on out of the box are meant to be safe for nearly any site, but several change what visitors and editors see: comments, author archives and attachment pages are turned off, for example. The ones most likely to break an integration (requiring authentication for all REST requests, blocking the XML-RPC endpoint, the Classic editor) stay off until you turn them on.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'One default that is on can still break something. Keel sends an <code>X-Frame-Options</code> header of <code>SAMEORIGIN</code>, so other sites cannot show yours in an iframe. That includes previews embedded on WordPress.com, intranet dashboards, screenshot and visual-review services, and kiosk or signage screens. If one of those needs to frame this site, set Frame Options to Leave unchanged under Security and Attack Surface. A blocked frame usually shows up as a blank box, with no error.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . esc_html__( 'Five settings disappear while another choice makes them irrelevant: the three XML-RPC method controls while the XML-RPC endpoint is blocked, Remember Me Length while Remember Me is disabled, and Password Policy Exemptions while Password Strength is off.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'A setting with a lock marker is decided somewhere else: by a constant in <code>wp-config.php</code> or, on multisite, by a Super Admin under Network Policy. The Locked settings summary at the top of this page links to each one and says what is holding it.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-passwords',
			'title'   => __( 'Passwords', 'keel-defaults' ),
			'content' =>
				'<p>' . wp_kses(
					__( 'For strong passwords, Keel requires at least 15 characters and screens against known breaches, instead of composition rules that demand a mix of character types. A password also cannot be the username, the name part of the email address, or a common choice. This follows <a href="https://pages.nist.gov/800-63-4/sp800-63b/authenticators/#passwordver" target="_blank" rel="noopener noreferrer">NIST SP 800-63B-4 § 3.1.1.2</a>. Composition rules push people toward predictable shapes, like <code>Password1!</code>, without making them harder to guess.', 'keel-defaults' ),
					array(
						'a'    => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
						'code' => array(),
					)
				) . '</p>' .
				'<p>' . esc_html__( 'WordPress’s strength meter stays in place, and its advice is good, but it runs only in the browser. A password set through the REST API, WP-CLI or a form with scripts off is never measured. Keel’s checks run on the server, so nothing skips them, but they ask how long a password is and whether it has leaked, not how easy it is to guess. The meter is still the better judge of an obvious choice.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'The breach check sends <a href="https://haveibeenpwned.com/API/v3#SearchingPwnedPasswordsByRange" target="_blank" rel="noopener noreferrer">Have I Been Pwned</a> (HIBP) only the first five characters of a SHA-1 hash of the password, made on this site, and checks the list that comes back here. Neither the password nor its full hash leaves the site. To turn the check off, define <code>KEEL_DISABLE_HIBP</code> or use the <code>keel_disable_hibp</code> filter; Site Health then says it is off on purpose.', 'keel-defaults' ),
					array(
						'a'    => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
						'code' => array(),
					)
				) . '</p>' .
				'<p>' . esc_html__( 'An outage, a bad reply or a used-up allowance lets the password through rather than blocking it. That is deliberate: otherwise nobody could change a password while HIBP is down. Each site (each network, on multisite) gets 10 fresh lookups to start and one more each minute by default, and Keel keeps up to 64 answers for 12 hours, so a burst of password changes cannot flood the service. The length, common-password and username rules still apply, and only a reply that arrived whole and parsed cleanly is ever kept.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: %s: URL of the Site Health status screen. */
						__( 'It does not fail quietly, though. When a lookup is skipped or cannot finish, Keel keeps a sample of the reason and shows it under <a href="%s">Site Health</a> for an hour: the service could not be reached, the reply was cut short or incomplete, the service answered with an HTTP error, or this site’s allowance of lookups ran out. Screening is the one part of the password policy that depends on a third party, so it is the one part that can stop working without warning. On multisite, only people who manage the network see the details.', 'keel-defaults' ),
						esc_url( admin_url( 'site-health.php' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: %s: URL of the Site Health status screen. */
						__( 'Checking a password only when it is set misses every password chosen before Keel was active. So a login with a username or email and password also re-checks it, at most once every 30 days per account, for every role unless the <code>keel_password_rescreen_skip_roles</code> filter leaves some out. A breached password still works. Its owner sees a warning on every admin screen until they change it, and anyone who can list users sees a count in <a href="%s">Site Health</a> and a Breached passwords view on the Users screen, with a link to send each person a password reset.', 'keel-defaults' ),
						esc_url( admin_url( 'site-health.php' ) )
					),
					array(
						'a'    => array( 'href' => array() ),
						'code' => array(),
					)
				) . '</p>' .
				( is_multisite()
					? '<p>' . esc_html__( 'On multisite, this setting is stored per site but does not act per site. WordPress keeps one user table for the whole network, so a password is checked against whichever site the person is setting it on, and once set, it is their password everywhere. A Super Admin can settle this for everyone: under Network Admin → Settings → Network Policy, ticking a setting decides it for the whole network and locks it on every site. Left unticked, each site decides for itself, and a password only has to pass the rules of the site where it is set.', 'keel-defaults' ) . '</p>'
					: ''
				),
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-xmlrpc',
			'title'   => __( 'XML-RPC', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'XML-RPC is WordPress’s original remote API. It predates the REST API and still carries the methods that let an outside client publish, fetch and manage a site with a username and password. Most sites no longer use XML-RPC, but some may need it in part or entirely.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'That is why there are four switches rather than one. Blocking the endpoint is the strictest choice. The other three are off by default, which removes the method families that attract abuse (pingbacks, remote publishing with a username and password, and multicall) while the rest of the endpoint keeps working. Turn one on only for a client that needs it.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'Jetpack talks to WordPress.com over XML-RPC, so blocking the endpoint breaks the connection and everything downstream of it. Test the connection and the features you use before deciding Jetpack no longer needs it.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'Blocking the endpoint inside WordPress still costs a full request: PHP starts, WordPress loads, and only then does Keel answer 403. If your host, CDN or firewall can refuse <code>xmlrpc.php</code> before the request reaches WordPress, that is cheaper, especially during a flood of requests, which is when blocking matters most. This setting is for sites that can’t do that.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'The bad reputation of <code>system.multicall</code> is out of date. It once let an attacker bundle hundreds of password guesses into a single request. WordPress 4.4 closed that in 2015. Refusing it today is modest attack-surface reduction against batching, not a fix for a live vulnerability. WordPress.com uses multicall to manage a site through Jetpack, so Keel lets through the requests Jetpack verifies as signed by WordPress.com even while multicall is off.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-environments',
			'title'   => __( 'Environments', 'keel-defaults' ),
			'content' =>
				'<p>' . wp_kses(
					__( 'Keel treats each site as production, staging, development or local. It reads <code>WP_ENVIRONMENT_TYPE</code> (production when unset) and recognizes common local hostnames. The Environment setting can override both. That override changes Keel only, and a copy of the database on another site ignores it.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Email:</strong> with Non-Production Email on, which is the default, outgoing mail is held back outside production and an admin notice says so. WordPress still reports each message as sent, so code behaves as it will in production. <code>KEEL_ALLOW_NONPRODUCTION_MAIL</code> in <code>wp-config.php</code> lets one site send anyway.', 'keel-defaults' ),
					array(
						'strong' => array(),
						'code'   => array(),
					)
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Environment indicator:</strong> a color-coded label in the admin bar, shown as an icon on narrow screens. It is off by default; turn it on wherever a live site and its copies could be mistaken for each other. The Environment setting changes what the label says, not whether it appears.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'Mail held back on a live site? Keel shows a warning when a public site says it is local, naming the <code>wp-config.php</code> file and the command that fixes it. Otherwise, check <code>WP_ENVIRONMENT_TYPE</code> in <code>wp-config.php</code> first.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-updates',
			'title'   => __( 'Updates', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'Keel’s update settings decide what WordPress may install by itself. Core Auto-Updates defaults to maintenance and security releases only. Plugin Auto-Updates and Theme Auto-Updates default to Leave unchanged, which keeps the choices made on the Plugins and Themes screens.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'Choosing Enabled or Disabled overrides those choices without rewriting them, so going back to Leave unchanged restores them. Another plugin that filters updates after Keel can still decide differently. There is no minor-only option for plugins and themes, because their version numbers follow no enforced rules.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: 1: URL of the Updates screen, 2: URL of the Site Health status screen. */
						__( 'A policy is not a delivery schedule. WordPress installs updates when its scheduled checks run and it can write the files. The <a href="%1$s">Updates screen</a> says which core policy is in force and where it comes from, and Update diagnostics in <a href="%2$s">Site Health</a> shows the last check, the next scheduled ones and any errors kept from failed attempts.', 'keel-defaults' ),
						esc_url( admin_url( 'update-core.php' ) ),
						esc_url( admin_url( 'site-health.php' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>' .
				( is_multisite()
					? '<p>' . esc_html__( 'On multisite, every site shares the same plugin and theme files, so one policy applies to the whole network. A Super Admin can set it under Network Policy. If Network Policy leaves plugin or theme updates unmanaged, the main site’s setting decides for every site, so change it on the main site.', 'keel-defaults' ) . '</p>'
					: ''
				),
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-overlaps',
			'title'   => __( 'Overlapping plugins', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'Many of Keel’s defaults are applied through WordPress filters that return a single value. When two plugins are on the same filter, the last one to run wins. There is no error, nothing is logged, and the plugin that lost goes on showing the setting you think it applied. That is how a site can have comments turned off in one plugin and still get comments: another plugin on the same filter turned them back on.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'Keel reports three kinds of overlap.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Shared setting:</strong> another active plugin or theme is on a setting Keel also sets. That shows an overlap, not a disagreement. Two plugins turning the same thing off both get their way, so this is not a reason to deactivate either one. Compare their settings and decide which should own it.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Setting not taking effect:</strong> Keel compared the value it asked for with the value WordPress ended up using, and they differ. When Keel can tell who set the final value, it names that plugin. Act on this one: it is measured, not inferred.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Untraceable:</strong> a plugin that switches a feature with one of WordPress’s own helper functions, such as <code>__return_false</code>, leaves nothing that points back to it. Keel reports these as untraceable rather than guess. Your list of active plugins is the place to look.', 'keel-defaults' ),
					array(
						'strong' => array(),
						'code'   => array(),
					)
				) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: %s: linked name of the Site Health screen. */
						__( '<strong>The full report, hook by hook, is under %s.</strong> The Dashboard, the Plugins screen and this settings screen show a short version. You can dismiss the one on the Dashboard. It comes back if the set of overlapping plugins changes, so dismissing it means “I’ve seen this,” not “never mention it again.”', 'keel-defaults' ),
						'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'keel-defaults' ) . '</a>'
					),
					array(
						'strong' => array(),
						'a'      => array( 'href' => array() ),
					)
				) . '</p>' .
				'<p>' . esc_html__( 'Nothing in Keel runs another plugin’s code to find out what it does. An earlier version did, and it was withdrawn for a simple reason: a check that reports collisions must not be able to cause them.', 'keel-defaults' ) . '</p>',
		)
	);

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Current posture', 'keel-defaults' ) . '</strong></p>' .
		'<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of the Site Health Info screen. */
				__( 'Every setting and its current value is listed under <a href="%s">Site Health → Info</a>, in the <strong>Keel Defaults</strong> section. Site Health → Status flags only the ones that need attention and lists the rest under Passed tests.', 'keel-defaults' ),
				esc_url( admin_url( 'site-health.php?tab=debug' ) )
			),
			array(
				'a'      => array( 'href' => array() ),
				'strong' => array(),
			)
		) . '</p>' .
		'<p>' . esc_html__( 'To share Keel’s setup with a developer or support, use Download JSON on this screen. The report leaves out site and user identifiers.', 'keel-defaults' ) . '</p>'
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
				'sanitize_callback' => 'keel_defaults_sanitize_site',
				'default'           => array(),
			)
		);
	}
);

/**
 * Sanitize a submission from *this site's* settings screen.
 *
 * Wraps the shared sanitizer with one thing the shared one must not do: refuse
 * to write a setting this site does not control. A wp-config constant or a
 * network policy takes a control out of the site owner's hands, and until now
 * that was enforced only by rendering the control disabled — which is a
 * presentational lock. A crafted POST, or a browser with the attribute removed,
 * wrote the value happily. Nothing broke, because the constant and the network
 * policy both win when the value is *read*, but the stored value drifted away
 * from what the screen showed and the lock was a suggestion.
 *
 * It also has to be enforced here rather than inside keel_defaults_sanitize(),
 * because the network screen shares that sanitizer — a network admin setting
 * policy is writing exactly the keys this function is refusing, and folding the
 * check into the shared path would make the network screen unable to save
 * anything it manages.
 *
 * @param mixed $input Raw submitted settings (untrusted).
 * @return array
 */
function keel_defaults_sanitize_site( $input ) {
	$clean    = keel_defaults_sanitize( $input );
	$existing = get_option( KEEL_DEFAULTS_OPTION, array() );
	$existing = is_array( $existing ) ? $existing : array();
	// Preserve the dormant site choice under a network lock during consolidation.
	if ( ! array_key_exists( 'admin_list_tables', $existing ) && array_intersect( array( 'helper_list_columns', 'compact_column_labels', 'fewer_list_columns', 'tidy_date_columns' ), array_keys( $existing ) ) ) {
		$existing['admin_list_tables'] = keel_defaults_list_table_mode_from_values( $existing );
	}

	foreach ( array_keys( keel_defaults_schema() ) as $key ) {
		$locked = ( null !== keel_defaults_config_lock( $key ) ) || ( null !== keel_defaults_network_lock( $key ) );

		/*
		 * A setting the screen never drew is in the same position as a locked
		 * one: the form has no business speaking for it.
		 *
		 * keel_defaults_sanitize() walks the whole schema and reads an absent
		 * checkbox as "off", which is correct for a box somebody unticked and
		 * wrong for one that was never rendered. Gating AI Connectors on core
		 * support therefore made every save on WordPress 6.4–6.9 rewrite the
		 * stored value from `yes` to `no` — silently, and against a release note
		 * promising it was left alone. The site would then have reached 7.0 with
		 * connectors switched on.
		 */
		$unsupported = ! keel_defaults_key_supported( $key );

		if ( ! $locked && ! $unsupported ) {
			continue;
		}

		// Keep what was already stored rather than dropping the key: the site's
		// own preference is what it returns to when the lock is lifted or core
		// catches up, and discarding it here would silently rewrite a choice the
		// site made before somebody else took the setting over.
		if ( array_key_exists( $key, $existing ) ) {
			$clean[ $key ] = $existing[ $key ];
		} else {
			unset( $clean[ $key ] );
		}
	}

	return $clean;
}

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
				$min = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$max = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;
				$raw = isset( $input[ $key ] ) ? $input[ $key ] : $field['default'];
				$raw = is_scalar( $raw ) ? $raw : $field['default'];

				if ( $min < 0 ) {
					$valid = is_scalar( $raw ) && preg_match( '/^-?\d+$/', trim( (string) $raw ) );
					$val   = $valid ? (int) $raw : (int) $field['default'];
				} else {
					$val = abs( (int) $raw );
				}

				$clean[ $key ] = min( $max, max( $min, $val ) );
				break;

			case 'select':
				// choices is a flat list of valid keys; cast to strings for a strict
				// compare against the posted (string) value so numeric-string keys
				// (e.g. '300') do not silently fail validation and revert to default.
				$choices       = isset( $field['choices'] ) ? array_map( 'strval', $field['choices'] ) : array();
				$value         = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : (string) $field['default'];
				$clean[ $key ] = in_array( $value, $choices, true ) ? $value : (string) $field['default'];
				break;

			case 'range':
				// The slider posts an index into the ordered values list; map it back
				// to the stored value. Also accept a direct value (e.g. seeded default
				// or a filter) so the store is robust either way.
				$values = isset( $field['values'] ) ? array_values( $field['values'] ) : array();
				$posted = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
				if ( '' !== $posted && ctype_digit( $posted ) && isset( $values[ (int) $posted ] ) ) {
					$clean[ $key ] = (string) $values[ (int) $posted ];
				} elseif ( in_array( $posted, array_map( 'strval', $values ), true ) ) {
					$clean[ $key ] = $posted;
				} else {
					$clean[ $key ] = (string) $field['default'];
				}
				break;

			case 'multiselect':
				// Store only slugs from the allow-list. This is the guardrail: even a
				// forged POST cannot exempt a privileged role, because roles outside
				// the low-privilege set are never valid choices.
				$allowed       = ( 'password_exempt_roles' === $key ) ? array_keys( keel_defaults_exemptable_roles() ) : array();
				$posted        = ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? array_map( 'strval', array_filter( $input[ $key ], 'is_scalar' ) ) : array();
				$clean[ $key ] = array_values( array_intersect( $posted, array_map( 'strval', $allowed ) ) );
				break;
		}
	}

	// A remembered login must never be shorter than a regular one — otherwise
	// ticking "Remember Me" would *shorten* the session. Clamp it up to match.
	if ( isset( $clean['remember_me_days'], $clean['session_regular_days'] )
		&& $clean['remember_me_days'] < $clean['session_regular_days'] ) {
		$clean['remember_me_days'] = $clean['session_regular_days'];
	}

	return $clean;
}

/**
 * Cross-setting dependency state for a field: an attribute string and whether it
 * starts hidden. Applied to the row (single field) or the checkbox wrapper
 * (sectioned field). JS syncs on change; this sets the initial server-side state.
 *
 * @param array  $field Schema field.
 * @param string $key   Schema key, used for the row's id.
 * @return array{0:string,1:bool} [ attribute string, hidden-now ]
 */
function keel_defaults_dep_state( $field, $key = '' ) {
	if ( empty( $field['depends']['field'] ) ) {
		return array( '', false );
	}

	/*
	 * The id is what makes the relationship programmatic. Without it the only
	 * thing tying a control to the rows it reveals is a data attribute a script
	 * reads — invisible to assistive technology, which is why a row appearing had
	 * no announced connection to the choice that produced it.
	 */
	$attr   = sprintf(
		' id="keel-dep-%1$s" data-keel-dep-field="%2$s" data-keel-dep-hide="%3$s"',
		esc_attr( $key ),
		esc_attr( $field['depends']['field'] ),
		esc_attr( (string) $field['depends']['hide_when'] )
	);
	$hidden = ( (string) keel_defaults_get( $field['depends']['field'] ) === (string) $field['depends']['hide_when'] );
	return array( $attr, $hidden );
}

/**
 * Render a per-setting warning that only applies on some sites.
 *
 * Separate from the help text because it is conditional: help describes what a
 * setting does everywhere, this describes what it would do here.
 *
 * @param string $warning Warning text, or '' for none.
 */
function keel_defaults_render_warning( $warning ) {
	if ( '' === (string) $warning ) {
		return;
	}

	echo '<p class="description keel-warning"><strong>' . esc_html( $warning ) . '</strong></p>';
}

/**
 * The screen a setting lives on, anchored to the setting itself.
 *
 * A notice that names a setting should hand over the place rather than the
 * directions. "Turn off Non-Production Email under Settings → Site Defaults" is a correct
 * sentence and a small chore: find the screen, then find the row among
 * thirty-nine of them.
 *
 * @param string $key Schema key.
 * @return string
 */
function keel_defaults_setting_url( $key ) {
	$url = admin_url( 'options-general.php?page=keel' );

	return ( '' === (string) $key ) ? $url : $url . '#' . keel_defaults_setting_anchor( $key );
}

/**
 * The anchor id a setting is reachable at.
 *
 * Its own element rather than the row's: a dependent row already carries
 * `keel-dep-<key>`, which `aria-controls` points at, and an element gets one id.
 *
 * @param string $key Schema key.
 * @return string
 */
function keel_defaults_setting_anchor( $key ) {
	return 'keel-setting-' . str_replace( '_', '-', (string) $key );
}

/**
 * A link to a setting, for use in notices.
 *
 * One helper so every notice spells the destination the same way. Three notices
 * describing the same journey in three registers is how a screen stops feeling
 * like one plugin.
 *
 * @param string $key  Schema key.
 * @param string $text Link text, already translated.
 * @return string
 */
function keel_defaults_setting_link( $key, $text ) {
	return sprintf(
		'<a href="%s">%s</a>',
		esc_url( keel_defaults_setting_url( $key ) ),
		esc_html( $text )
	);
}

/**
 * Echo a field's description paragraph (allows <code> and links).
 *
 * @param string $help Description text (already translated).
 * @param string $id   Optional id, so a control can point aria-describedby at it.
 */
function keel_defaults_render_help( $help, $id = '' ) {
	if ( '' === (string) $help ) {
		return;
	}
	$id_attr = ( '' !== $id ) ? ' id="' . esc_attr( $id ) . '"' : '';
	echo '<p class="description"' . $id_attr . '>' . wp_kses( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- id_attr is esc_attr()'d.
		$help,
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


/** Render a visible, text-labelled lock beside a setting's heading or statement. */
function keel_defaults_render_lock_marker() {
	echo '<span class="keel-lock-marker"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Locked', 'keel-defaults' ) . '</span>';
}

/**
 * Summarize locks with local links, keeping the deciding authorities separate.
 *
 * Configuration takes precedence, just as it does when the controls render.
 * Network policy is editable on its own screen, so only configuration locks
 * belong in that screen's summary. Unsupported settings have no link target.
 *
 * @param bool $network_screen Whether this is Network Policy.
 * @return void
 */
function keel_defaults_render_lock_summary( $network_screen = false ) {
	$groups  = array(
		'config'  => array(),
		'network' => array(),
	);
	$strings = keel_defaults_strings();
	foreach ( keel_defaults_schema() as $key => $field ) {
		if ( ! keel_defaults_key_supported( $key ) ) {
			continue;
		}
		if ( null !== keel_defaults_config_lock( $key ) ) {
			$groups['config'][] = $key;
		} elseif ( ! $network_screen && null !== keel_defaults_network_lock( $key ) ) {
			$groups['network'][] = $key;
		}
	}
	if ( ! $groups['config'] && ! $groups['network'] ) {
		return;
	}
	echo '<div class="keel-lock-summary" role="region" aria-label="' . esc_attr( __( 'Locked settings', 'keel-defaults' ) ) . '">';
	foreach ( $groups as $source => $keys ) {
		if ( ! $keys ) {
			continue;
		}
		$title = 'config' === $source ? sprintf(
			/* translators: 1: wp-config.php. 2: number of settings locked by configuration. */
			esc_html__( 'Locked by %1$s (%2$d)', 'keel-defaults' ),
			'<code>wp-config.php</code>',
			count( $keys )
		) : sprintf(
			/* translators: %d: number of settings locked by network policy. */
			esc_html__( 'Locked by network policy (%d)', 'keel-defaults' ),
			count( $keys )
		);
		echo '<p><strong>' . $title . '</strong></p><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at construction.
		foreach ( $keys as $key ) {
			printf( '<li><a href="#%s">%s</a></li>', esc_attr( keel_defaults_setting_anchor( $key ) ), esc_html( $strings[ $key ]['label'] ) );
		}
		echo '</ul>';
	}
	echo '</div>';
}

/** Render the settings page. */
function keel_defaults_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$schema  = keel_defaults_schema();
	$strings = keel_defaults_strings();
	$groups  = keel_defaults_group_labels();
	?>
	<div class="wrap">
		<div class="keel-page-header" style="display:flex;align-items:center;gap:14px;margin:8px 0 6px;">
			<?php
			/*
			 * The brand mark, identical to the one in .wordpress-org/. Fixed fills
			 * rather than currentColor: the keel is the namesake and the brand rules
			 * say it is never recoloured to anything but the steel or the ground.
			 * The mark sits on `.wrap`, which stays white under every admin colour
			 * scheme, so the light colourway is always the right one here.
			 */
			?>
			<span aria-hidden="true" style="flex:0 0 auto;line-height:0;">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="48" height="48" fill="none" focusable="false" aria-hidden="true">
					<path d="M96 22 L101 22 L101 122 L96 122 Z" fill="#1d1f20"/>
					<path d="M108 30 C 103 60 104 92 107 118 L153 118 C 143 88 128 56 108 30 Z" fill="#75797c"/>
					<path d="M92 46 C 78 70 64 96 51 118 L78 118 C 85 94 90 70 92 46 Z" fill="#75797c"/>
					<path d="M32 121 L168 121 L152 137 C 112 145 72 145 42 135 Z" fill="#1d1f20"/>
					<path d="M95 139 L112 139 L106 192 L100 192 Z" fill="#5980a6"/>
				</svg>
			</span>
			<div>
				<h1 style="margin:0;padding:0;line-height:1.2;"><?php esc_html_e( 'Keel', 'keel-defaults' ); ?></h1>
				<p class="description" style="font-size:14px;margin:2px 0 0;"><?php esc_html_e( 'Balanced defaults for steady sites.', 'keel-defaults' ); ?></p>
			</div>
		</div>
		<?php
		/*
		 * Where admin notices go. WordPress relocates every `.notice` to just
		 * before this marker, and without it falls back to "immediately after the
		 * first <h1>" — which here is inside the flex header, so "Settings saved."
		 * landed beside the logo in a column the width of the heading text.
		 */
		?>
		<hr class="wp-header-end">
		<p><?php esc_html_e( 'Keel sets a sound baseline for your site — balanced defaults for security, updates, privacy, performance, and the admin experience. Every option below is one deliberate default you can see and switch off. Nothing runs that isn\'t listed here, and anything you leave unchecked keeps WordPress exactly as it ships.', 'keel-defaults' ); ?></p>

		<?php keel_defaults_render_lock_summary(); ?>
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

						// A setting whose core feature this WordPress does not
						// have is not drawn. Showing a switch that cannot move
						// anything is worse than not offering it: the screen's
						// whole promise is that every control does what it says.
						if ( ! keel_defaults_key_supported( $key ) ) {
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

						// Display copy lives in strings.php (translatable); the schema
						// above carries only structure.
						$s         = isset( $strings[ $key ] ) ? $strings[ $key ] : array();
						$label     = isset( $s['label'] ) ? $s['label'] : $key;
						$statement = isset( $s['statement'] ) ? $s['statement'] : $label;
						$help      = isset( $s['help'] ) ? $s['help'] : '';

						// A wp-config.php constant may supersede this setting; if so the
						// control is disabled and the reason shown next to it.

						/*
						 * Two things can take a setting out of a site owner's hands: a
						 * wp-config constant, and a Super Admin deciding it for the
						 * network. They render identically — disabled control, reason
						 * beside it — because a site administrator should not have to
						 * learn two explanations for the same experience.
						 *
						 * The constant wins when both apply. It is the operator's
						 * highest-level declaration and it is true of this site
						 * specifically, so saying "your network admin set this" when
						 * wp-config is what actually decided would send somebody to
						 * argue with the wrong person.
						 */
						$lock   = keel_defaults_config_lock( $key );
						$lock   = ( null === $lock ) ? keel_defaults_network_lock( $key ) : $lock;
						$locked = null !== $lock;

						// The control shows what is enforced; the hidden inputs below still
						// carry what is stored, so a save keeps the site's own choice for
						// when the lock is lifted.
						$shown = $locked ? keel_defaults_config_locked_value( $key, $value ) : $value;

						// Accessible-name / description wiring for screen readers: the
						// help, lock note, and unit each get a stable id, and controls
						// point aria-describedby at whichever exist (lock first, so the
						// reason a control is disabled is announced up front).
						$help_id     = ( '' !== $help ) ? 'keel-' . $key . '-desc' : '';
						$lock_id     = $locked ? 'keel-' . $key . '-lock' : '';
						$unit_id     = ! empty( $s['unit'] ) ? 'keel-' . $key . '-unit' : '';
						$describedby = trim( implode( ' ', array_filter( array( $lock_id, $unit_id, $help_id ) ) ) );

						// Sectioned toggles stack as checkboxes under one shared row (core pattern).
						if ( null !== $sec ) {
							if ( null === $section_open ) {
								$sections = keel_defaults_section_labels();
								$stitle   = isset( $sections[ $sec ] ) ? $sections[ $sec ] : $sec;
								echo '<tr><th scope="row">' . esc_html( $stitle ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html( $stitle ) . '</span></legend>';
								$section_open = $sec;
							}
							list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field, $key );
							echo '<div class="keel-dep-item"' . $dep_attr . ( $dep_hidden ? ' style="display:none;"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dep_attr is built from esc_attr().
							printf( '<span class="keel-anchor" id="%s"></span>', esc_attr( keel_defaults_setting_anchor( $key ) ) );
							// Same lock handling as the single-field branch below. No
							// sectioned setting is lockable today, but the invariant this
							// screen documents — never offer a switch that cannot take
							// effect — has to hold wherever a control is drawn, not only
							// in the branch that happens to draw the locked ones now.
							if ( $locked && 'yes' === $value ) {
								printf( '<input type="hidden" name="%s" value="yes" />', esc_attr( $name ) );
							}
							keel_defaults_render_checkbox( $name, $shown, $statement, $locked, $describedby );
							if ( $locked ) {
								keel_defaults_render_lock_marker();
							}
							if ( $locked ) {
								printf(
									'<p class="description keel-config-lock" id="%s">%s</p>',
									esc_attr( $lock_id ),
									wp_kses( $lock, array( 'code' => array() ) )
								);
							}
							keel_defaults_render_warning( keel_defaults_jetpack_warning( $key ) );
							keel_defaults_render_help( $help, $help_id );
							echo '</div>';
							continue;
						}

						list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field, $key );
						?>
						<tr<?php echo $dep_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(). ?><?php echo $dep_hidden ? ' style="display:none;"' : ''; ?>>
							<th scope="row"><?php echo esc_html( $label ); ?>
							<?php
							if ( $locked ) {
								keel_defaults_render_lock_marker(); }
							?>
							</th>
							<td>
								<?php // The anchor lives in the control cell, not the heading: tests/settings-heading-case.php reads that heading, and an id is not part of a label. ?>
								<span class="keel-anchor" id="<?php echo esc_attr( keel_defaults_setting_anchor( $key ) ); ?>"></span>
								<?php keel_defaults_render_control( $key, $name, $value, $field, $s, $statement, $describedby, $locked ); ?>

								<?php if ( $locked ) : ?>
									<p class="description keel-config-lock" id="<?php echo esc_attr( $lock_id ); ?>"><?php echo wp_kses( $lock, array( 'code' => array() ) ); ?></p>
								<?php endif; ?>

								<?php keel_defaults_render_warning( keel_defaults_jetpack_warning( $key ) ); ?>
								<?php keel_defaults_render_help( $help, $help_id ); ?>
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

		<?php keel_defaults_render_posture_export(); ?>

	</div>
	<?php
}
