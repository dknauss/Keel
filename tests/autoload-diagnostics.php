<?php
/** Autoload diagnostic contracts; names and sizes only, never option values. @package Keel */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['cache']     = array();
$GLOBALS['options']   = array();
$GLOBALS['blog']      = 1;
$GLOBALS['scheduled'] = array();
$GLOBALS['allowed']   = true;
$GLOBALS['nonce']     = true;
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_transient( $key ) {
	if ( ! empty( $GLOBALS['negative_snapshot'] ) ) {
		return false;
	}
	if ( isset( $GLOBALS['stale_snapshot'] ) ) {
		return $GLOBALS['stale_snapshot'];
	}
	return $GLOBALS['cache'][ $GLOBALS['blog'] ][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) { $GLOBALS['cache'][ $GLOBALS['blog'] ][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['cache'][ $GLOBALS['blog'] ][ $key ] ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $GLOBALS['blog'] ][ $key ] ?? $default; }
function add_option( $key, $value, $unused = '', $autoload = true ) {
	if ( isset( $GLOBALS['options'][ $GLOBALS['blog'] ][ $key ] ) ) {
		return false; }
	if ( false !== $autoload ) {
		throw new RuntimeException( 'Diagnostic locks must not autoload.' ); }
	$GLOBALS['options'][ $GLOBALS['blog'] ][ $key ] = $value;
	return true;
}
function wp_using_ext_object_cache() { return ! empty( $GLOBALS['external_cache'] ); }
function wp_cache_delete( ...$args ) {
	if ( 'notoptions' === $args[0] ) {
		unset( $GLOBALS['negative_snapshot'] );
	}
	if ( '_transient_keel_autoload_report' === $args[0] ) {
		unset( $GLOBALS['stale_snapshot'] );
	}
}
function wp_cache_get( $key, $group, $force = false ) {
	check( $force && 'transient' === $group, 'Persistent snapshot reads force a fresh backend value.' );
	return $GLOBALS['cache'][ $GLOBALS['blog'] ][ $key ] ?? false;
}
function wp_generate_uuid4() { static $id = 0;
	return 'test-' . ( ++$id ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'][ $GLOBALS['blog'] ][ $hook ] ?? false; }
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['scheduled'][ $GLOBALS['blog'] ][ $hook ] = $when;
	return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $GLOBALS['blog'] ][ $hook ] ); }
function wp_autoload_values_to_autoload() { return array( 'yes', 'on', 'auto', 'auto-on' ); }
function get_plugins() { return array(
	'shop/shop.php'     => array( 'Name' => 'Shop <script>' ),
	'shared/shared.php' => array( 'Name' => 'Shared' ),
	'shared.php'        => array( 'Name' => 'Other Shared' ),
); }
function wp_get_theme( $slug ) { return new Keel_Test_Theme( $slug ); }
class Keel_Test_Theme {
	private $slug;
	public function __construct( $slug ) { $this->slug = $slug; }
	public function exists() { return 'test-theme' === $this->slug; }
	public function get( $field ) { return 'Test Theme'; }
}
function check_ajax_referer( $action ) { if ( ! $GLOBALS['nonce'] ) {
		throw new RuntimeException( 'nonce' ); } }
function wp_send_json_error( $data, $status ) { throw new RuntimeException( 'denied:' . $status ); }
function wp_send_json_success( $data ) { throw new RuntimeException( 'success' ); }
class Keel_Autoload_DB {
	public $options = 'wp_options';
	public $prefix  = 'hgnm_';
	public function get_blog_prefix() { return $this->prefix; }
	public $queries    = array();
	public $rows       = array();
	public $fail       = false;
	public $last_error = '';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback(
				'/%[ds]/',
				static function ( $match ) use ( $arg ) {
					return '%d' === $match[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", $arg ) . "'";
				},
				$sql,
				1
			); }
		return $sql;
	}
	public function query( $sql ) {
		if ( ! preg_match( "/VALUES \(\s*'keel_autoload_scan_lock',\s*'([^']+)'/", $sql, $match ) ) {
			throw new RuntimeException( 'Unexpected lock SQL.' );
		}
		if ( false !== get_option( 'keel_autoload_scan_lock' ) ) {
			return 0;
		}
		$GLOBALS['options'][ $GLOBALS['blog'] ]['keel_autoload_scan_lock'] = $match[1];
		return 1;
	}
	public function get_var( $sql ) {
		$value = get_option( 'keel_autoload_scan_lock' );
		return false === $value ? null : $value;
	}
	public function get_results( $sql, $mode ) {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( $this->fail ) {
			$this->last_error = 'query error';
			return array(); }
		if ( ! preg_match( '/option_id > (\d+).*LIMIT (\d+)/s', $sql, $match ) ) {
			throw new RuntimeException( 'No keyset bound.' ); }
		if ( false !== strpos( $sql, 'SELECT *' ) || false !== strpos( $sql, 'ORDER BY bytes' ) ) {
			throw new RuntimeException( 'Unbounded or value-bearing query.' ); }
		return array_slice(
			array_values(
				array_filter(
					$this->rows,
					static function ( $r ) use ( $match ) { return (int) $match[1] < $r['option_id'];
					}
				)
			),
			0,
			(int) $match[2]
		);
	}
	public function delete( $table, $where, $formats ) {
		if ( get_option( $where['option_name'] ) !== $where['option_value'] ) {
			return 0; }
		unset( $GLOBALS['options'][ $GLOBALS['blog'] ][ $where['option_name'] ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new Keel_Autoload_DB();
require dirname( __DIR__ ) . '/includes/autoload-diagnostics.php';
function check( $condition, $message ) { if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
function run_batch() { $GLOBALS['scheduled'][ $GLOBALS['blog'] ] = array();
	keel_defaults_autoload_collect(); }
$pending = keel_defaults_autoload_health();
check( empty( $GLOBALS['wpdb']->queries ), 'Opening the async report only schedules collection, never scans synchronously.' );
check( isset( $GLOBALS['scheduled'][1]['keel_defaults_autoload_collect'] ), 'Collection has a background continuation.' );
for ( $id = 1; $id <= 260; ++$id ) {
	$GLOBALS['wpdb']->rows[] = array(
		'option_id'   => $id,
		'option_name' => 'unknown_' . $id,
		'autoload'    => 'yes',
		'bytes'       => $id,
	);
}
$GLOBALS['wpdb']->rows[259] = array(
	'option_id'   => 260,
	'option_name' => 'shop_cache',
	'autoload'    => 'auto-on',
	'bytes'       => 900000,
);
run_batch();
$part = get_transient( 'keel_autoload_report' );
check( 250 === $part['scanned'] && ! $part['done'] && count( $part['top'] ) <= 10, 'One job bounds rows and metadata, and does not call a partial page complete.' );
run_batch();
$report = get_transient( 'keel_autoload_report' );
check( $report['done'] && ! $report['limited'] && 260 === $report['scanned'], 'Keyset continuation completes the actual data set.' );
check( 'shop_cache' === $report['top'][0]['name'] && 900000 === $report['top'][0]['bytes'], 'A later-page large option reaches the top of the report, including modern autoload values.' );
$count  = count( $GLOBALS['wpdb']->queries );
$health = keel_defaults_autoload_health();
check( count( $GLOBALS['wpdb']->queries ) === $count, 'Cached report reads never scan options.' );
check( false === strpos( $health['description'], '<script>' ), 'Plugin labels are escaped.' );
check( false !== strpos( $health['description'], 'Shop &lt;script&gt;' ), 'The probable plugin is named safely.' );
$unknown = keel_defaults_autoload_owner( 'shared_cache' );
check( 'unknown' === $unknown['confidence'], 'Ambiguous plugin prefixes remain unknown.' );
check( 'high' === keel_defaults_autoload_owner( 'theme_mods_test-theme' )['confidence'], 'A verified theme-mods name identifies its installed theme.' );
check( 'unknown' === keel_defaults_autoload_owner( 'some_random_key' )['confidence'], 'Unrecognized names do not acquire invented owners.' );
check( 'high' === keel_defaults_autoload_owner( 'rewrite_rules' )['confidence'], 'Known core options are distinguished from inferred plugin names.' );
foreach ( array( 'keel_settings', 'keel_defaults', '_transient_keel_cron_report', '_transient_timeout_keel_shortcode_residue', '_transient_keel_hibp_0123456789abcdef0123456789abcdef_5BAA6', '_transient_keel_backport_install_result_12' ) as $own ) {
	$owner = keel_defaults_autoload_owner( $own );
	check( 'Keel' === $owner['owner'] && 'high' === $owner['confidence'], "Keel attributes its own stored name {$own}." );
}
foreach ( array( 'keel_unrelated', '_transient_keel_hibp_short_5BAA6', '_transient_keel_backport_install_result_x', 'keel_settings_extra' ) as $other ) {
	check( 'Keel' !== keel_defaults_autoload_owner( $other )['owner'], "A name Keel does not write is not claimed: {$other}." );
}
foreach ( array( '_transient_wp_core_block_css_files', '_transient_wp_styles_for_blocks', '_transient_health-check-site-status-result', '_transient_doing_cron', '_transient_timeout_plugin_slugs', '_site_transient_update_plugins', '_site_transient_wp_plugin_dependencies_plugin_data' ) as $core ) {
	$owner = keel_defaults_autoload_owner( $core );
	check( 'WordPress core' === $owner['owner'] && 'high' === $owner['confidence'], "Core transient {$core} is attributed to core." );
}
check( 'unknown' === keel_defaults_autoload_owner( '_transient_some_random_key' )['confidence'], 'Unrecognized transients stay unknown.' );
check( 'Shop <script>' === keel_defaults_autoload_owner( '_transient_shop_cart' )['owner'], 'A transient prefix does not hide a plugin-slug match.' );
check( 'unknown' === keel_defaults_autoload_owner( '_transient_rewrite_rules' )['confidence'], 'A transient sharing a core option name is not treated as that option.' );
check( 'WordPress core' === keel_defaults_autoload_owner( 'hgnm_user_roles' )['owner'], 'The roles option follows the site table prefix.' );
check( 'unknown' === keel_defaults_autoload_owner( 'wp_user_roles' )['confidence'], 'Another prefix\'s roles option is not claimed for core.' );
$GLOBALS['blog'] = 2;
keel_defaults_autoload_health();
check( 0 === get_transient( 'keel_autoload_report' )['scanned'], 'A second site starts independently.' );
$GLOBALS['blog'] = 1;
check( 260 === get_transient( 'keel_autoload_report' )['scanned'], 'Switching back preserves the first site snapshot.' );
$GLOBALS['allowed'] = false;
try {
	keel_defaults_autoload_ajax();
	check( false, 'Unauthorized AJAX reached collection.' );
} catch ( RuntimeException $e ) {
	check( 'denied:403' === $e->getMessage(), 'AJAX checks Site Health capability.' ); }
$GLOBALS['allowed'] = true;
$GLOBALS['nonce']   = false;
try {
	keel_defaults_autoload_ajax();
	check( false, 'Invalid nonce reached collection.' );
} catch ( RuntimeException $e ) {
	check( 'nonce' === $e->getMessage(), 'AJAX checks the Site Health nonce.' ); }
$GLOBALS['nonce'] = true;
// A full scan budget is a partial observation, never an assertion about the whole database.
$report['done']    = false;
$report['scanned'] = 9999;
$report['cursor']  = 0;
set_transient( 'keel_autoload_report', $report, 3600 );
run_batch();
$limited = get_transient( 'keel_autoload_report' );
check( 10000 === $limited['scanned'] && $limited['done'] && $limited['limited'], 'The total scan budget stops collection and is reported as partial.' );
// Query failure must not turn into a reassuring empty or complete report.
delete_transient( 'keel_autoload_report' );
keel_defaults_autoload_health();
$GLOBALS['wpdb']->fail = true;
run_batch();
check( 'query_failed' === get_transient( 'keel_autoload_report' )['error'], 'A failed query is explicit.' );
$GLOBALS['wpdb']->fail = false;
// A stale worker must not release a replacement worker's lock.
$token = keel_defaults_autoload_lock();
$GLOBALS['options'][1]['keel_autoload_scan_lock'] = 'replacement:' . ( time() + 30 );
keel_defaults_autoload_unlock( $token );
check( false !== get_option( 'keel_autoload_scan_lock' ), 'Lock release compares ownership atomically.' );
$GLOBALS['options'][1]['keel_autoload_scan_lock'] = 'expired:' . ( time() - 10 );
check( false !== keel_defaults_autoload_lock(), 'An expired lock can be reclaimed.' );
// Corrupted caches restart safely; delayed external cron becomes an honest partial.
set_transient(
	'keel_autoload_report',
	array(
		'schema'  => 1,
		'site'    => 1,
		'started' => time(),
	),
	3600
);
check( false === keel_defaults_autoload_snapshot(), 'Incomplete cache metadata is rejected.' );
$report['started'] = time() - 601;
$report['done']    = false;
set_transient( 'keel_autoload_report', $report, 3600 );
$stopped = keel_defaults_autoload_snapshot();
check( $stopped['done'] && $stopped['limited'], 'An external scheduler delay cannot leave an endless pending report.' );
// A request-local old cursor must not overwrite a newer worker's progress.
$report['started']    = time();
$report['cursor']     = 250;
$report['scanned']    = 250;
$report['autoloaded'] = 250;
$report['done']       = false;
$report['limited']    = false;
set_transient( 'keel_autoload_report', $report, 3600 );
$GLOBALS['stale_snapshot'] = array_merge(
	$report,
	array(
		'cursor'     => 0,
		'scanned'    => 0,
		'autoloaded' => 0,
	)
);
unset( $GLOBALS['options'][1]['keel_autoload_scan_lock'] );
run_batch();
check( 260 === keel_defaults_autoload_snapshot()['scanned'], 'Lease acquisition bypasses stale request-local progress.' );

$GLOBALS['negative_snapshot'] = true;
keel_defaults_autoload_health();
check( 260 === keel_defaults_autoload_snapshot()['scanned'], 'A negative cache cannot replace a concurrently created report.' );
$GLOBALS['external_cache'] = true;
check( 260 === keel_defaults_autoload_snapshot( true )['scanned'], 'Persistent cache snapshots retain current progress.' );
$GLOBALS['external_cache'] = false;
echo "autoload diagnostics: OK\n";
