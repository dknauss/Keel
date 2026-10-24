<?php
/** Actual extension decision counts, bounds, permissions and request reuse. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['site']  = 1;
$GLOBALS['multi'] = false;
$GLOBALS['caps']  = array_fill_keys( array( 'update_plugins', 'update_themes', 'activate_plugins', 'edit_theme_options' ), true );
$GLOBALS['calls'] = 0;
$GLOBALS['ids']   = array( 'one', 'two', 'three', 'unknown' );
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function is_multisite() { return $GLOBALS['multi']; }
function get_current_blog_id() { return $GLOBALS['site']; }
function get_plugins() { return array_fill_keys( $GLOBALS['ids'], array() ); }
function wp_get_themes() { return get_plugins(); }
function get_site_option( $key, $default = false ) { return array(); }
function wp_is_auto_update_enabled_for_type( $type ) { return true; }
function get_site_transient( $key ) {
	$type  = 'update_plugins' === $key ? 'plugin' : 'theme';
	$items = array();
	foreach ( array( 'one', 'two', 'three' ) as $id ) {
		$items[ $id ] = (object) array(
			$type        => $id,
			'autoupdate' => 'one' === $id,
		);
	}
	return (object) array( 'no_update' => $items );
}
function apply_filters( $hook, $baseline, $item ) {
	++$GLOBALS['calls'];
	$type = 'auto_update_plugin' === $hook ? 'plugin' : 'theme';
	return 'two' === $item->{$type};
}
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return esc_html( $url ); }
function number_format_i18n( $number ) { return (string) $number; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function network_admin_url( $path ) { return 'https://example.test/wp-admin/network/' . $path; }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/extension-updates.php';
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$html = keel_defaults_extension_update_counts_markup( $type );
	check( false !== strpos( $html, '1 enabled, ' ) && false !== strpos( $html, '1 unknown, 2 changed by code.' ), 'Observed non-zero counts include unknowns and changes as a subset.' );
	check( false !== strpos( $html, '/wp-admin/' . $type . 's.php?' . ( 'plugin' === $type ? 'plugin_status' : 'theme_status' ) . '=auto-update-disabled' ) && 1 === substr_count( $html, '<a ' ), 'Only disabled counts link to the native filtered list.' );
	$calls = $GLOBALS['calls'];
	keel_defaults_extension_update_counts_markup( $type );
	check( $calls === $GLOBALS['calls'], 'Repeated rendering does not rerun item filters.' );
}
$GLOBALS['caps']['update_plugins'] = false;
check( '' === keel_defaults_extension_update_counts_markup( 'plugin' ), 'Cached reports do not bypass authority.' );
$GLOBALS['caps']['update_plugins'] = true;
$GLOBALS['multi']                  = true;
check( '' === keel_defaults_extension_update_counts_markup( 'plugin' ), 'Site managers cannot read network decisions.' );
$GLOBALS['caps']['manage_network_options'] = true;
$html                                      = keel_defaults_extension_update_counts_markup( 'plugin' );
check( false === strpos( $html, '<a ' ), 'No inaccessible network list link.' );
$GLOBALS['caps']['manage_network_plugins'] = true;
check( false !== strpos( keel_defaults_extension_update_counts_markup( 'plugin' ), '/network/plugins.php' ), 'Network managers link to the network list.' );
$GLOBALS['site'] = 2;
$GLOBALS['ids']  = range( 1, 201 );
$html            = keel_defaults_extension_update_counts_markup( 'plugin' );
check( false !== strpos( $html, '200 unknown' ) && false !== strpos( $html, 'First 200 items only; counts are partial.' ), 'Bounded counts identify partial coverage.' );
check( '' === keel_defaults_extension_update_counts_markup( 'invalid' ), 'Invalid types are rejected.' );
echo "OK: extension auto-update summaries.\n";
