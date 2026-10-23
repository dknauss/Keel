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
function keel_defaults_get( $key ) { return $GLOBALS['keel_policy']; }
function keel_defaults_network_manages( $key ) { return $GLOBALS['keel_network']; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['keel_caps'][ $cap ] ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return esc_html( $url ); }
function keel_defaults_setting_url( $key ) { return 'http://example.test/wp-admin/options-general.php?page=keel#keel-setting-core-update-policy'; }
function keel_defaults_setting_anchor( $key ) { return 'keel-setting-core-update-policy'; }
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
check( false !== strpos( $html, '<strong>Keel update policy</strong>' ), 'Clear bold attribution.' );
check( false !== strpos( $html, '7.1 release branch' ) && false !== strpos( $html, 'Major-version upgrades are not enabled by this policy.' ), 'Minor scope includes current branch without manual-only promise.' );
check( false !== strpos( $html, 'options-general.php?page=keel#keel-setting-core-update-policy' ), 'Link directly to policy.' );
check( false !== strpos( $html, 'not a guarantee' ), 'Policy does not promise delivery.' );
check( false !== strpos( $html, '<details><summary>Policy details</summary><p>Configured policy source:' ) && false === strpos( $html, '<details open' ), 'Provenance and delivery caveats are collapsed by default.' );
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
$GLOBALS['keel_policy'] = 'all';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'including major-version upgrades' ), 'All policy explains major upgrades.' );
$GLOBALS['keel_policy'] = 'manual';
check( false !== strpos( keel_defaults_update_policy_notice_markup( $minor ), 'No automatic WordPress core releases are enabled by this policy.' ), 'Manual describes the policy, not external updater behavior.' );
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
