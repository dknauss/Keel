<?php
/**
 * Every reported policy hook is registered with a callable only Keel owns.
 *
 * WordPress keys a hook's callbacks by callable identity. Two plugins calling
 * `add_filter( 'comments_open', '__return_false', 20 )` do not produce two
 * entries — the second registration overwrites the first under the same key,
 * and `$wp_filter` ends up holding one. The overlap report then excludes that
 * entry as Keel's own, and the other plugin is not merely unattributable, it is
 * absent: no name, no "unattributed callback", nothing.
 *
 * Measured on a real install before this guard existed: three comment-disabling
 * plugins active, and simply-disable-comments — which registers exactly that
 * callable at exactly that priority — appeared nowhere in the report. The same
 * shape hid disable-gutenberg on `use_block_editor_for_post`.
 *
 * A callable only Keel registers cannot collapse into anyone else's, so a rival
 * on the same hook always keeps an entry of its own to be seen through. That is
 * why the keel_defaults_return_* wrappers exist. They are not a style choice,
 * and replacing one with the core helper it mirrors silently blinds the report.
 *
 * `additive` hooks are exempt: they are excluded from reporting altogether, so a
 * collapse there has nothing to hide.
 *
 * Run: php tests/policy-callback-identity.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
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

/*
 * Callables that belong to WordPress rather than to Keel.
 *
 * `home_url` is in this list for a second reason as well as collision: used as a
 * filter callback it receives the filtered value as its `$path` argument, so
 * `add_filter( 'login_headerurl', 'home_url' )` returned
 * `http://example.com/https://wordpress.org/`. A core function whose first
 * parameter is not the value being filtered is never a correct callback.
 */
$foreign = array(
	'__return_false',
	'__return_true',
	'__return_zero',
	'__return_null',
	'__return_empty_array',
	'__return_empty_string',
	'home_url',
	'get_bloginfo',
);

$root = dirname( __DIR__ );
$all  = '';
foreach ( glob( $root . '/includes/*.php' ) as $file ) {
	$all .= file_get_contents( $file ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

preg_match_all(
	"/keel_defaults_add_policy_filter\(\s*'([a-z0-9_]+)'\s*,\s*'([a-zA-Z0-9_]+)'/",
	$all,
	$registrations,
	PREG_SET_ORDER
);

keel_assert( count( $registrations ) > 5, 'The scan found policy registrations to check (' . count( $registrations ) . ').' );

$kinds    = keel_defaults_policy_hooks();
$reported = 0;

foreach ( $registrations as $registration ) {
	list( , $hook, $callback ) = $registration;

	// A hook off the map, or one classified additive, is never named in the
	// report, so nothing can hide in it.
	if ( ! isset( $kinds[ $hook ] ) || 'additive' === $kinds[ $hook ] ) {
		continue;
	}

	++$reported;

	keel_assert(
		! in_array( $callback, $foreign, true ),
		"Hook '{$hook}' is registered with '{$callback}', which Keel does not own. Another plugin "
			. 'registering the same callable at the same priority collapses into a single entry and '
			. 'vanishes from the overlap report. Use a keel_defaults_* wrapper.'
	);
}

keel_assert( $reported > 5, "The scan reached the reported hooks ({$reported} checked)." );

if ( $fail > 0 ) {
	fwrite( STDERR, "policy callback identity: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "policy callback identity: OK ({$reported} reported hooks checked)\n" );
