<?php
/** Adversarial checks of legacy action authority and malformed settings. */
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function apply_filters( $hook, $value ) { return $value; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function is_multisite() { return ! empty( $GLOBALS['multisite'] ); }
function wp_roles() { return (object) array( 'roles' => array() ); }
function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['denial_status'] = is_array( $args ) ? ( $args['response'] ?? 500 ) : $args;
	throw new RuntimeException( 'denied' );
}
function check_admin_referer( $action ) {
	$GLOBALS['events'][] = 'nonce';
	if ( empty( $GLOBALS['nonce'] ) ) {
		throw new RuntimeException( 'nonce' ); }
}
function update_site_option( $key, $value ) {
	$GLOBALS['events'][] = 'write';
	throw new RuntimeException( 'write' );
}
function check_ajax_referer( $action, $field = false ) {
	check_admin_referer( $action );
}
function wp_send_json_error( $data, $status ) { throw new RuntimeException( 'denied' ); }
function wp_send_json_success( $data ) { throw new RuntimeException( 'response' ); }
function boundary_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/keel.php';
require_once dirname( __DIR__ ) . '/includes/backport-actions.php';
require_once dirname( __DIR__ ) . '/includes/backport-presentation.php';

foreach ( array( 'keel_defaults_backport_ajax', 'keel_defaults_dismiss_backport_notice' ) as $handler ) {
	foreach ( array( array( false, true, 'denied' ), array( true, false, 'nonce' ) ) as $case ) {
		list( $authorized, $GLOBALS['nonce'], $expected ) = $case;
		$GLOBALS['caps']                                  = $authorized ? array(
			'update_core'             => true,
			'view_site_health_checks' => true,
		) : array();
		try {
			$handler();
			throw new RuntimeException( 'Handler returned without rejecting request.' );
		} catch ( RuntimeException $error ) {
			boundary_assert( $expected === $error->getMessage(), $handler . ' rejects before reading or writing evidence.' );
		}
	}
}

foreach ( array(
	array( false, array(), true, 'denied', array() ),
	array( true, array( 'update_core' => true ), true, 'denied', array() ),
	array( true, array( 'manage_network_options' => true ), true, 'denied', array() ),
	array(
		true,
		array(
			'update_core'            => true,
			'manage_network_options' => true,
		),
		false,
		'nonce',
		array( 'nonce' ),
	),
	array( false, array( 'update_core' => true ), false, 'nonce', array( 'nonce' ) ),
	array( false, array( 'update_core' => true ), true, 'write', array( 'nonce', 'write' ) ),
	array(
		true,
		array(
			'update_core'            => true,
			'manage_network_options' => true,
		),
		true,
		'write',
		array( 'nonce', 'write' ),
	),
) as $case ) {
	list( $GLOBALS['multisite'], $GLOBALS['caps'], $GLOBALS['nonce'], $expected, $events ) = $case;
	$GLOBALS['events'] = array();
	$intercepted       = false;
	try {
		keel_defaults_handle_enable_minor_updates();
	} catch ( RuntimeException $error ) {
		$intercepted = true;
		boundary_assert( $expected === $error->getMessage(), 'Unexpected authority result: ' . $error->getMessage() );
	}
	boundary_assert( $intercepted, 'Action must reject or reach the intercepted write.' );
	if ( 'denied' === $expected ) {
		boundary_assert( 403 === $GLOBALS['denial_status'], 'Minor-update authority denial is HTTP 403.' );
	}
	boundary_assert( $events === $GLOBALS['events'], 'Denied action reached nonce or mutation.' );
}

$GLOBALS['multisite'] = true;
$GLOBALS['caps']      = array( 'update_core' => true );
boundary_assert( '' === keel_defaults_backport_actions( '7.1.3' ), 'Delegated site admin is not offered network actions.' );
$_POST['keel_network_nonce'] = 'hostile';
foreach ( array( array( false, true, 'denied' ), array( true, false, 'nonce' ) ) as $case ) {
	list( $authorized, $GLOBALS['nonce'], $expected ) = $case;
	$GLOBALS['caps']                                  = $authorized ? array( 'manage_network_options' => true ) : array();
	$GLOBALS['events']                                = array();
	try {
		keel_defaults_handle_network_save();
		throw new RuntimeException( 'Network save returned without rejecting request.' );
	} catch ( RuntimeException $error ) {
		boundary_assert( $expected === $error->getMessage(), 'Network save rejects before mutation.' );
	}
	boundary_assert( ! in_array( 'write', $GLOBALS['events'], true ), 'Rejected network save has no writes.' );
	if ( 'denied' === $expected ) {
		boundary_assert( 403 === $GLOBALS['denial_status'], 'Network authority denial is HTTP 403.' );
	}
}
unset( $_POST['keel_network_nonce'] );

// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- warnings are test failures for hostile payloads.
set_error_handler(
	function ( $severity, $message ) { throw new RuntimeException( $message );
	}
);
$schema   = keel_defaults_schema();
$baseline = keel_defaults_sanitize( array() );
foreach ( $schema as $key => $field ) {
	if ( ! in_array( $field['type'], array( 'number', 'range', 'multiselect', 'select' ), true ) ) {
		continue; }
	foreach ( array( array( array( 'hostile' ) ), new stdClass() ) as $invalid ) {
		$clean = keel_defaults_sanitize( array( $key => $invalid ) );
		boundary_assert( $baseline[ $key ] === $clean[ $key ], 'Malformed field falls back to its safe default: ' . $key );
	}
}
restore_error_handler();
echo "PHP boundaries: authority, nonce ordering and malformed settings passed.\n";
