<?php
/**
 * Structural guard for the rendered settings screen.
 *
 * The screen is the plugin's whole interface, and until now nothing rendered it
 * except the heading-case guard. That mattered when the range field was pulled
 * out of the template: the only way to be confident a 105-line extraction had
 * changed nothing was to render before and after and compare — which needed a
 * harness that did not exist yet.
 *
 * This is that harness, kept. It asserts what the screen must contain rather
 * than an exact byte count, because a snapshot hash fails on every deliberate
 * edit and tells you nothing about which part moved.
 *
 * Run: php tests/settings-render.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) {
	return $s; }
function esc_html( $s ) {
	return $s; }
function esc_html__( $s, $d = null ) {
	return $s; }
function esc_html_e( $s, $d = null ) {
	echo $s; }
function esc_attr( $s ) {
	return $s; }
function esc_attr_e( $s, $d = null ) {
	echo $s; }
function esc_js( $s ) {
	return $s; }
function esc_url( $s ) {
	return $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function is_multisite() {
	return false; }
function current_user_can( $cap ) {
	return true; }
function settings_fields( $g ) {}
function submit_button( ...$a ) {}
// Real WP echoes by default and only returns when $echo is false. A stub that
// merely returns silently drops every checked/selected attribute on the screen.
function checked( $a, $b = true, $echo = true ) {
	$out = ( (string) $a === (string) $b ) ? ' checked' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function disabled( $a, $b = true, $echo = true ) {
	$out = ( $a === $b ) ? ' disabled' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function selected( $a, $b = true, $echo = true ) {
	$out = ( (string) $a === (string) $b ) ? ' selected' : '';
	if ( $echo ) {
		echo $out; }
	return $out; }
function wp_kses( $s, $allowed = array() ) {
	return $s; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this stub is what stands in for wp_json_encode().
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function translate_user_role( $n ) {
	return $n; }
function wp_roles() {
	return new Keel_Render_Test_Roles(); }

class Keel_Render_Test_Roles {
	public $roles = array(
		'subscriber' => array(
			'name'         => 'Subscriber',
			'capabilities' => array( 'read' => true ),
		),
		'customer'   => array(
			'name'         => 'Customer',
			'capabilities' => array( 'read' => true ),
		),
	);
}

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/keel.php';

$fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $cond Condition.
 * @param string $msg  Description.
 */
function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/**
 * Render the screen with a given stored option array.
 *
 * @param array $options Stored settings.
 * @return string
 */
function keel_render( $options ) {
	$GLOBALS['keel_options'] = $options ? array( KEEL_DEFAULTS_OPTION => $options ) : array();
	ob_start();
	keel_defaults_render_settings_page();
	return ob_get_clean();
}

/*
 * Two states, because most of the screen's branches are invisible in one.
 * Defaults exercise the unlocked, undependent path; the configured state opens
 * the sections whose dependents hide, moves every non-toggle off its default,
 * and selects a role.
 */
$stock      = keel_render( array() );
$configured = keel_render(
	array(
		'disable_rest'          => 'yes',
		'block_xmlrpc_endpoint' => 'yes',
		'admin_menu_width'      => '200',
		'session_regular_days'  => 5,
		'remember_me_days'      => 30,
		'frame_options'         => 'DENY',
		'password_exempt_roles' => array( 'subscriber' ),
		'disable_remember_me'   => 'yes',
	)
);

foreach ( array(
	'stock'      => $stock,
	'configured' => $configured,
) as $state => $html ) {
	keel_assert( '' !== trim( $html ), "The screen renders in the {$state} state." );

	// Every schema key reaches the form. This is the assertion that would have
	// caught the extraction dropping a field type on the floor.
	foreach ( array_keys( keel_defaults_schema() ) as $key ) {
		keel_assert(
			false !== strpos( $html, KEEL_DEFAULTS_OPTION . '[' . $key . ']' ),
			"[{$state}] Setting '{$key}' has an input on the screen."
		);
	}

	// One control of each type actually rendered, so a branch cannot quietly
	// stop emitting anything.
	keel_assert( false !== strpos( $html, 'type="checkbox"' ), "[{$state}] Toggles render as checkboxes." );
	keel_assert( false !== strpos( $html, '<select ' ), "[{$state}] Selects render." );
	keel_assert( false !== strpos( $html, 'type="number"' ), "[{$state}] Number fields render." );
	keel_assert( false !== strpos( $html, 'type="range"' ), "[{$state}] The range field renders." );

	// The range field's preview is a feature, not decoration: the slider is
	// useless without the stylesheet it toggles and the script that drives it.
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-range"' ), "[{$state}] The range input carries its id." );

	/*
	 * The datalist, the readout and the input are wired to each other by id, and
	 * a broken link between them is silent: the slider still drags, it just
	 * stops showing labels or stops previewing. So assert the element that
	 * *defines* each id, not merely that the string appears somewhere — the
	 * input's own list= and for= attributes contain those strings too, which is
	 * enough to make a careless assertion pass while the target is gone.
	 */
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-stops"' ), "[{$state}] The datalist of stops exists with the id the slider points at." );
	keel_assert( false !== strpos( $html, 'list="admin_menu_width-stops"' ), "[{$state}] The slider points at that datalist." );
	keel_assert( false !== strpos( $html, 'id="admin_menu_width-output"' ), "[{$state}] The live readout exists with the id the slider points at." );
	keel_assert( false !== strpos( $html, 'for="admin_menu_width-range"' ), "[{$state}] The readout is associated with the slider." );
	keel_assert( false !== strpos( $html, 'keel-menu-width-preview' ), "[{$state}] The range field emits its preview styles." );
	keel_assert( false !== strpos( $html, "getElementById( 'admin_menu_width-range' )" ), "[{$state}] The range field emits the script that drives the preview." );

	// Accessible structure.
	keel_assert( substr_count( $html, '<th scope="row">' ) > 25, "[{$state}] Every row carries a heading." );
	keel_assert( false !== strpos( $html, 'screen-reader-text', ), "[{$state}] Fieldsets carry screen-reader legends." );
}

// --- state-dependent behaviour ---

// The multiselect offers only the low-privilege roles wp_roles() defines.
keel_assert( false !== strpos( $stock, 'value="subscriber"' ), 'The exemptions control offers subscriber.' );
keel_assert( false !== strpos( $stock, 'value="customer"' ), 'The exemptions control offers customer.' );

// A stored selection is reflected back.
keel_assert(
	preg_match( '/value="subscriber"[^>]* checked/', $configured ),
	'A stored role exemption renders checked.'
);

// A dependent row starts hidden when its controlling setting says so:
// remember_me_days depends on disable_remember_me, which is on in the
// configured state.
keel_assert(
	preg_match( '/data-keel-dep-field="disable_remember_me"[^>]*style="display:none;"/', $configured ),
	'A dependent row starts hidden when its controlling setting hides it.'
);
keel_assert(
	! preg_match( '/data-keel-dep-field="disable_remember_me"[^>]*style="display:none;"/', $stock ),
	'That same row is visible when the controlling setting does not hide it.'
);

// A non-default select value is selected, not silently reset.
keel_assert(
	preg_match( '/value="DENY"[^>]* selected/', $configured ),
	'A configured select value renders as the selected option.'
);

/*
 * --- accessibility of the one control that is not a checkbox ---
 *
 * The width slider stores a position and means a word. Before the first
 * accessibility sweep it announced "2" to a screen reader while the visible
 * output beside it read "240px", because the value and the label were different
 * things and only one of them was exposed. aria-valuetext is what closes that,
 * and it has to track the stored value rather than sit at whatever the field
 * was first rendered with — which is the part a static assertion on the default
 * state would not notice.
 */
keel_assert(
	preg_match( '/type="range"[^>]*aria-valuetext="WordPress default \(160px\)"/s', $stock ),
	'The slider announces its position as a word, not an index, at the default.'
);
keel_assert(
	preg_match( '/type="range"[^>]*aria-valuetext="200px"/s', $configured ),
	'And the announced word follows the stored value rather than the rendered default.'
);

/*
 * The output stays visible and stops being a live region. aria-valuetext already
 * announces the word on the focused slider, so leaving <output> live (which it is
 * implicitly) said it twice on every arrow-key press.
 */
keel_assert(
	preg_match( '/<output[^>]*aria-live="off"/', $stock ),
	'The visible output is not also a live region, so the word is announced once.'
);

/*
 * Every grouping has an accessible name. The slider's fieldset was the only one
 * of 28 without a legend — invisible in review because the row looked complete
 * and the input carried its own aria-label.
 */
foreach ( array(
	'stock'      => $stock,
	'configured' => $configured,
) as $state => $markup ) {
	preg_match_all( '#<fieldset.*?</fieldset>#s', $markup, $fm );
	$unnamed = array_filter(
		$fm[0],
		static function ( $block ) {
			return false === strpos( $block, '<legend' );
		}
	);

	keel_assert(
		array() === $unnamed,
		count( $unnamed ) . " fieldset(s) in the {$state} state have no <legend>, so the group has no accessible name."
	);
}

/*
 * --- a locked control announces why, and refuses to be written ---
 *
 * `disabled` takes a control out of the tab sequence, so the aria-describedby
 * wiring that names the reason is announced on focus that never happens. The
 * reason was deliberately put first in that attribute and then could not be
 * heard by anyone.
 *
 * aria-disabled keeps it focusable. That makes it submittable, which is only
 * safe because the lock is now enforced on save — so both halves are pinned
 * here, and the second is the one that turns a presentational lock into a real
 * one.
 */
// DISALLOW_FILE_MODS locks the two update settings, which is how a real site
// reaches this branch. Defined here, at the end, because a constant cannot be
// undefined and every render above must see the unlocked screen.
define( 'DISALLOW_FILE_MODS', true );

$locked_html = keel_render( array() );

keel_assert(
	null !== keel_defaults_config_lock( 'core_update_policy' ),
	'The constant produced a real lock to render (otherwise everything below passes vacuously).'
);
keel_assert(
	false !== strpos( $locked_html, 'aria-disabled="true"' ),
	'A locked control is marked aria-disabled, so it stays focusable and its reason can be announced.'
);

/*
 * Matched as an attribute, not as a substring, and in a way that survives both
 * spellings. Real WordPress emits disabled='disabled'; the stub in this file
 * emits a bare ` disabled`. The first version of this assertion looked only for
 * the real-WordPress spelling, so reverting the fix passed it clean — the test
 * was checking a format the harness never produces.
 */

/*
 * Scanned inside the control tags only, and in a way that survives both
 * spellings. Real WordPress emits disabled='disabled'; the stub in this file
 * emits a bare ` disabled`. The first version looked only for the real spelling
 * and passed when the fix was reverted; the second scanned the whole page and
 * failed on the word "disabled" inside an inline script comment. The thing being
 * asserted is a control attribute, so match control tags.
 */
preg_match_all( '/<(?:input|select)\b[^>]*>/', $locked_html, $control_tags );
$still_disabled = array_filter(
	$control_tags[0],
	static function ( $tag ) {
		return 1 === preg_match( '/(?<!aria-)\bdisabled\b/', $tag );
	}
);

keel_assert( count( $control_tags[0] ) > 20, 'The locked render produced controls to inspect (' . count( $control_tags[0] ) . ').' );
keel_assert(
	array() === $still_disabled,
	count( $still_disabled ) . ' control(s) still use the disabled attribute, which takes them out of the tab sequence: ' . substr( (string) reset( $still_disabled ), 0, 90 )
);
keel_assert(
	false !== strpos( $locked_html, 'data-keel-locked="1"' ),
	'The locked control is marked for the script that refuses changes to it.'
);
keel_assert(
	false !== strpos( $locked_html, "querySelectorAll( '[data-keel-locked]' )" ),
	'That script is emitted.'
);

/*
 * --- and the lock is real, not presentational ---
 *
 * A focusable control is a submittable one. Before this, the lock existed only
 * in the rendered attribute: a crafted POST wrote a locked setting happily. It
 * never took effect, because the constant wins when the value is read, but the
 * stored value drifted from what the screen showed and the lock was a
 * suggestion. This is the half that makes the change above safe.
 */
$GLOBALS['keel_options'] = array( KEEL_DEFAULTS_OPTION => array( 'core_update_policy' => 'minor' ) );

$attempt = keel_defaults_sanitize_site( array( 'core_update_policy' => 'all' ) );
keel_assert(
	'minor' === $attempt['core_update_policy'],
	"A POST for a locked setting keeps the stored value ('" . $attempt['core_update_policy'] . "'), rather than writing what was submitted."
);

// An unlocked setting on the same submission still saves, or the guard is just
// refusing everything.
$attempt2 = keel_defaults_sanitize_site(
	array(
		'core_update_policy' => 'all',
		'disable_emojis'     => 'yes',
	)
);
keel_assert( 'yes' === $attempt2['disable_emojis'], 'An unlocked setting on the same submission still saves.' );

/*
 * --- dependent rows say what governs them ---
 *
 * A row that appears had no programmatic relationship to the choice that
 * produced it: the link was a data attribute this plugin's own script reads,
 * which assistive technology cannot see. The row now carries an id so the
 * controlling input can point aria-controls at it.
 */
preg_match_all( '/<tr[^>]*data-keel-dep-field="([a-z_]+)"[^>]*>/', $stock, $dep_rows, PREG_SET_ORDER );

keel_assert( count( $dep_rows ) > 0, 'The screen renders dependent rows to check (' . count( $dep_rows ) . ').' );

foreach ( $dep_rows as $dep_row ) {
	keel_assert(
		false !== strpos( $dep_row[0], 'id="keel-dep-' ),
		'A dependent row carries an id, so aria-controls has something to point at: ' . substr( $dep_row[0], 0, 90 )
	);
}

/*
 * The script has to actually *set* them, or the ids are decoration.
 *
 * Asserting that the string "aria-controls" appears is not the same assertion:
 * the script also reads it back with getAttribute to append, so deleting the
 * setter left the getter behind and the check passed. Match the write.
 */
foreach ( array( 'aria-controls', 'aria-expanded' ) as $wiring ) {
	keel_assert(
		false !== strpos( $stock, "setAttribute( '{$wiring}'" ),
		"The dependent-row script sets {$wiring}, rather than only reading it."
	);
}

if ( $fail > 0 ) {
	fwrite( STDERR, "settings render: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'settings render: OK (two states, ' . count( keel_defaults_schema() ) . " settings each)\n" );
