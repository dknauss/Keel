<?php
/**
 * Read-only inventory of the Abilities API authorization filters.
 *
 * WordPress 7.1 added two filters that decide who may run an ability.
 * `wp_ability_permission_result` has the last word on every permission check,
 * after the ability's own `permission_callback`. `wp_pre_execute_ability` runs
 * before everything else in `WP_Ability::execute()`, and any value other than the
 * per-call sentinel skips validation, the permission check and the ability itself,
 * for REST, WP-CLI and direct callers alike.
 *
 * Keel sets no policy on either, so this is not an overlap report: there is no
 * Keel registration to contest. It is an inventory, and on these hooks one
 * callback is the finding, because one is enough to change an authorization
 * decision. Many are intentional — a two-factor gate, a cache, maintenance mode —
 * so a registration is something to review, not a fault.
 *
 * It reuses the attribution layer and nothing else. No callback is invoked: the
 * result depends on ability, user, input and channel, and the only way to learn
 * it would be to run other plugins' code, which Keel withdrew in 0.5.1. Design,
 * limits and the options not taken are in
 * docs/plans/abilities-authorization-inventory.md.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The inventoried hooks, each with the kind of decision it can change.
 *
 * @return array<string, string> Hook => `permission` or `short_circuit`.
 */
function keel_defaults_ability_authorization_hooks() {
	return array(
		'wp_ability_permission_result' => 'permission',
		'wp_pre_execute_ability'       => 'short_circuit',
	);
}

/**
 * Whether core has the filters this inventory covers.
 *
 * Both arrived in 7.1 together with `WP_Filter_Sentinel`, which core loads from
 * plugin.php on every request. Abilities on 6.9 and 7.0 have neither filter, and
 * a test that passes on every such site would only be noise.
 *
 * @return bool
 */
function keel_defaults_abilities_authorization_supported() {
	return class_exists( 'WP_Ability', false ) && class_exists( 'WP_Filter_Sentinel', false );
}

/**
 * What core's `__return_*` helpers do on each hook, without running them.
 *
 * A helper resolves to core, so nothing records who registered it. Its effect is
 * still known, because each one returns a constant. Core coerces any non-boolean
 * permission result to `false`, so every helper but `__return_true` denies. None
 * of them returns the pre-execute sentinel, so every one short-circuits.
 *
 * @param string $helper Function name.
 * @param string $kind   `permission` or `short_circuit`.
 * @return string `grants_all`, `denies_all`, `short_circuits_all`, or '' for an unknown helper.
 */
function keel_defaults_ability_helper_effect( $helper, $kind ) {
	$helpers = array( '__return_true', '__return_false', '__return_zero', '__return_null', '__return_empty_array', '__return_empty_string' );

	if ( ! in_array( $helper, $helpers, true ) ) {
		return '';
	}

	if ( 'short_circuit' === $kind ) {
		return 'short_circuits_all';
	}

	return '__return_true' === $helper ? 'grants_all' : 'denies_all';
}

/**
 * Inventory every callback registered on the authorization filters.
 *
 * Read with keel_defaults_registration_entries(), so each hook is bounded to
 * 1,000 entries and a malformed or oversized registry is reported as limited
 * rather than read as clean.
 *
 * Callbacks that resolve to WordPress are not treated as platform behavior here,
 * as they are on Keel's policy hooks. Core registers nothing on either filter, so
 * a core function found on one was put there by something else: `__return_true`
 * by a plugin granting everything, or `is_user_logged_in` by one granting every
 * logged-in user. Helpers are reported with their effect, other core functions
 * by name, and both as owned by unknown code.
 *
 * WordPress keys a string callable by its name, so two plugins registering the
 * same helper at the same priority leave one entry. Helpers therefore mean "one
 * or more", and entry counts are a floor.
 *
 * @return array{coverage:string,total:int,hooks:array<string,array{kind:string,entries:int,limited:bool,owners:list<string>,helpers:array<string,string>,core_functions:list<string>,untraceable:int}>}
 */
function keel_defaults_ability_authorization_inventory() {
	$out = array(
		'coverage' => 'unsupported',
		'total'    => 0,
		'hooks'    => array(),
	);

	if ( ! keel_defaults_abilities_authorization_supported() ) {
		return $out;
	}

	$out['coverage'] = 'complete';
	$core            = keel_defaults_core_roots();

	foreach ( keel_defaults_ability_authorization_hooks() as $hook => $kind ) {
		$row     = array(
			'kind'           => $kind,
			'entries'        => 0,
			'limited'        => false,
			'owners'         => array(),
			'helpers'        => array(),
			'core_functions' => array(),
			'untraceable'    => 0,
		);
		$entries = keel_defaults_registration_entries( $hook );

		if ( false === $entries ) {
			$row['limited']        = true;
			$out['coverage']       = 'partial';
			$out['hooks'][ $hook ] = $row;
			continue;
		}

		$owners = array();
		foreach ( $entries as $entry ) {
			$callback = $entry['callback'];
			++$row['entries'];

			$plugin = keel_defaults_callback_plugin_dir( $callback );
			if ( '' !== $plugin ) {
				$owners[ $plugin ] = true;
				continue;
			}

			$theme = keel_defaults_callback_theme( $callback );
			if ( '' !== $theme ) {
				$owners[ KEEL_DEFAULTS_THEME_PREFIX . $theme ] = true;
				continue;
			}

			$mu_plugin = keel_defaults_callback_mu_plugin( $callback );
			if ( '' !== $mu_plugin ) {
				$owners[ KEEL_DEFAULTS_MU_PLUGIN_PREFIX . $mu_plugin ] = true;
				continue;
			}

			$file = keel_defaults_callback_file( $callback );
			$real = '' === $file ? false : realpath( $file );
			$file = wp_normalize_path( false !== $real ? $real : $file );
			if ( is_string( $callback ) && '' !== $file && keel_defaults_path_is_under( $file, $core ) ) {
				$name   = strtolower( $callback );
				$effect = keel_defaults_ability_helper_effect( $name, $kind );
				if ( '' !== $effect ) {
					$row['helpers'][ $name ] = $effect;
				} else {
					$row['core_functions'][ $name ] = true;
				}
				continue;
			}

			++$row['untraceable'];
		}

		$row['owners']         = array_keys( $owners );
		$row['core_functions'] = array_keys( $row['core_functions'] );
		$out['total']         += $row['entries'];
		$out['hooks'][ $hook ] = $row;
	}

	return $out;
}

/**
 * Register the Site Health test where core has the filters.
 *
 * @param mixed $tests Registered Site Health tests; returned untouched unless an array.
 * @return mixed
 */
function keel_defaults_register_ability_authorization_test( $tests ) {
	if ( ! is_array( $tests ) || ! keel_defaults_abilities_authorization_supported() ) {
		return $tests;
	}

	$tests['direct']['keel_abilities_authorization'] = array(
		'label' => __( 'Ability permission overrides', 'keel-defaults' ),
		'test'  => 'keel_defaults_ability_authorization_health',
	);

	return $tests;
}

/**
 * Site Health presentation of the inventory, for people who may see checks.
 *
 * Owners are named the way the conflict report names them. File paths are never
 * shown, and nothing here reaches the posture export.
 *
 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string} Site Health result.
 */
function keel_defaults_ability_authorization_health() {
	$result = array(
		'label'       => __( 'No plugin is overriding ability permissions', 'keel-defaults' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'keel-defaults' ),
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_abilities_authorization',
	);

	if ( ! current_user_can( 'view_site_health_checks' ) ) {
		return $result;
	}

	$inventory = keel_defaults_ability_authorization_inventory();
	$intro     = '<p>' . esc_html__( 'Abilities are actions that AI tools, the REST API and WP-CLI can run on this site. Each one checks permission before it runs. WordPress lets plugins change that answer, or skip the check entirely.', 'keel-defaults' ) . '</p>';
	$limits    = '<p>' . esc_html__( 'Keel reads what is registered in this request. It does not run any plugin\'s code, so it cannot say what the result is for a particular ability, user, input or channel.', 'keel-defaults' ) . '</p>';

	if ( 'complete' === $inventory['coverage'] && 0 === $inventory['total'] ) {
		$result['description'] = $intro . '<p>' . esc_html__( 'Nothing on this site is registered to do either.', 'keel-defaults' ) . '</p>' . $limits;
		return $result;
	}

	$result['label']  = __( 'Plugins can change who may run abilities on this site', 'keel-defaults' );
	$result['status'] = 'recommended';

	$headings = array(
		'wp_ability_permission_result' => __( 'Overrides permission results', 'keel-defaults' ),
		'wp_pre_execute_ability'       => __( 'Can skip the permission check entirely', 'keel-defaults' ),
	);
	$effects  = array(
		'grants_all'         => __( 'grants every permission check that reaches it, for every ability, user and channel, including logged-out requests to abilities exposed over REST', 'keel-defaults' ),
		'denies_all'         => __( 'denies every permission check that reaches it, for every ability', 'keel-defaults' ),
		'short_circuits_all' => __( 'skips the permission check for every ability, including WP-CLI and other direct calls, and returns its own value instead', 'keel-defaults' ),
	);

	$list     = '';
	$helpers  = '';
	$untraced = false;
	foreach ( $inventory['hooks'] as $hook => $row ) {
		$names = array_map( 'keel_defaults_plugin_name', $row['owners'] );

		$untraced = $untraced || ! empty( $row['core_functions'] ) || ! empty( $row['helpers'] ) || $row['untraceable'] > 0;

		if ( ! empty( $row['core_functions'] ) ) {
			/* translators: %s: comma-separated WordPress function names. */
			$names[] = sprintf( __( 'the WordPress function %s, registered by code that cannot be traced', 'keel-defaults' ), implode( ', ', $row['core_functions'] ) );
		}
		if ( ! empty( $row['helpers'] ) ) {
			/* translators: %s: comma-separated helper function names, such as __return_true. */
			$names[] = sprintf( __( 'one or more registrations of %s', 'keel-defaults' ), implode( ', ', array_keys( $row['helpers'] ) ) );
		}
		if ( $row['untraceable'] > 0 ) {
			/* translators: %s: number of callbacks. */
			$names[] = sprintf( _n( '%s callback that cannot be traced to any plugin', '%s callbacks that cannot be traced to any plugin', $row['untraceable'], 'keel-defaults' ), number_format_i18n( $row['untraceable'] ) );
		}
		if ( $row['limited'] ) {
			$names[] = __( 'unknown: the hook registry is malformed or exceeds the 1,000-entry inspection limit', 'keel-defaults' );
		}
		if ( empty( $names ) ) {
			continue;
		}

		$list .= '<li><strong>' . esc_html( $headings[ $hook ] ) . '</strong> (<code>' . esc_html( $hook ) . '</code>): ' . esc_html( implode( '; ', $names ) ) . '</li>';

		foreach ( $row['helpers'] as $helper => $effect ) {
			$helpers .= '<p>' . sprintf(
				/* translators: 1: helper function name, 2: hook name, 3: what the helper does there. */
				esc_html__( 'One or more plugins or themes registered WordPress\'s %1$s helper on %2$s. That %3$s.', 'keel-defaults' ),
				'<code>' . esc_html( $helper ) . '</code>',
				'<code>' . esc_html( $hook ) . '</code>',
				'<strong>' . esc_html( $effects[ $effect ] ) . '</strong>'
			) . '</p>';
		}
	}

	$result['description'] = $intro
		. '<p>' . esc_html__( 'The code below can change that answer, or skip the check. One registration is enough, so each is worth understanding even when no other plugin is involved. Many are intentional, for example a two-factor gate or a cache.', 'keel-defaults' ) . '</p>'
		. '<ul>' . $list . '</ul>'
		. $helpers;

	if ( $untraced ) {
		$result['description'] .= '<p>' . esc_html__( 'A plugin that uses one of WordPress\'s own functions here leaves nothing behind to identify it: the function belongs to WordPress, not to whoever registered it. Your active plugins, theme and must-use plugins are the place to look.', 'keel-defaults' ) . '</p>';
	}

	$result['description'] .= '<p>' . esc_html__( 'Check each plugin\'s documentation for why it uses these filters.', 'keel-defaults' ) . '</p>' . $limits;

	return $result;
}
