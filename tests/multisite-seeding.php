<?php
/**
 * Seeding across single-site and network installs.
 *
 * The cases worth pinning are the ones that look fine when they are wrong.
 * A network activation that seeds only the current site leaves every other
 * subsite with no stored settings — and that reads as correct, because
 * keel_defaults_get() falls back to the schema default, so the settings screen
 * shows the documented values and the site behaves as documented. It only
 * surfaces when the schema changes in a later release and those sites move with
 * it while the seeded ones keep what they were given.
 *
 * Run: php tests/multisite-seeding.php
 *
 * @package keel
 */

$GLOBALS['keel_options']        = array();
$GLOBALS['keel_sites']          = array();   // site id => option store.
$GLOBALS['keel_current_site']   = 1;
$GLOBALS['keel_is_multisite']   = false;
$GLOBALS['keel_network_active'] = false;
$GLOBALS['keel_switch_log']     = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function is_multisite() { return $GLOBALS['keel_is_multisite']; }
function plugin_basename( $file ) { return 'keel/keel.php'; }
function is_plugin_active_for_network( $basename ) { return $GLOBALS['keel_network_active']; }

/**
 * Options are per site, so the store is keyed by the current site id.
 *
 * @return array<string,mixed>
 */
function keel_test_store() {
	$id = $GLOBALS['keel_current_site'];
	if ( ! isset( $GLOBALS['keel_sites'][ $id ] ) ) {
		$GLOBALS['keel_sites'][ $id ] = array();
	}
	return $GLOBALS['keel_sites'][ $id ];
}

function get_option( $key, $default = false ) {
	$store = keel_test_store();
	return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
}

function add_option( $key, $value ) {
	$id = $GLOBALS['keel_current_site'];
	if ( isset( $GLOBALS['keel_sites'][ $id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['keel_sites'][ $id ][ $key ] = $value;
	return true;
}

function update_option( $key, $value ) {
	$id                                   = $GLOBALS['keel_current_site'];
	$GLOBALS['keel_sites'][ $id ][ $key ] = $value;
	return true;
}

function get_sites( $args = array() ) {
	$ids = array_keys( $GLOBALS['keel_sites'] );
	sort( $ids );
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
	$number = isset( $args['number'] ) ? (int) $args['number'] : count( $ids );
	return array_slice( $ids, $offset, $number );
}

function switch_to_blog( $id ) {
	$GLOBALS['keel_switch_log'][] = (int) $id;
	$GLOBALS['keel_current_site'] = (int) $id;
}

function restore_current_blog() {
	$GLOBALS['keel_current_site'] = 1;
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Reset the fake network between cases.
 *
 * @param int[] $site_ids Sites that exist.
 */
function keel_reset_network( $site_ids ) {
	$GLOBALS['keel_sites']        = array();
	$GLOBALS['keel_switch_log']   = array();
	$GLOBALS['keel_current_site'] = 1;
	foreach ( $site_ids as $id ) {
		$GLOBALS['keel_sites'][ $id ] = array();
	}
}

// --- single site ---
$GLOBALS['keel_is_multisite'] = false;
keel_reset_network( array( 1 ) );
keel_defaults_activate( false );
keel_assert( isset( $GLOBALS['keel_sites'][1]['keel_settings'] ), 'Single-site activation seeds the site.' );
keel_assert( array() === $GLOBALS['keel_switch_log'], 'Single-site activation never switches blogs.' );

$seeded = $GLOBALS['keel_sites'][1]['keel_settings'];
$schema = keel_defaults_schema();
keel_assert( count( $seeded ) === count( $schema ), 'Every schema key is seeded.' );
keel_assert( $seeded['disable_comments'] === $schema['disable_comments']['default'], 'Seeded values are the schema defaults.' );
keel_assert( 10 === $seeded['post_revisions_limit'], 'New activations seed the recommended ten-revision limit.' );
keel_assert( KEEL_DEFAULTS_DATA_VERSION === $GLOBALS['keel_sites'][1][ KEEL_DEFAULTS_DATA_VERSION_OPTION ], 'Activation records the current data version.' );

// --- upgrade: established sites keep WordPress's previous unlimited policy ---
$GLOBALS['keel_sites'][1] = array(
	'keel_settings' => array( 'disable_comments' => 'yes' ),
);
keel_assert( true === keel_defaults_maybe_upgrade(), 'An old settings array is migrated before bootstrap reads it.' );
keel_assert( -1 === $GLOBALS['keel_sites'][1]['keel_settings']['post_revisions_limit'], 'Existing installs preserve unlimited revisions on upgrade.' );
keel_assert( false === keel_defaults_maybe_upgrade(), 'The migration is idempotent.' );

// A site with no settings was never seeded; migration must not activate policy
// behind activation's back.
$GLOBALS['keel_sites'][1] = array();
keel_assert( false === keel_defaults_maybe_upgrade(), 'Migration leaves an unseeded site alone.' );
keel_assert( ! isset( $GLOBALS['keel_sites'][1]['keel_settings'] ), 'An unseeded site remains unseeded.' );

// Restore an activated site for the reactivation cases below.
keel_defaults_activate( false );

// --- reactivation must not overwrite a configured site ---
// Asserted on the return value, not just the stored data. add_option() refuses
// to overwrite on its own, so checking only the data would pass with the guard
// deleted — it would be testing WordPress's semantics rather than this code's.
keel_assert( false === keel_defaults_seed_site(), 'Seeding reports false when settings already exist.' );

$GLOBALS['keel_sites'][1]['keel_settings']['disable_comments'] = 'CONFIGURED';
keel_defaults_activate( false );
keel_assert(
	'CONFIGURED' === $GLOBALS['keel_sites'][1]['keel_settings']['disable_comments'],
	'Reactivating never overwrites settings somebody has already configured.'
);

// And true on a site that has none, so the false above means something.
$GLOBALS['keel_sites'][99] = array();
switch_to_blog( 99 );
keel_assert( true === keel_defaults_seed_site(), 'Seeding reports true when it writes.' );
restore_current_blog();
unset( $GLOBALS['keel_sites'][99] );

// --- network activation seeds every existing site ---
$GLOBALS['keel_is_multisite'] = true;
keel_reset_network( array( 1, 2, 3 ) );
keel_defaults_activate( true );
foreach ( array( 1, 2, 3 ) as $id ) {
	keel_assert( isset( $GLOBALS['keel_sites'][ $id ]['keel_settings'] ), "Network activation seeds site {$id}." );
}
keel_assert( in_array( 1, $GLOBALS['keel_switch_log'], true ), 'Every site is switched to, including the current one.' );

// --- per-site activation on multisite touches only the current site ---
keel_reset_network( array( 1, 2, 3 ) );
keel_defaults_activate( false );
keel_assert( isset( $GLOBALS['keel_sites'][1]['keel_settings'] ), 'Per-site activation seeds the site it ran on.' );
keel_assert( ! isset( $GLOBALS['keel_sites'][2]['keel_settings'] ), 'Per-site activation leaves other sites alone.' );

// --- paging: a network larger than one page is fully covered ---
keel_reset_network( range( 1, 250 ) );
keel_defaults_activate( true );
$unseeded = 0;
foreach ( array_keys( $GLOBALS['keel_sites'] ) as $id ) {
	if ( ! isset( $GLOBALS['keel_sites'][ $id ]['keel_settings'] ) ) {
		++$unseeded;
	}
}
keel_assert( 0 === $unseeded, "Paging covers every site on a large network ({$unseeded} missed)." );

// --- a subsite created later ---
$GLOBALS['keel_network_active'] = true;
keel_reset_network( array( 1 ) );
$GLOBALS['keel_sites'][7] = array();
keel_defaults_seed_new_site( (object) array( 'blog_id' => 7 ) );
keel_assert( isset( $GLOBALS['keel_sites'][7]['keel_settings'] ), 'A subsite created after activation is seeded.' );
keel_assert( 1 === $GLOBALS['keel_current_site'], 'The current site is restored afterwards.' );

// Not network-active: the new subsite does not have Keel, so writing settings
// into it would leave an orphaned option for a plugin that never runs there.
$GLOBALS['keel_network_active'] = false;
keel_reset_network( array( 1 ) );
$GLOBALS['keel_sites'][8] = array();
keel_defaults_seed_new_site( (object) array( 'blog_id' => 8 ) );
keel_assert( ! isset( $GLOBALS['keel_sites'][8]['keel_settings'] ), 'A new subsite is not seeded when Keel is only activated per site.' );

// Garbage in, nothing out — wp_initialize_site is a public hook and anything
// can fire it.
$GLOBALS['keel_network_active'] = true;
keel_reset_network( array( 1 ) );
keel_defaults_seed_new_site( null );
keel_defaults_seed_new_site( (object) array() );
keel_assert( 1 === $GLOBALS['keel_current_site'], 'A malformed site object leaves the current site unchanged.' );

fwrite( STDOUT, "multisite seeding: OK\n" );
