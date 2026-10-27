<?php
/** Persistent notice policy, attribution, escaping and access boundaries. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['keel_policy']  = 'minor';
$GLOBALS['keel_caps']    = array(
	'update_core'            => true,
	'manage_options'         => true,
	'manage_network_options' => true,
);
$GLOBALS['keel_network'] = false;
$GLOBALS['wp_version']   = '7.1.3';
function keel_defaults_get( $key ) { return 'auto_update_translations' === $key ? 'yes' : $GLOBALS['keel_policy']; }
function keel_defaults_extension_update_policy( $type ) { return 'unset'; }
function keel_defaults_extension_update_counts_markup( $type ) { return ''; }
function keel_defaults_extension_update_list_url( $type, $disabled_only = false ) {
	return ( is_multisite() || ! empty( $GLOBALS['keel_network'] ) ) && ! current_user_can( 'manage_network_options' ) ? '' : 'http://example.test/wp-admin/' . $type . 's.php';
}
function is_multisite() { return ! empty( $GLOBALS['keel_multisite'] ); }
function get_main_site_id() { return 1; }
function get_admin_url( $id, $path ) { return 'http://main.example.test/wp-admin/' . $path; }
function admin_url( $path ) { return 'http://example.test/wp-admin/' . $path; }
function keel_defaults_network_manages( $key ) { return $GLOBALS['keel_network']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['keel_caps'][ $cap ] ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return esc_html( $url ); }
function keel_defaults_setting_url( $key ) { return 'http://example.test/wp-admin/options-general.php?page=keel#' . keel_defaults_setting_anchor( $key ); }
function keel_defaults_setting_anchor( $key ) { return 'keel-setting-' . str_replace( '_', '-', $key ); }
function network_admin_url( $path ) { return 'http://example.test/wp-admin/network/' . $path; }
function apply_filters( ...$args ) { throw new RuntimeException( 'Notice must not rerun policy filters.' ); }
function wp_remote_get( ...$args ) { throw new RuntimeException( 'Notice must not make requests.' ); }
require_once dirname( __DIR__ ) . '/includes/settings-policy.php';
$module = dirname( __DIR__ ) . '/includes/update-policy-notice.php';
if ( ! is_file( $module ) ) {
	fwrite( STDERR, "FAIL: persistent update-policy notice is missing.\n" );
	exit( 1 );
}
require_once $module;
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
$minor = array(
	'minor' => true,
	'major' => false,
	'dev'   => false,
);
$html  = keel_defaults_update_policy_notice_markup( $minor );
check( false !== strpos( $html, 'notice-info inline' ) && false === strpos( $html, 'is-dismissible' ), 'Persistent native inline notice.' );
check( false !== strpos( $html, '<h2 class="keel-update-policy-title">Keel update policy</h2>' ), 'The attribution is a heading, so screen readers can find the notice by heading.' );
check( false !== strpos( $html, 'Maintenance and security releases only, not major upgrades.' ), 'Major-release scope is inline in the WordPress summary.' );
check( false !== strpos( $html, 'Follow <a href="http://example.test/wp-admin/plugins.php">individual plugin settings</a>.' ) && false !== strpos( $html, 'Follow <a href="http://example.test/wp-admin/themes.php">individual theme settings</a>.' ), 'Per-item wording links only the relevant native list.' );
check( false !== strpos( $html, 'Its update policy is set by Keel’s <a href="http://example.test/wp-admin/options-general.php?page=keel#keel-setting-core-update-policy">Site Defaults</a>.' ) && false !== strpos( $html, 'An update policy describes but does not guarantee the intended outcome. Cron, hosting restrictions, and other plugins can affect update delivery.' ), 'Requested policy details preserve the configured source.' );
check( false !== strpos( $html, '7.1 release branch' ) && false !== strpos( $html, 'not major upgrades.' ), 'Minor scope includes current branch without manual-only promise.' );
check( false !== strpos( $html, 'options-general.php?page=keel#keel-setting-core-update-policy' ), 'Link directly to policy.' );
check( false !== strpos( $html, 'does not guarantee' ), 'Policy does not promise delivery.' );
foreach ( array( 'core-update-policy', 'plugin-auto-update-policy', 'theme-auto-update-policy', 'auto-update-translations' ) as $anchor ) {
	check( false !== strpos( $html, '#keel-setting-' . $anchor ), 'Every policy links to its own setting.' );
}
check( false !== strpos( $html, '<p class="description">This site is on the 7.1 release branch.' ) && false === strpos( $html, '<details>' ), 'Policy provenance and delivery caveat are visible without a disclosure.' );
check( false === strpos( $html, 'settings differ' ), 'Matching core flags are not called divergent.' );
check(
	false !== strpos(
		keel_defaults_update_policy_notice_markup(
			array(
				'minor' => true,
				'major' => true,
				'dev'   => false,
			)
		),
		'settings differ'
	),
	'Later filtered major decision is acknowledged.'
);
check(
	false !== strpos(
		keel_defaults_update_policy_notice_markup(
			array(
				'minor' => true,
				'major' => false,
				'dev'   => true,
			)
		),
		'settings differ'
	),
	'Unexpected development-release decision acknowledged.'
);
check(
	false !== strpos(
		keel_defaults_update_policy_notice_markup(
			array(
				'minor' => 'true',
				'major' => false,
			)
		),
		'unavailable'
	),
	'Malformed observed settings stay unknown.'
);
$GLOBALS['keel_multisite'] = true;
$html                      = keel_defaults_update_policy_notice_markup( $minor );
check( false !== strpos( $html, 'main.example.test/wp-admin/options-general.php?page=keel#keel-setting-plugin-auto-update-policy' ) && false !== strpos( $html, 'main.example.test/wp-admin/options-general.php?page=keel#keel-setting-theme-auto-update-policy' ), 'Shared inherited extension policies link to their main-site controls.' );
$GLOBALS['keel_multisite'] = false;
$GLOBALS['keel_policy']    = 'all';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'including major-version upgrades' ), 'All policy explains major upgrades.' );
$GLOBALS['keel_policy'] = 'manual';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'Automatic core updates are off.' ), 'Manual describes the policy, not external updater behavior.' );
$GLOBALS['keel_policy'] = 'inherit';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'WordPress or another component' ), 'Inheritance attributed honestly.' );
$GLOBALS['keel_policy'] = array( '<script>' );
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'unavailable' ), 'Malformed policy unknown.' );
$GLOBALS['keel_policy']  = 'minor';
$GLOBALS['keel_network'] = true;
$html                    = keel_defaults_update_policy_notice_markup( $minor );
check( false !== strpos( $html, 'Keel network policy' ) && false !== strpos( $html, 'network/settings.php?page=keel-network#keel-setting-core-update-policy' ), 'Network-managed policy links to network control.' );
unset( $GLOBALS['keel_caps']['manage_network_options'] );
check( false === strpos( keel_defaults_update_policy_notice_markup( $minor ), '<a ' ), 'No inaccessible network-management link.' );
$GLOBALS['keel_network'] = false;
$GLOBALS['wp_version']   = '<script>BAD</script>';
$html                    = keel_defaults_update_policy_notice_markup( $minor );
check( false === strpos( $html, '<script>' ) && false !== strpos( $html, 'branch is unavailable' ), 'Malformed version withheld.' );
$GLOBALS['wp_version'] = '7.2-beta1';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), '7.2 release branch' ), 'Development suffix does not corrupt release branch.' );
$GLOBALS['keel_caps'] = array();
ob_start();
keel_defaults_render_update_policy_notice( $minor );
check( '' === ob_get_clean(), 'Unauthorized rendering empty.' );
check( '' === keel_defaults_update_policy_notice_markup( $minor ), 'Unauthorized markup empty.' );
$GLOBALS['keel_caps'] = array(
	'update_core'    => true,
	'manage_options' => true,
);
define( 'WP_AUTO_UPDATE_CORE', true );
$html = keel_defaults_update_policy_notice_markup( $minor );
check( false !== strpos( $html, 'WP_AUTO_UPDATE_CORE' ) && false !== strpos( $html, 'including major-version upgrades' ), 'Config policy overrides stored minor choice.' );
define( 'DISALLOW_FILE_MODS', true );
$html = keel_defaults_update_policy_notice_markup( $minor );
check( false !== strpos( $html, 'DISALLOW_FILE_MODS' ) && false === strpos( $html, 'WP_AUTO_UPDATE_CORE' ), 'Global restriction takes attribution precedence.' );
echo "OK: persistent update-policy notice boundaries.\n";
