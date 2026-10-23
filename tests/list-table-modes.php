<?php
/** Three modes, legacy read fallback and network policy precedence. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'KEEL_DEFAULTS_OPTION', 'keel_settings' );
define( 'KEEL_DEFAULTS_NETWORK_OPTION', 'keel_network_settings' );
$GLOBALS['mode_site']      = array();
$GLOBALS['mode_network']   = array();
$GLOBALS['mode_multisite'] = false;
function is_multisite() { return $GLOBALS['mode_multisite']; }
function get_option( $key, $default = false ) { return $GLOBALS['mode_site']; }
function get_site_option( $key, $default = false ) { return $GLOBALS['mode_network']; }
function add_action( ...$args ) {}
function __( $s, $domain = '' ) { return $s; }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/schema.php';
require dirname( __DIR__ ) . '/includes/network.php';
$s = keel_defaults_schema();
check( isset( $s['admin_list_tables'] ) && 45 === count( $s ), 'One three-mode setting replaces four toggles; 45 total.' );
check( array( 'unchanged', 'tidy', 'tidy_helpers' ) === $s['admin_list_tables']['choices'], 'Three explicit choices.' );
check( 'unchanged' === keel_defaults_get( 'admin_list_tables' ), 'Default unchanged.' );
foreach ( array( 'helper_list_columns', 'compact_column_labels', 'fewer_list_columns', 'tidy_date_columns' ) as $old ) {
	check( ! isset( $s[ $old ] ), 'Obsolete control removed.' );
	$GLOBALS['mode_site'] = array( $old => 'yes' );
	check( ( 'helper_list_columns' === $old ? 'tidy_helpers' : 'tidy' ) === keel_defaults_get( 'admin_list_tables' ), 'Legacy enabled choice respected without writes.' );
}
$GLOBALS['mode_site']['admin_list_tables'] = 'unchanged';
check( 'unchanged' === keel_defaults_get( 'admin_list_tables' ), 'Explicit mode wins over stale legacy choice.' );
$GLOBALS['mode_site']['admin_list_tables'] = array( 'bad' );
check( 'unchanged' === keel_defaults_get( 'admin_list_tables' ), 'Invalid explicit mode fails closed.' );
$GLOBALS['mode_multisite'] = true;
$GLOBALS['mode_site']      = array( 'admin_list_tables' => 'tidy_helpers' );
$GLOBALS['mode_network']   = array( 'helper_list_columns' => 'no' );
check( 'unchanged' === keel_defaults_get( 'admin_list_tables' ) && keel_defaults_network_manages( 'admin_list_tables' ), 'Legacy network policy remains authoritative and locked.' );
$GLOBALS['mode_network'] = array(
	'admin_list_tables'   => 'tidy',
	'helper_list_columns' => 'yes',
);
check( 'tidy' === keel_defaults_get( 'admin_list_tables' ), 'Explicit new network choice wins.' );
check(
	array(
		'field'     => 'admin_list_tables',
		'hide_when' => 'unchanged',
	) === $s['date_columns_show_time']['depends'],
	'Show times depends on either tidy mode.'
);
echo "OK: three list-table modes and compatible read resolution.\n";
