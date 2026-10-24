<?php
/**
 * Disposable lab mu-plugin; never install on a real site.
 *
 * Lets pingback.ping fetch a source page served from this machine on port 9401
 * (see pingback-source.html and probe-pingback.sh). wp_safe_remote_get()
 * refuses loopback hosts; on a public site the source would be an outside
 * server, which it does fetch. Nothing else is allowed through.
 *
 * @package Keel
 */

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host, $url ) {
		return $external || 0 === strpos( $url, 'http://127.0.0.1:9401/' );
	},
	10,
	3
);
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) {
		$ports[] = 9401;
		return $ports;
	}
);
