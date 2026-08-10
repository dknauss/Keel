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
			__( 'Keel', 'keel' ),
			__( 'Site Defaults', 'keel' ),
			'manage_options',
			'keel',
			'keel_defaults_render_settings_page'
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, 'keel_defaults_add_help_tab' );
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
		esc_html__( 'Settings', 'keel' )
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
			'title'   => __( 'Overview', 'keel' ),
			'content' =>
				'<p>' . esc_html__( 'Keel applies a menu of sensible security, privacy, UX, and performance defaults to WordPress. Each switch on this page is one default, and they are independent: turning one on does not turn on anything else, and a switch left off means Keel does not apply that default at all.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'The defaults that are on out of the box are low-risk and safe for nearly any site. Anything that can change behavior or break an integration — requiring authentication for all REST requests, blocking the XML-RPC endpoint, the Classic editor — is off by default and opt-in.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'There is one exception: Keel sends an X-Frame-Options header of SAMEORIGIN, so other sites cannot embed yours in an iframe. If something else is meant to display this site inside a frame — an intranet dashboard, a screenshot or visual-review service, a kiosk or signage screen — set Frame options to “Leave unchanged” under Security and Attack Surface. A blocked frame usually fails silently, as a blank box.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'Five settings disappear, become inactive, and cannot be toggled when another choice makes them irrelevant and takes them off the table: the three XML-RPC method controls when the endpoint itself is blocked, Remember Me Length when Remember Me is off, and Password Policy Exemptions when Password Strength is off.', 'keel' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-passwords',
			'title'   => __( 'Passwords', 'keel' ),
			'content' =>
				'<p>' . wp_kses(
					__( 'For strong passwords, Keel requires length and breach screening in place of composition rules that require mixtures of different character types. This follows <a href="https://pages.nist.gov/800-63-4/sp800-63b/authenticators/#passwordver" target="_blank" rel="noopener noreferrer">NIST SP 800-63B-4 § 3.1.1.2</a>. Composition rules push people toward predictable shapes — like <code>Password1!</code> — without making them harder to guess.', 'keel' ),
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
					__( 'WordPress already shows a strength meter as you type, and Keel leaves it in place — it is good advice. It cannot be the rule, though. The meter, the weak-password warning and the checkbox asking you to confirm a weak password are all JavaScript, and nothing on the server reads any of them, so a password set through the REST API, WP-CLI, or a form with scripts turned off is never measured at all.', 'keel' ),
					array()
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'So Keel enforces on the server, where nothing can skip it — but it asks how long a password is and whether it has ever appeared in a breach, not how easy it is to guess. Something long that has never leaked can still be an obvious choice, and spotting that is what the meter is good at. They cover different things, which is why both are here.', 'keel' ),
					array()
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'The breach check sends <a href="https://haveibeenpwned.com/API/v3#SearchingPwnedPasswordsByRange" target="_blank" rel="noopener noreferrer">Have I Been Pwned</a> only the first five characters of a SHA-1 hash computed on this site, and matches the returned suffixes locally — so neither the password nor its full hash leaves the site. It can be switched off with the <code>KEEL_DISABLE_HIBP</code> constant or the <code>keel_disable_hibp</code> filter.', 'keel' ),
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
					__( 'An outage or a malformed response lets the password through rather than blocking it. That is deliberate &#8212; the alternative is that nobody can change a password if the HIBP API is down. The length, blocklist and personal-context rules still apply, and only a response that arrived whole and parsed cleanly is ever cached, so one bad reply cannot become hours of false &#8220;not breached&#8221; answers. The tradeoff: anything that stops this site reaching the HIBP API turns breach screening off quietly.', 'keel' ),
					array()
				) . '</p>' .
				( is_multisite()
					? '<p>' . wp_kses(
						__( 'On multisite, this setting is stored per site but does not act per site. WordPress keeps one user table for the whole network, so a password is checked against whichever site the person is setting it on — and once set, it is their password everywhere. A subsite that exempts a role is not exempting those accounts from another site\'s policy; it is deciding what happens when the password is changed there. The practical effect is that the strictest site on the network sets the floor for anyone who changes their password on it. Keel documents this rather than governing it: network-wide policy is deliberately out of scope for now.', 'keel' ),
						array()
					) . '</p>'
					: ''
				),
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'keel-xmlrpc',
			'title'   => __( 'XML-RPC', 'keel' ),
			'content' =>
				'<p>' . esc_html__( 'XML-RPC is WordPress\'s original remote API. It predates the REST API and still carries the methods that let an outside client publish, fetch and manage a site with a username and password. Most sites no longer use XML-RPC, but some may need it in part or entirely.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'That is why these are four switches rather than one. Turning the endpoint off completely is the strictest posture. The three narrower controls remove the specific method families that attract abuse — pingbacks, credential-authenticated publishing, and multicall — while still leaving the endpoint available.', 'keel' ) . '</p>' .
				'<p>' . esc_html__( 'Jetpack talks to WordPress.com over XML-RPC, so blocking the endpoint breaks the connection and everything downstream of it. Test the connection and the features you use before deciding Jetpack no longer needs it.', 'keel' ) . '</p>' .
				'<p>' . wp_kses(
					__( 'Blocking the endpoint inside WordPress still costs a request. PHP starts, WordPress loads, and only then does the plugin answer 403. If your host, CDN or firewall can refuse <code>xmlrpc.php</code> before the request even reaches WordPress, that is cheaper under exactly the load that makes blocking attractive — a flood of requests. This setting is the answer for sites without that option.', 'keel' ),
					array( 'code' => array() )
				) . '</p>' .
				'<p>' . wp_kses(
					__( 'Please note that <code>system.multicall</code>&#8217;s negative reputation is out of date. It once let an attacker bundle hundreds of password guesses into a single request. WordPress 4.4 closed that in 2015. Refusing it today is modest attack-surface reduction against batching, not a fix for a live vulnerability.', 'keel' ),
					array( 'code' => array() )
				) . '</p>',
		)
	);

	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'Current posture', 'keel' ) . '</strong></p>' .
		'<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of the Site Health Info screen. */
				__( 'Every default and its current state is listed under <a href="%s">Site Health → Info</a>, in the <strong>Keel Defaults</strong> section. Site Health → Status flags only the defaults that warrant attention, and files the rest under “Passed tests”.', 'keel' ),
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
				$min           = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$val           = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : (int) $field['default'];
				$clean[ $key ] = max( $min, $val );
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
	printf(
		'<label><input type="checkbox" name="%s" value="yes" %s%s%s /> %s</label>',
		esc_attr( $name ),
		checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
		disabled( $disabled, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed literal.
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
				return __( 'Locked by <code>WP_AUTO_UPDATE_CORE</code> in <code>wp-config.php</code>. Remove that constant to manage core releases here.', 'keel' );
			}
			if ( $updates_off ) {
				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel' ), $updates_off );
			}
			break;

		case 'auto_update_translations':
			if ( $updates_off ) {
				/* translators: %s: a wp-config.php constant name. */
				return sprintf( __( 'Overridden by <code>%s</code> in <code>wp-config.php</code>: WordPress installs no background updates.', 'keel' ), $updates_off );
			}
			break;

		case 'limit_unfiltered_html_to_admins':
			if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
				return __( '<code>DISALLOW_UNFILTERED_HTML</code> in <code>wp-config.php</code> already removes unfiltered HTML from every role, so this restriction has no additional effect.', 'keel' );
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
 * other field types run ten to twenty lines each; this one carries a whole
 * feature — the slider, the preview stylesheet it toggles, and the script that
 * drives them — and inlining all of it put CSS at a depth where the surrounding
 * PHP was no longer legible.
 *
 * The markup is unchanged apart from leading whitespace: raw HTML inside a
 * template emits its own indentation literally, so de-indenting the source
 * de-indents the output. Every tag, attribute and text node is identical — the
 * affected whitespace sits between tags and inside <style> and <script>, where
 * it carries no meaning. Verified by diffing the rendered screen before and
 * after, in two option states, and confirming the only changes were leading
 * tabs; tests/settings-render.php holds the structural assertions from here on.
 *
 * @param string $key         Schema key, used to build element ids.
 * @param string $name        Input name attribute.
 * @param mixed  $value       Stored value.
 * @param array  $field       Schema field.
 * @param array  $s           Display strings for this field.
 * @param string $label       Field label.
 * @param string $describedby Space-separated ids for aria-describedby.
 * @return void
 */
function keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby ) {
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
			// Cheap: runs live while dragging — only the readout text changes.
			function updateLabel() {
				var text = labels[ pos() ] || labels[0];
				output.textContent = text;
				// The value a screen reader announces has to move with the word,
				// or the slider goes on saying whatever it was rendered at.
				input.setAttribute( 'aria-valuetext', text );
			}
			// Expensive: reflows the whole admin layout and reads a computed
			// style, so it runs only when the drag settles (release/keyboard),
			// not on every 'input' tick — otherwise the slider is janky.
			function applyPreview() {
				document.body.style.setProperty( '--keel-menu-preview-width', ( widths[ pos() ] || widths[0] ) + 'px' );
				var am = document.getElementById( 'adminmenu' );
				if ( am ) { document.body.style.setProperty( '--keel-menu-preview-bg', window.getComputedStyle( am ).backgroundColor ); }
				document.body.classList.add( 'keel-menu-width-preview' );
			}
			input.addEventListener( 'input', updateLabel );
			input.addEventListener( 'change', applyPreview );
			input.addEventListener( 'pointerup', applyPreview );
			input.addEventListener( 'keyup', function ( event ) {
				if ( [ 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown' ].indexOf( event.key ) !== -1 ) {
					applyPreview();
				}
			} );
		} )();
		</script>
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
				<h1 style="margin:0;padding:0;line-height:1.2;"><?php esc_html_e( 'Keel', 'keel' ); ?></h1>
				<p class="description" style="font-size:14px;margin:2px 0 0;"><?php esc_html_e( 'Sensible defaults for steady sites.', 'keel' ); ?></p>
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
		<p><?php esc_html_e( 'Keel sets a sound baseline for your site — sensible defaults for security, updates, privacy, the admin experience, and performance. Every option below is one deliberate default you can see and switch off. Nothing runs that isn\'t listed here, and anything you leave unchecked keeps WordPress exactly as it ships.', 'keel' ); ?></p>

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

						// Display copy lives in strings.php (translatable); the schema
						// above carries only structure.
						$s         = isset( $strings[ $key ] ) ? $strings[ $key ] : array();
						$label     = isset( $s['label'] ) ? $s['label'] : $key;
						$statement = isset( $s['statement'] ) ? $s['statement'] : $label;
						$help      = isset( $s['help'] ) ? $s['help'] : '';

						// A wp-config.php constant may supersede this setting; if so the
						// control is disabled and the reason shown next to it.
						$lock   = keel_defaults_config_lock( $key );
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
							list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field );
							echo '<div class="keel-dep-item"' . $dep_attr . ( $dep_hidden ? ' style="display:none;"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- dep_attr is built from esc_attr().
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

						list( $dep_attr, $dep_hidden ) = keel_defaults_dep_state( $field );
						?>
						<tr<?php echo $dep_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(). ?><?php echo $dep_hidden ? ' style="display:none;"' : ''; ?>>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
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
									<select name="<?php echo esc_attr( $name ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; ?> <?php disabled( $locked ); ?>>
										<?php foreach ( $field['choices'] as $ck ) : ?>
											<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $ck, $value ); ?>>
												<?php echo esc_html( isset( $s['choices'][ $ck ] ) ? $s['choices'][ $ck ] : $ck ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'range' === $field['type'] ) : ?>
									<?php keel_defaults_render_range_field( $key, $name, $value, $field, $s, $label, $describedby ); ?>
								<?php elseif ( 'number' === $field['type'] ) : ?>
									<input type="number" min="<?php echo esc_attr( isset( $field['min'] ) ? (int) $field['min'] : 0 ); ?>" step="1"
										name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
										aria-label="<?php echo esc_attr( $label ); ?>"<?php echo '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''; ?>
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
											<p class="description"><?php esc_html_e( 'No low-privilege roles are available to exempt.', 'keel' ); ?></p>
										<?php else : ?>
											<?php foreach ( $ms_options as $role_slug => $role_name ) : ?>
												<label style="display:block;margin-bottom:6px;">
													<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( (string) $role_slug, $ms_current, true ) ); ?> />
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
	</div>
	<?php
}
