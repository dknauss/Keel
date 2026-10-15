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

/**
 * Assume an AJAX action returns control to its caller.
 *
 * @return bool Deliberately unreachable result.
 */
function keel_contract_reject_action_fallthrough() {
	keel_defaults_backport_ajax();
	return true;
}

/**
 * Produce a boolean failure instead of a structured validation error.
 *
 * @phpstan-return KeelPasswordVerdict
 */
function keel_contract_reject_password_false() {
	return false;
}
/**
 * Supply scalar data where the REST adapter must preserve a prepared user object.
 *
 * @param WP_REST_Request<array<string,mixed>> $request Raw REST request.
 */
function keel_contract_reject_prepared_user( WP_REST_Request $request ): void {
	keel_defaults_validate_rest_password( 'not-a-user-object', $request );
}

/** A range renderer requires its value collection. */
function keel_contract_reject_range_values(): void {
	keel_defaults_render_range_field( 'width', 'width', 'default', array(), array(), 'Width', '' );
}
/** Choice resolution reads the field's type directly. */
function keel_contract_reject_choice_type(): void {
	keel_defaults_control_choices( array( 'choices' => array( 'a' ) ), array() );
}
/** Labels must be a collection, not a single display string. */
function keel_contract_reject_range_labels(): void {
	keel_defaults_render_range_field(
		'width',
		'width',
		'default',
		array( 'values' => array( 'default' ) ),
		array( 'labels' => 'Default' ),
		'Width',
		''
	);
}

/** WordPress update decisions must be booleans. */
function keel_contract_reject_minor_core_input(): void {
	keel_defaults_allow_minor_core_updates( array() );
}

/** Policy verdicts cannot be consumed as version strings. */
function keel_contract_reject_minor_core_output(): string {
	return keel_defaults_allow_minor_core_updates( true );
}

/** WordPress update decisions must be booleans. */
function keel_contract_reject_major_core_input(): void {
	keel_defaults_allow_major_core_updates( array() );
}

/** Policy verdicts cannot be consumed as version strings. */
function keel_contract_reject_major_core_output(): string {
	return keel_defaults_allow_major_core_updates( true );
}

/** WordPress update decisions must be booleans. */
function keel_contract_reject_dev_core_input(): void {
	keel_defaults_allow_dev_core_updates( array() );
}

/** Policy verdicts cannot be consumed as version strings. */
function keel_contract_reject_dev_core_output(): string {
	return keel_defaults_allow_dev_core_updates( true );
}

/** WordPress update decisions must be booleans. */
function keel_contract_reject_translation_input(): void {
	keel_defaults_allow_translation_updates( array() );
}

/** Policy verdicts cannot be consumed as version strings. */
function keel_contract_reject_translation_output(): string {
	return keel_defaults_allow_translation_updates( true );
}

/** Seed defaults are a map, not a serialized string. */
function keel_contract_reject_seed_values(): string {
	return keel_defaults_seed_values();
}

/** Activation receives WordPress's boolean network flag. */
function keel_contract_reject_activation_flag(): void {
	keel_defaults_activate( array() );
}

/** Lifecycle decisions are booleans, not version strings. */
function keel_contract_reject_seed_result(): string {
	return keel_defaults_seed_site();
}

/** Lifecycle decisions are booleans, not version strings. */
function keel_contract_reject_migration_result(): string {
	return keel_defaults_maybe_upgrade();
}

/** Lifecycle decisions are booleans, not version strings. */
function keel_contract_reject_network_active_result(): string {
	return keel_defaults_is_network_active();
}

/** Suppressed mail must be an argument collection, not serialized text. */
function keel_contract_reject_mail_arguments(): void {
	keel_defaults_suppress_mail( null, 'serialized message' );
}

/** Suppression success is a boolean, not a message ID. */
function keel_contract_reject_mail_result(): string {
	return keel_defaults_suppress_mail( null, array() );
}

/** Effective From addresses are text, not validity decisions. */
function keel_contract_reject_mail_from(): bool {
	return keel_defaults_mail_from_address();
}

/** The suppression decision is a boolean, not rendered notice text. */
function keel_contract_reject_mail_policy(): string {
	return keel_defaults_suppresses_mail();
}

/** Public style registration requires a callable provider. */
function keel_contract_reject_style_provider(): void {
	keel_defaults_add_style( 'admin', 42 );
}

/** Providers must be invocable without mandatory arguments. */
function keel_contract_reject_style_arguments(): void {
	keel_defaults_add_style(
		'admin',
		static function ( string $required ): string { return $required;
		}
	);
}

/** Registry reads return a map, not rendered CSS. */
function keel_contract_reject_style_registry(): string {
	return keel_defaults_style_providers( 'admin' );
}

/** Stored network policy is a map, not display text. */
function keel_contract_reject_network_store(): string {
	return keel_defaults_network_settings();
}

/** Sanitized network policy is a map, not a boolean verdict. */
function keel_contract_reject_network_sanitized(): bool {
	return keel_defaults_sanitize_network( array(), array() );
}

/** The network control wrapper retains the shared label-collection contract. */
function keel_contract_reject_network_labels(): void {
	keel_defaults_render_network_control( 'sample', 'sample', 'yes', array(), array( 'labels' => 'Yes' ), 'Sample', '' );
}
