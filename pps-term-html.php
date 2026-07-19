<?php
/**
 * Allow rich HTML (headings, lists, paragraphs) in WooCommerce category descriptions.
 * Loaded by pps-calculators.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

remove_filter( 'pre_term_description', 'wp_filter_kses' );
remove_filter( 'term_description', 'wp_kses_data' );
add_filter( 'pre_term_description', 'wp_filter_post_kses' );

// ── Side-load wp-admin login branding (MCP-shipped). Loaded on every request
//    incl. wp-login.php, so it is a convenient always-loaded hook point.
//    Swaps the WordPress login logo for the site favicon. See pps-login-brand.php.
$_pps_login_brand = __DIR__ . '/pps-login-brand.php';
if ( file_exists( $_pps_login_brand ) ) {
    require_once $_pps_login_brand;
}
unset( $_pps_login_brand );
