<?php
/**
 * PPS Ship Schedule — every open job by the day it must ship.
 *
 * Owner, 2026-10-16: "get me a list of must ships by Oct 16". The must-ship date lives on
 * each calculator line (`mustShipByDate` in `_pps_metadata`, the same value the Job Ticket
 * prints as "Must ship by") and the delivery date in `_pps_delivery_date` (the one the
 * delivery-date guard floors to a working day). Neither was readable anywhere but one order
 * at a time. A custom order (quote, pay link) has only a "Requested delivery" date, so it is
 * listed by that and marked "must-ship not known".
 *
 * PPS Calculators → Ship Schedule. Read-only; built into `wp_options['pps_ship_schedule']`
 * hourly (cron `pps_ship_schedule_refresh`) and whenever the screen is opened, so the list
 * can also be read with wp_get_option. Jobs left off the Paper Order as "already printed"
 * (`_pps_paper_done`) are shown as printed, not dropped. tools-ship-schedule-test.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_SHIP_SCHEDULE_OPTION', 'pps_ship_schedule' );

/** A Y-m-d out of anything date-shaped, or ''. */
function pps_ship_ymd( $v ) {
    $v = trim( (string) $v );
    if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return "$m[1]-$m[2]-$m[3]";
    $t = $v !== '' ? strtotime( $v ) : false;
    return $t ? gmdate( 'Y-m-d', $t ) : '';
}

/** One row per open line item. Pure apart from the order objects it is given. */
function pps_ship_schedule_rows( array $orders ) {
    $rows = array();
    foreach ( $orders as $order ) {
        $customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_company();
        foreach ( $order->get_items() as $item ) {
            $meta = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
            $row  = array(
                'order' => $order->get_id(), 'status' => $order->get_status(), 'customer' => $customer,
                'product' => $item->get_name(), 'job' => '', 'qty' => '', 'size' => '',
                'must_ship' => '', 'delivery' => pps_ship_ymd( $item->get_meta( '_pps_delivery_date' ) ),
                'rush' => (float) $item->get_meta( '_pps_rush' ) > 0, 'source' => 'calculator',
                'printed' => (string) $item->get_meta( '_pps_paper_done' ) !== '',
            );
            if ( is_array( $meta ) ) {
                $row['job']       = (string) ( $meta['jobName'] ?? '' );
                $row['qty']       = (string) ( $meta['totalQty'] ?? $meta['qty'] ?? '' );
                $row['size']      = (string) ( $meta['sizeLabel'] ?? '' );
                $row['must_ship'] = pps_ship_ymd( $meta['mustShipByDate'] ?? '' );
                if ( $row['delivery'] === '' ) $row['delivery'] = pps_ship_ymd( $meta['estimatedDeliveryDate'] ?? '' );
                if ( ! $row['rush'] && ! empty( $meta['rushCost'] ) && (float) $meta['rushCost'] > 0 ) $row['rush'] = true;
            } else {
                $req = pps_ship_ymd( $item->get_meta( 'Requested delivery' ) );
                if ( $req === '' && method_exists( $order, 'get_meta' ) ) $req = pps_ship_ymd( $order->get_meta( '_pps_requested_date' ) );
                $row['delivery'] = $row['delivery'] ?: $req;
                $row['source']   = 'custom';
            }
            $rows[] = $row;
        }
    }
    // Soonest must-ship first; then by delivery; undated last.
    usort( $rows, function ( $a, $b ) {
        $ka = ( $a['must_ship'] ?: ( $a['delivery'] ?: '9999' ) ) . $a['delivery'];
        $kb = ( $b['must_ship'] ?: ( $b['delivery'] ?: '9999' ) ) . $b['delivery'];
        return strcmp( $ka, $kb ) ?: ( $a['order'] <=> $b['order'] );
    } );
    return $rows;
}

function pps_ship_schedule_build() {
    $out = array( 'built' => time(), 'rows' => array(), 'error' => '' );
    if ( ! function_exists( 'wc_get_orders' ) ) { $out['error'] = 'WooCommerce not active'; return $out; }
    try {
        $orders = wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC',
            'status' => apply_filters( 'pps_ship_schedule_statuses', array( 'processing', 'on-hold' ) ) ) );
        $out['rows'] = pps_ship_schedule_rows( is_array( $orders ) ? $orders : array() );
    } catch ( \Throwable $e ) {
        $out['error'] = $e->getMessage();
    }
    update_option( PPS_SHIP_SCHEDULE_OPTION, $out, false );
    return $out;
}

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'pps_ship_schedule_refresh' ) ) wp_schedule_event( time() + 60, 'hourly', 'pps_ship_schedule_refresh' );
} );
add_action( 'pps_ship_schedule_refresh', 'pps_ship_schedule_build' );

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Ship Schedule', '🚚 Ship Schedule', 'manage_woocommerce', 'pps-ship-schedule', 'pps_ship_schedule_render' );
}, 61 );

function pps_ship_schedule_render() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    $s     = pps_ship_schedule_build();
    $today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    echo '<div class="wrap"><h1>Ship Schedule</h1><p>Open jobs (processing, on hold) by the day they must ship. '
       . 'Must-ship is the date the job was quoted on; a custom order has only its requested delivery.</p>';
    if ( $s['error'] ) { echo '<div class="notice notice-error"><p>' . esc_html( $s['error'] ) . '</p></div></div>'; return; }
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Must ship</th><th>Delivery</th><th>Order</th><th>Customer</th><th>Job</th></tr></thead><tbody>';
    foreach ( $s['rows'] as $r ) {
        $ms = $r['must_ship'];
        $style = $ms !== '' && $ms < $today ? ' style="color:#b32d2e;font-weight:600"' : ( $ms === $today ? ' style="font-weight:600"' : '' );
        echo '<tr><td' . $style . '>' . esc_html( $ms !== '' ? $ms : '—' ) . ( $ms !== '' && $ms < $today ? ' (late)' : ( $ms === $today ? ' (today)' : '' ) ) . '</td>'
           . '<td>' . esc_html( $r['delivery'] ?: '—' ) . ( $r['rush'] ? ' <strong>RUSH</strong>' : '' ) . '</td>'
           . '<td><a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order'] ) ) . '">#' . (int) $r['order'] . '</a></td>'
           . '<td>' . esc_html( $r['customer'] ) . '</td>'
           . '<td>' . esc_html( $r['job'] !== '' ? $r['job'] : $r['product'] )
           . ( $r['size'] !== '' || $r['qty'] !== '' ? ' <span style="color:#777">' . esc_html( trim( $r['size'] . ' · ' . $r['qty'] . ' qty', ' ·' ) ) . '</span>' : '' )
           . ( $r['source'] === 'custom' ? ' <span style="color:#777">(custom — must-ship not known)</span>' : '' )
           . ( $r['printed'] ? ' <span style="color:#2271b1">printed</span>' : '' ) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
