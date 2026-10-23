<?php
/** Core delivery evidence boundaries, without remote requests or cron execution. */
define( 'ABSPATH', __DIR__ . '/' );
function add_action( ...$args ) {}
function __( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function current_user_can( $cap ) { return 'manage_network_options' === $cap ? false : $GLOBALS['allowed']; }
function is_multisite() { return $GLOBALS['network']; }
function human_time_diff( $from, $to ) { return ( $to - $from ) . ' secs'; }
function keel_defaults_selection_state( $state, $selected ) {
	if ( ! $state['operable'] ) {
		return 'blocked'; }
	return false === $selected ? 'unknown' : ( '' === $selected ? 'none' : 'scheduled' );
}
function check( $ok, $message ) { if ( ! $ok ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 ); } }
require __DIR__ . '/../includes/update-timeline.php';
$state  = array(
	'operable' => true,
	'blockers' => array(),
);
$update = array(
	'offer_age' => null,
	'failure'   => null,
);
$html   = keel_defaults_update_timeline_markup( false, false, $state, $update, false, 1000, false );
check( false !== strpos( $html, 'Next automatic install</th><td>Unknown' ), 'Missing selector evidence stays unknown.' );
check( false === strpos( $html, 'Last failed' ) && false === strpos( $html, 'Newer patch' ), 'Rows with nothing to report are left out.' );
check( false !== strpos( $html, 'Not read yet' ), 'Missing collection is not an empty schedule.' );
check( false !== strpos( $html, '<table class="widefat striped"' ) && false === strpos( $html, '<li>' ), 'Evidence renders as a table.' );
$snapshot            = array(
	'done'    => true,
	'error'   => '',
	'checked' => 900,
	'site'    => 1,
	'data'    => array(
		'limited'   => false,
		'malformed' => false,
		'core'      => array(
			'wp_version_check'     => 850,
			'wp_maybe_auto_update' => 1200,
		),
	),
);
$update['offer_age'] = 90000;
$html                = keel_defaults_update_timeline_markup( '<script>', '7.1.4', $state, $update, $snapshot, 1000, true );
check( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'Versions are escaped.' );
check( false !== strpos( $html, 'stale' ) && false !== strpos( $html, 'overdue' ), 'Stale offers and overdue checks are explicit.' );
check( false !== strpos( $html, 'external scheduler' ) && false !== strpos( $html, 'No installation time is promised' ), 'External cron never implies failure or an execution promise.' );
check( false !== strpos( $html, '1 day' ) || false !== strpos( $html, '90000 secs ago' ), 'Offer age reads as elapsed time.' );
check( false !== strpos( $html, 'not whether WordPress.org answered' ), 'A check attempt is not called a successful response.' );
check( false !== strpos( $html, 'Newer patch for this branch</th><td>7.1.4' ), 'A newer patch gets its own row.' );
$snapshot['data']['core']['wp_version_check'] = 920;
$html = keel_defaults_update_timeline_markup( '', '', $state, $update, $snapshot, 1000, false );
check( false === strpos( $html, 'overdue' ) && false !== strpos( $html, 'whether it ran' ), 'A check due after collection cannot be called overdue from cached evidence.' );

$state             = array(
	'operable' => false,
	'blockers' => array(
		array(
			'code' => 'file_mods',
			'text' => '<private>',
		),
	),
);
$update['failure'] = array(
	'at'   => 800,
	'code' => 'download_failed',
);
$html              = keel_defaults_update_timeline_markup( '7.1.4', '7.1.4', $state, $update, $snapshot, 1000, false );
check( false !== strpos( $html, 'Blocked' ) && false !== strpos( $html, 'file changes are turned off (<code>file_mods</code>)' ), 'Global blocker wins over a selected release, in plain words.' );
check( false === strpos( $html, 'private' ) && false !== strpos( $html, 'download_failed' ), 'Only safe failure and blocker codes are rendered.' );
foreach ( array( 'limited', 'malformed' ) as $coverage ) {
	$snapshot['data']['limited']   = 'limited' === $coverage;
	$snapshot['data']['malformed'] = 'malformed' === $coverage;
	$snapshot['data']['core']      = array( 'wp_version_check' => 1200 );
	$html                          = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $update, $snapshot, 1000, false );
	check( false !== strpos( $html, 'Next automatic update run</th><td>Unknown</td>' ) && false === strpos( $html, 'Not scheduled' ), 'Missing hook stays unknown under ' . $coverage . ' coverage.' );
	check( false !== strpos( $html, 'partial coverage' ) && false !== strpos( $html, 'Nothing is queued' ), 'Partial coverage and no selected release remain distinct.' );
	check( false !== strpos( $html, 'Next update check</th><td>1970-01-01 00:20 UTC</td>' ), 'Observed hook remains visible under partial coverage.' );
}
$snapshot['data']['limited']   = false;
$snapshot['data']['malformed'] = false;
$html                          = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $update, $snapshot, 1000, false );
check( false !== strpos( $html, 'Next automatic update run</th><td>Not scheduled</td>' ), 'Complete scan supports an absent hook.' );
check( false !== strpos( $html, 'Not found when Keel last read this site’s schedule.' ) && false !== strpos( $html, 'up to an hour old' ), 'Absent hook is qualified as cached evidence.' );
foreach ( array( 60, 90000 ) as $age ) {
	$update['offer_age'] = $age;
	$html                = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $update, false, 100000, false );
	check( false !== strpos( $html, 'Last attempted check with WordPress.org</th>' ), 'Check marker is labeled as an attempt.' );
	check( false !== strpos( $html, 'WordPress records when it tried, not whether WordPress.org answered.' ), 'Fresh and stale attempt markers never imply a successful response.' );
}
$GLOBALS['allowed'] = false;
$GLOBALS['network'] = false;
ob_start();
keel_defaults_render_update_timeline( false, false );
$html = ob_get_clean();
check( '' === $html, 'Unauthorized rendering reads no evidence.' );
$GLOBALS['allowed'] = true;
$GLOBALS['network'] = true;
ob_start();
keel_defaults_render_update_timeline( false, false );
$html = ob_get_clean();
check( '' === $html, 'Multisite requires network update authority before evidence reads.' );
echo "OK: core delivery timeline boundaries.\n";
