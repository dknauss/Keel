<?php
/**
 * Pure rules behind Public Names enforcement (#355, Enforced tier): what counts
 * as safe, the fallback order, and how replacement slugs are chosen. The live
 * write paths and the cleanup are in tests/integration/public-names.php.
 *
 * Run: php tests/public-names.php
 *
 * @package keel
 */

define( 'ABSPATH', __DIR__ . '/' );

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

/** Stub. */
function add_filter() {}
/** Stub. */
function add_action() {}
/**
 * Stub.
 *
 * @param string $s Text.
 * @return string
 */
function __( $s ) {
	return $s;
}
/**
 * Stub: core's "first last" format, untranslated.
 *
 * @param string $s Text.
 * @return string
 */
function translate_with_gettext_context( $s ) {
	return $s;
}

/**
 * Core's is_email() for the shapes used here.
 *
 * @param string $email Candidate.
 * @return string|false
 */
function is_email( $email ) {
	if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
		return false;
	}
	list( $local, $domain ) = explode( '@', $email, 2 );
	if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) || false === strpos( $domain, '.' ) || preg_match( '/\.{2,}/', $domain ) ) {
		return false;
	}
	return $email;
}

/**
 * Core's sanitize_title_with_dashes() for ASCII input.
 *
 * @param string $title Raw.
 * @return string
 */
function sanitize_title( $title ) {
	$title = strtolower( preg_replace( '/<[^>]*>/', '', (string) $title ) );
	$title = str_replace( '.', '-', $title );
	$title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = preg_replace( '/\s+/', '-', $title );
	$title = preg_replace( '|-+|', '-', $title );
	return trim( $title, '-' );
}

require dirname( __DIR__ ) . '/includes/identity-exposure.php';
require dirname( __DIR__ ) . '/includes/public-names.php';

$checks = 0;
$check  = static function ( $cond, $msg ) use ( &$checks ) {
	++$checks;
	keel_assert( $cond, $msg );
};

// Names.
$login = 'Pat.Morgan@Fixture.test';
$email = 'contact@else.test';
foreach ( array( 'pat.morgan@fixture.test', 'PAT.MORGAN', 'contact', 'Contact@Else.Test', 'Desk x@y.test Team', '', '   ' ) as $unsafe ) {
	$check( ! keel_defaults_public_name_is_safe( $unsafe, $login, $email ), "Unsafe name: '{$unsafe}'." );
}
foreach ( array( 'Pat Morgan', 'Studio @ Night', 'Crew ops@local', 'Pat' ) as $safe ) {
	$check( keel_defaults_public_name_is_safe( $safe, $login, $email ), "Safe name: '{$safe}'." );
}
$check( ! keel_defaults_public_name_is_safe( 'riverdesk', 'riverdesk', 'ops@else.test' ), 'A handle login is a credential too.' );
$check( ! keel_defaults_public_name_is_safe( 'JSmith', 'jsmith', 'jsmith@shop.test' ), 'Local part in another case is unsafe.' );

// Slugs.
$check( ! keel_defaults_public_slug_is_safe( 'pat-morganfixture-test', $login, $email ), 'Core slug of the email login is unsafe.' );
$check( ! keel_defaults_public_slug_is_safe( 'pat-morganfixture-test-3', $login, $email ), 'Its collision variant is unsafe.' );
$check( ! keel_defaults_public_slug_is_safe( 'contactelse-test', $login, $email ), 'Slug of the contact email is unsafe.' );
$check( ! keel_defaults_public_slug_is_safe( 'pat-morgan', $login, $email ), 'Slug of the local part is unsafe.' );
$check( ! keel_defaults_public_slug_is_safe( '', $login, $email ), 'An empty slug is unsafe.' );
$check( keel_defaults_public_slug_is_safe( 'pat-morgan-writes', $login, $email ), 'A different slug is safe.' );
$check( keel_defaults_public_slug_is_safe( 'field-notes-2', $login, $email ), 'An unrelated numbered slug is safe.' );

// Fallback order.
$base = array(
	'user_login'   => $login,
	'user_email'   => $email,
	'display_name' => $login,
	'nickname'     => $login,
	'first_name'   => '',
	'last_name'    => '',
);
$check( 'Wren Ashby' === keel_defaults_public_names_display( array_merge( $base, array( 'display_name' => 'Wren Ashby' ) ) ), 'A safe display name is kept.' );
$check(
	'Nick' === keel_defaults_public_names_display(
		array_merge(
			$base,
			array(
				'nickname'   => 'Nick',
				'first_name' => 'Fern',
				'last_name'  => 'Gale',
			)
		)
	),
	'Nickname first.'
);
$check(
	'Fern Gale' === keel_defaults_public_names_display(
		array_merge(
			$base,
			array(
				'first_name' => 'Fern',
				'last_name'  => 'Gale',
			)
		)
	),
	'Then first and last.'
);
$check(
	'Gale' === keel_defaults_public_names_display(
		array_merge(
			$base,
			array(
				'first_name' => 'x@y.test',
				'last_name'  => 'Gale',
			)
		)
	),
	'Then whichever single name is safe.'
);
$check( 'Fern' === keel_defaults_public_names_display( array_merge( $base, array( 'first_name' => 'Fern' ) ) ), 'First name alone.' );
$check( 'Old Safe' === keel_defaults_public_names_display( array_merge( $base, array( 'previous' => 'Old Safe' ) ) ), 'Then the name the account already had.' );
$handle = keel_defaults_public_names_display( $base );
$check( (bool) preg_match( '/^Member [0-9a-f]{6}$/', $handle ), "Then a neutral handle, got '{$handle}'." );
$check( keel_defaults_public_name_is_safe( $handle, $login, $email ), 'The handle is safe.' );

// Replacement slugs.
$check( 'wren-ashby' === keel_defaults_public_names_slug( 'pat-morganfixture-test', 'Wren Ashby', $login, $email ), 'Slug follows a safe byline.' );
$check( 'kept-slug' === keel_defaults_public_names_slug( 'kept-slug', 'Wren Ashby', $login, $email ), 'A safe slug is kept.' );
$token = keel_defaults_public_names_slug( 'river-desk', 'River Desk', 'river.desk', 'rd@else.test' );
$check( (bool) preg_match( '/^[0-9a-f]{10}$/', $token ), "Byline whose slug is the login's gives an opaque token, got '{$token}'." );
$long = keel_defaults_public_names_slug( 'pat-morganfixture-test', str_repeat( 'Bartholomew ', 6 ), $login, $email );
$check( strlen( $long ) <= 50 && '-' !== substr( $long, -1 ), 'A long byline slug fits the column without a trailing hyphen.' );

// The cleanup only touches what is unsafe.
$check(
	array() === keel_defaults_public_names_fix(
		array(
			'ID'            => 3,
			'user_login'    => 'wren',
			'user_email'    => 'w@else.test',
			'display_name'  => 'Wren Ashby',
			'nickname'      => 'Wren A',
			'first_name'    => '',
			'last_name'     => '',
			'user_nicename' => 'wren-ashby',
		)
	),
	'A safe account needs no change.'
);

if ( $fail ) {
	fwrite( STDERR, "{$fail} public names assertion(s) failed.\n" );
	exit( 1 );
}
echo "Public names: {$checks} rule checks passed.\n";
