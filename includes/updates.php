<?php
/**
 * Core and translation auto-update policy filter callbacks.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply Keel's maintenance/security core update policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_minor_core_updates( $enabled ) {
	$policy = keel_defaults_get( 'core_update_policy' );

	if ( 'inherit' === $policy ) {
		return $enabled;
	}

	return in_array( $policy, array( 'minor', 'all' ), true );
}

/**
 * Apply Keel's stable major core update policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_major_core_updates( $enabled ) {
	$policy = keel_defaults_get( 'core_update_policy' );

	if ( 'inherit' === $policy ) {
		return $enabled;
	}

	return 'all' === $policy;
}

/**
 * Keep development builds out of every explicit stable-release policy.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_dev_core_updates( $enabled ) {
	return 'inherit' === keel_defaults_get( 'core_update_policy' ) ? $enabled : false;
}

/**
 * Apply the translation update toggle.
 *
 * @param bool $enabled WordPress's current decision.
 * @return bool
 */
function keel_defaults_allow_translation_updates( $enabled ) {
	unset( $enabled );
	return keel_defaults_enabled( 'auto_update_translations' );
}
