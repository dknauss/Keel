<?php
/**
 * Read-only audit of Keel's load-bearing registrations in this request.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closed scope shared by registration and inspection; not a general hook scanner.
 *
 * @return array Audit contracts.
 */
function keel_defaults_registration_contracts() {
	return array(
		'comment_feeds'    => array(
			'hook'      => 'template_redirect',
			'direction' => 'before',
		),
		'author_feeds'     => array(
			'hook'      => 'template_redirect',
			'direction' => 'before',
		),
		'author_archives'  => array(
			'hook'      => 'template_redirect',
			'direction' => 'before',
		),
		'attachment_pages' => array(
			'hook'      => 'template_redirect',
			'direction' => 'before',
		),
		'session_length'   => array(
			'hook'      => 'auth_cookie_expiration',
			'direction' => 'after',
		),
	);
}

/**
 * Capture bootstrap context, without reevaluating any policy or callback.
 *
 * @param bool   $start Start a new bootstrap baseline.
 * @param string $id Closed contract identifier.
 * @param mixed  $callback Actual callback reference, including anonymous closures.
 * @param int    $priority Declared priority.
 * @return array In-memory baseline. Never persisted.
 */
function keel_defaults_registration_baseline( $start = false, $id = '', $callback = null, $priority = 10 ) {
	static $baseline = array(
		'site'     => null,
		'expected' => array(),
	);
	if ( $start ) {
		$baseline = array(
			'site'     => get_current_blog_id(),
			'expected' => array(),
		);
	}
	if ( '' !== $id && null !== $baseline['site'] && isset( keel_defaults_registration_contracts()[ $id ] ) ) {
		$baseline['expected'][ $id ] = array(
			'callback' => $callback,
			'priority' => $priority,
		);
	}
	return $baseline;
}

/**
 * Record an already-added registration; do not execute it or infer a new one.
 *
 * @param string $id Contract identifier.
 * @param mixed  $callback Actual callback reference.
 * @param int    $priority Declared priority.
 * @return void
 */
function keel_defaults_record_registration( $id, $callback, $priority ) {
	keel_defaults_registration_baseline( false, $id, $callback, $priority );
}

/**
 * Preserve add_action's arguments while retaining the anonymous callback identity.
 *
 * @param string   $hook Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $accepted_args Accepted arguments.
 * @param string   $id Contract identifier.
 * @return void
 */
function keel_defaults_add_audited_action( $hook, $callback, $priority, $accepted_args, $id ) {
	add_action( $hook, $callback, $priority, $accepted_args );
	keel_defaults_record_registration( $id, $callback, $priority );
}

/**
 * Read at most 1,000 registration entries from one of the two audited hooks.
 *
 * @param string $hook Known hook name.
 * @return array|false Flattened priorities and callback identities, or unknown.
 */
function keel_defaults_registration_entries( $hook ) {
	global $wp_filter;
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return array();
	}
	if ( ! $wp_filter[ $hook ] instanceof WP_Hook || ! is_array( $wp_filter[ $hook ]->callbacks ) || count( $wp_filter[ $hook ]->callbacks ) > 1000 ) {
		return false;
	}
	$entries = array();
	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $bucket ) {
		if ( ! is_int( $priority ) || ! is_array( $bucket ) || count( $entries ) + count( $bucket ) > 1000 ) {
			return false;
		}
		foreach ( $bucket as $registration ) {
			if ( ! is_array( $registration ) || ! array_key_exists( 'function', $registration ) ) {
				return false;
			}
			$entries[] = array(
				'priority' => $priority,
				'callback' => $registration['function'],
			);
		}
	}
	return $entries;
}

/**
 * Inspect current registrations only. No callback execution, reflection or writes.
 *
 * @return array Coverage and a closed set of audit rows.
 */
function keel_defaults_registration_audit() {
	$baseline = keel_defaults_registration_baseline();
	$out      = array(
		'coverage' => 'unavailable',
		'rows'     => array(),
	);
	if ( null === $baseline['site'] ) {
		return $out;
	}
	if ( get_current_blog_id() !== $baseline['site'] ) {
		$out['coverage'] = 'context_mismatch';
		return $out;
	}
	$out['coverage'] = 'complete';
	$hooks           = array();
	foreach ( keel_defaults_registration_contracts() as $id => $contract ) {
		$row = array(
			'status'              => 'inactive',
			'declared_priority'   => null,
			'observed_priority'   => null,
			'comparator_priority' => null,
		);
		if ( ! isset( $baseline['expected'][ $id ] ) ) {
			$out['rows'][ $id ] = $row;
			continue;
		}
		$hook = $contract['hook'];
		if ( ! array_key_exists( $hook, $hooks ) ) {
			$hooks[ $hook ] = keel_defaults_registration_entries( $hook );
		}
		$expected                 = $baseline['expected'][ $id ];
		$row['declared_priority'] = $expected['priority'];
		if ( false === $hooks[ $hook ] ) {
			$row['status']   = 'inspection_limited';
			$out['coverage'] = 'partial';
		} else {
			$priorities = array();
			$core       = array();
			foreach ( $hooks[ $hook ] as $entry ) {
				if ( $entry['callback'] === $expected['callback'] ) {
					$priorities[] = $entry['priority'];
				}
				if ( 'redirect_canonical' === $entry['callback'] ) {
					$core[] = $entry['priority'];
				}
			}
			$before                     = 'before' === $contract['direction'];
			$comparator                 = $before ? ( empty( $core ) ? null : min( $core ) ) : 10;
			$row['comparator_priority'] = $comparator;
			if ( empty( $priorities ) ) {
				$row['status'] = 'missing';
			} else {
				$actual                   = $before ? min( $priorities ) : max( $priorities );
				$row['observed_priority'] = $actual;
				$row['status']            = null === $comparator ? 'registered_no_comparator' : ( ( $before ? $actual < $comparator : $actual > $comparator ) ? 'registered' : 'precedence_lost' );
			}
		}
		$out['rows'][ $id ] = $row;
	}
	return $out;
}

/**
 * Authorized Site Health presentation of this request's bounded inspection.
 *
 * @return array Site Health result.
 */
function keel_defaults_registration_health() {
	$result = array(
		'label'       => __( 'Keel registration audit', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_registration_audit',
	);
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		return $result;
	}
	$audit                 = keel_defaults_registration_audit();
	$result['description'] = '<p>' . esc_html__( 'This audit inspects four redirect registrations and the session-length clamp declared during this request’s bootstrap. It executes no callbacks and makes no repairs. It does not prove runtime outcomes or coverage of other hooks, routes or requests.', 'keel-defaults' ) . '</p>';
	if ( in_array( $audit['coverage'], array( 'unavailable', 'context_mismatch' ), true ) ) {
		$result['status']       = 'recommended';
		$result['description'] .= '<p>' . esc_html__( 'Registration coverage is unavailable: bootstrap has not supplied a baseline, or the current site differs from the site whose callbacks were registered. Check Site Health in a fresh request for this site.', 'keel-defaults' ) . '</p>';
		return $result;
	}
	$labels                 = array(
		'comment_feeds'    => __( 'Comment feeds', 'keel-defaults' ),
		'author_feeds'     => __( 'Author feeds', 'keel-defaults' ),
		'author_archives'  => __( 'Author archives', 'keel-defaults' ),
		'attachment_pages' => __( 'Attachment pages', 'keel-defaults' ),
		'session_length'   => __( 'Session length', 'keel-defaults' ),
	);
	$states                 = array(
		'inactive'                 => __( 'Not registered by Keel in this bootstrap (policy inactive).', 'keel-defaults' ),
		'registered'               => __( 'Registered with the declared precedence.', 'keel-defaults' ),
		'registered_no_comparator' => __( 'Registered; core’s canonical redirect comparator is absent in this request.', 'keel-defaults' ),
		'missing'                  => __( 'Expected registration is missing.', 'keel-defaults' ),
		'precedence_lost'          => __( 'The current priority loses the declared precedence.', 'keel-defaults' ),
		'inspection_limited'       => __( 'Unknown: the hook registry is malformed or exceeds the 1,000-entry inspection limit.', 'keel-defaults' ),
	);
	$result['description'] .= '<ul>';
	foreach ( $audit['rows'] as $id => $row ) {
		if ( in_array( $row['status'], array( 'missing', 'precedence_lost', 'inspection_limited' ), true ) ) {
			$result['status'] = 'recommended';
		}
		$result['description'] .= '<li>' . esc_html( $labels[ $id ] ) . ': ' . esc_html( $states[ $row['status'] ] );
		if ( null !== $row['observed_priority'] ) {
			$result['description'] .= ' ' . sprintf( /* translators: %d: current hook priority. */ esc_html__( 'Observed priority: %d.', 'keel-defaults' ), $row['observed_priority'] );
		}
		$result['description'] .= '</li>';
	}
	$result['description'] .= '</ul><p>' . esc_html__( 'Redirects are compared with the earliest registered core canonical redirect; the session clamp must follow default priority 10. Later callbacks can still change outcomes. This is current-request evidence, not a persistent or network-wide verdict. Investigate intentional overrides before changing code; the audit does not identify who changed a registration.', 'keel-defaults' ) . '</p>';
	return $result;
}
