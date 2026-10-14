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
