<?php
/** Real WordPress cache scope and safe timeline evidence; no installs or cron runs. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_update_timeline_markup' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$now     = time();
$site    = get_current_blog_id();
$fixture = array(
	'schema'  => 1,
	'site'    => $site,
	'checked' => $now - 60,
	'done'    => true,
	'error'   => '',
	'data'    => array(
		'examined'  => 1,
		'limited'   => false,
		'malformed' => false,
		'core'      => array( 'wp_version_check' => $now - 300 ),
		'overdue'   => array(),
		'frequent'  => array(),
	),
	'update'  => array(
		'offer_age' => null,
		'failure'   => null,
	),
);
$assert  = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message ); }
};
$filter  = static function () use ( &$fixture ) { return $fixture;
};
add_filter( 'pre_transient_keel_cron_report', $filter );
try {
	$assert( false !== keel_defaults_cron_snapshot(), 'Valid current-site cached schedule accepted.' );
	$update = keel_defaults_cron_updates(
		(object) array( 'last_checked' => $now - 90000 ),
		array(
			'timestamp'  => $now + 20,
			'error_code' => '<secret>',
			'error_data' => 'SECRET_PAYLOAD',
		),
		$now
	);
	$html   = keel_defaults_update_timeline_markup( false, '', array( 'operable' => true ), $update, keel_defaults_cron_snapshot(), $now, true );
	$assert( false !== strpos( $html, 'stale' ) && false !== strpos( $html, 'overdue' ), 'Real cache evidence rendered.' );
	$assert( false === strpos( $html, 'SECRET_PAYLOAD' ) && false === strpos( $html, '&lt;secret&gt;' ), 'Failure payload omitted.' );
	$assert( false !== strpos( $html, 'time unknown' ), 'Future failure timestamp stays unknown.' );
	$fixture['site'] = $site + 100000;
	$assert( false === keel_defaults_cron_snapshot(), 'Foreign-site snapshot rejected.' );
	$fixture['site']    = $site;
	$fixture['checked'] = $now - 3601;
	$assert( false === keel_defaults_cron_snapshot(), 'Expired snapshot rejected.' );
	$fixture['checked'] = $now + 60;
	$assert( false === keel_defaults_cron_snapshot(), 'Future snapshot rejected.' );
	$state = array(
		'operable' => false,
		'blockers' => array(
			array(
				'code' => 'file_mods',
				'text' => 'private',
			),
		),
	);
	$html  = keel_defaults_update_timeline_markup( '7.1.4', '', $state, $update, false, $now, false );
	$assert( false !== strpos( $html, 'blocked' ) && false !== strpos( $html, 'file_mods' ), 'Real stable blocker helper wins over selection.' );
	$state['blockers'][0]['code'] = 'credentials';
	$assert( 'scheduled' === keel_defaults_selection_state( $state, '7.1.4' ), 'Core selection overrides branch-tip-only credential probe.' );
} finally {
	remove_filter( 'pre_transient_keel_cron_report', $filter );
}
echo "OK: real WordPress timeline cache/scope/privacy boundaries.\n";
