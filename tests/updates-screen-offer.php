<?php
/**
 * The same-line patch, offered on the screen whose job it is.
 *
 * Core's get_core_updates() drops every offer flagged for automatic installation — an
 * unconditional skip before the dismissed and available options are read — so the
 * Updates screen shows the newest release and never mentions the patch on the site's
 * own line. Keel's Site Health panel names that patch and then sends the reader to a
 * screen that will not show it.
 *
 * This renders the offer there instead.
 *
 * @package keel-defaults
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['keel_caps']  = array( 'update_core' => true );
$GLOBALS['keel_hooks'] = array();

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['keel_hooks'][] = compact( 'hook', 'cb', 'priority', 'args' );
}
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['keel_caps'][ $cap ] );
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES );
}
function esc_html__( $t, $d = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	return esc_html( $t );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES );
}
function esc_url( $u ) {
	return $u;
}
function __( $t, $d = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	return $t;
}

// Stand-ins for the two functions the offer can reach into. Without them
// function_exists() is false, the actions branch never runs, and every assertion about
// what the offer must not say is vacuously true.
function keel_defaults_backport_actions( $tip ) {
	return '<p>The Updates screen will not offer <code>' . esc_html( $tip ) . '</code>. It is offering something else instead. '
		. 'Reaching it means installing it deliberately from the command line.</p>';
}
function keel_defaults_backport_install_button( $version, $screen, array $state ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	return '<form method="post"><button>Install WordPress ' . esc_html( $version ) . ' now</button></form>';
}
function keel_defaults_updates_screen_offer( $version ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	return array(
		'state'  => 'none',
		'manual' => '7.1',
	);
}
function keel_defaults_minor_update_state() {
	return array(
		'policy'   => true,
		'operable' => true,
		'owner'    => 'default',
		'blockers' => array(),
	);
}
// Consumed on read, exactly as the real one is, so a second render is a real second read.
function keel_defaults_backport_result_markup() {
	$out                            = $GLOBALS['keel_pending_result'];
	$GLOBALS['keel_pending_result'] = '';

	return $out;
}

$GLOBALS['keel_pending_result'] = '';

require_once __DIR__ . '/../includes/updates-screen.php';

$failures = 0;
function keel_assert( $condition, $message ) {
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		$GLOBALS['failures'] = ( $GLOBALS['failures'] ?? 0 ) + 1;
	}
}

// When it appears at all.

$m = keel_defaults_updates_screen_markup( 'insecure', '6.4.10', '7.1', '7.1' );
keel_assert( '' !== $m, 'an insecure release with a same-line patch produces an offer' );

keel_assert( '' === keel_defaults_updates_screen_markup( 'outdated', '6.4.10', '7.1', '7.1' ), 'a release that is merely outdated produces nothing: this screen already offers the newer one' );
keel_assert( '' === keel_defaults_updates_screen_markup( 'latest', '', '7.1', '7.1' ), 'the current release produces nothing' );
keel_assert( '' === keel_defaults_updates_screen_markup( 'unknown', '', '', false ), 'an undetermined status produces nothing here — Site Health says so, this screen does not guess' );
keel_assert( '' === keel_defaults_updates_screen_markup( 'insecure', '', '7.1', '7.1' ), 'an insecure release with no patch on its line produces nothing: there is nothing to offer' );

// What it says. This is the comparison the administrator is actually making.

$m = keel_defaults_updates_screen_markup( 'insecure', '6.4.10', '7.1', '7.1' );
keel_assert( false !== strpos( $m, '6.4.10' ), 'the patch is named' );
keel_assert( false !== strpos( $m, '7.1' ), 'the release this screen is offering is named too' );

// The screen is already showing "Update to version 7.1". Repeating that WordPress is
// available adds nothing; the news is that it is not the only option.
keel_assert(
	false !== stripos( $m, 'known vulnerabilit' ),
	'it says why the patch matters, which is the one fact this screen does not have'
);
keel_assert(
	false !== stripos( $m, 'same release line' ) || false !== stripos( $m, 'on this line' ),
	'it says the patch stays on the current line'
);


// When core would install the patch anyway, the framing changes: there is no
// choice to present, only a note that the screen is not showing it.

$agreeing = keel_defaults_updates_screen_markup( 'insecure', '6.4.10', '7.1', '6.4.10' );
keel_assert( '' !== $agreeing, 'the offer still appears when core would install the patch' );
keel_assert(
	false === stripos( $agreeing, 'skip' ),
	'and it does not claim the patch is being skipped when core would install it'
);

$skipping = keel_defaults_updates_screen_markup( 'insecure', '6.4.10', '7.1', '7.1' );
keel_assert(
	false !== stripos( $skipping, 'skip' ) || false !== stripos( $skipping, 'not ' ),
	'when core would take the newer release, the offer says the patch is passed over'
);

// The panel's prose does not belong here.
//
// keel_defaults_backport_actions() explains that the Updates screen will not offer the
// patch and is offering the newest release instead. That is the news in Site Health and
// it is what carries a reader here. Printed on this screen it tells someone looking at
// the Updates screen that the Updates screen will not offer the release, directly above
// a button that installs it.
$m = keel_defaults_updates_screen_markup( 'insecure', '6.4.10', '7.1', '7.1' );
keel_assert(
	false === stripos( $m, 'Updates screen will not offer' ),
	'the offer does not tell a reader on the Updates screen that the Updates screen will not offer the patch'
);
keel_assert(
	false === stripos( $m, 'from the command line' ),
	'nor does it send them to WP-CLI while showing them a button'
);

// Capability, and registration.

$GLOBALS['keel_caps'] = array();
keel_assert( '' === keel_defaults_render_updates_screen_markup( 'insecure', '6.4.10', '7.1', '7.1' ), 'a reader who cannot update core is offered nothing' );
$GLOBALS['keel_caps'] = array( 'update_core' => true );

$GLOBALS['keel_hooks'] = array();
keel_defaults_register_updates_screen();
$hooks = wp_list_pluck_stub( $GLOBALS['keel_hooks'], 'hook' );
keel_assert(
	in_array( 'after_core_auto_updates_settings', $hooks, true ),
	'registered on after_core_auto_updates_settings, which renders directly above core own update block'
);
keel_assert(
	! in_array( 'core_upgrade_preamble', $hooks, true ),
	'not on core_upgrade_preamble: core documents that as firing after the core, plugin and theme tables, which puts the offer at the bottom of the page away from the release it is about'
);

/*
---------------------------------------------------------------------------
 * The result of an install started here is reported here.
 *
 * A successful install leaves the site no longer insecure, so the offer correctly
 * stops being made -- which is why the result cannot live inside it. Without this an
 * administrator returns to the screen they pressed the button on and is shown nothing,
 * while the result waits in a transient for their next visit to Site Health.
 * ------------------------------------------------------------------------
 */

$GLOBALS['keel_pending_result'] = '<div class="notice notice-success"><p>WordPress 6.9.7 was installed successfully.</p></div>';
$after                          = keel_defaults_updates_screen_content( 'latest', '', '7.1', '' );

keel_assert(
	false !== strpos( $after, 'was installed successfully' ),
	'a completed install is reported on the screen it was started from'
);
keel_assert(
	'' === keel_defaults_updates_screen_markup( 'latest', '', '7.1', '' ),
	'the offer itself is correctly gone once the site is no longer insecure'
);
keel_assert(
	false === strpos( keel_defaults_updates_screen_content( 'latest', '', '7.1', '' ), 'was installed successfully' ),
	'the result is shown once, not on every later visit to the screen'
);

$GLOBALS['keel_pending_result'] = '<div class="notice notice-error"><p>The filesystem refused the update.</p></div>';
keel_assert(
	false !== strpos( keel_defaults_updates_screen_content( 'insecure', '6.9.7', '7.1', '7.1' ), 'filesystem refused' ),
	'a failed install is reported on the screen it was started from'
);
$GLOBALS['keel_pending_result'] = '';

function wp_list_pluck_stub( $arr, $key ) {
	return array_map(
		static function ( $item ) use ( $key ) {
			return $item[ $key ];
		},
		$arr
	);
}

if ( $GLOBALS['failures'] > 0 ) {
	echo "updates-screen offer: {$GLOBALS['failures']} assertion(s) failed\n";
	exit( 1 );
}

echo "updates-screen offer: all assertions passed\n";
