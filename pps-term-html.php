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

// ── Side-load the Shippo integration test runner (staging diagnostic).
//    Inert unless the 'pps_shippo_test_trigger' option is set — see
//    docs/SHIPPO_TESTING.md for the regime and how to read results.
$_pps_shippo_test = __DIR__ . '/pps-shippo-test.php';
if ( file_exists( $_pps_shippo_test ) ) {
    require_once $_pps_shippo_test;
}
unset( $_pps_shippo_test );

// ── Side-load the homepage logo-band probe (staging diagnostic).
//    Inert unless the 'pps_home_probe_trigger' option is set.
$_pps_home_probe = __DIR__ . '/pps-home-probe.php';
if ( file_exists( $_pps_home_probe ) ) {
    require_once $_pps_home_probe;
}
unset( $_pps_home_probe );
