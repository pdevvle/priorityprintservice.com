<?php
/**
 * PPS — wp-admin login-page branding.
 *
 * Replaces the default WordPress login logo with the site icon (favicon)
 * and points the logo link at the site home instead of wordpress.org.
 * Self-contained; side-loaded from pps-term-html.php (MCP-shipped).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'login_head', function () {
	$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 192 ) : '';
	if ( ! $icon ) {
		return;
	}
	echo "\n<style id=\"pps-login-logo\">\n"
		. ".login h1 a{"
		. "background-image:url(" . esc_url( $icon ) . ") !important;"
		. "background-size:contain !important;"
		. "background-position:center center !important;"
		. "background-repeat:no-repeat !important;"
		. "width:84px !important;height:84px !important;"
		. "}\n</style>\n";
} );

add_filter( 'login_headerurl',  function () { return home_url( '/' ); } );
add_filter( 'login_headertext', function () { return get_bloginfo( 'name' ); } );
