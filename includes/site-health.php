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
		$key = (string) (int) $value;
		if ( isset( $s['states'][ $key ] ) ) {
			return (string) $s['states'][ $key ];
		}

		$number   = (int) $value;
		$singular = isset( $s['unit_singular'] ) ? (string) $s['unit_singular'] : ( isset( $s['unit'] ) ? (string) $s['unit'] : '' );
		$plural   = isset( $s['unit'] ) ? (string) $s['unit'] : $singular;
		$unit     = 1 === $number ? $singular : $plural;

		return trim( number_format_i18n( $number ) . ' ' . $unit );
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
		'description' => sprintf(
			/* translators: %s: link to the Keel settings screen. */
			__( 'Every default Keel manages and its current state on this site. Read-only — change them under %s.', 'keel-defaults' ),
			keel_defaults_setting_link( '', __( 'Settings → Keel', 'keel-defaults' ) )
		),
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

	$description = '<p>' . sprintf(
		/* translators: %s: link to the Keel settings screen. */
		esc_html__( 'Current state of the defaults Keel manages on this site — a read-only summary of your choices under %s, not a warning.', 'keel-defaults' ),
		keel_defaults_setting_link( '', __( 'Settings → Keel', 'keel-defaults' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by keel_defaults_setting_link(), which escapes both halves.
	) . '</p>';

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
 * One <li> per contested hook, naming what is contesting it.
 *
 * Extracted only because the report emits the same list twice under different
 * headings, and a second copy is how the two would drift apart.
 *
 * @param array<string, string[]> $conflicts Hook => plugin directory names.
 * @return string
 */
function keel_defaults_conflict_list( $conflicts ) {
	$out = '';

	foreach ( $conflicts as $hook => $hook_plugins ) {
		$out .= '<li><code>' . esc_html( $hook ) . '</code> — ' . sprintf(
			/* translators: %s: comma-separated plugin directory names. */
			esc_html__( 'callbacks from %s', 'keel-defaults' ),
			esc_html( implode( ', ', $hook_plugins ) )
		) . '</li>';
	}

	return $out;
}

/**
 * Report other plugins competing for the same settings.
 *
 * Two plugins registered on the same policy hook do not error or log. WordPress
 * composes both callbacks in priority order, so their settings may agree or may
 * disagree. Registration proves only that both participate in the decision.
 *
 * The result names what is contesting what and deliberately does not say which
 * plugin to keep. That is a judgement about what the site needs, and a check
 * answering it would be Keel arguing for its own retention.
 *
 * @return array
 */
function keel_defaults_site_health_conflicts() {
	$report   = keel_defaults_policy_overlap_report();
	$overlaps = $report['structural'];
	$badge    = array(
		'label' => __( 'Keel', 'keel-defaults' ),
		'color' => 'blue',
	);
	$intro    = '<p>' . esc_html__( 'WordPress runs every callback on a filter in priority order and uses the final value. Keel reports when another attributable plugin is registered on an authoritative policy hook that Keel also uses. This confirms an overlap, not that the plugins produce different outcomes.', 'keel-defaults' ) . '</p>';

	/*
	 * The other half, and the one that answers "so is my setting working?".
	 *
	 * Where a plugin overrides a setting through one of WordPress's own helpers
	 * there is nobody to name — nothing records which plugin called add_filter().
	 * Keel can still see that its setting is not producing the value it asks
	 * for, because its own callbacks run when WordPress runs the filter. This is
	 * read from those observations; nothing is invoked to produce it.
	 */
	$divergences = keel_defaults_policy_divergences();

	if ( ! empty( $divergences ) ) {
		$intro .= '<p><strong>' . sprintf(
			/* translators: %s: comma-separated list of filter names. */
			esc_html__( 'Not taking effect: %s', 'keel-defaults' ),
			esc_html( implode( ', ', array_keys( $divergences ) ) )
		) . '</strong></p><p>' . esc_html__( 'These settings were last seen producing a different value from the one configured here, so something else on this site is deciding them. Keel cannot say what: a plugin that turns a feature off using one of WordPress\'s own helper functions leaves nothing to trace it back by. Your active plugins are the place to look.', 'keel-defaults' ) . '</p>';
	}
	$details = '';

	if ( ! empty( $report['unconfirmed'] ) ) {
		$details .= '<p><strong>' . esc_html__( 'Unconfirmed overlaps', 'keel-defaults' ) . '</strong> ' . esc_html__( 'These callbacks share a compositional hook or cannot be attributed. This is informational only; it is not a reason to deactivate anything.', 'keel-defaults' ) . '</p><ul>';
		$details .= keel_defaults_conflict_list( $report['unconfirmed'] ) . '</ul>';
	}

	if ( empty( $overlaps ) ) {
		/*
		 * Nothing to name is not the same as nothing wrong.
		 *
		 * The status was decided by the attributable overlaps alone, so a site
		 * where the only finding was a setting not taking effect got "good" and
		 * "No attributable policy overlap was found" printed above a paragraph
		 * saying something else was deciding that setting. A green badge over
		 * those words is the one combination guaranteed not to be read — and
		 * the untraceable override is exactly the case with no overlap to
		 * report, so the two conditions coincide rather than being rare
		 * together.
		 */
		if ( ! empty( $divergences ) ) {
			return array(
				'label'       => __( 'A setting is not taking effect', 'keel-defaults' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => $intro . $details,
				'test'        => 'keel_defaults_conflicts',
			);
		}

		return array(
			'label'       => __( 'No attributable policy overlap was found', 'keel-defaults' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => $intro . $details,
			'test'        => 'keel_defaults_conflicts',
		);
	}

	$plugins = array();
	foreach ( $overlaps as $hook_plugins ) {
		foreach ( $hook_plugins as $plugin ) {
			$plugins[ $plugin ] = true;
		}
	}

	$description = $intro . '<p><strong>' . sprintf(
		/* translators: %s: comma-separated plugin directory names. */
		esc_html__( 'Also registered on these policy hooks: %s', 'keel-defaults' ),
		esc_html( implode( ', ', array_keys( $plugins ) ) )
	) . '</strong></p><p>' . esc_html__( 'Review the corresponding settings in both plugins. Registration on the same hook does not prove that their configured outcomes disagree, so do not deactivate either plugin based on this report alone.', 'keel-defaults' ) . '</p><ul>';
	$description .= keel_defaults_conflict_list( $overlaps ) . '</ul>' . $details;

	return array(
		'label'       => __( 'More than one plugin may influence the same settings', 'keel-defaults' ),
		'status'      => 'recommended',
		'badge'       => $badge,
		'description' => $description,
		'test'        => 'keel_defaults_conflicts',
	);
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
 *
 * @return string
 */
function keel_defaults_site_health_info_css() {
	return sprintf(
		'#health-check-accordion-block-%1$s .health-check-table th{font-weight:600;vertical-align:top}#health-check-accordion-block-%1$s .health-check-table td{vertical-align:top}',
		sanitize_html_class( KEEL_DEFAULTS_INFO_SECTION )
	);
}
