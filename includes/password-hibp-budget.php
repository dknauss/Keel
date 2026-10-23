<?php
/**
 * Bounded HIBP admission, fixed range slots and sampled shared evidence.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/password-hibp-store.php';

/**
 * Validated operator policy.
 *
 * @return array{capacity:int,interval:int}
 */
function keel_hibp_budget_policy() {
	$default = array(
		'capacity' => 10,
		'interval' => 60,
	);
	$policy  = apply_filters( 'keel_hibp_budget_policy', $default );
	return is_array( $policy ) && count( $policy ) === 2 && isset( $policy['capacity'], $policy['interval'] )
		&& is_int( $policy['capacity'] ) && $policy['capacity'] >= 1 && $policy['capacity'] <= 100
		&& is_int( $policy['interval'] ) && $policy['interval'] >= 1 && $policy['interval'] <= 3600 ? $policy : $default;
}

/**
 * Initial authoritative state, only for an absent row.
 *
 * @param array $policy Validated budget policy.
 * @param int   $now Current Unix timestamp.
 * @return array
 */
function keel_hibp_budget_initial( array $policy, $now ) {
	return array(
		'v'       => 2,
		'rev'     => 0,
		'policy'  => $policy,
		'credit'  => $policy['capacity'] * $policy['interval'],
		'last'    => $now,
		'leases'  => array(),
		'sample'  => null,
		'success' => null,
	);
}

/**
 * Bounded nonnegative time with room for expiry arithmetic.
 *
 * @param mixed $value Candidate timestamp.
 * @return bool
 */
function keel_hibp_budget_time( $value ) {
	return is_int( $value ) && $value >= 0 && $value <= PHP_INT_MAX - 43500;
}

/**
 * Diagnostic reason is bounded and contains no request data.
 *
 * @param string $kind Bounded failure reason.
 * @return bool
 */
function keel_hibp_budget_kind( $kind ) {
	return is_string( $kind ) && ( in_array( $kind, array( 'budget_exhausted', 'lookup_busy', 'budget_unavailable', 'unreachable', 'truncated', 'malformed' ), true ) || preg_match( '/\Ahttp_[1-5][0-9]{2}\z/D', $kind ) );
}

/**
 * Strict validation, never a reset on malformed authority.
 *
 * @param string|null|false $raw Bounded raw database value.
 * @param int               $now Current Unix timestamp.
 * @return array|false
 */
function keel_hibp_budget_state( $raw, $now ) {
	if ( ! is_string( $raw ) || strlen( $raw ) > 4096 || ! keel_hibp_budget_time( $now ) ) {
		return false;
	}
	$s = json_decode( $raw, true );
	if ( ! is_array( $s ) || count( $s ) !== 8 || ! isset( $s['v'], $s['rev'], $s['policy'], $s['credit'], $s['last'], $s['leases'] ) || ! array_key_exists( 'sample', $s ) || ! array_key_exists( 'success', $s ) || 2 !== $s['v']
		|| ! is_int( $s['rev'] ) || $s['rev'] < 0 || $s['rev'] >= PHP_INT_MAX || ! is_array( $s['policy'] ) || count( $s['policy'] ) !== 2 || ! isset( $s['policy']['capacity'], $s['policy']['interval'] )
		|| ! is_int( $s['policy']['capacity'] ) || $s['policy']['capacity'] < 1 || $s['policy']['capacity'] > 100 || ! is_int( $s['policy']['interval'] ) || $s['policy']['interval'] < 1 || $s['policy']['interval'] > 3600
		|| ! is_int( $s['credit'] ) || $s['credit'] < 0 || $s['credit'] > $s['policy']['capacity'] * $s['policy']['interval'] || ! keel_hibp_budget_time( $s['last'] ) || $s['last'] > $now + 300 || ! is_array( $s['leases'] ) || count( $s['leases'] ) > 2 ) {
		return false;
	}
	if ( array_values( $s['leases'] ) !== $s['leases'] ) {
		return false;
	}
	$slots  = array();
	$owners = array();
	foreach ( $s['leases'] as $lease ) {
		if ( ! is_array( $lease ) || count( $lease ) !== 3 || ! isset( $lease['slot'], $lease['owner'], $lease['until'] ) || ! is_int( $lease['slot'] ) || $lease['slot'] < 0 || $lease['slot'] > 63 || ! is_string( $lease['owner'] ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $lease['owner'] ) || ! keel_hibp_budget_time( $lease['until'] ) || $lease['until'] > $s['last'] + 30 || isset( $slots[ $lease['slot'] ] ) || isset( $owners[ $lease['owner'] ] ) ) {
			return false;
		}
		$slots[ $lease['slot'] ]   = true;
		$owners[ $lease['owner'] ] = true;
	}
	if ( null !== $s['success'] && ( ! keel_hibp_budget_time( $s['success'] ) || $s['success'] > $now ) ) {
		return false;
	}
	if ( null !== $s['sample'] && ( ! is_array( $s['sample'] ) || count( $s['sample'] ) !== 3 || ! isset( $s['sample']['kind'], $s['sample']['first'], $s['sample']['seen'] ) || ! keel_hibp_budget_kind( $s['sample']['kind'] ) || ! keel_hibp_budget_time( $s['sample']['first'] ) || ! keel_hibp_budget_time( $s['sample']['seen'] ) || $s['sample']['first'] > $s['sample']['seen'] || $s['sample']['seen'] > $now ) ) {
		return false;
	}
	return $s;
}

/**
 * Refill and policy conversion without granting another burst.
 *
 * @param array $state Validated control state.
 * @param array $policy Validated budget policy.
 * @param int   $now Current Unix timestamp.
 * @return array|false
 */
function keel_hibp_budget_refill( array $state, array $policy, $now ) {
	if ( false === keel_hibp_budget_state( wp_json_encode( $state ), $now ) ) {
		return false;
	}
	$cap             = $state['policy']['capacity'] * $state['policy']['interval'];
	$state['credit'] = min( $cap, $state['credit'] + min( $cap, max( 0, $now - $state['last'] ) ) );
	$state['last']   = max( $now, $state['last'] );
	if ( $state['policy'] !== $policy ) {
		$state['credit'] = $state['policy']['interval'] === $policy['interval']
			? min( $policy['capacity'] * $policy['interval'], $state['credit'] )
			: min( $policy['capacity'], (int) floor( $state['credit'] / $state['policy']['interval'] ) ) * $policy['interval'];
		$state['policy'] = $policy;
	}
	$state['leases'] = array_values(
		array_filter(
			$state['leases'],
			static function ( $lease ) use ( $now ) {
				return $lease['until'] > $now;
			}
		)
	);
	return $state;
}

/**
 * Direct-mapped slot, fixed independently of attacker cardinality.
 *
 * @param string $prefix Five-character SHA-1 prefix.
 * @return int
 */
function keel_hibp_budget_slot( $prefix ) {
	return hexdec( substr( $prefix, 0, 2 ) ) % 64;
}

/**
 * Decode a bounded slot. Corrupt slots are misses.
 *
 * @param string|null|false $raw Bounded raw database value.
 * @return array|false
 */
function keel_hibp_budget_slot_data( $raw ) {
	if ( ! is_string( $raw ) || strlen( $raw ) > 132096 ) {
		return false;
	}
	$s = json_decode( $raw, true );
	return is_array( $s ) && count( $s ) === 3 && isset( $s['v'], $s['owner'] ) && array_key_exists( 'data', $s ) && 2 === $s['v'] && is_string( $s['owner'] ) && preg_match( '/\A[a-f0-9]{32}\z/D', $s['owner'] ) ? $s : false;
}

/**
 * Valid cache hits never open or mutate the bucket.
 *
 * @param array  $scope Canonical database scope.
 * @param string $prefix Five-character SHA-1 prefix.
 * @param int    $now Current Unix timestamp.
 * @return string|false
 */
function keel_hibp_budget_cache( array $scope, $prefix, $now ) {
	$slot = keel_hibp_budget_slot_data( keel_hibp_budget_read( $scope, keel_hibp_budget_key( $scope, sprintf( 'slot_%02d', keel_hibp_budget_slot( $prefix ) ) ), 132096 ) );
	$d    = $slot ? $slot['data'] : null;
	return is_array( $d ) && count( $d ) === 4 && isset( $d['prefix'], $d['body'], $d['received'], $d['expires'] ) && $prefix === $d['prefix'] && is_string( $d['body'] ) && strlen( $d['body'] ) <= 131072 && keel_hibp_budget_time( $d['received'] ) && keel_hibp_budget_time( $d['expires'] ) && $d['received'] <= $now && $d['expires'] === $d['received'] + 43200 && $d['expires'] > $now && keel_hibp_body_is_valid( $d['body'] ) ? $d['body'] : false;
}

/**
 * Cached body, admission ticket, or bounded unknown reason.
 *
 * @param array  $scope Canonical database scope.
 * @param string $prefix Five-character SHA-1 prefix.
 * @return array
 */
function keel_hibp_budget_admit( array $scope, $prefix ) {
	if ( ! is_string( $prefix ) || ! preg_match( '/\A[A-F0-9]{5}\z/D', $prefix ) ) {
		return array( 'error' => 'budget_unavailable' );
	}
	$key    = keel_hibp_budget_key( $scope, 'state' );
	$slot   = keel_hibp_budget_slot( $prefix );
	$policy = keel_hibp_budget_policy();
	for ( $attempt = 0; $attempt < 3; ++$attempt ) {
		$now  = time();
		$body = keel_hibp_budget_cache( $scope, $prefix, $now );
		if ( false !== $body ) {
			return array( 'body' => $body );
		}
		$ready = false;
		$raw   = keel_hibp_budget_read( $scope, $key );
		if ( null === $raw ) {
			if ( ! keel_hibp_budget_connection_idle() || ! keel_hibp_budget_transactional( $scope ) ) {
				break; }
			$ready = true;
			keel_hibp_budget_write( $scope, $key, null, keel_hibp_budget_initial( $policy, $now ) );
			$raw = keel_hibp_budget_read( $scope, $key );
		}
		$state = keel_hibp_budget_state( $raw, $now );
		$state = $state ? keel_hibp_budget_refill( $state, $policy, $now ) : false;
		if ( ! $state || $state['rev'] > PHP_INT_MAX - 3 ) {
			break;
		}
		foreach ( $state['leases'] as $lease ) {
			if ( $slot === $lease['slot'] ) {
				return array( 'error' => 'lookup_busy' );
			}
		}
		if ( count( $state['leases'] ) >= 2 ) {
			return array( 'error' => 'lookup_busy' );
		}
		if ( $state['credit'] < $policy['interval'] ) {
			return array( 'error' => 'budget_exhausted' );
		}
		// A denied read needs no connection probe; check immediately before mutation.
		if ( ! $ready && ( ! keel_hibp_budget_connection_idle() || ! keel_hibp_budget_transactional( $scope ) ) ) {
			break; }
		$lease             = array(
			'slot'  => $slot,
			'owner' => str_replace( '-', '', wp_generate_uuid4() ),
			'until' => $now + 30,
		);
		$state['leases'][] = $lease;
		$state['credit']  -= $policy['interval'];
		++$state['rev'];
		if ( ! keel_hibp_budget_write( $scope, $key, $raw, $state ) ) {
			continue;
		}
		$ticket           = $lease;
		$ticket['scope']  = $scope;
		$ticket['prefix'] = $prefix;
		if ( keel_hibp_budget_claim( $ticket ) ) {
			return array( 'ticket' => $ticket );
		}
		keel_hibp_budget_finish( $ticket, null, 'budget_unavailable' );
		break;
	}
	return array( 'error' => 'budget_unavailable' );
}

/**
 * Claim a slot while fencing reservations in a short transaction.
 *
 * @param array $ticket Admission ownership token.
 * @return bool
 */
function keel_hibp_budget_claim( array $ticket ) {
	$scope    = $ticket['scope'];
	$key      = keel_hibp_budget_key( $scope, 'state' );
	$slot_key = keel_hibp_budget_key( $scope, sprintf( 'slot_%02d', $ticket['slot'] ) );
	for ( $i = 0; $i < 3; ++$i ) {
		$owned = keel_hibp_budget_owned( $ticket );
		if ( ! $owned || $owned['state']['rev'] > PHP_INT_MAX - 3 || ! keel_hibp_budget_transaction( 'START TRANSACTION' ) ) {
			return false;
		}
		$state = $owned['state'];
		++$state['rev'];
		if ( ! keel_hibp_budget_write( $scope, $key, $owned['raw'], $state ) ) {
			keel_hibp_budget_transaction( 'ROLLBACK' );
			continue;
		}
		$old  = keel_hibp_budget_read( $scope, $slot_key, 132096 );
		$data = keel_hibp_budget_slot_data( $old );
		if ( false === $old || $ticket['until'] <= time() || ! keel_hibp_budget_write(
			$scope,
			$slot_key,
			$old,
			array(
				'v'     => 2,
				'owner' => $ticket['owner'],
				'data'  => $data ? $data['data'] : null,
			),
			132096
		) || $ticket['until'] <= time() || ! keel_hibp_budget_transaction( 'COMMIT' ) ) {
			keel_hibp_budget_transaction( 'ROLLBACK' );
			return false;
		}
		return true;
	}
	return false;
}

/**
 * Current unexpired lease plus its exact control snapshot.
 *
 * @param array $ticket Admission ownership token.
 * @return array|false
 */
function keel_hibp_budget_owned( array $ticket ) {
	$raw   = keel_hibp_budget_read( $ticket['scope'], keel_hibp_budget_key( $ticket['scope'], 'state' ) );
	$state = keel_hibp_budget_state( $raw, time() );
	if ( $state ) {
		foreach ( $state['leases'] as $lease ) {
			if ( $lease['slot'] === $ticket['slot'] && $lease['owner'] === $ticket['owner'] && $lease['until'] > time() ) {
				return array(
					'raw'   => $raw,
					'state' => $state,
				);
			}
		}
	}
	return false;
}

/**
 * Sample at most once/5min, regardless of changing reasons.
 *
 * @param array  $state Validated control state.
 * @param string $kind Bounded failure reason.
 * @param int    $now Current Unix timestamp.
 * @return array
 */
function keel_hibp_budget_sample( array $state, $kind, $now ) {
	if ( keel_hibp_budget_kind( $kind ) && ( null === $state['sample'] || $now - $state['sample']['seen'] >= 300 ) ) {
		$state['sample'] = array(
			'kind'  => $kind,
			'first' => $now,
			'seen'  => $now,
		);
	}
	return $state;
}

/**
 * Fenced short transaction: never hold a DB lock across HTTP.
 *
 * @param array       $ticket Admission ownership token.
 * @param string|null $body Validated live range or null on failure.
 * @param string      $kind Bounded failure reason.
 * @return bool
 */
function keel_hibp_budget_finish( array $ticket, $body = null, $kind = '' ) {
	if ( null !== $body && ( ! is_string( $body ) || ! keel_hibp_body_is_valid( $body ) ) ) {
		return false;
	}
	$scope = $ticket['scope'];
	$key   = keel_hibp_budget_key( $scope, 'state' );
	for ( $attempt = 0; $attempt < 3; ++$attempt ) {
		$owned = keel_hibp_budget_owned( $ticket );
		if ( ! $owned || $owned['state']['rev'] > PHP_INT_MAX - 3 || ! keel_hibp_budget_transaction( 'START TRANSACTION' ) ) {
			return false;
		}
		$state = $owned['state'];
		++$state['rev'];
		// Lock the control row by CAS, fencing both new reservations and slot publication.
		if ( ! keel_hibp_budget_write( $scope, $key, $owned['raw'], $state ) ) {
			keel_hibp_budget_transaction( 'ROLLBACK' );
			continue;
		}
		if ( $ticket['until'] <= time() ) {
			keel_hibp_budget_transaction( 'ROLLBACK' );
			return false;
		}
		if ( null !== $body && strlen( $body ) <= 131072 ) {
			$slot_key = keel_hibp_budget_key( $scope, sprintf( 'slot_%02d', $ticket['slot'] ) );
			$old      = keel_hibp_budget_read( $scope, $slot_key, 132096 );
			$slot     = keel_hibp_budget_slot_data( $old );
			if ( ! $slot || $slot['owner'] !== $ticket['owner'] ) {
				keel_hibp_budget_transaction( 'ROLLBACK' );
				return false;
			}
			$now          = time();
			$slot['data'] = array(
				'prefix'   => $ticket['prefix'],
				'body'     => $body,
				'received' => $now,
				'expires'  => $now + 43200,
			);
			// Retention failure cannot turn a validated live verdict into clean or corrupt ownership.
			keel_hibp_budget_write( $scope, $slot_key, $old, $slot, 132096 );
		}
		$state['leases'] = array_values(
			array_filter(
				$state['leases'],
				static function ( $lease ) use ( $ticket ) {
					return $lease['owner'] !== $ticket['owner'];
				}
			)
		);
		if ( null !== $body ) {
			$state['success'] = time();
			// Preserve sampling cadence across successes and changing failure reasons.
		} else {
			$state = keel_hibp_budget_sample( $state, $kind, time() );
		}
		$lock_raw = keel_hibp_budget_read( $scope, $key );
		++$state['rev'];
		if ( ! keel_hibp_budget_write( $scope, $key, $lock_raw, $state ) || $ticket['until'] <= time() || ! keel_hibp_budget_transaction( 'COMMIT' ) ) {
			keel_hibp_budget_transaction( 'ROLLBACK' );
			return false;
		}
		return true;
	}
	return false;
}

/**
 * Sample denial without creating authority or per-attempt writes.
 *
 * @param string $kind Bounded failure reason.
 * @return void
 */
function keel_hibp_budget_note( $kind ) {
	$scope = keel_hibp_budget_scope();
	if ( ! $scope || ! keel_hibp_budget_kind( $kind ) ) {
		return;
	}
	$key = keel_hibp_budget_key( $scope, 'state' );
	for ( $i = 0; $i < 3; ++$i ) {
		$raw   = keel_hibp_budget_read( $scope, $key );
		$state = keel_hibp_budget_state( $raw, time() );
		if ( ! $state || $state['rev'] > PHP_INT_MAX - 3 ) {
			return;
		}
		$new = keel_hibp_budget_sample( $state, $kind, time() );
		if ( $new === $state || ! keel_hibp_budget_connection_idle() ) {
			return;
		}
		++$new['rev'];
		if ( keel_hibp_budget_write( $scope, $key, $raw, $new ) ) {
			return;
		}
	}
}

/**
 * Passive shared transport evidence; never initializes or refills.
 *
 * @return array
 */
function keel_hibp_budget_evidence() {
	$empty = array(
		'available' => false,
		'failure'   => array(
			'kind'  => 'budget_unavailable',
			'first' => 0,
			'seen'  => 0,
		),
		'success'   => null,
	);
	$scope = keel_hibp_budget_scope();
	if ( ! $scope ) {
		return $empty;
	}
	$raw = keel_hibp_budget_read( $scope, keel_hibp_budget_key( $scope, 'state' ) );
	if ( null === $raw ) {
		return array(
			'available' => true,
			'failure'   => null,
			'success'   => null,
		);
	}
	$state = keel_hibp_budget_state( $raw, time() );
	if ( ! $state ) {
		return $empty;
	}
	return array(
		'available' => true,
		'failure'   => null !== $state['sample'] && time() - $state['sample']['seen'] < 3600 ? $state['sample'] : null,
		'success'   => $state['success'],
	);
}
