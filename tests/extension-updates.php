<?php
/** Plugin/theme policy preserves core choices unless explicitly configured. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'KEEL_DEFAULTS_OPTION', 'keel_settings' );
$GLOBALS['settings'] = array();
$GLOBALS['network']  = false;
function keel_defaults_get( $key ) { return $GLOBALS['settings'][ $key ] ?? 'unset'; }
function is_multisite() { return $GLOBALS['network']; }
function is_main_site() { return false; }
function get_main_site_id() { return 1; }
function get_blog_option( $id, $key, $default = array() ) { return array(
	'plugin_auto_update_policy' => 'disabled',
	'theme_auto_update_policy'  => 'enabled',
); }
function keel_defaults_network_settings() { return $GLOBALS['network_settings'] ?? array(); }
function keel_defaults_background_updates_off() { return $GLOBALS['locked'] ?? null; }
function __( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function current_user_can( $cap ) { return $GLOBALS['allowed'] ?? false; }
function esc_attr( $s ) { return esc_html( $s ); }
function get_plugins() { return $GLOBALS['inventories']['plugin']; }
function wp_get_themes() { return $GLOBALS['inventories']['theme']; }
function get_site_transient( $key ) { return $GLOBALS['caches'][ $key ]; }
function get_site_option( $key, $default = false ) { return $default; }
function wp_is_auto_update_enabled_for_type( $type ) { return true; }
function apply_filters( $hook, $value, $item ) { return $GLOBALS['override'] ?? $value; }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
require __DIR__ . '/../includes/extension-updates.php';
require __DIR__ . '/../includes/update-timeline.php';
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$key = $type . '_auto_update_policy';
	foreach ( array( true, false, null ) as $incoming ) {
		check( keel_defaults_extension_update_decision( $type, $incoming ) === $incoming, 'Missing setting preserves the incoming value, including core null sentinel.' );
	}
	$GLOBALS['settings'][ $key ] = 'enabled';
	check( true === keel_defaults_extension_update_decision( $type, false ), 'Explicit enable overrides per-item opt out.' );
	$GLOBALS['settings'][ $key ] = 'disabled';
	check( false === keel_defaults_extension_update_decision( $type, true ), 'Explicit disable overrides per-item opt in.' );
	$GLOBALS['settings'][ $key ] = 'unset';
}
$GLOBALS['locked']                                = 'DISALLOW_FILE_MODS';
$GLOBALS['settings']['plugin_auto_update_policy'] = 'enabled';
check( null === keel_defaults_allow_plugin_updates( null ), 'Configuration lock preserves core sentinel instead of pretending to force updates.' );
$GLOBALS['locked']  = null;
$GLOBALS['network'] = true;
check( false === keel_defaults_allow_plugin_updates( true ), 'A subsite cannot override main-site policy for shared code.' );
$GLOBALS['network_settings']['plugin_auto_update_policy'] = 'enabled';
check( true === keel_defaults_allow_plugin_updates( false ), 'Network policy wins over main site.' );
$GLOBALS['network_settings']['plugin_auto_update_policy'] = 'invalid';
check( null === keel_defaults_allow_plugin_updates( null ), 'Invalid stored policy fails to unchanged.' );
ob_start();
keel_defaults_render_extension_updates();
$html = ob_get_clean();
check( '' === $html, 'Unauthorized report reads no cached inventory.' );
$cache  = (object) array(
	'response'  => array( 'p/p.php' => (object) array( 'plugin' => 'p/p.php' ) ),
	'no_update' => array(
		't' => array(
			'theme'              => 't',
			'disable_autoupdate' => true,
		),
	),
);
$report = keel_defaults_extension_update_report( 'plugin', array( 'p/p.php', 'missing' ), $cache, array( 'p/p.php' ), true );
check( 1 === $report['on'] && 1 === $report['unknown'] && 0 === $report['changed_count'], 'Per-item opt-ins survive and missing cache stays unknown.' );
$GLOBALS['override'] = false;
$report              = keel_defaults_extension_update_report( 'plugin', array( 'p/p.php' ), $cache, array( 'p/p.php' ), true );
check( 0 === $report['on'] && 'p/p.php' === $report['changed'][0]['id'], 'Later filter overriding opt-in is reported.' );
$GLOBALS['override'] = true;
$report              = keel_defaults_extension_update_report( 'theme', array( 't' ), $cache, array( 't' ), true );
check( 1 === $report['on'] && 1 === $report['changed_count'], 'Filter can override cached disable flag; report agrees with core baseline.' );
$report = keel_defaults_extension_update_report( 'theme', array_fill( 0, 220, 't' ), $cache, array(), false );
check( 200 === $report['examined'] && 10 === count( $report['changed'] ) && $report['limited'], 'Report bounds apply independently to evaluation and details.' );
$report = keel_defaults_extension_update_report( 'plugin', array( 'p/p.php' ), (object) array( 'response' => (object) array() ), array(), true );
check( 1 === $report['unknown'], 'Malformed cached collections stay unknown.' );
$GLOBALS['allowed'] = true;
$GLOBALS['network'] = false;
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$GLOBALS['inventories'][ $type ]              = array();
	$GLOBALS['caches'][ 'update_' . $type . 's' ] = (object) array( 'response' => array() );
	for ( $i = 1; $i <= 11; ++$i ) {
		$id                                     = $type . '-' . $i;
		$GLOBALS['inventories'][ $type ][ $id ] = array();
		$GLOBALS['caches'][ 'update_' . $type . 's' ]->response[ $id ] = (object) array( $type => $id );
	}
}
ob_start();
keel_defaults_render_extension_updates();
$html = ob_get_clean();
check( false !== strpos( $html, 'up to ten per type are listed' ), 'Changed-item caveat describes the per-type limit.' );
check( false !== strpos( $html, esc_html( 'a plugin, theme or Keel setting overrides WordPress’s baseline decision; up to ten per type are listed.' ) ) && false === strpos( $html, esc_html( 'item’s own toggle' ) ), 'Changed decisions are compared with the combined WordPress baseline, not only the item toggle.' );
check( 20 === substr_count( $html, 'Turned on by code' ), 'Ten plugins and ten themes are displayed together.' );
foreach ( array( 'plugin', 'theme' ) as $type ) {
	check( false !== strpos( $html, '<code>' . $type . '-10</code>' ) && false === strpos( $html, '<code>' . $type . '-11</code>' ), 'Each type independently retains its first ten changed items.' );
}
echo "OK: plugin/theme update policy boundaries.\n";
