<?php
/**
 * PPS Paper / Stock Report — which OPEN jobs are waiting on paper we don't stock.
 *
 * The question this answers: "what's on the floor right now that needs a stock
 * I have to order?" Every non-inventoried paper carries a lead time (+1 day
 * special order, +2 to +4 days factory), so an open job on one of them is a
 * job whose clock started before the paper was even ordered. That list existed
 * nowhere: paper lives in the line item's `_pps_metadata`, which is neither on
 * the order list table nor in any report.
 *
 * Deliberately read-only, and deliberately cached. Scanning open orders and
 * JSON-decoding every line item is cheap but not free, so the built report is kept
 * in an option and refreshed at most hourly — the same shape as the product
 * feed's transient. Nothing here writes to an order.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require). Depends on
 * pps-config-admin.php for pps_paper_is_inventoried()/pps_paper_enrich().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_PAPER_REPORT_OPTION', 'pps_paper_report' );
define( 'PPS_PAPER_REPORT_TTL', HOUR_IN_SECONDS );

/**
 * Statuses that count as "open" — work that is or will be on the floor.
 * Completed and cancelled jobs cannot need paper ordered.
 */
function pps_paper_report_open_statuses() {
    return apply_filters( 'pps_paper_report_statuses', array( 'processing', 'on-hold', 'pending' ) );
}

/**
 * Every paper row the shop knows about, keyed by normalised `val`.
 *
 * The order carries a snapshot of the paper row it was bought with, but the
 * live config is the authority on whether we stock it *now* — a stock can be
 * added to or dropped from inventory after an order is placed.
 */
function pps_paper_report_catalog() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $cache = array();
    if ( ! function_exists( 'pps_get_config' ) ) return $cache;

    $cfg = pps_get_config();
    $pcf = isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ? $cfg['pcf'] : array();

    // Paper pools as the config stores them. Names differ per pool but the row
    // shape (label/val/days/factory) is uniform.
    $pools = array( 'papers_nc', 'papers_cs', 'papers', 'cover_papers' );
    foreach ( $pools as $pool ) {
        if ( empty( $pcf[ $pool ] ) || ! is_array( $pcf[ $pool ] ) ) continue;
        $rows = $pcf[ $pool ];
        if ( function_exists( 'pps_paper_enrich' ) ) $rows = pps_paper_enrich( $rows, $pool );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['val'] ) ) continue;
            $cache[ pps_paper_report_key( $row['val'] ) ] = $row;
        }
    }
    return $cache;
}

/** Normalise a paper `val` to the string form the config keys on (0.002, 2.006, 14). */
function pps_paper_report_key( $val ) {
    return rtrim( rtrim( sprintf( '%.3F', (float) $val ), '0' ), '.' );
}

/**
 * Classify one paper: is it a stock we hold, and if not, how long is the wait?
 *
 * Resolution order — live config row, then the snapshot stored on the order,
 * then the val itself. That last fallback is not a guess: the catalog assigns
 * val < 1 to inventoried stock, 1.xx to special order and 2.xx to factory, so
 * a val alone still answers the question when a row has been renamed or
 * retired out of the config (docs/PAPER_CATALOG.md).
 */
function pps_paper_report_classify( $paper ) {
    $label = '';
    $val   = null;
    if ( is_array( $paper ) ) {
        $label = isset( $paper['label'] ) ? trim( (string) $paper['label'] ) : '';
        $val   = isset( $paper['val'] ) ? $paper['val'] : null;
    } elseif ( is_scalar( $paper ) && $paper !== '' ) {
        $val = $paper;
    }
    if ( $val === null && $label === '' ) return null;

    $row = null;
    if ( $val !== null ) {
        $catalog = pps_paper_report_catalog();
        $key     = pps_paper_report_key( $val );
        if ( isset( $catalog[ $key ] ) ) $row = $catalog[ $key ];
    }
    // Snapshot from the order, when the live config no longer lists the stock.
    if ( ! $row && is_array( $paper ) ) $row = $paper;

    if ( $label === '' && is_array( $row ) && ! empty( $row['label'] ) ) {
        $label = trim( (string) $row['label'] );
    }

    $days    = 0;
    $factory = false;
    $known   = false;
    if ( is_array( $row ) && ( isset( $row['days'] ) || isset( $row['factory'] ) ) ) {
        $known   = true;
        $days    = (int) ( $row['days'] ?? 0 );
        $factory = ! empty( $row['factory'] );
    }

    if ( $known ) {
        $in_stock = function_exists( 'pps_paper_is_inventoried' )
            ? pps_paper_is_inventoried( $row )
            : ( ! $factory && ! $days );
    } else {
        // Val-tier fallback, per the catalog's own numbering.
        $in_stock = ( $val !== null && (float) $val < 1 );
        if ( $val !== null && ! $in_stock ) {
            $days = ( (float) $val >= 2 ) ? 2 : 1;
        }
    }

    return array(
        'label'    => $label !== '' ? $label : ( $val !== null ? 'val ' . pps_paper_report_key( $val ) : 'unknown' ),
        'val'      => $val !== null ? pps_paper_report_key( $val ) : '',
        'in_stock' => (bool) $in_stock,
        'days'     => $in_stock ? 0 : max( $days, 1 ),
        'factory'  => (bool) $factory,
        'resolved' => $known,
    );
}

/**
 * Pull every paper an item uses. Booklets carry inside + cover (cover may be
 * "same as inside"); flats carry a single `paper`. Anything else is ignored
 * rather than guessed at.
 */
function pps_paper_report_item_papers( array $meta ) {
    $out = array();

    $add = function ( $paper, $role ) use ( &$out ) {
        $c = pps_paper_report_classify( $paper );
        if ( ! $c ) return;
        $c['role'] = $role;
        // Same stock in both roles is one paper to order, not two.
        foreach ( $out as &$existing ) {
            if ( $existing['label'] === $c['label'] ) {
                if ( strpos( $existing['role'], $role ) === false ) $existing['role'] .= ' + ' . $role;
                return;
            }
        }
        unset( $existing );
        $out[] = $c;
    };

    if ( isset( $meta['insidePaper'] ) ) $add( $meta['insidePaper'], 'inside' );

    $cover_mode = isset( $meta['coverMode'] ) ? (string) $meta['coverMode'] : '';
    if ( isset( $meta['coverPaper'] ) && $cover_mode !== 'same' ) {
        $add( $meta['coverPaper'], 'cover' );
    }

    if ( isset( $meta['paper'] ) ) $add( $meta['paper'], 'stock' );

    return $out;
}

/**
 * Build the report. One row per open line item that uses a non-inventoried
 * paper; plus totals so the admin screen can say "nothing to order" honestly.
 */
function pps_paper_report_build() {
    $report = array(
        'built'      => time(),
        'rows'       => array(),
        'scanned'    => 0,
        'calc_items' => 0,
        'error'      => '',
    );

    if ( ! function_exists( 'wc_get_orders' ) ) {
        $report['error'] = 'WooCommerce not active';
        return $report;
    }

    $orders = wc_get_orders( array(
        'limit'   => 200,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => pps_paper_report_open_statuses(),
    ) );
    if ( ! is_array( $orders ) ) return $report;

    foreach ( $orders as $order ) {
        $report['scanned']++;
        foreach ( $order->get_items() as $item ) {
            $raw = $item->get_meta( '_pps_metadata' );
            if ( ! $raw ) continue;                       // legacy/WCPA line — not ours
            $meta = json_decode( (string) $raw, true );
            if ( ! is_array( $meta ) ) continue;
            $report['calc_items']++;

            $papers = pps_paper_report_item_papers( $meta );
            $odd    = array_values( array_filter( $papers, static function ( $p ) { return ! $p['in_stock']; } ) );
            if ( ! $odd ) continue;

            $date = $order->get_date_created();
            $report['rows'][] = array(
                'order'    => $order->get_id(),
                'date'     => $date ? $date->date( 'Y-m-d' ) : '',
                'status'   => $order->get_status(),
                'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'product'  => $item->get_name(),
                'qty'      => (string) ( $meta['totalQty'] ?? $meta['qty'] ?? $item->get_quantity() ),
                'size'     => (string) ( $meta['sizeLabel'] ?? '' ),
                'job'      => (string) ( $meta['jobName'] ?? '' ),
                'papers'   => $odd,
                'all'      => $papers,
                'spec'     => (string) $item->get_meta( 'PPS-Spec' ),
            );
        }
    }

    return $report;
}

/** Cached accessor. Rebuilds when missing or older than the TTL. */
function pps_paper_report_get( $force = false ) {
    $cached = get_option( PPS_PAPER_REPORT_OPTION, null );
    if ( ! $force && is_array( $cached ) && isset( $cached['built'] )
         && ( time() - (int) $cached['built'] ) < PPS_PAPER_REPORT_TTL ) {
        return $cached;
    }
    $report = pps_paper_report_build();
    update_option( PPS_PAPER_REPORT_OPTION, $report, false );
    return $report;
}

/**
 * Keep the cache warm off the back of staff traffic only — a customer request
 * must never pay for this scan.
 */
function pps_paper_report_maybe_refresh() {
    if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) return;
    $cached = get_option( PPS_PAPER_REPORT_OPTION, null );
    if ( is_array( $cached ) && isset( $cached['built'] )
         && ( time() - (int) $cached['built'] ) < PPS_PAPER_REPORT_TTL ) return;
    pps_paper_report_get();
}
add_action( 'admin_init', 'pps_paper_report_maybe_refresh' );
add_action( 'rest_api_init', 'pps_paper_report_maybe_refresh' );

/**
 * Hourly cron is the real refresh: it runs with no user, so the report is
 * current even when nobody has opened wp-admin for a week. The capability-
 * gated hooks above only cover the gap between cron ticks.
 */
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'pps_paper_report_refresh' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'pps_paper_report_refresh' );
    }
} );
add_action( 'pps_paper_report_refresh', function () { pps_paper_report_get( true ); } );

/* ─────────────────────────────────────────────────────────────
 * Admin screen — PPS Calculators → Paper Report
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'Paper Report',
        '📄 Paper Report',
        'manage_options',
        'pps-paper-report',
        'pps_paper_report_render_admin'
    );
}, 60 );

function pps_paper_report_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $force  = isset( $_GET['refresh'] ) && check_admin_referer( 'pps_paper_report_refresh' );
    $report = pps_paper_report_get( $force );

    $refresh_url = wp_nonce_url(
        admin_url( 'admin.php?page=pps-paper-report&refresh=1' ),
        'pps_paper_report_refresh'
    );

    echo '<div class="wrap"><h1>Paper Report</h1>';
    echo '<p>Open jobs (' . esc_html( implode( ', ', pps_paper_report_open_statuses() ) )
       . ') that use a paper we do not hold in inventory — every one of these carries a lead time '
       . 'before it can run.</p>';

    if ( ! empty( $report['error'] ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $report['error'] ) . '</p></div></div>';
        return;
    }

    echo '<p><em>' . esc_html( sprintf(
        '%d open order%s scanned, %d calculator line item%s. Built %s.',
        (int) $report['scanned'], (int) $report['scanned'] === 1 ? '' : 's',
        (int) $report['calc_items'], (int) $report['calc_items'] === 1 ? '' : 's',
        $report['built'] ? human_time_diff( (int) $report['built'] ) . ' ago' : 'never'
    ) ) . ' <a href="' . esc_url( $refresh_url ) . '">Refresh now</a></em></p>';

    if ( ! $report['rows'] ) {
        echo '<div class="notice notice-success inline"><p><strong>Nothing to order.</strong> '
           . 'Every open job is on paper we stock.</p></div></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
       . '<th>Order</th><th>Date</th><th>Customer</th><th>Job</th>'
       . '<th>Paper to order</th><th>Lead</th></tr></thead><tbody>';

    foreach ( $report['rows'] as $r ) {
        $papers = array();
        $lead   = 0;
        foreach ( $r['papers'] as $p ) {
            $papers[] = esc_html( $p['label'] ) . ' <span style="color:#777">(' . esc_html( $p['role'] ) . ')</span>';
            $lead = max( $lead, (int) $p['days'] );
        }
        $job = $r['job'] !== '' ? $r['job'] : $r['product'];
        echo '<tr>'
           . '<td><a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order'] ) ) . '">#'
           . (int) $r['order'] . '</a><br><span style="color:#777">' . esc_html( $r['status'] ) . '</span></td>'
           . '<td>' . esc_html( $r['date'] ) . '</td>'
           . '<td>' . esc_html( $r['customer'] ) . '</td>'
           . '<td>' . esc_html( $job ) . '<br><span style="color:#777">'
           . esc_html( trim( $r['size'] . ' · ' . $r['qty'] . ' qty', ' ·' ) ) . '</span></td>'
           . '<td>' . implode( '<br>', $papers ) . '</td>'
           . '<td>+' . (int) $lead . ' day' . ( $lead === 1 ? '' : 's' ) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';
}
