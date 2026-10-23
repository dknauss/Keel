<?php
/**
 * Abilities authorization inventory: attribution, helper effects, bounds, access.
 *
 * Every fixture callback throws or exits. The inventory reads the registry and
 * reflects on callables; if it ever runs one, this process fails before it can
 * print its success marker.
 *
 * Run: php tests/abilities-authorization.php
 *
 * @package keel
 */

// Native filesystem calls are appropriate for this standalone temporary fixture.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

$fixture = sys_get_temp_dir() . '/keel-abilities-authz-' . getmypid();
foreach ( array( 'wp-includes', 'wp-admin', 'wp-content/plugins/gate-plugin', 'wp-content/mu-plugins', 'wp-content/themes/child', 'elsewhere' ) as $dir ) {
	@mkdir( $fixture . '/' . $dir, 0777, true );
}
$fixture = realpath( $fixture );

define( 'ABSPATH', $fixture . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_PLUGIN_DIR', $fixture . '/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', $fixture . '/wp-content/mu-plugins' );

// Stand-ins for core functions, living under the fake core root so reflection
// resolves them to WordPress exactly as it does on a real site.
file_put_contents(
	$fixture . '/wp-includes/functions.php',
	"<?php\n"
	. "function __return_true() { throw new RuntimeException( 'Inventory executed __return_true.' ); }\n"
	. "function __return_false() { throw new RuntimeException( 'Inventory executed __return_false.' ); }\n"
	. "function is_user_logged_in() { throw new RuntimeException( 'Inventory executed is_user_logged_in.' ); }\n"
);
file_put_contents(
	$fixture . '/wp-content/plugins/gate-plugin/gate.php',
	"<?php\n"
	. "function keel_test_gate_grants( \$permission ) { exit( 97 ); }\n"
	. "class Keel_Test_Gate { public static function deny( \$p ) { exit( 97 ); } public function cache( \$pre ) { exit( 97 ); } }\n"
	. "\$GLOBALS['keel_test_gate_closure'] = static function ( \$pre ) { exit( 97 ); };\n"
);
file_put_contents( $fixture . '/wp-content/mu-plugins/host-loader.php', "<?php\nfunction keel_test_host_cache( \$pre ) { exit( 97 ); }\n" );
file_put_contents( $fixture . '/wp-content/themes/child/functions.php', "<?php\nfunction keel_test_theme_grant( \$p ) { exit( 97 ); }\n" );
file_put_contents( $fixture . '/elsewhere/loose.php', "<?php\n\$GLOBALS['keel_test_loose_closure'] = static function ( \$p ) { exit( 97 ); };\n" );

register_shutdown_function(
	function () use ( $fixture ) {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( (string) $file ) : unlink( (string) $file );
		}
		@rmdir( $fixture );
	}
);
// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

require $fixture . '/wp-includes/functions.php';
require $fixture . '/wp-content/plugins/gate-plugin/gate.php';
require $fixture . '/wp-content/mu-plugins/host-loader.php';
require $fixture . '/wp-content/themes/child/functions.php';
require $fixture . '/elsewhere/loose.php';

$GLOBALS['wp_filter']  = array();
$GLOBALS['authorized'] = true;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $number, $d = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function number_format_i18n( $n ) { return (string) $n; }
function current_user_can( $cap ) { return $GLOBALS['authorized']; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function is_multisite() { return false; }
function get_option( $key, $default = false ) { return 'active_plugins' === $key ? array( 'gate-plugin/gate.php' ) : $default; }
function get_plugins() { return array( 'gate-plugin/gate.php' => array( 'Name' => 'Gate Plugin' ) ); }
function get_stylesheet_directory() { return ABSPATH . 'wp-content/themes/child'; }
function get_template_directory() { return ABSPATH . 'wp-content/themes/child'; }
function wp_get_theme( $slug ) {
	return new class() {
		public function exists() { return true; }
		public function get( $k ) { return 'Child Theme'; }
	};
}

class WP_Hook {
	public $callbacks = array();
}

function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

/** Register like WordPress does: string callables are keyed by their name. */
function keel_test_register( $hook, $callback, $priority = 10 ) {
	if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
		$GLOBALS['wp_filter'][ $hook ] = new WP_Hook();
	}
	static $objects = 0;
	$key            = is_string( $callback ) ? $callback : 'object:' . ( ++$objects );
	$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ $key ] = array(
		'function'      => $callback,
		'accepted_args' => 1,
	);
}

require dirname( __DIR__ ) . '/includes/conflicts.php';

// Before 7.1 the filters do not exist, so there is nothing to inventory and no
// Site Health test to show.
keel_assert( ! keel_defaults_abilities_authorization_supported(), 'Without the 7.1 Abilities classes the inventory is unsupported.' );
keel_assert( 'unsupported' === keel_defaults_ability_authorization_inventory()['coverage'], 'Unsupported cores report unsupported coverage, not a clean registry.' );
keel_assert( array() === keel_defaults_register_ability_authorization_test( array() ), 'No Site Health test is registered before 7.1.' );

// Declared inside a block so PHP does not hoist them above the checks before.
if ( ! class_exists( 'WP_Ability', false ) ) {
	class WP_Ability {}
	class WP_Filter_Sentinel {}
}
keel_assert( keel_defaults_abilities_authorization_supported(), 'The 7.1 Abilities classes make the inventory supported.' );
$tests = keel_defaults_register_ability_authorization_test( array() );
keel_assert( isset( $tests['direct']['keel_abilities_authorization'] ), 'The Site Health test is registered on 7.1.' );

// An empty registry is good.
$inventory = keel_defaults_ability_authorization_inventory();
keel_assert( 'complete' === $inventory['coverage'], 'An empty registry is fully inspected.' );
keel_assert( 0 === $inventory['total'], 'An empty registry has no callbacks.' );
$health = keel_defaults_ability_authorization_health();
keel_assert( 'good' === $health['status'], 'No callbacks is good.' );
keel_assert( false !== strpos( $health['description'], 'does not run any plugin' ), 'The good state still says nothing was executed.' );

// One attributable registration is already a finding.
keel_test_register( 'wp_ability_permission_result', 'keel_test_gate_grants' );
$inventory = keel_defaults_ability_authorization_inventory();
keel_assert( array( 'gate-plugin' ) === $inventory['hooks']['wp_ability_permission_result']['owners'], 'A plugin function is attributed to its plugin.' );
keel_assert( 'recommended' === keel_defaults_ability_authorization_health()['status'], 'A single registration is worth reviewing.' );
keel_assert( false !== strpos( keel_defaults_ability_authorization_health()['description'], 'Gate Plugin' ), 'Owners are shown by plugin name.' );

// Every remaining callable form, and every kind of owner.
keel_test_register( 'wp_ability_permission_result', 'Keel_Test_Gate::deny', 20 );
keel_test_register( 'wp_ability_permission_result', 'keel_test_theme_grant', 30 );
keel_test_register( 'wp_ability_permission_result', '__return_true', 40 );
keel_test_register( 'wp_ability_permission_result', 'is_user_logged_in', 50 );
keel_test_register( 'wp_ability_permission_result', $GLOBALS['keel_test_loose_closure'], 60 );
keel_test_register( 'wp_pre_execute_ability', array( new Keel_Test_Gate(), 'cache' ) );
keel_test_register( 'wp_pre_execute_ability', $GLOBALS['keel_test_gate_closure'], 11 );
keel_test_register( 'wp_pre_execute_ability', 'keel_test_host_cache', 12 );
keel_test_register( 'wp_pre_execute_ability', '__return_false', 13 );

$inventory  = keel_defaults_ability_authorization_inventory();
$permission = $inventory['hooks']['wp_ability_permission_result'];
$pre        = $inventory['hooks']['wp_pre_execute_ability'];
keel_assert( 6 === $permission['entries'], 'Every permission registration is counted.' );
keel_assert( array( 'gate-plugin', 'theme:child' ) === $permission['owners'], 'Static methods and theme functions are attributed; owners are not repeated.' );
keel_assert( array( '__return_true' => 'grants_all' ) === $permission['helpers'], '__return_true on the permission filter grants every check.' );
keel_assert( array( 'is_user_logged_in' ) === $permission['core_functions'], 'A non-helper WordPress function is named, not treated as platform behavior.' );
keel_assert( 1 === $permission['untraceable'], 'A closure outside every known root is untraceable.' );
keel_assert( array( 'gate-plugin', 'mu-plugin:host-loader.php' ) === $pre['owners'], 'Instance methods, closures and must-use code are attributed.' );
keel_assert( array( '__return_false' => 'short_circuits_all' ) === $pre['helpers'], 'Any helper on the pre-execute filter short-circuits every ability.' );
keel_assert( 10 === $inventory['total'], 'Totals cover both hooks.' );

$description = keel_defaults_ability_authorization_health()['description'];
keel_assert( false !== strpos( $description, 'grants every permission check' ), 'The helper effect is stated.' );
keel_assert( false !== strpos( $description, 'skips the permission check for every ability' ), 'The short-circuit effect is stated.' );
keel_assert( false !== strpos( $description, 'one or more' ), 'Helper registrations are reported as at least one, since identical callables collapse.' );
keel_assert( false !== strpos( $description, 'Child Theme (theme)' ), 'Themes are named as themes.' );
keel_assert( false !== strpos( $description, 'must-use plugin host-loader.php' ), 'Must-use plugins are named by file.' );
keel_assert( false !== strpos( $description, 'is_user_logged_in' ), 'Core functions registered by unknown code are named.' );
keel_assert( false === strpos( $description, $fixture ), 'No file path is ever shown.' );

// A registry Keel cannot fully read is unknown, never clean.
$GLOBALS['wp_filter']['wp_pre_execute_ability']->callbacks = array( 10 => array_fill( 0, 1001, array( 'function' => '__return_false' ) ) );
$inventory = keel_defaults_ability_authorization_inventory();
keel_assert( 'partial' === $inventory['coverage'], 'An oversized registry yields partial coverage.' );
keel_assert( true === $inventory['hooks']['wp_pre_execute_ability']['limited'], 'The oversized hook is marked limited.' );
keel_assert( 'recommended' === keel_defaults_ability_authorization_health()['status'], 'Partial coverage is not good.' );
$GLOBALS['wp_filter']                                      = array( 'wp_pre_execute_ability' => new WP_Hook() );
$GLOBALS['wp_filter']['wp_pre_execute_ability']->callbacks = array( 'not-a-priority' => 'malformed' );
keel_assert( 'partial' === keel_defaults_ability_authorization_inventory()['coverage'], 'A malformed registry yields partial coverage.' );
keel_assert( 'recommended' === keel_defaults_ability_authorization_health()['status'], 'A malformed registry with no readable callbacks is still not good.' );

// Only people who may see Site Health checks see the inventory.
$GLOBALS['authorized'] = false;
keel_assert( '' === keel_defaults_ability_authorization_health()['description'], 'Unauthorized callers see nothing.' );

echo "OK: abilities authorization inventory (no callbacks executed).\n";
