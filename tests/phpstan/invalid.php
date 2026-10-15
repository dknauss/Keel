<?php
/** Intentionally rejected examples; analyzed only by composer test:types. */

/**
 * Read an intentionally invalid offset.
 *
 * @return mixed Intentionally invalid offset.
 */
function keel_contract_reject_missing_key() {
	return keel_defaults_minor_update_state()['operabl'];
}
/**
 * Return an owner outside the producer's documented domain.
 *
 * @phpstan-return KeelMinorUpdateState
 */
function keel_contract_reject_owner() {
	return array(
		'policy'   => true,
		'owner'    => 'plugin',
		'operable' => true,
		'blockers' => array(),
	);
}
/**
 * Read an intentionally invalid offset.
 *
 * @return mixed Intentionally invalid offset.
 */
function keel_contract_reject_report_key() {
	return keel_defaults_policy_overlap_report()['confirmed'];
}
function keel_contract_reject_install_plan(): void {
	keel_defaults_run_backport_install(
		array(
			'offer' => new stdClass(),
			'args'  => array(
				'allow_relaxed_file_ownership' => true,
				'attempt_rollback'             => false,
			),
		)
	);
}

/**
 * Read a nonexistent validated result field.
 *
 * @return mixed Deliberately incorrect result access.
 */
function keel_contract_reject_result_key() {
	$result = keel_defaults_take_backport_result( 1 );
	return null === $result ? null : $result['messsages'];
}
/**
 * Read a return target that is not in the fixed map.
 *
 * @return mixed Deliberately incorrect map access.
 */
function keel_contract_reject_return_target() {
	return keel_defaults_backport_return_map()['external'];
}
/**
 * Produce an invalid Site Health verdict.
 *
 * @phpstan-return KeelBackportHealthResult
 */
function keel_contract_reject_health_status() {
	return array(
		'label'       => 'Test',
		'status'      => 'failed',
		'badge'       => array(
			'label' => 'Security',
			'color' => 'blue',
		),
		'description' => '',
		'actions'     => '',
		'test'        => 'keel_backport',
	);
}

/**
 * Treat normalized plugin paths as integers.
 *
 * @return array<string,int> Deliberately incorrect attribution contract.
 */
function keel_contract_reject_plugin_paths() {
	return keel_defaults_active_plugin_roots();
}
/**
 * Read a misspelled registration field.
 *
 * @return mixed Deliberately incorrect record access.
 */
function keel_contract_reject_registration_key() {
	foreach ( keel_defaults_registered_policy_hooks() as $records ) {
		foreach ( $records as $record ) {
			return $record['accepted_arg'];
		}
	}
	return null;
}
/**
 * Confuse the raw stored expectation with the public divergence marker.
 *
 * @phpstan-return KeelDivergenceMap
 */
function keel_contract_reject_divergence_marker() {
	return array( 'comments_open' => false );
}

/**
 * Confuse dismissal flags with display strings.
 *
 * @return array<string,string> Deliberately incorrect screen contract.
 */
function keel_contract_reject_notice_screens() {
	return keel_defaults_conflict_notice_screens();
}

/**
 * Produce a status outside the validated verdict domain.
 *
 * @return 'latest'|'outdated'|'insecure'|'unknown' Deliberately incorrect status.
 * @phpstan-return KeelVersionStatus
 */
function keel_contract_reject_version_status() {
	return 'supported';
}
/**
 * Treat the tri-state lookup as a structured record.
 *
 * @return array{breached:bool} Deliberately incorrect lookup contract.
 */
function keel_contract_reject_lookup_record() {
	return keel_hibp_lookup( 'static-only-example' );
}
/**
 * Treat the raw diagnostic record as display text.
 *
 * @return string Deliberately incorrect diagnostic contract.
 */
function keel_contract_reject_failure_text() {
	return keel_hibp_last_failure();
}
