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
