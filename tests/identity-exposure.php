<?php
/**
 * Detection rules for the email-in-public-name Site Health check (#355).
 *
 * Covers rules 1-4, mixed case, core's collision suffix and its 50-character
 * truncation, near misses that must stay clean, and the label that stands in
 * for an unsafe name. Pure functions only; the live behaviour is in
 * tests/integration/identity-exposure.php.
 *
 * Run: php tests/identity-exposure.php
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
 * Core's is_email() for the shapes used here: one at-sign, a dotted domain.
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

/**
 * Classify a fixture.
 *
 * @param string $login Login.
 * @param string $email Email.
 * @param string $name  Display name.
 * @param string $slug  Nicename; empty means core's derivation from the login.
 * @return array
 */
function keel_identity_case( $login, $email, $name, $slug = '' ) {
	return keel_defaults_identity_classify(
		array(
			'ID'            => 7,
			'user_login'    => $login,
			'user_email'    => $email,
			'display_name'  => $name,
			'user_nicename' => '' === $slug ? sanitize_title( mb_substr( $login, 0, 50 ) ) : $slug,
		)
	);
}

$cases = array(
	// Clean controls.
	'email login with a chosen byline and unrelated slug'  => array( array( 'pat@fixture.test', 'pat@fixture.test', 'Pat Morgan', 'pat-morgan' ), '' ),
	'handle login with distinct public fields'             => array( array( 'riverdesk', 'ops@fixture.test', 'River Desk', 'riverdesk' ), '' ),
	'at-sign that is not an address'                       => array( array( 'studio', 'studio@fixture.test', 'Studio @ Night', 'studio-night' ), '' ),
	'address-like token without a dotted domain'           => array( array( 'crew', 'crew@fixture.test', 'Crew ops@local', 'crew-ops' ), '' ),
	'unrelated slug with a numeric suffix'                 => array( array( 'notes@fixture.test', 'notes@fixture.test', 'Field Notes', 'field-notes-2' ), '' ),
	'suffix on a stem that is not core truncation'         => array( array( 'dan@example.com', 'dan@example.com', 'Dan K', 'da-2' ), '' ),

	// Rule 1.
	'rule 1: display name is the email login'              => array( array( 'case.login@fixture.test', 'other@fixture.test', 'case.login@fixture.test', 'case-login' ), 'strong' ),
	'rule 1: mixed case'                                   => array( array( 'Case.Login@fixture.test', 'other@fixture.test', 'case.login@FIXTURE.test', 'case-login' ), 'strong' ),

	// Rule 2.
	'rule 2: name is the contact email, handle login'      => array( array( 'handle', 'Contact@fixture.test', 'contact@fixture.test', 'handle' ), 'strong' ),
	'rule 2: address embedded in a longer byline'          => array( array( 'desk', 'desk@fixture.test', 'Desk outside@fixture.test Team', 'desk' ), 'strong' ),
	'rule 2: address in angle brackets'                    => array( array( 'desk', 'desk@fixture.test', 'Desk <outside@fixture.test>', 'desk' ), 'strong' ),

	// Rule 3.
	'rule 3: core slug of a mixed-case email login'        => array( array( 'Mixed.Case@Fixture.test', 'mc@fixture.test', 'Mixed Case', '' ), 'strong' ),
	'rule 3: slug from the contact email, handle login'    => array( array( 'handle2', 'writer@fixture.test', 'Writer', 'writerfixture-test' ), 'strong' ),
	'rule 3: core collision suffix'                        => array( array( 'dan@example.com', 'dan@example.com', 'Dan K', 'danexample-com-2' ), 'strong' ),
	'rule 3: plus sign removed by core'                    => array( array( 'a+tag@fixture.test', 'a+tag@fixture.test', 'Tagged', '' ), 'strong' ),

	// Rule 4.
	'rule 4: name is the local part, different case'       => array( array( 'jdoe@fixture.test', 'jdoe@fixture.test', 'JDoe', 'j-doe-writes' ), 'weak' ),
	'rule 4: slug is the sanitised local part'             => array( array( 'weak.slug@fixture.test', 'weak.slug@fixture.test', 'Weak Slug', 'weak-slug' ), 'weak' ),
	'rule 4: local-part slug with a collision suffix'      => array( array( 'dan@example.com', 'dan@example.com', 'Dan K', 'dan-2' ), 'weak' ),
	'rule 4: WooCommerce-style handle from the local part' => array( array( 'jsmith', 'jsmith@shop.test', 'jsmith', 'jsmith' ), 'weak' ),
	'rule 4 plus a strong field counts as strong'          => array( array( 'kim@fixture.test', 'kim@fixture.test', 'kim', '' ), 'strong' ),
);

foreach ( $cases as $label => $case ) {
	list( $args, $expected ) = $case;
	$got                     = keel_identity_case( ...$args );
	keel_assert( $expected === $got['level'], "{$label}: expected '{$expected}', got '{$got['level']}'." );
}

// 50-character truncation, with and without core's collision suffix.
$long = str_repeat( 'long.', 8 ) . 'writer@fixture.test';
$base = sanitize_title( mb_substr( $long, 0, 50 ) );
keel_assert( strlen( $base ) <= 50, 'Fixture base slug fits the column.' );
keel_assert( 'strong' === keel_identity_case( $long, 'lw@fixture.test', 'Long Writer', $base )['level'], 'Truncated core slug of a long email login is strong.' );
keel_assert( 'strong' === keel_identity_case( $long, 'lw@fixture.test', 'Long Writer', mb_substr( $base, 0, 47 ) . '-10' )['level'], 'Truncated -10 collision variant is strong.' );
keel_assert( '' === keel_identity_case( $long, 'lw@fixture.test', 'Long Writer', mb_substr( $base, 0, 45 ) . '-10' )['level'], 'A -10 suffix on the wrong stem length is not core output.' );
keel_assert( false === keel_defaults_identity_slug_from( 'danexample-com-1', 'dan@example.com' ), 'Core never emits a -1 suffix.' );

// Which fields matched.
$both = keel_identity_case( 'both@fixture.test', 'both@fixture.test', 'both@fixture.test', '' );
keel_assert( $both['name'] && $both['slug'], 'Name and slug both flagged.' );
$slug_only = keel_identity_case( 'slug@fixture.test', 'slug@fixture.test', 'Safe Byline', '' );
keel_assert( ! $slug_only['name'] && $slug_only['slug'], 'Slug-only exposure leaves the name unflagged.' );

// Labels never carry an unsafe name.
$row = array(
	'ID'            => 42,
	'display_name'  => 'Desk outside@fixture.test Team',
	'user_login'    => 'desk',
	'user_email'    => 'desk@fixture.test',
	'user_nicename' => 'desk',
);
keel_assert( 'User #42' === keel_defaults_identity_safe_label( $row, keel_defaults_identity_classify( $row ) ), 'Unsafe byline is replaced by the user ID.' );
$row['display_name'] = 'JDesk';
$row['user_email']   = 'jdesk@fixture.test';
keel_assert( 'User #42' === keel_defaults_identity_safe_label( $row, keel_defaults_identity_classify( $row ) ), 'A weak-match name is not echoed either.' );
$row['display_name'] = 'Front Desk';
keel_assert( 'Front Desk' === keel_defaults_identity_safe_label( $row, keel_defaults_identity_classify( $row ) ), 'A safe byline is shown.' );
$row['display_name'] = 'Studio @ Night';
keel_assert( 'User #42' === keel_defaults_identity_safe_label( $row, keel_defaults_identity_classify( $row ) ), 'Any at-sign in a label falls back to the ID.' );

// Role priority.
keel_assert( 'administrator' === keel_defaults_identity_primary_role( array( 'author', 'administrator' ) ), 'Most privileged role wins.' );
keel_assert( 'shop_manager' === keel_defaults_identity_primary_role( array( 'shop_manager' ) ), 'Custom roles are kept.' );
keel_assert( '' === keel_defaults_identity_primary_role( array() ), 'No role is empty.' );

if ( $fail ) {
	fwrite( STDERR, "{$fail} identity exposure assertion(s) failed.\n" );
	exit( 1 );
}
echo 'Identity exposure: ' . ( count( $cases ) + 13 ) . " detection checks passed.\n";
