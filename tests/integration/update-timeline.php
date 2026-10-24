<?php
/** Real WordPress cache scope and safe timeline evidence; no installs or cron runs. */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_update_timeline_markup' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}
$user    = get_current_user_id();
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
// Guard only the renderer: fixture setup and cleanup are outside this boundary.
$guard        = static function () { throw new RuntimeException( 'Timeline attempted HTTP, cron access/scheduling or a write.' );
};
$query_guard  = static function ( $sql ) use ( $guard ) {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql ) || preg_match( "/option_name\s*=\s*'cron'/i", $sql ) ) {
		$guard();
	}
	return $sql;
};
$render       = static function () use ( $guard, $query_guard ) {
	$hooks = array( 'pre_http_request', 'pre_option_cron', 'pre_schedule_event', 'pre_reschedule_event', 'pre_update_option', 'pre_update_site_option' );
	foreach ( $hooks as $hook ) {
		add_filter( $hook, $guard, -999 );
	}
	add_filter( 'query', $query_guard, -999 );
	$level = ob_get_level();
	ob_start();
	try {
		keel_defaults_render_update_timeline( false, false );
		return ob_get_contents();
	} finally {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		foreach ( $hooks as $hook ) {
			remove_filter( $hook, $guard, -999 );
		}
		remove_filter( 'query', $query_guard, -999 );
	}
};
$stable_cache = static function () { return array();
};
add_filter( 'pre_site_transient_keel_stable_check', $stable_cache );
add_filter( 'pre_transient_keel_cron_report', $filter );
try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	$assert( ! empty( $admins ), 'Disposable lab needs an administrator.' );
	wp_set_current_user( $admins[0]->ID );
	$can_render = current_user_can( 'update_core' ) && ( ! is_multisite() || current_user_can( 'manage_network_options' ) );
	$assert( $can_render, 'Lab administrator must have core and network update authority.' );
	$html = $render();
	$assert( false !== strpos( $html, '<table class="widefat striped"' ) && false !== strpos( $html, 'Automatic core updates' ), 'Authorized renderer produces the evidence table without side effects.' );
	foreach ( is_multisite() ? array( 'update_core', 'manage_network_options' ) : array( 'update_core' ) as $denied ) {
		$deny = static function ( $caps, $cap ) use ( $denied ) { return $cap === $denied ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 999, 2 );
		add_filter( 'pre_site_transient_update_core', $guard, -999 );
		try {
			$assert( '' === $render(), 'Denied ' . $denied . ' reads no update evidence and renders nothing.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 999 );
			remove_filter( 'pre_site_transient_update_core', $guard, -999 );
		}
	}
	wp_set_current_user( 0 );
	$assert( '' === $render(), 'Anonymous requests render no timeline.' );
	wp_set_current_user( $admins[0]->ID );
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
	foreach ( array( 'limited', 'malformed' ) as $coverage ) {
		$fixture['data']['limited']   = 'limited' === $coverage;
		$fixture['data']['malformed'] = 'malformed' === $coverage;
		$html                         = keel_defaults_update_timeline_markup( false, '', array( 'operable' => true ), $update, keel_defaults_cron_snapshot(), $now, false );
		$assert( false !== strpos( $html, 'Next scheduled automatic-update run</th><td>Unknown</td>' ) && false === strpos( $html, 'Not scheduled' ), 'Validated partial cache cannot establish absence: ' . $coverage );
		$assert( false !== strpos( $html, 'partial coverage' ) && false !== strpos( $html, 'overdue' ), 'Partial cache preserves its caveat and observed hook.' );
	}
	$fixture['data']['limited']   = false;
	$fixture['data']['malformed'] = false;
	$html                         = keel_defaults_update_timeline_markup( false, '', array( 'operable' => true ), $update, keel_defaults_cron_snapshot(), $now, false );
	$assert( false !== strpos( $html, 'Next scheduled automatic-update run</th><td>Not scheduled</td>' ) && false !== strpos( $html, 'Keel did not find a scheduled event when it last read this site’s schedule.' ), 'Complete cache establishes absence only at collection time.' );
	$fixture['site'] = $site + 100000;
	$assert( false === keel_defaults_cron_snapshot(), 'Foreign-site snapshot rejected.' );
	$fixture['site']    = $site;
	$fixture['checked'] = $now - 3601;
	$assert( false === keel_defaults_cron_snapshot(), 'Expired snapshot rejected.' );
	$fixture['checked'] = $now + 60;
	$assert( false === keel_defaults_cron_snapshot(), 'Future snapshot rejected.' );
	$valid            = $fixture;
	$valid['checked'] = $now - 60;
	$malformed        = $valid;
	$malformed['data']['core']['wp_version_check'] = 'not-a-timestamp';
	foreach ( array( false, array(), $malformed, array_merge( $valid, array( 'done' => false ) ), $fixture, array_merge( $valid, array( 'checked' => $now - 3601 ) ) ) as $unavailable ) {
		$fixture = $unavailable;
		$html    = $render();
		$assert( false !== strpos( $html, 'Not read yet' ) && false === strpos( $html, 'Not scheduled' ), 'Missing, pending, malformed, future and expired caches remain unread rather than implying absent jobs.' );
	}
	$fixture = array_merge( $valid, array( 'error' => 'malformed_cron' ) );
	$failed  = keel_defaults_cron_snapshot();
	$assert( false !== $failed && 'malformed_cron' === $failed['error'], 'Failed collection fixture is a valid cached error, not a rejected malformed snapshot.' );
	$html = $render();
	$assert( false !== strpos( $html, 'Scheduled update checks</th><td>Unavailable</td>' ) && false === strpos( $html, 'Not read yet' ) && false === strpos( $html, 'Not scheduled' ), 'Known collection failure is unavailable, distinct from unread evidence and absent jobs.' );
	$fixture                                     = $valid;
	$fixture['data']['core']['wp_version_check'] = $now - 30;
	$html                                        = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $update, keel_defaults_cron_snapshot(), $now, false );
	$assert( false !== strpos( $html, 'Due now;' ) && false === strpos( $html, 'already overdue' ), 'A hook becoming due after collection has unknown execution status.' );
	$fixture['data']['core']['wp_version_check'] = $now + 600;
	$html                                        = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $update, keel_defaults_cron_snapshot(), $now, false );
	$assert( false !== strpos( $html, gmdate( 'Y-m-d H:i', $now + 600 ) . ' UTC' ) && false === strpos( $html, 'Due now;' ), 'Future scheduled hooks retain their timestamp without a due claim.' );
	foreach ( array( null, 'not-a-timestamp', $now + 60, $now - 60, $now - 90000 ) as $attempt ) {
		$evidence = keel_defaults_cron_updates( (object) array( 'last_checked' => $attempt ), false, $now );
		$html     = keel_defaults_update_timeline_markup( '', '', array( 'operable' => true ), $evidence, false, $now, false );
		if ( ! is_int( $attempt ) || $attempt > $now ) {
			$assert( false !== strpos( $html, 'Last attempted check with WordPress.org</th><td>Unknown' ), 'Absent, malformed and future check markers stay unknown.' );
		} else {
			$assert( false !== strpos( $html, 'not whether WordPress.org answered' ), 'Fresh and stale check markers do not prove a response.' );
			$assert( ( $attempt < $now - 86400 ) === ( false !== strpos( $html, 'stale' ) ), 'Only stale attempt evidence receives the stale warning.' );
		}
	}
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
	$assert( false !== strpos( $html, 'Blocked' ) && false !== strpos( $html, 'file_mods' ), 'Real stable blocker helper wins over selection.' );
	$state['blockers'][0]['code'] = 'credentials';
	$assert( 'scheduled' === keel_defaults_selection_state( $state, '7.1.4' ), 'Core selection overrides branch-tip-only credential probe.' );
	echo "OK: warm timeline table, capability gates, cache/timestamp coverage and privacy.\n";
	// Cold-cache render must also remain read-only. Do not hide this behind a warm-up.
	remove_filter( 'pre_site_transient_keel_stable_check', $stable_cache );
	$cold = static function () { return false;
	};
	add_filter( 'site_transient_keel_stable_check', $cold );
	add_filter( 'site_transient_keel_stable_check_failed', $cold );
	try {
		$assert( false !== strpos( $render(), 'Automatic core updates' ), 'Cold stable-check cache renders without fetching or populating it.' );
	} finally {
		remove_filter( 'site_transient_keel_stable_check', $cold );
		remove_filter( 'site_transient_keel_stable_check_failed', $cold );
	}
} finally {
	remove_filter( 'pre_site_transient_keel_stable_check', $stable_cache );
	remove_filter( 'pre_transient_keel_cron_report', $filter );
	wp_set_current_user( $user );
}
echo "OK: real WordPress timeline cache/scope/privacy boundaries.\n";
