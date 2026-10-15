<?php
/** Static-only contract examples; never bootstrapped by WordPress or composer test. */

function keel_contract_valid_use(): void {
	$state = keel_defaults_minor_update_state();
	keel_defaults_selection_state( $state, false );
	keel_defaults_backport_route( '6.4.10', $state, '', false );
	keel_defaults_blocker_codes( $state['blockers'] );
	keel_defaults_blocker_texts( $state['blockers'] );
	keel_contract_accept_policy( $state['policy'] );
	keel_contract_accept_plugins( keel_defaults_policy_overlap_report()['structural'] );
	keel_contract_accept_plugins( keel_defaults_policy_overlap_report()['unconfirmed'] );
	keel_contract_accept_rungs( keel_defaults_update_ladder() );
	keel_contract_accept_screen( keel_defaults_updates_screen_offer( '6.4.10' ) );
	$plan = keel_defaults_prepare_backport_install( '6.4.10' );
	if ( ! is_wp_error( $plan ) ) {
		keel_defaults_run_backport_install( $plan );
	}
}
/**
 * Accept a resolved decision.
 *
 * @param bool $policy Resolved decision.
 */
function keel_contract_accept_policy( $policy ): void {}
/**
 * Accept a classified callback map.
 *
 * @param array<string,list<string>> $plugins Classified callbacks.
 */
function keel_contract_accept_plugins( array $plugins ): void {}
/**
 * Accept sorted offers.
 *
 * @phpstan-param list<KeelLadderRung> $rungs Sorted offers.
 */
function keel_contract_accept_rungs( array $rungs ): void {}
/**
 * Accept an Updates-screen outcome.
 *
 * @phpstan-param KeelUpdateScreenOffer $offer Visible/hidden/absent/unknown outcome.
 */
function keel_contract_accept_screen( array $offer ): void {}

/** Exercise the minimal inputs each consumer actually reads. */
function keel_contract_minimal_states(): void {
	keel_defaults_selection_state( array( 'operable' => true ), false );
	keel_defaults_selection_state(
		array(
			'operable' => true,
			'owner'    => 'ignored-by-selector',
		),
		false
	);
	keel_defaults_backport_return_url( 'unknown-submitted-token' );
	keel_defaults_selection_state(
		array(
			'operable' => false,
			'blockers' => array( 'reason' => array( 'code' => 'credentials' ) ),
		),
		'6.4.10'
	);
	keel_defaults_installer_refusals( array( 'reason' => array( 'code' => 'file_mods' ) ) );
	keel_defaults_blocker_texts( array( array( 'text' => 'Message without an unused code' ) ) );
	keel_defaults_backport_install_button( '6.4.10', 'none', array( 'blockers' => array() ) );
	keel_defaults_schedule_statement(
		array(
			'policy'   => true,
			'operable' => true,
		),
		'',
		'6.4.10'
	);
	keel_defaults_ladder_note( 'blocked', '', array( 'blockers' => array() ) );
	keel_contract_accept_health( keel_defaults_backport_test() );
	keel_contract_accept_health( keel_defaults_backport_verdict() );
	$tests = keel_defaults_register_backport_test(
		array(
			'direct' => array( 'third-party' => 'kept' ),
			42       => 'third-party metadata',
		)
	);
	keel_contract_accept_callback( $tests['async']['keel_backport']['async_direct_test'] );
	$result = keel_defaults_take_backport_result( 1 );
	if ( null !== $result ) {
		keel_contract_accept_messages( $result['messages'] );
	}
}
/**
 * Accept the emitted Site Health structure.
 *
 * @phpstan-param KeelBackportHealthResult $result Valid verdict.
 */
function keel_contract_accept_health( array $result ): void {}
/**
 * Accept a preserved asynchronous test callback.
 *
 * @param 'keel_defaults_backport_test' $callback Expected callback.
 */
function keel_contract_accept_callback( $callback ): void {}
/**
 * Accept all validated string-message keys, including third-party keyed arrays.
 *
 * @param array<array-key,string> $messages Validated messages.
 */
function keel_contract_accept_messages( array $messages ): void {}

/** Confirm that the inconclusive outcome remains an accepted finish input. */
function keel_contract_nullable_finish(): void {
	keel_defaults_finish_backport_install( null, '6.4.10', 'unknown-submitted-token' );
}

/** Exercise diagnostic maps, permissive callback boundaries and value identity. */
function keel_contract_diagnostics(): void {
	keel_contract_accept_paths( keel_defaults_active_plugin_roots() );
	keel_contract_accept_paths( keel_defaults_mu_plugin_files() );
	keel_contract_accept_paths( keel_defaults_mu_plugin_package_dirs() );
	keel_contract_accept_plugins( keel_defaults_competing_plugins() );
	keel_contract_accept_divergences( keel_defaults_policy_divergences() );
	keel_contract_accept_observed( keel_defaults_observe_policy_result( 'unknown', array( 'marker' => 'unchanged' ) ) );
	keel_defaults_callback_file( null );
	keel_defaults_callback_file( array( 'incomplete' ) );
	keel_defaults_callable_id( new stdClass() );
	keel_defaults_add_policy_filter( 'example', '__return_false', 10, 1, array( 'key' => 'disable_comments' ) );
	foreach ( keel_defaults_registered_policy_hooks() as $records ) {
		foreach ( $records as $record ) {
			keel_contract_accept_registration( $record );
		}
	}
}
/**
 * Accept normalized attribution maps.
 *
 * @param array<string,string> $paths Identity => path or path => identity.
 */
function keel_contract_accept_paths( array $paths ): void {}
/**
 * Accept only the public divergence markers, not the raw transient.
 *
 * @phpstan-param KeelDivergenceMap $hooks Live divergence markers.
 */
function keel_contract_accept_divergences( array $hooks ): void {}
/**
 * Accept the same value type supplied to the passive observer.
 *
 * @param array{marker:string} $value Unchanged observed value.
 */
function keel_contract_accept_observed( array $value ): void {}
/**
 * Accept the complete tracked registration.
 *
 * @phpstan-param KeelPolicyRegistration $record Registered callback metadata.
 */
function keel_contract_accept_registration( array $record ): void {}

/** Exercise the notice screen contract without fixing its extensible keys. */
function keel_contract_notice_screens(): void {
	keel_contract_accept_notice_screens( keel_defaults_conflict_notice_screens() );
	keel_contract_accept_notice_screens(
		array(
			'custom_screen'     => true,
			'persistent_screen' => false,
		)
	);
	keel_contract_accept_fingerprint( keel_defaults_notice_fingerprint() );
}
/**
 * Accept extensible screen IDs with boolean dismissal flags.
 *
 * @param array<string,bool> $screens Screen => dismissal availability.
 */
function keel_contract_accept_notice_screens( array $screens ): void {}
/**
 * Accept the notice's opaque dismissal identity, including the empty state.
 *
 * @param string $fingerprint Notice identity.
 */
function keel_contract_accept_fingerprint( $fingerprint ): void {}

/** Keep validated verdicts separate from raw cached transport data. */
function keel_contract_status_transport(): void {
	keel_contract_accept_version_status( keel_defaults_version_status() );
	keel_contract_accept_lookup_result( keel_hibp_lookup( 'static-only-example' ) );
	$failure = keel_hibp_last_failure();
	if ( null !== $failure ) {
		keel_contract_accept_raw_record( $failure );
	}
	keel_contract_accept_raw_record( keel_defaults_stable_check() );
	keel_defaults_stable_check_is_stale(
		array(
			42       => new stdClass(),
			'6.4.10' => null,
		),
		(object) array( 'updates' => array( false, (object) array( 'current' => '6.4.10' ) ) )
	);
}
/**
 * Accept only the validated running-version verdict.
 *
 * @param 'latest'|'outdated'|'insecure'|'unknown' $status Version verdict.
 * @phpstan-param KeelVersionStatus $status
 */
function keel_contract_accept_version_status( $status ): void {}
/**
 * Preserve unknown as a distinct lookup outcome.
 *
 * @param bool|null $result Breached, clean or unknown.
 */
function keel_contract_accept_lookup_result( $result ): void {}
/**
 * Accept raw cached records without claiming their optional fields are validated.
 *
 * @param array<array-key,mixed> $record Raw cached record.
 */
function keel_contract_accept_raw_record( array $record ): void {}

/** Keep request-ending callbacks distinct from data-producing helpers. */
function keel_contract_action_handlers(): void {
	keel_contract_accept_request_handler( 'keel_defaults_backport_ajax' );
	keel_contract_accept_request_handler( 'keel_defaults_handle_enable_minor_updates' );
	keel_contract_accept_request_handler( 'keel_defaults_dismiss_backport_notice' );
}
/**
 * Accept callbacks that end the request; do not invoke them in static fixtures.
 *
 * @param callable():never $handler Request-ending callback.
 */
function keel_contract_accept_request_handler( callable $handler ): void {}

/**
 * Exercise profile, reset and REST adapter boundaries without executing them.
 *
 * @param WP_REST_Request<array<string,mixed>> $request Raw REST request parameters.
 */
function keel_contract_password_adapters( WP_REST_Request $request, WP_User $user, WP_Error $errors ): void {
	$result = keel_defaults_validate_password( 'static-only-example', false );
	keel_contract_accept_password_verdict( $result );
	keel_defaults_password_enforced_for_user( false );
	keel_defaults_validate_profile_password( $errors, true, $user );
	keel_defaults_validate_reset_password( $errors, $user );
	$prepared = new stdClass();
	$out      = keel_defaults_validate_rest_password( $prepared, $request );
	if ( ! is_wp_error( $out ) ) {
		keel_contract_accept_prepared_user( $out );
	}
	keel_contract_accept_password_context( keel_defaults_rest_password_context( $request ) );
	keel_contract_accept_raw_record(
		keel_defaults_guard_rest_password_arg(
			array(
				'/wp/v2/users' => array(
					array( 'args' => array( 'password' => array() ) ),
					'schema' => 'metadata',
				),
				'/unrelated'   => false,
				42             => 'third-party metadata',
			)
		)
	);
}
/**
 * Accept only the validator's success marker or error.
 *
 * @phpstan-param KeelPasswordVerdict $result Password verdict.
 */
function keel_contract_accept_password_verdict( $result ): void {}
/** Accept the prepared user's original concrete type after excluding errors. */
function keel_contract_accept_prepared_user( stdClass $user ): void {}
/**
 * Accept existing or submitted REST user context.
 *
 * @param WP_User|stdClass $user Resolved context.
 */
function keel_contract_accept_password_context( $user ): void {}
/**
 * Accept a more precise caller's request without asserting that it is validated.
 *
 * @param WP_REST_Request<array{password?:mixed}> $request Partial request parameters.
 */
function keel_contract_partial_password_request( WP_REST_Request $request ): void {
	keel_defaults_validate_rest_password( new stdClass(), $request );
	keel_defaults_rest_password_context( $request );
}

/** A prepared user need not be a WordPress class or stdClass. */
class Keel_Contract_Prepared_User {
	/**
	 * Submitted login context.
	 *
	 * @var string
	 */
	public $user_login = 'static-only-user';
}
/**
 * Preserve a custom prepared object and accept its guarded context fields.
 *
 * @param WP_REST_Request<array<string,mixed>> $request Raw REST request.
 */
function keel_contract_custom_prepared_user( WP_REST_Request $request ): void {
	$user = new Keel_Contract_Prepared_User();
	keel_defaults_password_enforced_for_user( $user );
	keel_defaults_validate_password( 'static-only-example', $user );
	$out = keel_defaults_validate_rest_password( $user, $request );
	if ( ! is_wp_error( $out ) ) {
		keel_contract_accept_custom_prepared_user( $out );
	}
}
/** Accept the same concrete custom class after excluding validation errors. */
function keel_contract_accept_custom_prepared_user( Keel_Contract_Prepared_User $user ): void {}

/** Exercise minimal control projections and keyed range metadata. */
function keel_contract_settings_controls(): void {
	keel_defaults_render_control( 'fallback', 'fallback', 'yes', array(), array(), 'Statement', '' );
	keel_defaults_render_control(
		'number',
		'number',
		5,
		array(
			'type' => 'number',
			'min'  => '1',
			'max'  => 10.5,
		),
		array(
			'label' => 'Count',
			'unit'  => 'items',
		),
		'',
		'',
		true,
		'network'
	);
	keel_contract_accept_control_choices(
		keel_defaults_control_choices(
			array(
				'type'    => 'custom-select',
				'choices' => array( 'first' => 'a' ),
			),
			array( 'choices' => array( 'a' => 'A' ) )
		)
	);
	keel_defaults_control_choices( array( 'type' => 'multiselect' ), array() );
	keel_defaults_control_choices(
		array(
			'type'   => 'range',
			'values' => array(
				'narrow' => 160,
				'wide'   => 240.5,
			),
		),
		array(
			'labels' => array(
				'first'  => 'Narrow',
				'second' => 'Wide',
			),
		)
	);
	keel_defaults_render_range_field(
		'width',
		'width',
		'default',
		array(
			'values' => array(
				'first'  => 'default',
				'second' => 240.5,
			),
		),
		array(
			'labels' => array(
				'first'  => 'Default',
				'second' => 'Wide',
			),
			'unit'   => new stdClass(),
		),
		'Width',
		'',
		true
	);
}
/**
 * Accept the choice map after label fallback and key conversion.
 *
 * @param array<array-key,string> $choices Value => display label.
 */
function keel_contract_accept_control_choices( array $choices ): void {}

/**
 * Raw branch tips are validated by the offer renderer, including through wrappers.
 *
 * @param mixed $tip Unvalidated branch tip.
 */
function keel_contract_accept_updates_screen_tip( $tip ): void {
	keel_defaults_updates_screen_markup( 'insecure', $tip, '7.1', false );
	keel_defaults_updates_screen_content( 'insecure', $tip, '7.1', false );
	keel_defaults_render_updates_screen_markup( 'insecure', $tip, '7.1', false );
}
