<?php
/**
 * Plugin Name: Allow Docker host Scout Suite API
 * Description: WordPress rejects private IPs in wp_remote_* (SSRF guard). The demo API lives on the Docker host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'http_request_host_is_external',
	static function ( $allow, $host ) {
		$host = strtolower( (string) $host );
		if ( 'host.docker.internal' === $host || str_ends_with( $host, '.docker.internal' ) ) {
			return true;
		}
		return $allow;
	},
	10,
	2
);
