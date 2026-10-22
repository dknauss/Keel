<?php
/** Cached-only, redacted export on a disposable real WordPress site. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_posture_export' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$now        = time();
$site       = get_current_blog_id();
$user       = get_current_user_id();
$second     = 0;
$assert     = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$autoload   = array(
	'schema'     => 1,
	'site'       => $site,
	'started'    => $now - 60,
	'checked'    => $now - 10,
	'cursor'     => 2,
	'scanned'    => 2,
	'autoloaded' => 1,
	'done'       => true,
	'limited'    => false,
	'error'      => '',
	'autoload'   => array( 'SECRET_FLAG' ),
	'top'        => array(
		array(
			'name'  => 'SECRET_OPTION',
			'bytes' => 999,
			'value' => 'SECRET_VALUE',
		),
	),
);
$cron       = array(
	'schema'  => 1,
	'site'    => $site,
	'checked' => $now - 20,
	'done'    => true,
	'error'   => '',
	'data'    => array(
		'examined'  => 2,
		'limited'   => true,
		'malformed' => false,
		'core'      => array( 'wp_version_check' => $now + 200 ),
		'overdue'   => array(
			array(
				'hook'     => 'SECRET_HOOK',
				'schedule' => 'SECRET_SCHEDULE',
				'at'       => $now - 6000,
				'delay'    => 6000,
				'interval' => 60,
			),
		),
		'frequent'  => array(),
	),
	'update'  => array(
		'offer_age' => null,
		'failure'   => null,
	),
);
$settings   = array(
	'frame_options'         => 'SECRET_TOKEN',
	'session_regular_days'  => array( 'SECRET_USER' ),
	'password_exempt_roles' => array( 'SECRET_ROLE' ),
);
$divergence = array(
	'plugins' => keel_defaults_active_plugin_fingerprint(),
	'hooks'   => array( 'SECRET_CALLBACK' => true ),
);
$filters    = array(
	'pre_option_keel_settings'                => static function () use ( &$settings ) {
		return $settings;
	},
	'pre_site_option_keel_network_settings'   => static function () {
		return array();
	},
	'pre_transient_keel_autoload_report'      => static function () use ( &$autoload ) {
		return $autoload;
	},
	'pre_transient_keel_cron_report'          => static function () use ( &$cron ) {
		return $cron;
	},
	'pre_transient_keel_policy_divergence'    => static function () use ( &$divergence ) {
		return $divergence;
	},
	'pre_site_transient_keel_stable_check'    => static function () {
		return array(
			keel_defaults_wp_version() => 'insecure',
			'SECRET_VERSION'           => 'latest',
		);
	},
	'pre_site_transient_update_core'          => static function () use ( $now ) {
		return (object) array(
			'last_checked' => $now - 90000,
			'updates'      => array( (object) array( 'package' => 'SECRET_URL' ) ),
		);
	},
	'pre_site_option_auto_core_update_failed' => static function () use ( $now ) {
		return array(
			'timestamp'  => $now + 300,
			'critical'   => true,
			'error_code' => 'SECRET_CODE',
			'error_data' => 'SECRET_PAYLOAD',
		);
	},
);
$deny       = static function () {
	throw new RuntimeException( 'Export must not invoke probes, collection or HTTP.' );
};
try {
	foreach ( $filters as $hook => $callback ) {
		add_filter( $hook, $callback );
	}
	foreach ( array( 'pre_http_request', 'pre_option_cron', 'keel_policy_expectations' ) as $hook ) {
		add_filter( $hook, $deny );
	}
	$report = keel_defaults_posture_export();
	$json   = wp_json_encode( $report );
	$assert( false === strpos( $json, 'SECRET_' ) && false === strpos( $json, home_url() ), 'All sentinels, identifiers and payloads are excluded.' );
	$assert( strlen( $json ) < 32768, 'Closed schema remains bounded.' );
	$assert( null === $report['policies']['frame_options']['value'] && null === $report['policies']['password_exempt_roles']['value'], 'Invalid/free-form policies withheld.' );
	$assert( 999 === $report['diagnostics']['autoload']['largest_bytes'] && 'partial' === $report['diagnostics']['cron']['coverage'], 'Numeric cached evidence and coverage preserved.' );
	$assert( 1 === $report['conflicts']['observed_hooks'] && null === $report['conflicts']['observed_at'], 'Cached conflicts do not invoke expectation filters or invent freshness.' );
	$assert( null === $report['core']['failure_at'] && true === $report['core']['failure_observed'], 'Future failure time stays unknown; presence remains evidence.' );
	$divergence['plugins'] = 'invalidated';
	$assert( 'unknown' === keel_defaults_posture_export()['conflicts']['coverage'], 'Invalidated conflict provenance stays unknown.' );
	$autoload['checked'] = $now + 300;
	$assert( 'unavailable' === keel_defaults_posture_export()['diagnostics']['autoload']['coverage'], 'Future snapshot observation stays unknown.' );
	$autoload['checked'] = $now - 10;
	$autoload['done']    = false;
	$pending             = keel_defaults_posture_export()['diagnostics']['autoload'];
	$assert( 'pending' === $pending['coverage'] && null === $pending['observed_at'], 'Pending request timestamps must not masquerade as collected evidence.' );
	$autoload['done'] = true;
	$cron['checked']  = $now - 3601;
	$assert( 'unavailable' === keel_defaults_posture_export()['diagnostics']['cron']['coverage'], 'Expired cache stays unknown.' );
	$cron['checked']          = $now - 20;
	$cron['data']['examined'] = 'SECRET_COUNT';
	$assert( 'unavailable' === keel_defaults_posture_export()['diagnostics']['cron']['coverage'], 'Malformed cache is rejected.' );
	$cron['data']['examined'] = 2;
	foreach ( array( 'pre_http_request', 'pre_option_cron', 'keel_policy_expectations' ) as $hook ) {
		remove_filter( $hook, $deny );
	}
	if ( is_multisite() ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		wp_set_current_user( $admins[0]->ID );
		$nonce      = wp_create_nonce( 'keel-posture-export-' . $site );
		$installing = wp_installing();
		$second     = wpmu_create_blog( get_network()->domain, '/keel-export-' . wp_rand() . '/', 'Export probe', $admins[0]->ID );
		wp_installing( $installing );
		$assert( ! is_wp_error( $second ), 'Create disposable subsite.' );
		switch_to_blog( $second );
		try {
			$other = keel_defaults_posture_export();
			$assert( 'unavailable' === $other['diagnostics']['autoload']['coverage'] && 'unavailable' === $other['diagnostics']['cron']['coverage'], 'Another site’s cached diagnostic snapshots cannot cross scope.' );
			$assert( false === wp_verify_nonce( $nonce, 'keel-posture-export-' . get_current_blog_id() ), 'Download nonce cannot cross sites.' );
		} finally {
			restore_current_blog();
		}
	}
	wp_set_current_user( 0 );
	$assert( ! keel_defaults_can_export_posture(), 'Anonymous callers lack export authority.' );
} finally {
	foreach ( $filters as $hook => $callback ) {
		remove_filter( $hook, $callback );
	}
	foreach ( array( 'pre_http_request', 'pre_option_cron', 'keel_policy_expectations' ) as $hook ) {
		remove_filter( $hook, $deny );
	}
	if ( is_int( $second ) && $second > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $second, true );
	}
	wp_set_current_user( $user );
}
echo "OK: real cached-only export, redaction, unknown evidence and multisite isolation.\n";
