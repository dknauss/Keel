<?php
/**
 * The core and translation auto-update gates.
 *
 * Four pure functions decide whether a site takes WordPress updates by itself.
 * They had no test of any kind: `core_update_policy` was pinned in the settings
 * screen's render and exercised end-to-end by the integration harness, but the
 * functions that actually answer WordPress were never called from a test. A
 * wrong answer here is not cosmetic — it either stops a site receiving security
 * releases, or takes a major version somebody deliberately did not ask for, and
 * both fail silently and slowly.
 *
 * The whole truth table is asserted rather than a case or two, because the
 * interesting values are the ones nobody thinks about: `manual`, which must
 * refuse everything, and `inherit`, which must pass WordPress's own decision
 * through untouched in *both* directions.
 *
 * Run: php tests/core-update-policy.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function get_option( $name, $default_value = false ) {
	return ( KEEL_DEFAULTS_OPTION === $name ) ? $GLOBALS['keel_options'] : $default_value;
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) { return $value; }
function is_multisite() { return false; }
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
 * Set the stored update policy.
 *
 * @param string $policy One of the schema's choices.
 */
function keel_policy( $policy ) {
	$GLOBALS['keel_options']['core_update_policy'] = $policy;
}

// The policies under test are the schema's, not a list invented here — a choice
// added to the schema and forgotten about is exactly what this would miss.
$choices = keel_defaults_schema()['core_update_policy']['choices'];
sort( $choices );
$expected = array( 'all', 'inherit', 'manual', 'minor' );
keel_assert( $expected === $choices, 'The policy choices are the four this table covers (' . implode( ', ', $choices ) . ').' );

/*
 * --- minor releases: the security ones ---
 */
keel_policy( 'minor' );
keel_assert( true === keel_defaults_allow_minor_core_updates( false ), 'minor policy allows minor updates even when WordPress said no.' );
keel_policy( 'all' );
keel_assert( true === keel_defaults_allow_minor_core_updates( false ), 'all policy allows minor updates.' );
keel_policy( 'manual' );
keel_assert( false === keel_defaults_allow_minor_core_updates( true ), 'manual policy refuses minor updates even when WordPress said yes.' );

/*
 * inherit passes WordPress's decision through in both directions. Asserting only
 * the true case would pass on a function that returned true unconditionally.
 */
keel_policy( 'inherit' );
keel_assert( true === keel_defaults_allow_minor_core_updates( true ), 'inherit passes a yes through for minor updates.' );
keel_assert( false === keel_defaults_allow_minor_core_updates( false ), 'inherit passes a no through for minor updates.' );

/*
 * --- major releases: the ones that move a site to a new WordPress ---
 */
keel_policy( 'all' );
keel_assert( true === keel_defaults_allow_major_core_updates( false ), 'all policy allows major updates.' );
keel_policy( 'minor' );
keel_assert( false === keel_defaults_allow_major_core_updates( true ), 'minor policy refuses major updates — the whole point of choosing it.' );
keel_policy( 'manual' );
keel_assert( false === keel_defaults_allow_major_core_updates( true ), 'manual policy refuses major updates.' );
keel_policy( 'inherit' );
keel_assert( true === keel_defaults_allow_major_core_updates( true ), 'inherit passes a yes through for major updates.' );
keel_assert( false === keel_defaults_allow_major_core_updates( false ), 'inherit passes a no through for major updates.' );

/*
 * --- development builds ---
 *
 * Every explicit policy is a statement about stable releases, so none of them
 * opts a site into nightlies. Only inherit defers.
 */
foreach ( array( 'minor', 'all', 'manual' ) as $policy ) {
	keel_policy( $policy );
	keel_assert(
		false === keel_defaults_allow_dev_core_updates( true ),
		"{$policy} policy keeps development builds off, even when WordPress said yes."
	);
}
keel_policy( 'inherit' );
keel_assert( true === keel_defaults_allow_dev_core_updates( true ), 'inherit passes a yes through for development builds.' );
keel_assert( false === keel_defaults_allow_dev_core_updates( false ), 'inherit passes a no through for development builds.' );

/*
 * --- translations ---
 *
 * The one gate that ignores WordPress's decision entirely: the toggle is the
 * answer. Both cases asserted against both incoming values, so a function that
 * returned the argument would fail.
 */
foreach ( array(
	'yes' => true,
	'no'  => false,
) as $incoming_label => $incoming ) {
	$GLOBALS['keel_options']['auto_update_translations'] = 'yes';
	keel_assert( true === keel_defaults_allow_translation_updates( $incoming ), "Translations update when the toggle is on (WordPress said {$incoming_label})." );

	$GLOBALS['keel_options']['auto_update_translations'] = 'no';
	keel_assert( false === keel_defaults_allow_translation_updates( $incoming ), "Translations do not update when the toggle is off (WordPress said {$incoming_label})." );
}

/*
 * --- what a control locked by WP_AUTO_UPDATE_CORE shows ---
 *
 * The locked select used to show the stored policy. With the constant set to
 * true it read "Maintenance/security releases only" on a site installing every
 * release, and nothing on the screen disagreed with it: the lock note says who
 * decided, not what they decided.
 *
 * The table follows Core_Upgrader::should_update_to_version(), and the strict
 * comparisons are its. true and the four pre-release strings are one branch
 * there, because the pre-release part only matters to a site already running a
 * development build, which never offers a stable site a beta. Anything that
 * branch does not recognise falls through to WordPress's own defaults, which is
 * "Leave unchanged" here — including the string 'true', the likeliest typo.
 */
$constant_table = array(
	array( false, 'manual' ),
	array( true, 'all' ),
	array( 'minor', 'minor' ),
	array( 'beta', 'all' ),
	array( 'rc', 'all' ),
	array( 'development', 'all' ),
	array( 'branch-development', 'all' ),
	array( 'true', 'inherit' ),
	array( 'major', 'inherit' ),
	array( '', 'inherit' ),
	array( 1, 'inherit' ),
	array( 0, 'inherit' ),
);

foreach ( $constant_table as $row ) {
	list( $constant, $policy ) = $row;
	$shown                     = keel_defaults_core_policy_for_constant( $constant );

	keel_assert(
		$policy === $shown,
		'WP_AUTO_UPDATE_CORE = ' . var_export( $constant, true ) . " shows '{$policy}', not '{$shown}'." // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- test message.
	);
	keel_assert(
		in_array( $shown, $choices, true ),
		'WP_AUTO_UPDATE_CORE = ' . var_export( $constant, true ) . ' maps to a choice the select can show.' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- test message.
	);
}

/*
 * And the screen asks the constant, not the stored option. Defined last: a
 * constant cannot be undefined, and every gate above had to run without it.
 */
define( 'WP_AUTO_UPDATE_CORE', true );

keel_assert(
	'all' === keel_defaults_config_locked_value( 'core_update_policy', 'minor' ),
	'A control locked by WP_AUTO_UPDATE_CORE = true shows all releases, whatever was stored.'
);
keel_assert(
	'yes' === keel_defaults_config_locked_value( 'disable_emojis', 'yes' ),
	'A setting no constant locks shows its stored value.'
);

if ( $fail > 0 ) {
	fwrite( STDERR, "core update policy: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, 'core update policy: OK (4 gates, 4 policies, ' . count( $constant_table ) . " constant values)\n" );
