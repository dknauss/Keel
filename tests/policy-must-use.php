<?php
/**
 * Must-use and symlinked-core callbacks are attributed, named, and kept quiet.
 *
 * Found on managed hosting, where every site running Keel showed "2 of Keel's
 * settings are also affected by code that cannot be traced to any plugin". The
 * code could be traced perfectly well. It belonged to the host:
 *
 * - the must-use directory held one loader, itself a symlink to a file outside
 *   wp-content, which went on to include a couple of hundred more host files
 *   from other directories. None of them is under WPMU_PLUGIN_DIR, so a prefix
 *   test finds nothing;
 * - ABSPATH was a symlink too, so WordPress's own callbacks, whose reflected
 *   file is the real path, failed the "is this core" test.
 *
 * The fixture reproduces both shapes. What a must-use plugin loads is found from
 * the order PHP included files in: WordPress includes each must-use plugin in
 * turn and then `wp-includes/vars.php`, so whatever was included in between was
 * included by that plugin.
 *
 * Must-use code is named in Site Health and raises no notice. Nobody can switch
 * it off from the Plugins screen, so a banner about it is one nobody can clear.
 *
 * A theme is the other owner that used to read as untraceable. Unlike must-use
 * code it is something a site owner chose and can change, so it is named in the
 * notice the way a plugin is.
 *
 * Its own process under `composer test`: the include order is the fixture.
 *
 * Run: php tests/policy-must-use.php
 *
 * @package keel
 */

$GLOBALS['keel_options']       = array();
$GLOBALS['keel_foreign_calls'] = array();
$GLOBALS['wp_filter']          = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $number, $d = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function wp_json_encode( $v, $f = 0 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	return json_encode( $v, $f );
}
function get_transient( $k ) {
	return isset( $GLOBALS['keel_transients'][ $k ] ) ? $GLOBALS['keel_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['keel_transients'][ $k ] = $v;
	return true; }
function delete_transient( $k ) {
	unset( $GLOBALS['keel_transients'][ $k ] );
	return true; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function is_multisite() { return false; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function esc_url( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) $n; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 1; }
function get_user_meta( $user_id, $key, $single = false ) { return ''; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function wp_nonce_url( $url, $action = -1 ) { return $url; }
function get_current_screen() { return (object) array( 'id' => 'plugins' ); }

// Native filesystem calls are appropriate for this standalone temporary fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
$fixture = sys_get_temp_dir() . '/keel-must-use-' . getmypid();
foreach ( array( 'plugins/active-plugin', 'themes', 'shared/field-theme', 'mu-plugins', 'host/loader/1.0', 'host/platform', 'core/wp-includes', 'elsewhere' ) as $keel_dir ) {
	@mkdir( $fixture . '/' . $keel_dir, 0777, true );
}
$fixture = realpath( $fixture );

$keel_ran = "\$GLOBALS['keel_foreign_calls'][] = __FUNCTION__;";

// A must-use plugin that is simply a file in the must-use directory.
file_put_contents( $fixture . '/mu-plugins/direct.php', "<?php\nfunction keel_test_mu_direct( \$caps ) { {$keel_ran} return \$caps; }\n" );

// The host's loader: its real file lives elsewhere, and it includes more host
// code from two further directories, neither of them the must-use directory.
file_put_contents(
	$fixture . '/host/loader/1.0/host-loader.php',
	"<?php\nrequire __DIR__ . '/feature.php';\nrequire dirname( __DIR__, 3 ) . '/plugins/active-plugin/autoload.php';\nrequire dirname( __DIR__, 2 ) . '/platform/platform.php';\n"
);

// A shared autoloader makes the loader pull a file out of an active plugin half
// way through. That file is the plugin's, and the loader's run does not end there.
file_put_contents( $fixture . '/plugins/active-plugin/autoload.php', "<?php\nfunction keel_test_active_plugin( \$value ) { {$keel_ran} return \$value; }\n" );
// Part of the loader's own package, but loaded long after the must-use phase, as a
// feature loaded on a later hook is.
file_put_contents( $fixture . '/host/loader/1.0/late.php', "<?php\nfunction keel_test_host_late( \$open ) { {$keel_ran} return \$open; }\n" );
file_put_contents( $fixture . '/host/loader/1.0/feature.php', "<?php\nfunction keel_test_host_limit( \$num ) { {$keel_ran} return 100; }\n" );
file_put_contents(
	$fixture . '/host/platform/platform.php',
	"<?php\nclass Keel_Test_Host_Platform { public function user_has_cap( \$caps ) { {$keel_ran} return \$caps; } }\n"
	. "\$GLOBALS['keel_test_host_closure'] = function ( \$caps ) { {$keel_ran} return \$caps; };\n"
);
symlink( $fixture . '/host/loader/1.0/host-loader.php', $fixture . '/mu-plugins/host-loader.php' );

// Core, reached through a symlinked ABSPATH.
file_put_contents( $fixture . '/core/wp-includes/vars.php', "<?php\n" );
file_put_contents( $fixture . '/core/wp-includes/capabilities.php', "<?php\nfunction keel_test_core_cap( \$caps ) { {$keel_ran} return \$caps; }\n" );
symlink( $fixture . '/core', $fixture . '/wp' );

// The active theme, in a directory that is itself a symlink.
file_put_contents( $fixture . '/shared/field-theme/functions.php', "<?php\nfunction keel_test_theme_login_url( \$url ) { {$keel_ran} return \$url; }\n" );
symlink( $fixture . '/shared/field-theme', $fixture . '/themes/field-theme' );

// Loaded after WordPress has finished with must-use plugins, from nowhere known.
file_put_contents( $fixture . '/elsewhere/stray.php', "<?php\nfunction keel_test_stray( \$value ) { {$keel_ran} return \$value; }\n" );

register_shutdown_function(
	function () use ( $fixture ) {
		$files = array(
			'/mu-plugins/direct.php',
			'/mu-plugins/host-loader.php',
			'/plugins/active-plugin/autoload.php',
			'/wp',
			'/host/loader/1.0/host-loader.php',
			'/host/loader/1.0/feature.php',
			'/host/loader/1.0/late.php',
			'/host/platform/platform.php',
			'/core/wp-includes/vars.php',
			'/core/wp-includes/capabilities.php',
			'/elsewhere/stray.php',
			'/themes/field-theme',
			'/shared/field-theme/functions.php',
		);
		foreach ( $files as $file ) {
			@unlink( $fixture . $file );
		}
		foreach ( array( 'plugins/active-plugin', 'plugins', 'themes', 'shared/field-theme', 'shared', 'mu-plugins', 'host/loader/1.0', 'host/loader', 'host/platform', 'host', 'core/wp-includes', 'core', 'elsewhere', '' ) as $dir ) {
			@rmdir( $fixture . '/' . $dir );
		}
	}
);
// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

define( 'ABSPATH', $fixture . '/wp/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_PLUGIN_DIR', $fixture . '/plugins' );
define( 'WPMU_PLUGIN_DIR', $fixture . '/mu-plugins' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['keel_options']['active_plugins'] = array( 'active-plugin/autoload.php' );

function wp_get_mu_plugins() {
	return array( WPMU_PLUGIN_DIR . '/direct.php', WPMU_PLUGIN_DIR . '/host-loader.php' );
}

// The order wp-settings.php uses: each must-use plugin, then vars.php, and only
// then anything else.
foreach ( wp_get_mu_plugins() as $keel_mu_plugin ) {
	require $keel_mu_plugin;
}
require ABSPATH . WPINC . '/vars.php';
require ABSPATH . WPINC . '/capabilities.php';
require $fixture . '/elsewhere/stray.php';
require $fixture . '/host/loader/1.0/late.php';

function get_stylesheet_directory() { return $GLOBALS['keel_fixture'] . '/themes/field-theme'; }
function get_template_directory() { return get_stylesheet_directory(); }
function wp_get_theme( $slug = '' ) {
	return new class() {
		public function exists() { return true; }
		public function get( $header ) { return 'Name' === $header ? 'Field Theme' : ''; }
	};
}
$GLOBALS['keel_fixture'] = $fixture;
require get_stylesheet_directory() . '/functions.php';

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

$platform = new Keel_Test_Host_Platform();

// --- Attribution ---
keel_assert( 'direct.php' === keel_defaults_callback_mu_plugin( 'keel_test_mu_direct' ), 'A file in the must-use directory is attributed to itself.' );
keel_assert( 'host-loader.php' === keel_defaults_callback_mu_plugin( 'keel_test_host_limit' ), 'A file a symlinked must-use loader includes is attributed to the loader.' );
keel_assert( 'host-loader.php' === keel_defaults_callback_mu_plugin( array( $platform, 'user_has_cap' ) ), 'A method in a second directory the loader includes is attributed to the loader.' );
keel_assert( 'host-loader.php' === keel_defaults_callback_mu_plugin( $GLOBALS['keel_test_host_closure'] ), 'A closure the loader includes is attributed to the loader.' );
keel_assert( 'active-plugin' === keel_defaults_callback_plugin_dir( 'keel_test_active_plugin' ), 'An active plugin\'s file stays that plugin\'s, whoever included it first.' );
keel_assert( '' === keel_defaults_callback_mu_plugin( 'keel_test_active_plugin' ), 'A plugin file a must-use loader pulled in is not the loader\'s.' );
keel_assert( 'host-loader.php' === keel_defaults_callback_mu_plugin( 'keel_test_host_late' ), 'A file in the loader\'s own directory is the loader\'s, however late it loads.' );
keel_assert( '' === keel_defaults_callback_mu_plugin( 'keel_test_stray' ), 'A file included after the must-use phase is not attributed to a must-use plugin.' );
keel_assert( '' === keel_defaults_callback_mu_plugin( 'keel_test_core_cap' ), 'Core is not a must-use plugin.' );
keel_assert( '' === keel_defaults_callback_mu_plugin( 'keel_defaults_revision_limit' ), 'Keel is not a must-use plugin here.' );

$name = keel_defaults_plugin_name( KEEL_DEFAULTS_MU_PLUGIN_PREFIX . 'host-loader.php' );
keel_assert( false !== strpos( $name, 'host-loader.php' ) && false !== stripos( $name, 'must-use' ), 'A must-use plugin is printed as one, by file name.' );

// --- The report ---
keel_defaults_registered_policy_hooks( 'wp_revisions_to_keep', 'keel_defaults_revision_limit', 10, 2, 'post_revisions_limit' );
keel_defaults_registered_policy_hooks( 'user_has_cap', 'keel_defaults_limit_unfiltered_html', PHP_INT_MAX - 1, 4, 'limit_unfiltered_html' );
keel_defaults_registered_policy_hooks( 'auth_cookie_expiration', 'keel_defaults_session_length', 50, 3, 'session_regular_days' );

class Keel_Mu_Test_Hook {
	public $callbacks = array();
	public function __construct( array $callbacks ) { $this->callbacks = $callbacks; }
}

$host_hooks = array(
	'wp_revisions_to_keep' => new Keel_Mu_Test_Hook(
		array(
			5  => array( array( 'function' => 'keel_test_host_limit' ) ),
			10 => array(
				array(
					'function'      => 'keel_defaults_revision_limit',
					'accepted_args' => 2,
				),
			),
		)
	),
	'user_has_cap'         => new Keel_Mu_Test_Hook(
		array(
			1               => array( array( 'function' => array( $platform, 'user_has_cap' ) ) ),
			10              => array(
				array( 'function' => 'keel_test_core_cap' ),
				array( 'function' => 'keel_test_mu_direct' ),
			),
			PHP_INT_MAX - 1 => array(
				array(
					'function'      => 'keel_defaults_limit_unfiltered_html',
					'accepted_args' => 4,
				),
			),
			PHP_INT_MAX     => array( array( 'function' => $GLOBALS['keel_test_host_closure'] ) ),
		)
	),
);

$GLOBALS['wp_filter'] = $host_hooks;
$report               = keel_defaults_policy_overlap_report( true );

keel_assert(
	array( KEEL_DEFAULTS_MU_PLUGIN_PREFIX . 'host-loader.php' ) === $report['unconfirmed']['wp_revisions_to_keep'],
	'A must-use callback on an authoritative hook is named, for information.'
);
$caps = $report['unconfirmed']['user_has_cap'];
sort( $caps );
keel_assert(
	array( KEEL_DEFAULTS_MU_PLUGIN_PREFIX . 'direct.php', KEEL_DEFAULTS_MU_PLUGIN_PREFIX . 'host-loader.php' ) === $caps,
	'Must-use callbacks are named, and core reached through a symlinked ABSPATH is still core.'
);
keel_assert( array() === $report['structural'], 'Must-use code is not reported as a competing plugin.' );
keel_assert( array() === keel_defaults_unattributed_hooks(), 'Nothing is called untraceable when all of it was traced.' );

// --- What the administrator is shown ---
keel_assert( array() === keel_defaults_competing_plugins(), 'The notice has no plugin to name.' );
keel_assert( array() === keel_defaults_policy_divergences(), 'The fixture records no divergence.' );
keel_assert( '' === keel_defaults_notice_fingerprint(), 'Must-use code alone gives the notice nothing to show.' );

$health = keel_defaults_site_health_conflicts();
keel_assert( 'good' === $health['status'], 'Must-use code alone does not open a Site Health recommendation.' );
keel_assert( false !== strpos( $health['description'], 'host-loader.php' ), 'Site Health names the must-use plugin.' );
keel_assert( false !== strpos( $health['description'], 'direct.php' ), 'Site Health names every must-use plugin involved.' );
keel_assert( false === strpos( $health['description'], 'cannot trace' ), 'Site Health does not call traced code untraceable.' );
keel_assert( false !== strpos( $health['description'], 'cannot be turned off from the Plugins screen' ), 'Site Health says why must-use code raises no notice.' );

// --- Code that really cannot be traced still is reported ---
$GLOBALS['wp_filter']                           = $host_hooks;
$GLOBALS['wp_filter']['auth_cookie_expiration'] = new Keel_Mu_Test_Hook(
	array(
		10 => array( array( 'function' => 'keel_test_stray' ) ),
		50 => array(
			array(
				'function'      => 'keel_defaults_session_length',
				'accepted_args' => 3,
			),
		),
	)
);

keel_defaults_policy_overlap_report( true );
keel_assert( array( 'auth_cookie_expiration' ) === keel_defaults_unattributed_hooks(), 'A callback from no known place is still reported as untraceable.' );
keel_assert( 'recommended' === keel_defaults_site_health_conflicts()['status'], 'An untraceable overlap is still a Site Health recommendation.' );

// --- A theme is named, and it gets the notice ---
keel_assert( 'field-theme' === keel_defaults_callback_theme( 'keel_test_theme_login_url' ), 'A callback in the active theme is attributed to it, through a symlink.' );
keel_assert( '' === keel_defaults_callback_theme( 'keel_test_host_limit' ), 'Must-use code is not the theme.' );
keel_assert( '' === keel_defaults_callback_mu_plugin( 'keel_test_theme_login_url' ), 'The theme is not must-use code.' );

keel_defaults_registered_policy_hooks( 'login_headerurl', 'keel_defaults_login_header_url', 10, 1, 'login_logo_behavior' );
$GLOBALS['wp_filter']                    = $host_hooks;
$GLOBALS['wp_filter']['login_headerurl'] = new Keel_Mu_Test_Hook(
	array(
		10 => array(
			array( 'function' => 'keel_test_theme_login_url' ),
			array( 'function' => 'keel_defaults_login_header_url' ),
		),
	)
);

$themed = keel_defaults_policy_overlap_report( true );
keel_assert(
	array( KEEL_DEFAULTS_THEME_PREFIX . 'field-theme' ) === $themed['structural']['login_headerurl'],
	'A theme on an authoritative hook is a structural overlap, as a plugin is.'
);
keel_assert( array() === keel_defaults_unattributed_hooks(), 'Theme code is not called untraceable.' );
keel_assert( '' !== keel_defaults_notice_fingerprint(), 'A theme overlap is something the notice shows and can be dismissed.' );

ob_start();
keel_defaults_render_conflicts_notice();
$notice = (string) ob_get_clean();
keel_assert( false !== strpos( $notice, 'Field Theme' ), 'The notice names the theme as the Themes screen does.' );
keel_assert( false !== strpos( $notice, 'Your theme may influence' ), 'The notice says it is the theme.' );
keel_assert( false === strpos( $notice, 'plugin may influence' ) && false === strpos( $notice, 'plugins may influence' ), 'The notice does not call a theme a plugin.' );
keel_assert( false === strpos( $notice, 'host-loader.php' ), 'Must-use code stays out of the notice.' );

$themed_health = keel_defaults_site_health_conflicts();
keel_assert( 'recommended' === $themed_health['status'], 'A theme overlap is a Site Health recommendation.' );
keel_assert( false !== strpos( $themed_health['description'], 'Field Theme' ), 'Site Health names the theme.' );
keel_assert( false !== strpos( $themed_health['label'], 'theme' ) && false === stripos( $themed_health['label'], 'plugin' ), 'With only a theme involved, the Site Health headline says theme, not plugin.' );
keel_assert( false === strpos( $themed_health['description'], 'Plugins that may change' ), 'With only a theme involved, Site Health does not list it under plugins.' );

keel_assert( array() === $GLOBALS['keel_foreign_calls'], 'Attribution invokes no registered callback.' );

fwrite( STDOUT, "policy must-use: OK (must-use callbacks named, none executed)\n" );
