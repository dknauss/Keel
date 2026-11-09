<?php
/**
 * Email-in-public-name Site Health check (#355) on a disposable WordPress install.
 *
 * Creates real users through wp_insert_user(), so core derives the display
 * names, slugs and collision suffixes exactly as it does on a live site, then
 * reads the check's report and rendered HTML. Each case is compared against the
 * site's own baseline and its fixtures are deleted before the next one. Covers
 * the tester probes in docs/review/probes/355-email-exposure-site-health.md that
 * need no browser; the access and network-capture probes run in the lab.
 *
 * Run: wp eval-file tests/integration/identity-exposure.php
 *
 * @package Keel
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'keel_defaults_identity_exposure_health' ) ) {
	throw new RuntimeException( 'Load WordPress with Keel active.' );
}

$keel_ie_run      = (string) wp_rand( 100000, 999999 );
$keel_ie_users    = array();
$keel_ie_posts    = array();
$keel_ie_emails   = array();
$keel_ie_site     = 0;
$keel_ie_original = get_current_user_id();
$keel_ie_results  = array();

$keel_ie_assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

/**
 * Create a fixture user through core. Addresses are synthetic, under .test.
 *
 * @param array $data wp_insert_user() fields; "role" defaults to subscriber.
 * @return int User ID.
 */
$keel_ie_user = static function ( array $data ) use ( &$keel_ie_users, &$keel_ie_emails ) {
	$data += array(
		'user_pass' => wp_generate_password( 24 ),
		'role'      => 'subscriber',
	);
	$id    = wp_insert_user( $data );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture user: ' . $id->get_error_code() );
	}
	$keel_ie_users[] = $id;
	foreach ( array( $data['user_login'], $data['user_email'], $data['display_name'] ?? '' ) as $value ) {
		if ( preg_match_all( '/[^\s<>()]+@[^\s<>()]+\.test/', (string) $value, $found ) ) {
			foreach ( $found[0] as $address ) {
				$keel_ie_emails[ strtolower( $address ) ] = true;
			}
		}
	}
	return $id;
};

$keel_ie_delete_users = static function () use ( &$keel_ie_users, &$keel_ie_posts ) {
	foreach ( $keel_ie_posts as $post ) {
		wp_delete_post( $post, true );
	}
	$keel_ie_posts = array();
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	foreach ( $keel_ie_users as $id ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $id );
		} else {
			wp_delete_user( $id );
		}
	}
	$keel_ie_users = array();
};

$keel_ie_report = static function () {
	return keel_defaults_identity_exposure_report();
};

$keel_ie_delta = static function ( array $before, array $after, $level ) {
	return $after['total'][ $level ] - $before['total'][ $level ];
};

$keel_ie_leaks = static function ( $text ) use ( &$keel_ie_emails ) {
	$text  = (string) $text;
	$forms = array( $text, html_entity_decode( $text, ENT_QUOTES ), rawurldecode( $text ), strtolower( $text ) );
	foreach ( array_keys( $keel_ie_emails ) as $address ) {
		foreach ( $forms as $form ) {
			if ( false !== stripos( $form, $address ) || false !== stripos( $form, rawurlencode( $address ) ) ) {
				return $address;
			}
		}
	}
	return '';
};

$keel_ie_case = static function ( $id, $title, callable $build, callable $check ) use ( &$keel_ie_results, $keel_ie_report, $keel_ie_delete_users ) {
	$before = $keel_ie_report();
	try {
		$build();
		$after = $keel_ie_report();
		$check( $before, $after );
		$keel_ie_results[] = "PASS {$id} {$title}";
	} finally {
		$keel_ie_delete_users();
	}
};

$keel_ie_health = static function () {
	return keel_defaults_identity_exposure_health();
};

try {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $admins[0]->ID );
	$r = $keel_ie_run;

	$baseline = $keel_ie_report();
	$keel_ie_assert( ! $baseline['error'], 'Baseline scan ran without a database error.' );

	$keel_ie_case(
		'P1',
		'email login with chosen byline and unrelated slug, default nickname, plus a handle login: no finding',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'    => "p1.writer{$r}@fixture.test",
					'user_email'    => "p1.writer{$r}@fixture.test",
					'display_name'  => 'Morgan Pierce',
					'user_nicename' => "morgan-pierce-{$r}",
				)
			);
			$keel_ie_user(
				array(
					'user_login'    => "riverdesk{$r}",
					'user_email'    => "ops{$r}@fixture.test",
					'display_name'  => 'River Desk',
					'user_nicename' => "river-desk-{$r}",
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 0 === $keel_ie_delta( $before, $after, 'strong' ) && 0 === $keel_ie_delta( $before, $after, 'weak' ), 'P1: clean fixtures add no finding.' );
		}
	);

	$keel_ie_case(
		'P2',
		'at-signs that are not addresses stay clean',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'    => "studio{$r}",
					'user_email'    => "night{$r}@fixture.test",
					'display_name'  => 'Studio @ Night',
					'user_nicename' => "studio-night-{$r}",
				)
			);
			$keel_ie_user(
				array(
					'user_login'    => "crew{$r}",
					'user_email'    => "crewmail{$r}@fixture.test",
					'display_name'  => 'Crew ops@local',
					'user_nicename' => "crew-ops-{$r}",
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 0 === $keel_ie_delta( $before, $after, 'strong' ) && 0 === $keel_ie_delta( $before, $after, 'weak' ), 'P2: no finding for non-address at-signs.' );
		}
	);

	$keel_ie_case(
		'P3',
		'display name equal to the email login, different case',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'    => "Case.Login{$r}@fixture.test",
					'user_email'    => "contact{$r}@other-fixture.test",
					'display_name'  => strtolower( "Case.Login{$r}@fixture.test" ),
					'user_nicename' => "safe-slug-{$r}",
					'role'          => 'author',
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P3: one strong finding.' );
			$keel_ie_assert( 1 === ( $after['strong']['author'] ?? 0 ) - ( $before['strong']['author'] ?? 0 ), 'P3: counted under Author.' );
			$keel_ie_assert( 'recommended' === keel_defaults_identity_exposure_health()['status'], 'P3: recommended.' );
		}
	);

	$keel_ie_case(
		'P4',
		'display name equal to the contact email, handle login',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'    => "handle{$r}",
					'user_email'    => "p4.contact{$r}@fixture.test",
					'display_name'  => "p4.contact{$r}@fixture.test",
					'user_nicename' => "handle-{$r}",
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta, $keel_ie_leaks ) {
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P4: one strong finding.' );
			$health = keel_defaults_identity_exposure_health();
			$keel_ie_assert( 'recommended' === $health['status'], 'P4: recommended.' );
			$keel_ie_assert( '' === $keel_ie_leaks( wp_json_encode( $health ) ), 'P4: address absent from the result.' );
		}
	);

	$keel_ie_case(
		'P5',
		'address embedded in a longer byline',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'    => "desk{$r}",
					'user_email'    => "desk{$r}@fixture.test",
					'display_name'  => "Desk outside{$r}@fixture.test Team",
					'user_nicename' => "desk-team-{$r}",
					'role'          => 'administrator',
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta, $keel_ie_leaks, $r ) {
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P5: one strong finding.' );
			$json = wp_json_encode( keel_defaults_identity_exposure_health() );
			$keel_ie_assert( '' === $keel_ie_leaks( $json ), 'P5: embedded address absent.' );
			$keel_ie_assert( false === strpos( $json, 'Desk outside' ), 'P5: full byline absent.' );
		}
	);

	$keel_ie_case(
		'P6',
		'slug from a mixed-case email login, and from the contact email of a handle login',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'   => "Mixed.Slug{$r}@Fixture.test",
					'user_email'   => "mixed{$r}@fixture.test",
					'display_name' => 'Mixed Slug Safe Name',
				)
			);
			$keel_ie_user(
				array(
					'user_login'    => "plainhandle{$r}",
					'user_email'    => "slugwriter{$r}@fixture.test",
					'display_name'  => 'Slug Writer',
					'user_nicename' => "slugwriter{$r}@fixture.test",
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 2 === $keel_ie_delta( $before, $after, 'strong' ), 'P6: two strong findings.' );
		}
	);

	$keel_ie_case(
		'P7',
		'core collision suffix flagged, unrelated -2 slug not',
		static function () use ( $keel_ie_user, $r ) {
			$base = sanitize_title( "collide{$r}@fixture.test" );
			$keel_ie_user(
				array(
					'user_login'    => "reserver{$r}",
					'user_email'    => "reserver{$r}@other-fixture.test",
					'display_name'  => 'Reserver',
					'user_nicename' => $base,
				)
			);
			$id   = $keel_ie_user(
				array(
					'user_login'   => "collide{$r}@fixture.test",
					'user_email'   => "collide{$r}@fixture.test",
					'display_name' => 'Collide Safe',
				)
			);
			$slug = get_userdata( $id )->user_nicename;
			if ( "{$base}-2" !== $slug ) {
				throw new RuntimeException( "P7: core assigned {$slug}, not {$base}-2." );
			}
			$keel_ie_user(
				array(
					'user_login'    => "fieldnotes{$r}",
					'user_email'    => "fn{$r}@fixture.test",
					'display_name'  => 'Field Notes',
					'user_nicename' => "field-notes-{$r}-2",
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			// The reserver's own slug is derived from the colliding address, but that
			// address is not its email or login, so it is clean.
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P7: only the -2 collision variant is strong.' );
			$keel_ie_assert( 0 === $keel_ie_delta( $before, $after, 'weak' ), 'P7: no weak findings.' );
		}
	);

	$keel_ie_case(
		'P8a',
		'login over 50 characters, ordinary core slug',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'   => str_repeat( 'long.', 8 ) . 'writer@fixture.test',
					'user_email'   => "lw{$r}@fixture.test",
					'display_name' => 'Long Writer',
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P8a: truncated base is strong.' );
		}
	);

	$keel_ie_case(
		'P8b',
		'login over 50 characters, core -10 collision variant',
		static function () use ( $keel_ie_user, $r ) {
			$login = str_repeat( 'long.', 8 ) . 'writer@fixture.test';
			$base  = sanitize_title( mb_substr( $login, 0, 50 ) );
			for ( $n = 1; $n <= 9; $n++ ) {
				$keel_ie_user(
					array(
						'user_login'    => "reserve{$n}x{$r}",
						'user_email'    => "reserve{$n}x{$r}@other-fixture.test",
						'display_name'  => "Reserve {$n}",
						'user_nicename' => 1 === $n ? $base : mb_substr( $base, 0, 49 - strlen( (string) $n ) ) . "-{$n}",
					)
				);
			}
			$id   = $keel_ie_user(
				array(
					'user_login'   => $login,
					'user_email'   => "lw{$r}@fixture.test",
					'display_name' => 'Long Writer',
				)
			);
			$slug = get_userdata( $id )->user_nicename;
			if ( mb_substr( $base, 0, 47 ) . '-10' !== $slug ) {
				throw new RuntimeException( "P8b: core assigned {$slug}." );
			}
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'strong' ), 'P8b: -10 variant is strong; reservers are clean.' );
		}
	);

	foreach ( array(
		'name'  => array(
			'user_login'    => "P9Name{$r}@fixture.test",
			'user_email'    => "P9Name{$r}@fixture.test",
			'display_name'  => strtoupper( "p9name{$r}" ),
			'user_nicename' => "p9-byline-{$r}",
		),
		'slug'  => array(
			'user_login'    => "p9.slug{$r}@fixture.test",
			'user_email'    => "p9.slug{$r}@fixture.test",
			'display_name'  => 'Nine Slug',
			'user_nicename' => "p9-slug{$r}",
		),
		'store' => array(
			'user_login'    => "jsmith{$r}",
			'user_email'    => "jsmith{$r}@shop-fixture.test",
			'display_name'  => "jsmith{$r}",
			'user_nicename' => "jsmith{$r}",
			'role'          => 'customer',
		),
	) as $keel_ie_variant => $keel_ie_data ) {
		$keel_ie_case(
			"P9-{$keel_ie_variant}",
			"weak-only match ({$keel_ie_variant})",
			static function () use ( $keel_ie_user, $keel_ie_data ) {
				$keel_ie_user( $keel_ie_data );
			},
			static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta, $keel_ie_variant ) {
				$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'weak' ), "P9-{$keel_ie_variant}: one weak finding." );
				$keel_ie_assert( 0 === $keel_ie_delta( $before, $after, 'strong' ), "P9-{$keel_ie_variant}: no strong finding." );
				if ( 0 === $before['total']['strong'] ) {
					$keel_ie_assert( 'good' === keel_defaults_identity_exposure_health()['status'], "P9-{$keel_ie_variant}: status stays good." );
				}
			}
		);
	}

	$keel_ie_case(
		'P10',
		'accounts counted once by role; weak-only kept separate',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'   => "p10admin{$r}@fixture.test",
					'user_email'   => "p10admin{$r}@fixture.test",
					'display_name' => "p10admin{$r}@fixture.test",
					'role'         => 'administrator',
				)
			);
			$keel_ie_user(
				array(
					'user_login'   => "p10editor{$r}@fixture.test",
					'user_email'   => "p10editor{$r}@fixture.test",
					'display_name' => "p10editor{$r}@fixture.test",
					'role'         => 'editor',
				)
			);
			$keel_ie_user(
				array(
					'user_login'    => "p10author{$r}@fixture.test",
					'user_email'    => "p10author{$r}@fixture.test",
					'display_name'  => "p10author{$r}",
					'user_nicename' => "p10-byline-{$r}",
					'role'          => 'author',
				)
			);
			$keel_ie_user(
				array(
					'user_login'   => "p10both{$r}@fixture.test",
					'user_email'   => "p10both{$r}@fixture.test",
					'display_name' => "p10both{$r}",
					'role'         => 'author',
				)
			);
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $keel_ie_delta ) {
			$keel_ie_assert( 3 === $keel_ie_delta( $before, $after, 'strong' ), 'P10: three distinct strong accounts.' );
			$keel_ie_assert( 1 === $keel_ie_delta( $before, $after, 'weak' ), 'P10: one weak-only account.' );
			$keel_ie_assert( 1 === ( $after['strong']['administrator'] ?? 0 ) - ( $before['strong']['administrator'] ?? 0 ), 'P10: one strong Administrator.' );
			$keel_ie_assert( 1 === ( $after['strong']['editor'] ?? 0 ) - ( $before['strong']['editor'] ?? 0 ), 'P10: one strong Editor.' );
			$keel_ie_assert( 1 === ( $after['strong']['author'] ?? 0 ) - ( $before['strong']['author'] ?? 0 ), 'P10: the strong+weak Author counts once, as strong.' );
			$keel_ie_assert( 'recommended' === keel_defaults_identity_exposure_health()['status'], 'P10: recommended.' );
		}
	);

	$keel_ie_case(
		'P11',
		'named detail lists only administrators and editors with public activity',
		static function () use ( $keel_ie_user, $r, &$keel_ie_posts ) {
			$make          = static function ( $who, $role ) use ( $keel_ie_user, $r ) {
				return $keel_ie_user(
					array(
						'user_login'   => "p11{$who}{$r}@fixture.test",
						'user_email'   => "p11{$who}{$r}@fixture.test",
						'display_name' => 'Safe ' . ucfirst( $who ),
						'role'         => $role,
					)
				);
			};
			$post_admin    = $make( 'postadmin', 'administrator' );
			$comment_ed    = $make( 'commented', 'editor' );
			$draft_admin   = $make( 'draftadmin', 'administrator' );
			$pending_ed    = $make( 'pendinged', 'editor' );
			$author        = $make( 'author', 'author' );
			$keel_ie_posts = array(
				wp_insert_post(
					array(
						'post_author' => $post_admin,
						'post_title'  => 'P11 public',
						'post_status' => 'publish',
					)
				),
				wp_insert_post(
					array(
						'post_author' => $draft_admin,
						'post_title'  => 'P11 draft',
						'post_status' => 'draft',
					)
				),
				wp_insert_post(
					array(
						'post_author' => $author,
						'post_title'  => 'P11 author',
						'post_status' => 'publish',
					)
				),
			);
			$on            = $keel_ie_posts[0];
			wp_insert_comment(
				array(
					'comment_post_ID'  => $on,
					'user_id'          => $comment_ed,
					'comment_content'  => 'approved',
					'comment_approved' => 1,
				)
			);
			wp_insert_comment(
				array(
					'comment_post_ID'  => $on,
					'user_id'          => $pending_ed,
					'comment_content'  => 'pending',
					'comment_approved' => 0,
				)
			);
			keel_defaults_identity_exposure_invalidate();
		},
		static function ( $before, $after ) use ( $keel_ie_assert, $r ) {
			$labels = wp_list_pluck( $after['named'], 'label' );
			$keel_ie_assert( in_array( 'Safe Postadmin', $labels, true ) && in_array( 'Safe Commented', $labels, true ), 'P11: active administrator and editor named.' );
			foreach ( array( 'Safe Draftadmin', 'Safe Pendinged', 'Safe Author' ) as $absent ) {
				$keel_ie_assert( ! in_array( $absent, $labels, true ), "P11: {$absent} not named." );
			}
			$html = keel_defaults_identity_exposure_health()['description'];
			foreach ( $after['named'] as $entry ) {
				if ( 'Safe Postadmin' === $entry['label'] ) {
					$keel_ie_assert( false !== strpos( $html, 'user-edit.php?user_id=' . $entry['id'] ), 'P11: profile link points at the right user ID.' );
				}
			}
		}
	);

	$keel_ie_case(
		'P12',
		'coverage copy with archive and REST settings on and off',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'   => "p12{$r}@fixture.test",
					'user_email'   => "p12{$r}@fixture.test",
					'display_name' => "p12{$r}@fixture.test",
				)
			);
		},
		static function () use ( $keel_ie_assert ) {
			$force = static function ( $value ) {
				return static function ( $stored ) use ( $value ) {
					$stored                                 = is_array( $stored ) ? $stored : array();
					$stored['disable_author_archives']      = $value;
					$stored['restrict_rest_user_discovery'] = $value;
					return $stored;
				};
			};
			foreach ( array( 'yes', 'no' ) as $value ) {
				$filter = $force( $value );
				add_filter( 'option_keel_settings', $filter, 999 );
				add_filter( 'default_option_keel_settings', $filter, 999 );
				if ( is_multisite() ) {
					add_filter( 'site_option_keel_network_settings', '__return_empty_array', 999 );
				}
				$health = keel_defaults_identity_exposure_health();
				remove_filter( 'option_keel_settings', $filter, 999 );
				remove_filter( 'default_option_keel_settings', $filter, 999 );
				remove_filter( 'site_option_keel_network_settings', '__return_empty_array', 999 );
				$keel_ie_assert( 'recommended' === $health['status'], "P12 ({$value}): still recommended." );
				$keel_ie_assert( false !== strpos( $health['description'], 'Still visible whatever Keel is set to' ), "P12 ({$value}): names the surfaces Keel doesn't cover." );
				if ( 'yes' === $value ) {
					$keel_ie_assert( false !== strpos( $health['description'], 'does not make them safe to publish' ), 'P12 (on): says closed routes are not a fix.' );
				} else {
					$keel_ie_assert( false !== strpos( $health['description'], 'Open on this site because the matching Keel setting is off' ), 'P12 (off): says the routes are open.' );
				}
			}
		}
	);

	$keel_ie_case(
		'P18',
		'registration invalidates a warm result immediately',
		static function () {},
		static function ( $before ) use ( $keel_ie_assert, $keel_ie_user, $keel_ie_report, $r ) {
			$warm = get_transient( 'keel_identity_exposure' );
			$keel_ie_assert( is_array( $warm ), 'P18: report is cached.' );
			$keel_ie_user(
				array(
					'user_login' => "p18{$r}@fixture.test",
					'user_email' => "p18{$r}@fixture.test",
				)
			);
			$keel_ie_assert( keel_defaults_identity_exposure_generation() !== $warm['gen'], 'P18: registration changed the generation.' );
			$after = $keel_ie_report();
			$keel_ie_assert( 1 === $after['total']['strong'] - $before['total']['strong'], 'P18: next request reports the new strong match.' );
		}
	);

	$keel_ie_case(
		'P20',
		'running the report leaves identities, content and settings intact',
		static function () use ( $keel_ie_user, $r, &$keel_ie_posts ) {
			$id              = $keel_ie_user(
				array(
					'user_login' => "p20{$r}@fixture.test",
					'user_email' => "p20{$r}@fixture.test",
					'role'       => 'author',
				)
			);
			$keel_ie_posts[] = wp_insert_post(
				array(
					'post_author' => $id,
					'post_title'  => 'P20',
					'post_status' => 'publish',
				)
			);
			wp_insert_comment(
				array(
					'comment_post_ID'  => $keel_ie_posts[0],
					'user_id'          => $id,
					'comment_author'   => "p20{$r}@fixture.test",
					'comment_content'  => 'kept',
					'comment_approved' => 1,
				)
			);
		},
		static function () use ( $keel_ie_assert, &$keel_ie_users, &$keel_ie_posts ) {
			global $wpdb;
			$id       = end( $keel_ie_users );
			$snapshot = static function () use ( $wpdb, $id, &$keel_ie_posts ) {
				return wp_json_encode(
					array(
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- authoritative before/after snapshot.
						$wpdb->get_row( $wpdb->prepare( "SELECT user_login, user_email, display_name, user_nicename FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A ),
						get_user_meta( $id, 'nickname', true ),
						get_post( $keel_ie_posts[0], ARRAY_A ),
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- authoritative before/after snapshot.
						$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE user_id = %d", $id ), ARRAY_A ),
						get_option( 'keel_settings' ),
					)
				);
			};
			$first    = $snapshot();
			delete_transient( 'keel_identity_exposure' );
			keel_defaults_identity_exposure_health();
			keel_defaults_identity_exposure_health();
			$keel_ie_assert( $first === $snapshot(), 'P20: cold and warm checks change nothing but the cache.' );
		}
	);

	// P15 and P19 need a second site.
	if ( is_multisite() ) {
		$installing   = wp_installing();
		$keel_ie_site = wpmu_create_blog( get_network()->domain, '/keel-ie-' . $r . '/', 'Identity probe', $admins[0]->ID );
		wp_installing( $installing );
		$keel_ie_assert( ! is_wp_error( $keel_ie_site ), 'Create the second site.' );
		$site_a = get_current_blog_id();
		$site_b = $keel_ie_site;
		$b_base = array();

		$keel_ie_case(
			'P15',
			'each site counts only its own members, with warm caches A/B/A',
			static function () use ( $keel_ie_user, $r, $site_a, $site_b, &$keel_ie_posts, &$b_base ) {
				switch_to_blog( $site_b );
				$b_base = keel_defaults_identity_exposure_scan();
				restore_current_blog();
				$strong_a        = $keel_ie_user(
					array(
						'user_login' => "p15stronga{$r}@fixture.test",
						'user_email' => "p15stronga{$r}@fixture.test",
						'role'       => 'administrator',
					)
				);
				$keel_ie_posts[] = wp_insert_post(
					array(
						'post_author' => $strong_a,
						'post_title'  => 'P15',
						'post_status' => 'publish',
					)
				);
				$weak_b          = $keel_ie_user(
					array(
						'user_login'    => "p15weakb{$r}@fixture.test",
						'user_email'    => "p15weakb{$r}@fixture.test",
						'display_name'  => "p15weakb{$r}",
						'user_nicename' => "p15-b-{$r}",
					)
				);
				remove_user_from_blog( $weak_b, $site_a );
				add_user_to_blog( $site_b, $weak_b, 'subscriber' );
				$shared = $keel_ie_user(
					array(
						'user_login'    => "p15shared{$r}",
						'user_email'    => "p15shared{$r}@fixture.test",
						'display_name'  => 'Shared Safe',
						'user_nicename' => "shared-safe-{$r}",
					)
				);
				add_user_to_blog( $site_b, $shared, 'subscriber' );
				$nobody = $keel_ie_user(
					array(
						'user_login' => "p15nobody{$r}@fixture.test",
						'user_email' => "p15nobody{$r}@fixture.test",
					)
				);
				remove_user_from_blog( $nobody, $site_a );
			},
			static function ( $before ) use ( $keel_ie_assert, $keel_ie_report, $site_a, $site_b, &$b_base ) {
				$a1 = $keel_ie_report();
				switch_to_blog( $site_b );
				$b = $keel_ie_report();
				restore_current_blog();
				$a2 = $keel_ie_report();
				$keel_ie_assert( 1 === $a1['total']['strong'] - $before['total']['strong'], 'P15: A has one new strong match.' );
				$keel_ie_assert( 0 === $a1['total']['weak'] - $before['total']['weak'], 'P15: A does not count B\'s weak member.' );
				$keel_ie_assert( $b_base['total']['strong'] === $b['total']['strong'] && $b_base['total']['weak'] + 1 === $b['total']['weak'], 'P15: B gains one weak match and no strong.' );
				$keel_ie_assert( $a1['total'] === $a2['total'] && $a1['checked'] === $a2['checked'], 'P15: A served warm, unchanged by B.' );
				$keel_ie_assert( (int) $site_b === (int) $b['site'] && (int) $site_a === (int) $a2['site'], 'P15: each cache belongs to its own site.' );
				$keel_ie_assert( array() === $b['named'], 'P15: B shows none of A\'s named accounts.' );
			}
		);

		$keel_ie_case(
			'P19',
			'profile updates invalidate every site in both directions',
			static function () {},
			static function () use ( $keel_ie_assert, $keel_ie_user, $keel_ie_report, $r, $site_b ) {
				$id = $keel_ie_user(
					array(
						'user_login'    => "p19member{$r}",
						'user_email'    => "p19member{$r}@fixture.test",
						'display_name'  => 'Member Safe',
						'user_nicename' => "member-safe-{$r}",
					)
				);
				add_user_to_blog( $site_b, $id, 'subscriber' );
				$a0 = $keel_ie_report()['total']['strong'];
				switch_to_blog( $site_b );
				$b0 = $keel_ie_report()['total']['strong'];
				restore_current_blog();
				wp_update_user(
					array(
						'ID'           => $id,
						'display_name' => "p19member{$r}@fixture.test",
					)
				);
				$keel_ie_assert( $a0 + 1 === $keel_ie_report()['total']['strong'], 'P19: A sees the exposure on its next request.' );
				switch_to_blog( $site_b );
				$keel_ie_assert( $b0 + 1 === $keel_ie_report()['total']['strong'], 'P19: B sees it too.' );
				restore_current_blog();
				wp_update_user(
					array(
						'ID'           => $id,
						'display_name' => 'Member Safe',
					)
				);
				$keel_ie_assert( $a0 === $keel_ie_report()['total']['strong'], 'P19: A back to its baseline.' );
				switch_to_blog( $site_b );
				$keel_ie_assert( $b0 === $keel_ie_report()['total']['strong'], 'P19: B back to its baseline.' );
				restore_current_blog();
			}
		);
	}

	// P13, server side: nothing the check stores or renders carries a fixture address.
	$keel_ie_case(
		'P13-server',
		'no fixture address in the stored report or rendered result',
		static function () use ( $keel_ie_user, $r ) {
			$keel_ie_user(
				array(
					'user_login'   => "canary.login{$r}@fixture.test",
					'user_email'   => "canary.email{$r}@fixture.test",
					'display_name' => "Canary canary.byline{$r}@fixture.test",
					'role'         => 'administrator',
				)
			);
		},
		static function () use ( $keel_ie_assert, $keel_ie_leaks ) {
			delete_transient( 'keel_identity_exposure' );
			$cold = keel_defaults_identity_exposure_health();
			$warm = keel_defaults_identity_exposure_health();
			foreach ( array( $cold, $warm, get_transient( 'keel_identity_exposure' ) ) as $payload ) {
				$found = $keel_ie_leaks( maybe_serialize( $payload ) . wp_json_encode( $payload ) );
				$keel_ie_assert( '' === $found, "P13: {$found} leaked." );
			}
		}
	);

	fwrite( STDOUT, implode( "\n", $keel_ie_results ) . "\nOK: identity exposure contracts, " . count( $keel_ie_results ) . " cases.\n" );
} finally {
	$keel_ie_delete_users();
	if ( $keel_ie_site ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $keel_ie_site, true );
	}
	delete_transient( 'keel_identity_exposure' );
	wp_set_current_user( $keel_ie_original );
}
