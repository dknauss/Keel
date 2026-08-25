<?php
/**
 * Lightweight regression test for the Site Health posture surface.
 *
 * Run: php tests/site-health.php
 *
 * @package keel
 */

$GLOBALS['keel_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $number, $d = null ) { return ( 1 === (int) $number ) ? $single : $plural; }
function number_format_i18n( $n ) { return (string) $n; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }

/*
 * Keel reads network policy before the site option, so every harness that calls
 * keel_defaults_get() needs this. Single site is the honest default here: the
 * multisite path has its own coverage in tests/network-policy.php.
 */
function is_multisite() {
	return false;
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

// Registration adds a direct test without clobbering existing ones.
$tests = keel_defaults_site_health_tests( array( 'direct' => array( 'core' => array() ) ) );
keel_assert( isset( $tests['direct']['keel_defaults_posture'] ), 'Posture test is registered.' );
keel_assert( isset( $tests['direct']['core'] ), 'Existing tests are preserved.' );
keel_assert( 'keel_defaults_site_health_posture' === $tests['direct']['keel_defaults_posture']['test'], 'Callback is wired.' );

// state labels
$schema = keel_defaults_schema();
keel_assert( 'On' === keel_defaults_state_label( $schema['require_strong_passwords'], 'yes' ), 'Toggle On label.' );
keel_assert( 'Off' === keel_defaults_state_label( $schema['require_strong_passwords'], 'no' ), 'Toggle Off label.' );
keel_assert( 'Unchanged' === keel_defaults_state_label( $schema['frontend_admin_bar_behavior'], '' ), 'Empty select reads Unchanged.' );
$revision_strings = keel_defaults_strings()['post_revisions_limit'];
keel_assert( 'Unlimited' === keel_defaults_state_label( $schema['post_revisions_limit'], -1, $revision_strings ), 'Revision -1 reads Unlimited.' );
keel_assert( 'Disabled' === keel_defaults_state_label( $schema['post_revisions_limit'], 0, $revision_strings ), 'Revision zero reads Disabled.' );
keel_assert( '10 revisions' === keel_defaults_state_label( $schema['post_revisions_limit'], 10, $revision_strings ), 'A positive revision limit names its unit.' );

// Default posture (schema defaults: strong passwords + rest discovery both on) → good.
$GLOBALS['keel_options'] = array();
$result                  = keel_defaults_site_health_posture();
keel_assert( 'good' === $result['status'], 'Default posture is good.' );
keel_assert( false !== strpos( $result['description'], 'Security &' ) || false !== strpos( $result['description'], 'Security' ), 'Description lists the Security group.' );
keel_assert( false !== strpos( $result['actions'], 'page=keel' ), 'Actions link to the settings page.' );

// Turning off an unambiguous security item escalates to recommended, with a note.
$GLOBALS['keel_options']['keel_settings'] = array( 'require_strong_passwords' => 'no' );
$result                                   = keel_defaults_site_health_posture();
keel_assert( 'recommended' === $result['status'], 'Strong passwords off → recommended.' );
keel_assert( 'orange' === $result['badge']['color'], 'Recommended badge is orange.' );
keel_assert( false !== strpos( $result['description'], 'Strong passwords are off' ), 'A note explains the recommendation.' );

// An opinionated UX toggle being off does NOT escalate the status (no nagging).
$GLOBALS['keel_options']['keel_settings'] = array( 'disable_emojis' => 'no' );
$result                                   = keel_defaults_site_health_posture();
keel_assert( 'good' === $result['status'], 'An opinionated toggle off stays informational (good).' );


// --- Site Health → Info ---
// The Status test only speaks up when something warrants attention, and a
// passing test is filed inside a collapsed accordion. The inventory belongs in
// Info, where it is always visible and copyable into a support thread.
$info = keel_defaults_debug_information( array() );
keel_assert( isset( $info['keel'] ), 'Keel adds a section to Site Health → Info.' );
keel_assert( ! empty( $info['keel']['fields'] ), 'The Info section lists fields.' );

// One row per group rather than per default, so the group name is stated once.
keel_assert(
	count( $info['keel']['fields'] ) === count( keel_defaults_group_labels() ),
	'Info has a row per group — ' . count( $info['keel']['fields'] ) . ' of ' . count( keel_defaults_group_labels() ) . '.'
);


/*
 * The count that matters now lives a level down, and it is the one that can go
 * wrong quietly: the defaults are keyed into the value array by their display
 * label, so two defaults sharing a label in one group would overwrite each other
 * and drop one from the report with nothing above this line noticing.
 */
$listed = 0;
foreach ( $info['keel']['fields'] as $group_key => $field ) {
	keel_assert( is_array( $field['value'] ), "Group '{$group_key}' lists its defaults as an array." );
	$listed += count( $field['value'] );
}

/*
 * Counted against the keys that apply to this WordPress, not against the whole
 * schema. A setting naming a core feature newer than the plugin's floor is not
 * reported where that feature is absent, and no stub here supplies one — so on
 * this harness the expected total is one short of the schema. The gating itself
 * is owned by `tests/core-feature-gating.php`, which asserts both core states.
 */
$applicable = count( array_filter( array_keys( keel_defaults_schema() ), 'keel_defaults_key_supported' ) );

keel_assert(
	$applicable === $listed,
	'Every applicable schema key is listed under some group — ' . $listed . ' of ' . $applicable . '.'
);


/*
 * --- a number carries its unit ---
 *
 * "Remember Me Length: 14" is not a state anyone can read. The settings screen
 * prints the unit beside the input, so the number reads correctly there and
 * nowhere else.
 */
$strings = keel_defaults_strings();
$schema  = keel_defaults_schema();

$numbers = 0;
foreach ( $schema as $key => $field ) {
	if ( ! isset( $field['type'] ) || 'number' !== $field['type'] ) {
		continue;
	}

	++$numbers;

	// Every number must supply a readable unit or named sentinel states.
	keel_assert(
		isset( $strings[ $key ]['unit'], $strings[ $key ]['unit_singular'] ),
		"'{$key}' has no singular/plural unit for Site Health."
	);

	$state = keel_defaults_state_label( $field, $field['default'], isset( $strings[ $key ] ) ? $strings[ $key ] : array() );
	keel_assert(
		(string) $field['default'] !== $state,
		"'{$key}' reports a bare number ('{$state}') with no unit."
	);
	keel_assert( false !== strpos( $state, $strings[ $key ]['unit'] ), "'{$key}' reports '{$state}', which does not name its unit." );
}

keel_assert( $numbers > 0, 'The unit check found number fields to check (' . $numbers . ').' );

// min is 1 on both, so the singular is reachable and a bare "%s days" would
// print "1 days".
keel_assert(
	'1 day' === keel_defaults_state_label(
		array( 'type' => 'number' ),
		1,
		array(
			'unit'          => 'days',
			'unit_singular' => 'day',
		)
	),
	'A value of 1 uses the singular, not "1 days".'
);
keel_assert(
	'2 days' === keel_defaults_state_label(
		array( 'type' => 'number' ),
		2,
		array(
			'unit'          => 'days',
			'unit_singular' => 'day',
		)
	),
	'A value above 1 uses the plural.'
);

// A non-array from another plugin's filter must pass straight through rather
// than becoming an array and discarding whatever it was.
keel_assert( 'not-an-array' === keel_defaults_debug_information( 'not-an-array' ), 'A non-array is returned untouched.' );


/*
 * --- the stylesheet targets the section it is written for ---
 *
 * WordPress builds the section's DOM id out of our array key
 * (`health-check-accordion-block-{key}`, wp-admin/site-health-info.php), so the
 * key and the selector are two strings that must agree across two files. That is
 * the whole fragility of styling this section: not core changing, but our own
 * key being renamed while the CSS goes on pointing at the old one, silently, with
 * the table still rendering perfectly in core's default style.
 *
 * So assert they agree, and assert the section exists under that key rather than
 * only that some section exists.
 */
keel_assert(
	array_key_exists( KEEL_DEFAULTS_INFO_SECTION, $info ),
	"The Info section is registered under KEEL_DEFAULTS_INFO_SECTION ('" . KEEL_DEFAULTS_INFO_SECTION . "')."
);

$info_css = keel_defaults_site_health_info_css();

keel_assert(
	false !== strpos( $info_css, '#health-check-accordion-block-' . KEEL_DEFAULTS_INFO_SECTION . ' ' ),
	'The Info stylesheet targets the id WordPress builds from our section key.'
);


/*
 * Scoped, and provably so. An unscoped `.health-check-table th` rule would work
 * on the screen and restyle every other plugin's section on the page as a side
 * effect — which looks identical while you are looking at Keel's rows.
 */
$table_rules = substr_count( $info_css, '.health-check-table' );
$scoped      = substr_count( $info_css, '#health-check-accordion-block-' . KEEL_DEFAULTS_INFO_SECTION . ' .health-check-table' );

keel_assert( $table_rules > 0, 'The stylesheet has rules to check.' );
keel_assert(
	$table_rules === $scoped,
	"Every .health-check-table rule is scoped to Keel's own section; {$scoped} of {$table_rules} are."
);


/*
 * The alignment is the point of the rule; the weight is the cosmetic half. Both
 * cells are asserted separately, and per selector rather than "the string appears
 * somewhere" — dropping vertical-align from the th alone left the td's copy in
 * place and a substring check passed, which is the row the group name is in.
 */
foreach ( array( 'th', 'td' ) as $cell ) {
	keel_assert(
		(bool) preg_match( '/\.health-check-table ' . $cell . '\{[^}]*vertical-align:top/', $info_css ),
		"The {$cell} cells are top-aligned, so a group name sits level with the first default under it."
	);
}


/*
 * Core is 400 for .widefat th but 600 for .widefat.health-check-table th below
 * 782px, so this matches its neighbours on a narrow screen and stands out on a
 * wide one. 600 rather than 700 to sit at core's own semibold rather than past
 * it.
 */
keel_assert(
	(bool) preg_match( '/\.health-check-table th\{[^}]*font-weight:600/', $info_css ),
	'The group name is semibold, matching the weight core itself uses on this table below 782px.'
);

fwrite( STDOUT, "site health tests passed.\n" );
