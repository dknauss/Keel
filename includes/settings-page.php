<?php
/**
 * Settings screen: registration, sanitize, render helpers, and the page itself.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;


/*
 * =====================================================================
 * SETTINGS SCREEN — Settings → Keel
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
			'title'   => __( 'Overview', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'Keel applies a menu of sensible security, privacy, UX, and performance defaults to WordPress. Each switch on this page is one default, and they are independent: turning one on does not turn on anything else, and a switch left off means Keel does not apply that default at all.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'The defaults that are on out of the box are low-risk and safe for nearly any site. Anything that can change behavior or break an integration — requiring authentication for all REST requests, blocking the XML-RPC endpoint, the Classic editor — is off by default and opt-in only.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'There is one exception: Keel sends an <code>X-Frame-Options</code> header of <code>SAMEORIGIN</code>, so other sites cannot embed yours in an iframe. If something else is meant to display this site inside a frame — an intranet dashboard, a screenshot or visual-review service, a kiosk or signage screen — set Frame options to “Leave unchanged” under Security and Attack Surface. A blocked frame usually fails silently, as a blank box.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . esc_html__( 'Five settings disappear, become inactive, and cannot be toggled when another choice makes them irrelevant and takes them off the table: the three XML-RPC method controls when the endpoint itself is blocked, Remember Me Length when Remember Me is off, and Password Policy Exemptions when Password Strength is off.', 'keel-defaults' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-passwords',
			'title'   => __( 'Passwords', 'keel-defaults' ),
			'content' =>
				'<p>' . wp_kses(
					__( 'For strong passwords, Keel requires length and breach screening in place of composition rules that require mixtures of different character types. This follows <a href="https://pages.nist.gov/800-63-4/sp800-63b/authenticators/#passwordver" target="_blank" rel="noopener noreferrer">NIST SP 800-63B-4 § 3.1.1.2</a>. Composition rules push people toward predictable shapes — like <code>Password1!</code> — without making them harder to guess.', 'keel-defaults' ),
					array(
						'a'    => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
						'code' => array(),
					)
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'WordPress already shows a strength meter as you type, and Keel leaves it in place — it is good advice. It cannot be the rule, though. The meter, the weak-password warning and the checkbox asking you to confirm a weak password are all JavaScript, and nothing on the server reads any of them, so a password set through the REST API, WP-CLI, or a form with scripts turned off is never measured at all.', 'keel-defaults' ),
					array()
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'Keel enforces password strength on the server, where nothing can skip it — but it asks how long a password is and whether it has ever appeared in a breach, not how easy it is to guess. Something long that has never leaked can still be an obvious choice, and spotting that is what the strength meter is good at.', 'keel-defaults' ),
					array()
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'The breach check sends <a href="https://haveibeenpwned.com/API/v3#SearchingPwnedPasswordsByRange" target="_blank" rel="noopener noreferrer">Have I Been Pwned</a> only the first five characters of a SHA-1 hash computed on this site, and matches the returned suffixes locally — so neither the password nor its full hash leaves the site. It can be switched off with the <code>KEEL_DISABLE_HIBP</code> constant or the <code>keel_disable_hibp</code> filter.', 'keel-defaults' ),
					array(
						'a'    => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
						'code' => array(),
					)
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'An outage or a malformed response lets the password through rather than blocking it. That is deliberate — the alternative is that nobody can change a password if the HIBP API is down. The length, blocklist and personal-context rules still apply, and only a response that arrived whole and parsed cleanly is ever cached, so one bad reply cannot become hours of false &#8220;not breached&#8221; answers.', 'keel-defaults' ),
					array()
				) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: %s: URL of the Site Health status screen. */
						__( 'It does not fail quietly, though. A lookup that cannot be completed is recorded and reported under <a href="%s">Site Health</a>, which says what went wrong — unreachable, rate-limited, cut short, or answered by something that was not the service. Screening is the one part of the password policy that depends on a third party, so it is the one part that can stop working without warning. The record clears itself once a lookup succeeds.', 'keel-defaults' ),
						esc_url( admin_url( 'site-health.php' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>' .
				( is_multisite()
					? '<p>' . wp_kses(
						__( 'On multisite, this setting is stored per site but does not act per site. WordPress keeps one user table for the whole network, so a password is checked against whichever site the person is setting it on — and once set, it is their password everywhere. A Super Admin can settle this for everyone: under Network Admin → Settings → Keel Defaults, ticking a setting decides it for the whole network and locks it on every site. Left unticked, each site decides for itself and the strictest site sets the floor for anyone who changes their password there.', 'keel-defaults' ),
						array()
					) . '</p>'
					: ''
				),
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-xmlrpc',
			'title'   => __( 'XML-RPC', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'XML-RPC is WordPress\'s original remote API. It predates the REST API and still carries the methods that let an outside client publish, fetch and manage a site with a username and password. Most sites no longer use XML-RPC, but some may need it in part or entirely.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'That is why these are four switches rather than one. Turning the endpoint off completely is the strictest posture. The three narrower controls remove the specific method families that attract abuse — pingbacks, credential-authenticated publishing, and multicall — while still leaving the endpoint available.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'Jetpack talks to WordPress.com over XML-RPC, so blocking the endpoint breaks the connection and everything downstream of it. Test the connection and the features you use before deciding Jetpack no longer needs it.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'Blocking the endpoint inside WordPress still costs a request. PHP starts, WordPress loads, and only then does the plugin answer 403. If your host, CDN or firewall can refuse <code>xmlrpc.php</code> before the request even reaches WordPress, that is cheaper under exactly the load that makes blocking attractive — a flood of requests. This setting is the answer for sites without that option.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'The negative reputation of <code>system.multicall</code> is out of date. It once let an attacker bundle hundreds of password guesses into a single request. WordPress 4.4 closed that in 2015. Refusing it today is modest attack-surface reduction against batching, not a fix for a live vulnerability.', 'keel-defaults' ),
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
					__( 'Two defaults behave differently depending on whether Keel is running in a production or non-production environment: outgoing email and the environment indicator. Both defaults read <code>wp_get_environment_type()</code>, which returns whatever the <code>WP_ENVIRONMENT_TYPE</code> constant or environment variable is set to — the constant is the input, the function is the output — and <code>production</code> when nothing sets it. Keel also recognises common local hostnames without configuration.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Outgoing email stops at the edge of production.</strong> A database copied down from a live site carries real customer addresses and whatever mail service production was using, so a cron run or a bulk action can email real people from a staging site or a laptop. On any environment that is not production, Keel suppresses outgoing mail — and says so in an admin notice, because the alternative is somebody wondering for an afternoon why a password reset never arrived.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'WordPress still reports each suppressed message as sent, deliberately. Code that branches on the result of <code>wp_mail()</code> then behaves the way it will in production, instead of taking an error path that only ever runs on staging.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>The environment indicator is the colour-coded label in the admin bar naming the current environment.</strong> It is off by default and worth turning on anywhere somebody might have production and staging open in adjacent browser tabs. Below 960px the label collapses to its icon to save room, but it stays readable to screen readers. <strong>It is the opt-in half of this pair on purpose:</strong> suppressing mail is silent on production and only acts where it is needed, while the indicator would be visible on every screen of every live site all the time. It\'s useful as an opt-in feature for environments that have live production, local test, and development staging counterparts that can be confused — with very bad consequences.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'If mail is being suppressed on a site you consider production, the environment type is what to check first — not this plugin. Setting <code>WP_ENVIRONMENT_TYPE</code> explicitly in <code>wp-config.php</code> is worth doing on every install regardless, because core, plugins and themes all read it.', 'keel-defaults' ),
					array( 'code' => array() )
				) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-overlaps',
			'title'   => __( 'Overlapping plugins', 'keel-defaults' ),
			'content' =>
				'<p>' . esc_html__( 'Many of Keel\'s defaults are applied through WordPress filters that return a single value. When two plugins are registered on the same filter, only one plugin\'s activity prevails. There is no error, nothing is logged, and the plugin that was quietly overruled goes on showing the setting you think it applied. That is why WordPress can have two plugins configured to disable comments and still have comments.', 'keel-defaults' ) . '</p>' .
				'<p>' . esc_html__( 'Keel reports collisions with other plugins in two ways, and they answer different questions.', 'keel-defaults' ) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>The first method names plugins:</strong> another active plugin is registered on a setting Keel also sets. That confirms an overlap, not a disagreement — two plugins turning the same thing off both get their way, and the report is not a reason to deactivate either one. Compare their settings and decide which plugin should own the disabling function.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>The second method says a setting is not taking effect:</strong> Keel asked for a value, watched what the filter chain actually settled on, and they disagree. That one is worth acting on, because it is measured rather than inferred.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( '<strong>Sometimes there is no plugin that can be named.</strong> A plugin that turns a feature off using one of WordPress\'s own helper functions leaves nothing behind to identify it — the callback belongs to WordPress, not to whoever registered it. Those overlaps are reported as untraceable rather than guessed at, and your list of active plugins is the place to look to get to the bottom of the mystery.', 'keel-defaults' ),
					array( 'strong' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					sprintf(
						/* translators: %s: linked name of the Site Health screen. */
						__( '<strong>The full collision report, hook by hook, is under %s.</strong> The dashboard and plugins screens carry a short version, and the dashboard notification can be dismissed — it comes back if the set of overlapping plugins changes, so dismissing it means “I have seen this” rather than “never mention this again.”', 'keel-defaults' ),
						'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'keel-defaults' ) . '</a>'
					),
					array(
						'strong' => array(),
						'a'      => array( 'href' => array() ),
					)
				) . '</p>' .
				'<p>' . esc_html__( 'Nothing in Keel runs another plugin\'s code to find out what it does. An earlier version did, and it was withdrawn for a simple reason: a check that reports collisions must not be able to cause them.', 'keel-defaults' ) . '</p>',
		)
	);

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Current posture', 'keel-defaults' ) . '</strong></p>' .
		'<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of the Site Health Info screen. */
				__( 'Every default and its current state is listed under <a href="%s">Site Health → Info</a>, in the <strong>Keel Defaults</strong> section. Site Health → Status flags only the defaults that warrant attention, and files the rest under “Passed tests”.', 'keel-defaults' ),
				esc_url( admin_url( 'site-health.php?tab=debug' ) )
			),
			array(
				'a'      => array( 'href' => array() ),
				'strong' => array(),
			)
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

			case 'multiselect':
				// Store only slugs from the allow-list. This is the guardrail: even a
				// forged POST cannot exempt a privileged role, because roles outside
				// the low-privilege set are never valid choices.
				$allowed       = ( 'password_exempt_roles' === $key ) ? array_keys( keel_defaults_exemptable_roles() ) : array();
				$posted        = ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? array_map( 'strval', $input[ $key ] ) : array();
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
 * directions. "Turn off Non-Production Email under Settings → Keel" is a correct
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

/**
 * Echo a checkbox + its "true when checked" statement (the <label> only).
 *
 * @param string $name        Field input name.
 * @param mixed  $value       Current value.
 * @param string $statement   The "true when checked" statement (already translated).
 * @param bool   $disabled    Whether to render the checkbox disabled.
 * @param string $describedby Space-separated ids for aria-describedby (help/lock).
 */
function keel_defaults_render_checkbox( $name, $value, $statement, $disabled = false, $describedby = '' ) {
	$aria = ( '' !== $describedby ) ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '';

	/*
	 * aria-disabled, not disabled.
	 *
	 * A `disabled` control is removed from the tab sequence, so a screen-reader
	 * user never lands on it — and the aria-describedby wiring that announces
	 * *why* it cannot be changed is announced on focus, which never happens. The
	 * reason was written first in that attribute on purpose and then could not be
	 * heard. `aria-disabled` keeps the control focusable and announced as
	 * unavailable, so the explanation reaches the person it was written for.
	 *
	 * Safe only because the lock is now enforced on save in
	 * keel_defaults_sanitize_site(): a focusable control is a submittable one, and
	 * before that enforcement this would have let a locked value be written.
	 */
	$locked_attrs = $disabled ? ' aria-disabled="true" data-keel-locked="1"' : '';

	printf(
		'<label><input type="checkbox" name="%s" value="yes" %s%s%s /> %s</label>',
		esc_attr( $name ),
		checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
		$locked_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
		$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr().
		wp_kses( $statement, array( 'code' => array() ) )
	);
}

/**
 * If a wp-config.php constant supersedes a setting, return a short inline note
 * explaining it (the note may contain <code>). The control is then disabled and
 * the note shown, so the screen never offers a switch that cannot take effect.
 * Returns null when the setting is fully under the dashboard's control.
 *
 * @param string $key Schema key.
 * @return string|null
 */
function keel_defaults_config_lock( $key ) {
	// Any of these means WordPress performs no background updates at all, which
	// supersedes both the core-update policy and the translation-update toggle.
	$updates_off = null;
	if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
		$updates_off = 'AUTOMATIC_UPDATER_DISABLED';
	} elseif ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		$updates_off = 'DISALLOW_FILE_MODS';
	}

	switch ( $key ) {
		case 'core_update_policy':
			if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
				return __( 'Locked by <code>WP_AUTO_UPDATE_CORE</code> in <code>wp-config.php</code>. Remove that constant to manage core releases here.', 'keel-defaults' );
			}
			if ( $updates_off ) {

				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel-defaults' ), $updates_off );
			}
			break;

		case 'auto_update_translations':
			if ( $updates_off ) {

				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel-defaults' ), $updates_off );
			}
			break;

		case 'limit_unfiltered_html_to_admins':
			if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
				return __( '<code>DISALLOW_UNFILTERED_HTML</code> in <code>wp-config.php</code> already removes unfiltered HTML from every role, so this restriction has no additional effect.', 'keel-defaults' );
			}
			break;

		case 'post_revisions_limit':
			/*
			 * Core supplies true itself when wp-config.php says nothing, so
			 * defined() alone would lock this control on every site. A false or
			 * numeric value is distinguishable from that default and therefore
			 * proves that the operator chose a policy outside Keel.
			 */
			if ( defined( 'WP_POST_REVISIONS' ) && true !== WP_POST_REVISIONS ) {
				return __( 'Locked by <code>WP_POST_REVISIONS</code> in <code>wp-config.php</code>. Remove that constant to manage revision retention here.', 'keel-defaults' );
			}
			break;
	}

	return null;
}

/**
 * Render a range (slider) field, with its live admin-menu preview.
 *
 * Extracted from the settings template, where it was 105 of that function's 338
 * lines and the sole reason the file reached thirteen levels of indentation. The
 * other field types run ten to twenty lines each; this one carried a whole
 * feature — the slider, the preview stylesheet it toggles, and the script that
 * drives them — and inlining all of it put CSS at a depth where the surrounding
 * PHP was no longer legible.
 *
 * The stylesheet and the script have since moved out of PHP altogether, into
 * assets/css/settings.css and assets/js/settings.js. What is left here is the
 * markup, and the three data- attributes that carry this field's labels and
 * pixel widths to the script — which is why the script is now static and works
 * for any number of range fields instead of being re-emitted once per field.
 *
 * @param string $key         Schema key, used to build element ids.
 * @param string $name        Input name attribute.
 * @param mixed  $value       Stored value.
 * @param array  $field       Schema field.
 * @param array  $s           Display strings for this field.
 * @param string $label       Field label.
 * @param string $describedby Space-separated ids for aria-describedby.
 * @param bool   $locked      Whether wp-config.php or network policy has settled this.
 * @return void
 */
function keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby, $locked = false ) {
	$rvalues = array_map( 'strval', array_values( $field['values'] ) );
	$rlabels = array_values( isset( $s['labels'] ) ? $s['labels'] : array() );
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
		<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
		<?php
		/*
		 * aria-valuetext, because the value is a position and the meaning is a
		 * word. Without it a screen reader announces this slider as "2" — the
		 * index — and the site owner is choosing between Default, Narrow and
		 * Wide, none of which are numbers. The visible <output> carries the word
		 * for sighted users; this is the same information for everyone else, and
		 * it is announced as the *value* rather than as a description.
		 */
		?>
		<input type="range" min="0" max="<?php echo (int) ( count( $rvalues ) - 1 ); ?>" step="1"
			name="<?php echo esc_attr( $name ); ?>"
			id="<?php echo esc_attr( $rid ); ?>-range"
			value="<?php echo (int) $rcur; ?>"
			list="<?php echo esc_attr( $rid ); ?>-stops"
			aria-label="<?php echo esc_attr( $label ); ?>"
			aria-valuetext="<?php echo esc_attr( $rlabels[ $rcur ] ); ?>"
			<?php
			/*
			 * A locked slider has to say so to the script as well as to a reader.
			 * Selects, numbers and toggles already emitted this; the slider and
			 * the role checkboxes did not, so a network-locked control announced
			 * itself as locked and then previewed and accepted edits anyway. The
			 * server refuses the value either way — this is the screen agreeing
			 * with what the server will do.
			 */
			echo $locked ? ' aria-disabled="true" data-keel-locked="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			?>
			data-keel-range="<?php echo esc_attr( $rid ); ?>-output"
			data-keel-range-labels="<?php echo esc_attr( wp_json_encode( $rlabels ) ); ?>"
			data-keel-range-widths="<?php echo esc_attr( wp_json_encode( array_values( $rpx ) ) ); ?>"
			<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(). ?> style="vertical-align:middle;max-width:240px;" />
		<datalist id="<?php echo esc_attr( $rid ); ?>-stops">
			<?php foreach ( $rlabels as $ri => $rl ) : ?>
				<option value="<?php echo (int) $ri; ?>" label="<?php echo esc_attr( $rl ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<?php
		/*
		 * aria-live="off", deliberately, on an element whose implicit live
		 * politeness is "polite". The word is already announced as the slider's
		 * own value through aria-valuetext, so leaving this a live region made
		 * every arrow-key press say it twice — which is the "stream of noise"
		 * this control was suspected of and the reason to check. It stays a
		 * visible <output> for sighted users and is silent to a screen reader.
		 */
		?>
		<output for="<?php echo esc_attr( $rid ); ?>-range" id="<?php echo esc_attr( $rid ); ?>-output" aria-live="off" style="margin-inline-start:10px;font-weight:600;"><?php echo esc_html( $rlabels[ $rcur ] ); ?></output>
	</fieldset>
	<?php
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
				<p class="description" style="font-size:14px;margin:2px 0 0;"><?php esc_html_e( 'Sensible defaults for steady sites.', 'keel-defaults' ); ?></p>
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
		<p><?php esc_html_e( 'Keel sets a sound baseline for your site — sensible defaults for security, updates, privacy, the admin experience, and performance. Every option below is one deliberate default you can see and switch off. Nothing runs that isn\'t listed here, and anything you leave unchecked keeps WordPress exactly as it ships.', 'keel-defaults' ); ?></p>

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
							keel_defaults_render_checkbox( $name, $value, $statement, $locked, $describedby );
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
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<?php // The anchor lives in the control cell, not the heading: tests/settings-heading-case.php reads that heading, and an id is not part of a label. ?>
								<span class="keel-anchor" id="<?php echo esc_attr( keel_defaults_setting_anchor( $key ) ); ?>"></span>
								<?php if ( 'toggle' === $field['type'] ) : ?>
									<fieldset>
										<legend class="screen-reader-text"><span><?php echo esc_html( $label ); ?></span></legend>
										<?php // A disabled checkbox is not submitted; carry a 'yes' value so a save under the constant does not silently flip the stored preference to 'no'. ?>
										<?php if ( $locked && 'yes' === $value ) : ?>
											<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="yes" />
										<?php endif; ?>
										<?php keel_defaults_render_checkbox( $name, $value, $statement, $locked, $describedby ); ?>
									</fieldset>
								<?php elseif ( 'select' === $field['type'] ) : ?>
									<?php if ( $locked ) : ?>
										<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
									<?php endif; ?>
									<select name="<?php echo esc_attr( $name ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; ?><?php echo $locked ? ' aria-disabled="true" data-keel-locked="1"' : ''; ?>>
										<?php foreach ( $field['choices'] as $ck ) : ?>
											<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $ck, $value ); ?>>
												<?php echo esc_html( isset( $s['choices'][ $ck ] ) ? $s['choices'][ $ck ] : $ck ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'range' === $field['type'] ) : ?>
									<?php keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby, $locked ); ?>
								<?php elseif ( 'number' === $field['type'] ) : ?>
									<?php if ( $locked ) : ?>
										<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
									<?php endif; ?>
									<input type="number" min="<?php echo esc_attr( isset( $field['min'] ) ? (int) $field['min'] : 0 ); ?>" step="1"
										<?php echo isset( $field['max'] ) ? 'max="' . esc_attr( (int) $field['max'] ) . '"' : ''; ?>
										name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
										aria-label="<?php echo esc_attr( $label ); ?>"<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; ?>
										<?php echo $locked ? 'aria-disabled="true" data-keel-locked="1"' : ''; ?>
										<?php echo $locked ? 'readonly' : ''; ?>
										class="small-text" />
									<?php if ( ! empty( $s['unit'] ) ) : ?>
										<span class="keel-unit" id="<?php echo esc_attr( $unit_id ); ?>" style="margin-inline-start:6px;"><?php echo esc_html( $s['unit'] ); ?></span>
									<?php endif; ?>
								<?php elseif ( 'multiselect' === $field['type'] ) : ?>
									<?php $ms_options = keel_defaults_exemptable_roles(); ?>
									<?php $ms_current = array_map( 'strval', (array) $value ); ?>
									<fieldset<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; ?>>
										<legend class="screen-reader-text"><span><?php echo esc_html( $label ); ?></span></legend>
										<?php if ( empty( $ms_options ) ) : ?>
											<p class="description"><?php esc_html_e( 'No low-privilege roles are available to exempt.', 'keel-defaults' ); ?></p>
										<?php else : ?>
											<?php foreach ( $ms_options as $role_slug => $role_name ) : ?>
												<label style="display:block;margin-bottom:6px;">
													<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( (string) $role_slug, $ms_current, true ) ); ?><?php echo $locked ? ' aria-disabled="true" data-keel-locked="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal. ?> />
													<?php echo esc_html( $role_name ); ?>
												</label>
											<?php endforeach; ?>
										<?php endif; ?>
									</fieldset>
								<?php endif; ?>

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

	</div>
	<?php
}
