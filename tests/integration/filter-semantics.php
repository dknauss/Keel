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

if ( $fail ) {
	exit( 1 );
}

echo "filter semantics: OK\n";
