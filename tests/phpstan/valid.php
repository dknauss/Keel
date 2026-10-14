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
