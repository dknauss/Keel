<?php
/**
 * Network-scoped policy: what a Super Admin decides, and what a site keeps.
 *
 * The whole feature is a resolution order and a lock, so that is what this pins.
 * The failure worth fearing is not a crash — it is a network policy that quietly
 * does nothing on the subsites it was set for, which looks identical to one that
 * works from the screen where it was set.
 *
 * Run: php tests/network-policy.php
 *
 * @package keel
 */

$GLOBALS['keel_options']      = array();
$GLOBALS['keel_site_options'] = array();
$GLOBALS['keel_is_multisite'] = false;
$GLOBALS['keel_caps']         = array();

$fail = 0;

/**
 * Assert helper.
 *
 * Collects rather than exiting, so one run names every failure.
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

// --- stubs ---
/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function add_action( ...$args ) {}
/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function add_filter( ...$args ) {}
/**
 * Stub.
 *
 * @param mixed ...$args Ignored.
 */
function register_activation_hook( ...$args ) {}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function __( $s, $d = null ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 * @return string
 */
function esc_html__( $s, $d = null ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @param string $d Domain.
 */
function esc_html_e( $s, $d = null ) {
	echo $s; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @return string
 */
function esc_html( $s ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $s Text.
 * @return string
 */
function esc_attr( $s ) {
	return $s;
}
/**
 * Stub.
 *
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	return $value;
}
/**
 * Stub.
 *
 * @param string $key     Option.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
/**
 * Stub.
 *
 * @param string $key     Option.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_site_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_site_options'] ) ? $GLOBALS['keel_site_options'][ $key ] : $default;
}
/**
 * Stub.
 *
 * @param string $key   Option.
 * @param mixed  $value Value.
 * @return bool
 */
function update_site_option( $key, $value ) {
	$GLOBALS['keel_site_options'][ $key ] = $value;
	return true;
}
/**
 * Stub.
 *
 * @return bool
 */
function is_multisite() {
	return (bool) $GLOBALS['keel_is_multisite'];
}
/**
 * Stub.
 *
 * @param string $cap Capability.
 * @return bool
 */
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['keel_caps'][ $cap ] );
}
/**
 * Stub.
 *
 * @param mixed $v Value.
 * @return mixed
 */
function sanitize_text_field( $v ) {
	return is_string( $v ) ? trim( strip_tags( $v ) ) : $v; // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}
/**
 * Stub.
 *
 * @param mixed $v Value.
 * @return mixed
 */
function wp_unslash( $v ) {
	return $v;
}
/**
 * Stub.
 *
 * @param string $k Key.
 * @return string
 */
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
/**
 * Stub.
 *
 * @return array
 */
function wp_roles() {
	return new Keel_Network_Test_Roles();
}
/**
 * Minimal wp_roles() stand-in.
 */
class Keel_Network_Test_Roles {
	/**
	 * Roles.
	 *
	 * @var array
	 */
	public $roles = array(
		'subscriber' => array(
			'name'         => 'Subscriber',
			'capabilities' => array( 'read' => true ),
		),
	);
}

/**
 * Stub.
 *
 * @param mixed $v Value.
 * @return int
 */
function absint( $v ) {
	return abs( (int) $v );
}
/**
 * Stub.
 *
 * @param mixed $v Value.
 * @return string
 */
function sanitize_hex_color( $v ) {
	return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $v ) ? $v : '';
}

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

/*
 * --- single site pays nothing, and cannot be governed ---
 *
 * Every default on every request goes through keel_defaults_get(). If the
 * network layer were consulted on single site it would be an extra option read
 * on a feature that install can never use.
 */
$GLOBALS['keel_is_multisite'] = false;
$GLOBALS['keel_site_options'] = array( KEEL_DEFAULTS_NETWORK_OPTION => array( 'require_strong_passwords' => 'no' ) );
$GLOBALS['keel_options']      = array();

keel_assert( array() === keel_defaults_network_settings(), 'On single site there is no network policy, even with the option present.' );
keel_assert( false === keel_defaults_network_manages( 'require_strong_passwords' ), 'And nothing reports as network-managed.' );
keel_assert( 'yes' === keel_defaults_get( 'require_strong_passwords' ), 'A single-site read ignores the network option entirely.' );
keel_assert( null === keel_defaults_network_lock( 'require_strong_passwords' ), 'No lock note on single site.' );

/*
 * --- on a network, policy wins over the site's own value ---
 *
 * The site has deliberately stored the opposite of the network policy. That is
 * the case that matters: a subsite that already saved a value before the Super
 * Admin decided, which is every subsite on an existing network.
 */
$GLOBALS['keel_is_multisite'] = true;
$GLOBALS['keel_options']      = array( KEEL_DEFAULTS_OPTION => array( 'require_strong_passwords' => 'yes' ) );
$GLOBALS['keel_site_options'] = array( KEEL_DEFAULTS_NETWORK_OPTION => array( 'require_strong_passwords' => 'no' ) );

keel_assert( 'no' === keel_defaults_get( 'require_strong_passwords' ), 'Network policy overrides the site\'s stored value.' );
keel_assert( true === keel_defaults_network_manages( 'require_strong_passwords' ), 'The key reports as network-managed.' );
keel_assert( null !== keel_defaults_network_lock( 'require_strong_passwords' ), 'A managed key has a lock note for the site screen.' );

// A key the network does not manage still comes from the site.
keel_assert( 'yes' === keel_defaults_get( 'restrict_rest_user_discovery' ), 'An unmanaged key still resolves from the site, or the schema default.' );
keel_assert( false === keel_defaults_network_manages( 'restrict_rest_user_discovery' ), 'And reports as unmanaged.' );
keel_assert( null === keel_defaults_network_lock( 'restrict_rest_user_discovery' ), 'With no lock note.' );

/*
 * --- unsetting policy returns the site to what it had ---
 *
 * This is the whole argument for resolving at read rather than writing values
 * into subsites. If policy were pushed, the site's own 'yes' would have been
 * overwritten with 'no' and this would answer 'no' forever.
 */
$GLOBALS['keel_site_options'] = array( KEEL_DEFAULTS_NETWORK_OPTION => array() );
keel_assert( 'yes' === keel_defaults_get( 'require_strong_passwords' ), 'Unsetting network policy restores the site\'s own stored value, untouched.' );

/*
 * --- a corrupt network option does not take the site down ---
 */
$GLOBALS['keel_site_options'] = array( KEEL_DEFAULTS_NETWORK_OPTION => 'not-an-array' );
keel_assert( array() === keel_defaults_network_settings(), 'A non-array network option reads as no policy.' );
keel_assert( 'yes' === keel_defaults_get( 'require_strong_passwords' ), 'And the site keeps working.' );

/*
 * --- only a Super Admin may set policy ---
 */
$GLOBALS['keel_caps'] = array();
keel_assert( false === keel_defaults_can_manage_network(), 'A user with no capabilities cannot manage network policy.' );

$GLOBALS['keel_caps'] = array( 'manage_options' => true );
keel_assert(
	false === keel_defaults_can_manage_network(),
	'A site administrator cannot: manage_options is held on their own site, and this decides for every site.'
);

$GLOBALS['keel_caps'] = array( 'manage_network_options' => true );
keel_assert( true === keel_defaults_can_manage_network(), 'A Super Admin can.' );

$GLOBALS['keel_is_multisite'] = false;
keel_assert( false === keel_defaults_can_manage_network(), 'Nobody can on single site, whatever they hold.' );
$GLOBALS['keel_is_multisite'] = true;

/*
 * --- saving stores only what was ticked, through the site sanitizer ---
 *
 * One sanitizer, so a value that a site screen would reject cannot arrive
 * through the network screen instead.
 */
$saved = keel_defaults_sanitize_network(
	array(
		'require_strong_passwords' => 'yes',
		'session_regular_days'     => 5,
		'disable_emojis'           => 'yes',
	),
	array(
		'require_strong_passwords' => '1',
		'session_regular_days'     => '1',
	)
);

keel_assert( array_key_exists( 'require_strong_passwords', $saved ), 'A ticked key is stored.' );
keel_assert( array_key_exists( 'session_regular_days', $saved ), 'So is a ticked number.' );
keel_assert( ! array_key_exists( 'disable_emojis', $saved ), 'An unticked key is not stored, so the site keeps deciding it.' );
keel_assert( 5 === (int) $saved['session_regular_days'], 'The stored value survived sanitizing.' );

/*
 * A value the site sanitizer would clamp is clamped here too, rather than being
 * stored raw because it arrived by a different door.
 *
 * The bound tested is the one the schema actually declares. session_regular_days
 * has a `min` of 1 and no `max` — an unbounded session length is a choice, not a
 * mistake — so asserting against a maximum would have been asserting against a
 * key that does not exist, and passed for the wrong reason.
 */
$schema  = keel_defaults_schema();
$clamped = keel_defaults_sanitize_network(
	array( 'session_regular_days' => 0 ),
	array( 'session_regular_days' => '1' )
);

keel_assert(
	(int) $clamped['session_regular_days'] >= (int) $schema['session_regular_days']['min'],
	'A below-minimum value is clamped by the same sanitizer the site screen uses (got ' . $clamped['session_regular_days'] . ', min ' . $schema['session_regular_days']['min'] . ').'
);

// Nothing ticked stores nothing — the "hand it all back to the sites" case.
keel_assert( array() === keel_defaults_sanitize_network( array( 'require_strong_passwords' => 'yes' ), array() ), 'Ticking nothing stores nothing.' );

/*
 * --- uninstall removes the network option ---
 *
 * It belongs to no subsite, so the per-site loop in uninstall.php cannot reach
 * it: without an explicit delete it outlives the plugin as an orphan.
 */
$uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert(
	false !== strpos( $uninstall, "delete_site_option( 'keel_network_settings' )" ),
	'uninstall.php deletes the network option.'
);

/*
 * --- the screen is registered where only Super Admins can reach it ---
 */
$bootstrap = file_get_contents( dirname( __DIR__ ) . '/includes/bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert(
	false !== strpos( $bootstrap, "add_action( 'network_admin_menu', 'keel_defaults_network_menu' )" ),
	'The policy screen is registered on network_admin_menu, not admin_menu.'
);

$network_src = file_get_contents( dirname( __DIR__ ) . '/includes/network.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert(
	false !== strpos( $network_src, "'manage_network_options'," ),
	'The submenu requires manage_network_options.'
);
keel_assert(
	false !== strpos( $network_src, "check_admin_referer( 'keel-network-save', 'keel_network_nonce' )" ),
	'The save handler checks a nonce.'
);
keel_assert(
	false !== strpos( $network_src, 'keel_defaults_can_manage_network()' ),
	'The save handler checks the capability as well as the nonce — a nonce proves who sent the form, not what they may do.'
);

/*
 * --- the site screen shows a network lock the same way it shows a config lock ---
 *
 * And the constant wins when both apply, so nobody is sent to argue with their
 * network admin about a value wp-config decided.
 */
$settings_src = file_get_contents( dirname( __DIR__ ) . '/includes/settings-page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
keel_assert(
	false !== strpos( $settings_src, 'keel_defaults_network_lock( $key )' ),
	'The site settings screen consults the network lock.'
);
keel_assert(
	false !== strpos( $settings_src, '( null === $lock ) ? keel_defaults_network_lock( $key ) : $lock' ),
	'A wp-config constant takes precedence over the network note when both apply.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "network policy: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "network policy: OK\n" );
