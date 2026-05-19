<?php
/**
 * Plugin Name: PPS Cart Price Floor
 * Description: Server-side floor on pps_price to mitigate cart-price tampering. Mirrors the in-tree fix in pps-calculators.php (PR #26) but ships as a small standalone file so it can be deployed via MCP without round-tripping the 170 KB main plugin. Safe to delete once Cloudways pulls the main fix from `production` — both checks coexist harmlessly.
 * Version: 1.0.0
 * Author: Priority Print Service
 *
 * What this does:
 *   1. On `woocommerce_add_to_cart_validation` — reject the add if the
 *      submitted pps_price is below max(absolute_floor, regular_price * min_pct).
 *   2. On `woocommerce_before_calculate_totals` priority 10 (BEFORE the
 *      existing priority-20 hook in pps-calculators.php that sets the
 *      line-item price) — for any cart item with pps_price below the
 *      floor, rewrite the cart item's pps_price up to the floor. This
 *      catches sessions where a bad price already made it into the cart
 *      before this file was deployed.
 *
 * Knobs (PCF in wp_options['pps_calc_config']):
 *   pps_min_price_pct      => float, fraction of regular_price (default 0.5)
 *   pps_absolute_min_price => float, absolute floor in $ (default 5)
 *
 * Logging: rejected/forced attempts go to error_log() with [pps-floor]
 * prefix. No customer-facing detail beyond the standard WC error notice.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'pps_cart_price_floor_compute' ) ) {
    function pps_cart_price_floor_compute( $product_id ) {
        $cfg     = get_option( 'pps_calc_config', array() );
        $pcf     = ( isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ) ? $cfg['pcf'] : array();
        $min_pct = floatval( $pcf['pps_min_price_pct']      ?? 0.5 );
        $abs_min = floatval( $pcf['pps_absolute_min_price'] ?? 5 );

        $product = wc_get_product( $product_id );
        if ( ! $product ) return $abs_min;
        $regular = floatval( $product->get_regular_price() );
        if ( $regular <= 0 ) return $abs_min;
        return max( $abs_min, $regular * $min_pct );
    }
}

// ── Defense 1: reject add-to-cart with sub-floor price ──
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0, $variations = null, $cart_item_data = null ) {
    if ( ! is_array( $cart_item_data ) || ! isset( $cart_item_data['pps_price'] ) ) return $passed;
    $submitted = floatval( $cart_item_data['pps_price'] );
    $floor     = pps_cart_price_floor_compute( $product_id );
    if ( $submitted >= $floor ) return $passed;

    if ( function_exists( 'error_log' ) ) {
        error_log( sprintf(
            '[pps-floor] rejected add-to-cart: product=%d submitted=%.2f required>=%.2f',
            $product_id, $submitted, $floor
        ) );
    }
    if ( function_exists( 'wc_add_notice' ) ) {
        wc_add_notice( 'Calculator price below product minimum. Refresh the calculator and try again.', 'error' );
    }
    return false;
}, 10, 6 );

// ── Defense 2: rewrite sub-floor cart items at calculate-totals time ──
// Priority 10 runs BEFORE the existing priority-20 hook in pps-calculators.php
// that applies $item['pps_price'] to the line-item price. By the time that
// hook fires, we've already raised any sub-floor value to the floor.
add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! is_object( $cart ) || ! isset( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) return;

    foreach ( $cart->cart_contents as $key => $item ) {
        if ( ! isset( $item['pps_price'] ) ) continue;
        $product = isset( $item['data'] ) ? $item['data'] : null;
        if ( ! $product ) continue;
        $pid       = method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
        $submitted = floatval( $item['pps_price'] );
        $floor     = pps_cart_price_floor_compute( $pid );
        if ( $submitted >= $floor ) continue;

        $cart->cart_contents[ $key ]['pps_price'] = $floor;
        if ( function_exists( 'error_log' ) ) {
            error_log( sprintf(
                '[pps-floor] forced pps_price %.2f -> %.2f (cart_key=%s product=%d)',
                $submitted, $floor, $key, $pid
            ) );
        }
    }
}, 10 );
