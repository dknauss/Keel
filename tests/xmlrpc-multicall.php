<?php
/**
 * The multicall refusal does not cut a Jetpack site off from WordPress.com.
 *
 * Found on a live Jetpack site with Keel's shipped default: WordPress.com's
 * calls into the site failed with "transport error - HTTP status code was not
 * 200 (405)". Two faults stacked.
 *
 * 1. WordPress.com's server-to-site requests arrive as system.multicall, and
 *    Keel refused every multicall. The site still reported itself connected.
 * 2. The refusal used fault code 405. With remote publishing off (the default)
 *    xmlrpc_enabled is false, and wp_xmlrpc_server::error() then sends the fault
 *    code as the HTTP status — so the client saw a transport failure, not a
 *    fault it could read.
 *
 * So: a request Jetpack itself verifies as signed by WordPress.com is let
 * through, everything else is still refused, and the refusal is an ordinary
 * fault whose code is not an HTTP status. The query argument for=jetpack is
 * never what decides it; anyone can send that.
 *
 * Run: php tests/xmlrpc-multicall.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $number, $d = null ) { return ( 1 === (int) $number ) ? $single : $plural; }
function number_format_i18n( $n ) { return (string) $n; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function is_multisite() {
	return false;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** Enough of WP_Error for is_wp_error(). */
class WP_Error {}

/** Enough of IXR_Error to read back what was refused. */
class IXR_Error {
	public $code;
	public $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

/** The parent whose multiCall() runs when a request is let through. */
class wp_xmlrpc_server { // phpcs:ignore PEAR.NamingConventions.ValidClassName, Generic.Classes.OpeningBraceSameLine -- Core's own class name.
	public function multiCall( $methodcalls ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Core's own method name.
		return array( 'ran' => count( $methodcalls ) );
	}
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

$calls = array( array( 'methodName' => 'jetpack.testConnection' ), array( 'methodName' => 'jetpack.jsonAPI' ) );

// --- Jetpack absent ---------------------------------------------------------

keel_assert( false === keel_defaults_jetpack_request_verified(), 'With Jetpack absent, no request counts as verified.' );
keel_assert( '' === keel_defaults_jetpack_xmlrpc_state(), 'With Jetpack absent, there is no Jetpack XML-RPC state to report.' );

require dirname( __DIR__ ) . '/includes/class-keel-multicall-disabled-server.php';
$server = new Keel_Multicall_Disabled_Server();

$_GET['for'] = 'jetpack';
$refused     = $server->multiCall( $calls );
keel_assert( $refused instanceof IXR_Error, 'With Jetpack absent, multicall is refused even when the request says for=jetpack.' );

// --- the refusal is a fault a client can read -------------------------------

keel_assert( -32601 === $refused->code, 'The refusal uses the XML-RPC "method not found" fault code.' );
keel_assert( KEEL_DEFAULTS_MULTICALL_FAULT === $refused->code, 'And that code is the named constant.' );
keel_assert(
	$refused->code < 100 || $refused->code > 599,
	'The fault code is not an HTTP status: with xmlrpc_enabled false, core sends the fault code as the status, and 405 read as a transport failure.'
);

// --- a Jetpack that cannot verify requests ----------------------------------

// Active, but without the connection manager Keel asks: nothing can tell a
// signed request from any other, so multicall stays refused and Site Health
// has to say so rather than let the connection fail quietly.
define( 'JETPACK__VERSION', '6.0' );
keel_assert( 'refused' === keel_defaults_jetpack_xmlrpc_state(), 'An active Jetpack that Keel cannot verify requests for is reported as refused.' );
$result = keel_defaults_site_health_jetpack_xmlrpc();
keel_assert( 'recommended' === $result['status'], 'And Site Health raises it.' );
keel_assert( false !== strpos( $result['description'], 'XML-RPC Multicall' ), 'Naming the setting that lets it through.' );

// --- Jetpack present --------------------------------------------------------

require __DIR__ . '/fixtures/jetpack-manager-stub.php';

use Automattic\Jetpack\Connection\Manager as Keel_Test_Jetpack;

Keel_Test_Jetpack::$verified = false;
keel_assert( false === keel_defaults_jetpack_request_verified(), 'An unsigned request is not verified.' );
keel_assert( $server->multiCall( $calls ) instanceof IXR_Error, 'On a Jetpack site, an unsigned multicall is still refused — for=jetpack alone decides nothing.' );

Keel_Test_Jetpack::$verified = new WP_Error();
keel_assert( false === keel_defaults_jetpack_request_verified(), 'A verification error is not a verification.' );

Keel_Test_Jetpack::$verified = array();
keel_assert( false === keel_defaults_jetpack_request_verified(), 'An empty result is not a verification.' );

Keel_Test_Jetpack::$verified = array(
	'type'    => 'blog',
	'user_id' => 0,
);
keel_assert( true === keel_defaults_jetpack_request_verified(), 'A request Jetpack verifies as signed is verified.' );
keel_assert( array( 'ran' => 2 ) === $server->multiCall( $calls ), 'A signed Jetpack multicall runs core\'s own multiCall().' );

unset( $_GET['for'] );
keel_assert( array( 'ran' => 2 ) === $server->multiCall( $calls ), 'The signature decides it; the for=jetpack argument is not consulted at all.' );

Keel_Test_Jetpack::$throws = true;
keel_assert( false === keel_defaults_jetpack_request_verified(), 'If Jetpack\'s verifier throws, the request is not verified.' );
keel_assert( $server->multiCall( $calls ) instanceof IXR_Error, 'And multicall is refused rather than the request dying.' );
Keel_Test_Jetpack::$throws = false;

// --- what Site Health reports -----------------------------------------------

$GLOBALS['keel_options']['keel_settings'] = array();
keel_assert( 'verified' === keel_defaults_jetpack_xmlrpc_state(), 'Default settings on a connected Jetpack site: multicall is refused except for signed Jetpack requests.' );

$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_multicall' => 'yes' );
keel_assert( 'open' === keel_defaults_jetpack_xmlrpc_state(), 'With multicall allowed there is nothing refused.' );

$GLOBALS['keel_options']['keel_settings'] = array( 'block_xmlrpc_endpoint' => 'yes' );
keel_assert( 'blocked' === keel_defaults_jetpack_xmlrpc_state(), 'Blocking the endpoint refuses Jetpack whatever the multicall setting says.' );

$GLOBALS['keel_options']['keel_settings'] = array();
Keel_Test_Jetpack::$connected             = false;
keel_assert( '' === keel_defaults_jetpack_xmlrpc_state(), 'Jetpack installed but not connected has no connection to cut off.' );
Keel_Test_Jetpack::$connected = true;

$tests = keel_defaults_site_health_tests( array( 'direct' => array() ) );
keel_assert( isset( $tests['direct']['keel_defaults_jetpack_xmlrpc'] ), 'On a connected Jetpack site the check is registered.' );

$result = keel_defaults_site_health_jetpack_xmlrpc();
keel_assert( 'good' === $result['status'], 'Signed Jetpack requests getting through is a passing result.' );

$GLOBALS['keel_options']['keel_settings'] = array( 'block_xmlrpc_endpoint' => 'yes' );
$result                                   = keel_defaults_site_health_jetpack_xmlrpc();
keel_assert( 'critical' === $result['status'], 'A connected Jetpack site with the endpoint blocked is critical: WordPress.com cannot reach it.' );
keel_assert( false !== strpos( $result['description'], 'XML-RPC Endpoint' ), 'And the result names the setting to change.' );
$GLOBALS['keel_options']['keel_settings'] = array();

fwrite( STDOUT, "xmlrpc multicall tests passed.\n" );
