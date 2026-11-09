<?php
/**
 * Report accounts whose public name or URL slug publishes an email address.
 *
 * When an account's login is an email address, WordPress copies it into the
 * display name and derives the URL slug from it unless someone changes them.
 * Keel's archive and REST defaults close most anonymous routes to those fields,
 * but theme bylines, logged-in comment names and comment CSS classes still carry
 * them. This is the report-only tier decided on dknauss/Keel#355: one bounded
 * candidate query per site, counts by role, a capped list of named
 * administrators and editors with public activity, and never an address in the
 * output. Nothing here writes to a user, a post or a comment.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'site_status_tests', 'keel_defaults_register_identity_exposure_test' );
add_action( 'wp_ajax_health-check-keel-identity-exposure', 'keel_defaults_identity_exposure_ajax' );

// Any change to who is a member, or to a member's public fields, makes every
// site's cached report stale. One network-wide generation marker covers them all,
// including a profile edit made on a different site of the same network.
foreach ( array( 'user_register', 'profile_update', 'deleted_user', 'set_user_role', 'add_user_role', 'remove_user_role', 'add_user_to_blog', 'remove_user_from_blog', 'wpmu_delete_user' ) as $keel_identity_hook ) {
	add_action( $keel_identity_hook, 'keel_defaults_identity_exposure_invalidate' );
}
unset( $keel_identity_hook );

/** Candidate rows read per site; one more is fetched to detect a partial scan. */
const KEEL_IDENTITY_CANDIDATE_LIMIT = 20000;

/** Named administrators and editors shown at most. */
const KEEL_IDENTITY_NAMED_LIMIT = 20;

/** Seconds a report stays cached when nothing changes. */
const KEEL_IDENTITY_TTL = 43200;

/**
 * Register an async test, so the scan never runs while Site Health renders.
 *
 * @param array $tests Site Health tests.
 * @return array Registered tests.
 */
function keel_defaults_register_identity_exposure_test( $tests ) {
	if ( is_array( $tests ) ) {
		$tests['async']['keel_identity_exposure'] = array(
			'label'             => __( 'Email addresses in public names', 'keel-defaults' ),
			'test'              => 'keel-identity-exposure',
			'async_direct_test' => 'keel_defaults_identity_exposure_health',
		);
	}
	return $tests;
}

/**
 * Whether a string is an email address, ignoring surrounding punctuation.
 *
 * @param string $value Candidate.
 * @return bool
 */
function keel_defaults_identity_is_email( $value ) {
	$value = trim( (string) $value, " \t\n\r\0\x0B<>()[]{}\"',;:" );
	return '' !== $value && false !== strpos( $value, '@' ) && false !== is_email( $value );
}

/**
 * Whether any whitespace-separated token of a public name is an email address.
 *
 * A name like "Studio @ Night" or "ops@local" holds an at-sign but no address.
 *
 * @param string $name Public name.
 * @return bool
 */
function keel_defaults_identity_has_email_token( $name ) {
	$name = (string) $name;
	if ( false === strpos( $name, '@' ) ) {
		return false;
	}
	foreach ( preg_split( '/\s+/u', $name ) as $token ) {
		if ( keel_defaults_identity_is_email( $token ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a slug is the one core derives from a value, with or without the
 * collision suffix core appends when the slug is taken.
 *
 * Core builds the slug as sanitize_title( mb_substr( $value, 0, 50 ) ). On a
 * collision it tries mb_substr( $slug, 0, 49 - strlen( $n ) ) . "-$n" for
 * n = 2, 3, ... So a suffixed slug only counts when its stem is exactly that
 * truncation, and an unrelated slug such as "field-notes-2" never does.
 *
 * @param string $slug  Stored user_nicename.
 * @param string $value Login, email address or local part it may come from.
 * @return bool
 */
function keel_defaults_identity_slug_from( $slug, $value ) {
	$slug = strtolower( (string) $slug );
	$base = strtolower( (string) sanitize_title( mb_substr( (string) $value, 0, 50 ) ) );
	if ( '' === $base || '' === $slug ) {
		return false;
	}
	if ( $slug === $base ) {
		return true;
	}
	if ( ! preg_match( '/^(.+)-([1-9][0-9]*)$/', $slug, $parts ) || 2 > (int) $parts[2] ) {
		return false;
	}
	return mb_substr( $base, 0, 49 - strlen( $parts[2] ) ) === $parts[1];
}

/**
 * Classify one account. Pure: reads only the fields passed in.
 *
 * Strong (issue #355 rules 1-3):
 *  1. the login is an email address and the display name is the login;
 *  2. the display name is the contact email, or holds an email address;
 *  3. the slug is core's derivation of an email login or of the contact email.
 * Weak (rule 4): the display name or slug is the email's local part.
 * All comparisons ignore case.
 *
 * @param array $row user_login, user_email, display_name, user_nicename.
 * @return array{level:string,name:bool,slug:bool} Level is strong, weak or ''.
 */
function keel_defaults_identity_classify( array $row ) {
	$login = (string) ( $row['user_login'] ?? '' );
	$email = (string) ( $row['user_email'] ?? '' );
	$name  = (string) ( $row['display_name'] ?? '' );
	$slug  = (string) ( $row['user_nicename'] ?? '' );
	$lower = static function ( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ) ) : strtolower( trim( $value ) );
	};

	$login_is_email = keel_defaults_identity_is_email( $login );
	$email_is_email = keel_defaults_identity_is_email( $email );

	$strong_name = ( $login_is_email && $lower( $name ) === $lower( $login ) )
		|| ( '' !== $email && $lower( $name ) === $lower( $email ) )
		|| keel_defaults_identity_has_email_token( $name );
	$strong_slug = ( $login_is_email && keel_defaults_identity_slug_from( $slug, $login ) )
		|| ( $email_is_email && keel_defaults_identity_slug_from( $slug, $email ) );

	$locals = array();
	foreach ( array( $email_is_email ? $email : '', $login_is_email ? $login : '' ) as $address ) {
		if ( '' !== $address ) {
			$local = substr( $address, 0, strrpos( $address, '@' ) );
			if ( '' !== $local ) {
				$locals[ $lower( $local ) ] = $local;
			}
		}
	}
	$weak_name = false;
	$weak_slug = false;
	foreach ( $locals as $key => $local ) {
		$weak_name = $weak_name || $lower( $name ) === $key;
		$weak_slug = $weak_slug || keel_defaults_identity_slug_from( $slug, $local );
	}

	if ( $strong_name || $strong_slug ) {
		$level = 'strong';
	} elseif ( $weak_name || $weak_slug ) {
		$level = 'weak';
	} else {
		$level = '';
	}

	return array(
		'level' => $level,
		'name'  => $strong_name || $weak_name,
		'slug'  => $strong_slug || $weak_slug,
	);
}

/**
 * The role an account is counted under on this site, most privileged first.
 *
 * @param array $roles Role slugs.
 * @return string Role slug, or '' for none.
 */
function keel_defaults_identity_primary_role( array $roles ) {
	foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
		if ( in_array( $role, $roles, true ) ) {
			return $role;
		}
	}
	return $roles ? (string) reset( $roles ) : '';
}

/**
 * A label for a named account that can't leak an address: the display name
 * when it is safe, otherwise the user ID.
 *
 * @param array $row   Candidate row.
 * @param array $verdict Classification.
 * @return string Plain text.
 */
function keel_defaults_identity_safe_label( array $row, array $verdict ) {
	$name = trim( (string) ( $row['display_name'] ?? '' ) );
	if ( '' !== $name && ! $verdict['name'] && false === strpos( $name, '@' ) ) {
		return $name;
	}
	/* translators: %d: user ID. */
	return sprintf( __( 'User #%d', 'keel-defaults' ), (int) $row['ID'] );
}

/**
 * Scan this site's members once. Returns counts and safe labels, never an address.
 *
 * One candidate query narrows the members to accounts that could match at all,
 * then PHP applies the exact rules. Two grouped queries check public activity
 * for the exposed administrators and editors. No query runs per user.
 *
 * @return array Report.
 */
function keel_defaults_identity_exposure_scan() {
	global $wpdb;

	$meta_key = $wpdb->get_blog_prefix() . 'capabilities';
	$limit    = KEEL_IDENTITY_CANDIDATE_LIMIT + 1;

	// The candidate filter over-matches on purpose; keel_defaults_identity_classify()
	// decides. It catches every email login, every name with an at-sign, a name
	// equal to the email's local part, and a slug that is a prefix of the email
	// with its at-sign and plus signs removed and dots as hyphens (core's derivation), which also
	// covers a slug built from the local part. "_" in the LIKE pattern stands for
	// the hyphen-or-dot position. Collation makes these comparisons case-blind on
	// MySQL; LOWER() does it for SQLite. Administrators and editors sort first, so
	// a scan cut short by the limit still sees the accounts that can be named.
	// The LIKE patterns built from columns are the point of the query, not user input.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- one bounded diagnostic read, cached in a transient by the caller.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_nicename, m.meta_value AS caps
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
			WHERE u.user_login LIKE %s
				OR u.display_name LIKE %s
				OR LOWER(u.display_name) = LOWER(u.user_email)
				OR LOWER(u.display_name) = LOWER(SUBSTR(u.user_email, 1, INSTR(u.user_email, '@') - 1))
				OR REPLACE(REPLACE(REPLACE(LOWER(u.user_email), '@', ''), '.', '-'), '+', '') LIKE CONCAT(REPLACE(LOWER(u.user_nicename), '-', '_'), '%%')
			ORDER BY CASE WHEN m.meta_value LIKE %s OR m.meta_value LIKE %s THEN 0 ELSE 1 END, u.ID
			LIMIT %d",
			$meta_key,
			'%@%',
			'%@%',
			'%"administrator"%',
			'%"editor"%',
			$limit
		),
		ARRAY_A
	);
	// phpcs:enable

	$report = array(
		'schema'  => 1,
		'site'    => get_current_blog_id(),
		'gen'     => keel_defaults_identity_exposure_generation(),
		'checked' => time(),
		'error'   => '' !== $wpdb->last_error,
		'limited' => is_array( $rows ) && count( $rows ) > KEEL_IDENTITY_CANDIDATE_LIMIT,
		'strong'  => array(),
		'weak'    => array(),
		'total'   => array(
			'strong' => 0,
			'weak'   => 0,
		),
		'named'   => array(),
		'more'    => 0,
	);
	if ( ! is_array( $rows ) ) {
		$report['error'] = true;
		return $report;
	}
	$rows = array_slice( $rows, 0, KEEL_IDENTITY_CANDIDATE_LIMIT );

	$eligible = array();
	foreach ( $rows as $row ) {
		$class = keel_defaults_identity_classify( $row );
		if ( '' === $class['level'] ) {
			continue;
		}
		$caps  = maybe_unserialize( $row['caps'] );
		$roles = is_array( $caps ) ? array_keys( array_filter( $caps ) ) : array();
		$role  = keel_defaults_identity_primary_role( $roles );
		$level = $class['level'];

		$report[ $level ][ $role ] = ( $report[ $level ][ $role ] ?? 0 ) + 1;
		++$report['total'][ $level ];

		if ( 'strong' === $level && in_array( $role, array( 'administrator', 'editor' ), true ) ) {
			$eligible[ (int) $row['ID'] ] = array(
				'id'    => (int) $row['ID'],
				'role'  => $role,
				'label' => keel_defaults_identity_safe_label( $row, $class ),
				'name'  => $class['name'],
				'slug'  => $class['slug'],
			);
		}
	}

	if ( $eligible ) {
		$active = keel_defaults_identity_public_activity( array_slice( array_keys( $eligible ), 0, 500 ) );
		$named  = array();
		foreach ( $eligible as $id => $entry ) {
			if ( isset( $active[ $id ] ) ) {
				$named[] = $entry;
			}
		}
		usort(
			$named,
			static function ( $a, $b ) {
				return array( 'administrator' === $a['role'] ? 0 : 1, $a['id'] ) <=> array( 'administrator' === $b['role'] ? 0 : 1, $b['id'] );
			}
		);
		$report['more']  = max( 0, count( $named ) - KEEL_IDENTITY_NAMED_LIMIT );
		$report['named'] = array_slice( $named, 0, KEEL_IDENTITY_NAMED_LIMIT );
	}

	return $report;
}

/**
 * Which of these users have published content in a public post type or an
 * approved comment on this site.
 *
 * @param int[] $ids User IDs.
 * @return array<int,true> Set of active IDs.
 */
function keel_defaults_identity_public_activity( array $ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	$types = array_values( get_post_types( array( 'public' => true ) ) );
	$found = array();
	$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	if ( $types ) {
		$type_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated to match the arrays.
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_author IN ($in) AND post_type IN ($type_in)", array_merge( $ids, $types ) ) );
		foreach ( (array) $posts as $id ) {
			$found[ (int) $id ] = true;
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated to match the array.
	$comments = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->comments} WHERE comment_approved = '1' AND user_id IN ($in)", $ids ) );
	foreach ( (array) $comments as $id ) {
		$found[ (int) $id ] = true;
	}
	return $found;
}

/**
 * The network-wide marker that changes whenever a member or a member's public
 * fields change. Reports cached under an older marker are stale.
 *
 * @return string
 */
function keel_defaults_identity_exposure_generation() {
	return (string) get_site_option( 'keel_identity_exposure_gen', '0' );
}

/**
 * Mark every site's cached report stale.
 *
 * @return void
 */
function keel_defaults_identity_exposure_invalidate() {
	update_site_option( 'keel_identity_exposure_gen', wp_generate_uuid4() );
}

/**
 * This site's report, from cache when it is still current.
 *
 * @return array Report.
 */
function keel_defaults_identity_exposure_report() {
	$cached = get_transient( 'keel_identity_exposure' );
	if ( is_array( $cached ) && 1 === ( $cached['schema'] ?? 0 ) && (int) get_current_blog_id() === (int) ( $cached['site'] ?? 0 ) && keel_defaults_identity_exposure_generation() === ( $cached['gen'] ?? null ) ) {
		return $cached;
	}
	$report = keel_defaults_identity_exposure_scan();
	set_transient( 'keel_identity_exposure', $report, $report['error'] ? 300 : KEEL_IDENTITY_TTL );
	return $report;
}

/**
 * Role counts as plain text: "Administrator: 2, Editor: 1".
 *
 * @param array $counts Role slug => count.
 * @return string
 */
function keel_defaults_identity_role_counts( array $counts ) {
	$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
	$parts = array();
	foreach ( $counts as $role => $count ) {
		$label   = '' === $role ? __( 'No role on this site', 'keel-defaults' ) : ( isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role );
		$parts[] = sprintf( '%s: %d', $label, (int) $count );
	}
	return implode( ', ', $parts );
}

/**
 * The Site Health result. Never prints an email address or an unsafe name.
 *
 * @return array Site Health result.
 */
function keel_defaults_identity_exposure_health() {
	$result = array(
		'label'       => __( 'No account publishes an email address as its public name or URL slug', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_identity_exposure',
	);

	// Core's scheduled check runs with no user: it needs the status, not the detail.
	$detail = current_user_can( 'view_site_health_checks' );
	if ( ! $detail && ! wp_doing_cron() ) {
		return $result;
	}

	$report = keel_defaults_identity_exposure_report();
	if ( $report['total']['strong'] > 0 ) {
		$result['status'] = 'recommended';
		$result['label']  = __( 'Some accounts publish an email address as their public name or URL slug', 'keel-defaults' );
	}
	if ( $report['error'] ) {
		$result['label'] = __( 'Public names could not be checked for email addresses', 'keel-defaults' );
	}
	if ( ! $detail ) {
		return $result;
	}

	$html  = '<p>' . esc_html__( 'When a username is an email address, WordPress copies it into the public display name and builds the URL slug from it, unless someone changes them. Both then appear wherever the site names an author or commenter, publishing personal data and the login name of the account.', 'keel-defaults' ) . '</p>';
	$html .= '<p>' . esc_html__( 'An email address is fine as a private login. The fix is a public name and slug unrelated to it.', 'keel-defaults' ) . '</p>';

	if ( $report['error'] ) {
		$html .= '<p>' . esc_html__( 'The database query failed. Nothing was changed. Site Health will try again in five minutes.', 'keel-defaults' ) . '</p>';
	}

	if ( $report['total']['strong'] > 0 ) {
		$html .= '<p>' . sprintf(
			/* translators: 1: number of accounts, 2: counts by role. */
			esc_html( _n( '%1$d account shows its email address, or a slug built from it (%2$s).', '%1$d accounts show their email address, or a slug built from it (%2$s).', $report['total']['strong'], 'keel-defaults' ) ),
			(int) $report['total']['strong'],
			esc_html( keel_defaults_identity_role_counts( $report['strong'] ) )
		) . '</p>';
	}
	if ( $report['total']['weak'] > 0 ) {
		$html .= '<p>' . sprintf(
			/* translators: 1: number of accounts, 2: counts by role. */
			esc_html( _n( 'Weaker match: %1$d account uses the part of its email address before the @ as its public name or slug (%2$s). That reveals part of the address, not all of it.', 'Weaker match: %1$d accounts use the part of their email address before the @ as their public name or slug (%2$s). That reveals part of the address, not all of it.', $report['total']['weak'], 'keel-defaults' ) ),
			(int) $report['total']['weak'],
			esc_html( keel_defaults_identity_role_counts( $report['weak'] ) )
		) . '</p>';
	}

	if ( $report['named'] ) {
		$html .= '<p>' . esc_html__( 'Administrators and editors with published posts or approved comments, administrators first:', 'keel-defaults' ) . '</p><ul>';
		foreach ( $report['named'] as $entry ) {
			$what  = $entry['name'] && $entry['slug'] ? __( 'public name and URL slug', 'keel-defaults' ) : ( $entry['name'] ? __( 'public name', 'keel-defaults' ) : __( 'URL slug', 'keel-defaults' ) );
			$link  = get_edit_user_link( $entry['id'] );
			$label = esc_html( $entry['label'] );
			$html .= '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . $label . '</a>' : $label ) . ' (' . esc_html( $what ) . ')</li>';
		}
		$html .= '</ul>';
		if ( $report['more'] > 0 ) {
			$html .= '<p>' . sprintf(
				/* translators: %d: number of further accounts not listed. */
				esc_html( _n( '%d more administrator or editor with public activity is not listed.', '%d more administrators or editors with public activity are not listed.', $report['more'], 'keel-defaults' ) ),
				(int) $report['more']
			) . '</p>';
		}
	}

	if ( $report['limited'] ) {
		$html .= '<p>' . sprintf(
			/* translators: %d: candidate rows examined. */
			esc_html__( 'Partial count: this check stops after %d candidate accounts, so the real totals may be higher.', 'keel-defaults' ),
			(int) KEEL_IDENTITY_CANDIDATE_LIMIT
		) . '</p>';
	}

	$html .= keel_defaults_identity_coverage_copy();

	$result['description'] = $html;
	$result['actions']     = keel_defaults_identity_actions();
	return $result;
}

/**
 * What to do about exposed names: the Public Names setting for new and updated
 * accounts, its one-time cleanup for existing ones, and the by-hand route.
 *
 * Links appear only for people who can use them. On multisite the cleanup
 * lives in Network Admin, and the setting is decided by network policy or the
 * main site, so a subsite administrator gets the steps without the links.
 *
 * @return string HTML.
 */
function keel_defaults_identity_actions() {
	$html = sprintf(
		'<p><a href="%s">%s</a></p>',
		esc_url( admin_url( 'users.php' ) ),
		esc_html__( 'Review users', 'keel-defaults' )
	);

	if ( function_exists( 'keel_defaults_public_names_enforced' ) && keel_defaults_public_names_enforced() ) {
		$html .= '<p>' . esc_html__( 'Public Names is on, so accounts get safe public names and slugs whenever they are created or updated. Accounts that have not been saved since still show the old ones.', 'keel-defaults' ) . '</p>';
	} else {
		$setting = esc_html__( 'Turn on Public Names under Settings → Site Defaults to give accounts safe public names and slugs whenever they are created or updated.', 'keel-defaults' );
		if ( ( ! is_multisite() || is_main_site() ) && current_user_can( 'manage_options' ) ) {
			$setting = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=keel' ) ), $setting );
		}
		$html .= '<p>' . $setting . '</p>';
	}

	$cleanup = is_multisite()
		? esc_html__( 'To fix existing accounts in one pass, a Super Admin can run the one-time cleanup under Network Admin → Users → Public Names. It can also update the names on past comments.', 'keel-defaults' )
		: esc_html__( 'To fix existing accounts in one pass, run the one-time cleanup under Users → Public Names. It can also update the names on past comments.', 'keel-defaults' );
	if ( function_exists( 'keel_defaults_public_names_can_run' ) && keel_defaults_public_names_can_run() ) {
		$url     = is_multisite() ? network_admin_url( 'users.php?page=keel-public-names' ) : admin_url( 'users.php?page=keel-public-names' );
		$cleanup = sprintf( '<a href="%s">%s</a>', esc_url( $url ), $cleanup );
	}
	$html .= '<p>' . $cleanup . '</p>';

	$html .= '<p>' . esc_html__( 'To fix one account by hand, set a Nickname on its profile and choose it under "Display name publicly as". The profile screen cannot change the slug; the cleanup can. Comments already posted keep the old name unless the cleanup updates them.', 'keel-defaults' ) . '</p>';

	return $html;
}

/**
 * Say which public surfaces Keel's current settings already cover and which
 * still show these fields, so nobody reads the closed routes as a fix.
 *
 * @return string HTML.
 */
function keel_defaults_identity_coverage_copy() {
	$archives = keel_defaults_enabled( 'disable_author_archives' );
	$rest     = keel_defaults_enabled( 'restrict_rest_user_discovery' );

	$html = '<p>' . esc_html__( 'Still visible whatever Keel is set to: author names in theme bylines and the Post Author block, the names stored with logged-in comments, and the comment-author CSS class on each comment, which contains the slug.', 'keel-defaults' ) . '</p>';

	$covered = array();
	$open    = array();
	if ( $archives ) {
		$covered[] = __( 'author archives and their URLs, author names in feeds, the users sitemap and oEmbed author fields', 'keel-defaults' );
	} else {
		$open[] = __( 'author archives and their URLs, author names in feeds, the users sitemap and oEmbed author fields', 'keel-defaults' );
	}
	if ( $rest ) {
		$covered[] = __( 'the anonymous REST users list', 'keel-defaults' );
	} else {
		$open[] = __( 'the anonymous REST users list', 'keel-defaults' );
	}
	if ( $covered ) {
		/* translators: %s: list of surfaces. */
		$html .= '<p>' . esc_html( sprintf( __( 'Keel currently hides these routes: %s. That limits who can browse to the fields; it does not make them safe to publish.', 'keel-defaults' ), implode( '; ', $covered ) ) ) . '</p>';
	}
	if ( $open ) {
		/* translators: %s: list of surfaces. */
		$html .= '<p>' . esc_html( sprintf( __( 'Open on this site because the matching Keel setting is off: %s.', 'keel-defaults' ), implode( '; ', $open ) ) ) . '</p>';
	}
	return $html;
}

/**
 * Admin-ajax endpoint for the async test. Denied callers get nothing back.
 *
 * @return void
 */
function keel_defaults_identity_exposure_ajax() {
	check_ajax_referer( 'health-check-site-status' );
	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		wp_send_json_error( array(), 403 );
		return;
	}
	wp_send_json_success( keel_defaults_identity_exposure_health() );
}
