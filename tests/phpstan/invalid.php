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
function keel_contract_reject_owner(): void {
	keel_defaults_selection_state(
		array(
			'policy'   => true,
			'owner'    => 'plugin',
			'operable' => true,
			'blockers' => array(),
		),
		false
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
