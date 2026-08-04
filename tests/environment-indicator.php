<?php
/**
 * Lightweight regression test for the environment indicator.
 *
 * Run: php tests/environment-indicator.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']  = array();
$GLOBALS['keel_env']      = 'production';
$GLOBALS['keel_home']     = 'https://example-real.ca';
$GLOBALS['keel_bar_show'] = true;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function wp_get_environment_type() { return $GLOBALS['keel_env']; }
function home_url() { return $GLOBALS['keel_home']; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function is_admin_bar_showing() { return $GLOBALS['keel_bar_show']; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); }
function wp_parse_args( $args, $defaults = array() ) { return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this stub is what stands in for wp_parse_url().
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Fake admin bar that records the added node.
class Keel_Test_Admin_Bar {
	public $node = null;
	public function add_menu( $args ) { $this->node = $args; }
}

// --- environments map ---
$envs = keel_environments();
foreach ( array( 'production', 'staging', 'development', 'local' ) as $t ) {
	keel_assert( isset( $envs[ $t ] ), "Environment '{$t}' is defined." );
}
keel_assert( '#b92a2a' === $envs['production']['background_color'], 'Production is red.' );

// Filter can override a colour without dropping the others (wp_parse_args merge).
$GLOBALS['keel_filters']['keel_environments'] = array(
	'production' => array(
		'label'            => 'PROD',
		'icon'             => 'dashicons-warning',
		'background_color' => '#000',
		'text_color'       => '#fff',
	),
);
$envs = keel_environments();
keel_assert( 'PROD' === $envs['production']['label'], 'keel_environments filter applies.' );
keel_assert( isset( $envs['staging'] ), 'Filtering one environment keeps the defaults for the rest.' );
unset( $GLOBALS['keel_filters']['keel_environments'] );

// --- environment detection ---
$GLOBALS['keel_env']  = 'production';
$GLOBALS['keel_home'] = 'https://mysite.test';
keel_assert( 'local' === keel_current_environment(), 'A .test host reads as local.' );
$GLOBALS['keel_home'] = 'https://mysite.local/';
keel_assert( 'local' === keel_current_environment(), 'A .local host reads as local.' );
$GLOBALS['keel_home'] = 'https://mysite.example-real.ca';
keel_assert( 'production' === keel_current_environment(), 'A normal host uses wp_get_environment_type().' );

// The case the whole-URL suffix test used to miss: an explicit port.
$GLOBALS['keel_home'] = 'http://mysite.test:8080';
keel_assert( 'local' === keel_current_environment(), 'A .test host on an explicit port still reads as local.' );

// Loopback, however it is written.
foreach ( array( 'http://localhost:8881', 'http://localhost', 'http://127.0.0.1:8000', 'http://[::1]:8080' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert( 'local' === keel_current_environment(), "Loopback home '{$home}' reads as local." );
}

// Tool-specific default domains.
foreach ( array( 'https://myproject.ddev.site', 'http://myapp.lndo.site:8000', 'http://myapp.localhost' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert( 'local' === keel_current_environment(), "Local-tool home '{$home}' reads as local." );
}

// A suffix must match a whole label, not a substring of the host.
$GLOBALS['keel_home'] = 'https://latest.example.ca';
keel_assert( 'production' === keel_current_environment(), 'A host merely containing "test" is not local.' );
$GLOBALS['keel_home'] = 'https://localhost.example.ca';
keel_assert( 'production' === keel_current_environment(), 'A real host beginning with "localhost" is not local.' );

// Case is not significant in host names.
$GLOBALS['keel_home'] = 'https://MySite.TEST';
keel_assert( 'local' === keel_current_environment(), 'Host matching is case-insensitive.' );

// The constant always wins — this is what makes Studio (localhost:PORT with
// WP_ENVIRONMENT_TYPE set) report through core rather than through this list.
$GLOBALS['keel_filters']['keel_local_host_suffixes'] = array( '.example-real.ca' );
$GLOBALS['keel_home']                                = 'https://mysite.example-real.ca';
keel_assert( 'local' === keel_current_environment(), 'The suffix list is filterable.' );
unset( $GLOBALS['keel_filters']['keel_local_host_suffixes'] );

// --- toolbar node ---
$GLOBALS['keel_env'] = 'staging';
$bar                 = new Keel_Test_Admin_Bar();
keel_defaults_environment_toolbar_item( $bar );
keel_assert( 'keel-environment-indicator' === $bar->node['id'], 'Toolbar node has the expected id.' );
keel_assert( 'top-secondary' === $bar->node['parent'], 'Node is placed in the secondary group.' );
keel_assert( false !== strpos( $bar->node['meta']['class'], 'keel-environment-indicator--staging' ), 'Node class carries the environment type.' );
keel_assert( false !== strpos( $bar->node['title'], 'Staging' ), 'Node shows the environment label.' );

// --- CSS colour sanitiser (the security-critical piece) ---
keel_assert( '#b92a2a' === keel_defaults_sanitize_css_color( '#b92a2a' ), 'Hex colour is preserved.' );
keel_assert( 'rgba(0, 0, 0, .5)' === keel_defaults_sanitize_css_color( 'rgba(0, 0, 0, .5)' ), 'rgba() is preserved.' );
keel_assert( 'var(--x)' === keel_defaults_sanitize_css_color( 'var(--x)' ), 'Custom property is preserved.' );
$evil = keel_defaults_sanitize_css_color( '#fff; } body { background: red; } /*' );
foreach ( array( ';', '}', ':', '*' ) as $ch ) {
	keel_assert( false === strpos( $evil, $ch ), "Declaration-escaping character '{$ch}' is stripped." );
}

// --- styles output ---
$GLOBALS['keel_bar_show'] = true;
ob_start();
keel_defaults_environment_styles();
$css = ob_get_clean();
keel_assert( false !== strpos( $css, 'keel-environment-indicator--production' ), 'Styles include the production rule.' );
keel_assert( false !== strpos( $css, 'min-width: 783px' ), 'Styles include the responsive label-clip band.' );
keel_assert( false !== strpos( $css, 'clip-path: inset(50%)' ), 'Label is clipped (not display:none) for accessibility.' );

// No admin bar → no output.
$GLOBALS['keel_bar_show'] = false;
ob_start();
keel_defaults_environment_styles();
keel_assert( '' === trim( ob_get_clean() ), 'No styles when the admin bar is hidden.' );

// Schema.
$schema = keel_defaults_schema();
keel_assert( 'no' === $schema['environment_indicator']['default'], 'environment_indicator defaults off (opt-in).' );
keel_assert( 'ux' === $schema['environment_indicator']['group'], 'environment_indicator is in the UX group.' );

fwrite( STDOUT, "environment indicator tests passed.\n" );
