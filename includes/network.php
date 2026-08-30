<?php
/**
 * Network-scoped policy: settings a super admin decides once for every site.
 *
 * The problem this exists for is documented in readme.txt and has been for
 * weeks. WordPress keeps **one user table** for a whole network, so Keel's
 * password policy is stored per site but does not act per site: a password is
 * checked against whichever site the person happens to be setting it on, and
 * once set it is their password everywhere. The practical effect is that the
 * strictest site on a network sets the floor for anyone who changes their
 * password there. That is an honest description of a design nobody chose, and
 * the FAQ had to spend a paragraph explaining it.
 *
 * A network-scoped value replaces that paragraph with a setting.
 *
 * The model is deliberately small. A key present in the network option is
 * managed network-wide; a key absent from it is each site's own business. There
 * is no separate "enforce" flag, because a flag that can disagree with a value
 * is a third state to keep consistent and a fourth to get wrong — and the
 * question a super admin is actually asking is "do I decide this, or do they?",
 * which presence answers on its own.
 *
 * Enforcement is at read, not by writing into subsites. Pushing values into
 * every site's options would overwrite settings the site owner chose, would need
 * undoing if the policy were ever relaxed, and would silently disagree with the
 * network screen the moment a subsite saved its own form. Resolving at read
 * means a network value can be set and unset without touching a single subsite,
 * and unsetting it returns each site to exactly the value it had before.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every network-managed setting, as key => value.
 *
 * Empty on single site, and empty on a network where nothing has been set —
 * which is the state every existing install upgrades into, so this feature
 * changes nothing until somebody uses it.
 *
 * @return array
 */
function keel_defaults_network_settings() {
	if ( ! is_multisite() ) {
		return array();
	}

	$stored = get_site_option( KEEL_DEFAULTS_NETWORK_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Whether a setting is decided for the whole network.
 *
 * @param string $key Schema key.
 * @return bool
 */
function keel_defaults_network_manages( $key ) {
	$settings = keel_defaults_network_settings();

	return array_key_exists( $key, $settings );
}

/**
 * The network's value for a setting.
 *
 * Returns null when the setting is not network-managed, which is distinguishable
 * from a managed value of `null` only because the schema has no such value —
 * every default is a string, an int or an array.
 *
 * @param string $key Schema key.
 * @return mixed|null
 */
function keel_defaults_network_value( $key ) {
	$settings = keel_defaults_network_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
}

/**
 * The note shown beside a control a super admin has taken over.
 *
 * Deliberately the same shape as `keel_defaults_config_lock()`: a string to show
 * and a disabled control, or null. A site administrator meeting a setting they
 * cannot change should not have to learn two different explanations for why, and
 * the accessible wiring that announces the reason before the label already
 * exists for the wp-config case.
 *
 * @param string $key Schema key.
 * @return string|null
 */
function keel_defaults_network_lock( $key ) {
	if ( ! keel_defaults_network_manages( $key ) ) {
		return null;
	}

	return __( 'Set for the whole network by a Super Admin. Change it under Network Admin → Settings → Network Policy.', 'keel-defaults' );
}

/**
 * Whether the current user may set network policy.
 *
 * `manage_network_options` rather than `manage_options`: this screen decides for
 * every site on the network, and a site administrator holds `manage_options` on
 * their own site.
 *
 * @return bool
 */
function keel_defaults_can_manage_network() {
	return is_multisite() && current_user_can( 'manage_network_options' );
}

/**
 * Sanitize a submitted network policy into the stored shape.
 *
 * Only keys whose "manage" box is ticked are stored, and each value goes through
 * the same `keel_defaults_sanitize()` the per-site screen uses — one sanitizer,
 * so a value that would be rejected on a site screen cannot arrive through the
 * network screen instead.
 *
 * @param array $raw   Raw $_POST payload for the network form.
 * @param array $manage Keys the super admin ticked.
 * @return array
 */
function keel_defaults_sanitize_network( $raw, $manage ) {
	$schema = keel_defaults_schema();
	$raw    = is_array( $raw ) ? $raw : array();
	$manage = is_array( $manage ) ? $manage : array();
	$stored = keel_defaults_network_settings();

	// Sanitize the whole submission once, then keep only the managed keys. Doing
	// it in this order means the sanitizer sees a complete payload, which is what
	// it expects — several fields clamp against each other, and remembered-login
	// length is clamped against the regular length rather than in isolation.
	$clean = keel_defaults_sanitize( $raw );
	$out   = array();

	foreach ( array_keys( $schema ) as $key ) {
		/*
		 * The form does not render unsupported settings, so a posted absence
		 * says nothing about them. Carry any existing policy through untouched
		 * rather than reading the silence as "unmanage this".
		 */
		if ( ! keel_defaults_key_supported( $key ) ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}

			continue;
		}

		// wp-config.php is above network policy. Preserve an existing policy
		// underneath the lock, or keep an unmanaged key absent, exactly as the
		// site sanitizer preserves a site's dormant preference.
		if ( null !== keel_defaults_config_lock( $key ) ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
			continue;
		}

		if ( ! array_key_exists( $key, $manage ) ) {
			continue;
		}

		if ( array_key_exists( $key, $clean ) ) {
			$out[ $key ] = $clean[ $key ];
		}
	}

	return $out;
}

/**
 * The Network Admin screen: one policy for every site.
 *
 * Registered under Network Admin → Settings, and only there. A site
 * administrator never sees it — `manage_network_options` is the capability, and
 * on a network only Super Admins hold it.
 */
function keel_defaults_network_menu() {
	$hook = add_submenu_page(
		'settings.php',
		__( 'Network Policy', 'keel-defaults' ),
		__( 'Network Policy', 'keel-defaults' ),
		'manage_network_options',
		'keel-network',
		'keel_defaults_render_network_page'
	);

	keel_defaults_enqueue_on_screen( $hook, 'keel_defaults_enqueue_network_assets' );
}

/**
 * Handle the network form.
 *
 * Network Admin has no options.php equivalent — `settings.php` does not save for
 * you — so the POST is handled here, nonce and capability checked by hand, and
 * the redirect carries the result rather than leaving the browser on a POST.
 */
function keel_defaults_handle_network_save() {
	if ( ! isset( $_POST['keel_network_nonce'] ) ) {
		return;
	}

	if ( ! keel_defaults_can_manage_network() ) {
		wp_die( esc_html__( 'You do not have permission to set network policy.', 'keel-defaults' ) );
	}

	check_admin_referer( 'keel-network-save', 'keel_network_nonce' );

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- both are sanitized by keel_defaults_sanitize_network().
	$raw    = isset( $_POST[ KEEL_DEFAULTS_OPTION ] ) ? wp_unslash( $_POST[ KEEL_DEFAULTS_OPTION ] ) : array();
	$manage = isset( $_POST['keel_network_manage'] ) ? wp_unslash( $_POST['keel_network_manage'] ) : array();
	// phpcs:enable

	update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, keel_defaults_sanitize_network( $raw, $manage ) );

	wp_safe_redirect( add_query_arg( 'keel-updated', '1', network_admin_url( 'settings.php?page=keel-network' ) ) );
	exit;
}

/**
 * Render the network policy screen.
 */
function keel_defaults_render_network_page() {
	if ( ! keel_defaults_can_manage_network() ) {
		return;
	}

	$schema   = keel_defaults_schema();
	$strings  = keel_defaults_strings();
	$groups   = keel_defaults_group_labels();
	$network  = keel_defaults_network_settings();
	$sections = keel_defaults_section_labels();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Keel — network policy', 'keel-defaults' ); ?></h1>

		<?php if ( isset( $_GET['keel-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag. ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network policy saved.', 'keel-defaults' ); ?></p></div>
		<?php endif; ?>

		<p class="description" style="max-width:46em;">
			<?php esc_html_e( 'Tick a setting to decide it for every site on this network. Sites see it as locked and cannot change it. Anything left unticked stays each site\'s own business, and their saved values are untouched — untick it later and every site returns to exactly what it had.', 'keel-defaults' ); ?>
		</p>

		<p class="description" style="max-width:46em;">
			<?php esc_html_e( 'The password rules are the ones most worth setting here. WordPress keeps one user table for the whole network, so a password is checked against whichever site it is set on — without a network policy, the strictest site sets the floor for everyone who changes their password there.', 'keel-defaults' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( network_admin_url( 'settings.php?page=keel-network' ) ); ?>">
			<?php wp_nonce_field( 'keel-network-save', 'keel_network_nonce' ); ?>

			<?php foreach ( $groups as $group_key => $group_label ) : ?>
				<h2><?php echo esc_html( $group_label ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<?php
					foreach ( $schema as $key => $field ) :
						if ( $field['group'] !== $group_key ) {
							continue;
						}

						/*
						 * Same gate as the site screen. Offering "decide this for
						 * the whole network" for a feature this WordPress does not
						 * have lets a Super Admin set a policy that cannot take
						 * effect, and contradicts the site screen next door, which
						 * does not show the setting at all.
						 */
						if ( ! keel_defaults_key_supported( $key ) ) {
							continue;
						}

						$s         = isset( $strings[ $key ] ) ? $strings[ $key ] : array();
						$label     = isset( $s['label'] ) ? $s['label'] : $key;
						$statement = isset( $s['statement'] ) ? $s['statement'] : $label;
						$managed   = array_key_exists( $key, $network );
						$value     = $managed ? $network[ $key ] : $field['default'];
						$name      = KEEL_DEFAULTS_OPTION . '[' . $key . ']';
						$desc_id   = 'keel-net-' . $key . '-desc';
						$lock      = keel_defaults_config_lock( $key );
						$locked    = null !== $lock;
						$lock_id   = $locked ? 'keel-net-' . $key . '-lock' : '';
						$described = trim( implode( ' ', array_filter( array( $lock_id, ! empty( $s['help'] ) ? $desc_id : '' ) ) ) );
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><span><?php echo esc_html( $label ); ?></span></legend>

									<label>
										<input type="checkbox" name="keel_network_manage[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $managed ); ?><?php echo $locked ? ' aria-disabled="true" data-keel-locked="1"' : ''; ?><?php echo '' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : ''; ?> />
										<?php esc_html_e( 'Decide this for the whole network', 'keel-defaults' ); ?>
									</label>

									<p style="margin:6px 0 0;">
										<?php keel_defaults_render_network_control( $key, $name, $value, $field, $s, $statement, $described, $locked ); ?>
									</p>

									<?php if ( $locked ) : ?>
										<p class="description keel-config-lock" id="<?php echo esc_attr( $lock_id ); ?>"><?php echo wp_kses( $lock, array( 'code' => array() ) ); ?></p>
									<?php endif; ?>

									<?php if ( ! empty( $s['help'] ) ) : ?>
										<p class="description" id="<?php echo esc_attr( $desc_id ); ?>"><?php echo wp_kses( $s['help'], array( 'code' => array() ) ); ?></p>
									<?php endif; ?>
								</fieldset>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save network policy', 'keel-defaults' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Render one value control for the network screen.
 *
 * Deliberately simpler than the per-site screen: no dependent-row hiding, no
 * range slider with a live preview. A super admin setting policy for fifty sites
 * is not previewing their own admin menu width, and every one of those
 * affordances is a second implementation to keep in step with the first.
 *
 * @param string $key       Schema key.
 * @param string $name      Form field name.
 * @param mixed  $value     Current value.
 * @param array  $field     Schema entry.
 * @param array  $s         Display strings.
 * @param string $statement Checkbox statement.
 * @param string $describedby Description ids for aria-describedby.
 * @param bool   $locked      Whether wp-config.php supersedes this control.
 */
function keel_defaults_render_network_control( $key, $name, $value, $field, $s, $statement, $describedby, $locked = false ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'toggle';
	$aria = '' !== $describedby ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : '';
	$lock = $locked ? ' aria-disabled="true" data-keel-locked="1"' : '';

	if ( 'toggle' === $type ) {
		printf(
			'<label><input type="checkbox" name="%s" value="yes" %s%s%s /> %s</label>',
			esc_attr( $name ),
			checked( 'yes', $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr().
			$lock, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			wp_kses( $statement, array( 'code' => array() ) )
		);
		return;
	}

	if ( 'number' === $type ) {
		printf(
			'<input type="number" name="%s" value="%s" min="%d" max="%d" class="small-text"%s%s%s /> %s',
			esc_attr( $name ),
			esc_attr( (string) $value ),
			isset( $field['min'] ) ? (int) $field['min'] : 0,
			isset( $field['max'] ) ? (int) $field['max'] : 3650,
			$aria, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr().
			$lock, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			$locked ? ' readonly' : '',
			esc_html( isset( $s['unit'] ) ? $s['unit'] : '' )
		);
		return;
	}

	if ( 'multiselect' === $type ) {
		/*
		 * The schema carries no `choices` for the one multiselect there is: the
		 * roles are discovered at runtime, because which roles exist is a
		 * property of the site rather than of this plugin. The site screen has
		 * always asked keel_defaults_exemptable_roles() for them; this screen
		 * read a key that has never existed and emitted a warning per option.
		 *
		 * Nothing caught it because nothing had rendered this page in a test.
		 */
		$chosen = (array) $value;

		/*
		 * slug => label, both ways in. A schema-provided list is bare slugs
		 * labelled from strings.php; the roles arrive already paired with their
		 * translated names, which `translate_user_role()` has localised. Taking
		 * only the keys threw those away and printed `subscriber` at a Super
		 * Admin — correct data, and not what the site screen shows beside it.
		 */
		if ( isset( $field['choices'] ) ) {
			$choices = array();

			foreach ( (array) $field['choices'] as $slug ) {
				$choices[ $slug ] = isset( $s['choices'][ $slug ] ) ? $s['choices'][ $slug ] : $slug;
			}
		} else {
			$choices = keel_defaults_exemptable_roles();
		}

		foreach ( $choices as $choice => $label ) {
			printf(
				'<label style="margin-inline-end:12px;"><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
				esc_attr( $name ),
				esc_attr( $choice ),
				checked( in_array( (string) $choice, array_map( 'strval', $chosen ), true ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
				esc_html( $label )
			);
		}
		return;
	}

	// select and range both resolve to a list of discrete choices.
	$choices = array();

	if ( 'range' === $type ) {
		$values = isset( $field['values'] ) ? array_values( $field['values'] ) : array();
		$labels = isset( $s['labels'] ) ? array_values( $s['labels'] ) : array();
		foreach ( $values as $i => $v ) {
			$choices[ (string) $v ] = isset( $labels[ $i ] ) ? $labels[ $i ] : (string) $v;
		}
	} else {
		foreach ( (array) $field['choices'] as $c ) {
			$choices[ (string) $c ] = isset( $s['choices'][ $c ] ) ? $s['choices'][ $c ] : (string) $c;
		}
	}

	printf( '<select name="%s"%s%s>', esc_attr( $name ), $aria, $lock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr() or fixed.

	foreach ( $choices as $cval => $clabel ) {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $cval ),
			selected( (string) $cval, (string) $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal.
			esc_html( $clabel )
		);
	}

	echo '</select>';
}
