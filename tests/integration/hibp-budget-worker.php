<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Disposable real-WordPress DB/worker harness, trusted allowlisted table, local fixtures and files.
/** Independent real-WordPress worker; CI disposable databases only. @package Keel */
if ( ( 'true' !== getenv( 'GITHUB_ACTIONS' ) && '1' !== getenv( 'KEEL_HIBP_LAB' ) ) || empty( $argv[1] ) ) {
	fwrite( STDERR, "HIBP lab workers require GitHub Actions and a job file.\n" );
	exit( 2 );
}
$job = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $job ) || ! is_file( $job['wp'] . '/.hibp-budget-lab' ) || ! is_dir( $job['work'] ) ) {
	exit( 2 );
}
class Keel_HIBP_Lab_Exception extends RuntimeException {}
$metrics = array(
	'queries'      => 0,
	'store_reads'  => 0,
	'store_writes' => 0,
	'http'         => 0,
	'begins'       => 0,
	'commits'      => 0,
	'rollbacks'    => 0,
);
$phase   = array(
	'transaction'   => false,
	'control_write' => false,
	'reserved'      => false,
	'http_done'     => false,
	'paused'        => false,
);
function hibp_lab_json( $path, $data ) {
	file_put_contents( $path . '.tmp', json_encode( $data ) );
	rename( $path . '.tmp', $path );
}
function hibp_lab_wait( $path ) {
	$end = microtime( true ) + 25;
	while ( ! is_file( $path ) ) {
		if ( microtime( true ) > $end ) {
			throw new Keel_HIBP_Lab_Exception( 'barrier timeout' );
		}
		usleep( 5000 );
	}
}
function hibp_lab_pause( $name ) {
	global $job, $phase;
	if ( ( $job['pause'] ?? '' ) !== $name || $phase['paused'] ) {
		return;
	}
	$phase['paused'] = true;
	hibp_lab_json( $job['work'] . '/paused.json', array( 'phase' => $name ) );
	hibp_lab_wait( $job['work'] . '/resume' );
}
function hibp_lab_query( $sql ) {
	global $metrics, $phase;
	++$metrics['queries'];
	$store = false !== strpos( $sql, 'keel_hibp_v2_' );
	$write = (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql );
	if ( preg_match( '/^\s*(START TRANSACTION|BEGIN)\b/i', $sql ) ) {
		++$metrics['begins'];
		$phase['transaction']   = true;
		$phase['control_write'] = false;
	} elseif ( preg_match( '/^\s*(COMMIT|ROLLBACK)\b/i', $sql, $match ) ) {
		$transaction_metric = 'COMMIT' === strtoupper( $match[1] ) ? 'commits' : 'rollbacks';
		++$metrics[ $transaction_metric ];
		$phase['transaction'] = false;
	}
	if ( $store ) {
		++$metrics[ $write ? 'store_writes' : 'store_reads' ];
		if ( $phase['http_done'] ) {
			hibp_lab_pause( 'after_http' );
		}
		if ( $phase['reserved'] && ! $phase['transaction'] && ! $write && false !== strpos( $sql, '_state' ) ) {
			hibp_lab_pause( 'after_reservation' );
		}
		if ( $phase['transaction'] && $phase['control_write'] && $write && false !== strpos( $sql, '_slot_' ) ) {
			hibp_lab_pause( 'publisher_locked' );
		}
		if ( $write && false !== strpos( $sql, '_state' ) ) {
			$phase['reserved'] = true;
			if ( $phase['transaction'] ) {
				$phase['control_write'] = true;
			}
		}
	}
	return $sql;
}
function hibp_lab_http( $pre, $args, $url ) {
	global $job, $metrics, $phase;
	if ( 'api.pwnedpasswords.com' !== parse_url( $url, PHP_URL_HOST ) ) {
		return new WP_Error( 'hibp_lab_external_blocked' );
	}
	++$metrics['http'];
	hibp_lab_pause( 'before_http' );
	if ( 'error' === ( $job['http'] ?? '' ) ) {
		$phase['http_done'] = true;
		return new WP_Error( 'hibp_lab_synthetic_failure' );
	}
	$hash               = strtoupper( sha1( $job['password'] ?? 'synthetic-hibp-lab-value' ) );
	$body               = substr( $hash, 5 ) . ":3\r\n" . str_repeat( '0', 35 ) . ':0';
	$phase['http_done'] = true;
	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'Synthetic lab fixture',
		),
		'cookies'  => array(),
	);
}
// Preinitialize hooks so even WP/plugin bootstrap HTTP cannot reach a real API.
$GLOBALS['wp_filter']['query'][10][]                       = array(
	'function'      => 'hibp_lab_query',
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['pre_http_request'][ PHP_INT_MAX ][] = array(
	'function'      => 'hibp_lab_http',
	'accepted_args' => 3,
);
$lab_url                    = parse_url( $job['url'] ?? 'http://hibp-budget.test/' );
$_SERVER['HTTP_HOST']       = $lab_url['host'] . ( isset( $lab_url['port'] ) ? ':' . $lab_url['port'] : '' );
$lab_path                   = $lab_url['path'] ?? '/';
$_SERVER['REQUEST_URI']     = is_string( $lab_path ) && '' !== $lab_path ? $lab_path : '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
define( 'WP_USE_THEMES', false );
ob_start();
try {
	require $job['wp'] . '/wp-load.php';
	if ( ! defined( 'KEEL_HIBP_BUDGET_LAB' ) || true !== KEEL_HIBP_BUDGET_LAB || ! function_exists( 'keel_hibp_budget_admit' ) ) {
		throw new Keel_HIBP_Lab_Exception( 'lab marker or runtime API missing' );
	}
	$bootstrap = $metrics;
	$scope     = keel_hibp_budget_scope();
	if ( ! $scope || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $scope['table'] ) ) {
		throw new Keel_HIBP_Lab_Exception( 'canonical scope unavailable' );
	}
	$state_key = 'keel_hibp_v2_' . $scope['id'] . '_state';
	function hibp_lab_seed( $credit = null ) {
		global $scope, $state_key;
		$state = keel_hibp_budget_initial( keel_hibp_budget_policy(), time() );
		if ( null !== $credit ) {
			$state['credit'] = $credit;
		}
		$old = keel_hibp_budget_read( $scope, $state_key );
		if ( ! keel_hibp_budget_write( $scope, $state_key, $old, $state ) ) {
			throw new Keel_HIBP_Lab_Exception( 'fixture seed CAS failed' );
		}
	}
	function hibp_lab_rows() {
		global $scope, $wpdb;
		$prefix = 'keel_hibp_v2_' . $scope['id'] . '_';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM `{$scope['table']}` WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
		$out    = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'key'      => substr( $row['option_name'], strlen( $prefix ) ),
				'bytes'    => strlen( $row['option_value'] ),
				'hash'     => hash( 'sha256', $row['option_value'] ),
				'autoload' => $row['autoload'],
			);
		}
		return $out;
	}
	function hibp_lab_assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new Keel_HIBP_Lab_Exception( $message );
		}
	}
	if ( isset( $job['barrier'] ) ) {
		touch( $job['work'] . '/ready' );
		hibp_lab_wait( $job['barrier'] );
	}
	$start_metrics = $metrics;
	$start         = microtime( true );
	$result        = array();
	switch ( $job['op'] ) {
		case 'reset':
			for ( $i = -1; $i < 64; ++$i ) {
				$key = 'keel_hibp_v2_' . $scope['id'] . '_' . ( -1 === $i ? 'state' : sprintf( 'slot_%02d', $i ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$scope['table']}` WHERE option_name = %s", $key ) );
			}
			break;
		case 'seed':
			hibp_lab_seed( $job['credit'] ?? null );
			break;
		case 'probe':
			$result = array(
				'scope'            => $scope,
				'state'            => json_decode( keel_hibp_budget_read( $scope, $state_key ), true ),
				'rows'             => hibp_lab_rows(),
				'persistent_cache' => wp_using_ext_object_cache(),
			);
			break;
		case 'admit':
		case 'held':
			$result = keel_hibp_budget_admit( $scope, $job['prefix'] );
			if ( 'held' === $job['op'] ) {
				hibp_lab_json( $job['work'] . '/admitted.json', $result );
				hibp_lab_wait( $job['work'] . '/finish' );
				if ( isset( $result['ticket'] ) ) {
					$result['finish'] = keel_hibp_budget_finish( $result['ticket'], str_repeat( '0', 35 ) . ':0' );
				}
			}
			break;
		case 'claim':
			$result = array( 'claim' => keel_hibp_budget_claim( $job['ticket'] ) );
			break;
		case 'finish':
			$result = array( 'finish' => keel_hibp_budget_finish( $job['ticket'], $job['body'] ?? null ) );
			break;
		case 'expire':
			$raw   = keel_hibp_budget_read( $scope, $state_key );
			$state = keel_hibp_budget_state( $raw, time() );
			foreach ( $state['leases'] as &$lease ) {
				$lease['until'] = time() - 1;
			}
			unset( $lease );
			++$state['rev'];
			hibp_lab_assert( keel_hibp_budget_write( $scope, $state_key, $raw, $state ), 'expire fixture CAS failed' );
			break;
		case 'lookup':
			$result['verdicts'] = array();
			for ( $i = 0; $i < ( $job['count'] ?? 1 ); ++$i ) {
				$result['verdicts'][] = keel_hibp_lookup( $job['password'] ?? 'synthetic-hibp-lab-value' );
				if ( ! empty( $job['alternate'] ) ) {
					keel_hibp_budget_note( $i % 2 ? 'lookup_busy' : 'budget_exhausted' );
				}
			}
			break;
		case 'flush':
			wp_cache_flush();
			break;
		case 'noop':
			break;
		case 'evidence':
			$result['evidence'] = keel_hibp_budget_evidence();
			break;
		case 'diagnose':
			hibp_lab_seed();
			$result['idle']                = keel_hibp_budget_connection_idle();
			$result['transactional']       = keel_hibp_budget_transactional( $scope );
			$result['idle_after_metadata'] = keel_hibp_budget_connection_idle();
			$raw                           = keel_hibp_budget_read( $scope, $state_key );
			$state                         = keel_hibp_budget_state( $raw, time() );
			$result['valid_state']         = false !== $state;
			++$state['rev'];
			$result['cas']   = keel_hibp_budget_write( $scope, $state_key, $raw, $state );
			$result['begin'] = keel_hibp_budget_transaction( 'START TRANSACTION' );
			if ( $result['begin'] ) {
				$result['rollback'] = keel_hibp_budget_transaction( 'ROLLBACK' ); }
			$admission           = keel_hibp_budget_admit( $scope, '01001' );
			$result['admission'] = isset( $admission['ticket'] ) ? 'ticket' : ( $admission['error'] ?? 'body' );
			$result['leases']    = count( keel_hibp_budget_state( keel_hibp_budget_read( $scope, $state_key ), time() )['leases'] );
			break;
		case 'adapter':
			hibp_lab_seed();
			$errors_before = count( $GLOBALS['EZSQL_ERROR'] ?? array() );
			for ( $i = 0; $i < 10; ++$i ) {
				hibp_lab_assert( keel_hibp_budget_connection_idle(), 'idle connection detection failed' ); }
			hibp_lab_assert( count( $GLOBALS['EZSQL_ERROR'] ?? array() ) === $errors_before, 'expected transaction probes accumulated request error records' );
			$raw   = keel_hibp_budget_read( $scope, $state_key );
			$state = json_decode( $raw, true );
			// A case-only mismatch must lose even under case-insensitive MySQL collation.
			$state['success'] = time();
			hibp_lab_assert( ! keel_hibp_budget_write( $scope, $state_key, strtoupper( $raw ), $state ), 'case-folded CAS accepted' );
			hibp_lab_assert( ! keel_hibp_budget_write( $scope, $state_key, $raw . ' ', $state ), 'whitespace-mismatched CAS accepted' );
			hibp_lab_assert( strtoupper( bin2hex( $raw ) ) === $wpdb->get_var( $wpdb->prepare( "SELECT HEX(option_value) FROM `{$scope['table']}` WHERE option_name=%s", $state_key ) ), 'HEX byte roundtrip failed' );
			$result['class']  = get_class( $wpdb );
			$result['engine'] = defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql';
			if ( 'mysql' === $result['engine'] ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $scope['table'] ) );
				hibp_lab_assert( $row && 'innodb' === strtolower( $row->Engine ), 'options table must use InnoDB' );
				$result['engine'] = $row->Engine;
			}
			hibp_lab_assert( false !== $wpdb->query( 'START TRANSACTION' ), 'BEGIN unsupported' );
			if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
				$dbh = $wpdb->get_driver();
				hibp_lab_assert( is_object( $dbh ) && is_callable( array( $dbh, 'inTransaction' ) ) && $dbh->inTransaction(), 'SQLite caller transaction cannot be detected' );
			} else {
				hibp_lab_assert( ! keel_hibp_budget_connection_idle(), 'session transaction probe unsupported' );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE `{$scope['table']}` SET option_value=%s WHERE option_name=%s", $raw . ' ', $state_key ) );
			hibp_lab_assert( keel_hibp_budget_read( $scope, $state_key ) === $raw . ' ', 'transaction write not visible to caller' );
			$before    = $metrics;
			$admission = keel_hibp_budget_admit( $scope, '01000' );
			hibp_lab_assert( isset( $admission['error'] ) && 'budget_unavailable' === $admission['error'], 'active caller transaction admitted' );
			hibp_lab_assert( $metrics['begins'] === $before['begins'] && $metrics['commits'] === $before['commits'] && $metrics['rollbacks'] === $before['rollbacks'], 'admission touched caller transaction' );
			hibp_lab_assert( null === keel_hibp_lookup( 'synthetic-transaction-lab-value' ), 'active transaction must return unknown' );
			hibp_lab_assert( 0 === $metrics['http'], 'active transaction reached HTTP' );
			hibp_lab_assert( false !== $wpdb->query( 'ROLLBACK' ), 'ROLLBACK unsupported' );
			hibp_lab_assert( keel_hibp_budget_read( $scope, $state_key ) === $raw, 'caller write was committed or rollback failed' );
			hibp_lab_assert( false !== $wpdb->query( 'START TRANSACTION' ) && false !== $wpdb->query( 'COMMIT' ), 'COMMIT unsupported' );
			break;
		case 'storage':
			for ( $i = 0; $i < ( $job['count'] ?? 2048 ); ++$i ) {
				hibp_lab_seed();
				$prefix    = sprintf( '%02X%03X', $i % 256, intdiv( $i, 256 ) );
				$admission = keel_hibp_budget_admit( $scope, $prefix );
				hibp_lab_assert( isset( $admission['ticket'] ), 'storage fixture admission failed' );
				hibp_lab_assert( keel_hibp_budget_finish( $admission['ticket'], str_repeat( '0', 35 ) . ':0' ), 'storage fixture publication failed' );
			}
			$result['rows'] = hibp_lab_rows();
			hibp_lab_assert( count( $result['rows'] ) <= 65, 'row cardinality exceeded' );
			foreach ( $result['rows'] as $row ) {
				hibp_lab_assert( (bool) preg_match( '/^(state|slot_(?:[0-5][0-9]|6[0-3]))$/D', $row['key'] ), 'unbounded key' );
				hibp_lab_assert( $row['bytes'] <= ( 'state' === $row['key'] ? 4096 : 132096 ), 'encoded cap exceeded' );
				hibp_lab_assert( 'no' === $row['autoload'], 'authority/payload autoloaded' );
			}
			hibp_lab_assert( ! keel_hibp_budget_write( $scope, $state_key, keel_hibp_budget_read( $scope, $state_key ), array( 'oversize' => str_repeat( 'x', 4096 ) ) ), 'state encoded cap ignored' );
			hibp_lab_assert( false === keel_hibp_budget_read( $scope, $state_key, 1 ), 'bounded read ignored' );
			$slot_key = keel_hibp_budget_key( $scope, 'slot_63' );
			hibp_lab_assert( ! keel_hibp_budget_write( $scope, $slot_key, keel_hibp_budget_read( $scope, $slot_key, 132096 ), array( 'oversize' => str_repeat( 'x', 132096 ) ), 132096 ), 'slot encoded cap ignored' );
			hibp_lab_seed();
			$large = implode( "\n", array_fill( 0, 3449, str_repeat( '0', 35 ) . ':0' ) );
			hibp_lab_assert( strlen( $large ) <= 131072 && strlen( wp_json_encode( $large ) ) > 132096, 'encoded expansion fixture is not at boundary' );
			$admission = keel_hibp_budget_admit( $scope, 'FFABC' );
			hibp_lab_assert( isset( $admission['ticket'] ) && keel_hibp_budget_finish( $admission['ticket'], $large ), 'valid live response lost on encoded retention rejection' );
			hibp_lab_assert( false === keel_hibp_budget_cache( $scope, 'FFABC', time() ), 'encoded expansion exceeded fixed slot cap' );
			hibp_lab_seed();
			$oversized = $large . "\n" . str_repeat( '0', 35 ) . ':12345678901234567890123456789012345678901234567890';
			hibp_lab_assert( strlen( $oversized ) > 131072, 'raw oversize fixture too short' );
			$admission = keel_hibp_budget_admit( $scope, 'FEABC' );
			hibp_lab_assert( isset( $admission['ticket'] ) && keel_hibp_budget_finish( $admission['ticket'], $oversized ), 'raw oversize live result lost' );
			hibp_lab_assert( false === keel_hibp_budget_cache( $scope, 'FEABC', time() ), 'raw body cap ignored' );

			break;
		case 'uninstall':
			if ( 'true' !== getenv( 'GITHUB_ACTIONS' ) ) {
				throw new Keel_HIBP_Lab_Exception( 'Full uninstall proof requires the disposable CI fixture.' );
			}
			hibp_lab_seed();
			// Simulate another caller's stale option-miss cache for every direct SQL key.
			$owners = is_multisite() ? get_networks( array( 'number' => 10 ) ) : array( (object) array( 'id' => 0 ) );
			foreach ( $owners as $network ) {
				if ( is_multisite() ) {
					switch_to_blog( get_main_site_id( (int) $network->id ) ); }
				$owner_scope = array(
					'table' => $wpdb->options,
					'id'    => (int) $network->id,
				);
				$negative    = array();
				for ( $i = -1; $i < 64; ++$i ) {
					$key   = 'keel_hibp_v2_' . $owner_scope['id'] . '_' . ( -1 === $i ? 'state' : sprintf( 'slot_%02d', $i ) );
					$value = -1 === $i ? keel_hibp_budget_initial( keel_hibp_budget_policy(), time() ) : array(
						'v'     => 2,
						'owner' => str_repeat( '0', 32 ),
						'data'  => null,
					);
					$old   = keel_hibp_budget_read( $owner_scope, $key );
					if ( wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) !== $old ) {
						hibp_lab_assert( keel_hibp_budget_write( $owner_scope, $key, $old, $value ), 'uninstall fixture creation failed' );
					}
					$negative[ $key ] = true;
				}
				wp_cache_set( 'notoptions', $negative, 'options' );
				if ( is_multisite() ) {
					restore_current_blog(); }
			}
			define( 'WP_UNINSTALL_PLUGIN', true );
			require dirname( __DIR__, 2 ) . '/uninstall.php';
			hibp_lab_assert( array() === hibp_lab_rows(), 'uninstall left rows behind a stale option cache' );
			if ( is_multisite() ) {
				foreach ( get_networks( array( 'number' => 10 ) ) as $network ) {
					$table  = $wpdb->get_blog_prefix( get_main_site_id( (int) $network->id ) ) . 'options';
					$prefix = 'keel_hibp_v2_' . (int) $network->id . '_';
					hibp_lab_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ), 'network fixed rows survived uninstall' );
				}
			}
			$result['fixed_rows_removed'] = true;
			break;
		case 'scope':
			if ( ! is_multisite() ) {
				hibp_lab_assert( 0 === $scope['id'], 'single-site scope is not zero' );
				$result['multisite'] = false;
				break;
			}
			$original = $GLOBALS['current_site'];
			$network  = get_current_network_id();
			$sites    = get_sites(
				array(
					'network_id' => $network,
					'number'     => 10,
					'fields'     => 'ids',
				)
			);
			hibp_lab_assert( count( $sites ) >= 2, 'CI requires child site fixture' );
			foreach ( $sites as $site_id ) {
				switch_to_blog( $site_id );
				hibp_lab_assert( keel_hibp_budget_scope() === $scope, 'blog switch split authority' );
				restore_current_blog();
			}
			$networks = get_networks( array( 'number' => 10 ) );
			hibp_lab_assert( count( $networks ) >= 2, 'CI requires second network fixture' );
			foreach ( $networks as $other ) {
				if ( (int) $other->id === $network ) {
					continue;
				}
				$GLOBALS['current_site'] = $other;
				switch_to_blog( get_main_site_id( (int) $other->id ) );
				$second = keel_hibp_budget_scope();
				hibp_lab_assert( $second && $second['id'] !== $scope['id'] && $second['table'] !== $scope['table'], 'networks share authority' );
				$admission = keel_hibp_budget_admit( $second, '01000' );
				hibp_lab_assert( isset( $admission['ticket'] ), 'other network did not have independent allowance' );
				keel_hibp_budget_finish( $admission['ticket'] );
				for ( $i = -1; $i < 64; ++$i ) {
					$key = keel_hibp_budget_key( $second, -1 === $i ? 'state' : sprintf( 'slot_%02d', $i ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$second['table']}` WHERE option_name = %s", $key ) );
				}
				restore_current_blog();
				$GLOBALS['current_site'] = $original;
			}
			$result['multisite'] = true;
			break;
		default:
			throw new Keel_HIBP_Lab_Exception( 'unsupported lab operation' );
	}
	$delta = array();
	foreach ( $metrics as $key => $value ) {
		$delta[ $key ] = $value - $start_metrics[ $key ];
	}
	ob_end_clean();
	hibp_lab_json(
		$job['work'] . '/result.json',
		array(
			'ok'           => true,
			'result'       => $result,
			'metrics'      => $delta,
			'bootstrap'    => $bootstrap,
			'milliseconds' => round( ( microtime( true ) - $start ) * 1000, 3 ),
			'peak_bytes'   => memory_get_peak_usage( true ),
		)
	);
} catch ( Throwable $error ) {
	ob_end_clean();
	// Only harness-authored assertion messages; never print SQL, WP config or payloads.
	$message = $error instanceof Keel_HIBP_Lab_Exception ? $error->getMessage() : 'unexpected PHP exception: ' . get_class( $error );
	hibp_lab_json(
		$job['work'] . '/result.json',
		array(
			'ok'    => false,
			'error' => $message,
		)
	);
	exit( 1 );
}
