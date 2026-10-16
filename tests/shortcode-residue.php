<?php
/** Shortcode residue parser and bounded observation contracts. @package Keel */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['shortcode_tags'] = array(
	'live'    => 'fixture',
	'ns:live' => 'fixture',
	'123'     => 'fixture',
);
$GLOBALS['records']        = array();
$GLOBALS['writes']         = 0;
$GLOBALS['reads']          = 0;
$GLOBALS['confirmed']      = array();
function shortcode_exists( $tag ) { return isset( $GLOBALS['shortcode_tags'][ $tag ] ); }
function apply_filters( $hook, $value ) { return 'keel_shortcode_residue_tags' === $hook ? $GLOBALS['confirmed'] : $value; }
// Core's six capture groups, including its escaping and enclosing-tag rules.
function get_shortcode_regex( $tags = null ) {
	$names = implode( '|', array_map( 'preg_quote', $tags ) );
	return '\\[(\\[?)(' . $names . ')(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}
function get_transient( $key ) { ++$GLOBALS['reads'];
	return $GLOBALS['records'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { ++$GLOBALS['writes'];
	$GLOBALS['records'][ $key ] = $value;
	$GLOBALS['ttl']             = $ttl; }
require dirname( __DIR__ ) . '/includes/shortcodes.php';
function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 ); }
}
$cases = array(
	'plain prose'                                     => 'plain prose',
	'[sic] [1] [editorial insertion] [unknown]'       => '[sic] [1] [editorial insertion] [unknown]',
	'[lost /]'                                        => '',
	'[lost id="12"]'                                  => '',
	'[lost]Keep <b>this</b> text[/lost]'              => 'Keep <b>this</b> text',
	'[[lost id="12"]] [[lost]]'                       => '[[lost id="12"]] [[lost]]',
	'<pre>[lost /]</pre><code>[lost /]</code>'        => '<pre>[lost /]</pre><code>[lost /]</code>',
	'<script>let x="[lost /]";</script><style>[lost /]</style><textarea>[lost /]</textarea>' => '<script>let x="[lost /]";</script><style>[lost /]</style><textarea>[lost /]</textarea>',
	'<!-- [lost /] --><a title="[lost /]">link</a>'   => '<!-- [lost /] --><a title="[lost /]">link</a>',
	'[ns:live][lost /][/ns:live] [123][lost /][/123]' => '[ns:live][lost /][/ns:live] [123][lost /][/123]',
	'[live id="1"] [live][lost /][/live]'             => '[live id="1"] [live][lost /][/live]',
	'[outer][inner /]Visible[/outer]'                 => 'Visible',
	'[lost https://example.test]'                     => '[lost https://example.test]',
);
foreach ( $cases as $input => $expected ) {
	$tags   = array();
	$actual = keel_defaults_strip_shortcode_residue( $input, $tags );
	check( $expected === $actual, "Parser: $input => $actual" );
	check( ( $input !== $expected ) === ( count( $tags ) > 0 ), 'Only changed output supplies diagnostic evidence.' );
}
$GLOBALS['confirmed'] = array( 'lost' );
$tags                 = array();
check( '' === keel_defaults_strip_shortcode_residue( '[lost]', $tags ), 'Explicitly confirmed bare tag is hidden.' );
$GLOBALS['confirmed'] = array();
check( 0 === $GLOBALS['reads'] && 0 === $GLOBALS['writes'], 'Pure parsing touches no storage.' );
keel_defaults_record_shortcode_residue( 7, array( 'lost' ) );
keel_defaults_record_shortcode_residue( 7, array( 'lost' ) );
check( 1 === $GLOBALS['writes'], 'Unchanged answer writes only once.' );
check( HOUR_IN_SECONDS === $GLOBALS['ttl'], 'Observation expires naturally.' );
for ( $id = 8; $id < 40; ++$id ) {
	keel_defaults_record_shortcode_residue( $id, array( 'lost' ) ); }
check( 20 === count( keel_defaults_shortcode_observations() ), 'Post storage is capped.' );
keel_defaults_record_shortcode_residue( 7, array_fill( 0, 50, 'lost' ) );
check( 1 === count( keel_defaults_shortcode_observations()[7]['tags'] ), 'Tags are deduplicated.' );
$GLOBALS['records']['keel_shortcode_residue'] = array(
	99 => array(
		'tags' => array( 'gone' ),
		'seen' => time() - HOUR_IN_SECONDS - 1,
	),
);
check( array() === keel_defaults_shortcode_observations(), 'Each entry expires independently.' );
$GLOBALS['records']['keel_shortcode_residue'] = array(
	99    => array(
		'tags' => 'bad',
		'seen' => time(),
	),
	'bad' => null,
);
check( array() === keel_defaults_shortcode_observations(), 'Malformed cached evidence is ignored.' );
echo "shortcode residue: OK\n";

$tags  = array();
$large = str_repeat( '[lost /]', 101 );
check( 0 === strcmp( $large, keel_defaults_strip_shortcode_residue( $large, $tags ) ) && ! $tags, 'Over-budget candidate counts fail open without evidence.' );
$tags       = array();
$empty_pair = '[lost][/lost]';
check( '' === keel_defaults_strip_shortcode_residue( $empty_pair, $tags ), 'Empty pairs count as strong syntax.' );
$tags    = array();
$literal = "\x1Akeel0\x1A [lost /]";
check( "\x1Akeel0\x1A " === keel_defaults_strip_shortcode_residue( $literal, $tags ), 'Literal token-shaped text cannot collide with masking.' );
$GLOBALS['records'] = array();
$many_tags          = array();
for ( $i = 0; $i < 15; ++$i ) {
	$many_tags[] = 'tag_' . $i; }
keel_defaults_record_shortcode_residue( 7, $many_tags );
check( 10 === count( keel_defaults_shortcode_observations()[7]['tags'] ), 'Per-post tag evidence is capped.' );

class WP_Post {
	public $ID          = 7;
	public $post_status = 'publish';
}
$GLOBALS['fixture_post']      = new WP_Post();
$GLOBALS['can_edit']          = false;
$GLOBALS['admin']             = false;
$GLOBALS['password_required'] = false;
$GLOBALS['enabled']           = true;
function is_admin() { return $GLOBALS['admin']; }
function get_post( $id = null ) { return $GLOBALS['fixture_post']; }
function post_password_required( $post ) { return $GLOBALS['password_required']; }
function current_user_can( $cap, $id ) { check( 'edit_post' === $cap && 7 === $id, 'Uses per-object edit permission.' );
	return $GLOBALS['can_edit']; }
function keel_defaults_enabled( $key ) { return $GLOBALS['enabled']; }
function __( $s, $domain = null ) { return $s; }
function esc_html__( $s, $domain = null ) { return esc_html( $s ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function get_edit_post_link( $id, $context ) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
$GLOBALS['records'] = array();
$GLOBALS['reads']   = 0;
check( 'Prose [sic]' === keel_defaults_hide_shortcode_residue( 'Prose [sic]' ) && 0 === $GLOBALS['reads'], 'Healthy wrapper reads no diagnostic storage.' );
check( '' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'Reader wrapper hides residue.' );
$GLOBALS['can_edit'] = true;
check( '[lost /]' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'Editor wrapper keeps residue.' );
$report = keel_defaults_site_health_shortcodes();
check( 'recommended' === $report['status'] && false !== strpos( $report['description'], 'post=7' ) && false !== strpos( $report['description'], 'lost' ), 'Report links affected post and names missing tag.' );
$GLOBALS['can_edit'] = false;
check( 'good' === keel_defaults_site_health_shortcodes()['status'], 'Inaccessible post evidence is not disclosed.' );
$GLOBALS['enabled'] = false;
check( false !== strpos( keel_defaults_site_health_shortcodes()['label'], 'disabled' ), 'Disabled policy does not present old observations as active cleanup.' );
$GLOBALS['admin'] = true;
check( '[lost /]' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'Admin rendering is untouched.' );
$GLOBALS['admin']             = false;
$GLOBALS['password_required'] = true;
check( '[lost /]' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'Locked password content is untouched.' );
$GLOBALS['password_required'] = false;
$GLOBALS['fixture_post']      = null;
check( '[lost /]' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'Unattributable content is untouched.' );
define( 'REST_REQUEST', true );
check( '[lost /]' === keel_defaults_hide_shortcode_residue( '[lost /]' ), 'REST rendering is untouched.' );
echo "shortcode rendering and diagnostics: OK\n";
