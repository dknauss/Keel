<?php
/**
 * Real-WordPress guard for final-value filter execution semantics.
 *
 * Run through WP-CLI, never directly:
 * wp eval-file tests/integration/filter-semantics.php
 */

$fail   = 0;
$events = array();

$assert = static function ( $condition, $message ) use ( &$fail ) {
	if ( ! $condition ) {
		++$fail;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
};

$mail_first  = static function ( $value ) use ( &$events ) {
	$events[] = 'mail-first';
	return true;
};
$mail_second = static function ( $value ) use ( &$events ) {
	$events[] = 'mail-second';
	return $value;
};
add_filter( 'pre_wp_mail', $mail_first, PHP_INT_MIN );
add_filter( 'pre_wp_mail', $mail_second, PHP_INT_MIN + 1 );
apply_filters( 'pre_wp_mail', null, array( 'to' => 'nobody@example.invalid' ) );
$assert( array( 'mail-first', 'mail-second' ) === $events, 'Every pre_wp_mail callback runs after the first non-null result, in priority order.' );
remove_filter( 'pre_wp_mail', $mail_first, PHP_INT_MIN );
remove_filter( 'pre_wp_mail', $mail_second, PHP_INT_MIN + 1 );

$events         = array();
$comment_first  = static function ( $value ) use ( &$events ) {
	$events[] = 'comment-first';
	return array();
};
$comment_second = static function ( $value ) use ( &$events ) {
	$events[] = 'comment-second';
	return $value;
};
add_filter( 'comments_pre_query', $comment_first, PHP_INT_MIN );
add_filter( 'comments_pre_query', $comment_second, PHP_INT_MIN + 1 );
apply_filters( 'comments_pre_query', null, new WP_Comment_Query() );
$assert( array( 'comment-first', 'comment-second' ) === $events, 'Every comments_pre_query callback runs after the first non-null result, in priority order.' );
remove_filter( 'comments_pre_query', $comment_first, PHP_INT_MIN );
remove_filter( 'comments_pre_query', $comment_second, PHP_INT_MIN + 1 );

/* A filtered WP_Hook clone must keep its protected priority index in sync. */
$clone_hook = 'keel_test_clone_priority_index';
$early      = static function ( $value ) {
	return $value + 1;
};
$late       = static function ( $value ) {
	return $value + 10;
};
add_filter( $clone_hook, $early, 10 );
add_filter( $clone_hook, $late, PHP_INT_MAX );

$probe = keel_defaults_run_policy_probe(
	$clone_hook,
	$GLOBALS['wp_filter'][ $clone_hook ],
	static function ( $callback, $priority ) {
		unset( $callback );
		return PHP_INT_MAX === $priority;
	},
	array(
		'value' => 0,
		'args'  => array(),
	)
);

$assert( $probe['ok'] && 10 === $probe['value'], 'Policy probes keep WP_Hook callbacks and its protected priority index synchronized.' );
remove_all_filters( $clone_hook );

if ( $fail ) {
	exit( 1 );
}

echo "filter semantics: OK\n";
