<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress JSON test stub.
/** Bounded HIBP policy and state regression. @package Keel */
define( 'ABSPATH', __DIR__ );
$GLOBALS['keel_budget_policy'] = null;
function apply_filters( $name, $value ) { return null === $GLOBALS['keel_budget_policy'] ? $value : $GLOBALS['keel_budget_policy']; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function budget_assert( $ok, $message ) { if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 ); } }
$module = dirname( __DIR__ ) . '/includes/password-hibp-budget.php';
budget_assert( file_exists( $module ), 'The bounded budget module exists.' );
require $module;
$p = keel_hibp_budget_policy();
budget_assert(
	array(
		'capacity' => 10,
		'interval' => 60,
	) === $p,
	'Default burst and refill.'
);
foreach ( array(
	array(),
	array(
		'capacity' => 0,
		'interval' => 60,
	),
	array(
		'capacity' => 10,
		'interval' => '60',
	),
	array(
		'capacity' => 101,
		'interval' => 60,
	),
) as $invalid ) {
	$GLOBALS['keel_budget_policy'] = $invalid;
	budget_assert( keel_hibp_budget_policy() === $p, 'Invalid policy is replaced as a whole.' );
}
$GLOBALS['keel_budget_policy'] = null;
$s                             = keel_hibp_budget_initial( $p, 1000 );
budget_assert( 600 === $s['credit'] && array() === $s['leases'], 'Initial full bucket has no leases.' );
$s['credit'] = 0;
$t           = keel_hibp_budget_refill( $s, $p, 1059 );
budget_assert( 59 === $t['credit'], 'Fractional refill is preserved.' );
$t = keel_hibp_budget_refill( $t, $p, 1060 );
budget_assert( 60 === $t['credit'], 'Exactly one credit at 60 seconds.' );
$t = keel_hibp_budget_refill( $t, $p, 900 );
budget_assert( 60 === $t['credit'] && 1060 === $t['last'], 'Clock rollback mints nothing and retains high-water mark.' );
$t = keel_hibp_budget_refill( $s, $p, 100000 );
budget_assert( 600 === $t['credit'], 'Idle refill is capped.' );
$s['credit'] = 179;
$t           = keel_hibp_budget_refill(
	$s,
	array(
		'capacity' => 1,
		'interval' => 120,
	),
	1000
);
budget_assert( 120 === $t['credit'], 'Policy changes conserve whole credits and clamp capacity.' );
$raw = json_encode( keel_hibp_budget_initial( $p, 1000 ) );
budget_assert( false !== keel_hibp_budget_state( $raw, 1000 ), 'Fresh state validates.' );
foreach ( array( '{}', '{', str_repeat( 'x', 4097 ), json_encode( array_merge( json_decode( $raw, true ), array( 'credit' => 601 ) ) ), json_encode( array_merge( json_decode( $raw, true ), array( 'last' => 1400 ) ) ) ) as $bad ) {
	budget_assert( false === keel_hibp_budget_state( $bad, 1000 ), 'Corrupt/oversized/future state is not a fresh bucket.' );
}

$s           = keel_hibp_budget_initial( $p, 1000 );
$s['credit'] = 59;
$t           = keel_hibp_budget_refill(
	$s,
	array(
		'capacity' => 11,
		'interval' => 60,
	),
	1000
);
budget_assert( 59 === $t['credit'], 'Capacity-only changes preserve fractional credit.' );

// MySQL metadata discovery must also fail conservatively and restore DB state.
$GLOBALS['wpdb'] = new class() {
	public $suppressed = false;
	public function suppress_errors( $value ) { $old = $this->suppressed;
		$this->suppressed                            = $value;
		return $old; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql ) { throw new RuntimeException( 'Synthetic metadata failure' ); }
};
budget_assert(
	false === keel_hibp_budget_transactional(
		array(
			'table' => 'wp_options',
			'id'    => 0,
		)
	),
	'A throwing metadata check denies fresh work.'
);
budget_assert( false === $GLOBALS['wpdb']->suppressed, 'Metadata failure restores error suppression.' );
$GLOBALS['wpdb']->suppressed = true;
budget_assert(
	false === keel_hibp_budget_transactional(
		array(
			'table' => 'wp_options',
			'id'    => 0,
		)
	),
	'Repeated metadata failure remains unknown.'
);
budget_assert( true === $GLOBALS['wpdb']->suppressed, 'Metadata failure preserves existing suppression.' );

echo "HIBP budget: policy/refill/schema safeguards passed.\n";
