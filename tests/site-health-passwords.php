<?php
/** Passive shared breach-screening evidence and authorization. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['enabled']    = true;
$GLOBALS['optout']     = false;
$GLOBALS['multi']      = false;
$GLOBALS['manager']    = true;
$GLOBALS['authorized'] = true;
$GLOBALS['reads']      = 0;
$GLOBALS['filters']    = 0;
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function wp_kses( $s, $allowed ) { return $s; }
function keel_defaults_enabled( $key ) { return $GLOBALS['enabled']; }
function keel_defaults_setting_link( $key, $label ) { return $label; }
function apply_filters( $hook, $value, $password ) { ++$GLOBALS['filters'];
	return $GLOBALS['optout']; }
function current_user_can( $cap ) { return 'manage_network_options' === $cap ? $GLOBALS['manager'] : $GLOBALS['authorized']; }
function is_multisite() { return $GLOBALS['multi']; }
function human_time_diff( $at ) { return 'TIME-' . $at; }
function keel_hibp_budget_evidence() { ++$GLOBALS['reads'];
	return $GLOBALS['evidence']; }
function keel_hibp_last_failure() { return $GLOBALS['evidence']['failure']; }
function keel_hibp_last_success() { return $GLOBALS['evidence']['success']; }
function check( $condition, $message ) { if ( ! $condition ) {
		throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/site-health.php';
$GLOBALS['evidence'] = array(
	'available' => true,
	'failure'   => null,
	'success'   => null,
);
$GLOBALS['enabled']  = false;
keel_defaults_site_health_breach_screening();
check( 0 === $GLOBALS['reads'] && 0 === $GLOBALS['filters'], 'Disabled screening reads no store and invokes no opt-out filter.' );
$GLOBALS['enabled'] = true;
$GLOBALS['optout']  = true;
keel_defaults_site_health_breach_screening();
check( 0 === $GLOBALS['reads'] && 1 === $GLOBALS['filters'], 'Code opt-out precedes evidence and is evaluated once.' );
$GLOBALS['optout'] = false;
$r                 = keel_defaults_site_health_breach_screening();
check( 1 === $GLOBALS['reads'], 'Health reads passive evidence exactly once.' );
check( false === strpos( $r['description'], 'nothing has exercised' ), 'No evidence does not prove no password checks occurred.' );
foreach ( array( 'budget_exhausted', 'lookup_busy', 'budget_unavailable', 'unreachable', 'http_429', '<script>' ) as $kind ) {
	$GLOBALS['evidence'] = array(
		'available' => true,
		'failure'   => array(
			'kind'  => $kind,
			'first' => 101,
			'seen'  => 202,
		),
		'success'   => 303,
	);
	$r                   = keel_defaults_site_health_breach_screening();
	check( 'recommended' === $r['status'] && false !== strpos( $r['description'], 'sample' ), 'Denials are sampled, degraded evidence.' );
	check( false === strpos( $r['description'], 'Passwords set since' ) && false !== strpos( $r['description'], 'Cached' ), 'Failures do not establish that every subsequent password was unchecked.' );
	check( false === strpos( $r['description'], '<script>' ), 'Unknown diagnostic kinds are never raw HTML.' );
	check( false !== strpos( $r['description'], 'TIME-202' ) && false !== strpos( $r['description'], 'TIME-303' ), 'Retained sample and later live success are both displayed.' );
	check( false !== strpos( $r['description'], 'A later live response does not erase the earlier sampled unknown verdict' ), 'A later success does not imply universal recovery or clear the sample.' );
	check( 202 === $GLOBALS['evidence']['failure']['seen'], 'Presentation preserves the sample used by the shared diagnostic throttle.' );
	if ( in_array( $kind, array( 'budget_exhausted', 'lookup_busy', 'budget_unavailable' ), true ) ) {
		check( false === strpos( $r['description'], 'service could not be reached' ), 'Local admission failures are not provider outages.' );
	}
}
$GLOBALS['evidence']['failure']['kind'] = 'lookup_busy';
foreach ( array( 101, 202 ) as $success ) {
	$GLOBALS['evidence']['success'] = $success;
	$r                              = keel_defaults_site_health_breach_screening();
	check( 'recommended' === $r['status'] && false !== strpos( $r['description'], 'sample' ), 'Older or same-second success never clears sampled unavailability.' );
	check( false === strpos( $r['description'], 'A later live response' ), 'Do not invent ordering when success predates or shares the sample timestamp.' );
}
$GLOBALS['evidence']['success'] = 303;
$GLOBALS['multi']               = true;
$GLOBALS['manager']             = false;
$r                              = keel_defaults_site_health_breach_screening();
check( 'recommended' === $r['status'] && false === strpos( $r['description'], 'TIME-' ) && false === strpos( $r['description'], 'sample' ), 'Site administrators get generic degraded status without shared activity.' );
check( false === strpos( $r['description'], 'later live response' ), 'A site administrator cannot infer the ordering of another site’s shared activity.' );
$GLOBALS['manager'] = true;
$r                  = keel_defaults_site_health_breach_screening();
check( false !== strpos( $r['description'], 'network-shared screening transport evidence' ) && false !== strpos( $r['description'], 'TIME-202' ), 'Network managers see correctly scoped sampled evidence.' );
$GLOBALS['evidence'] = array(
	'available' => true,
	'failure'   => null,
	'success'   => 303,
);
$r                   = keel_defaults_site_health_breach_screening();
check( 'good' === $r['status'] && false !== strpos( $r['description'], 'TIME-303' ) && false !== strpos( $r['description'], 'historical transport evidence' ), 'Success is dated historical evidence, not a current availability promise.' );
$GLOBALS['manager'] = false;
$r                  = keel_defaults_site_health_breach_screening();
check( false === strpos( $r['description'], 'TIME-' ) && false === strpos( $r['description'], 'completed' ), 'Site administrators cannot infer another site’s successful activity.' );
$GLOBALS['manager']  = true;
$GLOBALS['evidence'] = array(
	'available' => false,
	'failure'   => null,
	'success'   => null,
);
$r                   = keel_defaults_site_health_breach_screening();
check( 'recommended' === $r['status'] && false !== strpos( $r['description'], 'unknown' ), 'Unavailable storage is unknown, not clean.' );
$GLOBALS['authorized'] = false;
$reads                 = $GLOBALS['reads'];
$filters               = $GLOBALS['filters'];
$r                     = keel_defaults_site_health_breach_screening();
check( '' === $r['description'] && $reads === $GLOBALS['reads'] && $filters === $GLOBALS['filters'], 'Unauthorized calls reveal nothing and evaluate nothing.' );
echo "OK: passive password screening Site Health evidence.\n";
