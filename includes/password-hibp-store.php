<?php
/**
 * Fixed, database-authoritative HIBP rows. No object-cache authority.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical trusted scope.
 *
 * @return array{table:string,id:int}|false
 */
function keel_hibp_budget_scope() {
	global $wpdb;
	if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
		return false;
	}
	$id    = 0;
	$table = $wpdb->options;
	if ( is_multisite() ) {
		$id   = get_current_network_id();
		$main = get_main_site_id( $id );
		$site = $main ? get_site( $main ) : null;
		if ( ! $site || (int) $site->site_id !== $id || ! empty( $site->deleted ) ) {
			return false;
		}
		$table = $wpdb->get_blog_prefix( $main ) . 'options';
	}
	return is_int( $id ) && $id >= 0 && is_string( $table ) && preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table )
		? array(
			'table' => $table,
			'id'    => $id,
		) : false;
}

/**
 * Fixed option name, never credential-derived.
 *
 * @param array  $scope Canonical database scope.
 * @param string $part Fixed row identifier.
 * @return string
 */
function keel_hibp_budget_key( array $scope, $part ) {
	return 'keel_hibp_v2_' . $scope['id'] . '_' . $part;
}

/**
 * Bounded raw value, missing, or unavailable.
 *
 * @param array  $scope Canonical database scope.
 * @param string $key Fixed option name.
 * @param int    $cap Maximum encoded bytes.
 * @return string|null|false
 */
function keel_hibp_budget_read( array $scope, $key, $cap = 4096 ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- CAS authority cannot use an evictable cache.
	$previous = $wpdb->suppress_errors( true );
	$raw      = $wpdb->get_var( $wpdb->prepare( "SELECT SUBSTR(option_value,1,%d) FROM `{$scope['table']}` WHERE option_name = %s", $cap + 1, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier derived from trusted WP scope and allowlisted.
	$error    = $wpdb->last_error;
	$wpdb->suppress_errors( $previous );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	return '' !== $error || ( null !== $raw && ( ! is_string( $raw ) || strlen( $raw ) > $cap ) ) ? false : $raw;
}

/**
 * Insert-if-absent or byte-exact compare-and-swap.
 *
 * @param array  $scope Canonical database scope.
 * @param string $key Fixed option name.
 * @param mixed  $old Expected raw value, or null for insert.
 * @param array  $replacement Candidate value.
 * @param int    $cap Maximum encoded bytes.
 * @return bool
 */
function keel_hibp_budget_write( array $scope, $key, $old, array $replacement, $cap = 4096 ) {
	global $wpdb;
	$value = wp_json_encode( $replacement, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $value ) || strlen( $value ) > $cap ) {
		return false;
	}
	$previous = $wpdb->suppress_errors( true );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed trusted table; all values prepared; cache would violate CAS authority.
	if ( null === $old ) {
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$scope['table']}` (option_name,option_value,autoload) VALUES (%s,%s,'no')", $key, $value ) );
	} else {
		$result = $wpdb->query( $wpdb->prepare( "UPDATE `{$scope['table']}` SET option_value = %s, autoload = 'no' WHERE option_name = %s AND HEX(option_value) = %s", $value, $key, strtoupper( bin2hex( $old ) ) ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->suppress_errors( $previous );
	return 1 === $result;
}

/**
 * Reject caller-owned transactions before any authority mutation or HTTP.
 *
 * MySQL-compatible BEGIN may implicitly commit an existing transaction, including
 * the SQLite translator. Unknown connection implementations fail conservatively.
 *
 * @return bool The connection is idle and in normal autocommit mode.
 */
function keel_hibp_budget_connection_idle() {
	global $wpdb;
	if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
		if ( ! is_callable( array( $wpdb, 'get_driver' ) ) ) {
			return false;
		}
		$driver = $wpdb->get_driver();
		return is_object( $driver ) && is_callable( array( $driver, 'inTransaction' ) ) && ! $driver->inTransaction();
	}
	$connection = $wpdb->dbh;
	if ( ! $connection instanceof mysqli ) {
		return false;
	}
	$previous = $wpdb->suppress_errors( true );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Non-mutating connection check plus an immediately released private savepoint; never commits caller data.
	$autocommit = $wpdb->get_var( 'SELECT @@session.autocommit' );
	if ( '1' !== (string) $autocommit ) {
		$wpdb->suppress_errors( $previous );
		return false;
	}
	$token = str_replace( '-', '', wp_generate_uuid4() );
	if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $token ) ) {
		$wpdb->suppress_errors( $previous );
		return false;
	}
	$name      = 'keel_hibp_probe_' . $token;
	$old_error = $wpdb->last_error;
	// Outside a transaction SAVEPOINT has no effect and RELEASE reports 1305.
	// Inside a transaction both succeed; release only our private savepoint and deny.
	$saved    = $wpdb->query( "SAVEPOINT `$name`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Random identifier strictly allowlisted above.
	$released = false !== $saved ? $wpdb->query( "RELEASE SAVEPOINT `$name`" ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same allowlisted private identifier.
	$idle     = false !== $saved && false === $released && 1305 === $connection->errno;
	if ( $idle ) {
		$wpdb->last_error = $old_error;
	}
	$wpdb->suppress_errors( $previous );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	return $idle;
}

/**
 * Short transactions must actually roll back on the owning table.
 *
 * @param array $scope Canonical database scope.
 * @return bool
 */
function keel_hibp_budget_transactional( array $scope ) {
	global $wpdb;
	if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
		return true;
	}
	$previous = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Capability inspection of canonical table; no application data.
	$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $scope['table'] ) );
	$wpdb->suppress_errors( $previous );
	return is_object( $row ) && isset( $row->Engine ) && 'innodb' === strtolower( $row->Engine ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL metadata field.
}

/**
 * Execute one fixed transaction command, never user SQL.
 *
 * @param string $command Allowlisted transaction command.
 * @return bool
 */
function keel_hibp_budget_transaction( $command ) {
	global $wpdb;
	if ( 'START TRANSACTION' === $command && ! keel_hibp_budget_connection_idle() ) {
		return false;
	}
	if ( ! in_array( $command, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
		return false;
	}
	$previous = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Strict fixed command allowlist.
	$result = $wpdb->query( $command );
	$wpdb->suppress_errors( $previous );
	return false !== $result;
}
