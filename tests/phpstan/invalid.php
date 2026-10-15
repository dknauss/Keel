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
