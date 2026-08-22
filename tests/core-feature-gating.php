<?php
/**
 * A default that depends on a core feature only shows where that feature exists.
 *
 * `disable_ai_connectors` turns off the AI provider connectors WordPress 7.0
 * introduced, through the `wp_supports_ai` gate. On 6.4 — still inside the
 * floor this plugin declares — there are no connectors, no gate and no
 * Connectors screen, so the toggle rendered a control that could not do
 * anything and Site Health reported a posture the site did not have.
 *
 * Raising `Requires at least` to 7.0 would have fixed it by locking out every
 * site below 7.0 to serve one setting out of 38; the other 37 work on 6.4. So
 * the schema entry names the core function it needs and the screens skip it
 * when that function is absent. It is a capability probe, not a version
 * compare: core's own `wp_supports_ai()` is the exact thing being extended, so
 * nothing has to be kept in step with a release schedule.
 *
 * Gating is display-only, deliberately. The key stays in the schema — the
 * schema is the map, and `tests/default-count.php`, `tests/docs-consistency.php`
 * and the seeding in `lifecycle.php` all read it — so a site that upgrades to
 * 7.0 finds the setting already there at its documented default rather than
 * missing.
 *
 * Both states have to be exercised and a function cannot be undefined once
 * declared, so the unsupported state is this process and the supported state is
 * a second one: the file re-runs itself with KEEL_TEST_HAS_AI=1, which defines
 * the stub before the plugin loads.
 *
 * Run: php tests/core-feature-gating.php
 *
 * @package keel
 */

$has_ai = (bool) getenv( 'KEEL_TEST_HAS_AI' );

if ( $has_ai ) {
	/**
	 * Stand in for the core function WordPress 7.0 added.
	 *
	 * @return bool
	 */
	function wp_supports_ai() {
		return true;
	}
}

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
function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, (int) $d ); }
function _n( $single, $plural, $number, $d = null ) {
	return 1 === (int) $number ? $single : $plural; }
function wp_kses( $s, $allowed = array() ) {
	return $s; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this stub is what stands in for wp_json_encode().
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function translate_user_role( $n ) {
	return $n; }
function wp_roles() {
	return new Keel_Gating_Test_Roles(); }

class Keel_Gating_Test_Roles {
	public $roles = array(
		'subscriber' => array(
			'name'         => 'Subscriber',
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

$state  = $has_ai ? 'core has AI connectors' : 'core has no AI connectors';
$schema = keel_defaults_schema();

// --- the schema declares the dependency, rather than the screens knowing it ---

keel_assert(
	isset( $schema['disable_ai_connectors']['requires'] ),
	'disable_ai_connectors names the core function it depends on, in the schema.'
);

keel_assert(
	isset( $schema['disable_ai_connectors']['requires'] ) && 'wp_supports_ai' === $schema['disable_ai_connectors']['requires'],
	'The dependency named is wp_supports_ai — the gate the setting actually filters.'
);

// --- the key is still in the map either way ---

/*
 * The whole point of gating display rather than the schema. Drop the key from
 * the schema on old core and a site that upgrades has no stored value, the
 * reference doc describes a key that does not exist, and the count guards
 * disagree with the documents depending on which WordPress ran the test.
 */
keel_assert(
	isset( $schema['disable_ai_connectors'] ),
	"[{$state}] The key stays in the schema — gating is display-only."
);

keel_assert(
	38 === count( $schema ),
	"[{$state}] The schema is the same size on either core (" . count( $schema ) . ')."'
);

// --- the probe itself ---

keel_assert(
	function_exists( 'keel_defaults_key_supported' ),
	'keel_defaults_key_supported() exists.'
);

if ( function_exists( 'keel_defaults_key_supported' ) ) {
	keel_assert(
		keel_defaults_key_supported( 'disable_ai_connectors' ) === $has_ai,
		"[{$state}] keel_defaults_key_supported( 'disable_ai_connectors' ) is " . ( $has_ai ? 'true' : 'false' ) . '.'
	);

	// A requirement nobody declared must never gate anything. This is the
	// assertion that catches a typo'd `requires` value quietly hiding a
	// setting that has no dependency at all.
	$unsupported = array();

	foreach ( array_keys( $schema ) as $key ) {
		if ( ! keel_defaults_key_supported( $key ) ) {
			$unsupported[] = $key;
		}
	}

	$expected = $has_ai ? array() : array( 'disable_ai_connectors' );

	keel_assert(
		$expected === $unsupported,
		"[{$state}] Exactly the settings whose requirement is unmet are gated (got: " . ( $unsupported ? implode( ', ', $unsupported ) : 'none' ) . ').'
	);
}

// --- the settings screen ---

$GLOBALS['keel_options'] = array();
ob_start();
keel_defaults_render_settings_page();
$html = (string) ob_get_clean();

keel_assert( '' !== trim( $html ), "[{$state}] The screen renders." );

$rendered = false !== strpos( $html, 'disable_ai_connectors' );

keel_assert(
	$has_ai === $rendered,
	$has_ai
		? 'The AI Connectors control renders where core has connectors.'
		: 'The AI Connectors control is absent where core has none — it could not do anything there.'
);

// The rest of the screen is unaffected either way; a gate that takes its
// neighbours with it is the failure mode worth naming.
foreach ( array( 'disable_comments', 'frame_options', 'admin_menu_width' ) as $neighbour ) {
	keel_assert(
		false !== strpos( $html, $neighbour ),
		"[{$state}] {$neighbour} still renders — the gate took only its own row."
	);
}

// --- Site Health ---

$posture = keel_defaults_posture_by_group();
$keys    = array();

foreach ( $posture as $rows ) {
	foreach ( $rows as $row ) {
		$keys[] = $row['key'];
	}
}

keel_assert(
	in_array( 'disable_ai_connectors', $keys, true ) === $has_ai,
	$has_ai
		? 'Site Health reports the AI Connectors posture where core has connectors.'
		: 'Site Health does not report an AI Connectors posture the site cannot have.'
);

keel_assert(
	in_array( 'disable_comments', $keys, true ),
	"[{$state}] Site Health still reports the ungated settings."
);

// --- run the other half, once ---

if ( ! $has_ai ) {
	$cmd = 'KEEL_TEST_HAS_AI=1 ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ );
	exec( $cmd, $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- re-running this same test file in a second process is the only way to have the core function both absent and present.

	if ( 0 !== $code ) {
		++$fail;
		fwrite( STDERR, "The supported-core pass failed:\n" . implode( "\n", $out ) . "\n" );
	}
}

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "core feature gating: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

if ( $has_ai ) {
	fwrite( STDOUT, "core feature gating: OK (core has AI connectors)\n" );
} else {
	fwrite( STDOUT, "core feature gating: OK (both core states)\n" );
}
