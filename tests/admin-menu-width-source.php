<?php
/**
 * Keel can tell when Jetpack, not Keel, is setting the admin menu's width.
 *
 * Found on a live WordPress.com site: the menu was 272px wide with Keel's Admin
 * Menu Width at "Leave unchanged". Jetpack's masterbar package was loading
 * admin-menu.css under the handle `jetpack-admin-menu`, and Keel emits nothing
 * at that setting, so Jetpack's width stood while Keel's screen said it was
 * setting none.
 *
 * The detection has to be right at two points in a request:
 *
 * - After admin_enqueue_scripts, where the style queue is the answer. This is
 *   where the settings screen and Site Health → Info render.
 * - Before or during it, where the queue is not built yet and the registered
 *   callback is the evidence. Site Health → Status runs its tests on that hook
 *   at priority 10 and Jetpack enqueues at 11.
 *
 * It must not answer from the host. Nothing here defines IS_ATOMIC, IS_WPCOM or
 * JETPACK__VERSION except to prove they change nothing.
 *
 * Run: php tests/admin-menu-width-source.php
 *
 * @package keel
 */

$GLOBALS['keel_options']               = array();
$GLOBALS['keel_enqueued_styles']       = array();
$GLOBALS['keel_did']                   = 0;
$GLOBALS['keel_doing']                 = false;
$GLOBALS['keel_style_queries']         = 0;
$GLOBALS['keel_jetpack_enqueue_calls'] = 0;
$GLOBALS['wp_filter']                  = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $number, $d = null ) { return ( 1 === (int) $number ) ? $single : $plural; }
function number_format_i18n( $n ) { return (string) $n; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function is_multisite() { return false; }
function did_action( $hook ) {
	return 'admin_enqueue_scripts' === $hook ? $GLOBALS['keel_did'] : 0;
}
function doing_action( $hook = null ) {
	return 'admin_enqueue_scripts' === $hook && $GLOBALS['keel_doing'];
}
function wp_style_is( $handle, $status = 'enqueued' ) {
	++$GLOBALS['keel_style_queries'];
	return 'enqueued' === $status && in_array( $handle, $GLOBALS['keel_enqueued_styles'], true );
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';
require __DIR__ . '/fixtures/jetpack-admin-menu-stub.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Put the request at a point relative to admin_enqueue_scripts.
 *
 * @param string $when 'before', 'during' or 'after'.
 */
function keel_at( $when ) {
	$GLOBALS['keel_did']   = 'before' === $when ? 0 : 1;
	$GLOBALS['keel_doing'] = 'during' === $when;
}

/**
 * Register callbacks on admin_enqueue_scripts the way WP_Hook stores them.
 *
 * @param array $by_priority Priority => list of callables.
 */
function keel_hook( $by_priority ) {
	$hook            = new stdClass();
	$hook->callbacks = array();
	foreach ( $by_priority as $priority => $callables ) {
		foreach ( $callables as $i => $callable ) {
			$hook->callbacks[ $priority ][ 'cb' . $i ] = array(
				'function'      => $callable,
				'accepted_args' => 1,
			);
		}
	}
	$GLOBALS['wp_filter'] = array( 'admin_enqueue_scripts' => $hook );
}

/**
 * Store Keel's width setting.
 *
 * @param string|null $width A width, or null for a site that never saved one.
 */
function keel_width( $width ) {
	$GLOBALS['keel_options'] = null === $width ? array() : array( KEEL_DEFAULTS_OPTION => array( 'admin_menu_width' => $width ) );
}

$menu = new Automattic\Jetpack\Masterbar\Atomic_Admin_Menu();
$note = 'setting the admin menu to 272px';

// --- after the hook: the style queue decides, and nothing else does ----------

keel_at( 'after' );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'With nothing queued, nothing is setting the width.' );

$GLOBALS['keel_enqueued_styles'] = array( 'jetpack-admin-menu' );
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'Jetpack\'s menu stylesheet in the queue is reported.' );

$GLOBALS['keel_enqueued_styles'] = array( 'jetpack-admin-menu-extra', 'admin-menu', 'jetpack' );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'Only the exact handle counts.' );

// A registered callback that did not enqueue (dequeued since, say) is a no:
// once the queue exists it outranks the registration.
$GLOBALS['keel_enqueued_styles'] = array();
keel_hook( array( 11 => array( array( $menu, 'enqueue_scripts' ) ) ) );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'After the hook, a stylesheet that is not queued is not reported, whatever was registered.' );

// --- before the hook: the registration decides, and the queue is not asked ---

keel_at( 'before' );
$GLOBALS['keel_style_queries']   = 0;
$GLOBALS['keel_enqueued_styles'] = array( 'jetpack-admin-menu' );
$GLOBALS['wp_filter']            = array();
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'Before the hook with nothing registered, nothing is reported.' );
keel_assert( 0 === $GLOBALS['keel_style_queries'], 'Before the hook the style queue is not consulted at all: it has not been built, so it is not evidence either way.' );
$GLOBALS['keel_enqueued_styles'] = array();

keel_hook( array( 11 => array( array( $menu, 'enqueue_scripts' ) ) ) );
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'Before the hook, Jetpack\'s registered enqueue is reported.' );
keel_assert( 0 === $GLOBALS['keel_jetpack_enqueue_calls'], 'Finding Jetpack\'s callback does not run it.' );

keel_hook(
	array(
		10 => array( 'some_plugin_enqueue', array( new stdClass(), 'enqueue_scripts' ) ),
		11 => array( array( $menu, 'enqueue_scripts' ) ),
	)
);
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'It is found among other callbacks and at a later priority.' );

keel_hook( array( 10 => array( 'some_plugin_enqueue', array( new stdClass(), 'enqueue_scripts' ), static function () {} ) ) );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'Another object\'s enqueue_scripts, a function and a closure are not Jetpack\'s menu.' );

keel_hook( array( 10 => array( array( $menu, 'add_dashboard_switcher' ) ) ) );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'A different method on Jetpack\'s menu class enqueues nothing and is not reported.' );

keel_hook( array( 11 => array( array( 'Automattic\\Jetpack\\Masterbar\\Atomic_Admin_Menu', 'enqueue_scripts' ) ) ) );
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'A callback registered by class name is recognised too.' );

$GLOBALS['keel_autoloaded'] = array();
spl_autoload_register(
	static function ( $class_name ) {
		$GLOBALS['keel_autoloaded'][] = $class_name;
	}
);
keel_hook( array( 10 => array( array( 'Some_Plugin_Not_Loaded_Yet', 'enqueue_scripts' ) ) ) );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'Another plugin\'s static enqueue_scripts is not Jetpack\'s menu.' );
keel_assert( array() === $GLOBALS['keel_autoloaded'], 'And looking at it does not autoload that plugin\'s class.' );

// --- during the hook: Site Health at priority 10, Jetpack at 11 --------------

keel_at( 'during' );
keel_hook( array( 11 => array( array( $menu, 'enqueue_scripts' ) ) ) );
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'Mid-hook and ahead of Jetpack, the registration is still the evidence.' );

$GLOBALS['wp_filter']            = array();
$GLOBALS['keel_enqueued_styles'] = array( 'jetpack-admin-menu' );
keel_assert( 'jetpack' === keel_defaults_admin_menu_width_source(), 'Mid-hook and after Jetpack, the queue is.' );

$GLOBALS['keel_enqueued_styles'] = array();
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'Mid-hook with neither, nothing is reported.' );

// --- the host decides nothing ------------------------------------------------

keel_at( 'after' );
define( 'IS_ATOMIC', true );
define( 'IS_WPCOM', true );
define( 'JETPACK__VERSION', '16.3' );
keel_width( null );
keel_assert( '' === keel_defaults_admin_menu_width_source(), 'A WordPress.com host with Jetpack active, but no stylesheet queued, is not reported: this is the Classic admin style.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'admin_menu_width' ), 'And the width field carries no note there.' );
keel_assert( '' !== keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ), 'The XML-RPC warning, which does follow Jetpack being active, is unaffected.' );

// --- the note: one state out of every combination ----------------------------

$expected = array(
	// Stored width, stylesheet queued, note shown.
	array( null, true, true ),
	array( 'default', true, true ),
	array( '160', true, false ),
	array( '200', true, false ),
	array( '240', true, false ),
	array( '280', true, false ),
	array( '300', true, false ),
	array( null, false, false ),
	array( 'default', false, false ),
	array( '160', false, false ),
	array( '300', false, false ),
);

foreach ( $expected as $case ) {
	list( $width, $queued, $shown ) = $case;

	keel_width( $width );
	$GLOBALS['keel_enqueued_styles'] = $queued ? array( 'jetpack-admin-menu' ) : array();

	$label   = ( null === $width ? 'never saved' : $width ) . ( $queued ? ', stylesheet queued' : ', no stylesheet' );
	$warning = keel_defaults_jetpack_warning( 'admin_menu_width' );

	keel_assert( keel_defaults_jetpack_sets_menu_width() === $shown, "[{$label}] Jetpack-sets-the-width is " . ( $shown ? 'true' : 'false' ) . '.' );
	keel_assert( ( '' !== $warning ) === $shown, "[{$label}] The note is " . ( $shown ? 'shown' : 'not shown' ) . '.' );
	keel_assert( '' === keel_defaults_jetpack_warning( 'helper_list_columns' ), "[{$label}] The note stays on its own setting." );

	if ( $shown ) {
		keel_assert( false !== strpos( $warning, $note ), "[{$label}] The note names 272px." );
		keel_assert( false !== strpos( $warning, 'Choose 160px' ), "[{$label}] The note names the 160px stop as the way back." );
	}

	// Site Health reads the same answer, on the row that states the width.
	$state = '';
	foreach ( keel_defaults_posture_by_group() as $items ) {
		foreach ( $items as $item ) {
			if ( 'admin_menu_width' === $item['key'] ) {
				$state = $item['state'];
			}
		}
	}
	keel_assert( '' !== $state, "[{$label}] Site Health lists the width." );

	// The two surfaces that print that row, not only the list behind them.
	$info   = keel_defaults_debug_information( array() );
	$status = keel_defaults_site_health_posture();
	keel_assert( ( false !== strpos( implode( ' ', $info[ KEEL_DEFAULTS_INFO_SECTION ]['fields']['ux']['value'] ), 'sets 272px' ) ) === $shown, "[{$label}] Site Health Info " . ( $shown ? 'carries' : 'does not carry' ) . ' the Jetpack note.' );
	keel_assert( ( false !== strpos( $status['description'], 'sets 272px' ) ) === $shown, "[{$label}] The Status summary " . ( $shown ? 'carries' : 'does not carry' ) . ' the Jetpack note.' );
	keel_assert( ( false !== strpos( $state, '272px' ) ) === $shown, "[{$label}] Site Health's width row " . ( $shown ? 'names' : 'does not name' ) . ' Jetpack\'s 272px.' );
}

// --- detecting it changes nothing --------------------------------------------

keel_width( null );
$GLOBALS['keel_enqueued_styles'] = array( 'jetpack-admin-menu' );
keel_defaults_jetpack_warning( 'admin_menu_width' );
keel_assert( array() === $GLOBALS['keel_options'], 'Showing the note stores nothing: the setting is the administrator\'s to change.' );
keel_assert( '' === trim( keel_defaults_admin_menu_width_css() ), 'And Keel still emits no width CSS at the unchanged setting.' );

keel_width( '160' );
keel_assert( false !== strpos( keel_defaults_admin_menu_width_css(), '160px !important' ), 'At 160px Keel asserts core\'s width with !important, which is what takes it back from Jetpack.' );

fwrite( STDOUT, "admin menu width source: OK\n" );
