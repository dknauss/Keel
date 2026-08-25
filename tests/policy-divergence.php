<?php
/**
 * Passive detection of a Keel setting that is not taking effect.
 *
 * Some plugins turn a feature off by handing one of WordPress's own helpers to a
 * filter. `__return_false` resolves to wp-includes, so reflection cannot say
 * which plugin registered it, and the overlap check stays silent — correctly,
 * because it has nothing to name.
 *
 * It can still say something true. Keel is registered on every hook it governs,
 * so its own callbacks run when WordPress runs the filter, with the real
 * arguments, in the real request. Comparing the value the chain settles on
 * against the value Keel's setting asks for costs a comparison and executes
 * nobody else's code.
 *
 * The distinction this file exists to hold: observing a filter WordPress is
 * already running is not the same as calling one. The second was built, shipped
 * and withdrawn — see the 0.5.1 notes — and nothing here may drift back into it.
 *
 * Run: php tests/policy-divergence.php
 *
 * @package keel
 */

$GLOBALS['keel_options']    = array();
$GLOBALS['keel_transients'] = array();
$GLOBALS['keel_filters']    = array();
$GLOBALS['wp_filter']       = array();
$GLOBALS['keel_foreign']    = 0;

define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/keel-divergence-' . getmypid() );
define( 'KEEL_DEFAULTS_OPTION', 'keel_settings' );
define( 'KEEL_DEFAULTS_NETWORK_OPTION', 'keel_network_settings' );

function add_action( ...$a ) {}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['wp_filter'][ $hook ][ $prio ][] = $cb; }
function register_activation_hook( ...$a ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr( $s ) { return $s; }
function is_multisite() { return false; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function trailingslashit( $p ) { return rtrim( (string) $p, '/\\' ) . '/'; }
function is_customize_preview() { return false; }
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $k ] : $d; }
function get_transient( $k ) {
	++$GLOBALS['keel_transient_reads'];
	return array_key_exists( $k, $GLOBALS['keel_transients'] ) ? $GLOBALS['keel_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) {
	++$GLOBALS['keel_transient_writes'];
	$GLOBALS['keel_transients'][ $k ] = $v;
	return true; }
function delete_transient( $k ) {
	unset( $GLOBALS['keel_transients'][ $k ] );
	return true; }
function apply_filters( $hook, $value ) {
	if ( array_key_exists( $hook, $GLOBALS['keel_filters'] ) ) {
		return $GLOBALS['keel_filters'][ $hook ];
	}
	foreach ( isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : array() as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = call_user_func( $cb, $value );
		}
	}
	return $value; }

require dirname( __DIR__ ) . '/includes/schema.php';
require dirname( __DIR__ ) . '/includes/conflicts.php';
require dirname( __DIR__ ) . '/includes/site-health.php';

$GLOBALS['keel_transient_writes'] = 0;
$GLOBALS['keel_transient_reads']  = 0;
$fail                             = 0;

function keel_assert( $cond, $msg ) {
	global $fail;
	if ( ! $cond ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
	}
}

/** A foreign callback that disables something the untraceable way. */
function keel_divergence_foreign( $value ) {
	++$GLOBALS['keel_foreign'];
	return false;
}

// --- the expectation map is settings-derived, not hardcoded per plugin ---

$expectations = keel_defaults_policy_expectations();

keel_assert( ! empty( $expectations ), 'Hooks with a knowable expected value are declared.' );
keel_assert(
	array_key_exists( 'xmlrpc_enabled', $expectations ),
	'xmlrpc_enabled is one of them — the case that prompted this.'
);

/*
 * comments_open and pings_open are the same question asked twice.
 *
 * Both are booleans, both are decided by disable_comments alone, and both are
 * registered together in the same bootstrap block — so an expectation for one
 * and not the other is an omission rather than a judgement. pings_open was the
 * missing half: a plugin forcing pingbacks back open while Keel's comments
 * default said otherwise produced no divergence record and nothing on screen.
 */
foreach ( array( 'comments_open', 'pings_open' ) as $comment_hook ) {
	keel_assert(
		array_key_exists( $comment_hook, $expectations ),
		"{$comment_hook} has an expectation: it is a boolean that disable_comments alone decides."
	);
}

// --- nothing overriding: nothing reported, nothing written ---

$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'yes' );
$writes_before                            = $GLOBALS['keel_transient_writes'];

keel_defaults_observe_policy_result( 'xmlrpc_enabled', true );

keel_assert( array() === keel_defaults_policy_divergences(), 'A setting that takes effect is not reported.' );
keel_assert( $writes_before === $GLOBALS['keel_transient_writes'], 'And nothing is written on a site with no divergence.' );

// --- the reported case: Keel says allow, the chain says no ---

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

$divergences = keel_defaults_policy_divergences();

keel_assert(
	array_key_exists( 'xmlrpc_enabled', $divergences ),
	'A setting that is not producing the configured value is recorded.'
);
keel_assert(
	0 === $GLOBALS['keel_foreign'],
	'And no foreign callback was invoked to find that out.'
);

// --- written once, not on every request ---

$writes_after = $GLOBALS['keel_transient_writes'];
keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );
keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	$writes_after === $GLOBALS['keel_transient_writes'],
	'An unchanged divergence is not rewritten on every request.'
);

/*
 * --- the healthy path touches no storage at all ---
 *
 * This is the cost of the feature on every ordinary request, so it has to be
 * nothing. Reading first, to find out whether a past divergence needed clearing,
 * put a query on every front-end page load — measured, constant, whether or not
 * anything was wrong. Clearing is the record expiring instead.
 */
$GLOBALS['keel_transient_reads'] = 0;
$writes_healthy                  = $GLOBALS['keel_transient_writes'];

keel_defaults_observe_policy_result( 'xmlrpc_enabled', true );

keel_assert(
	0 === $GLOBALS['keel_transient_reads'],
	'A setting that is working reads nothing.'
);
keel_assert(
	$writes_healthy === $GLOBALS['keel_transient_writes'],
	'And writes nothing.'
);

// --- a setting Keel is not governing is not Keel's business ---

$GLOBALS['keel_transients']               = array();
$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'no' );

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	array() === keel_defaults_policy_divergences(),
	'A value Keel itself asked for is not a divergence.'
);

/*
 * --- a record outlives its cause for as short a time as possible ---
 *
 * The TTL is a backstop, not the mechanism. A divergence can only start or stop
 * when something changes, and every one of those moments is an event: a plugin
 * is activated or deactivated, or the setting itself is saved. Clearing there
 * costs nothing on an ordinary request, which is the property the healthy path
 * was just restructured to get.
 */
$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'yes' );
$GLOBALS['keel_transients']               = array();

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );
keel_assert( array() !== keel_defaults_policy_divergences(), 'A divergence is recorded to begin with.' );

keel_defaults_forget_policy_divergences();

keel_assert(
	array() === keel_defaults_policy_divergences(),
	'Changing the plugin set drops the record rather than waiting for it to expire.'
);

/*
 * --- and a record made under a different expectation is not trusted ---
 *
 * Free, because it needs no extra read: the expectation is stored beside the
 * hook, so a setting since changed invalidates the record on the way out. This
 * is the case the TTL cannot catch at all — flipping the setting to match what
 * the other plugin was doing would otherwise leave "not taking effect" standing
 * for an hour, about a setting that is now being honoured.
 */
keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );
keel_assert( array() !== keel_defaults_policy_divergences(), 'Recorded again, under "allow".' );

$GLOBALS['keel_options']['keel_settings'] = array( 'xmlrpc_allow_remote_publishing' => 'no' );

keel_assert(
	array() === keel_defaults_policy_divergences(),
	'A record made under the opposite setting is discarded on read.'
);

/*
 * --- a record survives another one being written ---
 *
 * The stored value is the expectation, not a flag, so a hook expected to be
 * false is recorded as false. The write path was reading through
 * keel_defaults_policy_divergences(), which normalises every live entry to true
 * before returning it — so writing a second divergence rewrote the first one's
 * expectation to true, and the next read discarded it for disagreeing with
 * itself. Recording one thing erased another.
 */
$GLOBALS['keel_transients']               = array();
$GLOBALS['keel_options']['keel_settings'] = array(
	'disable_comments'               => 'yes',   // expects comments_open false
	'xmlrpc_allow_remote_publishing' => 'yes',   // expects xmlrpc_enabled true
);

keel_defaults_observe_policy_result( 'comments_open', true );
keel_assert(
	array_key_exists( 'comments_open', keel_defaults_policy_divergences() ),
	'A hook expected to be false is recorded when it comes back true.'
);

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

$both = keel_defaults_policy_divergences();

keel_assert(
	array_key_exists( 'xmlrpc_enabled', $both ),
	'The second divergence is recorded.'
);
keel_assert(
	array_key_exists( 'comments_open', $both ),
	'And recording it does not erase the first.'
);

/*
 * --- a record does not outlive the plugin set that produced it ---
 *
 * Deactivating network-wide clears the transient on the site the request ran
 * on, and nowhere else; every other subsite kept reporting until the TTL. The
 * event hooks stay for promptness, but correctness cannot depend on the right
 * site having handled the request, so the record carries a fingerprint of the
 * plugins active when it was made and is discarded when that no longer matches.
 */
$GLOBALS['keel_options']['active_plugins'] = array( 'rival/rival.php' );
$GLOBALS['keel_transients']                = array();

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );
keel_assert( array() !== keel_defaults_policy_divergences(), 'Recorded against the current plugin set.' );

$GLOBALS['keel_options']['active_plugins'] = array();

keel_assert(
	array() === keel_defaults_policy_divergences(),
	'A record made under a different set of active plugins is not reported.'
);

/*
 * --- and the still-diverging case is re-recorded, not left mute ---
 *
 * The reader discards a record whose fingerprint no longer matches. The writer
 * was checking the same record for the hook and returning early when it found
 * it, without checking that fingerprint — so once the plugin set changed, the
 * reader ignored the record and the writer declined to replace it. A divergence
 * that was still happening on every request went unreported until the hour ran
 * out. The event hooks hide this on the site that served the activation; a
 * network-wide change leaves every other subsite in exactly this state.
 */
$GLOBALS['keel_options']['active_plugins'] = array( 'rival/rival.php', 'another/another.php' );

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	array_key_exists( 'xmlrpc_enabled', keel_defaults_policy_divergences() ),
	'A divergence still happening under a new plugin set is recorded again rather than waiting out the TTL.'
);

/*
 * --- Keel never names itself ---
 *
 * The overlap check told Keel's callbacks from everyone else's by matching
 * priority, callable identity and argument count against what Keel recorded
 * when it registered. The divergence observers go on with a bare add_filter(),
 * so they were in $wp_filter and not in that record — and reflection resolved
 * them, correctly, to Keel's own directory. Keel reported itself as a plugin
 * competing with Keel, on every hook it watches. Seen on a real install:
 * "Also registered on these policy hooks: keel-defaults".
 *
 * The signature match cannot cover this, because it only knows about callbacks
 * that went through keel_defaults_add_policy_filter(). The directory is true
 * however the callback came to be registered.
 */
keel_assert(
	'' !== keel_defaults_own_plugin_dir(),
	'Keel can name its own directory, which is what the self-exclusion rests on.'
);

keel_defaults_watch_policy_results();

$watched = array_keys( keel_defaults_policy_expectations() );

keel_assert(
	! empty( $watched ),
	'There is at least one watched hook to check.'
);

foreach ( $watched as $hook ) {
	$observers = isset( $GLOBALS['wp_filter'][ $hook ][ PHP_INT_MAX ] )
		? $GLOBALS['wp_filter'][ $hook ][ PHP_INT_MAX ]
		: array();

	keel_assert(
		! empty( $observers ),
		"An observer is registered on {$hook}, which is what got misattributed."
	);
}

/*
 * --- a setting that is not taking effect is not a clean bill of health ---
 *
 * The Site Health status was decided by the attributable overlaps alone. With
 * none of those, the check returned "good" and the label "No attributable
 * policy overlap was found" — while the body of the same report said a setting
 * was not taking effect. A green badge over the words "not taking effect" is
 * the one combination guaranteed not to be read.
 *
 * This was hidden for as long as Keel was naming itself as a competing plugin,
 * because that kept the overlap list non-empty and the status orange for the
 * wrong reason. Removing the false positive is what exposed it.
 */
$GLOBALS['keel_transients']                = array();
$GLOBALS['keel_options']['active_plugins'] = array( 'rival/rival.php' );

keel_defaults_observe_policy_result( 'xmlrpc_enabled', false );

keel_assert(
	! empty( keel_defaults_policy_divergences() ),
	'The divergence is recorded, so the status can be judged against it.'
);

$health = keel_defaults_site_health_conflicts();

keel_assert(
	'good' !== $health['status'],
	'A setting that is not taking effect is not reported as good, even with nothing attributable to name.'
);

keel_assert(
	false === strpos( $health['label'], 'No attributable policy overlap was found' ),
	'And the label does not say nothing was found while the body says a setting is being overridden.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, sprintf( "policy divergence: %d assertion%s failed\n", $fail, 1 === $fail ? '' : 's' ) );
	exit( 1 );
}

fwrite( STDOUT, "policy divergence: OK (no foreign callback invoked)\n" );
