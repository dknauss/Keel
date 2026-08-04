<?php
/**
 * Structural guard for the rendered settings screen.
 *
 * The screen is the plugin's whole interface, and until now nothing rendered it
 * except the heading-case guard. That mattered when the range field was pulled
 * out of the template: the only way to be confident a 105-line extraction had
 * changed nothing was to render before and after and compare — which needed a
 * harness that did not exist yet.
 *
 * This is that harness, kept. It asserts what the screen must contain rather
 * than an exact byte count, because a snapshot hash fails on every deliberate
 * edit and tells you nothing about which part moved.
 *
 * Run: php tests/settings-render.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) {
	return $s; }
function esc_html( $s ) {
	return $s; }
function esc_html__( $s, $d = null ) {
	return $s; }
function esc_html_e( $s, $d = null ) {
	echo $s; }
function esc_attr( $s ) {
	return $s; }
function esc_attr_e( $s, $d = null ) {
	echo $s; }
function esc_js( $s ) {
	return $s; }
function esc_url( $s ) {
	return $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function is_multisite() {
	return false; }
function current_user_can( $cap ) {
	return true; }
function settings_fields( $g ) {}
function submit_button( ...$a ) {}
// Real WP echoes by default and only returns when $echo is false. A stub that
// merely returns silently drops every checked/selected attribute on the screen.
function checked( $a, $b = true, $echo = true ) {
	$out = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function disabled( $a, $b = true, $echo = true ) {
	$out = ( $a === $b ) ? ' disabled' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function selected( $a, $b = true, $echo = true ) {
	$out = ( (string) $a === (string) $b ) ? ' selected' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function wp_kses( $s, $allowed = array() ) {
	return $s; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this stub is what stands in for wp_json_encode().
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function translate_user_role( $n ) {
	return $n; }
function wp_roles() {
	return new Keel_Render_Test_Roles(); }

class Keel_Render_Test_Roles {
	public $roles = array(
		'subscriber' => array(
			'name'         => 'Subscriber',
			'capabilities' => array( 'read' => true ),
		),
		'customer'   => array(
			'name'         => 'Customer',
			'capabilities' => array( 'read' => true ),
		),
	);
}

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/keel.php';

$fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * Render the screen with a given stored option array.
 *
 * @param array $options Stored settings.
 * @return string
 */
function keel_render( $options ) {
	$GLOBALS['keel_options'] = $options ? array( KEEL_DEFAULTS_OPTION => $options ) : array();
	ob_start();
	keel_defaults_render_settings_page();
	return ob_get_clean();
}

/*
 * Two states, because most of the screen's branches are invisible in one.
 * Defaults exercise the unlocked, undependent path; the configured state opens
 * the sections whose dependents hide, moves every non-toggle off its default,
 * and selects a role.
 */
$stock      = keel_render( array() );
$configured = keel_render(
	array(
		'disable_rest'          => 'yes',
		'block_xmlrpc_endpoint' => 'yes',
		'admin_menu_width'      => '200',
		'session_regular_days'  => 5,
		'remember_me_days'      => 30,
		'frame_options'         => 'DENY',
		'password_exempt_roles' => array( 'subscriber' ),
		'disable_remember_me'   => 'yes',
	)
);

foreach ( array(
	'stock'      => $stock,
	'configured' => $configured,
) as $state => $html ) {
	keel_assert( '' !== trim( $html ), "The screen renders in the {$state} state." );

	// Every schema key reaches the form. This is the assertion that would have
	// caught the extraction dropping a field type on the floor.
	foreach ( array_keys( keel_defaults_schema() ) as $key ) {
		keel_assert(
			false !== strpos( $html, KEEL_DEFAULTS_OPTION . '[' . $key . ']' ),
			"[{$state}] Setting '{$key}' has an input on the screen."
		);
	}

	// One control of each type actually rendered, so a branch cannot quietly
	// stop emitting anything.
	keel_assert( false !== strpos( $html, 'type="checkbox"' ), "[{$state}] Toggles render as checkboxes." );
	keel_assert( false !== strpos( $html, '<select ' ), "[{$state}] Selects render." );
	keel_assert( false !== strpos( $html, 'type="number"' ), "[{$state}] Number fields render." );
	keel_assert( false !== strpos( $html, 'type="range"' ), "[{$state}] The range field renders." );

	// The range field's preview is a feature, not decoration: the slider is
	// useless without the stylesheet it toggles and the script that drives it.
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-range"' ), "[{$state}] The range input carries its id." );

	/*
	 * The datalist, the readout and the input are wired to each other by id, and
	 * a broken link between them is silent: the slider still drags, it just
	 * stops showing labels or stops previewing. So assert the element that
	 * *defines* each id, not merely that the string appears somewhere — the
	 * input's own list= and for= attributes contain those strings too, which is
	 * enough to make a careless assertion pass while the target is gone.
	 */
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-stops"' ), "[{$state}] The datalist of stops exists with the id the slider points at." );
	keel_assert( false !== strpos( $html, 'list="admin_menu_width-stops"' ), "[{$state}] The slider points at that datalist." );
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-output"' ), "[{$state}] The live readout exists with the id the slider points at." );
	keel_assert( false !== strpos( $html, 'for="admin_menu_width-range"' ), "[{$state}] The readout is associated with the slider." );
	keel_assert( false !== strpos( $html, 'keel-menu-width-preview' ), "[{$state}] The range field emits its preview styles." );
	keel_assert( false !== strpos( $html, "getElementById( 'admin_menu_width-range' )" ), "[{$state}] The range field emits the script that drives the preview." );

	// Accessible structure.
	keel_assert( substr_count( $html, '<th scope="row">' ) > 25, "[{$state}] Every row carries a heading." );
	keel_assert( false !== strpos( $html, 'screen-reader-text', ), "[{$state}] Fieldsets carry screen-reader legends." );
}

// --- state-dependent behaviour ---

// The multiselect offers only the low-privilege roles wp_roles() defines.
keel_assert( false !== strpos( $stock, 'value="subscriber"' ), 'The exemptions control offers subscriber.' );
keel_assert( false !== strpos( $stock, 'value="customer"' ), 'The exemptions control offers customer.' );

// A stored selection is reflected back.
keel_assert(
	preg_match( '/value="subscriber"[^>]* checked/', $configured ),
	'A stored role exemption renders checked.'
);

// A dependent row starts hidden when its controlling setting says so:
// remember_me_days depends on disable_remember_me, which is on in the
// configured state.
keel_assert(
	preg_match( '/data-keel-dep-field="disable_remember_me"[^>]*style="display:none;"/', $configured ),
	'A dependent row starts hidden when its controlling setting hides it.'
);
keel_assert(
	! preg_match( '/data-keel-dep-field="disable_remember_me"[^>]*style="display:none;"/', $stock ),
	'That same row is visible when the controlling setting does not hide it.'
);

// A non-default select value is selected, not silently reset.
keel_assert(
	preg_match( '/value="DENY"[^>]* selected/', $configured ),
	'A configured select value renders as the selected option.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "settings render: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'settings render: OK (two states, ' . count( keel_defaults_schema() ) . " settings each)\n" );
