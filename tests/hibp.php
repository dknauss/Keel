<?php
/**
 * Lightweight regression test for the breach-screening (HIBP) response handling.
 *
 * Covers the parts that decide whether a range response can be trusted at all:
 * the transport-boundary check, the well-formedness check, and the padded-row
 * rule. A response that fails any of them must read as *unknown* rather than
 * "not breached" — the two used to be the same value, which made an outage
 * indistinguishable from a clean corpus. Screening still fails open.
 *
 * It also covers what the lookup leaves behind, which is one row of counters:
 * no reply is kept between requests, and nothing about the password reaches
 * the database.
 *
 * Run: php tests/hibp.php
 *
 * @package keel
 */

$GLOBALS['keel_filters'] = array();
$GLOBALS['keel_options'] = array();
$GLOBALS['keel_uuid']    = 0;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['keel_filters'] ) ? $GLOBALS['keel_filters'][ $hook ] : $value;
}
$GLOBALS['keel_object_cache'] = array();
function wp_cache_get( $key, $group = '' ) {
	return array_key_exists( $group . ':' . $key, $GLOBALS['keel_object_cache'] ) ? $GLOBALS['keel_object_cache'][ $group . ':' . $key ] : false;
}
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	$GLOBALS['keel_object_cache'][ $group . ':' . $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['keel_options'] ) ? $GLOBALS['keel_options'][ $key ] : $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = true ) {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['keel_options'] ) ) {
		return false;
	}
	$GLOBALS['keel_options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) {
	unset( $autoload );
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

/*
 * --- transport and cache stubs, so keel_hibp_lookup() can be run end to end ---
 *
 * The cache stubs record what is written. Nothing should be: they are here so
 * a write would be noticed, not because the lookup uses them.
 */
$GLOBALS['keel_transients']    = array();
$GLOBALS['keel_http_calls']    = 0;
$GLOBALS['keel_http_reply']    = array(
	'code' => 200,
	'body' => '',
);
$GLOBALS['keel_http_lastargs'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['keel_transients'] ) ? $GLOBALS['keel_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['keel_transients'][ $key ] = $value;
	return true;
}
function wp_remote_get( $url, $args = array() ) {
	++$GLOBALS['keel_http_calls'];
	$GLOBALS['keel_http_lastargs'] = $args;
	return $GLOBALS['keel_http_reply'];
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) && isset( $r['code'] ) ? $r['code'] : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

require_once __DIR__ . '/stubs/wp-error.php';

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/stubs/hibp-store.php';
require dirname( __DIR__ ) . '/keel.php';

function keel_assert( $cond, $msg ) {
	if ( ! $cond ) {
		fwrite( STDERR, "Assertion failed: {$msg}\n" );
		exit( 1 );
	}
}

/**
 * Build a syntactically valid range line for a suffix.
 *
 * @param string $suffix 35-character SHA-1 suffix.
 * @param int    $count  Breach count (0 = padded row).
 * @return string
 */
function keel_range_line( $suffix, $count ) {
	return $suffix . ':' . $count;
}

$hit    = str_repeat( 'A', 35 );
$other  = str_repeat( 'B', 35 );
$padded = str_repeat( 'C', 35 );

// --- response limit ---
keel_assert( 131072 === keel_hibp_response_limit(), 'Default limit is 128KB.' );
$GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] = 10;
keel_assert( 1024 === keel_hibp_response_limit(), 'Limit is floored at 1KB, so a filter cannot make every response look truncated.' );
unset( $GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] );

// --- transport boundary ---
keel_assert( true === keel_hibp_body_is_complete( 'abc', 10 ), 'A short body is complete.' );
keel_assert( false === keel_hibp_body_is_complete( str_repeat( 'x', 10 ), 10 ), 'A body at the cap is treated as truncated.' );
keel_assert( false === keel_hibp_body_is_complete( str_repeat( 'x', 11 ), 10 ), 'A body over the cap is truncated.' );
// The case the untrimmed check exists for: capped body ending exactly on CRLF.
$capped = str_repeat( 'x', 8 ) . "\r\n";
keel_assert( false === keel_hibp_body_is_complete( $capped, 10 ), 'A capped body ending on CRLF is still truncated (checked before trimming).' );

// --- well-formedness ---
$valid = keel_range_line( $hit, 12 ) . "\r\n" . keel_range_line( $other, 3 );
keel_assert( true === keel_hibp_body_is_valid( $valid ), 'A CRLF range body is valid.' );
keel_assert( true === keel_hibp_body_is_valid( str_replace( "\r\n", "\n", $valid ) ), 'An LF range body is valid.' );
keel_assert( true === keel_hibp_body_is_valid( strtolower( $valid ) ), 'Lower-case hex is valid.' );
keel_assert( false === keel_hibp_body_is_valid( '' ), 'An empty body is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( '<html><body>502 Bad Gateway</body></html>' ), 'An HTML error page served with a 200 is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( $valid . "\r\n" . substr( $padded, 0, 20 ) . ':' ), 'A partial trailing line is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( keel_range_line( substr( $hit, 0, 34 ), 1 ) ), 'A short suffix is not valid.' );
keel_assert( false === keel_hibp_body_is_valid( $hit . ':x' ), 'A non-numeric count is not valid.' );

// --- match semantics ---
$body = implode(
	"\r\n",
	array(
		keel_range_line( $other, 5 ),
		keel_range_line( $padded, 0 ),
		keel_range_line( $hit, 1 ),
	)
);
keel_assert( true === keel_hibp_range_contains( $body, $hit ), 'A row with a real count is a match.' );
keel_assert( true === keel_hibp_range_contains( $body, strtolower( $hit ) ), 'Suffix comparison is case-insensitive.' );
keel_assert( false === keel_hibp_range_contains( $body, $padded ), 'A padded row (count 0) is not a match.' );
keel_assert( false === keel_hibp_range_contains( $body, str_repeat( 'D', 35 ) ), 'An absent suffix is not a match.' );

// --- opt-out plumbing (no network) ---
define( 'KEEL_DISABLE_HIBP', true );
keel_assert( false === keel_password_is_pwned( 'correct horse battery staple' ), 'The constant skips the lookup and reports not-breached.' );

$GLOBALS['keel_filters']['keel_password_is_pwned'] = true;
keel_assert( true === keel_password_is_pwned( 'anything' ), 'The verdict filter still applies when the lookup is disabled, so a local blocklist works.' );
unset( $GLOBALS['keel_filters']['keel_password_is_pwned'] );

// --- Jetpack awareness: warn where it applies, nowhere else ---
keel_assert( '' === keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ), 'With Jetpack absent, the endpoint block carries no warning.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'disable_rest' ), 'The warning belongs to one setting, not to every setting.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'disable_ai_connectors' ), 'With Jetpack absent, AI Connectors carries no warning.' );

define( 'JETPACK__VERSION', '14.0' );
keel_assert( '' !== keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ), 'With Jetpack active, the endpoint block warns.' );
keel_assert( '' === keel_defaults_jetpack_warning( 'disable_rest' ), 'Even then the warning stays on its own setting.' );

/*
 * Jetpack reads the same core gate Keel closes. Compared on a live site with
 * Keel loaded and with it skipped: with the gate closed, Jetpack reported every
 * AI extension unavailable with the reason "ai_disabled", did not register its
 * AI Assistant block, and dropped its jetpack-ai REST routes. The setting is
 * named for core's connectors, so nothing told the owner it reached Jetpack.
 */
$ai_warning = keel_defaults_jetpack_warning( 'disable_ai_connectors' );
keel_assert( false !== strpos( $ai_warning, 'Jetpack' ) && false !== strpos( $ai_warning, 'AI Assistant' ), 'With Jetpack active, AI Connectors says Jetpack\'s AI features follow it: ' . $ai_warning );
keel_assert( keel_defaults_jetpack_warning( 'block_xmlrpc_endpoint' ) !== $ai_warning, 'And it is its own warning, not the XML-RPC one.' );

/*
 * --- what a lookup leaves behind, which is one row of counters ---
 *
 * The breach check used to keep each reply for twelve hours. It keeps none now:
 * a reply answers the request that asked for it and is gone. What is stored is
 * the allowance and nothing else, so no row can say anything about a password
 * that was checked.
 *
 * Each reset below is a new request. That matters because one answer is
 * remembered for the rest of the request that asked — and for no longer.
 */
$GLOBALS['keel_request'] = 1000.0;

function keel_hibp_reset( $code, $body ) {
	$GLOBALS['wpdb']               = new Keel_HIBP_Test_DB();
	$GLOBALS['keel_transients']    = array();
	$GLOBALS['keel_object_cache']  = array();
	$GLOBALS['keel_http_calls']    = 0;
	$GLOBALS['keel_http_reply']    = array(
		'code' => $code,
		'body' => $body,
	);
	$_SERVER['REQUEST_TIME_FLOAT'] = ++$GLOBALS['keel_request'];
}

/**
 * Every stored row, by name.
 *
 * @return array<string,string>
 */
function keel_hibp_stored() {
	return $GLOBALS['wpdb']->pdo->query( 'SELECT option_name, option_value FROM wp_options ORDER BY option_name' )->fetchAll( PDO::FETCH_KEY_PAIR ); // phpcs:ignore WordPress.DB.RestrictedClasses -- SQL-backed test double.
}

/**
 * Whether the only thing stored is the allowance, with nothing of the password in it.
 *
 * At rest the row holds integers, two nulls and a fixed reason word. A hash, a
 * prefix or a reply body has nowhere to go, and this fails if one finds a way.
 *
 * @param string $password The password that was just checked.
 * @return bool
 */
function keel_hibp_only_the_allowance( $password ) {
	$rows = keel_hibp_stored();
	if ( array( 'keel_hibp_v2_0_state' ) !== array_keys( $rows ) ) {
		return false;
	}
	$raw   = $rows['keel_hibp_v2_0_state'];
	$state = json_decode( $raw, true );
	$hash  = strtoupper( sha1( $password ) );

	return false !== keel_hibp_budget_state( $raw, time() )
		&& array( 'v', 'rev', 'policy', 'credit', 'last', 'leases', 'sample', 'success' ) === array_keys( $state )
		&& array() === $state['leases']
		&& false === stripos( $raw, substr( $hash, 0, 5 ) )
		&& false === stripos( $raw, substr( $hash, 5 ) )
		&& false === stripos( $raw, $password )
		&& empty( $GLOBALS['keel_transients'] )
		&& empty( $GLOBALS['keel_object_cache'] );
}

$live_password = 'correct-horse-battery-staple-9271';
$live_suffix   = strtoupper( substr( sha1( $live_password ), 5 ) );

/*
 * The allowance is never handed anything about the password. Pinned by
 * signature, because the easiest way to bring a cache back is to start passing
 * the prefix or the reply in again.
 */
$parameters = static function ( $callable ) {
	return array_map(
		static function ( $parameter ) {
			return $parameter->getName();
		},
		( new ReflectionFunction( $callable ) )->getParameters()
	);
};
keel_assert( array( 'scope', 'privileged' ) === $parameters( 'keel_hibp_budget_admit' ), 'Admission is given the scope and whose check it is, and nothing about the lookup.' );
keel_assert( array( 'ticket', 'live', 'kind' ) === $parameters( 'keel_hibp_budget_finish' ), 'Completion is told whether a reply arrived, never the reply.' );
keel_assert( ! function_exists( 'keel_hibp_budget_cache' ) && ! function_exists( 'keel_hibp_budget_slot' ) && ! function_exists( 'keel_hibp_budget_claim' ), 'Nothing is left that reads, places or claims a kept reply.' );

// A 200 whose body is empty.
keel_hibp_reset( 200, '' );
keel_assert( null === keel_hibp_lookup( $live_password ), 'An empty 200 reports *unknown*, not "not breached".' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'An empty 200 leaves the allowance row and nothing else.' );
keel_assert( null === keel_hibp_lookup( $live_password ) && 2 === $GLOBALS['keel_http_calls'], 'Unknown is never remembered: the next check in the same request asks again.' );

// Same question for a body that is not a range list at all.
keel_hibp_reset( 200, '<html>captive portal</html>' );
keel_assert( null === keel_hibp_lookup( $live_password ), 'A malformed 200 reports unknown.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'A malformed 200 leaves the allowance row and nothing else.' );

// And for a transport failure.
keel_hibp_reset( 0, '' );
$GLOBALS['keel_http_reply'] = new WP_Error( 'http_request_failed', 'down' );
keel_assert( null === keel_hibp_lookup( $live_password ), 'An unreachable API reports unknown.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'An unreachable API leaves the allowance row and nothing else.' );

/*
 * A body cut off at the response limit is the dangerous one: the missing tail is
 * indistinguishable from "no match", so a real match silently reads as clean. It
 * must not be trusted.
 *
 * The body is built to reach the cap exactly — keel_hibp_body_is_complete() is a
 * `strlen < limit` test — and it is otherwise perfectly well formed, so nothing
 * but the length check can reject it.
 */
$GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] = 1024;
$limit  = keel_hibp_response_limit();
$line   = keel_range_line( str_repeat( 'B', 35 ), 2 );
$rows   = (int) floor( $limit / ( strlen( $line ) + 1 ) ) + 2;
$at_cap = implode( "\n", array_fill( 0, $rows, $line ) );

keel_assert( strlen( $at_cap ) >= $limit, 'The fixture really does reach the transport cap (' . strlen( $at_cap ) . ' >= ' . $limit . ').' );
keel_assert( keel_hibp_body_is_valid( trim( $at_cap ) ), 'The fixture is otherwise well formed, so only the length check can reject it.' );

keel_hibp_reset( 200, $at_cap );
keel_assert( null === keel_hibp_lookup( $live_password ), 'A body at the truncation boundary reports unknown.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'A body at the truncation boundary leaves the allowance row and nothing else.' );
unset( $GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] );

// The positive path: a definite answer, and still nothing kept.
keel_hibp_reset( 200, keel_range_line( $live_suffix, 42 ) );
keel_assert( true === keel_hibp_lookup( $live_password ), 'A listed suffix with a non-zero count is reported breached.' );
keel_assert( 1 === $GLOBALS['keel_http_calls'], 'The lookup went out once.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'A breached verdict leaves the allowance row and nothing else.' );

/*
 * One request may check the same password twice: the REST users route validates
 * it as an argument and again before the user is saved. The second check reuses
 * the first answer, so the pair costs one lookup, as it did when replies were kept.
 */
$raw = keel_hibp_stored()['keel_hibp_v2_0_state'];
keel_assert( true === keel_hibp_lookup( $live_password ), 'A second check in the same request gets the same answer.' );
keel_assert( 1 === $GLOBALS['keel_http_calls'], 'It does not go out again.' );
keel_assert( keel_hibp_stored()['keel_hibp_v2_0_state'] === $raw, 'It spends no allowance and records nothing.' );

// The answer is for one password. Another one is a new question.
$GLOBALS['keel_http_reply']['body'] = keel_range_line( str_repeat( 'D', 35 ), 9 );
keel_assert( false === keel_hibp_lookup( 'another-value-entirely-5512' ), 'A different password in the same request is looked up, and is clean here.' );
keel_assert( 2 === $GLOBALS['keel_http_calls'], 'It went out for it.' );
keel_assert( false === keel_hibp_lookup( 'another-value-entirely-5512' ) && 2 === $GLOBALS['keel_http_calls'], 'A clean answer is reused within the request as well.' );

// One answer is held, not a list: the newer one replaced the older.
$GLOBALS['keel_http_reply']['body'] = keel_range_line( $live_suffix, 42 );
keel_assert( true === keel_hibp_lookup( $live_password ) && 3 === $GLOBALS['keel_http_calls'], 'Only the latest answer is held, so the first password is asked about again.' );

// And it does not outlive the request. A long-running worker serves many.
$_SERVER['REQUEST_TIME_FLOAT'] = ++$GLOBALS['keel_request'];
keel_assert( true === keel_hibp_lookup( $live_password ) && 4 === $GLOBALS['keel_http_calls'], 'The next request asks again: nothing was kept to answer it from.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'Four lookups later the allowance row is still all there is.' );
$state = json_decode( keel_hibp_stored()['keel_hibp_v2_0_state'], true );
keel_assert( 600 - 4 * 60 === $state['credit'], 'Each lookup that went out spent one credit, and the reused answers spent none.' );

// With the allowance spent there is nothing to fall back on, and that is the point.
keel_hibp_reset( 200, keel_range_line( $live_suffix, 1 ) );
keel_assert( true === keel_hibp_lookup( $live_password ), 'A lookup with allowance in hand answers.' );
$scope           = keel_hibp_budget_scope();
$old             = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
$state           = json_decode( $old, true );
$state['credit'] = 0;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', $old, $state );
$_SERVER['REQUEST_TIME_FLOAT'] = ++$GLOBALS['keel_request'];
$before                        = $GLOBALS['keel_http_calls'];
keel_assert( null === keel_hibp_lookup( $live_password ), 'With the allowance spent, the same password in a later request is unknown.' );
keel_assert( $GLOBALS['keel_http_calls'] === $before, 'And no request goes out for it.' );

/*
 * TLS verification is what makes the hostile-upstream case require a real
 * compromise rather than a coffee-shop network. Keel never passes sslverify, so
 * WordPress's default (true) stands; this fails if anyone ever adds it as false.
 */
keel_hibp_reset( 200, keel_range_line( $live_suffix, 1 ) );
keel_hibp_lookup( $live_password );
keel_assert(
	! array_key_exists( 'sslverify', $GLOBALS['keel_http_lastargs'] ) || false !== $GLOBALS['keel_http_lastargs']['sslverify'],
	'The breach lookup never disables TLS verification.'
);
keel_assert(
	isset( $GLOBALS['keel_http_lastargs']['headers']['Add-Padding'] ),
	'The lookup asks for padding, so response size cannot reveal how many real matches a prefix had.'
);

// Sampled diagnostics contain no credential material and do not count denials.
keel_hibp_reset( 429, '' );
keel_hibp_lookup( $live_password );
$record = keel_hibp_last_failure();
keel_assert( 'http_429' === $record['kind'], 'HTTP rate limiting is distinguishable.' );
keel_assert( ! isset( $record['count'] ), 'Sampling is not a denied-lookup counter.' );
keel_hibp_budget_note( 'malformed' );
keel_assert( keel_hibp_last_failure() === $record, 'Changing reasons cannot bypass the five-minute sampling interval.' );
$GLOBALS['keel_http_reply'] = array(
	'code' => 200,
	'body' => $live_suffix . ':3',
);
keel_assert( true === keel_hibp_lookup( $live_password ), 'A live lookup can recover.' );
keel_assert( null !== keel_hibp_last_success(), 'Live completion records success.' );
keel_assert( keel_hibp_last_failure() === $record, 'Success preserves the bounded sampling cadence.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'Failure and recovery evidence live in the allowance row too.' );
keel_assert( false === keel_password_is_pwned( 'anything' ), 'Opt-out and fail-open adapter remain unchanged.' );

// Atomic admission and bounded state tests (real parallel proof is a separate lab).
keel_hibp_reset( 200, $live_suffix . ':1' );
$scope = keel_hibp_budget_scope();
$a     = keel_hibp_budget_admit( $scope );
$b     = keel_hibp_budget_admit( $scope );
keel_assert( isset( $a['ticket'], $b['ticket'] ), 'Two lookups can be in flight at once.' );
keel_assert( 0 === $a['ticket']['slot'] && 1 === $b['ticket']['slot'], 'They take the two lanes in order.' );
keel_assert( array( 'slot', 'owner', 'until', 'scope' ) === array_keys( $a['ticket'] ), 'A ticket names a lane, an owner and an expiry, and nothing about the lookup.' );
keel_assert( 'lookup_busy' === keel_hibp_budget_admit( $scope )['error'], 'A third active lookup is denied.' );
keel_assert( array( 'keel_hibp_v2_0_state' ) === array_keys( keel_hibp_stored() ), 'Lookups in flight add leases to the one row, not rows.' );
keel_assert( keel_hibp_budget_finish( $a['ticket'], false, 'unreachable' ), 'Failure releases its own lease.' );
keel_assert( ! keel_hibp_budget_finish( $a['ticket'], true ), 'A released ticket cannot release twice or record a success.' );
$state = json_decode( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), true );
keel_assert( null === $state['success'], 'The refused completion recorded nothing.' );
$c = keel_hibp_budget_admit( $scope );
keel_assert( isset( $c['ticket'] ) && 0 === $c['ticket']['slot'], 'The freed lane is the next one taken, whatever is being looked up.' );
keel_hibp_budget_finish( $c['ticket'], false, 'unreachable' );

// Past its expiry a lease is void, whether or not anything has taken its place yet.
$old                         = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
$state                       = json_decode( $old, true );
$state['leases'][0]['until'] = time() - 1;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', $old, $state );
$lapsed = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
keel_assert( $b['ticket']['owner'] === $state['leases'][0]['owner'] && $b['ticket']['until'] > time(), 'Fixture: the lease has lapsed in the stored row while its ticket still looks live.' );
keel_assert( false === keel_hibp_budget_owned( $b['ticket'] ), 'A lapsed lease is no longer owned.' );
keel_assert( ! keel_hibp_budget_finish( $b['ticket'], true ), 'And cannot complete.' );
keel_assert( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ) === $lapsed, 'The refused completion changed nothing.' );
$b['ticket']['until']        = time() - 1;
$state['leases'][0]['until'] = time() + 20;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', $lapsed, $state );
keel_assert( ! keel_hibp_budget_finish( $b['ticket'], true ), 'Nor can a ticket that says itself that it has lapsed.' );
$state = json_decode( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), true );
keel_assert( null === $state['success'] && 1 === count( $state['leases'] ), 'Neither recorded a success or released the lease.' );
keel_assert( 420 === $state['credit'], 'Failure does not refund consumed credits.' );
$state['credit'] = 0;
$old             = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', $old, $state );
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope )['error'], 'Empty allowance denies outbound work.' );
$GLOBALS['wpdb']->query( "UPDATE wp_options SET option_value = '{}' WHERE option_name = 'keel_hibp_v2_0_state'" );
$before = $GLOBALS['keel_http_calls'];
keel_assert( null === keel_hibp_lookup( 'some-other-value' ), 'Corrupt authority is unknown.' );
keel_assert( $GLOBALS['keel_http_calls'] === $before, 'Corrupt authority cannot mint a fresh burst.' );
keel_assert( '{}' === keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), 'Invalid authority is never reset.' );
keel_hibp_reset( 200, $live_suffix . ':1' );
$GLOBALS['wpdb']->pdo->beginTransaction();
$GLOBALS['wpdb']->query( "INSERT INTO wp_options VALUES ('caller-owned','pending','no')" );
keel_assert( null === keel_hibp_lookup( $live_password ), 'Caller-owned transaction denies new outbound work.' );
keel_assert( 0 === $GLOBALS['keel_http_calls'], 'No HTTP runs inside a caller-owned transaction.' );
keel_assert( $GLOBALS['wpdb']->pdo->inTransaction(), 'Lookup never commits the caller transaction.' );
keel_assert( 1 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_options' ), 'No budget or diagnostic writes join the caller transaction.' );
$GLOBALS['wpdb']->pdo->rollBack();
keel_assert( 0 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_options' ), 'Caller retains rollback control.' );
// An operator may raise the transport cap. A large reply is answered from and dropped like any other.
$large = implode( "\n", array_fill( 0, 3600, $live_suffix . ':1' ) );
$GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] = 200000;
keel_hibp_reset( 200, $large );
keel_assert( strlen( $large ) > 131072 && keel_hibp_body_is_valid( $large ), 'Oversized fixture is a valid complete range.' );
keel_assert( true === keel_hibp_lookup( $live_password ), 'Oversized valid live response preserves its positive verdict.' );
keel_assert( keel_hibp_only_the_allowance( $live_password ), 'Raising the transport cap stores nothing more.' );
unset( $GLOBALS['keel_filters']['keel_hibp_max_response_bytes'] );
// Case-insensitive DB collations must not weaken byte-exact CAS.
keel_hibp_reset( 200, '' );
$scope   = keel_hibp_budget_scope();
$initial = keel_hibp_budget_initial( keel_hibp_budget_policy(), time() );
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', null, $initial );
$raw = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
keel_assert( ! keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', strtoupper( $raw ), $initial ), 'CAS rejects a differently cased expected byte string.' );
$initial['rev']                = 0;
$GLOBALS['wpdb']->cas_attempts = 0;
$GLOBALS['wpdb']->cas_failures = 10;
$denied                        = keel_hibp_budget_admit( $scope );
keel_assert( 'budget_unavailable' === $denied['error'], 'Repeated CAS contention produces unknown.' );
keel_assert( 3 === $GLOBALS['wpdb']->cas_attempts, 'Admission stops after three CAS attempts without polling.' );

// Capability failures must remain unknown rather than interrupting password changes.
keel_hibp_reset( 200, $live_suffix . ':1' );
$GLOBALS['wpdb']->driver_failure = true;
keel_assert( null === keel_hibp_lookup( $live_password ), 'A throwing driver capability check remains unknown.' );
keel_assert( 0 === $GLOBALS['keel_http_calls'], 'A throwing capability check starts no HTTP.' );
keel_assert( 0 === (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_options' ), 'Failed capability discovery creates no authority.' );
keel_assert( false === $GLOBALS['wpdb']->suppress_errors( false ), 'Capability failures restore database error-suppression state.' );
$GLOBALS['wpdb']->suppress_errors( true );
keel_assert( null === keel_hibp_lookup( $live_password ), 'Repeated driver failures remain unknown.' );
keel_assert( true === $GLOBALS['wpdb']->suppress_errors( false ), 'Capability failures also preserve existing suppression.' );

/*
 * --- half the allowance is kept back for privileged accounts ---
 *
 * Any visitor who can reach a password field can make the site spend allowance.
 * Without a reserve, enough of those requests leave nothing for the accounts
 * that matter, and the check fails open for them too. So requests that are not
 * for a privileged account stop at the halfway line.
 */
$policy = keel_hibp_budget_policy();
keel_assert( 300 === keel_hibp_budget_reserve( $policy ), 'The default reserve is five lookups out of ten.' );
keel_assert(
	60 === keel_hibp_budget_reserve(
		array(
			'capacity' => 3,
			'interval' => 60,
		)
	),
	'An odd allowance rounds the reserve down.'
);
keel_assert(
	0 === keel_hibp_budget_reserve(
		array(
			'capacity' => 1,
			'interval' => 60,
		)
	),
	'An allowance of one cannot be split, so it is shared.'
);

keel_hibp_reset( 200, $live_suffix . ':1' );
$scope = keel_hibp_budget_scope();
for ( $i = 0; $i < 5; ++$i ) {
	$ordinary = keel_hibp_budget_admit( $scope );
	keel_assert( isset( $ordinary['ticket'] ), 'Ordinary lookup ' . ( $i + 1 ) . ' of five is admitted.' );
	keel_hibp_budget_finish( $ordinary['ticket'], true );
}
$raw = keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' );
keel_assert( 300 === json_decode( $raw, true )['credit'], 'Five ordinary lookups leave exactly the reserve.' );
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope )['error'], 'A sixth ordinary lookup is refused with the reserve intact.' );
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope, 1 )['error'], 'Only a strict true opens the reserve: a truthy value is an ordinary request.' );
keel_assert( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ) === $raw, 'A refusal writes nothing.' );

$before = $GLOBALS['keel_http_calls'];
keel_assert( null === keel_hibp_lookup( $live_password ), 'End to end, an ordinary check at the line is unknown.' );
keel_assert( $GLOBALS['keel_http_calls'] === $before, 'And sends nothing.' );
keel_assert( true === keel_hibp_lookup( $live_password, true ), 'The same check for a privileged account is answered.' );
keel_assert( $GLOBALS['keel_http_calls'] === $before + 1, 'From one lookup.' );
keel_assert( 240 === json_decode( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), true )['credit'], 'It came out of the reserve.' );
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope )['error'], 'Ordinary requests are still refused below the line.' );

for ( $i = 0; $i < 4; ++$i ) {
	$reserved = keel_hibp_budget_admit( $scope, true );
	keel_assert( isset( $reserved['ticket'] ), 'A privileged account can spend the reserve to nothing.' );
	keel_hibp_budget_finish( $reserved['ticket'], true );
}
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope, true )['error'], 'And then it waits like everyone else.' );

// The line is worked out from the policy, never stored, so the stored row keeps its shape.
keel_assert( array( 'v', 'rev', 'policy', 'credit', 'last', 'leases', 'sample', 'success' ) === array_keys( json_decode( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), true ) ), 'The reserve adds nothing to the stored row.' );

// A refill is spent by whoever asks first: ordinary requests get what stands above the line.
$state           = json_decode( keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), true );
$state['credit'] = 360;
keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', keel_hibp_budget_read( $scope, 'keel_hibp_v2_0_state' ), $state );
$ordinary = keel_hibp_budget_admit( $scope );
keel_assert( isset( $ordinary['ticket'] ), 'One credit above the line admits one ordinary lookup.' );
keel_hibp_budget_finish( $ordinary['ticket'], true );
keel_assert( 'budget_exhausted' === keel_hibp_budget_admit( $scope )['error'], 'And only one.' );

/*
 * --- what earlier versions left behind is deleted ---
 *
 * Versions up to 0.9.1 kept each reply for twelve hours in one of 64 fixed rows
 * beside the control row. Those rows are deleted by name when a site first
 * loads this version, and again with WordPress's daily clean-up, because a
 * request still running the old code can write one after the first pass.
 */

/**
 * Put back what an earlier version would have stored: a control row and 64 kept replies.
 *
 * @return void
 */
function keel_hibp_seed_earlier_rows() {
	keel_hibp_reset( 200, '' );
	$scope = keel_hibp_budget_scope();
	keel_hibp_budget_write( $scope, 'keel_hibp_v2_0_state', null, keel_hibp_budget_initial( keel_hibp_budget_policy(), time() ) );
	for ( $row = 0; $row < 64; ++$row ) {
		keel_hibp_budget_write(
			$scope,
			sprintf( 'keel_hibp_v2_0_slot_%02d', $row ),
			null,
			array(
				'v'     => 2,
				'owner' => str_repeat( 'a', 32 ),
				'data'  => array(
					'prefix'   => sprintf( 'ABC%02X', $row ),
					'body'     => str_repeat( 'E', 35 ) . ':7',
					'received' => time() - 50000,
					'expires'  => time() - 6800,
				),
			)
		);
	}
	// Neighbours that must survive: somebody else's option, and a name that only resembles ours.
	$GLOBALS['wpdb']->query( "INSERT INTO wp_options VALUES ('siteurl','https://example.test','yes')" );
	$GLOBALS['wpdb']->query( "INSERT INTO wp_options VALUES ('keel_hibp_v2_0_slot_64','not ours to judge','no')" );
	$GLOBALS['wpdb']->query( "INSERT INTO wp_options VALUES ('keel_hibp_v2_1_slot_00','another network','no')" );
}

keel_hibp_seed_earlier_rows();
keel_assert( 68 === count( keel_hibp_stored() ), 'The fixture holds a control row, 64 kept replies and three neighbours.' );
keel_assert( true === keel_hibp_budget_forget_replies( keel_hibp_budget_scope() ), 'The clean-up reports success.' );
keel_assert(
	array( 'keel_hibp_v2_0_slot_64', 'keel_hibp_v2_0_state', 'keel_hibp_v2_1_slot_00', 'siteurl' ) === array_keys( keel_hibp_stored() ),
	'All 64 kept replies are gone, and the allowance, another network\'s rows and unrelated options are untouched.'
);
keel_assert( true === keel_hibp_budget_forget_replies( keel_hibp_budget_scope() ), 'Running it again with nothing to delete still reports success.' );

// The same clean-up, as WordPress's daily clean-up calls it.
keel_hibp_seed_earlier_rows();
keel_assert( true === keel_defaults_forget_breach_replies(), 'The daily clean-up deletes them too.' );
keel_assert( 4 === count( keel_hibp_stored() ), 'And leaves the same four rows.' );
$keel_bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/keel.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source, not a remote URL.
keel_assert(
	false !== strpos( $keel_bootstrap, "add_action( 'delete_expired_transients', 'keel_defaults_forget_breach_replies' );" ),
	'The clean-up is hooked to WordPress\'s daily clean-up of expired cached data.'
);

// Updating from the version that kept replies deletes them on the first request.
keel_hibp_seed_earlier_rows();
$GLOBALS['keel_options'] = array(
	KEEL_DEFAULTS_OPTION              => array( 'post_revisions_limit' => 10 ),
	KEEL_DEFAULTS_DATA_VERSION_OPTION => 1,
);
keel_assert( 2 === KEEL_DEFAULTS_DATA_VERSION, 'Dropping the kept replies is data version 2.' );
keel_assert( true === keel_defaults_maybe_upgrade(), 'A site on data version 1 is upgraded.' );
keel_assert( 4 === count( keel_hibp_stored() ), 'The upgrade deleted the kept replies.' );
keel_assert( 2 === $GLOBALS['keel_options'][ KEEL_DEFAULTS_DATA_VERSION_OPTION ], 'And recorded the new version.' );
$queries = $GLOBALS['wpdb']->queries;
keel_assert( false === keel_defaults_maybe_upgrade() && $queries === $GLOBALS['wpdb']->queries, 'An upgraded site runs no clean-up query on later requests.' );

// A database that refuses the delete is asked again on the next request.
keel_hibp_seed_earlier_rows();
$GLOBALS['keel_options'][ KEEL_DEFAULTS_DATA_VERSION_OPTION ] = 1;
$GLOBALS['wpdb']->refuse_deletes                              = true;
keel_assert( false === keel_hibp_budget_forget_replies( keel_hibp_budget_scope() ), 'A refused delete is reported.' );
keel_assert( false === keel_defaults_maybe_upgrade(), 'The upgrade does not claim to have happened.' );
keel_assert( 1 === $GLOBALS['keel_options'][ KEEL_DEFAULTS_DATA_VERSION_OPTION ], 'The version stays where it was.' );
keel_assert( 68 === count( keel_hibp_stored() ), 'Nothing was half-deleted.' );
$GLOBALS['wpdb']->refuse_deletes = false;
keel_assert( true === keel_defaults_maybe_upgrade() && 4 === count( keel_hibp_stored() ), 'The next request finishes the job.' );

// With no database to speak of there is nothing to delete, and the upgrade is not held up.
$GLOBALS['keel_options'][ KEEL_DEFAULTS_DATA_VERSION_OPTION ] = 1;
$GLOBALS['wpdb'] = null;
keel_assert( true === keel_defaults_forget_breach_replies(), 'No scope means nothing to clean up.' );
keel_assert( true === keel_defaults_maybe_upgrade(), 'And the upgrade still completes.' );

echo "hibp: OK\n";
