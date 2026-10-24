<?php
/**
 * Conflict notice presentation and per-user dismissal handling.
 *
 * Loaded by conflicts.php. Bootstrap retains registration and timing.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fingerprint of everything the notice reports, both halves.
 *
 * Dismissing has to mean "I have seen this", and what there is to see is the
 * attributable overlaps *and* the settings that are not taking effect. Hashing
 * only the overlaps meant a dismissal taken while two plugins were contesting a
 * hook also silenced a later, unrelated divergence — and a site with nothing but
 * divergences produced an empty fingerprint, which no dismissal could ever match.
 *
 * Divergences go in under a key no hook can occupy, so the two cannot be
 * confused for one another in the hash.
 *
 * @return string
 */
function keel_defaults_notice_fingerprint() {
	$state       = keel_defaults_competing_plugins();
	$divergences = keel_defaults_policy_divergences();

	if ( ! empty( $divergences ) ) {
		$state['keel:divergence'] = array_keys( $divergences );
	}

	$unnamed = keel_defaults_unattributed_hooks();

	if ( ! empty( $unnamed ) ) {
		$state['keel:unattributed'] = $unnamed;
	}

	return keel_defaults_conflicts_fingerprint( $state );
}

/**
 * Where the conflict notice is shown, and whether it can be dismissed there.
 *
 * The plugins screen is where somebody lands the moment they activate a
 * competing plugin, and Keel's settings screen is where they would go to do
 * something about it. On both, the notice is the point of the visit rather than
 * an interruption, so there is nothing to dismiss — hiding it there would hide
 * the reason for being there.
 *
 * The dashboard is daily-driver space, where a banner that cannot be dismissed
 * is an obstruction rather than information.
 *
 * @return array<string, bool> Screen id => whether dismissal is offered.
 */
function keel_defaults_conflict_notice_screens() {
	/**
	 * Filters notice placement and dismissal availability.
	 *
	 * @param array<string,bool> $screens Screen ID => whether dismissal is offered.
	 */
	return apply_filters(
		'keel_conflict_notice_screens',
		array(
			'plugins'            => false,
			'settings_page_keel' => false,
			'dashboard'          => true,
		)
	);
}

/**
 * Print the notice when enabled for this screen and a conflict is present.
 *
 * @return void
 */
function keel_defaults_render_conflicts_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen  = get_current_screen();
	$screens = keel_defaults_conflict_notice_screens();
	$id      = isset( $screen->id ) ? $screen->id : '';

	if ( ! isset( $screens[ $id ] ) ) {
		return;
	}

	$conflicts   = keel_defaults_competing_plugins();
	$divergences = keel_defaults_policy_divergences();
	$unnamed     = keel_defaults_unattributed_hooks();

	if ( empty( $conflicts ) && empty( $divergences ) && empty( $unnamed ) ) {
		return;
	}

	$dismissible = $screens[ $id ];
	$fingerprint = keel_defaults_notice_fingerprint();

	if ( $dismissible && get_user_meta( get_current_user_id(), 'keel_conflicts_dismissed', true ) === $fingerprint ) {
		return;
	}

	$plugins = array();
	$themes  = array();
	foreach ( $conflicts as $hook_plugins ) {
		foreach ( $hook_plugins as $plugin ) {
			if ( 0 === strpos( $plugin, KEEL_DEFAULTS_THEME_PREFIX ) ) {
				$themes[ substr( $plugin, strlen( KEEL_DEFAULTS_THEME_PREFIX ) ) ] = true;
			} else {
				$plugins[ $plugin ] = true;
			}
		}
	}

	$health = admin_url( 'site-health.php' );

	/*
	 * A divergence outranks an overlap, so it goes first and sets the notice
	 * colour. An overlap says two plugins are registered on the same hook, which
	 * may be perfectly fine; a divergence says a setting configured on this site
	 * is measurably not doing what it says. Only one of those needs acting on.
	 */
	$class = empty( $divergences ) ? 'notice-info' : 'notice-warning';
	?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
		<?php if ( ! empty( $divergences ) ) : ?>
		<p>
			<strong>
			<?php
			/* translators: %s: comma-separated setting names, each followed by its hook. */
			$not_taking_effect = _n(
				'A Keel setting is not taking effect on this site: %s',
				'Some Keel settings are not taking effect on this site: %s',
				count( $divergences ),
				'keel-defaults'
			);

			printf(
				esc_html( $not_taking_effect ),
				keel_defaults_hook_setting_names( array_keys( $divergences ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper.
			);
			?>
			</strong>
		</p>
		<p>
			<?php
			esc_html_e( 'These were last seen producing a different value from the one configured here, so something else on this site is deciding them.', 'keel-defaults' );
			echo ' ';
			// Same rule as the Site Health test: claim nobody can be named only
			// when no plugin on these hooks was.
			if ( array() === array_diff_key( $divergences, $conflicts ) ) {
				esc_html_e( 'The plugins named below use the same hooks and are the place to look.', 'keel-defaults' );
			} else {
				esc_html_e( 'Keel cannot always say what: a plugin that switches a feature on or off using one of WordPress\'s own helper functions leaves nothing to trace it back by. Your active plugins are the place to look.', 'keel-defaults' );
			}
			?>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $plugins ) ) : ?>
		<p>
			<strong>
			<?php
			/* translators: %s: comma-separated plugin names. */
			$heading = _n(
				'Another plugin may influence some of the same settings as Keel: %s',
				'Other plugins may influence some of the same settings as Keel: %s',
				count( $plugins ),
				'keel-defaults'
			);

			printf(
				esc_html( $heading ),
				esc_html( implode( ', ', array_map( 'keel_defaults_plugin_name', array_keys( $plugins ) ) ) )
			);
			?>
			</strong>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $themes ) ) : ?>
		<p>
			<strong>
			<?php
			printf(
				/* translators: %s: theme name, or a child theme and its parent, comma-separated. */
				esc_html__( 'Your theme may influence some of the same settings as Keel: %s', 'keel-defaults' ),
				esc_html( implode( ', ', array_map( 'keel_defaults_theme_name', array_keys( $themes ) ) ) )
			);
			?>
			</strong>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $plugins ) || ! empty( $themes ) ) : ?>
		<p>
				<?php esc_html_e( 'They add filters on the same WordPress hooks as some Keel settings, where the last plugin to run can override the others. Check that their settings and Keel\'s are set up to achieve complementary outcomes. Sharing a hook is insufficient evidence of a conflict.', 'keel-defaults' ); ?>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $unnamed ) ) : ?>
		<p>
			<?php
			/* translators: 1: number of settings, 2: opening strong tag, 3: closing strong tag. */
			$untraceable = _n(
				'%1$s of %2$sKeel%3$s\'s settings is also affected by code that cannot be traced to any plugin.',
				'%1$s of %2$sKeel%3$s\'s settings are also affected by code that cannot be traced to any plugin.',
				count( $unnamed ),
				'keel-defaults'
			);

			printf(
				esc_html( $untraceable ),
				esc_html( number_format_i18n( count( $unnamed ) ) ),
				'<strong>',
				'</strong>'
			);
			?>
			<?php esc_html_e( 'A plugin that switches a feature on or off using one of WordPress\'s own helper functions leaves nothing behind to identify it.', 'keel-defaults' ); ?>
		</p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $health ); ?>"><?php esc_html_e( 'See which settings may be contested under Site Health', 'keel-defaults' ); ?></a>
			<?php if ( $dismissible ) : ?>
				&nbsp;|&nbsp;
				<a class="keel-dismiss-conflicts" href="<?php echo esc_url( wp_nonce_url( admin_url( 'index.php?keel-dismiss-conflicts=1' ), 'keel-dismiss-conflicts' ) ); ?>">
					<?php esc_html_e( 'Dismiss until this changes', 'keel-defaults' ); ?>
				</a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}

/**
 * Record a dismissal.
 *
 * Stores the fingerprint of what was on screen, not a flag, so the notice comes
 * back when the conflicts change rather than staying gone forever. Per user,
 * because dismissing is one person saying they have read it.
 *
 * Handled here rather than with core's `is-dismissible` class, which only hides
 * the notice for the current page view and stores nothing.
 *
 * @return void
 */
function keel_defaults_handle_conflicts_dismissal() {
	if ( ! isset( $_GET['keel-dismiss-conflicts'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked immediately below; this is the presence test that decides whether to look.
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'keel-dismiss-conflicts' );

	update_user_meta(
		get_current_user_id(),
		'keel_conflicts_dismissed',
		keel_defaults_notice_fingerprint()
	);

	wp_safe_redirect( admin_url( 'index.php' ) );
	exit;
}
