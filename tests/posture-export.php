<?php
/** Export projection must never serialize arbitrary stored values. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'KEEL_DEFAULTS_VERSION', '0.8.0' );
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['export_hooks'][ $hook ] = $priority; }
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function current_user_can( $cap ) { return $GLOBALS['caps'][ $cap ] ?? false; }
function get_current_blog_id() { return 3; }
function keel_defaults_schema() { return array(
	'toggle'  => array( 'type' => 'toggle' ),
	'choice'  => array(
		'type'    => 'select',
		'choices' => array( 'safe' ),
	),
	'number'  => array(
		'type' => 'number',
		'min'  => 1,
		'max'  => 20,
	),
	'private' => array( 'type' => 'roles' ),
); }
function keel_defaults_get( $key ) { return array(
	'toggle'  => 'yes',
	'choice'  => 'SECRET_URL',
	'number'  => array( 'SECRET_TOKEN' ),
	'private' => array( 'SECRET_USER' ),
)[ $key ]; }
function keel_defaults_key_supported( $key ) { return true; }
function keel_defaults_config_lock( $key ) { return 'toggle' === $key ? 'SECRET_PATH' : null; }
function keel_defaults_config_locked_value( $key, $value ) { return $value; }
function keel_defaults_network_manages( $key ) { return false; }
function keel_defaults_background_updates_off() { return null; }
function get_site_transient( $key ) { return 'keel_stable_check' === $key ? array(
	'7.1.3'          => 'insecure',
	'SECRET_VERSION' => 'latest',
) : false; }
function get_site_option( $key, $default = false ) { return false; }
function get_transient( $key ) { return false; }
function keel_defaults_wp_version() { return '7.1.3'; }
function keel_defaults_autoload_snapshot() { return false; }
function keel_defaults_cron_snapshot() { return false; }
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . ( $args['response'] ?? 0 ) ); }
function check_admin_referer( $action ) { $GLOBALS['nonce_action'] = $action;
	throw new RuntimeException( 'bad nonce' ); }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
require __DIR__ . '/../includes/posture-export.php';
check( 0 === $GLOBALS['export_hooks']['init'] && ! isset( $GLOBALS['export_hooks']['admin_post_nopriv_keel_posture_export'] ), 'Early authenticated dispatch precedes core checks without a public download hook.' );
$report = keel_defaults_posture_export();
$json   = json_encode( $report ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone projection test.
check( false === strpos( $json, 'SECRET_' ), 'Secrets and arbitrary identities must not enter the export.' );
check( 'yes' === $report['policies']['toggle']['value'] && 'configuration' === $report['policies']['toggle']['source'], 'Validated policy and lock category are exported.' );
check( null === $report['policies']['choice']['value'] && null === $report['policies']['private']['value'], 'Malformed choices and free-form roles are withheld.' );
check( 'insecure' === $report['core']['status'] && null === $report['core']['observed_at'], 'Cached vulnerability status has explicitly unknown freshness.' );
check( 'unknown' === $report['core']['operability'] && 'unavailable' === $report['diagnostics']['cron']['coverage'], 'Unprobed and absent evidence remain unknown.' );
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
	keel_defaults_handle_posture_export();
	check( false, 'GET must fail.' );
} catch ( RuntimeException $e ) {
	check( 'denied:405' === $e->getMessage(), 'GET rejected before evidence reads.' ); }
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
	keel_defaults_handle_posture_export();
	check( false, 'Unauthorized POST must fail.' );
} catch ( RuntimeException $e ) {
	check( 'denied:403' === $e->getMessage(), 'Capability rejection precedes nonce.' ); }
$GLOBALS['caps'] = array(
	'manage_options'          => true,
	'view_site_health_checks' => true,
);
try {
	keel_defaults_handle_posture_export();
	check( false, 'Invalid nonce must fail.' );
} catch ( RuntimeException $e ) {
	check( 'bad nonce' === $e->getMessage() && 'keel-posture-export-3' === $GLOBALS['nonce_action'], 'Nonce binds export to the current site.' ); }
$GLOBALS['multisite'] = true;
check( ! keel_defaults_can_export_posture(), 'Multisite requires network-management authority.' );
$GLOBALS['caps']['manage_network_options'] = true;
check( keel_defaults_can_export_posture(), 'Super admin can explicitly export the current site.' );
$GLOBALS['pagenow']        = 'index.php';
$_GET['action']            = 'keel_posture_export';
$_SERVER['REQUEST_METHOD'] = 'GET';
keel_defaults_dispatch_posture_export();
$GLOBALS['pagenow'] = 'admin-post.php';
try {
	keel_defaults_dispatch_posture_export();
	check( false, 'Early dispatch must reject GET.' );
} catch ( RuntimeException $e ) {
	check( 'denied:405' === $e->getMessage(), 'Only the intended admin-post request dispatches early.' ); }
echo "OK: sanitized posture export projection and authorization.\n";
