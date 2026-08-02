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
				'<p>' . esc_html__( 'The defaults that are on out of the box are low-risk and safe for nearly any site. Anything that can change behavior or break an integration — cross-origin framing, requiring authentication for all REST requests, the Classic editor — is off by default and opt-in.', 'keel' ) . '</p>' .
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
 * Echo a field's description paragraph (allows <code> and links).
 *
 * @param string $help Description text (already translated).
 */
function keel_defaults_render_help( $help ) {
	if ( '' === (string) $help ) {
		return;
	}
	echo '<p class="description">' . wp_kses(
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
 * @param string $name      Field input name.
 * @param mixed  $value     Current value.
 * @param string $statement The "true when checked" statement (already translated).
 * @param bool   $disabled  Whether to render the checkbox disabled.
 */
function keel_defaults_render_checkbox( $name, $value, $statement, $disabled = false ) {
	printf(
		'<label><input type="checkbox" name="%s" value="yes" %s%s /> %s</label>',
		esc_attr( $name ),
		checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
		disabled( $disabled, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed literal.
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
							keel_defaults_render_checkbox( $name, $value, $statement );
							keel_defaults_render_help( $help );
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
										<?php keel_defaults_render_checkbox( $name, $value, $statement, $locked ); ?>
									</fieldset>
								<?php elseif ( 'select' === $field['type'] ) : ?>
									<?php if ( $locked ) : ?>
										<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
									<?php endif; ?>
									<select name="<?php echo esc_attr( $name ); ?>" <?php disabled( $locked ); ?>>
										<?php foreach ( $field['choices'] as $ck ) : ?>
											<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $ck, $value ); ?>>
												<?php echo esc_html( isset( $s['choices'][ $ck ] ) ? $s['choices'][ $ck ] : $ck ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'range' === $field['type'] ) : ?>
									<?php
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
									<input type="number" min="<?php echo esc_attr( isset( $field['min'] ) ? (int) $field['min'] : 0 ); ?>" step="1"
										name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
										class="small-text" />
								<?php endif; ?>

								<?php if ( $locked ) : ?>
									<p class="description keel-config-lock"><?php echo wp_kses( $lock, array( 'code' => array() ) ); ?></p>
								<?php endif; ?>

								<?php keel_defaults_render_help( $help ); ?>
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
