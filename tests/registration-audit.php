<?php
/** Bounded registration introspection must never execute callbacks or repair hooks. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['site'] = 1;
function get_current_blog_id() { return $GLOBALS['site']; }
function current_user_can( $cap ) { return $GLOBALS['authorized'] ?? true; }
function __( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function check( $ok, $message ) { if ( ! $ok ) {
		throw new RuntimeException( $message ); } }
class WP_Hook {
	public $callbacks = array();
}
function callbacks( $hook, $priority, $callback ) {
	$GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][] = array( 'function' => $callback );
}
require __DIR__ . '/../includes/registration-audit.php';
check( 'unavailable' === keel_defaults_registration_audit()['coverage'], 'No bootstrap baseline is not a healthy registry.' );
keel_defaults_registration_baseline( true );
$callback = static function () { throw new RuntimeException( 'Audit executed a callback.' );
};
keel_defaults_record_registration( 'author_archives', $callback, 9 );
$GLOBALS['wp_filter'] = array( 'template_redirect' => new WP_Hook() );
$report               = keel_defaults_registration_audit();
check( 'missing' === $report['rows']['author_archives']['status'], 'Removed callback is reported.' );
check( array() === $GLOBALS['wp_filter']['template_redirect']->callbacks, 'Audit does not repair a missing callback.' );
check( 'inactive' === $report['rows']['session_length']['status'], 'Inactive policy is not missing.' );
callbacks( 'template_redirect', 9, $callback );
callbacks( 'template_redirect', 10, 'redirect_canonical' );
check( 'registered' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Known callback before canonical redirect is registered.' );
$GLOBALS['wp_filter']['template_redirect']->callbacks = array( 10 => array( array( 'function' => $callback ), array( 'function' => 'redirect_canonical' ) ) );
check( 'precedence_lost' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'A priority tie loses precedence.' );
$GLOBALS['wp_filter']['template_redirect']->callbacks = array();
callbacks( 'template_redirect', 0, $callback );
callbacks( 'template_redirect', 5, 'redirect_canonical' );
check( 'registered' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Priority zero is valid, not absent.' );
callbacks( 'template_redirect', -1, 'redirect_canonical' );
check( 'precedence_lost' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Core reprioritization is observed dynamically.' );
$session = static function () { throw new RuntimeException( 'No session evaluation.' );
};
keel_defaults_record_registration( 'session_length', $session, 50 );
$GLOBALS['wp_filter']['auth_cookie_expiration'] = new WP_Hook();
callbacks( 'auth_cookie_expiration', 10, $session );
callbacks( 'auth_cookie_expiration', 50, $session );
check( 'registered' === keel_defaults_registration_audit()['rows']['session_length']['status'], 'An extra early copy does not hide a valid late clamp.' );
unset( $GLOBALS['wp_filter']['auth_cookie_expiration']->callbacks[50] );
check( 'precedence_lost' === keel_defaults_registration_audit()['rows']['session_length']['status'], 'Moving a clamp to default priority loses its declared ordering.' );
$GLOBALS['site'] = 2;
check( 'context_mismatch' === keel_defaults_registration_audit()['coverage'], 'Switched-site settings cannot reuse another bootstrap baseline.' );
$GLOBALS['site']                                      = 1;
$GLOBALS['wp_filter']['template_redirect']->callbacks = array_fill( 0, 1001, array() );
check( 'inspection_limited' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Empty priority buckets cannot bypass the traversal bound.' );
$GLOBALS['wp_filter']['template_redirect']->callbacks = array( 10 => array_fill( 0, 1001, array( 'function' => $callback ) ) );
check( 'inspection_limited' === keel_defaults_registration_audit()['rows']['author_archives']['status'], 'Oversized hook registry yields unknown coverage.' );
$GLOBALS['authorized'] = false;
check( '' === keel_defaults_registration_health()['description'], 'Unauthorized callers cannot inspect registrations.' );
echo "OK: runtime registration audit boundaries.\n";
