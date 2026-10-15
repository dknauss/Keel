<?php
/**
 * Core backports: presentation.
 *
 * Loaded by backports.php; public callback names and behavior are unchanged.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Site Health test, asynchronously.
 *
 * Async for the same reason core's WordPress.org communication test is: a direct
 * test runs during the page render, so a cold cache would pause Site Health for
 * the length of the request. Async, the page paints and the result arrives.
 *
 * The test name matters more than it looks. Core's JS builds the action as
 * `'health-check-' + test.replace( '_', '-' )`, replacing only the first
 * underscore — so `keel_backport` gives `health-check-keel-backport`, and a name
 * with two underscores would produce an action nothing is listening on.
 *
 * @param array<array-key,mixed> $tests Registered tests, including third-party entries.
 * @return array<array-key,mixed>
 * @phpstan-return array<array-key,mixed>&array{async:array<array-key,mixed>&array{keel_backport:KeelBackportTestRegistration}}
 */
function keel_defaults_register_backport_test( $tests ) {
	$tests['async']['keel_backport'] = array(
		'label'             => __( 'Security patch status', 'keel-defaults' ),
		'test'              => 'keel_backport',
		'async_direct_test' => 'keel_defaults_backport_test',
	);

	return $tests;
}

/**
 * Report whether this site is running a version with known vulnerabilities.
 *
 * Deliberately distinguishes "behind" from "vulnerable". Being outdated is a
 * legitimate, deliberate state; being insecure is not. Core's update nag cannot
 * tell the difference and reports both identically.
 *
 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string} Site Health result.
 * @phpstan-return KeelBackportHealthResult
 */
function keel_defaults_backport_test() {
	$result = keel_defaults_backport_verdict();
	$notice = keel_defaults_backport_result_markup();

	if ( '' !== $notice ) {
		$result['actions'] = $notice . $result['actions'];
	}

	// Appended here rather than on site_status_test_result, because that filter
	// only fires through perform_test() — the async AJAX path answers directly
	// and would silently omit it.
	$ladder = keel_defaults_ladder_markup();

	if ( '' !== $ladder ) {
		$result['description'] .= $ladder;
	}

	return $result;
}

/**
 * What the panel may claim about a scheduled install.
 *
 * `policy && operable` establishes only that minor updates are permitted and the
 * updater can run. It does not establish what core would take: a site that also
 * permits majors gets the highest offer, not the nearest, so the same panel could
 * promise the same-line patch was scheduled directly above a ladder marking 7.1 as
 * the release WordPress would actually install.
 *
 * The claim is therefore made only when core's own selection is the patch.
 *
 * @param array        $state    Result of keel_defaults_minor_update_state().
 * @param string|false $selected Result of keel_defaults_ladder_selection().
 * @param string       $tip      The same-line patch.
 * @return string Escaped sentence.
 * @phpstan-param KeelScheduleState $state
 */
function keel_defaults_schedule_statement( array $state, $selected, $tip ) {
	if ( ! $state['policy'] || ! $state['operable'] ) {
		return '';
	}

	if ( is_string( $selected ) && '' !== $selected && $selected === $tip ) {
		return esc_html__( 'The configured policy permits minor updates, the updater looks operable, and this is the release WordPress would install, so it should arrive on a scheduled check. That is not a guarantee: WP-Cron still has to run.', 'keel-defaults' );
	}

	if ( is_string( $selected ) && '' !== $selected ) {
		return sprintf(
			/* translators: 1: release core would install, 2: the same-line patch. */
			esc_html__( 'Automatic updates are running here, but WordPress would install %1$s rather than %2$s: it takes the highest release the settings permit, not the nearest. This patch will not arrive on its own. If you want it, you\'ll need to install it manually.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		);
	}

	if ( false === $selected ) {
		return esc_html__( 'Automatic updates appear to be available, but Keel could not determine which release WordPress would install next, so nothing here establishes that this patch is scheduled.', 'keel-defaults' );
	}

	return esc_html__( 'Automatic updates appear to be available, but WordPress is not selecting a release to install, so this patch is not scheduled.', 'keel-defaults' );
}

/**
 * The sentence printed beneath the ladder, for a given selection state.
 *
 * Pure, and separate from the markup, because the defect it exists to prevent lives
 * between two sentences rather than inside either. The route statement and this note
 * are concatenated into one rendered panel: the blocked route said clearing the
 * blocker comes first while this said any rung could still be installed deliberately,
 * and each was correct read alone.
 *
 * @param string       $selection Result of keel_defaults_selection_state().
 * @param string|false $selected  Result of keel_defaults_ladder_selection().
 * @param array        $state     Result of keel_defaults_minor_update_state().
 * @return string Escaped sentence.
 * @phpstan-param KeelBlockerState $state
 */
function keel_defaults_ladder_note( $selection, $selected, array $state ) {
	if ( 'blocked' === $selection ) {
		/*
		 * Deliberately does not repeat the blocker list. It is stated in full above,
		 * and this list is appended directly beneath it.
		 *
		 * Whether Keel offers a deliberate install from here is the installer's answer,
		 * so this asks it. Only the installer's refusals close that route. A switched-off
		 * automatic updater or an earlier critical failure also produce this state, and
		 * the Install button beneath still works for both.
		 *
		 * A refusal is a statement about Keel, not about the site. DISALLOW_FILE_MODS
		 * does stop WP-CLI as well; a checkout or a web-request credentials problem
		 * often does not, and a deployment workflow may be exactly how this site is
		 * meant to be updated. Claiming the release cannot be installed at all
		 * overstated what this screen can know.
		 */
		if ( keel_defaults_installer_refusals( $state['blockers'] ) ) {
			return esc_html__( 'None of these will install on their own, because the updater cannot act here. Keel will not offer a deliberate install from this screen until that is cleared; a deployment workflow or WP-CLI may still be able to perform the update.', 'keel-defaults' );
		}

		return esc_html__( 'None of these will install on their own while automatic updates are held back here. That does not prevent a deliberate install.', 'keel-defaults' );
	}

	if ( 'unknown' === $selection ) {
		return esc_html__( 'Keel could not determine which of these WordPress would install.', 'keel-defaults' );
	}

	if ( 'scheduled' === $selection ) {
		return sprintf(
			/* translators: %s: version WordPress would install. */
			esc_html__( 'WordPress would install %s and skip the rest. It does not step through them one version line at a time.', 'keel-defaults' ),
			'<code>' . esc_html( $selected ) . '</code>'
		);
	}

	/*
	 * Not "this site's update settings decline all of them". A compatibility floor on
	 * every offer — a PHP or MySQL requirement none of them meets — produces exactly
	 * this empty selection, and changing settings would not help. The reason is not
	 * knowable from here, so it is not named, and no deliberate install is promised
	 * either: a requirement none of them meets would refuse that too.
	 */
	return esc_html__( 'None of these will install on their own. Something is declining every one of them, which may be this site\'s update settings or a requirement the offers do not meet. If it is a requirement, Keel will refuse a deliberate install for the same reason.', 'keel-defaults' );
}

/**
 * The verdict itself, without the ladder.
 *
 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string} Site Health result.
 * @phpstan-return KeelBackportHealthResult
 */
function keel_defaults_backport_verdict() {
	$status  = keel_defaults_version_status();
	$version = keel_defaults_wp_version();
	$tip     = keel_defaults_branch_tip();

	$result = array(
		'label'       => __( 'This version of WordPress core is not currently flagged as insecure', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_backport',
	);

	if ( 'unknown' === $status ) {
		$result['status'] = 'recommended';
		$result['label']  = __( 'Patch status could not be determined', 'keel-defaults' );

		// Three quite different situations produce 'unknown', and saying the
		// wrong one sends people to debug something that is not wrong.
		if ( false !== strpos( $version, '-' ) ) {
			$reason = esc_html__( 'This is a development build, which WordPress.org does not classify.', 'keel-defaults' );
		} elseif ( empty( keel_defaults_stable_check() ) ) {
			$reason = esc_html__( 'Keel could not reach WordPress.org, or the response was not usable. That may be a passing outage rather than anything about this site.', 'keel-defaults' );
		} else {
			$reason = esc_html__( 'WordPress.org does not list this exact version.', 'keel-defaults' );
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: WordPress version, 2: explanation. */
			esc_html__( 'Keel could not determine whether %1$s has known vulnerabilities. %2$s', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			$reason
		) . '</p>';

		return $result;
	}

	if ( 'insecure' === $status ) {
		$result['status'] = 'critical';
		$result['label']  = __( 'This version of WordPress has known vulnerabilities', 'keel-defaults' );

		if ( '' === $tip ) {
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: WordPress version. */
				esc_html__( 'WordPress.org classifies %s as insecure, and every release on this line is flagged the same way, so there is no nearer release to move to. No setting will produce one. The only remedy is moving to a line that is still maintained.', 'keel-defaults' ),
				'<code>' . esc_html( $version ) . '</code>'
			) . '</p>';

			return $result;
		}

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress.org classifies %1$s as %2$s: it has publicly known vulnerabilities. The nearest release without known vulnerabilities is %4$s, on your own %3$s line.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<strong>' . esc_html__( 'insecure', 'keel-defaults' ) . '</strong>',
			'<code>' . esc_html( keel_defaults_version_line( $version ) ) . '</code>',
			'<code>' . esc_html( $tip ) . '</code>'
		) . '</p>';

		$state = keel_defaults_minor_update_state();

		if ( $state['policy'] && $state['operable'] ) {
			// Not from policy alone. policy && operable says the updater can run; it
			// does not say what core would take, and on a site that also permits
			// majors core takes the highest offer and steps over this patch. Promising
			// otherwise contradicted the ladder printed directly below.
			$result['description'] .= '<p>' . keel_defaults_schedule_statement( $state, keel_defaults_ladder_selection(), $tip ) . '</p>';
		} elseif ( $state['policy'] ) {
			$result['description'] .= '<p><strong>' . esc_html__( 'The policy permits minor updates, but this patch cannot currently install automatically.', 'keel-defaults' ) . '</strong> '
				. wp_kses( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ), array( 'code' => array() ) ) . '.</p>';
		} else {
			// This is the one place in the panel that states the cause in
			// full. The ladder and the actions below name the kind of problem
			// instead of repeating the sentence: all three are concatenated
			// into a single Site Health panel, and each was written to stand
			// alone, so a real 6.9.5 site saw the same constant named three
			// times and "this will not arrive on its own" said five ways.
			$result['description'] .= '<p><strong>' . sprintf(
				/* translators: %s: patched version. */
				esc_html__( '%s will not install by itself.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>'
			) . '</strong> '
				. ( $state['operable']
					? esc_html__( 'Minor updates are switched off on this site, so WordPress will not fetch it.', 'keel-defaults' )
					: sprintf(
						/* translators: %s: reasons the updater cannot run. */
						esc_html__( 'Two things are stopping it: minor updates are switched off, and %s.', 'keel-defaults' ),
						wp_kses( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ), array( 'code' => array() ) )
					)
				) . '</p><p>'
				. esc_html__( 'WordPress has no security-only update setting. Security fixes ship inside ordinary maintenance releases, so switching off minor updates switches off security fixes with them — there is no way to keep one without the other.', 'keel-defaults' ) . '</p>';
		}

		$result['actions'] = keel_defaults_backport_actions( $tip );

		return $result;
	}

	if ( 'outdated' === $status ) {
		// 'recommended', not 'good'. Staying on a maintained older line is a
		// legitimate choice and this must not nag as though it were a fault.
		// But it is not a settled state either: lines stop being patched, and
		// when yours does, this check flips to critical with no patch to offer.
		// Reporting that as 'good' would hide a decision worth revisiting.
		$result['status']      = 'recommended';
		$result['label']       = __( 'This version of WordPress core is not currently flagged as insecure', 'keel-defaults' );
		$result['description'] = '<p>' . sprintf(
			/* translators: 1: current version, 2: latest version. */
			esc_html__( '%1$s is not currently flagged as insecure by WordPress.org. The current release is %2$s.', 'keel-defaults' ),
			'<code>' . esc_html( $version ) . '</code>',
			'<code>' . esc_html( keel_defaults_latest_version() ) . '</code>'
		) . '</p>';
		$result['description'] .= '<p>' . sprintf(
			/* translators: %s: release line, such as 7.0. */
			esc_html__( 'That is not a support guarantee. Only the current release is actively supported. Fixes for older lines are backported where feasible, and the %s line will eventually be retired. This check covers WordPress core only — not plugins, themes or PHP.', 'keel-defaults' ),
			esc_html( keel_defaults_version_line( $version ) )
		) . '</p>';

		return $result;
	}

	$result['description'] = '<p>' . sprintf(
		/* translators: %s: WordPress version. */
		esc_html__( '%s is the current release.', 'keel-defaults' ),
		'<code>' . esc_html( $version ) . '</code>'
	) . '</p>';

	return $result;
}

/**
 * Offer a way to act, preferring the mechanism core already has.
 *
 * Two routes, in order of preference:
 *
 * 1. Switch minor auto-updates back on. This fixes the cause rather than the
 *    symptom: the patch installs itself, and so does the next one.
 * 2. Install the patch now, once, through core's own upgrader.
 *
 * Neither happens without a click. Keel's job is to make the decision visible,
 * not to make it.
 *
 * @param string $tip Target version.
 * @return string Markup.
 */
function keel_defaults_backport_actions( $tip ) {
	if ( ! current_user_can( 'update_core' ) ) {
		return '';
	}

	$out = '';

	$state              = keel_defaults_minor_update_state();
	$filesystem_blocked = ! empty( keel_defaults_installer_refusals( $state['blockers'] ) );

	if ( $filesystem_blocked ) {
		// The blockers are listed in the verdict this is appended to, so
		// this says what to do about them rather than naming them again.
		$out .= '<p class="description">'
			. esc_html__( 'Start with what is blocking this patch, above. Until it passes those checks, no change to the update policy will make any difference.', 'keel-defaults' )
			. '</p>';
	} elseif ( ! $state['policy'] ) {
		// Only offer the button when the option is genuinely what decides it.
		// Otherwise it would write a value that something downstream overrides,
		// and report success for a change with no effect.
		if ( 'option' === $state['owner'] ) {
			$out .= sprintf(
				'<p><a class="button button-primary" href="%s">%s</a></p><p class="description">%s</p>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=keel_defaults_enable_minor_updates' ),
						'keel_defaults_enable_minor_updates'
					)
				),
				esc_html__( 'Turn automatic security updates back on', 'keel-defaults' ),
				esc_html__( 'Recommended. This patch, and every future one on this release line, will install without being asked for.', 'keel-defaults' )
			);
		} elseif ( 'keel' === $state['owner'] ) {
			$out .= '<p>' . sprintf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=keel-defaults#updates' ) ),
				esc_html__( 'Change the core update policy in Keel', 'keel-defaults' )
			) . '</p><p class="description">'
			. esc_html__( 'Keel is deciding this, not the stored WordPress option. Setting the option directly would have no effect.', 'keel-defaults' )
			. '</p>';
		} elseif ( 'constant' === $state['owner'] ) {
			$out .= '<p class="description">' . sprintf(
				/* translators: 1: constant name, 2: configuration file name. */
				esc_html__( 'The %1$s constant is deciding this, and Keel will not override it. Change it in %2$s.', 'keel-defaults' ),
				'<code>WP_AUTO_UPDATE_CORE</code>',
				'<code>wp-config.php</code>'
			) . '</p>';
		} else {
			$out .= '<p class="description">'
			. esc_html__( 'Another plugin is filtering this decision, so neither the stored option nor Keel governs it. Whatever registered that filter has to change.', 'keel-defaults' )
			. '</p>';
		}
	}

	$offer   = keel_defaults_updates_screen_offer( $tip );
	$screen  = $offer['state'];
	$install = keel_defaults_backport_install_button( $tip, $screen, $state );

	if ( 'visible' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'update-core.php' ) ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
				esc_html( $tip )
			)
		);
	} elseif ( 'hidden' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( admin_url( 'update-core.php' ) ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Install %s now from the Updates screen', 'keel-defaults' ),
				esc_html( $tip )
			),
			sprintf(
				/* translators: 1: target version, 2: the "Show hidden updates" link on the Updates screen. */
				esc_html__( 'Someone dismissed %1$s on this site, so the Updates screen keeps it out of the way until you select %2$s.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>',
				'<strong>' . esc_html__( 'Show hidden updates', 'keel-defaults' ) . '</strong>'
			)
		);
	} elseif ( 'unknown' === $screen ) {
		$out .= sprintf(
			'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( admin_url( 'update-core.php' ) ),
			esc_html__( 'Check the Updates screen', 'keel-defaults' ),
			sprintf(
				/* translators: %s: target version. */
				esc_html__( 'This site has not checked WordPress.org for core updates yet, so Keel cannot say whether the Updates screen is offering %s. Opening that screen checks, and answers it.', 'keel-defaults' ),
				'<code>' . esc_html( $tip ) . '</code>'
			)
		);
	} else {
		$code = '<code>' . esc_html( $tip ) . '</code>';

		$lead = keel_defaults_backport_lead( $tip, $offer['manual'] );

		// Which release cron would install is core's answer, not one to derive
		// from policy. policy && operable says updates can run; it does not say
		// what they would pick. On a site that also permits majors, core takes
		// the highest rung — so this promised the patch while the ladder
		// directly above marked a different release as the winner.
		$route = keel_defaults_backport_route(
			$tip,
			$state,
			keel_defaults_ladder_selection(),
			null !== keel_defaults_offer_for_version( $tip )
		);

		// Every branch of the route ends by naming the command line, which was
		// the only deliberate route when those sentences were written. There is
		// a button now, directly beneath this paragraph, and a sentence telling
		// someone to open a terminal while one sits under it reads as though
		// Keel does not know its own feature. Said once, here, rather than
		// rewriting all seven branches — the existing sentences are still true,
		// they are just no longer the whole list.
		if ( '' !== $install ) {
			$route .= ' ' . sprintf(
				/* translators: %s: target version. */
				esc_html__( 'Or install %s now, below.', 'keel-defaults' ),
				$code
			);
		}

		$out .= '<p class="description">' . $lead . ' ' . $route . '</p>';
	}

	$out .= $install;

	return $out;
}

/**
 * Why this release has not appeared, for a site the Updates screen is not offering it on.
 *
 * It used to say "the Updates screen will not offer 6.8.8". That was true when it was
 * written and U1 made it false: Keel renders the offer on that screen now. What still
 * will not list the patch is WordPress's own update list, which drops every offer
 * flagged for automatic installation — a different claim, and the one worth making,
 * because it explains why nobody has seen this release rather than complaining about a
 * screen Keel has just fixed.
 *
 * What that list is offering instead comes from the screen, not from stable-check. The
 * two caches refresh independently, so naming stable-check's latest release can name a
 * version the screen is not showing. When it is offering nothing, no substitute is
 * claimed.
 *
 * @param string $tip    Patched release on this line.
 * @param string $manual Version the Updates screen is offering, or ''.
 * @return string Escaped sentence.
 */
function keel_defaults_backport_lead( $tip, $manual ) {
	$code = '<code>' . esc_html( $tip ) . '</code>';

	if ( '' !== $manual && $manual !== $tip ) {
		return sprintf(
			/* translators: 1: version WordPress own update list is offering, 2: patched release on this line. */
			esc_html__( 'WordPress\'s own update list is offering %1$s and will not include %2$s. Keel adds it to the Updates screen.', 'keel-defaults' ),
			'<code>' . esc_html( $manual ) . '</code>',
			$code
		);
	}

	return sprintf(
		/* translators: %s: patched release on this line. */
		esc_html__( 'WordPress\'s own update list does not include %s. Keel adds it to the Updates screen.', 'keel-defaults' ),
		$code
	);
}

/**
 * The marker beside a rung, if any.
 *
 * A selection on a site whose updater cannot act is still a fact about what
 * core would pick — it is just not a fact about what will happen. Saying
 * "would install" there contradicts the verdict in the same panel, so the
 * blocked case describes the choice without promising the outcome.
 *
 * @param string       $selection Result of keel_defaults_selection_state().
 * @param string|false $selected  Version core selected.
 * @param string       $version   Version of the rung being rendered.
 * @return string Escaped markup, or ''.
 */
function keel_defaults_ladder_rung_mark( $selection, $selected, $version ) {
	if ( ! is_string( $selected ) || $selected !== $version ) {
		return '';
	}

	/*
	 * "WordPress", never "core". They are the same actor to a site owner, and the
	 * ladder used to name it both ways in adjacent rows.
	 */
	if ( 'blocked' === $selection ) {
		// Conditional on purpose: core would pick this, and cannot act. Saying it
		// installs this contradicts the verdict in the same panel.
		return esc_html__( 'WordPress would pick this', 'keel-defaults' );
	}

	if ( 'scheduled' !== $selection ) {
		return '';
	}

	return esc_html__( 'WordPress installs this', 'keel-defaults' );
}

/**
 * Show an admin notice when the running version has known vulnerabilities.
 *
 * Deliberately narrow. It fires only for `insecure` — never for `outdated`,
 * which is a legitimate place to be — and only for users who could act on it.
 * A notice that cannot be acted on is just noise wearing a warning's clothes.
 *
 * It is dismissible per user, and the dismissal is keyed to the version, so
 * dismissing it on 6.8.7 does not hide it again on 6.8.9. That is the failure
 * mode of most "remind me later" notices: they are dismissed once, during the
 * one incident that mattered, and never seen again.
 *
 * @return void
 */
function keel_defaults_backport_notice() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// Update-related screens already say their piece; do not stack on them.
	if ( $screen && in_array( $screen->id, array( 'update-core', 'update-core-network', 'site-health' ), true ) ) {
		return;
	}

	// Read-only: the notice never triggers a fetch. If nothing is cached yet the
	// notice simply does not appear this request, and Site Health does the
	// fetching on a screen where a slow response is expected.
	if ( ! is_array( get_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT ) ) ) {
		return;
	}

	if ( 'insecure' !== keel_defaults_version_status() ) {
		return;
	}

	$version = keel_defaults_wp_version();

	// One key holding the version it was dismissed at, rather than one key per
	// version. Same behaviour — dismissing on 6.8.7 does not hide it on 6.8.9 —
	// but it leaves a single row to remove on uninstall instead of an unbounded
	// set that only a LIKE query could find.
	if ( get_user_meta( get_current_user_id(), 'keel_backport_dismissed', true ) === $version ) {
		return;
	}

	$tip   = keel_defaults_branch_tip();
	$state = keel_defaults_minor_update_state();
	$link  = __( 'See what is preventing it — Site Health', 'keel-defaults' );

	if ( '' === $tip ) {
		$body = sprintf(
			/* translators: %s: WordPress version. */
			esc_html__( 'WordPress %s has known vulnerabilities, and every release on this line is flagged the same way. Moving to a maintained line is the only remedy.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>'
		);
	} elseif ( $state['policy'] && $state['operable'] ) {
		/*
		 * Deliberately no schedule promise. This renders on every admin screen, so it
		 * cannot afford find_core_auto_update() to learn what core would take — and
		 * without that it cannot know whether this patch is the release that would
		 * arrive. Site Health has the answer and the room to explain it.
		 */
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without them is %2$s, on this same line. Automatic updates are on, so a fix should install on its own, possibly a newer release than %2$s, depending on your update settings.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
		$link = __( 'Check which release will install — Site Health', 'keel-defaults' );
	} elseif ( ! $state['operable'] ) {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version, 3: reasons. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without known vulnerabilities is %2$s, on this same line, but it cannot currently install automatically: %3$s.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>',
			wp_kses( implode( '; ', keel_defaults_blocker_texts( $state['blockers'] ) ), array( 'code' => array() ) )
		);
	} else {
		$body = sprintf(
			/* translators: 1: current version, 2: patched version. */
			esc_html__( 'WordPress %1$s has known vulnerabilities. The nearest release without known vulnerabilities is %2$s, on this same line, but minor updates are switched off on this site, so it will not arrive on its own.', 'keel-defaults' ),
			'<strong>' . esc_html( $version ) . '</strong>',
			'<strong>' . esc_html( $tip ) . '</strong>'
		);
	}

	printf(
		'<div class="notice notice-error is-dismissible keel-defaults-backport-notice" data-keel-version="%s"><p>%s</p><p><a href="%s">%s</a></p></div>',
		esc_attr( $version ),
		wp_kses_post( $body ),
		esc_url( admin_url( 'site-health.php' ) ),
		esc_html( $link )
	);
}

/**
 * Make the dismissal stick, rather than only hiding the notice until reload.
 *
 * @return void
 */
function keel_defaults_backport_notice_script() {
	if ( ! current_user_can( 'update_core' ) ) {
		return;
	}

	$script = sprintf(
		'document.addEventListener("click",function(e){' .
			'var b=e.target.closest(".keel-defaults-backport-notice .notice-dismiss");if(!b)return;' .
			'var n=b.closest(".keel-defaults-backport-notice");' .
			'var d=new FormData();d.append("action","keel_defaults_dismiss_backport");' .
			'd.append("nonce",%s);d.append("version",n.dataset.keelVersion);' .
			'fetch(%s,{method:"POST",body:d,credentials:"same-origin"});});',
		wp_json_encode( wp_create_nonce( 'keel_defaults_dismiss_backport' ) ),
		wp_json_encode( admin_url( 'admin-ajax.php' ) )
	);

	wp_add_inline_script( 'common', $script );
}

/**
 * Render the ladder, marking the rung WordPress will take.
 *
 * @return string Markup, or '' when there is nothing to show.
 */
function keel_defaults_ladder_markup() {
	$rungs = keel_defaults_update_ladder();

	if ( count( $rungs ) < 2 ) {
		return '';
	}

	$selected  = keel_defaults_ladder_selection();
	$state     = keel_defaults_minor_update_state();
	$selection = keel_defaults_selection_state( $state, $selected );
	$rows      = '';

	/*
	 * The rung a reader is actually looking for. Marking only core's selection
	 * answers "what will happen" and leaves "what do I want" to be worked out from
	 * the version numbers — which is the whole confusion this panel exists to end.
	 */
	$target = keel_defaults_branch_tip();

	foreach ( $rungs as $rung ) {
		$kind = $rung['same_line']
			? __( 'security and maintenance, same release line', 'keel-defaults' )
			: __( 'new release line', 'keel-defaults' );

		if ( $rung['delta'] ) {
			$kind .= __( ' — a delta package is available', 'keel-defaults' );
		}

		$is_target = ( '' !== $target && $target === $rung['version'] );
		$core_mark = keel_defaults_ladder_rung_mark( $selection, $selected, $rung['version'] );

		/*
		 * Composed, not combined. Both marks can land on one rung — the release a
		 * reader wants is also the one WordPress would take — and that used to be a
		 * fourth string spelling out both facts in one sentence. Joining the two short
		 * labels says the same thing, drops a string, and removes the branch that
		 * existed only to hold it.
		 *
		 * The panel above has already established that this is the nearest release on
		 * the site's own line, so the rung does not repeat it.
		 */
		$labels = array();

		if ( $is_target ) {
			$labels[] = esc_html__( 'security fix', 'keel-defaults' );
		}

		if ( '' !== $core_mark ) {
			$labels[] = $core_mark;
		}

		// One arrow, however many labels. rung_mark() returns the label alone for
		// exactly this reason: when it carried its own arrow, a rung that was both the
		// fix and core's choice printed two of them.
		$marks = empty( $labels )
			? ''
			: ' <strong>' . esc_html( '←' ) . ' ' . implode( ' · ', $labels ) . '</strong>';

		$rows .= sprintf(
			'<li><code>%1$s</code> — %2$s%3$s</li>',
			esc_html( $rung['version'] ),
			esc_html( $kind ),
			$marks
		);
	}

	$note = keel_defaults_ladder_note( $selection, $selected, $state );

	// Always at least two rungs: the markup returns early below that, so no
	// plural handling is needed.
	return '<p>' . esc_html(
		sprintf(
			/* translators: %d: number of releases offered. */
			__( 'WordPress.org is offering this site %d releases. It will install at most one of them, and it picks the highest your settings allow rather than the nearest:', 'keel-defaults' ),
			count( $rungs )
		)
	)
		. '</p><ul>' . $rows . '</ul><p class="description">' . $note . '</p>';
}
