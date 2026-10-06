<?php
/**
 * Stand-in for Jetpack's connection manager, for tests/xmlrpc-multicall.php.
 *
 * Only the two methods Keel calls. What they return is set by the test through
 * the static properties, so each case is one line there.
 *
 * @package keel
 */

namespace Automattic\Jetpack\Connection;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- A test double; the docblock above describes it.
class Manager {
	public static $verified  = false;
	public static $connected = true;
	public static $throws    = false;

	public function verify_xml_rpc_signature() {
		if ( self::$throws ) {
			throw new \RuntimeException( 'Jetpack changed underneath us.' );
		}
		return self::$verified;
	}

	public function is_connected() {
		return self::$connected;
	}
}
