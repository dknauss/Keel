<?php
/**
 * The Keel Site Health surface.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

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
	$tests['direct']['keel_defaults_posture']   = array(
		'label' => __( 'Keel Defaults', 'keel-defaults' ),
		'test'  => 'keel_defaults_site_health_posture',
	);
	$tests['direct']['keel_defaults_conflicts'] = array(
		'label' => __( 'Overlapping defaults plugins', 'keel-defaults' ),
		'test'  => 'keel_defaults_site_health_conflicts',
	);
	return $tests;
}

/**
 * Human-readable current state of one setting, for the posture summary.
 *
 * @param array $field Schema field (structure).
 * @param mixed $value Current value.
 * @param array $s     Display strings for this field (choice/range labels).
 * @return string
 */
function keel_defaults_state_label( $field, $value, $s = array() ) {
	$type = isset( $field['type'] ) ? $field['type'] : 'toggle';

	if ( 'toggle' === $type ) {
		return ( 'yes' === $value ) ? __( 'On', 'keel-defaults' ) : __( 'Off', 'keel-defaults' );
	}
	if ( 'select' === $type ) {
		if ( '' === $value ) {
			return __( 'Unchanged', 'keel-defaults' );
		}
		return isset( $s['choices'][ $value ] ) ? (string) $s['choices'][ $value ] : (string) $value;
	}
	if ( 'multiselect' === $type ) {
		$vals = array_map( 'strval', (array) $value );
		return empty( $vals ) ? __( 'None', 'keel-defaults' ) : implode( ', ', $vals );
	}
	if ( 'range' === $type ) {
		$values = isset( $field['values'] ) ? array_map( 'strval', array_values( $field['values'] ) ) : array();
		$labels = isset( $s['labels'] ) ? array_values( $s['labels'] ) : array();
		$idx    = array_search( (string) $value, $values, true );
		return ( false !== $idx && isset( $labels[ $idx ] ) ) ? (string) $labels[ $idx ] : (string) $value;
	}

	/*
	 * A bare number is not a state. The settings screen prints the unit beside the
	 * input, so "14" reads as days there and nowhere else; in Site Health and in
	 * the Status summary it arrived as "Remember Me Length: 14".
	 *
	 * Both number fields are days and the minimum is 1, so "1 days" is reachable
	 * and this needs _n() rather than a suffix. Hard-coding the unit here is safe
	 * only for as long as that stays true, so tests/site-health.php asserts it and
	 * names this function when it stops being true.
	 */
	if ( 'number' === $type ) {
		$days = (int) $value;
		/* translators: %s: number of days. */
		return sprintf( _n( '%s day', '%s days', $days, 'keel-defaults' ), number_format_i18n( $days ) );
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
function keel_defaults_posture_by_group() {
	$schema  = keel_defaults_schema();
	$strings = keel_defaults_strings();

	$by_group = array();

	foreach ( $schema as $key => $field ) {
		// Same rule as the settings screen: do not report a posture on a
		// feature this WordPress does not have. Site Health is read as the
		// answer to "what is this plugin doing to my site?", and a row for a
		// setting with nothing to act on is a wrong answer.
		if ( ! keel_defaults_key_supported( $key ) ) {
			continue;
		}

		$group                = isset( $field['group'] ) ? $field['group'] : 'other';
		$s                    = isset( $strings[ $key ] ) ? $strings[ $key ] : array();
		$by_group[ $group ][] = array(
			'key'   => $key,
			'label' => isset( $s['label'] ) ? $s['label'] : $key,
			'state' => keel_defaults_state_label( $field, keel_defaults_get( $key ), $s ),
		);
	}

	return $by_group;
}

/**
 * The same summary, as Site Health → Info.
 *
 * The Status tab was the wrong home for this and that is why nobody could find
 * it. A passing test is filed inside the collapsed "Passed tests" accordion at
 * the bottom of the page, so on a correctly configured site — the common case —
 * the summary is behind a fold with nothing pointing at it.
 *
 * Info is where WordPress keeps inventories: always expanded, copyable as a
 * block, and the first thing anyone asks for in a support thread. A read-only
 * list of every default and its state is an inventory, not a health warning.
 *
 * The Status test stays. It answers a different question — is anything actually
 * wrong — and it is the one that should be quiet when the answer is no.
 *
 * @param array $info Debug information sections.
 * @return array
 */
function keel_defaults_debug_information( $info ) {
	if ( ! is_array( $info ) ) {
		return $info;
	}

	$groups = keel_defaults_group_labels();
	$fields = array();

	/*
	 * One row per group, not per default. Naming the group on all 38 rows put
	 * "Security and Attack Surface: " in front of nine of them and made the
	 * column that should scan fastest the one carrying the most repetition.
	 *
	 * Core renders an array value as a definition list inside the value cell, so
	 * the group is stated once and its defaults sit under it. That is as close as
	 * the filter reaches: wp-admin/site-health-info.php emits a fixed two columns
	 * and passes both through esc_html, so a third column, a row-spanning cell
	 * and a bold group name are all unavailable here. The clipboard export
	 * indents nested values the same way, so a pasted report groups too.
	 */
	foreach ( keel_defaults_posture_by_group() as $group_key => $items ) {
		$states = array();

		foreach ( $items as $item ) {
			$states[ $item['label'] ] = $item['state'];
		}

		$fields[ $group_key ] = array(
			'label' => isset( $groups[ $group_key ] ) ? $groups[ $group_key ] : $group_key,
			'value' => $states,
		);
	}

	$info[ KEEL_DEFAULTS_INFO_SECTION ] = array(
		'label'       => __( 'Keel Defaults', 'keel-defaults' ),
		'description' => __( 'Every default Keel manages and its current state on this site. Read-only — change them under Settings → Keel.', 'keel-defaults' ),
		'fields'      => $fields,
	);

	return $info;
}

/**
 * Posture summary for Site Health → Status.
 *
 * @return array
 */
function keel_defaults_site_health_posture() {
	$groups   = keel_defaults_group_labels();
	$by_group = keel_defaults_posture_by_group();

	$strong_ok = keel_defaults_enabled( 'require_strong_passwords' );
	$rest_ok   = keel_defaults_enabled( 'restrict_rest_user_discovery' );
	$status    = ( $strong_ok && $rest_ok ) ? 'good' : 'recommended';

	$description = '<p>' . esc_html__( 'Current state of the defaults Keel manages on this site — a read-only summary of your choices under Settings → Keel, not a warning.', 'keel-defaults' ) . '</p>';

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
			$notes[] = esc_html__( 'Strong passwords are off, so privileged accounts can set weak or breached passwords.', 'keel-defaults' );
		}
		if ( ! $rest_ok ) {
			$notes[] = esc_html__( 'REST user discovery is open, so anonymous visitors can enumerate usernames.', 'keel-defaults' );
		}
		$description .= '<p><strong>' . esc_html__( 'Worth a look:', 'keel-defaults' ) . '</strong> ' . esc_html( implode( ' ', $notes ) ) . '</p>';
	}

	return array(
		'label'       => ( 'good' === $status )
			? __( 'Keel defaults are in place', 'keel-defaults' )
			: __( 'Some Keel security defaults are off', 'keel-defaults' ),
		'status'      => $status,
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => ( 'good' === $status ) ? 'green' : 'orange',
		),
		'description' => $description,
		'actions'     => sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=keel' ) ),
			esc_html__( 'Review Keel settings', 'keel-defaults' )
		),
		'test'        => 'keel_defaults_posture',
	);
}

/**
 * Report other plugins competing to set the same defaults.
 *
 * Two plugins that both set a session length do not error, do not log, and do
 * not look wrong: WordPress runs both filters and keeps whichever answered
 * last, so the losing plugin's settings screen goes on displaying a value the
 * site does not use.
 *
 * The result names what is contesting what and deliberately does not say which
 * plugin to keep. That is a judgement about what the site needs, and a check
 * answering it would be Keel arguing for its own retention.
 *
 * @return array
 */
function keel_defaults_site_health_conflicts() {
	$conflicts = keel_defaults_competing_plugins();
	$badge     = array(
		'label' => __( 'Keel', 'keel-defaults' ),
		'color' => 'blue',
	);
	$intro     = '<p>' . esc_html__( 'Settings such as session length and login behavior are applied through WordPress filters that return a single value. When two plugins set the same one, WordPress keeps whichever ran last. There is no error, and the plugin that lost goes on showing the value it believes it applied.', 'keel-defaults' ) . '</p>';

	if ( empty( $conflicts ) ) {
		return array(
			'label'       => __( 'No other plugin is setting the same defaults', 'keel-defaults' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => $intro,
			'test'        => 'keel_defaults_conflicts',
		);
	}

	$plugins = array();
	foreach ( $conflicts as $hook_plugins ) {
		foreach ( $hook_plugins as $plugin ) {
			$plugins[ $plugin ] = true;
		}
	}

	$description = $intro . '<p><strong>' . sprintf(
		/* translators: %s: comma-separated plugin directory names. */
		esc_html__( 'Also setting these defaults: %s', 'keel-defaults' ),
		esc_html( implode( ', ', array_keys( $plugins ) ) )
	) . '</strong></p><p>' . esc_html__( 'Only one plugin should own these settings. Choose the one this site keeps and deactivate the others — whichever you keep, its settings screen will then be telling the truth.', 'keel-defaults' ) . '</p><ul>';

	foreach ( $conflicts as $hook => $hook_plugins ) {
		$description .= '<li><code>' . esc_html( $hook ) . '</code> — ' . sprintf(
			/* translators: %s: comma-separated plugin directory names. */
			esc_html__( 'contested by %s', 'keel-defaults' ),
			esc_html( implode( ', ', $hook_plugins ) )
		) . '</li>';
	}

	$description .= '</ul>';

	return array(
		'label'       => __( 'More than one plugin is setting the same defaults', 'keel-defaults' ),
		'status'      => 'recommended',
		'badge'       => $badge,
		'description' => $description,
		'test'        => 'keel_defaults_conflicts',
	);
}

/**
 * The hooks Keel sets policy through, and whether sharing one matters.
 *
 * `authoritative` — the callback returns a value that replaces its input, so
 * two callbacks cannot both win and the later registration silently decides.
 * `additive` — callbacks contribute to a structure, so coexisting is normal.
 *
 * This map is the only part of the check needing human judgement, and it stays
 * short because it covers only the hooks Keel writes policy through.
 *
 * @return array<string, string>
 */
function keel_defaults_policy_hooks() {
	return apply_filters(
		'keel_policy_hooks',
		array(
			'auth_cookie_expiration'     => 'authoritative',
			'login_headerurl'            => 'authoritative',
			'rest_authentication_errors' => 'authoritative',
			'wp_headers'                 => 'additive',
			'user_has_cap'               => 'additive',
			'rest_endpoints'             => 'additive',
			'heartbeat_settings'         => 'additive',
		)
	);
}

/**
 * Resolve a registered callback to the plugin directory it lives in.
 *
 * Reflection is what makes this general: it answers "which file is this code
 * in" for any callable, so a plugin nobody has heard of is attributed exactly
 * like a known one. No list of rival plugins to maintain — such a list only
 * ever knows yesterday's plugins.
 *
 * @param mixed $callback Registered callback.
 * @return string Plugin directory name, or '' when it cannot be attributed.
 */
function keel_defaults_callback_plugin_dir( $callback ) {
	$file = '';

	try {
		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$reflection = new ReflectionFunction( $callback );
			$file       = (string) $reflection->getFileName();
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, (string) $callback[1] );
			$file       = (string) $reflection->getFileName();
		}
	} catch ( Throwable $e ) {
		return '';
	}

	if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
		return '';
	}

	$base = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	$file = wp_normalize_path( $file );

	if ( 0 !== strpos( $file, $base ) ) {
		return '';
	}

	$parts = explode( '/', substr( $file, strlen( $base ) ) );

	return isset( $parts[0] ) ? $parts[0] : '';
}

/**
 * Other plugins competing for the policies Keel sets.
 *
 * Only `authoritative` hooks are reported. On those, callbacks discard the
 * value they were handed, so WordPress keeps whichever ran last and the losing
 * plugin's settings screen goes on displaying a value the site does not use.
 * Themes, mu-plugins and core resolve to '' and are skipped: this reports
 * plugins competing to own a setting, which is what an admin can act on.
 *
 * @return array<string, string[]> Hook => plugin directory names.
 */
function keel_defaults_competing_plugins() {
	global $wp_filter;

	$self     = defined( 'KEEL_DEFAULTS_FILE' ) ? basename( dirname( KEEL_DEFAULTS_FILE ) ) : 'keel-defaults';
	$conflict = array();

	foreach ( keel_defaults_policy_hooks() as $hook => $kind ) {
		if ( 'authoritative' !== $kind || empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			continue;
		}

		$plugins = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				if ( ! isset( $registered['function'] ) ) {
					continue;
				}

				$dir = keel_defaults_callback_plugin_dir( $registered['function'] );

				if ( '' !== $dir && $dir !== $self ) {
					$plugins[ $dir ] = true;
				}
			}
		}

		if ( ! empty( $plugins ) ) {
			$conflict[ $hook ] = array_keys( $plugins );
		}
	}

	return $conflict;
}

/**
 * Style Keel's own section of Site Health → Info.
 *
 * Two columns is all `wp-admin/site-health-info.php` emits, and it passes both
 * cells through `esc_html`, so there is no markup lever here — the group name
 * cannot be marked up as a heading and cannot span rows. CSS is the only
 * mechanism, which is worth being explicit about rather than dressing up.
 *
 * What makes it safe rather than fragile is the scope. The id comes from our own
 * array key, so nothing here can reach another plugin's section or core's, and
 * if a future WordPress renames `.health-check-table` the rules stop matching
 * and the table renders in core's default style. Failure is a plain table, not a
 * broken one.
 *
 * `vertical-align` is the reason this exists. Each value cell holds a list of up
 * to nine defaults, and a middle-aligned row header floats to the centre of its
 * own list, level with nothing. Top alignment puts the group name beside the
 * first default under it.
 *
 * The weight is a partial departure, and the qualifier is the interesting half.
 * Core sets `.widefat th` to 400, but `.widefat.health-check-table th` to 600
 * inside `@media screen and (max-width: 782px)` — so below 782px every Info
 * section is already semibold and Keel simply matches, while above it Keel's
 * group names are the only bold ones on the page. Measured both ways rather than
 * read off the first rule that matched: the browser reported 600 for core's
 * section too, which was the narrow-viewport rule and nearly became a correction
 * in the wrong direction.
 *
 * Worth it because these are headings for the lists beside them rather than
 * field labels. It does mean the section stands out at desktop width, which is
 * where most people read it.
 */
function keel_defaults_site_health_info_styles() {
	printf(
		'<style id="keel-site-health-info">#health-check-accordion-block-%1$s .health-check-table th{font-weight:600;vertical-align:top}#health-check-accordion-block-%1$s .health-check-table td{vertical-align:top}</style>',
		esc_attr( KEEL_DEFAULTS_INFO_SECTION )
	);
}
