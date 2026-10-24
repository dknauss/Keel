<?php
/** Static update disclosure must not inspect or capture site data. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['wp_version']     = '7.1.3';
$GLOBALS['keel_multisite'] = false;
$GLOBALS['keel_caps']      = array(
	'view_site_health_checks' => true,
	'manage_network_options'  => true,
);
function current_user_can( $cap ) { return ! empty( $GLOBALS['keel_caps'][ $cap ] ); }
function is_multisite() { return $GLOBALS['keel_multisite']; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( $url ) { return $url; }
function wp_kses( $text, $allowed_html ) { return $text; }
function get_option( ...$args ) { throw new RuntimeException( 'No site option reads.' ); }
function get_site_option( ...$args ) { throw new RuntimeException( 'No network option reads.' ); }
function get_plugins() { throw new RuntimeException( 'No inventory.' ); }
function wp_get_themes() { throw new RuntimeException( 'No inventory.' ); }
function wp_remote_post( ...$args ) { throw new RuntimeException( 'No HTTP.' ); }
function update_option( ...$args ) { throw new RuntimeException( 'No storage.' ); }
function add_action( ...$args ) { throw new RuntimeException( 'Declaration-only.' ); }
function apply_filters( ...$args ) { throw new RuntimeException( 'No request/policy filters.' ); }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
$module = dirname( __DIR__ ) . '/includes/update-disclosure.php';
if ( ! is_file( $module ) ) {
	fwrite( STDERR, "FAIL: update-check disclosure missing.\n" );
	exit( 1 );
}
require_once $module;
$info    = keel_defaults_update_disclosure_info( array() );
$section = $info['keel_update_disclosure'];
$text    = json_encode( $section, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone test without WordPress.
check( false !== strpos( $text, 'core/version-check/1.7/' ) && false !== strpos( $text, 'plugins/update-check/1.1/' ) && false !== strpos( $text, 'themes/update-check/1.1/' ), 'All three known endpoints.' );
check( false !== strpos( $text, 'not evidence' ) && false !== strpos( $text, 'inactive' ), 'Disclosure distinguishes baseline from transmission and includes inactive inventory category.' );
check( false !== strpos( $section['description'], '<a href="https://developer.wordpress.org/reference/functions/wp_version_check/" target="_blank" rel="noopener noreferrer">Core version check</a>' ) && false !== strpos( $section['description'], 'wp_update_plugins/' ) && false !== strpos( $section['description'], 'wp_update_themes/' ), 'Core source references are labeled external links, not raw URLs.' );
check( false !== strpos( $section['fields']['themes']['value'], 'author names and author/update URIs' ) && false === strpos( $section['fields']['themes']['value'], 'compatibility metadata' ), 'Theme categories match core request arrays.' );
check( false === strpos( $text, '7.1.3' ), 'No installed version value exported.' );
foreach ( array( '6.4', '6.5.5', '6.8.1', '6.9.2', '7.0', '7.1.3' ) as $version ) {
	$GLOBALS['wp_version'] = $version;
	check( isset( keel_defaults_update_disclosure_info( array() )['keel_update_disclosure']['fields']['core'] ), 'Supported stable family has documented categories.' );
}
foreach ( array( '7.2', '7.2-alpha1', '7.1-RC1', '6.3.9', '<script>bad</script>', array(), null ) as $version ) {
	$GLOBALS['wp_version'] = $version;
	$fields                = keel_defaults_update_disclosure_info( array() )['keel_update_disclosure']['fields'];
	check( ! isset( $fields['core'] ) && false !== strpos( $fields['coverage']['value'], 'unverified' ), 'Unknown/development family never presented as audited baseline.' );
}
$GLOBALS['wp_version'] = '7.1.3';
$GLOBALS['keel_caps']  = array();
check( array( 'existing' => true ) === keel_defaults_update_disclosure_info( array( 'existing' => true ) ), 'Unauthorized sees no disclosure.' );
$GLOBALS['keel_caps']      = array( 'view_site_health_checks' => true );
$GLOBALS['keel_multisite'] = true;
check( array() === keel_defaults_update_disclosure_info( array() ), 'Subsite authority alone cannot access network-context disclosure.' );
$GLOBALS['keel_caps']['manage_network_options'] = true;
check( isset( keel_defaults_update_disclosure_info( array() )['keel_update_disclosure'] ), 'Network manager may view static disclosure.' );
check( null === keel_defaults_update_disclosure_info( null ), 'Malformed filter input preserved.' );
echo "OK: static update-check disclosure boundaries.\n";
