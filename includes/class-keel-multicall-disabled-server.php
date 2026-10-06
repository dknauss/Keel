<?php
/**
 * XML-RPC server that refuses system.multicall, except to Jetpack.
 *
 * Loaded lazily from the wp_xmlrpc_server_class filter: it extends
 * wp_xmlrpc_server, which only exists on an XML-RPC request.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drop-in that refuses system.multicall for everyone but WordPress.com.
 *
 * WordPress 4.4 stopped multicall being a password-guessing multiplier, so
 * refusing it is modest defence-in-depth against general batching, not a
 * password control. That is too small a gain to break a connected Jetpack site
 * for: WordPress.com's server-to-site requests arrive as multicall, and refusing
 * them all left the site reporting itself connected while WordPress.com could no
 * longer list plugins or save settings on it.
 */
class Keel_Multicall_Disabled_Server extends wp_xmlrpc_server {
	/**
	 * Refuse batched (multicall) requests, unless Jetpack verifies the request.
	 *
	 * The fault code is deliberately not an HTTP status. With remote publishing
	 * off, xmlrpc_enabled is false and wp_xmlrpc_server::error() sends the fault
	 * code as the response status; 405 here went out as HTTP 405 and clients
	 * reported a transport failure instead of reading the fault.
	 *
	 * @param array $methodcalls Boxcarred method calls.
	 * @return array|IXR_Error
	 */
	public function multiCall( $methodcalls ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides a core method name.
		if ( keel_defaults_jetpack_request_verified() ) {
			return parent::multiCall( $methodcalls );
		}

		return new IXR_Error( KEEL_DEFAULTS_MULTICALL_FAULT, 'system.multicall is disabled on this site.' );
	}
}
