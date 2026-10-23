<?php
// phpcs:disable WordPress.DB.RestrictedClasses -- Isolated PDO SQLite test database.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress JSON test stub.
/** SQL-backed test double; real concurrent backend proof lives in integration labs. */
class Keel_HIBP_Test_DB {
	public $options    = 'wp_options';
	public $last_error = '';
	public $pdo;
	public $cas_attempts = 0;
	public $cas_failures = 0;
	public $queries      = 0;
	private $suppressed  = false;
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_options (option_name TEXT UNIQUE,option_value TEXT,autoload TEXT)' );
	}
	public function get_driver() { return $this->pdo; }
	public function suppress_errors( $value ) { $old = $this->suppressed;
		$this->suppressed                            = $value;
		return $old; }
	public function prepare( $sql, ...$args ) {
		return preg_replace_callback(
			'/%[sd]/',
			function ( $match ) use ( &$args ) { $value = array_shift( $args );
				return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( $value );
			},
			$sql
		);
	}
	public function get_var( $sql ) {
		++$this->queries;
		$this->last_error = '';
		try {
			$v = $this->pdo->query( $sql )->fetchColumn();
			return false === $v ? null : $v;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return null; }
	}
	public function query( $sql ) {
		if ( false !== strpos( $sql, 'AND HEX(option_value)' ) ) {
			++$this->cas_attempts;
			if ( $this->cas_failures > 0 ) {
				--$this->cas_failures;
				return 0; }
		}
		++$this->queries;
		$this->last_error = '';
		$sql              = str_replace( array( 'INSERT IGNORE', 'START TRANSACTION' ), array( 'INSERT OR IGNORE', 'BEGIN' ), $sql );
		try {
			return $this->pdo->exec( $sql );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false; }
	}
}
function is_multisite() { return false; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
define( 'DB_ENGINE', 'sqlite' );
$GLOBALS['wpdb'] = new Keel_HIBP_Test_DB();
