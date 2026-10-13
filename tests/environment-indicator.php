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
function is_multisite() { return false; }
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $name ] : $default;
}
function current_user_can( $cap ) { return $GLOBALS['keel_can']; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['keel_options'][ $name ] = $value;
	return true; }
function delete_option( $name ) { unset( $GLOBALS['keel_options'][ $name ] );
	return true; }
$GLOBALS['keel_can'] = true;
function admin_url( $path = '' ) { return 'https://example-real.ca/wp-admin/' . $path; }
function esc_url( $s ) { return $s; }
function get_current_screen() { return isset( $GLOBALS['keel_screen'] ) ? (object) array( 'id' => $GLOBALS['keel_screen'] ) : null; }
$GLOBALS['keel_options'] = array();
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

// --- manual override ---
//
// The override is Keel's own answer to "what is this site", for the live site
// whose wp-config.php still says local. It beats everything else, including a
// declared environment and the host heuristic.
$GLOBALS['keel_home'] = 'https://client.ddev.site';
$GLOBALS['keel_env']  = 'production';

$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'production' );
keel_assert( 'production' === keel_defaults_current_environment(), 'An override of production beats the local-host heuristic.' );

putenv( 'WP_ENVIRONMENT_TYPE=local' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
$GLOBALS['keel_env'] = 'local';
keel_assert( 'production' === keel_defaults_current_environment(), 'An override of production beats a declared local environment.' );

$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'staging' );
keel_assert( 'staging' === keel_defaults_current_environment(), 'Any of the four names can be the override.' );

// A value outside core's four names is automatic, not a fifth environment.
$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'prod' );
keel_assert( '' === keel_defaults_environment_override(), 'An unrecognised override value reads as automatic.' );
keel_assert( 'local' === keel_defaults_current_environment(), 'With an unrecognised override, the declared environment applies.' );

$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'auto' );
keel_assert( '' === keel_defaults_environment_override(), 'auto means no override.' );
keel_assert( 'local' === keel_defaults_current_environment(), 'With auto, the declared environment applies.' );

// --- a live site labelled local or development ---
//
// The case this exists for: WP_ENVIRONMENT_TYPE says local, the site is served
// from a real public domain. Still declared local through the variable above.
$GLOBALS['keel_home'] = 'https://m.arginalia.com';
keel_assert( keel_defaults_environment_looks_mislabelled(), 'A public host declared local is flagged.' );

// Development on an ordinary public host is NOT flagged: managed hosts run real
// development environments on public names, and the notice's advice (set
// Production) would switch their mail suppression off. Only WordPress.com
// (IS_ATOMIC, a constant, so not testable in this process) flags development.
$GLOBALS['keel_env'] = 'development';
putenv( 'WP_ENVIRONMENT_TYPE=development' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
keel_assert( ! keel_defaults_environment_looks_mislabelled(), 'A public host declared development is not flagged off WordPress.com.' );

// Staging legitimately lives on public hosts.
$GLOBALS['keel_env'] = 'staging';
putenv( 'WP_ENVIRONMENT_TYPE=staging' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
keel_assert( ! keel_defaults_environment_looks_mislabelled(), 'Staging on a public host is not flagged.' );

$GLOBALS['keel_env'] = 'local';
putenv( 'WP_ENVIRONMENT_TYPE=local' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.

// Never on a machine that is plainly local, which would tell somebody their
// laptop is a live site.
foreach ( array( 'http://localhost:8885', 'https://site.test', 'http://127.0.0.1', 'http://192.168.1.20', 'http://10.0.0.5:8080', 'http://intranet', 'https://wiki.internal', 'http://[::1]:8080' ) as $home ) {
	$GLOBALS['keel_home'] = $home;
	keel_assert( ! keel_defaults_environment_looks_mislabelled(), "A local or private home '{$home}' is not flagged." );
}

// Public and private addresses, including the shapes mutation testing found unpinned.
keel_assert( keel_defaults_is_public_host( '8.8.8.8' ), 'A public IPv4 address is a public host.' );
keel_assert( keel_defaults_is_public_host( '2606:4700::1111' ), 'A public IPv6 address is a public host.' );
keel_assert( keel_defaults_is_public_host( 'M.ARGINALIA.COM' ), 'An upper-case public host is a public host.' );
foreach ( array( '169.254.1.1', '0.0.0.0', '100.100.1.1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', 'localhost.', 'WIKI.INTERNAL', 'site.example', 'app.10.0.0.5.nip.io', 'fd00::1' ) as $private_host ) {
	keel_assert( ! keel_defaults_is_public_host( $private_host ), "'{$private_host}' is not a public host." );
}

// An override is the administrator's answer; the warning stops asking.
$GLOBALS['keel_home']                     = 'https://m.arginalia.com';
$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'production' );
keel_assert( ! keel_defaults_environment_looks_mislabelled(), 'With an override set, the site is not flagged.' );

// ...but the override changes only Keel. WordPress, Jetpack and the host still
// read "local", so a second warning replaces the first instead of silence.
keel_assert( keel_defaults_environment_override_masks_label(), 'An override covering for a wrong label is reported as masking it.' );
keel_assert( ! keel_defaults_environment_override_is_redundant(), 'An override that disagrees with the declaration is not redundant.' );
ob_start();
keel_defaults_render_environment_mismatch_notice();
$masking = (string) ob_get_clean();
keel_assert( false !== strpos( $masking, 'keel-environment-override-masking' ), 'The masking notice is shown.' );
keel_assert( false === strpos( $masking, 'keel-environment-mismatch' ), 'The original warning is replaced, not repeated.' );
keel_assert( false !== strpos( $masking, '<strong>production</strong>' ) && false !== strpos( $masking, '<strong>local</strong>' ), 'The masking notice names both environments.' );
keel_assert( false !== strpos( $masking, 'wp config set WP_ENVIRONMENT_TYPE production --type=constant' ), 'The masking notice gives the WP-CLI command.' );
keel_assert( false !== strpos( $masking, 'server environment variable' ), 'A value from the environment variable is named as such.' );
keel_assert( false !== strpos( $masking, 'back to Automatic' ), 'The masking notice says to return to Automatic afterwards.' );

$health = keel_defaults_site_health_environment();
keel_assert( 'recommended' === $health['status'], 'Site Health recommends fixing a masked label.' );
keel_assert( 'keel_defaults_environment' === $health['test'], 'The Site Health test has a stable id.' );

// The Network Admin hears about the label even with a site-level override,
// because WP_ENVIRONMENT_TYPE is network-wide and overrides are not.
ob_start();
keel_defaults_render_network_environment_notice();
$network = (string) ob_get_clean();
keel_assert( false !== strpos( $network, 'every site in the network' ), 'The Network Admin is warned about a network-wide label.' );
keel_assert( false !== strpos( $network, 'wp config set' ), 'The network notice carries the same advice.' );
$GLOBALS['keel_can'] = false;
ob_start();
keel_defaults_render_network_environment_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'No network notice without the capability.' );
$GLOBALS['keel_can'] = true;

// Choosing Local on a live site WordPress already calls local fixes nothing, so
// it must not be a way to make the warning go away.
$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'local' );
keel_assert( keel_defaults_environment_looks_mislabelled(), 'An override repeating the wrong label still counts as mislabelled.' );
keel_assert( ! keel_defaults_environment_override_is_redundant(), 'An override repeating a wrong label is not called redundant.' );
keel_assert( 'critical' === keel_defaults_site_health_environment()['status'], 'Site Health stays critical when the override repeats the wrong label.' );
ob_start();
keel_defaults_render_environment_mismatch_notice();
keel_assert( false !== strpos( (string) ob_get_clean(), 'keel-environment-mismatch' ), 'The original warning stays when the override repeats the wrong label.' );
$GLOBALS['keel_options']['keel_settings'] = array();

// With nothing done yet, Site Health calls the live site labelled local critical.
$health = keel_defaults_site_health_environment();
keel_assert( 'critical' === $health['status'], 'Site Health reports a live site labelled local as critical.' );
keel_assert( false !== strpos( $health['description'], 'wp config set' ), 'Site Health gives the same advice as the notice.' );

// The escape hatch for a development site that really lives on a public host.
$GLOBALS['keel_filters']['keel_environment_looks_mislabelled'] = false;
keel_assert( ! keel_defaults_environment_looks_mislabelled(), 'The mislabelled check is filterable.' );
unset( $GLOBALS['keel_filters']['keel_environment_looks_mislabelled'] );

// The notice names the label, the host and the setting that fixes it.
ob_start();
keel_defaults_render_environment_mismatch_notice();
$notice = (string) ob_get_clean();
keel_assert( false !== strpos( $notice, 'notice-warning' ), 'The mismatch notice is a warning.' );
keel_assert( false !== strpos( $notice, '<strong>local</strong>' ), 'The notice names the declared environment.' );
keel_assert( false !== strpos( $notice, 'm.arginalia.com' ), 'The notice names the host.' );
keel_assert( false !== strpos( $notice, '#keel-setting-environment-override' ), 'The notice links to the Environment setting.' );

// Without the capability, nothing is shown.
$GLOBALS['keel_can'] = false;
ob_start();
keel_defaults_render_environment_mismatch_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'No notice for a user who cannot manage options.' );
$GLOBALS['keel_can'] = true;

// --- an override copied with the database ---
//
// Production chosen on the live site, then the database copied to a laptop or
// a staging site. The copy must not inherit it: that is real mail from a copy.
$GLOBALS['keel_options']['keel_settings']                  = array( 'environment_override' => 'production' );
$GLOBALS['keel_options']['keel_environment_override_host'] = 'm.arginalia.com';
keel_assert( 'production' === keel_defaults_current_environment(), 'On the site it was chosen on, the override applies.' );

$GLOBALS['keel_home'] = 'http://localhost:8885';
keel_assert( keel_defaults_environment_override_is_stale(), 'On another host, the override is stale.' );
keel_assert( 'local' === keel_defaults_current_environment(), 'A stale override is ignored; the declared environment applies.' );

$GLOBALS['keel_home'] = 'https://staging.m.arginalia.com';
keel_assert( 'local' === keel_defaults_current_environment(), 'A staging copy on a public host ignores the override too.' );
ob_start();
keel_defaults_render_environment_mismatch_notice();
$stale_notice = (string) ob_get_clean();
keel_assert( false !== strpos( $stale_notice, 'keel-environment-override-stale' ), 'A stale override is reported.' );
keel_assert( false !== strpos( $stale_notice, 'm.arginalia.com</code>' ) && false !== strpos( $stale_notice, 'staging.m.arginalia.com' ), 'The stale notice names both hosts.' );

// Site Health: this copy is also labelled local on a public host, so it is
// critical, and still mentions the ignored override, as the notices do.
$health = keel_defaults_site_health_environment();
keel_assert( 'critical' === $health['status'] && false !== strpos( $health['description'], 'being ignored' ), 'A mislabelled copy with a stale override is critical and names both.' );
$GLOBALS['keel_home'] = 'http://localhost:8885';
$health               = keel_defaults_site_health_environment();
keel_assert( 'recommended' === $health['status'] && false !== strpos( $health['label'], 'being ignored' ), 'A stale override alone is recommended in Site Health.' );
$GLOBALS['keel_home'] = 'https://staging.m.arginalia.com';

// No recorded host (network policy, older data): nothing to compare, so it applies.
unset( $GLOBALS['keel_options']['keel_environment_override_host'] );
keel_assert( 'production' === keel_defaults_current_environment(), 'An override with no recorded host applies.' );

// Recording: only a change to the override records the host.
$GLOBALS['keel_home'] = 'https://m.arginalia.com/';
keel_defaults_record_environment_override_host( array( 'environment_override' => 'auto' ), array( 'environment_override' => 'production' ) );
keel_assert( 'm.arginalia.com' === $GLOBALS['keel_options']['keel_environment_override_host'], 'Choosing an override records this host.' );
$GLOBALS['keel_home'] = 'http://localhost:8885';
keel_defaults_record_environment_override_host(
	array(
		'environment_override' => 'production',
		'x'                    => 1,
	),
	array(
		'environment_override' => 'production',
		'x'                    => 2,
	)
);
keel_assert( 'm.arginalia.com' === $GLOBALS['keel_options']['keel_environment_override_host'], 'Saving another setting on a copy does not adopt the override.' );
keel_defaults_record_environment_override_host( array( 'environment_override' => 'production' ), array( 'environment_override' => 'auto' ) );
keel_assert( ! isset( $GLOBALS['keel_options']['keel_environment_override_host'] ), 'Returning to automatic forgets the host.' );
keel_defaults_record_environment_override_host( 'keel_settings', array( 'environment_override' => 'staging' ) );
keel_assert( 'localhost' === $GLOBALS['keel_options']['keel_environment_override_host'], 'Adding the option with an override records the host.' );
unset( $GLOBALS['keel_options']['keel_environment_override_host'] );
$GLOBALS['keel_options']['keel_settings'] = array();
$GLOBALS['keel_home']                     = 'https://m.arginalia.com';

// --- an override that says what the site already says ---
//
// Typically left behind once wp-config.php is fixed. Pointed out on Keel's own
// screen only, and never as a fault.
putenv( 'WP_ENVIRONMENT_TYPE=production' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- setting the variable under test is the point.
$GLOBALS['keel_env']                      = 'production';
$GLOBALS['keel_home']                     = 'https://m.arginalia.com';
$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'production' );
keel_assert( keel_defaults_environment_override_is_redundant(), 'An override matching the declaration is redundant.' );
keel_assert( ! keel_defaults_environment_override_masks_label(), 'A redundant override masks nothing.' );
ob_start();
keel_defaults_render_environment_mismatch_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'A redundant override is not mentioned on every screen.' );
$GLOBALS['keel_screen'] = 'settings_page_keel';
ob_start();
keel_defaults_render_environment_mismatch_notice();
$redundant = (string) ob_get_clean();
keel_assert( false !== strpos( $redundant, 'keel-environment-override-redundant' ) && false !== strpos( $redundant, 'notice-info' ), 'Keel’s own screen suggests returning to Automatic.' );
unset( $GLOBALS['keel_screen'] );
$health = keel_defaults_site_health_environment();
keel_assert( 'good' === $health['status'] && false !== strpos( $health['description'], 'back to Automatic' ), 'Site Health passes a redundant override and suggests Automatic.' );

// Agreement passes, and says so.
$GLOBALS['keel_options']['keel_settings'] = array();
$health                                   = keel_defaults_site_health_environment();
keel_assert( 'good' === $health['status'] && false !== strpos( $health['label'], 'agree' ), 'Site Health passes when WordPress and Keel agree.' );

// A production site overridden to staging is holding back its own email. That
// may be deliberate, but it is never harmless, so Site Health asks.
$GLOBALS['keel_options']['keel_settings'] = array( 'environment_override' => 'staging' );
$health                                   = keel_defaults_site_health_environment();
keel_assert( 'recommended' === $health['status'] && false !== strpos( $health['label'], 'holding back its email' ), 'A production site overridden to staging, with mail held back, is recommended.' );

// With non-production mail handling off, nothing is held back: described, not flagged.
$GLOBALS['keel_options']['keel_settings'] = array(
	'environment_override'        => 'staging',
	'suppress_nonproduction_mail' => 'no',
);
$health                                   = keel_defaults_site_health_environment();
keel_assert( 'good' === $health['status'] && false !== strpos( $health['label'], 'treats this production site as staging' ), 'With mail not held back, the difference is described, not flagged.' );
$GLOBALS['keel_options']['keel_settings'] = array();

// Nor is a healthy network nagged.
ob_start();
keel_defaults_render_network_environment_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'No network notice when the label is right.' );

putenv( 'WP_ENVIRONMENT_TYPE=local' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- restoring this block's starting state.
$GLOBALS['keel_env'] = 'local';

// And says nothing when there is nothing to say.
$GLOBALS['keel_home'] = 'http://localhost:8885';
ob_start();
keel_defaults_render_environment_mismatch_notice();
keel_assert( '' === trim( (string) ob_get_clean() ), 'No notice on a local host.' );

putenv( 'WP_ENVIRONMENT_TYPE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- clearing what this block set.
$GLOBALS['keel_env']  = 'production';
$GLOBALS['keel_home'] = 'https://example-real.ca';

// --- where wp-config.php is ---
//
// On hosts that share one copy of core between sites (WordPress.com among them)
// the file sits beside the content directory, not beside WordPress.
keel_assert( '' === keel_defaults_wp_config_path(), 'No wp-config.php found is reported as none.' );
$keel_tmp = sys_get_temp_dir() . '/keel-env-' . getmypid();
// keel_assert() exits on failure, so clean up however the run ends.
register_shutdown_function(
	function () use ( $keel_tmp ) {
		foreach ( array( $keel_tmp . '/wp-config.php', ABSPATH . 'wp-config.php' ) as $keel_file ) {
			if ( is_file( $keel_file ) ) {
				unlink( $keel_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
			}
		}
		foreach ( array( $keel_tmp . '/wp-content', $keel_tmp ) as $keel_dir ) {
			if ( is_dir( $keel_dir ) ) {
				rmdir( $keel_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
			}
		}
	}
);
mkdir( $keel_tmp . '/wp-content', 0700, true );
file_put_contents( $keel_tmp . '/wp-config.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
// Stays defined for the rest of this file, like the WP_ENVIRONMENT_TYPE constant below.
define( 'WP_CONTENT_DIR', $keel_tmp . '/wp-content' );
$keel_found  = keel_defaults_wp_config_path();
$keel_expect = $keel_tmp . '/wp-config.php';
keel_assert( $keel_expect === $keel_found, 'wp-config.php beside the content directory is found.' );
keel_assert( false !== strpos( keel_defaults_environment_fix_advice(), $keel_tmp . '/wp-config.php' ), 'The advice names the file it found.' );

// Where core looks first wins: beside WordPress itself.
file_put_contents( ABSPATH . 'wp-config.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
$keel_found  = keel_defaults_wp_config_path();
$keel_expect = ABSPATH . 'wp-config.php';
keel_assert( $keel_expect === $keel_found, 'wp-config.php beside WordPress wins over the one beside the content directory.' );
unlink( ABSPATH . 'wp-config.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.

// The constant is the other half of the same test. It cannot be undefined once
// set, so it is asserted last and the value is one the heuristic would override.
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
$GLOBALS['keel_env'] = 'staging';
keel_assert(
	'staging' === keel_defaults_current_environment(),
	'The WP_ENVIRONMENT_TYPE constant wins over the host heuristic.'
);
keel_assert( 'constant' === keel_defaults_environment_declaration_source(), 'A constant declaration is reported as the constant.' );
keel_assert( false === strpos( keel_defaults_environment_fix_advice(), 'server environment variable' ), 'Advice for a constant does not mention the variable.' );

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
keel_assert( 'auto' === $schema['environment_override']['default'], 'environment_override defaults to automatic.' );
keel_assert( array( 'auto', 'production', 'staging', 'development', 'local' ) === $schema['environment_override']['choices'], 'environment_override offers automatic and the four core environments.' );

fwrite( STDOUT, "environment indicator tests passed.\n" );
