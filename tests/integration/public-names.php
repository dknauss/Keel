<?php
/**
 * Public Names enforcement and cleanup (#355, Enforced tier) on a disposable
 * WordPress install.
 *
 * Real users through wp_insert_user(), wp_update_user(), register_new_user()
 * and the cleanup's own batch function, so core derives names, slugs and
 * collision suffixes exactly as on a live site. Covers the tester probes in
 * docs/review/probes/355-email-exposure-enforced.md that need no browser or
 * HTTP client; those run in the lab. Each case deletes its fixtures.
 *
 * Run: wp eval-file tests/integration/public-names.php
 *
 * @package Keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_public_names_policy' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$keel_pn_run     = (string) wp_rand( 100000, 999999 );
$keel_pn_users   = array();
$keel_pn_posts   = array();
$keel_pn_results = array();
$keel_pn_admin   = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 1 );
if ( is_multisite() ) {
	$keel_pn_admin = (int) get_user_by( 'login', get_super_admins()[0] )->ID;
}

// No mail leaves the lab.
add_filter( 'pre_wp_mail', '__return_true' );

$keel_pn_assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

/**
 * Turn enforcement on or off where it is decided: the main site's settings.
 *
 * @param string $value yes|no.
 */
$keel_pn_set = static function ( $value ) {
	$switch = is_multisite() && ! is_main_site();
	if ( $switch ) {
		switch_to_blog( get_main_site_id() );
	}
	$opt                         = get_option( KEEL_DEFAULTS_OPTION, array() );
	$opt                         = is_array( $opt ) ? $opt : array();
	$opt['protect_public_names'] = $value;
	update_option( KEEL_DEFAULTS_OPTION, $opt );
	if ( $switch ) {
		restore_current_blog();
	}
};

$keel_pn_user = static function ( array $data ) use ( &$keel_pn_users ) {
	$data += array(
		'user_pass' => wp_generate_password( 24 ),
		'role'      => 'author',
	);
	$id    = wp_insert_user( $data );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture user: ' . $id->get_error_code() );
	}
	$keel_pn_users[] = $id;
	return $id;
};

$keel_pn_row = static function ( $id ) {
	global $wpdb;
	clean_user_cache( $id );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh read of the committed row.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, user_login, user_email, user_pass, display_name, user_nicename FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh read of the committed meta.
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN ('nickname','first_name','last_name')", $id ), OBJECT_K );
	foreach ( array( 'nickname', 'first_name', 'last_name' ) as $key ) {
		$row[ $key ] = isset( $meta[ $key ] ) ? $meta[ $key ]->meta_value : '';
	}
	return $row;
};

$keel_pn_safe = static function ( array $row ) {
	return keel_defaults_public_name_is_safe( $row['display_name'], $row['user_login'], $row['user_email'] )
		&& keel_defaults_public_name_is_safe( $row['nickname'], $row['user_login'], $row['user_email'] )
		&& keel_defaults_public_slug_is_safe( $row['user_nicename'], $row['user_login'], $row['user_email'] );
};

$keel_pn_cleanup = static function () use ( &$keel_pn_users, &$keel_pn_posts ) {
	foreach ( $keel_pn_posts as $post ) {
		wp_delete_post( $post, true );
	}
	$keel_pn_posts = array();
	foreach ( $keel_pn_users as $id ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $id );
		} else {
			wp_delete_user( $id );
		}
	}
	$keel_pn_users = array();
	delete_site_option( KEEL_PUBLIC_NAMES_RUN );
	delete_option( KEEL_PUBLIC_NAMES_RUN );
};

/**
 * Run the cleanup to completion as the admin, the way the screen does.
 *
 * @param int  $target   Site.
 * @param bool $comments Rewrite comments.
 * @return array Final progress.
 */
$keel_pn_cleanup_run = static function ( $target, $comments ) use ( $keel_pn_admin ) {
	delete_site_option( KEEL_PUBLIC_NAMES_RUN );
	delete_option( KEEL_PUBLIC_NAMES_RUN );
	$previous = get_current_user_id();
	wp_set_current_user( $keel_pn_admin );
	$_POST = array(
		'site'     => (string) $target,
		'comments' => $comments ? '1' : '',
	);
	$state = keel_defaults_public_names_step( 'start' );
	$guard = 0;
	while ( $state && empty( $state['done'] ) && ++$guard < 1000 ) {
		$state = keel_defaults_public_names_step( 'continue' );
	}
	$_POST = array();
	wp_set_current_user( $previous );
	return $state;
};

$keel_pn_case = static function ( $id, callable $body ) use ( &$keel_pn_results, $keel_pn_cleanup, $keel_pn_set ) {
	try {
		$body();
		$keel_pn_results[] = "PASS {$id}";
	} finally {
		$keel_pn_cleanup();
		$keel_pn_set( 'no' );
	}
};

$keel_pn_set( 'no' );

try {
	// P1: off is the default, and off means core behaviour on every API path.
	$keel_pn_case(
		'P1',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run ) {
			$schema = keel_defaults_schema();
			$keel_pn_assert( 'no' === $schema['protect_public_names']['default'], 'P1: shipped default is off.' );
			$keel_pn_assert( 'no' === keel_defaults_public_names_policy(), 'P1: effective policy is off.' );
			$login = "off.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
				)
			);
			$row   = $keel_pn_row( $id );
			$keel_pn_assert( $login === $row['display_name'] && $login === $row['nickname'] && sanitize_title( $login ) === $row['user_nicename'], 'P1: create follows core.' );
			wp_update_user(
				array(
					'ID'         => $id,
					'first_name' => 'Off',
				)
			);
			$after = $keel_pn_row( $id );
			$keel_pn_assert( $after['display_name'] === $login && $after['user_nicename'] === $row['user_nicename'] && 'Off' === $after['first_name'], 'P1: update follows core.' );
			$reg = register_new_user( "offreg{$keel_pn_run}", "offreg.{$keel_pn_run}@fixture.test" );
			$keel_pn_assert( is_int( $reg ), 'P1: registration accepted.' );
			$GLOBALS['keel_pn_users'][] = $reg;
			$keel_pn_assert( "offreg{$keel_pn_run}" === $keel_pn_row( $reg )['display_name'], 'P1: registration follows core.' );
		}
	);

	// P2: activation, saving the setting, viewing the screen and cron never remediate.
	$keel_pn_case(
		'P2',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_admin ) {
			$login                      = "seed.{$keel_pn_run}@fixture.test";
			$id                         = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
				)
			);
			$post                       = wp_insert_post(
				array(
					'post_title'  => 'P2',
					'post_status' => 'publish',
				)
			);
			$GLOBALS['keel_pn_posts'][] = $post;
			$comment                    = wp_insert_comment(
				array(
					'comment_post_ID' => $post,
					'user_id'         => $id,
					'comment_author'  => $login,
					'comment_content' => 'seed',
				)
			);
			$before                     = array( $keel_pn_row( $id ), get_comment( $comment )->comment_author );

			do_action( 'activate_' . plugin_basename( KEEL_DEFAULTS_FILE ) );
			$keel_pn_set( 'yes' );
			$previous = get_current_user_id();
			wp_set_current_user( $keel_pn_admin );
			ob_start();
			keel_defaults_public_names_render();
			ob_end_clean();
			wp_set_current_user( $previous );
			foreach ( (array) _get_cron_array() as $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					if ( 0 === strpos( $hook, 'keel' ) ) {
						foreach ( $events as $event ) {
							do_action_ref_array( $hook, $event['args'] );
						}
					}
				}
			}
			clean_comment_cache( $comment );
			$after = array( $keel_pn_row( $id ), get_comment( $comment )->comment_author );
			$keel_pn_assert( $before === $after, 'P2: no event wrote to identity or comment rows.' );
			$keel_pn_assert( null === keel_defaults_public_names_state(), 'P2: no run was started or queued.' );
		}
	);

	// P3: self-registration with an email username.
	$keel_pn_case(
		'P3',
		function () use ( $keel_pn_assert, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$keel_pn_set( 'yes' );
			$login = "reg.{$keel_pn_run}@fixture.test";
			$id    = register_new_user( $login, $login );
			$keel_pn_assert( is_int( $id ), 'P3: registration succeeds.' );
			$GLOBALS['keel_pn_users'][] = $id;
			$row                        = $keel_pn_row( $id );
			$keel_pn_assert( $row['user_login'] === $login && $row['user_email'] === $login, 'P3: login and email stored as supplied.' );
			$keel_pn_assert( $keel_pn_safe( $row ), 'P3: public fields are safe.' );
		}
	);

	// P4: Add New with safe names and no slug.
	$keel_pn_case(
		'P4',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$keel_pn_set( 'yes' );
			$login = "addnew.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
					'first_name' => 'Avery',
					'last_name'  => 'Quill',
				)
			);
			$row   = $keel_pn_row( $id );
			$keel_pn_assert( 'Avery Quill' === $row['display_name'], 'P4: full byline kept.' );
			$keel_pn_assert( $keel_pn_safe( $row ) && false === strpos( $row['nickname'], '@' ), 'P4: slug and nickname safe.' );
		}
	);

	// P6: insert, the existing-ID insert path, and a partial wp_update_user().
	$keel_pn_case(
		'P6',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$login = "old.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
					'last_name'  => 'Keeps',
				)
			);
			$keel_pn_assert( ! $keel_pn_safe( $keel_pn_row( $id ) ), 'P6: fixture starts exposed.' );
			$keel_pn_set( 'yes' );
			$result = wp_update_user(
				array(
					'ID'         => $id,
					'first_name' => 'Only',
				)
			);
			$row    = $keel_pn_row( $id );
			$keel_pn_assert( $result === $id && 'Only' === $row['first_name'] && 'Keeps' === $row['last_name'], 'P6: requested edit persists, omitted field kept.' );
			$keel_pn_assert( $keel_pn_safe( $row ), 'P6: omitted unsafe fields corrected.' );
			$slug = $row['user_nicename'];

			$result = wp_insert_user(
				array(
					'ID'           => $id,
					'user_login'   => $login,
					'user_email'   => $login,
					'display_name' => $login,
					'user_pass'    => '',
				)
			);
			$row    = $keel_pn_row( $id );
			$keel_pn_assert( $result === $id && $keel_pn_safe( $row ) && $slug === $row['user_nicename'], 'P6: existing-ID insert path corrected, safe slug kept.' );

			$fresh                      = wp_insert_user(
				array(
					'user_login' => "new.{$keel_pn_run}@fixture.test",
					'user_email' => "new.{$keel_pn_run}@fixture.test",
					'user_pass'  => wp_generate_password(),
				)
			);
			$GLOBALS['keel_pn_users'][] = $fresh;
			$keel_pn_assert( is_int( $fresh ) && $keel_pn_safe( $keel_pn_row( $fresh ) ), 'P6: direct insert safe.' );
		}
	);

	// P9: WooCommerce-style handle from the local part, with a collision suffix.
	$keel_pn_case(
		'P9',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$handle = "jsmith{$keel_pn_run}";
			$keel_pn_user(
				array(
					'user_login'    => "reserver{$keel_pn_run}",
					'user_email'    => "reserver.{$keel_pn_run}@fixture.test",
					'display_name'  => 'Reserver',
					'user_nicename' => $handle,
				)
			);
			$keel_pn_set( 'yes' );
			$id  = $keel_pn_user(
				array(
					'user_login'   => $handle,
					'user_email'   => "{$handle}@shop.test",
					'display_name' => strtoupper( $handle ),
					'nickname'     => $handle,
					'role'         => is_multisite() ? 'subscriber' : ( get_role( 'customer' ) ? 'customer' : 'subscriber' ),
				)
			);
			$row = $keel_pn_row( $id );
			$keel_pn_assert( $handle === $row['user_login'] && "{$handle}@shop.test" === $row['user_email'], 'P9: login and email kept.' );
			$keel_pn_assert( $keel_pn_safe( $row ) && 0 !== strpos( $row['user_nicename'], $handle ), 'P9: local-part fields replaced, including the collision slug.' );
		}
	);

	// P10: chosen safe values survive; only the unsafe field changes.
	$keel_pn_case(
		'P10',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set ) {
			$keel_pn_set( 'yes' );
			$login = "pen.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login'    => $login,
					'user_email'    => $login,
					'display_name'  => 'Wren Ashby',
					'nickname'      => 'Wren',
					'user_nicename' => "k{$keel_pn_run}x",
				)
			);
			$row   = $keel_pn_row( $id );
			$keel_pn_assert( 'Wren Ashby' === $row['display_name'] && 'Wren' === $row['nickname'] && "k{$keel_pn_run}x" === $row['user_nicename'], 'P10: chosen values accepted.' );
			wp_update_user(
				array(
					'ID'          => $id,
					'description' => 'unrelated',
				)
			);
			$keel_pn_assert( array_intersect_key( $row, array_flip( array( 'display_name', 'nickname', 'user_nicename' ) ) ) === array_intersect_key( $keel_pn_row( $id ), array_flip( array( 'display_name', 'nickname', 'user_nicename' ) ) ), 'P10: unrelated edit changes nothing public.' );

			foreach ( array( 'display_name', 'nickname', 'user_nicename' ) as $field ) {
				$values           = array(
					'display_name'  => 'Wren Ashby',
					'nickname'      => 'Wren',
					'user_nicename' => "m{$keel_pn_run}{$field}",
				);
				$mlogin           = "mix.{$field}.{$keel_pn_run}@fixture.test";
				$unsafe           = 'user_nicename' === $field ? sanitize_title( $mlogin ) : $mlogin;
				$values[ $field ] = $unsafe;
				$mid              = $keel_pn_user(
					array_merge(
						array(
							'user_login' => $mlogin,
							'user_email' => $mlogin,
						),
						$values
					)
				);
				$mrow             = $keel_pn_row( $mid );
				foreach ( $values as $key => $value ) {
					if ( $key === $field ) {
						$keel_pn_assert( $mrow[ $key ] !== $value, "P10: unsafe {$field} replaced." );
					} else {
						$keel_pn_assert( $mrow[ $key ] === $value, "P10: safe {$key} kept when {$field} was unsafe." );
					}
				}
			}

			$at = $keel_pn_user(
				array(
					'user_login'   => "at.{$keel_pn_run}@fixture.test",
					'user_email'   => "at.{$keel_pn_run}@fixture.test",
					'display_name' => 'Studio @ Night',
					'nickname'     => 'Studio',
				)
			);
			$keel_pn_assert( 'Studio @ Night' === $keel_pn_row( $at )['display_name'], 'P10: a non-address at-sign is kept.' );
		}
	);

	// P11: fallback order.
	$keel_pn_case(
		'P11',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set ) {
			$keel_pn_set( 'yes' );
			$cases = array(
				'nick'  => array(
					'nickname'   => 'Nick Choice',
					'first_name' => 'Fern',
					'last_name'  => 'Gale',
					'expect'     => 'Nick Choice',
				),
				'full'  => array(
					'nickname'   => '',
					'first_name' => 'Fern',
					'last_name'  => 'Gale',
					'expect'     => 'Fern Gale',
				),
				'last'  => array(
					'nickname'   => '',
					'first_name' => "tok.{$keel_pn_run}@else.test",
					'last_name'  => 'Gale',
					'expect'     => 'Gale',
				),
				'first' => array(
					'nickname'   => '',
					'first_name' => 'Fern',
					'last_name'  => '',
					'expect'     => 'Fern',
				),
				'case'  => array(
					'nickname'   => 'FB.CASE{run}',
					'first_name' => 'Fern',
					'last_name'  => 'Gale',
					'expect'     => 'Fern Gale',
				),
			);
			foreach ( $cases as $label => $case ) {
				$login = "fb.{$label}{$keel_pn_run}@fixture.test";
				$nick  = str_replace( '{run}', (string) $keel_pn_run, $case['nickname'] );
				$id    = $keel_pn_user(
					array(
						'user_login'   => $login,
						'user_email'   => $login,
						'display_name' => $login,
						'nickname'     => '' === $nick ? $login : $nick,
						'first_name'   => $case['first_name'],
						'last_name'    => $case['last_name'],
					)
				);
				$got   = $keel_pn_row( $id )['display_name'];
				$keel_pn_assert( $case['expect'] === $got, "P11 {$label}: expected '{$case['expect']}', got '{$got}'." );
			}
		}
	);

	// P12: neutral handle; opaque slug when the byline's slug is the login's; stable after saves.
	$keel_pn_case(
		'P12',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set ) {
			$keel_pn_set( 'yes' );
			$handles = array();
			for ( $i = 0; $i < 3; $i++ ) {
				$login     = "h{$i}.{$keel_pn_run}@fixture.test";
				$id        = $keel_pn_user(
					array(
						'user_login' => $login,
						'user_email' => $login,
					)
				);
				$name      = $keel_pn_row( $id )['display_name'];
				$handles[] = $name;
				$keel_pn_assert( false === stripos( $name, "h{$i}" ) && false === strpos( $name, (string) $id ) && false === strpos( $name, 'fixture' ), 'P12: handle not built from login, email or ID.' );
			}
			$keel_pn_assert( count( array_unique( $handles ) ) === 3, 'P12: handles differ between accounts.' );

			$login = "river.desk{$keel_pn_run}";
			$id    = $keel_pn_user(
				array(
					'user_login'   => $login,
					'user_email'   => "rd.{$keel_pn_run}@fixture.test",
					'display_name' => "River Desk{$keel_pn_run}",
				)
			);
			$slug  = $keel_pn_row( $id )['user_nicename'];
			$keel_pn_assert( (bool) preg_match( '/^[0-9a-f]{8,12}$/', $slug ), "P12: opaque slug, got '{$slug}'." );
			wp_update_user(
				array(
					'ID'          => $id,
					'description' => 'later',
				)
			);
			$keel_pn_assert( $slug === $keel_pn_row( $id )['user_nicename'], 'P12: opaque slug stable after a later save.' );
		}
	);

	// P13: long and colliding slugs, repaired by the cleanup.
	$keel_pn_case(
		'P13',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_cleanup_run, $keel_pn_safe ) {
			global $wpdb;
			$long = str_repeat( 'long.', 8 ) . "w{$keel_pn_run}@ft.test";
			$long = substr( $long, 0, 60 );
			$a    = $keel_pn_user(
				array(
					'user_login' => $long,
					'user_email' => "lw.{$keel_pn_run}@fixture.test",
				)
			);
			$base = $keel_pn_row( $a )['user_nicename'];
			$keel_pn_assert( strlen( $base ) <= 50 && ! $keel_pn_safe( $keel_pn_row( $a ) ), 'P13: truncated core slug starts exposed.' );
			$long2  = substr( $long, 0, 59 ) . 'x';
			$b      = $keel_pn_user(
				array(
					'user_login' => $long2,
					'user_email' => "lw2.{$keel_pn_run}@fixture.test",
				)
			);
			$byline = 'Bartholomew Featherstonehaugh Cholmondeley Marjoribanks ' . $keel_pn_run;
			$keel_pn_user(
				array(
					'user_login'    => "reserve{$keel_pn_run}",
					'user_email'    => "reserve.{$keel_pn_run}@fixture.test",
					'display_name'  => 'Reserve',
					'user_nicename' => trim( mb_substr( sanitize_title( $byline ), 0, 50 ), '-' ),
				)
			);
			update_user_meta( $a, 'nickname', $byline );
			$digits = $keel_pn_user(
				array(
					'user_login'    => "fn{$keel_pn_run}",
					'user_email'    => "fn.{$keel_pn_run}@fixture.test",
					'display_name'  => 'Field Notes',
					'user_nicename' => "field-notes-{$keel_pn_run}-2",
				)
			);
			$keel_pn_cleanup_run( get_current_blog_id(), false );
			foreach ( array( $a, $b ) as $id ) {
				$row = $keel_pn_row( $id );
				$keel_pn_assert( $keel_pn_safe( $row ) && strlen( $row['user_nicename'] ) <= 50, 'P13: repaired slug safe and within 50.' );
			}
			$keel_pn_assert( "field-notes-{$keel_pn_run}-2" === $keel_pn_row( $digits )['user_nicename'], 'P13: safe numeric slug kept.' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- collision census.
			$dupes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT user_nicename FROM {$wpdb->users} GROUP BY user_nicename HAVING COUNT(*) > 1) d" );
			$keel_pn_assert( 0 === $dupes, 'P13: every slug unique.' );
		}
	);

	// P15, P18, P24: cleanup fixes untouched accounts, leaves comments alone by default, reruns as a no-op.
	$keel_pn_case(
		'P15+P18+P24',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_cleanup_run, $keel_pn_safe, $keel_pn_admin ) {
			$pass                       = wp_generate_password( 24 );
			$strong                     = $keel_pn_user(
				array(
					'user_login' => "strong.{$keel_pn_run}@fixture.test",
					'user_email' => "strong.{$keel_pn_run}@fixture.test",
					'user_pass'  => $pass,
					'role'       => 'editor',
				)
			);
			$local                      = $keel_pn_user(
				array(
					'user_login'   => "loc{$keel_pn_run}",
					'user_email'   => "loc{$keel_pn_run}@fixture.test",
					'display_name' => "Loc{$keel_pn_run}",
					'user_pass'    => $pass,
				)
			);
			$nick                       = $keel_pn_user(
				array(
					'user_login'    => "nk{$keel_pn_run}",
					'user_email'    => "nick.{$keel_pn_run}@fixture.test",
					'display_name'  => 'Nick Safe',
					'nickname'      => "nick.{$keel_pn_run}@fixture.test",
					'user_nicename' => "nick-safe-{$keel_pn_run}",
				)
			);
			$safe                       = $keel_pn_user(
				array(
					'user_login'    => "safe{$keel_pn_run}",
					'user_email'    => "safe.{$keel_pn_run}@fixture.test",
					'display_name'  => 'Safe Person',
					'nickname'      => 'Safe',
					'user_nicename' => "safe-person-{$keel_pn_run}",
				)
			);
			$post                       = wp_insert_post(
				array(
					'post_title'  => 'P15',
					'post_status' => 'publish',
					'post_author' => $strong,
				)
			);
			$GLOBALS['keel_pn_posts'][] = $post;
			$old                        = $keel_pn_row( $strong )['display_name'];
			$comment                    = wp_insert_comment(
				array(
					'comment_post_ID' => $post,
					'user_id'         => $strong,
					'comment_author'  => $old,
					'comment_content' => 'kept',
				)
			);
			$before_safe                = $keel_pn_row( $safe );
			$before_cred                = array();
			foreach ( array( $strong, $local, $nick ) as $id ) {
				$r                  = $keel_pn_row( $id );
				$before_cred[ $id ] = array( $r['user_login'], $r['user_email'], $r['user_pass'] );
			}
			$roles = array_map(
				static function ( $id ) {
					return get_userdata( $id )->roles;
				},
				array( $strong, $local, $nick, $safe )
			);

			// P18: the checkbox renders unchecked.
			$previous = get_current_user_id();
			wp_set_current_user( $keel_pn_admin );
			ob_start();
			keel_defaults_public_names_render();
			$html = (string) ob_get_clean();
			wp_set_current_user( $previous );
			$keel_pn_assert( (bool) preg_match( '/<input type="checkbox" name="comments" value="1">/', $html ), 'P18: comment checkbox defaults to unchecked.' );

			$first = $keel_pn_cleanup_run( get_current_blog_id(), false );
			foreach ( array( $strong, $local, $nick ) as $id ) {
				$keel_pn_assert( $keel_pn_safe( $keel_pn_row( $id ) ), "P15: account {$id} repaired." );
			}
			$keel_pn_assert( $before_safe === $keel_pn_row( $safe ), 'P15: safe account identical.' );
			$roles_after = array_map(
				static function ( $id ) {
					return get_userdata( $id )->roles;
				},
				array( $strong, $local, $nick, $safe )
			);
			$keel_pn_assert( $roles_after === $roles, 'P15: roles identical.' );
			$keel_pn_assert( (int) get_post( $post )->post_author === $strong, 'P15: post ownership identical.' );
			clean_comment_cache( $comment );
			$keel_pn_assert( get_comment( $comment )->comment_author === $old && 0 === (int) $first['comments_changed'], 'P18: comments untouched without the checkbox.' );
			$keel_pn_assert( $first['changed'] >= 3, 'P15: at least the three exposed accounts changed.' );

			delete_transient( 'keel_identity_exposure' );
			keel_defaults_identity_exposure_invalidate();
			$report = keel_defaults_identity_exposure_report();
			foreach ( array( $strong, $local, $nick ) as $id ) {
				$keel_pn_assert( ! in_array( $id, wp_list_pluck( $report['named'], 'id' ), true ), 'P15: fixed account no longer named by Site Health.' );
			}

			// P24: rerun changes nothing; credentials identical; sign-in works.
			$second = $keel_pn_cleanup_run( get_current_blog_id(), false );
			$keel_pn_assert( 0 === (int) $second['changed'] && 0 === (int) $second['comments_changed'], 'P24: second run with the same choice changes nothing.' );

			// R5 on one site: a later run with comments on still finds the old name by its hash.
			$third = $keel_pn_cleanup_run( get_current_blog_id(), true );
			clean_comment_cache( $comment );
			$keel_pn_assert( 0 === (int) $third['changed'] && 1 === (int) $third['comments_changed'] && get_comment( $comment )->comment_author !== $old, 'R5: a later run with comments on renames the earlier comment.' );
			$fourth = $keel_pn_cleanup_run( get_current_blog_id(), true );
			$keel_pn_assert( 0 === (int) $fourth['changed'] && 0 === (int) $fourth['comments_changed'], 'P24: a rerun with comments on changes nothing.' );
			foreach ( $before_cred as $id => $cred ) {
				$r = $keel_pn_row( $id );
				$keel_pn_assert( array( $r['user_login'], $r['user_email'], $r['user_pass'] ) === $cred, 'P24: login, email and hash identical.' );
			}
			$keel_pn_assert( wp_authenticate( "strong.{$keel_pn_run}@fixture.test", $pass ) instanceof WP_User, 'P24: email login still signs in.' );
			$keel_pn_assert( wp_authenticate( "loc{$keel_pn_run}", $pass ) instanceof WP_User, 'P24: handle login still signs in.' );
			$keel_pn_assert( wp_authenticate( "loc{$keel_pn_run}@fixture.test", $pass ) instanceof WP_User, 'P24: email accepted as login still signs in.' );
		}
	);

	// P19: comment rewrite only on the account's own exact-match comments, cache cleared.
	$keel_pn_case(
		'P19',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_cleanup_run ) {
			$login                      = "cm.{$keel_pn_run}@fixture.test";
			$id                         = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
				)
			);
			$other                      = $keel_pn_user(
				array(
					'user_login'   => "other{$keel_pn_run}",
					'user_email'   => "other.{$keel_pn_run}@fixture.test",
					'display_name' => 'Other',
				)
			);
			$post                       = wp_insert_post(
				array(
					'post_title'  => 'P19',
					'post_status' => 'publish',
				)
			);
			$GLOBALS['keel_pn_posts'][] = $post;
			$make                       = static function ( $user, $author ) use ( $post ) {
				return wp_insert_comment(
					array(
						'comment_post_ID'      => $post,
						'user_id'              => $user,
						'comment_author'       => $author,
						'comment_author_email' => 'x@fixture.test',
						'comment_content'      => 'c',
					)
				);
			};
			$match                      = $make( $id, $login );
			$edited                     = $make( $id, 'Relabelled' );
			$cased                      = $make( $id, strtoupper( $login ) );
			$guest                      = $make( 0, $login );
			$others                     = $make( $other, $login );
			$all                        = array( $match, $edited, $cased, $guest, $others );
			$snap                       = static function () use ( $all ) {
				global $wpdb;
				$in = implode( ',', array_map( 'intval', $all ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				return $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ($in) ORDER BY comment_ID", OBJECT_K );
			};
			foreach ( $all as $c ) {
				get_comment( $c ); // Warm the cache.
			}
			$before = $snap();
			$state  = $keel_pn_cleanup_run( get_current_blog_id(), true );
			$after  = $snap();
			$new    = $keel_pn_row( $id )['display_name'];
			$keel_pn_assert( $after[ $match ]->comment_author === $new && 1 === (int) $state['comments_changed'], 'P19: only the exact match changed.' );
			foreach ( array( $edited, $cased, $guest, $others ) as $c ) {
				$keel_pn_assert( $before[ $c ] == $after[ $c ], "P19: comment {$c} identical." ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- row objects compared by value.
			}
			$b = (array) $before[ $match ];
			$a = (array) $after[ $match ];
			unset( $b['comment_author'], $a['comment_author'] );
			$keel_pn_assert( $a === $b, 'P19: other columns identical.' );
			$keel_pn_assert( get_comment( $match )->comment_author === $new, 'P19: cached read shows the new name.' );
		}
	);

	// P16, P17, P20 (server side): refusals write nothing; output carries no address.
	$keel_pn_case(
		'P16+P17+P20',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_admin ) {
			$login  = "deny.{$keel_pn_run}@fixture.test";
			$id     = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
				)
			);
			$before = $keel_pn_row( $id );
			$roles  = array( 'subscriber', 'contributor', 'author', 'editor' );
			if ( is_multisite() ) {
				$roles[] = 'administrator';
			}
			foreach ( $roles as $role ) {
				$caller = $keel_pn_user(
					array(
						'user_login' => "caller{$role}{$keel_pn_run}",
						'user_email' => "caller.{$role}.{$keel_pn_run}@fixture.test",
						'role'       => $role,
					)
				);
				wp_set_current_user( $caller );
				$_POST = array(
					'keel_public_names_nonce' => wp_create_nonce( 'keel_public_names_run' ),
					'mode'                    => 'start',
					'comments'                => '1',
				);
				$keel_pn_assert( null === keel_defaults_public_names_authorize(), "P16: {$role} refused." );
			}
			wp_set_current_user( 0 );
			$keel_pn_assert( null === keel_defaults_public_names_authorize(), 'P16: anonymous refused.' );
			wp_set_current_user( $keel_pn_admin );
			foreach ( array( '', 'bad', wp_create_nonce( 'other_action' ) ) as $nonce ) {
				$_POST = array(
					'keel_public_names_nonce' => $nonce,
					'mode'                    => 'start',
				);
				$keel_pn_assert( null === keel_defaults_public_names_authorize(), 'P17: bad nonce refused.' );
			}
			ob_start();
			keel_defaults_public_names_render();
			$html  = (string) ob_get_clean();
			$_POST = array();
			wp_set_current_user( 0 );
			$keel_pn_assert( $before === $keel_pn_row( $id ) && null === keel_defaults_public_names_state(), 'P16/P17: nothing written or queued.' );
			$keel_pn_assert( false === stripos( $html, 'fixture.test' ) && false === stripos( $html, rawurlencode( $login ) ), 'P20: screen carries no address.' );
		}
	);

	// R1 (Codex review of #361): fallbacks from raw input are sanitized like core's own fields.
	$keel_pn_case(
		'R1',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set ) {
			$keel_pn_set( 'yes' );
			$login = "xss.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login'   => $login,
					'user_email'   => $login,
					'display_name' => $login,
					'nickname'     => '<img src=x onerror=alert(1)>Pen',
					'first_name'   => '<script>x()</script>Ada',
				)
			);
			$row   = $keel_pn_row( $id );
			foreach ( array( 'display_name', 'nickname', 'first_name' ) as $field ) {
				$keel_pn_assert( false === stripos( $row[ $field ], '<img' ) && false === stripos( $row[ $field ], '<script' ), "R1: no markup stored in {$field}." );
			}
			$keel_pn_assert( false !== strpos( $row['display_name'], 'Pen' ), 'R1: the sanitized nickname is still the chosen fallback.' );
			wp_update_user(
				array(
					'ID'           => $id,
					'display_name' => $login,
					'nickname'     => '<b onmouseover=x()>Ink</b>',
				)
			);
			$row = $keel_pn_row( $id );
			$keel_pn_assert( false === stripos( $row['display_name'], '<b' ) && false === stripos( $row['display_name'], 'onmouseover' ), 'R1: update path sanitizes the fallback too.' );
		}
	);

	// R2: a nickname in meta_input, which core writes after the filtered one.
	$keel_pn_case(
		'R2',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$keel_pn_set( 'yes' );
			$login = "metain.{$keel_pn_run}@fixture.test";
			$id    = $keel_pn_user(
				array(
					'user_login' => $login,
					'user_email' => $login,
					'meta_input' => array( 'nickname' => $login ),
				)
			);
			$keel_pn_assert( $keel_pn_safe( $keel_pn_row( $id ) ), 'R2: meta_input nickname on insert is replaced.' );
			wp_update_user(
				array(
					'ID'         => $id,
					'meta_input' => array( 'nickname' => strtoupper( $login ) ),
				)
			);
			$keel_pn_assert( $keel_pn_safe( $keel_pn_row( $id ) ), 'R2: meta_input nickname on update is replaced.' );
			$safe = $keel_pn_user(
				array(
					'user_login' => "metasafe{$keel_pn_run}",
					'user_email' => "metasafe.{$keel_pn_run}@fixture.test",
					'meta_input' => array( 'nickname' => 'Chosen Nick' ),
				)
			);
			$keel_pn_assert( 'Chosen Nick' === $keel_pn_row( $safe )['nickname'], 'R2: a safe meta_input nickname is kept.' );
		}
	);

	// R3: the nickname is checked against the email being saved, not the cached one.
	$keel_pn_case(
		'R3',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
			$keel_pn_set( 'yes' );
			$id = $keel_pn_user(
				array(
					'user_login'   => "mover{$keel_pn_run}",
					'user_email'   => "old.{$keel_pn_run}@fixture.test",
					'display_name' => 'Mover',
					'nickname'     => 'Mover',
				)
			);
			get_userdata( $id ); // Warm the cache with the old email.
			wp_update_user(
				array(
					'ID'         => $id,
					'user_email' => "newlocal{$keel_pn_run}@fixture.test",
					'nickname'   => "newlocal{$keel_pn_run}",
				)
			);
			$row = $keel_pn_row( $id );
			$keel_pn_assert( "newlocal{$keel_pn_run}@fixture.test" === $row['user_email'], 'R3: the email change was saved.' );
			$keel_pn_assert( $keel_pn_safe( $row ), 'R3: the new local part is not kept as the nickname.' );
		}
	);

	// R4: comments are rewritten in bounded chunks and a run resumes inside one account.
	$keel_pn_case(
		'R4',
		function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_run, $keel_pn_cleanup_run ) {
			global $wpdb;
			$login                      = "chatty.{$keel_pn_run}@fixture.test";
			$id                         = $keel_pn_user(
				array(
					'user_login'   => $login,
					'user_email'   => $login,
					'display_name' => $login,
					'first_name'   => 'Chatty',
				)
			);
			$post                       = wp_insert_post(
				array(
					'post_title'  => 'R4',
					'post_status' => 'publish',
				)
			);
			$GLOBALS['keel_pn_posts'][] = $post;
			$count                      = KEEL_PUBLIC_NAMES_BATCH * 2 + 37;
			$rows                       = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$rows[] = $wpdb->prepare( '(%d, %d, %s, %s, %s, %s, %s)', $post, $id, $login, 'c', current_time( 'mysql' ), current_time( 'mysql', true ), '1' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- fixture rows, each prepared above.
			$wpdb->query( "INSERT INTO {$wpdb->comments} (comment_post_ID, user_id, comment_author, comment_content, comment_date, comment_date_gmt, comment_approved) VALUES " . implode( ',', $rows ) );
			$first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(comment_ID) FROM {$wpdb->comments} WHERE user_id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture read.

			// A previous request ran out of time after the account's first chunk:
			// rename the first chunk by hand, then resume from the saved comment.
			$hashes = keel_defaults_public_names_add_old( $id, $login );
			update_user_meta( $id, KEEL_PUBLIC_NAMES_OLD, $hashes );
			wp_update_user(
				array(
					'ID'           => $id,
					'display_name' => 'Chatty',
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture setup.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_author = 'Chatty' WHERE user_id = %d AND comment_ID < %d", $id, $first + KEEL_PUBLIC_NAMES_BATCH ) );
			$state                    = array(
				'id'               => 'r4',
				'target'           => get_current_blog_id(),
				'comments'         => true,
				'cursor'           => $id - 1,
				'scanned'          => 0,
				'changed'          => 1,
				'comments_changed' => 0,
				'done'             => false,
				'started'          => time(),
				'comment_resume'   => array(
					'id'    => $id,
					'after' => $first + KEEL_PUBLIC_NAMES_BATCH - 1,
				),
			);
			list( $state, $finished ) = keel_defaults_public_names_comments( $state, $id, $hashes, 'Chatty', microtime( true ) );
			$keel_pn_assert( $finished && empty( $state['comment_resume'] ), 'R4: the account finished in chunks.' );
			$keel_pn_assert( $count - KEEL_PUBLIC_NAMES_BATCH === (int) $state['comments_changed'], 'R4: resumed after the saved comment (' . (int) $state['comments_changed'] . ' changed).' );
			$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_author = %s", $id, $login ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture read.
			$keel_pn_assert( 0 === $left, 'R4: every comment renamed.' );

			// From scratch, a full run renames all of them across several chunks.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture reset.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_author = %s WHERE user_id = %d", $login, $id ) );
			$state = $keel_pn_cleanup_run( get_current_blog_id(), true );
			$left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d AND comment_author = %s", $id, $login ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture read.
			$keel_pn_assert( 0 === $left && $count === (int) $state['comments_changed'], 'R4: a full run renames every comment exactly once.' );
			$keel_pn_assert( false === in_array( $login, (array) get_user_meta( $id, KEEL_PUBLIC_NAMES_OLD, true ), true ), 'R4: only hashes are stored, never the old name.' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixture cleanup.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE user_id = %d", $id ) );
		}
	);

	if ( is_multisite() ) {
		// P21 (decided: network policy, else the main site decides for everyone).
		$keel_pn_case(
			'P21',
			function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_set, $keel_pn_safe ) {
				$b = wpmu_create_blog( get_network()->domain, "/pn{$keel_pn_run}/", 'PN B', 1 );
				$keel_pn_assert( is_int( $b ), 'P21: second site.' );
				try {
					$login = "shared.{$keel_pn_run}@fixture.test";
					$id    = $keel_pn_user(
						array(
							'user_login' => $login,
							'user_email' => $login,
						)
					);
					add_user_to_blog( $b, $id, 'author' );
					$snapshot = $keel_pn_row( $id );

					// Toggling the setting touches no user rows.
					$keel_pn_set( 'yes' );
					$keel_pn_set( 'no' );
					$keel_pn_assert( $snapshot === $keel_pn_row( $id ), 'P21: toggling writes nothing.' );

					// Main site off, B's own stored value on: saves on B follow core.
					update_blog_option( $b, KEEL_DEFAULTS_OPTION, array( 'protect_public_names' => 'yes' ) );
					switch_to_blog( $b );
					$keel_pn_assert( 'no' === keel_defaults_public_names_policy() && null !== keel_defaults_network_lock( 'protect_public_names' ), 'P21: B follows the main site and shows why.' );
					wp_update_user(
						array(
							'ID'          => $id,
							'description' => 'b',
						)
					);
					restore_current_blog();
					$keel_pn_assert( ! $keel_pn_safe( $keel_pn_row( $id ) ), 'P21: main site off, B save follows core.' );

					// Main site on: a save in B's context repairs the shared row, seen from both sites.
					$keel_pn_set( 'yes' );
					switch_to_blog( $b );
					wp_update_user(
						array(
							'ID'          => $id,
							'description' => 'b2',
						)
					);
					$from_b = $keel_pn_row( $id );
					restore_current_blog();
					$keel_pn_assert( $keel_pn_safe( $from_b ) && $from_b === $keel_pn_row( $id ), 'P21: main site on, one safe stored value on both sites.' );

					// Network policy wins over the main site.
					update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, array( 'protect_public_names' => 'no' ) );
					switch_to_blog( $b );
					$keel_pn_assert( 'no' === keel_defaults_public_names_policy(), 'P21: network policy decides.' );
					restore_current_blog();
				} finally {
					delete_site_option( KEEL_DEFAULTS_NETWORK_OPTION );
					wp_delete_site( $b );
				}
			}
		);

		// P22 (decided: one chosen site's members and that site's comments).
		$keel_pn_case(
			'P22',
			function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_cleanup_run, $keel_pn_safe, $keel_pn_admin ) {
				$b = wpmu_create_blog( get_network()->domain, "/pnb{$keel_pn_run}/", 'PN B', 1 );
				try {
					$a      = get_current_blog_id();
					$shared = $keel_pn_user(
						array(
							'user_login' => "both.{$keel_pn_run}@fixture.test",
							'user_email' => "both.{$keel_pn_run}@fixture.test",
						)
					);
					add_user_to_blog( $b, $shared, 'author' );
					switch_to_blog( $b );
					$only_b = $keel_pn_user(
						array(
							'user_login' => "bonly.{$keel_pn_run}@fixture.test",
							'user_email' => "bonly.{$keel_pn_run}@fixture.test",
						)
					);
					remove_user_from_blog( $only_b, $a );
					$post_b    = wp_insert_post(
						array(
							'post_title'  => 'B',
							'post_status' => 'publish',
						)
					);
					$comment_b = wp_insert_comment(
						array(
							'comment_post_ID' => $post_b,
							'user_id'         => $shared,
							'comment_author'  => "both.{$keel_pn_run}@fixture.test",
							'comment_content' => 'b',
						)
					);
					restore_current_blog();
					$post_a                     = wp_insert_post(
						array(
							'post_title'  => 'A',
							'post_status' => 'publish',
						)
					);
					$GLOBALS['keel_pn_posts'][] = $post_a;
					$comment_a                  = wp_insert_comment(
						array(
							'comment_post_ID' => $post_a,
							'user_id'         => $shared,
							'comment_author'  => "both.{$keel_pn_run}@fixture.test",
							'comment_content' => 'a',
						)
					);
					$only_b_before              = $keel_pn_row( $only_b );

					wp_set_current_user( $keel_pn_admin );
					ob_start();
					keel_defaults_public_names_render();
					$html = (string) ob_get_clean();
					wp_set_current_user( 0 );
					$keel_pn_assert( false !== strpos( $html, 'name="site"' ) && false !== strpos( $html, 'PN B' ), 'P22: target site chosen and named before submit.' );

					$state = $keel_pn_cleanup_run( $a, true );
					$keel_pn_assert( $keel_pn_safe( $keel_pn_row( $shared ) ), 'P22: shared member repaired, visible on both sites.' );
					$keel_pn_assert( $only_b_before === $keel_pn_row( $only_b ), 'P22: B-only member untouched.' );
					clean_comment_cache( $comment_a );
					$keel_pn_assert( get_comment( $comment_a )->comment_author === $keel_pn_row( $shared )['display_name'], 'P22: A comment updated.' );
					switch_to_blog( $b );
					clean_comment_cache( $comment_b );
					$keel_pn_assert( get_comment( $comment_b )->comment_author === "both.{$keel_pn_run}@fixture.test", 'P22: no comment on B changed.' );
					restore_current_blog();
					$keel_pn_assert( (int) $state['target'] === $a, 'P22: run recorded its target.' );
					$GLOBALS['keel_pn_users'][] = $only_b;
				} finally {
					wp_delete_site( $b );
				}
			}
		);

		// R5: a later run on another site still finds a shared account's old comments there.
		$keel_pn_case(
			'R5',
			function () use ( $keel_pn_assert, $keel_pn_user, $keel_pn_row, $keel_pn_run, $keel_pn_cleanup_run ) {
				$b = wpmu_create_blog( get_network()->domain, "/pnr5{$keel_pn_run}/", 'PN R5', 1 );
				try {
					$a     = get_current_blog_id();
					$login = "roam.{$keel_pn_run}@fixture.test";
					$id    = $keel_pn_user(
						array(
							'user_login'   => $login,
							'user_email'   => $login,
							'display_name' => $login,
							'first_name'   => 'Roam',
						)
					);
					add_user_to_blog( $b, $id, 'author' );
					switch_to_blog( $b );
					$post = wp_insert_post(
						array(
							'post_title'  => 'R5',
							'post_status' => 'publish',
						)
					);
					$c    = wp_insert_comment(
						array(
							'comment_post_ID' => $post,
							'user_id'         => $id,
							'comment_author'  => $login,
							'comment_content' => 'b',
						)
					);
					restore_current_blog();

					$keel_pn_cleanup_run( $a, true );
					$renamed = $keel_pn_row( $id )['display_name'];
					switch_to_blog( $b );
					clean_comment_cache( $c );
					$keel_pn_assert( get_comment( $c )->comment_author === $login, 'R5: the run on A left B alone.' );
					restore_current_blog();

					$state = $keel_pn_cleanup_run( $b, true );
					switch_to_blog( $b );
					clean_comment_cache( $c );
					$keel_pn_assert( get_comment( $c )->comment_author === $renamed, 'R5: the run on B renamed the shared account\'s comment there.' );
					restore_current_blog();
					$keel_pn_assert( 0 === (int) $state['changed'] && 1 === (int) $state['comments_changed'], 'R5: no account changed twice; one comment changed.' );
				} finally {
					wp_delete_site( $b );
				}
			}
		);

		// R6: networks too big for a list take a site ID.
		$keel_pn_case(
			'R6',
			function () use ( $keel_pn_assert, $keel_pn_admin ) {
				$fake = static function ( $result, $query ) {
					return ! empty( $query->query_vars['count'] ) ? KEEL_PUBLIC_NAMES_SITE_LIST + 1 : $result;
				};
				add_filter( 'sites_pre_query', $fake, 10, 2 );
				wp_set_current_user( $keel_pn_admin );
				ob_start();
				keel_defaults_public_names_render();
				$html = (string) ob_get_clean();
				wp_set_current_user( 0 );
				remove_filter( 'sites_pre_query', $fake, 10 );
				$keel_pn_assert( false !== strpos( $html, 'type="number" name="site"' ) && false === strpos( $html, '<select name="site"' ), 'R6: a site ID field replaces the list.' );
			}
		);
	}
} finally {
	$keel_pn_cleanup();
	$keel_pn_set( 'no' );
	remove_filter( 'pre_wp_mail', '__return_true' );
}

foreach ( $keel_pn_results as $line ) {
	echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
}
echo 'Public names: ' . count( $keel_pn_results ) . " cases passed.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
