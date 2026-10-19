<?php
/**
 * PPS Production Analytics — what the shop is getting done, and how fast.
 *
 * Owner, 2026-10-19: "Add analytics screen to review what is being accomplished and at what
 * rate. Graphs and charts, as well as fulfilment stats. Include estimated sheets printed as
 * well as estimated cost (paper + color)."
 *
 * PPS Calculators → Production Analytics. Read-only. For a chosen window (30 / 90 / 180 /
 * 365 days) it reads every order that is processing, on hold or completed and reports:
 *
 *   - Intake: orders and jobs received per week, revenue (order totals).
 *   - Fulfilment: orders completed per week (the completed date is when Shippo's label or a
 *     person closed the order, so it stands in for the ship date), on time against the job's
 *     must-ship date, business days from payment to completion (median, 90th percentile),
 *     the late ones by name, the open backlog and what in it is late.
 *   - Sheets: press sheets for every completed job, read the way the Paper Order reads them
 *     (pps_po_calc_rows() for calculator lines, the operator's saved plan for custom lines),
 *     with no overs added. Custom lines with no plan are not guessed at; they are counted
 *     and named as "not counted".
 *   - Cost: paper + colour, the calculators' own material model — paper price per press
 *     sheet (the price on the order's paper snapshot, else the live config row with the same
 *     label) plus clicks per printed side (pcf printing_black_cost / printing_fullcolor_cost):
 *     a flat side in colour is black + colour, in B&W black; a booklet's inside is black × 2
 *     (+ colour × 2 when not B&W), its cover colour × 2 (black × 2 when B&W) — the same
 *     shapes pps_materials_price_floor() uses. A custom line's colour is not known, so its
 *     sheets are costed as full colour both sides and said to be. No labour, no finishing.
 *
 * Charts are inline SVG drawn here (no outside script). Built results are cached for an hour
 * per window in the transient `pps_analytics_<days>`; "Refresh" rebuilds.
 * tools-analytics-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_AN_CACHE', 'pps_analytics_' );

function pps_an_ymd( $v ) {
    if ( is_object( $v ) && method_exists( $v, 'date' ) ) return $v->date( 'Y-m-d' );
    $v = trim( (string) $v );
    if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return "$m[1]-$m[2]-$m[3]";
    $t = $v !== '' ? strtotime( $v ) : false;
    return $t ? gmdate( 'Y-m-d', $t ) : '';
}

function pps_an_today() {
    if ( function_exists( 'pps_mp_today' ) ) return pps_mp_today();
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( 'now', new DateTimeZone( $tz ) ) )->format( 'Y-m-d' ); }
    catch ( \Throwable $e ) { return gmdate( 'Y-m-d' ); }
}

/** Monday of the week a Y-m-d falls in. */
function pps_an_week( $ymd ) {
    $t = strtotime( $ymd . ' 12:00:00 UTC' );
    return gmdate( 'Y-m-d', $t - ( (int) gmdate( 'N', $t ) - 1 ) * 86400 );
}

/** Weekdays from one date to a later one (0 when the same day or earlier). */
function pps_an_bizdays( $from, $to ) {
    if ( $from === '' || $to === '' || $to <= $from ) return 0;
    $a = strtotime( $from . ' 12:00:00 UTC' ); $b = strtotime( $to . ' 12:00:00 UTC' ); $n = 0;
    for ( $t = $a + 86400; $t <= $b; $t += 86400 ) if ( (int) gmdate( 'N', $t ) < 6 ) $n++;
    return $n;
}

function pps_an_pct( array $sorted, $p ) {
    if ( ! $sorted ) return null;
    $i = (int) ceil( $p * count( $sorted ) ) - 1;
    return $sorted[ max( 0, min( count( $sorted ) - 1, $i ) ) ];
}

/* ── Cost model ─────────────────────────────────────────────── */

/** Click costs and the config's paper prices by label. */
function pps_an_rates( $cfg = null ) {
    if ( $cfg === null ) $cfg = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $pcf = is_array( $cfg['pcf'] ?? null ) ? $cfg['pcf'] : array();
    $prices = array();
    foreach ( array( 'papers_nc', 'papers_cs' ) as $k ) {
        foreach ( (array) ( $cfg[ $k ] ?? array() ) as $p ) {
            if ( is_array( $p ) && isset( $p['label'], $p['price'] ) && (float) $p['price'] > 0 && ! isset( $prices[ $p['label'] ] ) ) $prices[ (string) $p['label'] ] = (float) $p['price'];
        }
    }
    return array(
        'black'  => (float) ( $pcf['printing_black_cost'] ?? 0.01 ),
        'color'  => (float) ( $pcf['printing_fullcolor_cost'] ?? 0.05 ),
        'prices' => $prices,
    );
}

/** The paper's price per press sheet: the order's own snapshot, else the config row by label. */
function pps_an_paper_price( $paper, array $rates ) {
    if ( ! is_array( $paper ) ) return null;
    if ( isset( $paper['price'] ) && (float) $paper['price'] > 0 ) return (float) $paper['price'];
    $l = (string) ( $paper['label'] ?? '' );
    return ( $l !== '' && isset( $rates['prices'][ $l ] ) ) ? $rates['prices'][ $l ] : null;
}

/** Click cost for one press sheet of a row, as the calculators price it. */
function pps_an_clicks( $role, array $meta, array $rates ) {
    $k = $rates['black']; $c = $rates['color'];
    if ( $role === 'inside' ) return ( ( $meta['insideColor'] ?? '' ) === 'bw' ) ? $k * 2 : $k * 2 + $c * 2;
    if ( $role === 'cover' )  return ( ( $meta['coverColor'] ?? '' ) === 'bw' ) ? $k * 2 : $c * 2;
    if ( $role === 'stock' ) {
        $side  = function ( $col ) use ( $k, $c ) { return $col === 'color' ? $k + $c : $k; };
        $sides = (int) ( $meta['sides'] ?? 1 );
        if ( ( $meta['calcType'] ?? '' ) === 'sticker' ) $sides = 1;
        return $side( (string) ( $meta['frontColor'] ?? 'color' ) ) + ( $sides === 2 ? $side( (string) ( $meta['backColor'] ?? 'color' ) ) : 0 );
    }
    return ( $k + $c ) * 2;                       // custom: colour unknown, costed as 4/4
}

/**
 * One line item's sheets and cost. `counted` false = a custom line with no paper plan (not
 * guessed at). `priced` false = sheets known but some paper had no price.
 */
function pps_an_item( $item, array $rates ) {
    $out  = array( 'sheets' => 0, 'paper' => 0.0, 'color' => 0.0, 'counted' => true, 'priced' => true, 'assumed' => false, 'calc' => 'custom', 'by_sheet' => array() );
    $meta = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
    $plan = json_decode( (string) $item->get_meta( '_pps_paper_plan' ), true );
    $rows = null;
    if ( is_array( $meta ) && ! is_array( $plan ) && function_exists( 'pps_po_calc_rows' ) ) {
        $rows = pps_po_calc_rows( $meta );
        $out['calc'] = (string) ( $meta['calcType'] ?? 'calculator' ) ?: 'calculator';
    }
    if ( $rows === null && is_array( $plan ) ) {
        if ( ! empty( $plan['none'] ) ) return $out;               // no press paper: nothing to count
        $rows = function_exists( 'pps_po_plan_rows' ) ? pps_po_plan_rows( $plan ) : array();
        $out['assumed'] = (bool) $rows;
    }
    if ( $rows === null ) { $out['counted'] = false; return $out; }
    foreach ( $rows as $r ) {
        $n = (int) $r['sheets'];
        if ( $n <= 0 ) continue;
        $out['sheets'] += $n;
        $sh = (string) ( $r['sheet'] ?? '13x19' );
        $out['by_sheet'][ $sh ] = ( $out['by_sheet'][ $sh ] ?? 0 ) + $n;
        $price = pps_an_paper_price( $r['paper'], $rates );
        if ( $price === null ) $out['priced'] = false; else $out['paper'] += $price * $n;
        $out['color'] += pps_an_clicks( (string) $r['role'], is_array( $meta ) ? $meta : array(), $rates ) * $n;
    }
    return $out;
}

/* ── Building the numbers ───────────────────────────────────── */

function pps_an_statuses() { return array( 'processing', 'on-hold', 'completed' ); }

/**
 * Everything the screen shows, from order objects. $from..$today is the window; an order
 * counts as received in the week it was paid (else created) and as fulfilled in the week it
 * was completed.
 */
function pps_an_build( $orders, $from, $today, array $rates ) {
    $weeks = array();
    for ( $w = pps_an_week( $from ); $w <= $today; $w = gmdate( 'Y-m-d', strtotime( $w . ' 12:00:00 UTC' ) + 7 * 86400 ) ) {
        $weeks[ $w ] = array( 'received' => 0, 'jobs_in' => 0, 'revenue' => 0.0, 'done' => 0, 'jobs_out' => 0, 'sheets' => 0, 'paper' => 0.0, 'color' => 0.0, 'on_time' => 0, 'late' => 0 );
    }
    $t = array( 'received' => 0, 'jobs_in' => 0, 'revenue' => 0.0, 'done' => 0, 'jobs_out' => 0, 'sheets' => 0, 'paper' => 0.0, 'color' => 0.0,
                'done_revenue' => 0.0, 'on_time' => 0, 'late' => 0, 'no_date' => 0, 'uncounted' => array(), 'unpriced' => 0, 'assumed' => 0 );
    $turn = array(); $late_list = array(); $products = array(); $sheet_sizes = array();
    $open = array( 'orders' => 0, 'jobs' => 0, 'sheets' => 0, 'late' => array(), 'past_start' => 0, 'value' => 0.0, 'uncounted' => 0 );

    foreach ( $orders as $o ) {
        $status = $o->get_status();
        if ( ! in_array( $status, pps_an_statuses(), true ) ) continue;
        $paid  = pps_an_ymd( $o->get_date_paid() ?: $o->get_date_created() );
        $done  = $status === 'completed' ? pps_an_ymd( $o->get_date_completed() ?: $o->get_date_modified() ) : '';
        $total = (float) $o->get_total();
        $name  = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ?: $o->get_billing_company();
        $items = $o->get_items();
        $edit  = method_exists( $o, 'get_edit_order_url' ) ? $o->get_edit_order_url() : admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' );
        $must = ''; $start = ''; $printed_all = true; $calc_items = array();
        foreach ( $items as $item ) {
            $meta = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
            $ms = is_array( $meta ) ? pps_an_ymd( $meta['mustShipByDate'] ?? '' ) : '';
            if ( $ms !== '' && ( $must === '' || $ms < $must ) ) $must = $ms;
            $st = pps_an_ymd( $item->get_meta( 'PPS-Production-Start' ) ?: ( is_array( $meta ) ? ( $meta['productionStartDate'] ?? '' ) : '' ) );
            if ( $st !== '' && ( $start === '' || $st < $start ) ) $start = $st;
            if ( (string) $item->get_meta( '_pps_paper_done' ) === '' ) $printed_all = false;
            $calc_items[] = array( $item, pps_an_item( $item, $rates ) );
        }
        $jobs = count( $items );

        if ( $paid !== '' && $paid >= $from && $paid <= $today ) {
            $w = pps_an_week( $paid );
            if ( isset( $weeks[ $w ] ) ) { $weeks[ $w ]['received']++; $weeks[ $w ]['jobs_in'] += $jobs; $weeks[ $w ]['revenue'] += $total; }
            $t['received']++; $t['jobs_in'] += $jobs; $t['revenue'] += $total;
            foreach ( $calc_items as $ci ) {
                $k = $ci[1]['calc'];
                if ( ! isset( $products[ $k ] ) ) $products[ $k ] = array( 'jobs' => 0, 'revenue' => 0.0, 'sheets' => 0 );
                $products[ $k ]['jobs']++; $products[ $k ]['revenue'] += (float) $ci[0]->get_total();
            }
        }

        if ( $done !== '' && $done >= $from && $done <= $today ) {
            $w = pps_an_week( $done );
            $t['done']++; $t['jobs_out'] += $jobs; $t['done_revenue'] += $total;
            if ( isset( $weeks[ $w ] ) ) { $weeks[ $w ]['done']++; $weeks[ $w ]['jobs_out'] += $jobs; }
            foreach ( $calc_items as $ci ) {
                list( $item, $c ) = $ci;
                if ( ! $c['counted'] ) { $t['uncounted'][] = array( 'order' => $o->get_id(), 'product' => $item->get_name() ); continue; }
                if ( ! $c['priced'] ) $t['unpriced']++;
                if ( $c['assumed'] ) $t['assumed']++;
                $t['sheets'] += $c['sheets']; $t['paper'] += $c['paper']; $t['color'] += $c['color'];
                if ( isset( $weeks[ $w ] ) ) { $weeks[ $w ]['sheets'] += $c['sheets']; $weeks[ $w ]['paper'] += $c['paper']; $weeks[ $w ]['color'] += $c['color']; }
                if ( isset( $products[ $c['calc'] ] ) ) $products[ $c['calc'] ]['sheets'] += $c['sheets'];
                else $products[ $c['calc'] ] = array( 'jobs' => 0, 'revenue' => 0.0, 'sheets' => $c['sheets'] );
                foreach ( $c['by_sheet'] as $sz => $n ) $sheet_sizes[ $sz ] = ( $sheet_sizes[ $sz ] ?? 0 ) + $n;
            }
            if ( $paid !== '' ) $turn[] = pps_an_bizdays( $paid, $done );
            if ( $must === '' ) { $t['no_date']++; }
            elseif ( $done <= $must ) { $t['on_time']++; if ( isset( $weeks[ $w ] ) ) $weeks[ $w ]['on_time']++; }
            else {
                $t['late']++; if ( isset( $weeks[ $w ] ) ) $weeks[ $w ]['late']++;
                $late_list[] = array( 'order' => $o->get_id(), 'url' => $edit, 'customer' => $name, 'must' => $must, 'done' => $done, 'days' => pps_an_bizdays( $must, $done ) );
            }
        }

        if ( $status !== 'completed' ) {
            $open['orders']++; $open['jobs'] += $jobs; $open['value'] += $total;
            foreach ( $calc_items as $ci ) { if ( $ci[1]['counted'] ) $open['sheets'] += $ci[1]['sheets']; else $open['uncounted']++; }
            if ( $must !== '' && $must < $today ) $open['late'][] = array( 'order' => $o->get_id(), 'url' => $edit, 'customer' => $name, 'must' => $must, 'days' => pps_an_bizdays( $must, $today ) );
            if ( $start !== '' && $start < $today && ! $printed_all ) $open['past_start']++;
        }
    }
    sort( $turn );
    usort( $late_list, function ( $a, $b ) { return $b['days'] <=> $a['days'] ?: strcmp( $b['done'], $a['done'] ); } );
    usort( $open['late'], function ( $a, $b ) { return $b['days'] <=> $a['days']; } );
    uasort( $products, function ( $a, $b ) { return $b['jobs'] <=> $a['jobs']; } );
    arsort( $sheet_sizes );
    return array(
        'from' => $from, 'today' => $today, 'weeks' => $weeks, 'totals' => $t, 'products' => $products, 'sheet_sizes' => $sheet_sizes,
        'turn' => array( 'n' => count( $turn ), 'median' => pps_an_pct( $turn, 0.5 ), 'p90' => pps_an_pct( $turn, 0.9 ),
                         'hist' => array_count_values( array_map( function ( $d ) { return min( $d, 15 ); }, $turn ) ) ),
        'late' => array_slice( $late_list, 0, 15 ), 'open' => $open, 'rates' => array( 'black' => $rates['black'], 'color' => $rates['color'] ),
    );
}

/**
 * The order ids a window needs — and only those: paid (created) in it, completed in it, or
 * still open (the backlog). Ids, not objects: the first version loaded every order from 120
 * days before the window, all at once, and on production that ran PHP out of memory or time
 * — a fatal error, shown in the Missive frame as WordPress's "critical error" (2026-10-19).
 */
function pps_an_order_ids( $from ) {
    if ( ! function_exists( 'wc_get_orders' ) ) return array();
    $since = gmdate( 'Y-m-d', strtotime( $from . ' 12:00:00 UTC' ) - 3 * 86400 );   // paid in the window, created a little before
    $q = function ( array $a ) { $r = wc_get_orders( $a + array( 'limit' => 3000, 'return' => 'ids' ) ); return is_array( $r ) ? $r : array(); };
    $ids = array_merge(
        $q( array( 'status' => pps_an_statuses(), 'date_created' => '>=' . $since ) ),
        $q( array( 'status' => array( 'completed' ), 'date_completed' => '>=' . $from ) ),
        $q( array( 'status' => array( 'processing', 'on-hold' ) ) )
    );
    return array_values( array_unique( array_map( 'intval', $ids ) ) );
}

/** The orders, one at a time: each is loaded, used and let go (the object cache is emptied as it goes). */
function pps_an_orders( $from ) {
    $n = 0;
    foreach ( pps_an_order_ids( $from ) as $id ) {
        $o = wc_get_order( $id );
        if ( $o && is_callable( array( $o, 'get_billing_first_name' ) ) ) yield $o;   // never a refund
        if ( ++$n % 100 === 0 && function_exists( 'wp_cache_flush_runtime' ) ) wp_cache_flush_runtime();
    }
}

function pps_an_get( $days, $refresh = false ) {
    $days = in_array( (int) $days, array( 30, 90, 180, 365 ), true ) ? (int) $days : 90;
    $key  = PPS_AN_CACHE . $days;
    if ( ! $refresh ) { $c = get_transient( $key ); if ( is_array( $c ) ) return $c; }
    $today = pps_an_today();
    $from  = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - ( $days - 1 ) * 86400 );
    if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 120 );
    $built = pps_an_build( pps_an_orders( $from ), $from, $today, pps_an_rates() );
    $built['built_at'] = current_time( 'Y-m-d H:i' ); $built['days'] = $days;
    set_transient( $key, $built, HOUR_IN_SECONDS );
    return $built;
}

/* The 30- and 90-day windows are built hourly in the background, so opening the screen (or
   the Missive panel's Analytics) normally reads a finished result. */
add_action( 'pps_analytics_refresh', function () { pps_an_get( 90, true ); pps_an_get( 30, true ); pps_an_labels( true ); } );
add_action( 'init', function () {
    if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'pps_analytics_refresh' ) ) wp_schedule_event( time() + 300, 'hourly', 'pps_analytics_refresh' );
} );

/* ── Charts (inline SVG) ────────────────────────────────────── */

function pps_an_nice_max( $v ) {
    if ( $v <= 0 ) return 1;
    $p = pow( 10, floor( log10( $v ) ) );
    foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) if ( $m * $p >= $v ) return $m * $p;
    return 10 * $p;
}

/**
 * Bars per category. $series = [ [name, colour, values[]], … ] aligned to $labels.
 * $stack true stacks the series; false draws them side by side. $fmt formats axis numbers.
 */
function pps_an_svg_bars( array $labels, array $series, $stack = false, $fmt = null, $h = 220 ) {
    $count = $fmt === null;                       // counts: whole-number gridlines
    $fmt = $fmt ?: function ( $v ) { return number_format( $v ); };
    $n = count( $labels ); if ( ! $n || ! $series ) return '<p class="description">No data in this window.</p>';
    $max = 0;
    for ( $i = 0; $i < $n; $i++ ) {
        if ( $stack ) { $s = 0; foreach ( $series as $se ) $s += (float) ( $se[2][ $i ] ?? 0 ); $max = max( $max, $s ); }
        else foreach ( $series as $se ) $max = max( $max, (float) ( $se[2][ $i ] ?? 0 ) );
    }
    $max = ( $count && $max <= 40 ) ? max( 4, (int) ceil( $max / 4 ) * 4 ) : pps_an_nice_max( $max );
    $W = 440;                                     // one width for every chart, so the text is one size (and legible in the Missive sidebar)
    $L = 52; $T = 8; $B = 34; $ch = $h - $T - $B; $cw = ( $W - $L - 8 ) / $n;
    $o = '<svg class="pps-an-chart" viewBox="0 0 ' . $W . ' ' . $h . '" width="100%" style="max-width:' . ( $W * 1.4 ) . 'px" role="img">';
    for ( $g = 0; $g <= 4; $g++ ) {
        $y = $T + $ch - $ch * $g / 4;
        $o .= '<line x1="' . $L . '" x2="' . ( $W - 4 ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '" stroke="#e3e3e3"/>';
        $o .= '<text x="' . ( $L - 6 ) . '" y="' . round( $y + 4, 1 ) . '" text-anchor="end" font-size="10" fill="#777">' . esc_html( $fmt( $max * $g / 4 ) ) . '</text>';
    }
    $every = max( 1, (int) ceil( $n / 9 ) );
    for ( $i = 0; $i < $n; $i++ ) {
        $x0 = $L + $i * $cw + $cw * 0.15; $bw = $cw * 0.7; $acc = 0;
        $tip = array( $labels[ $i ] );
        foreach ( $series as $k => $se ) {
            $v = (float) ( $se[2][ $i ] ?? 0 ); $tip[] = $se[0] . ': ' . $fmt( $v );
            $bh = $ch * $v / $max;
            if ( $stack ) { $x = $x0; $w = $bw; $y = $T + $ch - $acc - $bh; $acc += $bh; }
            else { $w = $bw / count( $series ); $x = $x0 + $k * $w; $y = $T + $ch - $bh; }
            if ( $bh > 0 ) $o .= '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( max( 1, $w - 1 ), 1 ) . '" height="' . round( $bh, 1 ) . '" fill="' . esc_attr( $se[1] ) . '"><title>' . esc_html( implode( ' · ', $tip ) ) . '</title></rect>';
        }
        if ( $i % $every === 0 ) $o .= '<text x="' . round( $x0 + $bw / 2, 1 ) . '" y="' . ( $h - $B + 14 ) . '" text-anchor="middle" font-size="10" fill="#555">' . esc_html( $labels[ $i ] ) . '</text>';
    }
    $lx = $L;
    foreach ( $series as $se ) {
        $o .= '<rect x="' . $lx . '" y="' . ( $h - 12 ) . '" width="10" height="10" fill="' . esc_attr( $se[1] ) . '"/><text x="' . ( $lx + 14 ) . '" y="' . ( $h - 3 ) . '" font-size="11" fill="#333">' . esc_html( $se[0] ) . '</text>';
        $lx += 24 + 7 * strlen( $se[0] );
    }
    return $o . '</svg>';
}

/* ── Shipping labels bought, per day ───────────────────────────────
 * Owner, 2026-10-19: "all shipping labels bought per day with links to the shippo label
 * downloads for that day". Read from Shippo's own transaction list, so it holds every label on
 * the account — bought from the Missive panel or in Shippo's web app alike. The label PDF links
 * are Shippo's (signed, about a year). Carrier, service and price live on each label's RATE,
 * one lookup each; rates never change, so each is looked up once and kept in
 * `pps_an_label_rates` (at most PPS_AN_LABEL_LOOKUPS new ones per build, so a long history
 * fills in over a few builds rather than in one slow request). The WooCommerce order is the
 * label's metadata ("Order 87485 · box 1 of 1", the panel's) or its Shippo order's number
 * (`pps_an_label_orders`, also kept). Last PPS_AN_LABEL_DAYS days whatever the window, cached
 * 10 minutes (`pps_an_labels` transient); read-only — it never buys, voids or changes a label. */

define( 'PPS_AN_LABEL_DAYS', 30 );
define( 'PPS_AN_LABEL_LOOKUPS', 40 );

function pps_an_shop_tz() {
    try { return new DateTimeZone( function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix' ); }
    catch ( \Throwable $e ) { return new DateTimeZone( 'America/Phoenix' ); }
}

/** Shippo's UTC timestamp as the shop's day and time. */
function pps_an_label_when( $iso ) {
    try { $t = new DateTime( (string) $iso, new DateTimeZone( 'UTC' ) ); } catch ( \Throwable $e ) { return array( '', '' ); }
    $t->setTimezone( pps_an_shop_tz() );
    return array( $t->format( 'Y-m-d' ), $t->format( 'g:i a' ) );
}

/** The day $days ago at 00:00 shop time, in UTC, as Shippo's lower bound. */
function pps_an_label_since( $today, $days ) {
    $t = new DateTime( $today . ' 00:00:00', pps_an_shop_tz() );
    $t->modify( '-' . ( max( 1, (int) $days ) - 1 ) . ' days' );
    $t->setTimezone( new DateTimeZone( 'UTC' ) );
    return $t->format( 'Y-m-d\TH:i:s' );
}

function pps_an_labels( $refresh = false ) {
    if ( ! $refresh ) { $c = get_transient( 'pps_an_labels' ); if ( is_array( $c ) ) return $c; }
    $today = pps_an_today();
    $out = array( 'days' => PPS_AN_LABEL_DAYS, 'today' => $today, 'list' => array(), 'pending' => 0, 'error' => '', 'built_at' => current_time( 'mysql' ) );
    if ( ! function_exists( 'pps_ms_api' ) ) { $out['error'] = 'The Missive Shipping file (pps-missive-ship.php) is not loaded.'; return $out; }
    $since = pps_an_label_since( $today, PPS_AN_LABEL_DAYS );
    $tx = array();
    for ( $page = 1; $page <= 20; $page++ ) {
        $r = pps_ms_api( 'GET', 'transactions/?results=100&page=' . $page . '&object_created_gte=' . rawurlencode( $since ) );
        if ( is_wp_error( $r ) ) { $out['error'] = $r->get_error_message(); break; }
        foreach ( (array) ( $r['results'] ?? array() ) as $t ) if ( is_array( $t ) ) $tx[] = $t;
        if ( empty( $r['next'] ) ) break;
    }
    $rates  = get_option( 'pps_an_label_rates', array() );  $rates  = is_array( $rates ) ? $rates : array();
    $orders = get_option( 'pps_an_label_orders', array() ); $orders = is_array( $orders ) ? $orders : array();
    $known  = get_transient( 'pps_ms_orders' );
    if ( is_array( $known ) ) foreach ( $known as $num => $id ) if ( ! isset( $orders[ $id ] ) ) $orders[ $id ] = (string) $num;
    $lookups = 0; $grew = false;
    foreach ( $tx as $t ) {
        $st = (string) ( $t['status'] ?? '' );
        if ( ! in_array( $st, array( 'SUCCESS', 'REFUNDPENDING', 'REFUNDED', 'REFUNDREJECTED' ), true ) || empty( $t['label_url'] ) ) continue;
        $rid = (string) ( $t['rate'] ?? '' );
        if ( $rid !== '' && ! isset( $rates[ $rid ] ) && $lookups < PPS_AN_LABEL_LOOKUPS ) {
            $lookups++;
            $rt = pps_ms_api( 'GET', 'rates/' . rawurlencode( $rid ) );
            if ( ! is_wp_error( $rt ) ) {
                $rates[ $rid ] = array( 'carrier' => (string) ( $rt['provider'] ?? '' ), 'service' => (string) ( $rt['servicelevel']['name'] ?? '' ),
                                        'amount' => (float) ( $rt['amount'] ?? 0 ), 'currency' => (string) ( $rt['currency'] ?? 'USD' ) );
                $grew = true;
            }
        }
        $num = '';
        if ( preg_match( '/Order\s+#?(\d+)/i', (string) ( $t['metadata'] ?? '' ), $m ) ) $num = $m[1];
        $sid = (string) ( $t['order'] ?? '' );
        if ( $num === '' && $sid !== '' ) {
            if ( ! isset( $orders[ $sid ] ) && $lookups < PPS_AN_LABEL_LOOKUPS ) {
                $lookups++;
                $so = pps_ms_api( 'GET', 'orders/' . rawurlencode( $sid ) );
                if ( ! is_wp_error( $so ) ) { $orders[ $sid ] = preg_replace( '/\D/', '', (string) ( $so['order_number'] ?? '' ) ); $grew = true; }
            }
            $num = (string) ( $orders[ $sid ] ?? '' );
        }
        $rate = $rates[ $rid ] ?? null;
        if ( ! $rate ) $out['pending']++;
        list( $day, $time ) = pps_an_label_when( $t['object_created'] ?? '' );
        if ( $day === '' ) continue;
        $out['list'][] = array(
            'day' => $day, 'time' => $time, 'at' => (string) ( $t['object_created'] ?? '' ), 'order' => $num,
            'carrier' => $rate['carrier'] ?? '', 'service' => $rate['service'] ?? '', 'amount' => $rate ? (float) $rate['amount'] : null,
            'tracking' => (string) ( $t['tracking_number'] ?? '' ), 'tracking_url' => (string) ( $t['tracking_url_provider'] ?? '' ),
            'label' => (string) $t['label_url'], 'status' => $st,
            'from_panel' => (bool) preg_match( '/·\s*box\s+\d+\s+of\s+\d+/u', (string) ( $t['metadata'] ?? '' ) ),
        );
    }
    if ( $grew ) {
        if ( count( $rates ) > 3000 ) $rates = array_slice( $rates, -3000, null, true );
        if ( count( $orders ) > 3000 ) $orders = array_slice( $orders, -3000, null, true );
        update_option( 'pps_an_label_rates', $rates, false );
        update_option( 'pps_an_label_orders', $orders, false );
    }
    usort( $out['list'], function ( $a, $b ) { return strcmp( $b['at'], $a['at'] ); } );
    if ( $out['error'] === '' ) set_transient( 'pps_an_labels', $out, 10 * MINUTE_IN_SECONDS );
    return $out;
}

/** The labels section of the screen. $L is pps_an_labels(). */
function pps_an_labels_output( array $L ) {
    echo '<div class="card"><h2>Shipping labels bought, per day <span class="description">(last ' . (int) $L['days'] . ' days, every label on the Shippo account)</span></h2>';
    if ( $L['error'] !== '' ) echo '<p class="late">Shippo could not be read: ' . esc_html( $L['error'] ) . '</p>';
    $by = array();
    foreach ( $L['list'] as $l ) $by[ $l['day'] ][] = $l;
    $labels = array(); $counts = array(); $spend = array();
    $t = new DateTime( $L['today'] . ' 12:00:00', new DateTimeZone( 'UTC' ) ); $t->modify( '-' . ( $L['days'] - 1 ) . ' days' );
    for ( $i = 0; $i < $L['days']; $i++ ) {
        $ymd = $t->format( 'Y-m-d' ); $rows = $by[ $ymd ] ?? array();
        $labels[] = $t->format( 'M j' );
        $counts[] = count( array_filter( $rows, function ( $r ) { return $r['status'] !== 'REFUNDED'; } ) );
        $spend[]  = array_sum( array_map( function ( $r ) { return $r['status'] === 'REFUNDED' ? 0 : (float) $r['amount']; }, $rows ) );
        $t->modify( '+1 day' );
    }
    if ( $L['list'] ) {
        echo '<div class="grid2"><div>' . pps_an_svg_bars( $labels, array( array( 'Labels', '#2271b1', $counts ) ) ) . '</div><div>'
           . pps_an_svg_bars( $labels, array( array( 'Postage', '#00a32a', $spend ) ), false, function ( $v ) { return '$' . number_format( $v ); } ) . '</div></div>';
    } elseif ( $L['error'] === '' ) echo '<p>No labels bought in the last ' . (int) $L['days'] . ' days.</p>';
    $first = true;
    foreach ( $by as $day => $rows ) {
        $live = array_filter( $rows, function ( $r ) { return $r['status'] !== 'REFUNDED'; } );
        $sum  = array_sum( array_map( function ( $r ) { return (float) $r['amount']; }, $live ) );
        $head = gmdate( 'D M j', strtotime( $day . ' 12:00:00 UTC' ) ) . ' — ' . count( $live ) . ' label' . ( count( $live ) === 1 ? '' : 's' ) . ' · $' . number_format( $sum, 2 )
              . ( count( $rows ) > count( $live ) ? ' · ' . ( count( $rows ) - count( $live ) ) . ' refunded' : '' );
        echo '<details' . ( $first ? ' open' : '' ) . ' style="margin:6px 0"><summary style="cursor:pointer;font-weight:600">' . esc_html( $head ) . '</summary>';
        echo '<table class="widefat striped" style="margin-top:4px"><thead><tr><th>Time</th><th>Order</th><th>Carrier · service</th><th>Tracking</th><th>Postage</th><th>Label</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $ord = $r['order'] !== '' ? '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order'] ) ) . '">#' . (int) $r['order'] . '</a>' : '<span class="description">not linked</span>';
            $svc = trim( $r['carrier'] . ( $r['service'] !== '' ? ' · ' . $r['service'] : '' ) );
            $trk = $r['tracking'] === '' ? '—' : ( preg_match( '#^https://#', $r['tracking_url'] ) ? '<a href="' . esc_url( $r['tracking_url'] ) . '">' . esc_html( $r['tracking'] ) . '</a>' : esc_html( $r['tracking'] ) );
            $st  = $r['status'] === 'SUCCESS' ? '' : ' <span class="late">' . esc_html( strtolower( str_replace( 'REFUND', 'refund ', $r['status'] ) ) ) . '</span>';
            echo '<tr><td>' . esc_html( $r['time'] ) . ( $r['from_panel'] ? ' <span class="description" title="Bought from the Missive panel">panel</span>' : '' ) . '</td><td>' . $ord . '</td><td>' . ( $svc !== '' ? esc_html( $svc ) : '<span class="description">—</span>' ) . $st . '</td><td>' . $trk . '</td>'
               . '<td>' . ( $r['amount'] === null ? '—' : '$' . number_format( $r['amount'], 2 ) ) . '</td>'
               . '<td>' . ( preg_match( '#^https://#', $r['label'] ) ? '<a href="' . esc_url( $r['label'] ) . '">Label PDF</a>' : '—' ) . '</td></tr>';
        }
        echo '</tbody></table></details>';
        $first = false;
    }
    if ( $L['pending'] ) echo '<p class="description">Carrier, service and postage are still being looked up for ' . (int) $L['pending'] . ' label' . ( $L['pending'] === 1 ? '' : 's' ) . ' — they fill in on the next refresh.</p>';
    echo '<p class="description">Read from Shippo ' . esc_html( $L['built_at'] ) . ' (kept 10 minutes). Refunded labels are listed but not counted.</p></div>';
}

/* ── Screen ─────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Production Analytics', '📊 Production Analytics', 'manage_woocommerce', 'pps-analytics', 'pps_an_render' );
}, 30 );

function pps_an_money( $v ) { return '$' . number_format( (float) $v, 0 ); }

function pps_an_render() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    $days    = (int) ( $_GET['days'] ?? 90 );
    $refresh = ! empty( $_GET['refresh'] ) && check_admin_referer( 'pps_an_refresh' );
    $d   = pps_an_get( $days, $refresh );
    $d['labels'] = pps_an_labels( $refresh );
    $url = admin_url( 'admin.php?page=pps-analytics' );
    echo '<div class="wrap pps-an"><h1>📊 Production Analytics</h1>';
    pps_an_output( $d, function ( $n ) use ( $url ) { return add_query_arg( 'days', $n, $url ); },
        wp_nonce_url( add_query_arg( array( 'days' => $d['days'], 'refresh' => 1 ), $url ), 'pps_an_refresh' ) );
    echo '</div>';
}

/**
 * The screen's body, shared by wp-admin and the Missive panel's frame. $days_url( n ) is the
 * link for another window; $refresh_url rebuilds this one.
 */
function pps_an_output( array $d, $days_url, $refresh_url ) {
    $t  = $d['totals']; $op = $d['open'];
    $wk = $d['weeks'];
    $labels = array_map( function ( $w ) { return gmdate( 'M j', strtotime( $w . ' 12:00:00 UTC' ) ); }, array_keys( $wk ) );
    $col = function ( $k ) use ( $wk ) { return array_values( array_map( function ( $r ) use ( $k ) { return $r[ $k ]; }, $wk ) ); };
    $n_weeks = max( 1, count( $wk ) );
    $cost = $t['paper'] + $t['color'];
    $done_with_date = $t['on_time'] + $t['late'];
    echo '<style>.pps-an .tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:12px 0}.pps-an .tile{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px}.pps-an .tile b{display:block;font-size:22px;line-height:1.3}.pps-an .tile span{color:#646970;font-size:12px}.pps-an .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:14px}.pps-an .card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin-bottom:14px}.pps-an .card h2{margin:0 0 8px;font-size:15px}.pps-an table.widefat td,.pps-an table.widefat th{padding:5px 8px}.pps-an .late{color:#b32d2e}.pps-an .chips a{margin-right:6px}</style>';
    echo '<p class="chips">Window: ';
    foreach ( array( 30, 90, 180, 365 ) as $n ) echo $n === (int) $d['days'] ? '<strong>' . $n . ' days</strong> ' : '<a href="' . esc_url( $days_url( $n ) ) . '">' . $n . ' days</a> ';
    echo ' · ' . esc_html( gmdate( 'M j, Y', strtotime( $d['from'] ) ) ) . ' – ' . esc_html( gmdate( 'M j, Y', strtotime( $d['today'] ) ) );
    echo ' · built ' . esc_html( $d['built_at'] ) . ' <a class="button button-small" href="' . esc_url( $refresh_url ) . '">Refresh</a></p>';

    $tile = function ( $v, $l, $cls = '' ) { return '<div class="tile"><b class="' . $cls . '">' . $v . '</b><span>' . $l . '</span></div>'; };
    $pl = function ( $n, $w ) { return number_format( $n ) . ' ' . $w . ( (int) $n === 1 ? '' : 's' ); };
    echo '<div class="tiles">'
       . $tile( number_format( $t['received'] ), 'orders received · ' . $pl( $t['jobs_in'], 'job' ) )
       . $tile( pps_an_money( $t['revenue'] ), 'revenue received · ' . pps_an_money( $t['revenue'] / $n_weeks ) . '/week' )
       . $tile( number_format( $t['done'] ), 'orders fulfilled · ' . number_format( $t['done'] / $n_weeks, 1 ) . '/week' )
       . $tile( $done_with_date ? round( 100 * $t['on_time'] / $done_with_date ) . '%' : '—', 'shipped by must-ship date (' . $t['on_time'] . ' of ' . $done_with_date . ')' )
       . $tile( $d['turn']['median'] !== null ? $d['turn']['median'] . ' days' : '—', 'median paid → fulfilled (business days)' . ( $d['turn']['p90'] !== null ? ' · 90% within ' . $d['turn']['p90'] : '' ) )
       . $tile( number_format( $t['sheets'] ), 'press sheets printed (est.) · ' . number_format( $t['sheets'] / $n_weeks ) . '/week' )
       . $tile( pps_an_money( $cost ), 'paper + colour (est.) · paper ' . pps_an_money( $t['paper'] ) . ' · colour ' . pps_an_money( $t['color'] ) )
       . $tile( $t['done_revenue'] > 0 ? round( 100 * $cost / $t['done_revenue'] ) . '%' : '—', 'paper + colour as a share of fulfilled revenue' )
       . $tile( number_format( $op['orders'] ), 'open orders now · ' . $pl( $op['jobs'], 'job' ) . ' · ' . $pl( $op['sheets'], 'sheet' ) )
       . $tile( count( $op['late'] ), 'open orders past must-ship · ' . $op['past_start'] . ' past start date, not printed', $op['late'] ? 'late' : '' )
       . '</div>';

    $money = function ( $v ) { return '$' . number_format( $v ); };
    echo '<div class="grid2">';
    echo '<div class="card"><h2>Orders received vs fulfilled, per week</h2>' . pps_an_svg_bars( $labels, array( array( 'Received', '#2271b1', $col( 'received' ) ), array( 'Fulfilled', '#00a32a', $col( 'done' ) ) ) ) . '</div>';
    echo '<div class="card"><h2>Press sheets printed (est.), per week</h2>' . pps_an_svg_bars( $labels, array( array( 'Sheets', '#8c5fbf', $col( 'sheets' ) ) ) ) . '</div>';
    echo '<div class="card"><h2>Paper + colour cost (est.), per week</h2>' . pps_an_svg_bars( $labels, array( array( 'Paper', '#dba617', $col( 'paper' ) ), array( 'Colour', '#d63638', $col( 'color' ) ) ), true, $money ) . '</div>';
    echo '<div class="card"><h2>Revenue received, per week</h2>' . pps_an_svg_bars( $labels, array( array( 'Revenue', '#135e96', $col( 'revenue' ) ) ), false, $money ) . '</div>';
    echo '<div class="card"><h2>Fulfilled on time vs late, per week</h2>' . pps_an_svg_bars( $labels, array( array( 'On time', '#00a32a', $col( 'on_time' ) ), array( 'Late', '#d63638', $col( 'late' ) ) ), true ) . '</div>';
    $hl = array(); $hv = array();
    for ( $i = 0; $i <= 15; $i++ ) { $hl[] = $i === 15 ? '15+' : (string) $i; $hv[] = (int) ( $d['turn']['hist'][ $i ] ?? 0 ); }
    echo '<div class="card"><h2>Turnaround: business days from payment to fulfilment</h2>' . pps_an_svg_bars( $hl, array( array( 'Orders', '#2271b1', $hv ) ) ) . '</div>';
    echo '</div>';

    echo '<div class="grid2"><div class="card"><h2>By product</h2><table class="widefat striped"><thead><tr><th>Product</th><th>Jobs received</th><th>Revenue</th><th>Sheets printed (est.)</th></tr></thead><tbody>';
    if ( ! $d['products'] ) echo '<tr><td colspan="4">None in this window.</td></tr>';
    foreach ( $d['products'] as $k => $p ) echo '<tr><td>' . esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $k ) ) ) . '</td><td>' . number_format( $p['jobs'] ) . '</td><td>' . pps_an_money( $p['revenue'] ) . '</td><td>' . number_format( $p['sheets'] ) . '</td></tr>';
    echo '</tbody></table>';
    if ( $d['sheet_sizes'] ) { echo '<p>Press sheets by size: '; $bits = array(); foreach ( $d['sheet_sizes'] as $sz => $n ) $bits[] = esc_html( str_replace( 'x', '×', $sz ) ) . ' ' . number_format( $n ); echo implode( ' · ', $bits ) . '</p>'; }
    echo '</div>';

    echo '<div class="card"><h2>Late shipments in this window</h2>';
    if ( ! $d['late'] ) echo '<p>None — every fulfilled order with a must-ship date went out on time.</p>';
    else {
        echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Customer</th><th>Must ship</th><th>Fulfilled</th><th>Late by</th></tr></thead><tbody>';
        foreach ( $d['late'] as $l ) echo '<tr><td><a href="' . esc_url( $l['url'] ) . '">#' . (int) $l['order'] . '</a></td><td>' . esc_html( $l['customer'] ) . '</td><td>' . esc_html( $l['must'] ) . '</td><td>' . esc_html( $l['done'] ) . '</td><td class="late">' . (int) $l['days'] . ' business day' . ( $l['days'] === 1 ? '' : 's' ) . '</td></tr>';
        echo '</tbody></table>';
    }
    if ( $op['late'] ) {
        echo '<h2 style="margin-top:12px">Open and past must-ship</h2><table class="widefat striped"><tbody>';
        foreach ( array_slice( $op['late'], 0, 15 ) as $l ) echo '<tr><td><a href="' . esc_url( $l['url'] ) . '">#' . (int) $l['order'] . '</a></td><td>' . esc_html( $l['customer'] ) . '</td><td>must ship ' . esc_html( $l['must'] ) . '</td><td class="late">' . (int) $l['days'] . ' business day' . ( $l['days'] === 1 ? '' : 's' ) . ' late</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div></div>';

    if ( isset( $d['labels'] ) && is_array( $d['labels'] ) ) pps_an_labels_output( $d['labels'] );

    echo '<div class="card"><h2>How these are estimated</h2><ul style="list-style:disc;margin-left:20px">';
    echo '<li><b>Fulfilled</b> = the order reached Completed in the window (Shippo completes an order when its label is bought), so the completed date stands in for the ship date. <b>On time</b> = completed on or before the job\'s must-ship date; ' . (int) $t['no_date'] . ' fulfilled order' . ( $t['no_date'] === 1 ? '' : 's' ) . ' had no must-ship date (custom orders) and ' . ( $t['no_date'] === 1 ? 'is' : 'are' ) . ' left out of that rate.</li>';
    echo '<li><b>Sheets</b> are press sheets as the Paper Order counts them (the yield each job was priced on, or the saved paper plan for a custom line), without overs.';
    if ( $t['uncounted'] ) echo ' <span class="late">' . count( $t['uncounted'] ) . ' fulfilled custom line' . ( count( $t['uncounted'] ) === 1 ? '' : 's' ) . ' had no paper plan and ' . ( count( $t['uncounted'] ) === 1 ? 'is' : 'are' ) . ' not counted</span> (orders ' . esc_html( implode( ', ', array_unique( array_map( function ( $u ) { return '#' . $u['order']; }, array_slice( $t['uncounted'], 0, 12 ) ) ) ) ) . ( count( $t['uncounted'] ) > 12 ? ', …' : '' ) . ').';
    echo '</li><li><b>Cost</b> is paper + colour only: each sheet\'s paper price plus clicks per printed side at the configured black (' . esc_html( '$' . rtrim( rtrim( number_format( $d['rates']['black'], 4 ), '0' ), '.' ) ) . ') and colour (' . esc_html( '$' . rtrim( rtrim( number_format( $d['rates']['color'], 4 ), '0' ), '.' ) ) . ') click rates — the calculators\' own material figures. No labour, finishing or shipping.';
    if ( $t['assumed'] ) echo ' ' . (int) $t['assumed'] . ' custom line' . ( $t['assumed'] === 1 ? '' : 's' ) . ' (colour not recorded) costed as full colour both sides.';
    if ( $t['unpriced'] ) echo ' <span class="late">' . (int) $t['unpriced'] . ' job' . ( $t['unpriced'] === 1 ? '' : 's' ) . ' used a paper with no price on file; their paper cost is missing.</span>';
    echo '</li><li><b>Revenue</b> = order totals as paid, by the week paid. Received and fulfilled weeks are Monday–Sunday.</li></ul></div>';
}

/* ── The same screen inside the Missive panel ───────────────────
 * Owner, 2026-10-19: "add the analytics to the missive panel too". Missive's frame has no
 * WordPress login, so the page is opened with the panel key (`pps_mp_key_ok()`), like the
 * panel's ship and impose pages. Read-only. Links (an order's edit screen) are handed to the
 * panel, which opens them with Missive.openURL. A rebuild through this page is allowed once a
 * minute (`pps_an_frame_refresh` transient), since the key travels in the URL. */

function pps_an_frame_html( array $d, $key ) {
    $base = add_query_arg( array( 'pps_missive_analytics' => 1, 'k' => $key ), home_url( '/' ) );
    ob_start();
    pps_an_output( $d, function ( $n ) use ( $base ) { return add_query_arg( 'days', $n, $base ); }, add_query_arg( array( 'days' => $d['days'], 'refresh' => 1 ), $base ) );
    $body = ob_get_clean();
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production Analytics</title>'
         . '<style>body{font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:8px;background:#f6f7f7;color:#1d2327}a{color:#2563eb;text-decoration:none}'
         . 'table.widefat{border-collapse:collapse;width:100%;background:#fff}table.widefat th{text-align:left}table.widefat td,table.widefat th{border-top:1px solid #eee;font-size:12px}'
         . '.button{border:1px solid #2563eb;border-radius:3px;padding:1px 6px}.description{color:#777}ul{padding-left:4px}</style></head>'
         . '<body><div class="pps-an">' . $body . '</div>'
         . '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[href]");if(!a)return;var u=a.href;'
         . 'if(u.indexOf("pps_missive_analytics=")>0)return;'
         . 'e.preventDefault();if(!/^https:\/\//.test(u))return;if(window.parent!==window)window.parent.postMessage({ppsOpen:u},location.origin);else window.open(u,"_blank","noopener");});</script>'
         . '</body></html>';
}

add_action( 'wp_loaded', function () {
    if ( ! isset( $_GET['pps_missive_analytics'] ) ) return;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    header_remove( 'X-Frame-Options' );
    $k = (string) wp_unslash( $_GET['k'] ?? '' );
    if ( ! function_exists( 'pps_mp_key_ok' ) || ! pps_mp_key_ok( $k ) ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo 'This link is not valid. Open analytics from the Missive panel.'; exit; }
    $refresh = ! empty( $_GET['refresh'] ) && ! get_transient( 'pps_an_frame_refresh' );
    if ( $refresh ) set_transient( 'pps_an_frame_refresh', 1, 60 );
    try { $d = pps_an_get( (int) ( $_GET['days'] ?? 90 ), $refresh ); $d['labels'] = pps_an_labels( $refresh ); }
    catch ( \Throwable $e ) {
        error_log( 'PPS analytics: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo 'The analytics could not be built: ' . $e->getMessage() . '. The admin screen (PPS Calculators → Production Analytics) shows the same numbers.'; exit;
    }
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'Referrer-Policy: no-referrer' );
    echo pps_an_frame_html( $d, $k );
    exit;
} );

