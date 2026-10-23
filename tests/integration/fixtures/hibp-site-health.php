<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Marked disposable local fixture; trusted canonical identifiers, fixed filesystem paths and scalar-only digests (never deserialized).
/** Local-only HIBP browser instrumentation; synthetic evidence, never live HIBP. @package Keel */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'KEEL_HIBP_BROWSER_LAB' ) || true !== KEEL_HIBP_BROWSER_LAB
	|| ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	return;
}
$GLOBALS['keel_hibp_browser_counts'] = array(
	'http'   => 0,
	'writes' => 0,
);
function keel_hibp_browser_config() {
	$file = WP_CONTENT_DIR . '/keel-hibp-browser.json';
	return is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
}
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 'api.pwnedpasswords.com' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			++$GLOBALS['keel_hibp_browser_counts']['http'];
		}
		return new WP_Error( 'hibp_browser_lab', 'External HTTP blocked in disposable lab.' );
	},
	PHP_INT_MAX,
	3
);
add_filter(
	'query',
	static function ( $sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) && false !== strpos( $sql, 'keel_hibp_v2_' ) ) {
			++$GLOBALS['keel_hibp_browser_counts']['writes'];
		}
		return $sql;
	}
);
add_filter(
	'pre_option_keel_settings',
	static function () {
		return array( 'require_strong_passwords' => 'setting_off' === ( keel_hibp_browser_config()['case'] ?? '' ) ? 'no' : 'yes' );
	}
);
add_filter(
	'keel_disable_hibp',
	static function ( $disabled ) {
		return 'optout' === ( keel_hibp_browser_config()['case'] ?? '' ) || $disabled;
	}
);
add_action(
	'init',
	static function () {
		// This impersonation exists only inside the explicitly enabled localhost lab.
		$config = keel_hibp_browser_config();
		$role   = isset( $_COOKIE['keel_hibp_browser_role'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['keel_hibp_browser_role'] ) ) : '';
		if ( in_array( $role, array( 'site', 'delegated' ), true ) && ! empty( $config['user'] ) ) {
			wp_set_current_user( (int) $config['user'] );
			if ( 'delegated' === $role ) {
				add_filter(
					'user_has_cap',
					static function ( $caps ) { $caps['view_site_health_checks'] = true;
						return $caps;
					}
				);
			}
		} elseif ( 'anonymous' === $role ) {
			wp_set_current_user( 0 );
		}
	},
	1
);
add_action(
	'shutdown',
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return; }
		if ( ! function_exists( 'keel_hibp_budget_scope' ) ) {
			return; }
		$scope            = keel_hibp_budget_scope();
		$raw              = $scope ? keel_hibp_budget_read( $scope, keel_hibp_budget_key( $scope, 'state' ) ) : false;
		$record           = $GLOBALS['keel_hibp_browser_counts'];
		$record['digest'] = hash( 'sha256', serialize( $raw ) );
		file_put_contents( WP_CONTENT_DIR . '/keel-hibp-browser.jsonl', wp_json_encode( $record ) . "\n", FILE_APPEND | LOCK_EX );
	}
);
