<?php
/**
 * Same-line patch installer tests.
 *
 * The filesystem write itself belongs to the real-core matrix. This file pins
 * every decision Keel makes before handing the offer to Core_Upgrader, plus the
 * exact offer and arguments handed across that boundary.
 *
 * @package keel
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/fixtures/no-db-dropin' );

$fail = 0;

function keel_install_assert( $condition, $message ) {
	global $fail;
	if ( ! $condition ) {
		++$fail;
		fwrite( STDERR, "Assertion failed: {$message}\n" );
	}
}

class WP_Error {
	private $code;
	private $messages;

	public function __construct( $code = '', $message = '' ) {
		$this->code     = $code;
		$this->messages = '' === $message ? array() : array( $message );
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return isset( $this->messages[0] ) ? $this->messages[0] : '';
	}

	public function get_error_messages() {
		return $this->messages;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

$GLOBALS['keel_install'] = array(
	'tip'        => '6.8.8',
	'blockers'   => array(),
	'transients' => array(),
	'can'        => array(
		'update_core'            => true,
		'manage_network_options' => true,
	),
	'multisite'  => false,
	'user'       => 7,
	'relaxed'    => true,
);

function __( $text, $domain = '' ) {
	return $text;
}

function esc_html__( $text, $domain = '' ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return $url;
}

function wp_kses_post( $html ) {
	return strip_tags( $html, '<a><strong><code>' );
}

function add_action( $hook, $callback ) {}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['keel_install']['can'][ $capability ] );
}

function is_multisite() {
	return $GLOBALS['keel_install']['multisite'];
}

function get_locale() {
	return isset( $GLOBALS['keel_install']['locale'] ) ? $GLOBALS['keel_install']['locale'] : 'en_US';
}

function get_current_user_id() {
	return $GLOBALS['keel_install']['user'];
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function wp_create_nonce( $action ) {
	return 'nonce:' . $action;
}

function get_site_transient( $key ) {
	return isset( $GLOBALS['keel_install']['transients'][ $key ] )
		? $GLOBALS['keel_install']['transients'][ $key ]
		: false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['keel_install']['transients'][ $key ] = $value;
	$GLOBALS['keel_install']['ttl'][ $key ]        = $ttl;
	return true;
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['keel_install']['transients'][ $key ] );
	return true;
}

function get_transient( $key ) {
	return get_site_transient( $key );
}

function set_transient( $key, $value, $ttl = 0 ) {
	return set_site_transient( $key, $value, $ttl );
}

function delete_transient( $key ) {
	return delete_site_transient( $key );
}

function keel_defaults_branch_tip() {
	return $GLOBALS['keel_install']['tip'];
}

function keel_defaults_minor_update_state() {
	return array(
		'policy'   => empty( $GLOBALS['keel_install']['automatic_disabled'] ),
		'operable' => empty( $GLOBALS['keel_install']['blockers'] ),
		'owner'    => 'option',
		'blockers' => $GLOBALS['keel_install']['blockers'],
	);
}

function keel_defaults_blocker_codes( array $blockers ) {
	return array_column( $blockers, 'code' );
}

function keel_defaults_relaxed_ownership_allowed() {
	return $GLOBALS['keel_install']['relaxed'];
}

class Automatic_Upgrader_Skin {}

class Core_Upgrader {
	public function __construct( $skin ) {
		$GLOBALS['keel_install']['skin'] = $skin;
	}

	public function upgrade( $offer, $args ) {
		$GLOBALS['keel_install']['upgrade_calls'][] = array( $offer, $args );
		return isset( $GLOBALS['keel_install']['upgrade_result'] )
			? $GLOBALS['keel_install']['upgrade_result']
			: $offer->current;
	}
}

$GLOBALS['wpdb'] = new class() {
	public $is_mysql = true;

	public function db_version() {
		return isset( $GLOBALS['keel_install']['db_version'] ) ? $GLOBALS['keel_install']['db_version'] : '8.0';
	}
};

require dirname( __DIR__ ) . '/includes/backport-install.php';

function keel_install_offer( $overrides = array() ) {
	$offer = array_merge(
		array(
			'response'        => 'autoupdate',
			'current'         => '6.8.8',
			'locale'          => 'en_US',
			'php_version'     => '7.2.24',
			'mysql_version'   => '5.5.5',
			'new_files'       => false,
			'new_bundled'     => '6.7',
			'partial_version' => '6.8.7',
			'packages'        => (object) array(
				'partial'     => 'https://downloads.example/partial.zip',
				'new_bundled' => false,
				'no_content'  => 'https://downloads.example/no-content.zip',
				'full'        => 'https://downloads.example/full.zip',
				'rollback'    => 'https://downloads.example/rollback.zip',
			),
		),
		$overrides
	);

	return (object) $offer;
}

function keel_install_prime_offer( $offer = null ) {
	$GLOBALS['keel_install']['transients']['update_core'] = (object) array(
		'updates' => array( null === $offer ? keel_install_offer() : $offer ),
	);
}

function keel_install_error_code( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
}

keel_install_prime_offer();


// A localized site's offer, shaped the way core actually stores it.
//
// wp_version_check() maps every package through esc_url(), so a package the
// API sends as false is written to the transient as ''. A site that declares
// local_package gets a localized offer whose only package is the localized
// full zip — no_content, new_bundled, partial and rollback are all false — so
// all four arrive as empty strings.
//
// The previous fixture spelled an absent package `false`, which is the API's
// shape and not the transient's, and the validator rejected ''. The result was
// that the install refused on every non-English site with "the cached update
// offer contains an invalid package", and the unit suite could not see it
// because it was testing the wrong shape. The live matrix found it on fr_FR.
function keel_install_localized_offer() {
	return keel_install_offer(
		array(
			'locale'          => 'fr_FR',
			'partial_version' => '',
			'new_bundled'     => '',
			'packages'        => (object) array(
				'partial'     => '',
				'new_bundled' => '',
				'no_content'  => '',
				'full'        => 'https://downloads.example/fr_FR/full.zip',
				'rollback'    => '',
			),
		)
	);
}

keel_install_assert(
	true === keel_defaults_validate_install_offer( keel_install_localized_offer() ),
	'a localized offer whose optimized packages are empty strings is valid'
);

keel_install_prime_offer( keel_install_localized_offer() );
$localized_plan = keel_defaults_prepare_backport_install( '6.8.8' );

keel_install_assert(
	is_array( $localized_plan ),
	'a localized site can prepare the install rather than being refused'
);
keel_install_assert(
	isset( $localized_plan['offer'] ) && 'https://downloads.example/fr_FR/full.zip' === $localized_plan['offer']->packages->full,
	'and the localized full package is the one handed to the upgrader'
);

// The genuinely malformed case still fails: a package that is neither a string
// nor false is not something core could have written.
keel_install_assert(
	'invalid_offer' === keel_install_error_code(
		keel_defaults_validate_install_offer(
			keel_install_offer(
				array(
					'packages' => (object) array(
						'partial'     => array(),
						'new_bundled' => '',
						'no_content'  => '',
						'full'        => 'https://downloads.example/full.zip',
						'rollback'    => '',
					),
				)
			)
		)
	),
	'a package that is neither string nor false is still refused'
);

keel_install_prime_offer();

// Automatic-update policy is irrelevant to a deliberate install.
$GLOBALS['keel_install']['automatic_disabled'] = true;
$GLOBALS['keel_install']['blockers']           = array(
	array(
		'code' => 'automatic_disabled_filter',
		'text' => 'automatic updates disabled',
	),
);
$plan = keel_defaults_prepare_backport_install( '6.8.8' );
keel_install_assert( is_array( $plan ), 'automatic updates disabled with a healthy filesystem is allowed' );

foreach ( array( 'file_mods', 'credentials', 'vcs' ) as $code ) {
	$GLOBALS['keel_install']['blockers'] = array(
		array(
			'code' => $code,
			'text' => "blocked by {$code}",
		),
	);
	$before                              = count( isset( $GLOBALS['keel_install']['upgrade_calls'] ) ? $GLOBALS['keel_install']['upgrade_calls'] : array() );
	$result                              = keel_defaults_prepare_backport_install( '6.8.8' );
	keel_install_assert( 'filesystem_blocked' === keel_install_error_code( $result ), "{$code} refuses the install" );
	keel_install_assert( count( isset( $GLOBALS['keel_install']['upgrade_calls'] ) ? $GLOBALS['keel_install']['upgrade_calls'] : array() ) === $before, "{$code} never reaches the upgrader" );
}

// #139 lets a selected *different* offer outrank the branch-tip credential
// probe for reporting. The install target is the branch tip itself, so that
// exception must never cross this boundary even when core selected the tip.
$GLOBALS['keel_install']['selected'] = '6.8.8';
$GLOBALS['keel_install']['blockers'] = array(
	array(
		'code' => 'credentials',
		'text' => 'credentials unavailable for the selected tip',
	),
);
keel_install_assert(
	'filesystem_blocked' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ),
	'credentials refuses the actual target even when core selected that target'
);

$GLOBALS['keel_install']['blockers'] = array(
	array(
		'code' => 'previous_failure',
		'text' => 'previous failure',
	),
);
$plan                                = keel_defaults_prepare_backport_install( '6.8.8' );
keel_install_assert( is_array( $plan ), 'a previous critical failure is allowed' );
$button = keel_defaults_backport_install_button( '6.8.8', 'none', keel_defaults_minor_update_state() );
keel_install_assert( false !== strpos( $button, 'previous core update failed critically' ), 'the previous failure is warned beside the button' );

$GLOBALS['keel_install']['blockers'] = array();
keel_install_assert( 'stale_target' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.7' ) ), 'a wrong target is refused' );
$GLOBALS['keel_install']['tip'] = '';
keel_install_assert( 'stale_target' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'a replay after the tip moves is refused' );
$GLOBALS['keel_install']['tip'] = '6.8.8';

keel_install_assert( 'method_not_allowed' === keel_install_error_code( keel_defaults_validate_backport_request( 'GET', true, false, true ) ), 'GET is refused' );
keel_install_assert( 'forbidden' === keel_install_error_code( keel_defaults_validate_backport_request( 'POST', false, false, false ) ), 'missing update_core is refused' );
keel_install_assert( 'forbidden' === keel_install_error_code( keel_defaults_validate_backport_request( 'POST', true, true, false ) ), 'multisite also requires manage_network_options' );
keel_install_assert( true === keel_defaults_validate_backport_request( 'POST', true, true, true ), 'a network administrator can proceed' );
keel_install_assert( keel_defaults_backport_nonce_action( '6.8.8' ) !== keel_defaults_backport_nonce_action( '6.8.9' ), 'the nonce action is bound to the target version' );

unset( $GLOBALS['keel_install']['transients']['update_core'] );
keel_install_assert( 'invalid_offer' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'a missing offer is refused' );
keel_install_prime_offer( (object) array( 'current' => '6.8.8' ) );
keel_install_assert( 'invalid_offer' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'a malformed offer is refused' );
keel_install_prime_offer(
	keel_install_offer(
		array(
			'packages' => (object) array(
				'partial'     => array(),
				'new_bundled' => false,
				'no_content'  => false,
				'full'        => 'https://downloads.example/full.zip',
				'rollback'    => false,
			),
		)
	)
);
keel_install_assert( 'invalid_offer' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'a malformed package field is refused' );
keel_install_prime_offer(
	keel_install_offer(
		array(
			'packages' => (object) array(
				'partial'     => false,
				'new_bundled' => false,
				'no_content'  => false,
				'full'        => false,
				'rollback'    => false,
			),
		)
	)
);
keel_install_assert( 'no_package' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'a packageless offer is refused' );

keel_install_prime_offer( keel_install_offer( array( 'php_version' => '99.0' ) ) );
keel_install_assert( 'php_not_compatible' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'PHP incompatibility is refused before upgrade' );
keel_install_prime_offer( keel_install_offer( array( 'mysql_version' => '99.0' ) ) );
keel_install_assert( 'mysql_not_compatible' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'MySQL incompatibility is refused before upgrade' );
keel_install_prime_offer( keel_install_offer( array( 'required_php_extensions' => array( 'keel_extension_that_does_not_exist' ) ) ) );
keel_install_assert( 'php_extensions_not_compatible' === keel_install_error_code( keel_defaults_prepare_backport_install( '6.8.8' ) ), 'an offered missing PHP extension is refused before upgrade' );

// Duplicate versions prefer the site locale, then its automatic offer.
$GLOBALS['keel_install']['locale'] = 'fr_FR';
$english                           = keel_install_offer();
$french_manual                     = keel_install_offer(
	array(
		'locale'   => 'fr_FR',
		'response' => 'upgrade',
	)
);
$french_auto                       = keel_install_offer( array( 'locale' => 'fr_FR' ) );
$GLOBALS['keel_install']['transients']['update_core'] = (object) array( 'updates' => array( $english, $french_manual, $french_auto ) );
keel_install_assert( keel_defaults_install_offer_for_version( '6.8.8' ) === $french_auto, 'the matching-locale automatic offer wins duplicate versions' );
$GLOBALS['keel_install']['locale'] = 'en_US';

keel_install_prime_offer();
$GLOBALS['keel_install']['relaxed'] = false;
$plan                               = keel_defaults_prepare_backport_install( '6.8.8' );
keel_defaults_run_backport_install( $plan );
$call = end( $GLOBALS['keel_install']['upgrade_calls'] );
keel_install_assert( $plan['offer'] === $call[0], 'the selected raw offer reaches Core_Upgrader unchanged' );
keel_install_assert( false === $call[1]['allow_relaxed_file_ownership'], 'the relaxed ownership helper decides the exact upgrader argument' );
keel_install_assert( true === $call[1]['attempt_rollback'], 'rollback protection is enabled' );
keel_install_assert( $GLOBALS['keel_install']['skin'] instanceof Automatic_Upgrader_Skin, 'the upgrader uses the no-output automatic skin' );

$state = array( 'blockers' => array() );
keel_install_assert( '' === keel_defaults_backport_install_button( '6.8.8', 'visible', $state ), 'the button is absent when core visibly offers the patch' );
keel_install_assert( false !== strpos( keel_defaults_backport_install_button( '6.8.8', 'none', $state ), 'method="post"' ), 'the fallback action is a POST form' );

keel_defaults_store_backport_result( 7, 'download_failed', array( '<strong>Failed</strong><script>alert(1)</script>' ) );
keel_defaults_store_backport_result( 8, 'other_user', array( 'private result' ) );
$GLOBALS['keel_install']['user'] = 7;
$notice                          = keel_defaults_backport_result_markup();
keel_install_assert( false !== strpos( $notice, '<strong>Failed</strong>' ), 'allowed error markup survives' );
keel_install_assert( false === strpos( $notice, '<script>' ), 'unsafe error markup is escaped or stripped' );
keel_install_assert( false === strpos( $notice, 'private result' ), 'another user result is not exposed' );
keel_install_assert( null === keel_defaults_take_backport_result( 7 ), 'the acting user result is consumed once' );
keel_install_assert( 'other_user' === keel_defaults_take_backport_result( 8 )['code'], 'another user retains their own result' );
$result_key = keel_defaults_backport_result_key( 7 );
keel_install_assert( KEEL_DEFAULTS_BACKPORT_RESULT_TTL === $GLOBALS['keel_install']['ttl'][ $result_key ], 'result storage is short-lived' );

if ( $fail > 0 ) {
	fwrite( STDERR, "\n{$fail} assertion(s) failed.\n" );
	exit( 1 );
}

echo "backport-install: all assertions passed\n";
