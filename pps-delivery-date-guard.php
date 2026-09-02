<?php
/**
 * Plugin Name: PPS Delivery Date Guard
 * Description: Keeps a non-working day off a PPS order's delivery date, and stops the third-party Estimate Delivery Date plugin from writing a second, competing delivery estimate onto calculator line items. Ships as a small standalone file so it can be deployed without round-tripping the 340 KB main plugin.
 * Version: 1.0.0
 * Author: Priority Print Service
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * Order 87105 was placed on Sunday 2026-08-30 and carried a Sunday delivery date.
 * Two separate systems compute a delivery date for that order, and both share the
 * same blind spot: a zero-day count returns the start date without first moving it
 * to a day the shop is open.
 *
 *   1. PPS. `pps_add_business_days( $start, 0 )` returns $start unchanged, and
 *      `pps_quoted_delivery_date()` accepts any well-formed Y-m-d out of the
 *      calculator's metadata without asking whether the shop is open that day.
 *      The calculator has been fixed to stop sending one (quotedDeliveryYMD), but
 *      an old cart, a restored reorder or a stale tab can still present one, and
 *      the server should not be taking the client's word for it either way.
 *
 *   2. Estimate Delivery Date for WooCommerce Pro (pi-edd). Its
 *      `EstimateCalculator::get_delivery_date()` skips its whole weekend loop when
 *      shipping days is 0 and returns the raw date. PPS calculator products are
 *      WooCommerce *virtual* products, so no shipping method applies to them and
 *      that is exactly the 0-day case. It then writes `pi_item_min_date`,
 *      `pi_item_max_date`, `pi_item_estimate_msg` and friends onto the line item —
 *      keys with no underscore prefix, so WooCommerce shows them.
 *
 * PPS owns the delivery date for any product in the calculator registry: it knows
 * the production days, the 2pm cutoff, the closure list and the transit zone, and
 * pi-edd knows none of them. So for registry products pi-edd is silenced rather
 * than corrected — a second opinion from a system with less information is not a
 * useful second opinion. Products NOT in the registry are left entirely alone;
 * they may legitimately be using pi-edd.
 *
 * This file only ever moves a date FORWARD to the next working day. It never moves
 * one earlier, so it cannot turn a promise the customer accepted into a shorter one.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Shared helpers ───────────────────────────────────────────────────────────

/**
 * Is the shop open on this date?
 *
 * Prefers the main plugin's own answer so there is one closure list, not two.
 * The fallback is weekday-only, which is the conservative direction: it can leave
 * a holiday in place, never introduce a weekend.
 */
function pps_ddg_is_working_day( DateTime $d ): bool {
    if ( function_exists( 'pps_is_business_day' ) ) {
        return (bool) pps_is_business_day( $d );
    }

    $dow = (int) $d->format( 'N' );
    if ( $dow >= 6 ) return false;

    if ( function_exists( 'pps_get_closures' ) ) {
        $closures = (array) pps_get_closures();
        if ( in_array( $d->format( 'Y-m-d' ), $closures, true ) ) return false;
        if ( in_array( $d->format( 'm-d' ),   $closures, true ) ) return false;
    }

    return true;
}

/** The first working day on or after $d. Returns a new object; $d is untouched. */
function pps_ddg_snap_forward( DateTime $d ): DateTime {
    $out = clone $d;
    // Bounded so a pathological closure list cannot spin: a year of closures is
    // already a broken configuration, and looping forever would take the site down.
    for ( $i = 0; $i < 366 && ! pps_ddg_is_working_day( $out ); $i++ ) {
        $out->modify( '+1 day' );
    }
    return $out;
}

/** Is this product driven by a PPS calculator? */
function pps_ddg_is_pps_product( $product_id ): bool {
    if ( ! function_exists( 'pps_get_calculator_for_product' ) ) return false;
    return (bool) pps_get_calculator_for_product( (int) $product_id );
}

/** The shop's own timezone, not the server's. */
function pps_ddg_timezone(): DateTimeZone {
    $tz = 'America/Phoenix';
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $tz  = $cfg['pcf']['shop_timezone'] ?? $tz;
    }
    try { return new DateTimeZone( $tz ); }
    catch ( Exception $e ) { return new DateTimeZone( 'America/Phoenix' ); }
}

// ── 1. Floor the PPS delivery date to a working day ──────────────────────────
//
// Priority 99 on the same hook the main plugin writes at (10), so this reads what
// it wrote and corrects it in place before the item is saved.

add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( ! is_object( $item ) || ! isset( $values['pps_metadata'] ) ) return;

    $ymd = (string) $item->get_meta( '_pps_delivery_date', true );
    if ( $ymd === '' ) return;

    $tz = pps_ddg_timezone();
    $d  = DateTime::createFromFormat( 'Y-m-d|', $ymd, $tz );

    // createFromFormat rolls out-of-range parts over, so "2026-13-45" parses into a
    // real but wrong date. Round-tripping is what catches that.
    if ( ! $d || $d->format( 'Y-m-d' ) !== $ymd ) return;

    if ( pps_ddg_is_working_day( $d ) ) return;

    $fixed = pps_ddg_snap_forward( $d );

    $item->update_meta_data( '_pps_delivery_date', $fixed->format( 'Y-m-d' ) );
    $item->update_meta_data( 'Estimated Delivery', $fixed->format( 'l, M j, Y' ) );

    // Say so on the order. A date that moved after the customer saw it is something
    // production and support both need to know about, and silently correcting it
    // would hide however it got there in the first place.
    if ( is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
        $order->add_order_note( sprintf(
            'Delivery date %s falls on a day the shop is closed; moved to %s.',
            $d->format( 'l, M j, Y' ),
            $fixed->format( 'l, M j, Y' )
        ) );
    }
}, 99, 4 );

// ── 2. Keep pi-edd off PPS line items ────────────────────────────────────────

// Stop it writing pi_item_min_date / pi_item_max_date / pi_item_estimate_msg etc.
add_filter( 'pi_edd_disable_product_estimate_storage', function( $disable, $item, $cart_item_key = '', $values = array(), $order = null ) {
    if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) return $disable;
    return pps_ddg_is_pps_product( $item->get_product_id() ) ? true : $disable;
}, 10, 5 );

// Stop it displaying its order-level estimate on an order that is entirely ours.
// A mixed order (a PPS product alongside a WCPA one) keeps pi-edd's estimate,
// because the non-PPS line genuinely has no other source for one.
add_filter( 'pisol_edd_hide_estimate_in_order', function( $hide, $order ) {
    if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) return $hide;

    $items = $order->get_items();
    if ( empty( $items ) ) return $hide;

    foreach ( $items as $item ) {
        if ( ! method_exists( $item, 'get_product_id' ) ) continue;
        if ( ! pps_ddg_is_pps_product( $item->get_product_id() ) ) return $hide;
    }
    return true;
}, 10, 2 );

// Hide pi-edd's line-item keys in wp-admin. They have no underscore prefix, so
// WooCommerce's own hiding rule does not reach them, and they show up in the order
// item table next to the PPS spec — which is how a stray Sunday gets read as ours.
// This covers orders that were already placed; the filter above covers new ones.
add_filter( 'woocommerce_hidden_order_itemmeta', function( $hidden ) {
    return array_unique( array_merge( (array) $hidden, array(
        'pi_item_min_date',
        'pi_item_max_date',
        'pi_item_min_days',
        'pi_item_max_days',
        'pi_item_estimate_msg',
        'estimate_details',
        '_min_shipping_start_date',
        '_max_shipping_start_date',
    ) ) );
} );
