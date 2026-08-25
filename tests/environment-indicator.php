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
keel_assert( 'local' === keel_defaults_current_environment(), 'A .test host reads as local.' );
$GLOBALS['keel_home'] = 'https://mysite.local/';
keel_assert( 'local' === keel_defaults_current_environment(), 'A .local host reads as local.' );
$GLOBALS['keel_home'] = 'https://mysite.example-real.ca';
keel_assert( 'production' === keel_defaults_current_environment(), 'A normal host uses wp_get_environment_type().' );

// The case the whole-URL suffix test used to miss: an explicit port.
$GLOBALS['keel_home'] = 'http://mysite.test:8080';
keel_assert( 'local' === keel_defaults_current_environment(), 'A .test host on an explicit port still reads as local.' );

// Loopback, however it is written.
foreach ( array( 'http://localhost:8881', 'http://localhost', 'http://127.0.0.1:8000', 'http://[::1]:8080' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert( 'local' === keel_defaults_current_environment(), "Loopback home '{$home}' reads as local." );
}

// Tool-specific default domains.
foreach ( array( 'https://myproject.ddev.site', 'http://myapp.lndo.site:8000', 'http://myapp.localhost' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert( 'local' === keel_defaults_current_environment(), "Local-tool home '{$home}' reads as local." );
}

// A suffix must match a whole label, not a substring of the host.
$GLOBALS['keel_home'] = 'https://latest.example.ca';
keel_assert( 'production' === keel_defaults_current_environment(), 'A host merely containing "test" is not local.' );
$GLOBALS['keel_home'] = 'https://localhost.example.ca';
keel_assert( 'production' === keel_defaults_current_environment(), 'A real host beginning with "localhost" is not local.' );

/*
 * A suffix must match at the END of the host, not anywhere inside it.
 *
 * The two cases above look like they cover this, and do not: neither host
 * contains the suffix *with* its leading dot, so swapping the suffix test for a
 * substring search left both of them passing. Mutation testing surfaced it.
 *
 * These do contain the dotted suffix mid-host, which is a real naming pattern —
 * an agency running client staging under a shared parent domain — and exactly
 * the case where labelling a live client site "Local" would be worst.
 */
foreach ( array( 'https://client.test.agency.com', 'https://shop.local.example.net', 'https://a.ddev.site.example.org' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert(
		'production' === keel_defaults_current_environment(),
		"A suffix appearing mid-host does not make '{$home}' local."
	);
}

// Case is not significant in host names.
$GLOBALS['keel_home'] = 'https://MySite.TEST';
keel_assert( 'local' === keel_defaults_current_environment(), 'Host matching is case-insensitive.' );

$GLOBALS['keel_filters']['keel_local_host_suffixes'] = array( '.example-real.ca' );
$GLOBALS['keel_home']                                = 'https://mysite.example-real.ca';
keel_assert( 'local' === keel_defaults_current_environment(), 'The suffix list is filterable.' );
unset( $GLOBALS['keel_filters']['keel_local_host_suffixes'] );

// --- an explicitly declared environment beats the host heuristic ---
//
// Core resolves WP_ENVIRONMENT_TYPE from the environment variable as well as the
// constant, and the variable is the documented way to set it — DDEV, Lando and
// wp-env all generate one. Testing only for the constant meant a site that said
// "staging" on a .ddev.site hostname was relabelled Local, which is the one
// failure an environment indicator must not have.
//
// getenv() is called for real here rather than stubbed, because that is the call
// the fix turns on.
$GLOBALS['keel_home'] = 'https://client.ddev.site';
$GLOBALS['keel_env']  = 'staging';

keel_assert( 'local' === keel_defaults_current_environment(), 'With nothing declared, a .ddev.site host reads as local.' );

putenv( 'WP_ENVIRONMENT_TYPE=staging' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
keel_assert(
	'staging' === keel_defaults_current_environment(),
	'A WP_ENVIRONMENT_TYPE environment variable wins over the host heuristic.'
);

// A value core would reject is not a declaration. Core falls back to production
// for anything outside its four names, and inheriting that would paint a red
// Production badge on somebody's laptop because of a typo.
putenv( 'WP_ENVIRONMENT_TYPE=stagng' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
$GLOBALS['keel_env'] = 'production'; // What core answers for an invalid value.
keel_assert(
	'local' === keel_defaults_current_environment(),
	'A misspelled declaration falls back to the host heuristic, not to production.'
);

putenv( 'WP_ENVIRONMENT_TYPE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clearing what the previous line set.
keel_assert( 'local' === keel_defaults_current_environment(), 'Clearing the variable restores the heuristic.' );

// The constant is the other half of the same test. It cannot be undefined once
// set, so it is asserted last and the value is one the heuristic would override.
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
$GLOBALS['keel_env'] = 'staging';
keel_assert(
	'staging' === keel_defaults_current_environment(),
	'The WP_ENVIRONMENT_TYPE constant wins over the host heuristic.'
);

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
$css                      = keel_defaults_environment_css();
keel_assert( false !== strpos( $css, 'keel-environment-indicator--production' ), 'Styles include the production rule.' );
keel_assert( false !== strpos( $css, 'min-width: 783px' ), 'Styles include the responsive label-clip band.' );
keel_assert( false !== strpos( $css, 'clip-path: inset(50%)' ), 'Label is clipped (not display:none) for accessibility.' );

// No admin bar → no output.
$GLOBALS['keel_bar_show'] = false;
keel_assert( '' === trim( keel_defaults_environment_css() ), 'No styles when the admin bar is hidden.' );

// Schema.
$schema = keel_defaults_schema();
keel_assert( 'no' === $schema['environment_indicator']['default'], 'environment_indicator defaults off (opt-in).' );
keel_assert( 'ux' === $schema['environment_indicator']['group'], 'environment_indicator is in the UX group.' );

fwrite( STDOUT, "environment indicator tests passed.\n" );
