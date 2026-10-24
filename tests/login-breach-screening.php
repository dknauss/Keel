<?php
/**
 * Login re-check of existing passwords against known breaches (#323).
 *
 * Runs the real lookup pipeline against the SQL-backed store double, so the
 * "no request" assertions count actual outbound calls rather than trusting a
 * mock of keel_hibp_lookup(). Covers the issue's acceptance list:
 *
 * - a breached password at login sets the flag and shows the owner a notice;
 *   a clean one does not;
 * - a second login inside the re-screen interval makes no request;
 * - unknown or budget-exhausted results allow the login and record nothing;
 * - changing the password clears the flag;
 *
 * and the admin surface: Site Health and the Users view reveal flagged
 * accounts only to people who can list users, escape every account field, and
 * link to core's nonced password-reset action.
 *
 * Run: php tests/login-breach-screening.php
 *
 * @package keel
 */

$GLOBALS['keel_filters']      = array();
$GLOBALS['keel_options']      = array();
$GLOBALS['keel_uuid']         = 0;
$GLOBALS['keel_user_meta']    = array();
$GLOBALS['keel_users']        = array();
$GLOBALS['keel_current_user'] = 0;
$GLOBALS['keel_caps']         = array();
$GLOBALS['keel_did']          = array();
$GLOBALS['keel_object_cache'] = array();
$GLOBALS['keel_transients']   = array();
$GLOBALS['keel_http_calls']   = 0;
$GLOBALS['keel_blog_id']      = 1;
$GLOBALS['keel_user_sites']   = array();
$GLOBALS['keel_http_reply']   = array(
	'code' => 200,
	'body' => '',
);

$GLOBALS['keel_user_query_args'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
function did_action( $hook ) { return empty( $GLOBALS['keel_did'][ $hook ] ) ? 0 : 1; }
function wp_cache_get( $key, $group = '' ) {
	return array_key_exists( $group . ':' . $key, $GLOBALS['keel_object_cache'] ) ? $GLOBALS['keel_object_cache'][ $group . ':' . $key ] : false;
}
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	$GLOBALS['keel_object_cache'][ $group . ':' . $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) {
	if ( 'date_format' === $key ) {
		return 'Y-m-d';
	}
	if ( 'time_format' === $key ) {
		return 'H:i';
	}
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = true ) {
	if ( array_key_exists( $key, $GLOBALS['keel_options'] ) ) {
		return false;
	}
	$GLOBALS['keel_options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['keel_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['keel_options'][ $key ] );
	return true;
}
function wp_generate_uuid4() {
	++$GLOBALS['keel_uuid'];
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['keel_uuid'] );
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['keel_transients'] ) ? $GLOBALS['keel_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['keel_transients'][ $key ] = $value;
	return true;
}
function wp_remote_get( $url, $args = array() ) {
	++$GLOBALS['keel_http_calls'];
	return $GLOBALS['keel_http_reply'];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['code'] ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// --- users, meta and capabilities ---
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['keel_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['keel_user_meta'][ $user_id ][ $key ] : '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['keel_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['keel_user_meta'][ $user_id ][ $key ] );
	return true;
}
function get_userdata( $user_id ) { return isset( $GLOBALS['keel_users'][ $user_id ] ) ? $GLOBALS['keel_users'][ $user_id ] : false; }
function get_current_user_id() { return $GLOBALS['keel_current_user']; }
function current_user_can( $cap, ...$args ) { return ! empty( $GLOBALS['keel_caps'][ $cap ] ); }
function user_can( $user, $cap ) { return in_array( 'administrator', (array) $user->roles, true ) && 'manage_options' === $cap; }
function get_edit_profile_url( $user_id ) { return 'https://example.test/wp-admin/profile.php'; }
function get_edit_user_link( $user_id ) { return 'https://example.test/wp-admin/user-edit.php?user_id=' . (int) $user_id; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=NONCE-' . $action; }
function number_format_i18n( $n ) { return (string) $n; }
function human_time_diff( $from, $to = null ) { return 'AGO'; }
function date_i18n( $format, $ts ) { return gmdate( $format, $ts ); }
function translate_user_role( $name ) { return $name; }
function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'subscriber'    => array( 'name' => 'Subscriber' ),
		),
	);
}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_unslash( $v ) { return $v; }
function is_admin() { return true; }
function get_current_blog_id() { return $GLOBALS['keel_blog_id']; }

/**
 * Sites an account belongs to; every account is on site 1 unless set.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function keel_user_sites( $user_id ) {
	return isset( $GLOBALS['keel_user_sites'][ $user_id ] ) ? $GLOBALS['keel_user_sites'][ $user_id ] : array( 1 );
}

require_once __DIR__ . '/stubs/wp-user.php';

/**
 * Minimal user query: filters the stubbed users by the meta key it is given.
 *
 * With a `blog_id` it keeps only that site's members, as core does. Without one
 * it searches the whole network, so a report that forgets to scope itself
 * fails the multisite assertions rather than passing on core's default.
 */
class WP_User_Query {
	private $found = array();
	public function __construct( $args ) {
		$GLOBALS['keel_user_query_args'][] = $args;
		foreach ( $GLOBALS['keel_users'] as $user ) {
			if ( ! empty( $args['blog_id'] ) && ! in_array( (int) $args['blog_id'], keel_user_sites( $user->ID ), true ) ) {
				continue;
			}
			if ( '' !== get_user_meta( $user->ID, $args['meta_key'], true ) ) {
				$this->found[] = $user;
			}
		}
		usort(
			$this->found,
			function ( $a, $b ) use ( $args ) {
				$by_time = (int) get_user_meta( $b->ID, $args['meta_key'], true ) - (int) get_user_meta( $a->ID, $args['meta_key'], true );
				return $by_time ? $by_time : $b->ID - $a->ID;
			}
		);
		$this->total = count( $this->found );
		$this->found = array_slice( $this->found, $args['offset'] ?? 0, $args['number'] );
	}
	public $total;
	public function get_total() { return $this->total; }
	public function get_results() { return $this->found; }
}

require_once __DIR__ . '/stubs/wp-error.php';

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/stubs/hibp-store.php';
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
 * Fresh lookup store and HTTP reply; leaves user state alone.
 *
 * @param int|WP_Error $code HTTP status, or a transport error.
 * @param string       $body Response body.
 */
function keel_reset_lookup( $code, $body = '' ) {
	$GLOBALS['wpdb']              = new Keel_HIBP_Test_DB();
	$GLOBALS['keel_transients']   = array();
	$GLOBALS['keel_object_cache'] = array();
	$GLOBALS['keel_http_calls']   = 0;
	$GLOBALS['keel_http_reply']   = $code instanceof WP_Error ? $code : array(
		'code' => $code,
		'body' => $body,
	);
}

/**
 * Simulate an interactive login: authenticate, then wp_login.
 *
 * @param WP_User $user     Account.
 * @param string  $password Submitted password.
 */
function keel_login( $user, $password ) {
	$result = keel_defaults_capture_login_password( $user, $user->user_login, $password );
	keel_assert( $result === $user, 'Capture never changes the authentication result.' );
	keel_defaults_screen_login_password( $user->user_login, $user );
}

/**
 * Render the notice for whoever is signed in.
 *
 * @return string
 */
function keel_notice() {
	ob_start();
	keel_defaults_render_breached_password_notice();
	return (string) ob_get_clean();
}

$breached_pw = 'correct-horse-battery-staple-9271';
$clean_pw    = 'a-long-clean-passphrase-nobody-leaked';
$breached_ok = strtoupper( substr( sha1( $breached_pw ), 5 ) ) . ':12';
$clean_body  = str_repeat( 'F', 35 ) . ':3';

$editor = new WP_User( 2, array( 'editor' ), 'ed', 'Ed <script>alert(1)</script>' );
$admin  = new WP_User( 1, array( 'administrator' ), 'boss', 'The Boss' );
$sub    = new WP_User( 3, array( 'subscriber' ), 'sub', 'Sub' );

$GLOBALS['keel_users'] = array(
	1 => $admin,
	2 => $editor,
	3 => $sub,
);

// --- interval ---
keel_assert( 30 * DAY_IN_SECONDS === keel_defaults_password_rescreen_interval(), 'The default re-screen interval is 30 days.' );
$GLOBALS['keel_filters']['keel_password_rescreen_interval'] = 5;
keel_assert( HOUR_IN_SECONDS === keel_defaults_password_rescreen_interval(), 'A filter cannot drop the interval below one hour.' );
$GLOBALS['keel_filters']['keel_password_rescreen_interval'] = 7 * DAY_IN_SECONDS;
keel_assert( 7 * DAY_IN_SECONDS === keel_defaults_password_rescreen_interval(), 'The interval is filterable.' );
unset( $GLOBALS['keel_filters']['keel_password_rescreen_interval'] );

// --- capture only what core verified, and only for that account ---
keel_defaults_capture_login_password( new WP_Error( 'incorrect_password', 'x' ), 'ed', $breached_pw );
keel_assert( null === keel_defaults_login_password_slot( 2 ), 'A failed login holds no password.' );
keel_defaults_capture_login_password( $editor, 'ed', '' );
keel_assert( null === keel_defaults_login_password_slot( 2 ), 'Cookie re-auth (no password) holds nothing.' );
keel_defaults_capture_login_password( $editor, 'ed', $breached_pw );
keel_assert( null === keel_defaults_login_password_slot( 1 ), 'A held password is never handed back for another account.' );
keel_assert( null === keel_defaults_login_password_slot( 2 ), 'Asking for the wrong account also discards it.' );
$GLOBALS['keel_did']['application_password_did_authenticate'] = true;
keel_defaults_capture_login_password( $editor, 'ed', $breached_pw );
keel_assert( null === keel_defaults_login_password_slot( 2 ), 'Application passwords are never captured.' );
unset( $GLOBALS['keel_did']['application_password_did_authenticate'] );

// --- breached login: flag, timestamp, notice for the owner only ---
keel_reset_lookup( 200, $breached_ok );
keel_login( $editor, $breached_pw );
keel_assert( 1 === $GLOBALS['keel_http_calls'], 'The first login makes one lookup.' );
keel_assert( keel_defaults_password_breached_at( 2 ) > 0, 'A breached password sets the flag.' );
keel_assert( (int) get_user_meta( 2, 'keel_password_screened', true ) > 0, 'And records when it was screened.' );
$stored = wp_json_encode( $GLOBALS['keel_user_meta'] );
keel_assert( false === strpos( $stored, $breached_pw ) && false === stripos( $stored, sha1( $breached_pw ) ) && false === stripos( $stored, substr( sha1( $breached_pw ), 0, 5 ) . '"' ), 'User meta holds timestamps only: no password, hash or prefix.' );
keel_assert( null === keel_defaults_login_password_slot( 2 ), 'Screening consumes the held password.' );

$GLOBALS['keel_current_user'] = 2;
$notice                       = keel_notice();
keel_assert( false !== strpos( $notice, 'known data breach' ) && false !== strpos( $notice, 'profile.php#password' ), 'The flagged person sees a warning linking to their password field.' );
keel_assert( false === strpos( $notice, 'is-dismissible' ), 'The warning clears by changing the password, not by dismissing it.' );
$GLOBALS['keel_current_user'] = 1;
keel_assert( '' === keel_notice(), 'Nobody else sees that person\'s warning.' );

// --- second login inside the interval: no request, even with a cold cache ---
$first = (int) get_user_meta( 2, 'keel_password_screened', true );
keel_reset_lookup( 200, $breached_ok );
keel_login( $editor, $breached_pw );
keel_assert( 0 === $GLOBALS['keel_http_calls'], 'A second login inside the interval makes no lookup.' );
keel_assert( 'fresh' === keel_defaults_screen_user_password( $editor, $breached_pw, $first + DAY_IN_SECONDS ), 'Inside the interval the result is still fresh.' );
keel_assert( 'breached' === keel_defaults_screen_user_password( $editor, $breached_pw, $first + 31 * DAY_IN_SECONDS ), 'After the interval it is checked again.' );
keel_assert( keel_defaults_password_breached_at( 2 ) === $first, 'A re-check keeps the first sighting rather than resetting it.' );
update_user_meta( 2, 'keel_password_screened', time() + DAY_IN_SECONDS );
keel_reset_lookup( 200, $breached_ok );
keel_assert( 'breached' === keel_defaults_screen_user_password( $editor, $breached_pw ), 'A screened time in the future is stale, not fresh for ever.' );

// --- changing the password clears the flag ---
keel_defaults_password_set_forgets_screening( 'new', 2 );
keel_assert( 0 === keel_defaults_password_breached_at( 2 ) && '' === get_user_meta( 2, 'keel_password_screened', true ), 'wp_set_password() clears both keys, so the next login checks the new password.' );

keel_reset_lookup( 200, $breached_ok );
keel_login( $editor, $breached_pw );
$old = clone $editor;
keel_defaults_profile_update_forgets_screening( 2, $old, array( 'user_pass' => $old->user_pass ) );
keel_assert( keel_defaults_password_breached_at( 2 ) > 0, 'A profile save that keeps the password keeps the flag.' );
keel_defaults_profile_update_forgets_screening( 2, $old, array( 'user_pass' => '$wp$2y$10$new' ) );
keel_assert( 0 === keel_defaults_password_breached_at( 2 ), 'A profile save with a new hash clears the flag.' );
keel_reset_lookup( 200, $breached_ok );
keel_login( $editor, $breached_pw );
$GLOBALS['keel_users'][2]            = clone $editor;
$GLOBALS['keel_users'][2]->user_pass = '$wp$2y$10$fromdb';
keel_defaults_profile_update_forgets_screening( 2, $old );
keel_assert( 0 === keel_defaults_password_breached_at( 2 ), 'Without the saved data, the stored hash is compared instead.' );
$GLOBALS['keel_users'][2] = $editor;

// --- clean login ---
keel_reset_lookup( 200, $clean_body );
keel_login( $editor, $clean_pw );
keel_assert( 0 === keel_defaults_password_breached_at( 2 ), 'A clean password sets no flag.' );
keel_assert( (int) get_user_meta( 2, 'keel_password_screened', true ) > 0, 'A clean verdict is still recorded, so the next login skips the lookup.' );
$GLOBALS['keel_current_user'] = 2;
keel_assert( '' === keel_notice(), 'No warning for a clean password.' );
update_user_meta( 2, 'keel_password_breached', 123 );
keel_assert( 'clean' === keel_defaults_screen_user_password( $editor, $clean_pw, time() + 31 * DAY_IN_SECONDS ), 'A later clean verdict...' );
keel_assert( 0 === keel_defaults_password_breached_at( 2 ), '...removes a flag left behind by a change Keel did not see.' );
keel_defaults_forget_password_screening( 2 );

// --- unknown verdicts allow the login and record nothing ---
foreach (
	array(
		'unreachable'  => array( new WP_Error( 'http_request_failed', 'down' ), '' ),
		'rate limited' => array( 429, '' ),
		'malformed'    => array( 200, '<html>captive portal</html>' ),
	) as $case => $reply
) {
	keel_reset_lookup( $reply[0], $reply[1] );
	keel_assert( 'unknown' === keel_defaults_screen_user_password( $editor, $breached_pw ), "The {$case} case is unknown." );
	keel_assert( '' === get_user_meta( 2, 'keel_password_screened', true ) && 0 === keel_defaults_password_breached_at( 2 ), "The {$case} case records nothing, so the next login tries again." );
}
keel_reset_lookup( 200, $breached_ok );
$scope           = keel_hibp_budget_scope();
$state           = keel_hibp_budget_initial( keel_hibp_budget_policy(), time() );
$state['credit'] = 0;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', null, $state );
keel_assert( 'unknown' === keel_defaults_screen_user_password( $editor, $breached_pw ), 'An exhausted budget is unknown.' );
keel_assert( 0 === $GLOBALS['keel_http_calls'], 'An exhausted budget makes no request.' );
keel_assert( '' === get_user_meta( 2, 'keel_password_screened', true ), 'And records nothing.' );

// A local breach list still flags during an outage; it cannot invent "clean".
keel_reset_lookup( new WP_Error( 'http_request_failed', 'down' ) );
$GLOBALS['keel_filters']['keel_password_is_pwned'] = true;
keel_assert( 'breached' === keel_defaults_screen_user_password( $editor, $breached_pw ), 'The keel_password_is_pwned filter can flag a password while the service is down.' );
unset( $GLOBALS['keel_filters']['keel_password_is_pwned'] );
keel_defaults_forget_password_screening( 2 );

// --- every role is checked by default; a filter can leave roles out ---
keel_reset_lookup( 200, $breached_ok );
keel_assert( 'breached' === keel_defaults_screen_user_password( $sub, $breached_pw ), 'Subscribers are checked at login by default, although they are exempt from the length rules.' );
keel_defaults_forget_password_screening( 3 );
$GLOBALS['keel_filters']['keel_password_rescreen_skip_roles'] = array( 'subscriber' );
keel_reset_lookup( 200, $breached_ok );
keel_assert( 'skipped' === keel_defaults_screen_user_password( $sub, $breached_pw ), 'The filter can leave subscribers out.' );
keel_assert( 0 === $GLOBALS['keel_http_calls'] && array() === array_filter( $GLOBALS['keel_user_meta'][3] ?? array() ), 'Skipping makes no request and stores nothing.' );
$both = new WP_User( 6, array( 'subscriber', 'editor' ), 'both', 'Both' );
keel_assert( 'breached' === keel_defaults_screen_user_password( $both, $breached_pw ), 'An account holding any unlisted role is still checked.' );
keel_defaults_forget_password_screening( 6 );
unset( $GLOBALS['keel_filters']['keel_password_rescreen_skip_roles'] );

// --- Site Health: who may see what ---
keel_reset_lookup( 200, $breached_ok );
keel_login( $editor, $breached_pw );
$GLOBALS['keel_current_user'] = 1;
$GLOBALS['keel_caps']         = array();
$r                            = keel_defaults_site_health_breached_passwords();
keel_assert( '' === $r['description'] && 'good' === $r['status'] && '' === $r['actions'], 'Without Site Health access nothing is revealed, not even through the status.' );
$GLOBALS['keel_caps'] = array( 'view_site_health_checks' => true );
$r                    = keel_defaults_site_health_breached_passwords();
keel_assert( 'good' === $r['status'] && false === strpos( $r['description'], 'Ed' ) && false === strpos( $r['label'], '1' ), 'Site Health access without list_users reveals no count and no names.' );

$GLOBALS['keel_caps'] = array(
	'view_site_health_checks' => true,
	'list_users'              => true,
	'edit_user'               => true,
);
$r                    = keel_defaults_site_health_breached_passwords();
keel_assert( 'recommended' === $r['status'], 'A flagged non-administrator is recommended, not critical.' );
keel_assert( false !== strpos( $r['label'], '1 account has a password' ), 'The label carries the count: ' . $r['label'] );
keel_assert( false === strpos( $r['description'], '<script>' ) && false !== strpos( $r['description'], '&lt;script&gt;' ), 'Display names are escaped.' );
keel_assert( false !== strpos( $r['description'], '<caption>' ) && false !== strpos( $r['description'], 'scope="row"' ) && false !== strpos( $r['description'], 'role="region"' ), 'The list is a captioned table with row headers in a scrollable region.' );
keel_assert( false !== strpos( $r['description'], 'action=resetpassword&amp;users=2&amp;_wpnonce=NONCE-bulk-users' ), 'Each row links to core\'s nonced Send password reset action.' );
keel_assert( false !== strpos( $r['description'], 'user-edit.php?user_id=2#password' ), 'Each row links to the account\'s password field.' );
keel_assert( false !== strpos( $r['description'], 'datetime="' ), 'The flagged time is machine-readable.' );
keel_assert( false !== strpos( $r['actions'], 'users.php?keel_password=breached' ), 'Site Health links to the filtered Users view.' );
keel_assert( false !== strpos( $r['description'], 'at most once every 30 days' ), 'The interval reads as days, not "1 month".' );

$GLOBALS['keel_current_user'] = 2;
$r                            = keel_defaults_site_health_breached_passwords();
keel_assert( false === strpos( $r['description'], 'action=resetpassword' ), 'No reset email to yourself; matches core\'s row actions.' );
$GLOBALS['keel_current_user'] = 1;

$GLOBALS['keel_caps']['edit_user'] = false;
$r                                 = keel_defaults_site_health_breached_passwords();
keel_assert( false === strpos( $r['description'], 'user-edit.php' ) && false === strpos( $r['description'], 'resetpassword' ), 'No edit or reset links for accounts the viewer cannot edit.' );
$GLOBALS['keel_caps']['edit_user'] = true;

update_user_meta( 1, 'keel_password_breached', time() );
$r = keel_defaults_site_health_breached_passwords();
keel_assert( 'critical' === $r['status'] && false !== strpos( $r['label'], '2 accounts have passwords' ), 'A flagged administrator makes it critical.' );
keel_defaults_forget_password_screening( 1 );
keel_defaults_forget_password_screening( 2 );
$r = keel_defaults_site_health_breached_passwords();
keel_assert( 'good' === $r['status'] && '' === $r['actions'] && false !== strpos( $r['description'], 'No account on this site is flagged' ), 'A clean site passes, with no list.' );

// --- Users screen view ---
$views = array( 'all' => '<a href="users.php" class="current" aria-current="page">All</a>' );
keel_assert( keel_defaults_breached_users_view( $views ) === $views, 'No view is added while nothing is flagged.' );
update_user_meta( 2, 'keel_password_breached', time() );
$out = keel_defaults_breached_users_view( $views );
keel_assert( isset( $out['keel_breached'] ) && false !== strpos( $out['keel_breached'], '(1)' ), 'A flagged account adds a counted view.' );
$_GET['keel_password'] = 'breached';
$out                   = keel_defaults_breached_users_view( $views );
keel_assert( false === strpos( $out['all'], 'current' ) && false !== strpos( $out['keel_breached'], 'aria-current="page"' ), 'Only the active view is marked current.' );
keel_assert( isset( keel_defaults_breached_users_columns( array() )['keel_password_breached'] ), 'The filtered view adds a Breach found column.' );
$GLOBALS['keel_caps']['list_users'] = false;
keel_assert( keel_defaults_breached_users_view( $views ) === $views && array() === keel_defaults_breached_users_columns( array() ), 'Without list_users the view and column are not offered.' );
unset( $_GET['keel_password'] );
$GLOBALS['keel_caps']['list_users'] = true;

// --- multisite: a site's report covers only that site's members ---
// Ed (2) is flagged and belongs to site 1 only. Ana (7) belongs to site 2 only
// and is flagged and screened there. Kit (8) belongs to both.
$ana = new WP_User( 7, array( 'editor' ), 'ana', 'Ana Elsewhere' );
$kit = new WP_User( 8, array( 'author' ), 'kit', 'Kit Both' );

$GLOBALS['keel_users'][7]   = $ana;
$GLOBALS['keel_users'][8]   = $kit;
$GLOBALS['keel_user_sites'] = array(
	7 => array( 2 ),
	8 => array( 1, 2 ),
);

$GLOBALS['keel_user_query_args'] = array();
keel_defaults_forget_password_screening( 2 );
update_user_meta( 2, 'keel_password_breached', time() );
update_user_meta( 2, 'keel_password_screened', time() );
update_user_meta( 7, 'keel_password_breached', time() );
update_user_meta( 7, 'keel_password_screened', time() );

$GLOBALS['keel_blog_id'] = 1;
$found                   = keel_defaults_breached_users( 10 );
keel_assert( 1 === $found['total'] && 2 === $found['users'][0]->ID, 'Site 1 lists only its own flagged member, not another site\'s.' );
keel_assert( 1 === keel_defaults_screened_user_count(), 'Site 1 counts only its own screened members.' );
$r = keel_defaults_site_health_breached_passwords();
keel_assert( false === strpos( $r['description'], 'Ana Elsewhere' ) && false === strpos( $r['description'], 'ana' ) && false !== strpos( $r['label'], '1 account has a password' ), 'Site 1\'s Site Health neither names nor counts site 2\'s account.' );
keel_assert( false !== strpos( keel_defaults_breached_users_view( $views )['keel_breached'], '(1)' ), 'Site 1\'s Users view counts only its own members.' );

$GLOBALS['keel_blog_id'] = 2;
$found                   = keel_defaults_breached_users( 10 );
keel_assert( 1 === $found['total'] && 7 === $found['users'][0]->ID, 'Site 2 lists its own flagged member and not site 1\'s.' );
$r = keel_defaults_site_health_breached_passwords();
keel_assert( false === strpos( $r['description'], '&lt;script&gt;' ) && false !== strpos( $r['description'], 'Ana Elsewhere' ), 'Site 2\'s Site Health shows its member and not site 1\'s.' );

// The flag is network-wide user meta, so a member of both sites shows on both.
update_user_meta( 8, 'keel_password_breached', time() );
keel_assert( 2 === keel_defaults_breached_users( 10 )['total'], 'An account on two sites appears in site 2\'s report.' );
$GLOBALS['keel_blog_id'] = 1;
keel_assert( 2 === keel_defaults_breached_users( 10 )['total'], 'And in site 1\'s.' );

keel_assert( array() !== $GLOBALS['keel_user_query_args'], 'The multisite checks ran report queries.' );
foreach ( $GLOBALS['keel_user_query_args'] as $args ) {
	keel_assert( ! empty( $args['blog_id'] ), 'Every report query names its site explicitly.' );
}

// Severity must include older accounts beyond the ten displayed rows, including
// a second page of results, while respecting the current site's membership.
$saved_users = $GLOBALS['keel_users'];
$saved_meta  = $GLOBALS['keel_user_meta'];
for ( $id = 20; $id <= 130; ++$id ) {
	$GLOBALS['keel_users'][ $id ] = new WP_User( $id, array( 130 === $id ? 'administrator' : 'subscriber' ), 'account' . $id, 'Account ' . $id );
	update_user_meta( $id, 'keel_password_breached', 2000 - $id );
}
$r = keel_defaults_site_health_breached_passwords();
keel_assert( 'critical' === $r['status'], 'An older flagged administrator outside the displayed rows still makes the result critical.' );
keel_assert( false === strpos( $r['description'], 'Account 130' ), 'Severity does not expand the ten-row display.' );
$GLOBALS['keel_user_sites'][130] = array( 2 );
$r                               = keel_defaults_site_health_breached_passwords();
keel_assert( 'recommended' === $r['status'], 'A flagged administrator who belongs only to another site does not raise this site\'s severity.' );
for ( $id = 20; $id <= 130; ++$id ) {
	$GLOBALS['keel_users'][ $id ]->roles = array( 20 === $id ? 'administrator' : 'subscriber' );
	update_user_meta( $id, 'keel_password_breached', 1000 );
}
$r = keel_defaults_site_health_breached_passwords();
keel_assert( 'critical' === $r['status'], 'Equal flag timestamps retain a stable page order and do not hide an older administrator.' );
$GLOBALS['keel_users']     = $saved_users;
$GLOBALS['keel_user_meta'] = $saved_meta;
unset( $GLOBALS['keel_user_sites'][130] );

if ( $fail > 0 ) {
	fwrite( STDERR, "login breach screening: {$fail} failed\n" );
	exit( 1 );
}

fwrite( STDOUT, "login breach screening: OK\n" );
