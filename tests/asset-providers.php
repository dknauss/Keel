<?php
/**
 * Provider ordering, guarding and asset attachment regression checks.
 *
 * @package Keel
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'KEEL_DEFAULTS_VERSION', 'test' );
define( 'KEEL_DEFAULTS_FILE', __FILE__ );
$GLOBALS['keel_asset_events'] = array();
function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['keel_asset_events'][] = array( 'hook', $hook, $callback, $priority );
}
function wp_register_style( ...$args ) { $GLOBALS['keel_asset_events'][] = array( 'register', $args ); }
function wp_enqueue_style( ...$args ) { $GLOBALS['keel_asset_events'][] = array( 'style', $args ); }
function wp_enqueue_script( ...$args ) { $GLOBALS['keel_asset_events'][] = array( 'script', $args ); }
function wp_add_inline_style( $handle, $css ) { $GLOBALS['keel_asset_events'][] = array( 'css', $handle, $css ); }
function plugins_url( $path, $file ) { return 'https://example.test/keel/' . $path; }
function keel_asset_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
function keel_asset_named() { return '  .named { color: red; }  '; }
class Keel_Asset_Provider {
	public function __invoke() { return ' .object {} '; }
	public static function css() { return ' .method {} '; }
}
require dirname( __DIR__ ) . '/includes/assets.php';
keel_asset_assert(
	array(
		array( 'hook', 'admin_enqueue_scripts', 'keel_defaults_register_admin_style', 5 ),
		array( 'hook', 'login_enqueue_scripts', 'keel_defaults_register_login_style', 5 ),
		array( 'hook', 'wp_enqueue_scripts', 'keel_defaults_register_front_style', 5 ),
	) === $GLOBALS['keel_asset_events'],
	'Context registration hook order and priority remain stable.'
);
keel_defaults_add_style( 'admin', 'keel_asset_named' );
keel_defaults_add_style( 'admin', 'keel_asset_named' );
$closure = static function () { return ' .closure {} ';
};
keel_defaults_add_style( 'admin', $closure );
keel_defaults_add_style( 'admin', $closure );
keel_defaults_add_style( 'admin', new Keel_Asset_Provider() );
keel_defaults_add_style( 'admin', array( 'Keel_Asset_Provider', 'css' ) );
keel_defaults_add_style(
	'admin',
	static function () { return " \n\t ";
	}
);
keel_defaults_add_style(
	'admin',
	static function () { return false;
	}
);
keel_defaults_add_style(
	'admin',
	static function () { return 0;
	}
);
// Invalid entries are retained by the registry but must never be invoked.
keel_defaults_style_providers( 'admin', 'keel_missing_asset_provider' );
keel_defaults_style_providers( 'admin', 42 );
keel_defaults_style_providers( 'admin', array( 'missing', 'method' ) );
$providers = keel_defaults_style_providers( 'admin' );
keel_asset_assert( 11 === count( $providers ), 'Only named strings deduplicate; closures and raw entries retain insertion order.' );
keel_asset_assert( array() === keel_defaults_style_providers( 'unknown', $closure ), 'Unknown contexts remain empty.' );
keel_asset_assert( keel_defaults_style_providers( 'admin', null ) === $providers, 'A null provider reads without mutating the registry.' );
keel_defaults_add_style(
	'login',
	static function () { return ' .login {} ';
	}
);
keel_defaults_add_style(
	'front',
	static function () { return ' .front {} ';
	}
);
$GLOBALS['keel_asset_events'] = array();
keel_defaults_register_admin_style();
keel_asset_assert( 'register' === $GLOBALS['keel_asset_events'][0][0] && 'style' === $GLOBALS['keel_asset_events'][1][0], 'Register and enqueue precede provider attachment.' );
keel_asset_assert( false === $GLOBALS['keel_asset_events'][0][1][1], 'Computed styles keep their no-source handle.' );
$css      = array_slice( $GLOBALS['keel_asset_events'], 2 );
$expected = array();
foreach ( array( '.named { color: red; }', '.closure {}', '.closure {}', '.object {}', '.method {}', '0' ) as $rule ) {
	$expected[] = array( 'css', KEEL_DEFAULTS_ADMIN_HANDLE, $rule );
}
keel_asset_assert( $expected === $css, 'CSS text, ordering, trimming, zero conversion and callable guards remain exact.' );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_register_login_style();
keel_defaults_register_front_style();
keel_asset_assert( array( 'css', KEEL_DEFAULTS_LOGIN_HANDLE, '.login {}' ) === $GLOBALS['keel_asset_events'][2], 'Login providers attach only to their handle.' );
keel_asset_assert( array( 'css', KEEL_DEFAULTS_FRONT_HANDLE, '.front {}' ) === $GLOBALS['keel_asset_events'][5], 'Front providers attach only to their handle.' );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_attach_styles( 'unknown', 'unused' );
keel_asset_assert( array() === $GLOBALS['keel_asset_events'], 'Unknown contexts attach nothing.' );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_enqueue_update_evidence_assets();
keel_asset_assert( array( array( 'style', array( 'keel-defaults-update-evidence', 'https://example.test/keel/assets/css/update-evidence.css', array(), KEEL_DEFAULTS_VERSION ) ), array( 'script', array( 'keel-defaults-update-evidence', 'https://example.test/keel/assets/js/update-evidence.js', array(), KEEL_DEFAULTS_VERSION, true ) ) ) === $GLOBALS['keel_asset_events'], 'Updates-screen focus styling and keyboard scrolling use versioned, independently dequeueable assets.' );
// Atomic alone also identifies other hosts; only WordPress.com gets the repair.
$GLOBALS['keel_asset_events'] = array();
keel_defaults_enqueue_settings_assets();
keel_asset_assert( ! in_array( 'css', array_column( $GLOBALS['keel_asset_events'], 0 ), true ), 'Ordinary sites do not receive the WordPress.com Help override.' );
define( 'IS_ATOMIC', true );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_enqueue_settings_assets();
keel_asset_assert( ! in_array( 'css', array_column( $GLOBALS['keel_asset_events'], 0 ), true ), 'Atomic hosting alone does not receive the WordPress.com Help override.' );
define( 'WPCOMSH_VERSION', 'test' );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_enqueue_settings_assets();
$help_css = array_values(
	array_filter(
		$GLOBALS['keel_asset_events'],
		static function ( $event ) {
			return 'css' === $event[0];
		}
	)
);
keel_asset_assert( array( array( 'css', KEEL_DEFAULTS_SETTINGS_STYLE, 'body.settings_page_keel #contextual-help-link-wrap { display: block; }' ) ) === $help_css, 'WordPress.com restores only Keel\'s Help control through its settings stylesheet.' );
$GLOBALS['keel_asset_events'] = array();
keel_defaults_enqueue_network_assets();
keel_asset_assert( ! in_array( 'css', array_column( $GLOBALS['keel_asset_events'], 0 ), true ), 'The Help override does not spill into the network screen.' );
echo "asset providers: OK\n";
