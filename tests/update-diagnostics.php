<?php
/** Site Health update evidence composition and authority boundaries. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['caps']  = array(
	'view_site_health_checks' => true,
	'update_core'             => true,
);
$GLOBALS['multi'] = false;
$GLOBALS['reads'] = 0;
function __( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function is_multisite() { return $GLOBALS['multi']; }
function keel_defaults_branch_tip() { ++$GLOBALS['reads'];
	return false; }
function keel_defaults_ladder_selection() { return false; }
function keel_defaults_minor_update_state( $tip ) { return array(
	'operable' => true,
	'blockers' => array(),
); }
function keel_defaults_selection_state( $state, $selected ) { return 'unknown'; }
function get_site_transient( $key ) { return false; }
function get_site_option( $key ) { return false; }
function keel_defaults_cron_updates( ...$args ) { return array(
	'offer_age' => null,
	'failure'   => null,
); }
function keel_defaults_cron_snapshot() { return false; }
function keel_defaults_render_extension_updates() { echo '<table id="extension-fixture"></table>'; }
function wp_remote_get( ...$args ) { throw new RuntimeException( 'No HTTP in composition.' ); }
function wp_schedule_single_event( ...$args ) { throw new RuntimeException( 'No collectors from composition.' ); }
function check( $condition, $message ) { if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/update-timeline.php';
$result = keel_defaults_site_health_update_diagnostics();
check( 'keel_update_diagnostics' === $result['test'] && false !== strpos( $result['description'], 'Automatic core updates' ) && false !== strpos( $result['description'], 'extension-fixture' ), 'Both evidence tables belong to the Site Health result.' );
check( false !== strpos( $result['description'], 'The following tables contain update forensics for troubleshooting. They do not provide a health verdict or an installation schedule.' ), 'Informational group is not a successful-update claim.' );
foreach ( array( 'view_site_health_checks', 'update_core' ) as $cap ) {
	$GLOBALS['caps'][ $cap ] = false;
	$before                  = $GLOBALS['reads'];
	check( '' === keel_defaults_site_health_update_diagnostics()['description'] && $before === $GLOBALS['reads'], 'Denied authority reads no shared evidence.' );
	$GLOBALS['caps'][ $cap ] = true;
}
$GLOBALS['multi'] = true;
$before           = $GLOBALS['reads'];
check( '' === keel_defaults_site_health_update_diagnostics()['description'] && $before === $GLOBALS['reads'], 'Multisite site managers receive no network evidence.' );
$GLOBALS['caps']['manage_network_options'] = true;
check( '' !== keel_defaults_site_health_update_diagnostics()['description'], 'Network managers retain evidence access.' );
echo "OK: Site Health update diagnostics composition and authority.\n";
