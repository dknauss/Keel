<?php
/** Cron attribution metadata contracts. @package Keel */
define( 'ABSPATH', __DIR__ . '/' );
function wp_json_encode( $value ) { return json_encode( $value ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function keel_defaults_autoload_owner( $name ) {
	if ( 'rewrite_rules' === $name ) {
		return array(
			'owner'      => 'WordPress core',
			'confidence' => 'high',
			'evidence'   => 'Known core OPTION.',
		); }
	return array(
		'owner'      => 'Unknown',
		'confidence' => 'unknown',
		'evidence'   => 'Unrecognized name.',
	); }
function keel_cron_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
require dirname( __DIR__ ) . '/includes/cron-diagnostics.php';
$now    = 2000000000;
$cron   = array(
	$now - 3600 => array(
		'shop_cron' => array(
			'hash' => array(
				'schedule' => 'minute',
				'interval' => 60,
				'args'     => array( 'SECRET' ),
			),
		),
	),
	$now + 100  => array(
		'wp_version_check' => array(
			'hash' => array(
				'schedule' => 'twicedaily',
				'interval' => 43200,
				'args'     => array(),
			),
		),
	),
	'VERSION'   => 2,
);
$report = keel_defaults_cron_extract( $cron, $now );
keel_cron_assert( 2 === $report['examined'] && ! $report['limited'], 'Valid event instances are examined.' );
keel_cron_assert( 3600 === $report['overdue'][0]['delay'], 'Overdue evidence is seconds late at snapshot time.' );
keel_cron_assert( 60 === $report['frequent'][0]['interval'], 'Frequent schedule evidence uses stored seconds.' );
keel_cron_assert( $now + 100 === $report['core']['wp_version_check'], 'Core next check is retained.' );
keel_cron_assert( false === strpos( wp_json_encode( $report ), 'SECRET' ), 'Event arguments never enter metadata.' );
$many = array();
for ( $i = 0; $i < 2100; ++$i ) {
	$many[ $now + $i ] = array(
		'unknown_hook' => array(
			'x' => array(
				'schedule' => false,
				'args'     => array(),
			),
		),
	);
}
$partial = keel_defaults_cron_extract( $many, $now );
keel_cron_assert( $partial['limited'] && 2000 === $partial['examined'], 'Event cap yields a partial observation.' );
keel_cron_assert( count( $partial['overdue'] ) <= 5 && count( $partial['frequent'] ) <= 5, 'Output remains bounded.' );
keel_cron_assert( 'high' === keel_defaults_cron_owner( 'wp_maybe_auto_update' )['confidence'], 'Known core hooks have explicit attribution.' );
keel_cron_assert( 'unknown' === keel_defaults_cron_owner( 'mystery' )['confidence'], 'Unrecognized hook remains unknown.' );
foreach ( array( 'wp_site_health_scheduled_check', 'wp_scheduled_auto_draft_delete', 'recovery_mode_clean_expired_keys', 'wp_delete_temp_updater_backups', 'publish_future_post' ) as $core_hook ) {
	keel_cron_assert( 'WordPress core' === keel_defaults_cron_owner( $core_hook )['owner'], "Core-scheduled hook {$core_hook} is attributed to core." );
}
$update = keel_defaults_cron_updates(
	(object) array( 'last_checked' => $now - 50000 ),
	array(
		'timestamp'  => $now - 100,
		'error_code' => 'disk_full',
		'error_data' => 'SECRET',
	),
	$now
);
keel_cron_assert( 50000 === $update['offer_age'] && 'disk_full' === $update['failure']['code'], 'Update evidence includes age and safe failure code.' );
keel_cron_assert( false === strpos( wp_json_encode( $update ), 'SECRET' ), 'Failure data is never stored.' );
$unknown = keel_defaults_cron_updates( (object) array( 'last_checked' => $now + 1 ), array( 'critical' => true ), $now );
keel_cron_assert( null === $unknown['offer_age'] && null === $unknown['failure']['at'], 'Future offers and undated failures are unknown.' );
echo "cron diagnostics: OK\n";
keel_cron_assert( function_exists( 'keel_defaults_cron_health' ), 'Report integrates with Site Health.' );
$GLOBALS['cron_cache'] = array();
$GLOBALS['cron_blog']  = 1;
$GLOBALS['cron_cap']   = true;
$GLOBALS['cron_jobs']  = 0;
function get_current_blog_id() { return $GLOBALS['cron_blog']; }
function get_transient( $key ) { return $GLOBALS['cron_cache'][ get_current_blog_id() ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['cron_cache'][ get_current_blog_id() ] = $value;
	$GLOBALS['cron_ttl'] = $ttl; }
function wp_using_ext_object_cache() { return false; }
function wp_cache_delete( ...$args ) {}
function current_user_can( $cap ) { return $GLOBALS['cron_cap']; }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function keel_defaults_autoload_lock() { return 'token:' . ( time() + 30 ); }
function keel_defaults_autoload_lease() { return 'token:' . ( time() + 30 ); }
function keel_defaults_autoload_unlock( $token ) {}
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_single_event( $at, $hook ) { ++$GLOBALS['cron_jobs']; }
function get_site_transient( $key ) { return (object) array( 'last_checked' => time() - 50000 ); }
function get_site_option( $key ) { return array(
	'error_code' => 'disk_full',
	'timestamp'  => time() - 100,
	'error_data' => 'SECRET',
); }
function check_ajax_referer( $action ) { if ( empty( $GLOBALS['cron_nonce'] ) ) {
		throw new RuntimeException( 'nonce' ); } }
function wp_send_json_error( $data, $status ) { throw new RuntimeException( 'denied:' . $status ); }
function wp_send_json_success( $data ) {}
class Keel_Cron_DB {
	public $options    = 'wp_options';
	public $last_error = '';
	public $reads      = 0;
	public $row;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql, $format ) { ++$this->reads;
		return $this->row; }
}
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['wpdb']      = new Keel_Cron_DB();
$GLOBALS['wpdb']->row = array(
	'bytes'   => 100,
	'payload' => serialize( $cron ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- fixture uses core's format.
);
keel_defaults_cron_health();
keel_cron_assert( 0 === $GLOBALS['wpdb']->reads && 1 === $GLOBALS['cron_jobs'], 'Opening health only requests background collection.' );
keel_defaults_cron_collect();
$state = keel_defaults_cron_snapshot();
keel_cron_assert( $state['done'] && '' === $state['error'] && false === strpos( wp_json_encode( $state ), 'SECRET' ), 'Background job stores metadata only.' );
$reads  = $GLOBALS['wpdb']->reads;
$health = keel_defaults_cron_health();
keel_cron_assert( $reads === $GLOBALS['wpdb']->reads, 'Fresh cached report does not collect again.' );
$GLOBALS['cron_blog'] = 2;
keel_cron_assert( false === keel_defaults_cron_snapshot(), 'Reports are site-scoped.' );
keel_defaults_cron_health();
$GLOBALS['wpdb']->row = array(
	'bytes'   => 1048577,
	'payload' => null,
);
keel_defaults_cron_collect();
keel_cron_assert( 'cron_too_large' === keel_defaults_cron_snapshot()['error'] && 300 === $GLOBALS['cron_ttl'], 'Oversized cron is explicit and error cache is short.' );
$GLOBALS['cron_cap'] = false;
keel_cron_assert( '' === keel_defaults_cron_health()['description'], 'Unauthorized callers receive no metadata.' );
$GLOBALS['cron_nonce'] = true;
try {
	keel_defaults_cron_ajax();
	keel_cron_assert( false, 'Unauthorized AJAX must stop.' );
} catch ( RuntimeException $e ) {
	keel_cron_assert( 'denied:403' === $e->getMessage(), 'AJAX enforces capability.' ); }
$GLOBALS['cron_cap']   = true;
$GLOBALS['cron_nonce'] = false;
try {
	keel_defaults_cron_ajax();
	keel_cron_assert( false, 'Invalid nonce must stop.' );
} catch ( RuntimeException $e ) {
	keel_cron_assert( 'nonce' === $e->getMessage(), 'AJAX enforces nonce.' ); }
$state['site']    = get_current_blog_id();
$state['checked'] = time() - 601;
$state['done']    = false;
set_transient( 'keel_cron_report', $state, 3600 );
keel_cron_assert( 'scheduling_delayed' === keel_defaults_cron_snapshot()['error'], 'External cron delay does not stay pending forever.' );
echo "cron runtime: OK\n";

$state['checked'] = time();
$state['done']    = true;
$state['data']    = array();
set_transient( 'keel_cron_report', $state, 3600 );
keel_cron_assert( false === keel_defaults_cron_snapshot(), 'Malformed report shapes are rejected.' );
$GLOBALS['cron_blog']        = 3;
$GLOBALS['wpdb']->last_error = 'failure';
keel_defaults_cron_health();
keel_defaults_cron_collect();
keel_cron_assert( 'query_failed' === keel_defaults_cron_snapshot()['error'], 'Query failure must not look like an empty schedule.' );
$GLOBALS['cron_blog']        = 4;
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->row        = array(
	'bytes'   => 4,
	'payload' => 'oops',
);
keel_defaults_cron_health();
keel_defaults_cron_collect();
keel_cron_assert( 'malformed_cron' === keel_defaults_cron_snapshot()['error'], 'Malformed serialization is reported safely.' );
class Keel_Cron_Object {
	public function __wakeup() { throw new RuntimeException( 'Object arguments must not be instantiated.' ); }
}
$cron[ $now - 3600 ]['shop_cron']['hash']['args'] = array( new Keel_Cron_Object() );
$GLOBALS['cron_blog']                             = 5;
$GLOBALS['wpdb']->row                             = array(
	'bytes'   => 100,
	'payload' => serialize( $cron ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- core fixture format.
);
keel_defaults_cron_health();
keel_defaults_cron_collect();
keel_cron_assert( '' === keel_defaults_cron_snapshot()['error'], 'Classless decoding never invokes object wakeup.' );

keel_cron_assert( 'unknown' === keel_defaults_cron_owner( 'rewrite_rules' )['confidence'], 'An option name does not prove cron hook ownership.' );
