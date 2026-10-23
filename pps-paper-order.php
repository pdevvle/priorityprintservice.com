<?php
/**
 * PPS Paper Order — a copy-paste supply order built from the open jobs.
 *
 * Owner, 2026-10-15: "build a basic / copy paste paper order based on orders (incl custom
 * orders!!) submitted through the site. Using the yield calculations I want it to suggest
 * the number of sheets that need to be ordered, but also including further extrapolation
 * into 28x40 parent sheets." It replaced the daily exceptions email (pps-job-health.php).
 *
 * PPS Calculators → Paper Order. For every open job (processing, on hold) it works out the
 * press sheets each paper needs, adds overs, groups by paper and press sheet, converts to
 * parent sheets, and prints the result as text to paste into an email to the supplier.
 *
 * WHERE THE SHEET COUNTS COME FROM
 *
 * - Calculator orders carry the yield they were priced on, in `_pps_metadata` → `debug`.
 *   Flats: `pressSheets` (= quantity ÷ `imp`). A flat whose `imp` is under 1 is priced at a
 *   "half-up" rate on 13×19 but runs 1-up on 13×27.5, so it orders quantity sheets of
 *   13×27.5 — the calculators' own `_physSheets` rule. Booklets: `parentSheets` is the
 *   inside count; covers are counted here, one cover spread per book at `floor(imp ÷ 2)`
 *   spreads per sheet (a spread is the footprint of one inside leaf). Saddle and coupon
 *   PRICE covers at `books ÷ imp`, half that — fine for a price, short for a paper order.
 *   Self-cover books have no separate cover.
 * - Custom orders (quotes, pay links, the legacy product forms) carry only free text. The
 *   screen suggests a paper, finished size and quantity from that text, the operator
 *   confirms or corrects it once, and the plan is saved on the line (`_pps_paper_plan`).
 *   A flat plan is imposed with the flats' own rule (`pps_po_flat_imp`, a port of
 *   calcBrochureImp); a booklet or anything odd is entered as press sheets directly.
 *
 * WHAT IT DOES NOT KNOW: grain direction, makeready per job, and whether a factory stock is
 * bought pre-cut. Overs is one percentage on the screen. Parent sheets assume straight cuts
 * from one parent size, both orientations tried.
 *
 * Writes only `_pps_paper_plan` and `_pps_paper_done` (hidden item meta) and its settings
 * option. Never changes an order's status. Loaded by pps-calculators.php.
 * tools-paper-order-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_PO_SETTINGS', 'pps_paper_order_settings' );

/* ─────────────────────────────────────────────────────────────
 * Pure helpers (no WordPress) — the test drives these directly
 * ───────────────────────────────────────────────────────────── */

/**
 * The flats' imposition rule, ported verbatim from calcBrochureImp (calc-brochure.html):
 * usable long axis 18.5″ on 13×19 (27″ on 13×27.5), short axis 12.5″, thresholds that
 * already allow for bleed and gutter. Tries both orientations. 0.5 means "half-up".
 */
function pps_po_flat_imp( $long, $short, $sheet_long = 18.5 ) {
    $L = (float) $sheet_long ?: 18.5;
    $calc = function ( $w, $h ) use ( $L ) {
        if ( $w <= 0 || $h <= 0 ) return 0;
        $r1 = $L / $w;
        $across = $r1 < 0.5 ? 0 : ( $r1 < 1 ? 0.5 : ( $r1 < 1.48 ? 1 : ( $r1 < 3 ? 2 : ( $r1 < 4 ? 3 : ( $r1 < 5 ? 4 : ( $r1 < 6 ? 5 : ( $r1 < 7 ? 6 : ( $r1 < 8 ? 7 : 8 ) ) ) ) ) ) ) );
        $r2 = 12.5 / $h;
        $down = $r2 < 1 ? 0 : ( $r2 < 2 ? 1 : ( $r2 < 3 ? 2 : ( $r2 < 4 ? 3 : 4 ) ) );
        return $across * $down;
    };
    return max( $calc( (float) $long, (float) $short ), $calc( (float) $short, (float) $long ) );
}

/** "13x19", "13×27.5", "13 x 19" → [13.0, 19.0]. Anything unreadable is 13×19. */
function pps_po_sheet_dims( $sheet ) {
    if ( preg_match( '/(\d+(?:\.\d+)?)\s*[x×X]\s*(\d+(?:\.\d+)?)/u', (string) $sheet, $m ) ) {
        return array( (float) $m[1], (float) $m[2] );
    }
    return array( 13.0, 19.0 );
}

/** Canonical name for a press sheet: "13x19", "13x27.5". */
function pps_po_sheet_name( $sheet ) {
    list( $a, $b ) = pps_po_sheet_dims( $sheet );
    return pps_po_dec( min( $a, $b ) ) . 'x' . pps_po_dec( max( $a, $b ) );
}

/** How many press sheets one parent sheet cuts into: straight cuts, both orientations. */
function pps_po_parent_yield( $sw, $sh, $pw, $ph ) {
    if ( $sw <= 0 || $sh <= 0 || $pw <= 0 || $ph <= 0 ) return 0;
    $a = floor( $pw / $sw ) * floor( $ph / $sh );
    $b = floor( $pw / $sh ) * floor( $ph / $sw );
    return (int) max( $a, $b );
}

/** A dimension or percentage without trailing zeros: 40 → "40", 27.50 → "27.5", 2.0 → "2". */
function pps_po_dec( $n ) {
    $s = number_format( (float) $n, 3, '.', '' );
    return strpos( $s, '.' ) !== false ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
}

/** Number formatting for the order text: 1234 → "1,234". */
function pps_po_n( $n ) { return number_format( (float) $n, 0, '.', ',' ); }

/**
 * Sheets for a flat piece of finished size W×H at a quantity. Same rule as the flats:
 * under 1-up on 13×19 runs on 13×27.5 at its yield there (at least 1-up).
 */
function pps_po_flat_sheets( $w, $h, $qty ) {
    $long = max( (float) $w, (float) $h ); $short = min( (float) $w, (float) $h );
    $qty  = max( 0, (int) $qty );
    if ( $long <= 0 || $short <= 0 || ! $qty ) return null;
    if ( $long > 27 || $short > 12.5 ) return null;              // bigger than any press sheet
    $imp = pps_po_flat_imp( $long, $short );
    if ( $imp >= 1 ) {
        return array( 'sheet' => '13x19', 'sheets' => (int) ceil( $qty / $imp ),
                      'basis' => pps_po_n( $qty ) . ' ÷ ' . pps_po_n( $imp ) . '-up' );
    }
    $imp27 = max( 1, pps_po_flat_imp( $long, $short, 27 ) );
    return array( 'sheet' => '13x27.5', 'sheets' => (int) ceil( $qty / $imp27 ),
                  'basis' => pps_po_n( $qty ) . ' ÷ ' . pps_po_n( $imp27 ) . '-up on 13×27.5 (too big for 13×19)' );
}

/**
 * The press sheets a calculator line needs, one row per paper role. Returns null when the
 * order does not carry enough to count (an old build with no `debug`): it then goes to the
 * "needs paper info" list like a custom order, rather than being guessed at.
 *
 * Each row: role, paper (the raw paper array from the order), sheet, sheets, basis.
 */
function pps_po_calc_rows( array $meta ) {
    $d   = is_array( $meta['debug'] ?? null ) ? $meta['debug'] : array();
    $qty = (int) ( $meta['totalQty'] ?? $d['totalQty'] ?? $meta['qty'] ?? 0 );
    $rows = array();

    if ( isset( $meta['insidePaper'] ) ) {                        // booklet
        $inside = (float) ( $d['parentSheets'] ?? 0 );
        $imp    = (float) ( $d['imp'] ?? 0 );
        if ( $inside <= 0 || $imp <= 0 || $qty <= 0 ) return null;
        $sheet  = pps_po_sheet_name( $d['pressSheet'] ?? '13x19' );
        $pages  = (int) ( $d['totalPages'] ?? $meta['pages'] ?? 0 );
        $rows[] = array(
            'role' => 'inside', 'paper' => $meta['insidePaper'], 'sheet' => $sheet,
            'sheets' => (int) ceil( $inside - 1e-9 ),
            'basis' => pps_po_n( $qty ) . ' books' . ( $pages ? ' × ' . $pages . ' pp' : '' ) . ', ' . pps_po_dec( $imp ) . '-up',
        );
        if ( isset( $meta['coverPaper'] ) && (string) ( $meta['coverMode'] ?? '' ) !== 'same' ) {
            $per = max( 1, (int) floor( $imp / 2 ) );
            $rows[] = array(
                'role' => 'cover', 'paper' => $meta['coverPaper'], 'sheet' => $sheet,
                'sheets' => (int) ceil( $qty / $per ),
                'basis' => pps_po_n( $qty ) . ' covers, ' . $per . ' per sheet' . ( $imp < 2 ? ' — check: a 1-up book\'s cover may need a larger sheet' : '' ),
            );
        }
        return $rows;
    }

    if ( isset( $meta['paper'] ) ) {                              // flat or sticker
        $imp = (float) ( $d['imp'] ?? 0 );
        if ( $imp <= 0 && ! empty( $meta['longEdge'] ) && ! empty( $meta['shortEdge'] ) ) {
            $imp = pps_po_flat_imp( max( $meta['longEdge'], $meta['shortEdge'] ), min( $meta['longEdge'], $meta['shortEdge'] ) );
        }
        $q = (int) ( $d['qty'] ?? $qty );
        if ( $imp <= 0 || $q <= 0 ) return null;
        if ( $imp < 1 ) {
            // Priced at a sub-1 yield on 13×19; physically 1-up on 13×27.5 (the calculators' _physSheets).
            $rows[] = array( 'role' => 'stock', 'paper' => $meta['paper'], 'sheet' => '13x27.5',
                             'sheets' => $q, 'basis' => pps_po_n( $q ) . ' × 1-up on 13×27.5' );
        } else {
            $ps = isset( $d['pressSheets'] ) ? (float) $d['pressSheets'] : $q / $imp;
            $rows[] = array( 'role' => 'stock', 'paper' => $meta['paper'], 'sheet' => '13x19',
                             'sheets' => (int) ceil( $ps - 1e-9 ),
                             'basis' => pps_po_n( $q ) . ' ÷ ' . pps_po_dec( $imp ) . '-up' );
        }
        return $rows;
    }
    return null;
}

/**
 * Rows from an operator's plan for a custom line. A plan row is either
 * { paper, w, h, qty } (a flat, imposed here) or { paper, sheets, sheet } (entered directly).
 * `paper` is { val, label } from the catalog or { label } typed in.
 */
function pps_po_plan_rows( array $plan ) {
    $rows = array();
    foreach ( (array) ( $plan['rows'] ?? array() ) as $r ) {
        if ( ! is_array( $r ) || empty( $r['paper'] ) ) continue;
        $sheets = (int) ( $r['sheets'] ?? 0 );
        if ( $sheets > 0 ) {
            $rows[] = array( 'role' => 'custom', 'paper' => $r['paper'], 'sheet' => pps_po_sheet_name( $r['sheet'] ?? '13x19' ),
                             'sheets' => $sheets, 'basis' => 'entered as ' . pps_po_n( $sheets ) . ' press sheets' );
            continue;
        }
        $f = pps_po_flat_sheets( $r['w'] ?? 0, $r['h'] ?? 0, $r['qty'] ?? 0 );
        if ( ! $f ) continue;
        $sz = pps_po_dec( $r['w'] ) . '×' . pps_po_dec( $r['h'] );
        $rows[] = array( 'role' => 'custom', 'paper' => $r['paper'], 'sheet' => $f['sheet'],
                         'sheets' => $f['sheets'], 'basis' => $sz . ', ' . $f['basis'] );
    }
    return $rows;
}

/**
 * A best guess at a custom line's job from its text: finished size, quantity and paper.
 * Only a suggestion — the operator confirms it before it counts. $catalog is
 * [ key => row ] as pps_po_catalog() returns.
 */
function pps_po_suggest( $text, array $catalog ) {
    $t   = (string) $text;
    $out = array( 'w' => '', 'h' => '', 'qty' => '', 'paper' => '' );

    // Prefer an "opened"/"flat" size ("8x8 (8x24 Opened)"), else the first size given.
    $sizes = array();
    if ( preg_match_all( '/(\d+(?:\.\d+)?)\s*["”]?\s*[x×X]\s*(\d+(?:\.\d+)?)\s*["”]?(\s*\(?\s*(?:open|opened|flat))?/iu', $t, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $s ) $sizes[] = array( (float) $s[1], (float) $s[2], ! empty( $s[3] ) );
    }
    foreach ( $sizes as $s ) { if ( $s[2] ) { $out['w'] = $s[0]; $out['h'] = $s[1]; break; } }
    if ( $out['w'] === '' && $sizes ) { $out['w'] = $sizes[0][0]; $out['h'] = $sizes[0][1]; }

    if ( preg_match( '/(\d[\d,]*)\s*(?:pcs|pieces|pc|qty|ea|each|copies|cards|sheets)\b/i', $t, $q )
      || preg_match( '/\b(?:qty|quantity)\s*[:#]?\s*(\d[\d,]*)/i', $t, $q ) ) {
        $out['qty'] = (int) str_replace( ',', '', $q[1] );
    }

    // Paper: the catalog row sharing the most words with the text, weight required.
    $words = function ( $s ) {
        $s = strtolower( preg_replace( '/(\d+)\s*(lb|#|pt)\b/i', '$1$2', (string) $s ) );
        $s = str_replace( array( 'cardstock', 'card stock' ), 'cover', $s );
        return array_filter( preg_split( '/[^a-z0-9.]+/', $s ) );
    };
    $tw = array_flip( $words( $t ) );
    $best = 0; $pick = '';
    foreach ( $catalog as $key => $row ) {
        $lw = $words( $row['label'] ?? '' );
        $weight = array_values( array_filter( $lw, function ( $w ) { return (bool) preg_match( '/^\d+(lb|pt|#)$/', $w ); } ) );
        if ( ! $weight || ! isset( $tw[ $weight[0] ] ) ) continue;
        $score = 0; foreach ( $lw as $w ) if ( isset( $tw[ $w ] ) ) $score++;
        if ( $score > $best ) { $best = $score; $pick = $key; }
    }
    if ( $best >= 2 ) $out['paper'] = $pick;
    return $out;
}

/**
 * Group job rows by paper and press sheet, add overs, convert to parent sheets.
 * $jobs: [ { order, customer, product, job, rows: [ { paper(classified), sheet, sheets, basis, role } ] } ]
 */
function pps_po_aggregate( array $jobs, array $settings ) {
    $overs = max( 0, (float) ( $settings['overs_pct'] ?? 0 ) );
    $pw = (float) ( $settings['parent_w'] ?? 28 ); $ph = (float) ( $settings['parent_h'] ?? 40 );
    $groups = array();
    foreach ( $jobs as $j ) {
        foreach ( $j['rows'] as $r ) {
            $p   = $r['paper'];
            $key = ( $p['val'] !== '' ? 'v' . $p['val'] : 'l' . strtolower( $p['label'] ) ) . '|' . $r['sheet'];
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array( 'label' => $p['label'], 'val' => $p['val'], 'in_stock' => $p['in_stock'],
                    'days' => $p['days'], 'factory' => $p['factory'], 'sheet' => $r['sheet'], 'net' => 0, 'jobs' => array() );
            }
            $groups[ $key ]['net'] += (int) $r['sheets'];
            $groups[ $key ]['jobs'][] = array( 'order' => $j['order'], 'customer' => $j['customer'],
                'what' => $j['job'] !== '' ? $j['job'] : $j['product'], 'role' => $r['role'], 'sheets' => (int) $r['sheets'], 'basis' => $r['basis'] );
        }
    }
    foreach ( $groups as &$g ) {
        $g['total'] = (int) ceil( $g['net'] * ( 1 + $overs / 100 ) - 1e-9 );
        list( $sw, $sh ) = pps_po_sheet_dims( $g['sheet'] );
        $g['per_parent'] = pps_po_parent_yield( $sw, $sh, $pw, $ph );
        $g['parents'] = $g['per_parent'] ? (int) ceil( $g['total'] / $g['per_parent'] ) : null;
    }
    unset( $g );
    uasort( $groups, function ( $a, $b ) {
        if ( $a['in_stock'] !== $b['in_stock'] ) return $a['in_stock'] ? 1 : -1;   // to-order first
        return strcasecmp( $a['label'] . $a['sheet'], $b['label'] . $b['sheet'] );
    } );
    return array_values( $groups );
}

/** The text to paste into an email to the supplier. */
function pps_po_text( array $groups, array $settings, $when = '', $include_stocked = false ) {
    $pw = (float) ( $settings['parent_w'] ?? 28 ); $ph = (float) ( $settings['parent_h'] ?? 40 );
    $parent = pps_po_dec( $pw ) . '×' . pps_po_dec( $ph );
    $overs  = (float) ( $settings['overs_pct'] ?? 0 );
    $out = array( 'PAPER ORDER' . ( $when !== '' ? ' — ' . $when : '' ),
                  'Parent sheets ' . $parent . '. Press sheets include ' . pps_po_dec( $overs ) . '% overs.', '' );
    $n = 0;
    foreach ( $groups as $g ) {
        if ( $g['in_stock'] && ! $include_stocked ) continue;
        $n++;
        $press = str_replace( 'x', '×', $g['sheet'] );
        $line  = $n . '. ' . $g['label'] . ( $g['in_stock'] ? ' (stocked)' : '' );
        $out[] = $line;
        $out[] = '   ' . pps_po_n( $g['total'] ) . ' sheets ' . $press
               . ( $g['parents'] !== null ? ' = ' . pps_po_n( $g['parents'] ) . ' parent sheets ' . $parent . ' (' . $g['per_parent'] . ' out)' : ' (does not cut from ' . $parent . ')' );
        $orders = array();
        foreach ( $g['jobs'] as $j ) $orders[] = '#' . $j['order'];
        $out[] = '   For ' . implode( ', ', array_unique( $orders ) );
        $out[] = '';
    }
    if ( ! $n ) $out[] = 'Nothing to order: every open job is on paper we stock.';
    return rtrim( implode( "\n", $out ) ) . "\n";
}

/* ─────────────────────────────────────────────────────────────
 * WordPress side
 * ───────────────────────────────────────────────────────────── */

function pps_po_settings() {
    $s = get_option( PPS_PO_SETTINGS, array() );
    if ( ! is_array( $s ) ) $s = array();
    return array(
        'overs_pct' => isset( $s['overs_pct'] ) ? max( 0, min( 50, (float) $s['overs_pct'] ) ) : 5,
        'parent_w'  => isset( $s['parent_w'] ) && (float) $s['parent_w'] > 0 ? (float) $s['parent_w'] : 28,
        'parent_h'  => isset( $s['parent_h'] ) && (float) $s['parent_h'] > 0 ? (float) $s['parent_h'] : 40,
    );
}

/** Statuses whose jobs still need paper. Unpaid (pending) orders do not buy paper. */
function pps_po_statuses() {
    return apply_filters( 'pps_paper_order_statuses', array( 'processing', 'on-hold' ) );
}

/** Every configured paper, keyed by normalised val, for the plan form and the text guess. */
function pps_po_catalog() {
    return function_exists( 'pps_paper_report_catalog' ) ? pps_paper_report_catalog() : array();
}

/** Classify a paper the way the Paper Report does, preferring the live config's name. */
function pps_po_classify( $paper, $sticker = false ) {
    if ( function_exists( 'pps_paper_report_classify' ) ) {
        $c = pps_paper_report_classify( $paper, ! $sticker );
    } else {
        $c = array( 'label' => is_array( $paper ) ? (string) ( $paper['label'] ?? '' ) : '', 'val' => '', 'in_stock' => false, 'days' => 0, 'factory' => false );
    }
    if ( ! $c ) return null;
    $cat = pps_po_catalog();
    if ( ! $sticker && $c['val'] !== '' && isset( $cat[ $c['val'] ]['label'] ) ) $c['label'] = (string) $cat[ $c['val'] ]['label'];
    if ( is_array( $paper ) && ! empty( $paper['typed'] ) ) { $c['in_stock'] = false; $c['val'] = ''; }
    return $c;
}

/** Everything a person can read on a line: its name and visible meta. For the text guess. */
function pps_po_item_text( $item ) {
    $parts = array( $item->get_name() );
    foreach ( $item->get_meta_data() as $m ) {
        $d = $m->get_data();
        if ( strpos( (string) $d['key'], '_' ) === 0 || ! is_scalar( $d['value'] ) ) continue;
        $parts[] = $d['key'] . ': ' . $d['value'];
    }
    return trim( implode( "\n", $parts ) );
}

/** Scan the open orders. Returns jobs (counted), needs (custom lines without a plan), parked. */
function pps_po_build( $orders = null ) {
    $out = array( 'jobs' => array(), 'needs' => array(), 'parked' => array(), 'scanned' => 0 );
    if ( $orders === null ) {
        if ( ! function_exists( 'wc_get_orders' ) ) return $out;
        $orders = wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC', 'status' => pps_po_statuses() ) );
    }
    foreach ( (array) $orders as $order ) {
        $out['scanned']++;
        $date = $order->get_date_created();
        $base = array(
            'order' => $order->get_id(), 'date' => $date ? $date->date( 'Y-m-d' ) : '', 'status' => $order->get_status(),
            'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_company(),
        );
        foreach ( $order->get_items() as $item_id => $item ) {
            $row = $base + array( 'item_id' => (int) $item_id, 'product' => $item->get_name(), 'job' => '', 'qty' => '', 'size' => '' );

            $done = (string) $item->get_meta( '_pps_paper_done' );
            $plan = json_decode( (string) $item->get_meta( '_pps_paper_plan' ), true );
            if ( $done !== '' ) { $out['parked'][] = $row + array( 'why' => 'printed / not needed' ); continue; }

            $raw  = $item->get_meta( '_pps_metadata' );
            $meta = $raw ? json_decode( (string) $raw, true ) : null;
            if ( is_array( $meta ) && ! is_array( $plan ) ) {
                $row['job']  = (string) ( $meta['jobName'] ?? '' );
                $row['qty']  = (string) ( $meta['totalQty'] ?? $meta['qty'] ?? '' );
                $row['size'] = (string) ( $meta['sizeLabel'] ?? '' );
                $rows = pps_po_calc_rows( $meta );
                if ( $rows !== null ) {
                    $sticker = (string) ( $meta['calcType'] ?? '' ) === 'sticker';
                    foreach ( $rows as &$r ) $r['paper'] = pps_po_classify( $r['paper'], $sticker && $r['role'] === 'stock' );
                    unset( $r );
                    $row['rows'] = array_values( array_filter( $rows, function ( $r ) { return ! empty( $r['paper'] ); } ) );
                    $row['source'] = 'calculator';
                    $out['jobs'][] = $row;
                    continue;
                }
            }
            if ( is_array( $plan ) ) {
                if ( ! empty( $plan['none'] ) ) { $out['parked'][] = $row + array( 'why' => 'no press paper' ); continue; }
                $rows = pps_po_plan_rows( $plan );
                foreach ( $rows as &$r ) $r['paper'] = pps_po_classify( $r['paper'] );
                unset( $r );
                $row['rows'] = array_values( array_filter( $rows, function ( $r ) { return ! empty( $r['paper'] ); } ) );
                $row['source'] = 'plan';
                $row['plan'] = $plan;
                if ( $row['rows'] ) { $out['jobs'][] = $row; continue; }
            }
            $text = pps_po_item_text( $item );
            $out['needs'][] = $row + array( 'text' => $text, 'suggest' => pps_po_suggest( $text, pps_po_catalog() ) );
        }
    }
    return $out;
}

/* ── Saving a plan, parking a line ───────────────────────────── */

add_action( 'admin_post_pps_po_item', function () {
    $item_id = (int) ( $_POST['item_id'] ?? 0 );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! $item_id ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_po_item_' . $item_id );
    $do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );

    if ( $do === 'done' ) {
        wc_update_order_item_meta( $item_id, '_pps_paper_done', current_time( 'Y-m-d' ) );
    } elseif ( $do === 'none' ) {
        wc_update_order_item_meta( $item_id, '_pps_paper_plan', wp_json_encode( array( 'none' => 1 ) ) );
    } elseif ( $do === 'restore' ) {
        wc_delete_order_item_meta( $item_id, '_pps_paper_done' );
        $p = json_decode( (string) wc_get_order_item_meta( $item_id, '_pps_paper_plan', true ), true );
        if ( is_array( $p ) && ! empty( $p['none'] ) ) wc_delete_order_item_meta( $item_id, '_pps_paper_plan' );
    } elseif ( $do === 'clear' ) {
        wc_delete_order_item_meta( $item_id, '_pps_paper_plan' );
    } elseif ( $do === 'plan' ) {
        $cat  = pps_po_catalog();
        $rows = array();
        foreach ( (array) ( $_POST['r'] ?? array() ) as $r ) {
            $r = is_array( $r ) ? wp_unslash( $r ) : array();
            $key   = sanitize_text_field( $r['paper'] ?? '' );
            $typed = sanitize_text_field( $r['other'] ?? '' );
            if ( $key !== '' && $key !== 'other' && isset( $cat[ $key ] ) ) {
                $paper = array( 'val' => $key, 'label' => (string) $cat[ $key ]['label'] );
            } elseif ( $typed !== '' ) {
                $paper = array( 'label' => mb_substr( $typed, 0, 120 ), 'typed' => 1 );
            } else {
                continue;
            }
            $rows[] = array(
                'paper' => $paper,
                'w' => (float) ( $r['w'] ?? 0 ), 'h' => (float) ( $r['h'] ?? 0 ), 'qty' => (int) str_replace( ',', '', (string) ( $r['qty'] ?? 0 ) ),
                'sheets' => (int) str_replace( ',', '', (string) ( $r['sheets'] ?? 0 ) ),
                'sheet' => in_array( $r['sheet'] ?? '', array( '13x19', '13x27.5', '12x18' ), true ) ? $r['sheet'] : '13x19',
            );
        }
        if ( $rows ) wc_update_order_item_meta( $item_id, '_pps_paper_plan', wp_json_encode( array( 'rows' => $rows, 'by' => get_current_user_id(), 'at' => current_time( 'Y-m-d H:i' ) ) ) );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=pps-paper-order' ) . '#pps-po-' . ( $do === 'plan' || $do === 'restore' ? 'top' : 'needs' ) );
    exit;
} );

add_action( 'admin_post_pps_po_settings', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_po_settings' );
    update_option( PPS_PO_SETTINGS, array(
        'overs_pct' => max( 0, min( 50, (float) ( $_POST['overs_pct'] ?? 5 ) ) ),
        'parent_w'  => max( 1, (float) ( $_POST['parent_w'] ?? 28 ) ),
        'parent_h'  => max( 1, (float) ( $_POST['parent_h'] ?? 40 ) ),
    ), false );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-paper-order' ) );
    exit;
} );

/* ── The Wednesday email ─────────────────────────────────────────
 * Owner, 2026-10-17: "email it weekly on wednesdays". The same copy-paste order the screen
 * shows, mailed to the office (pps_reorder_contact_recipient(), never orders@) every
 * Wednesday at 7:00 shop time, only when there is an open job. Custom lines still waiting for
 * a paper plan are listed by name, because their paper is not in the totals until someone
 * confirms it on the screen. The last send is kept in `pps_paper_order_last_email`; a send
 * inside six days of the last one is skipped, so a doubled cron event cannot mail twice. */

define( 'PPS_PO_EMAIL_HOOK', 'pps_paper_order_weekly' );
define( 'PPS_PO_EMAIL_LAST', 'pps_paper_order_last_email' );

function pps_po_email_tz() {
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return new DateTimeZone( $tz ); } catch ( \Throwable $e ) { return new DateTimeZone( 'America/Phoenix' ); }
}

/** The next Wednesday 07:00 in the shop's timezone, as a timestamp (today counts if before 7). */
function pps_po_next_wednesday( $now = null ) {
    $tz = pps_po_email_tz();
    $d  = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz );
    $t  = $d->setTime( 7, 0 );
    if ( (int) $d->format( 'N' ) !== 3 || $t->getTimestamp() <= $d->getTimestamp() ) $t = $d->modify( 'next wednesday' )->setTime( 7, 0 );
    return $t->getTimestamp();
}

/** Subject and body, or null when there is no open job. Pure apart from what it is given. */
function pps_po_email( array $built, array $settings, $when, $screen_url ) {
    if ( ! $built['jobs'] && ! $built['needs'] ) return null;
    $groups = pps_po_aggregate( $built['jobs'], $settings );
    $body   = pps_po_text( $groups, $settings, $when, false );
    $stocked = array();
    foreach ( $groups as $g ) {
        if ( ! $g['in_stock'] ) continue;
        $stocked[] = '- ' . $g['label'] . ': ' . pps_po_n( $g['total'] ) . ' sheets ' . str_replace( 'x', '×', $g['sheet'] );
    }
    if ( $stocked ) $body .= "\nStocked papers these jobs also use (check the shelf):\n" . implode( "\n", $stocked ) . "\n";
    if ( $built['needs'] ) {
        $body .= "\nNOT COUNTED YET: custom orders with no paper confirmed. Set their paper on the screen:\n";
        foreach ( $built['needs'] as $n ) $body .= '- #' . $n['order'] . ' ' . $n['customer'] . ' — ' . $n['product'] . "\n";
    }
    $body .= "\nOpen the Paper Order screen: " . $screen_url . "\n";
    $to_order = 0;
    foreach ( $groups as $g ) if ( ! $g['in_stock'] ) $to_order++;
    $subject = 'Paper order for ' . $when . ': ' . ( $to_order ? $to_order . ' paper' . ( $to_order === 1 ? '' : 's' ) . ' to order' : 'nothing to order' )
             . ( $built['needs'] ? ', ' . count( $built['needs'] ) . ' custom order' . ( count( $built['needs'] ) === 1 ? '' : 's' ) . ' to confirm' : '' );
    return array( 'subject' => $subject, 'body' => $body );
}

/**
 * The weekly send. A TEST send ($test) goes out even with no open job, says TEST in the
 * subject, and is not recorded — so it never uses up that week's Wednesday email.
 */
function pps_po_send_weekly( $test = false ) {
    $test = $test === true;   // the cron hook passes no argument; only an explicit true is a test
    $last = (int) get_option( PPS_PO_EMAIL_LAST, 0 );
    if ( ! $test && $last && time() - $last < 6 * DAY_IN_SECONDS ) return false;
    $when  = ( new DateTimeImmutable( 'now', pps_po_email_tz() ) )->format( 'D M j, Y' );
    $built = pps_po_build();
    $mail  = pps_po_email( $built, pps_po_settings(), $when, admin_url( 'admin.php?page=pps-paper-order' ) );
    if ( ! $test ) update_option( PPS_PO_EMAIL_LAST, time(), false );
    if ( ! $mail && $test ) $mail = array( 'subject' => 'Paper order for ' . $when . ': no open jobs', 'body' => "No open jobs, so the Wednesday email would not be sent this week.\n" );
    if ( ! $mail ) return false;
    if ( $test ) {
        $mail['subject'] = 'TEST — ' . $mail['subject'];
        $mail['body']    = "This is a test of the Wednesday paper order email. The real one goes out every Wednesday at 7:00.\n\n" . $mail['body'];
    }
    $to = function_exists( 'pps_reorder_contact_recipient' ) ? pps_reorder_contact_recipient() : 'Office@priorityprintservice.com';
    return wp_mail( $to, $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
}
add_action( PPS_PO_EMAIL_HOOK, 'pps_po_send_weekly' );

/** "Send test email" on the screen. */
add_action( 'admin_post_pps_po_test', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_po_test' );
    $ok = pps_po_send_weekly( true );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-paper-order&pps_po_test=' . ( $ok ? 'sent' : 'failed' ) ) );
    exit;
} );

/**
 * A test requested without a browser: set option `pps_paper_order_send_test` to 1 and the
 * next request sends one test and deletes the option. Claimed by deleting it first, so two
 * requests at once cannot both send.
 */
add_action( 'init', function () {
    if ( ! get_option( 'pps_paper_order_send_test' ) ) return;
    if ( ! delete_option( 'pps_paper_order_send_test' ) ) return;
    $ok = pps_po_send_weekly( true );
    update_option( 'pps_paper_order_test_result', array( 'at' => time(), 'sent' => (bool) $ok ), false );
}, 30 );

/** Keep exactly one weekly event, landing on a Wednesday at 7:00 shop time. */
add_action( 'init', function () {
    $next = wp_next_scheduled( PPS_PO_EMAIL_HOOK );
    if ( $next ) {
        $d = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( pps_po_email_tz() );
        if ( (int) $d->format( 'N' ) === 3 && $d->format( 'H:i' ) === '07:00' ) return;
        wp_clear_scheduled_hook( PPS_PO_EMAIL_HOOK );
    }
    wp_schedule_event( pps_po_next_wednesday(), 'weekly', PPS_PO_EMAIL_HOOK );
} );

/* ── The screen ──────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Paper Order', '🧻 Paper Order', 'manage_woocommerce', 'pps-paper-order', 'pps_po_render_admin' );
}, 59 );

/** A small form posting one action for one line. */
function pps_po_button( $item_id, $do, $label, $class = 'button' ) {
    return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
         . wp_nonce_field( 'pps_po_item_' . (int) $item_id, '_wpnonce', true, false )
         . '<input type="hidden" name="action" value="pps_po_item"><input type="hidden" name="item_id" value="' . (int) $item_id . '">'
         . '<input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

function pps_po_order_link( $id ) {
    return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $id ) ) . '">#' . (int) $id . '</a>';
}

function pps_po_render_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    $s      = pps_po_settings();
    $built  = pps_po_build();
    $groups = pps_po_aggregate( $built['jobs'], $s );
    $when   = function_exists( 'wp_date' ) ? wp_date( 'M j, Y' ) : date( 'M j, Y' );
    $text   = pps_po_text( $groups, $s, $when, false );
    $text_all = pps_po_text( $groups, $s, $when, true );
    $cat    = pps_po_catalog();
    $parent = pps_po_dec( $s['parent_w'] ) . '×' . pps_po_dec( $s['parent_h'] );

    echo '<div class="wrap" id="pps-po-top"><h1>Paper Order</h1>';
    echo '<p>Paper for every open job (' . esc_html( implode( ', ', pps_po_statuses() ) ) . '), from the yield each job was priced on, '
       . 'grouped by paper and press sheet, with overs, and converted to ' . esc_html( $parent ) . ' parent sheets. '
       . esc_html( sprintf( '%d open orders scanned.', (int) $built['scanned'] ) ) . '</p>';
    $last = (int) get_option( PPS_PO_EMAIL_LAST, 0 );
    echo '<p style="color:#555">Emailed to ' . esc_html( function_exists( 'pps_reorder_contact_recipient' ) ? pps_reorder_contact_recipient() : 'Office@priorityprintservice.com' )
       . ' every Wednesday at 7:00 when there is an open job. '
       . esc_html( $last ? 'Last checked ' . ( new DateTimeImmutable( '@' . $last ) )->setTimezone( pps_po_email_tz() )->format( 'D M j, g:i a' ) . '.' : 'Not sent yet.' ) . ' '
       . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
       . wp_nonce_field( 'pps_po_test', '_wpnonce', true, false )
       . '<input type="hidden" name="action" value="pps_po_test"><button class="button">Send test email</button></form>'
       . ( isset( $_GET['pps_po_test'] ) ? ' <strong>' . ( $_GET['pps_po_test'] === 'sent' ? 'Test sent.' : 'Test could not be sent.' ) . '</strong>' : '' ) . '</p>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0 16px">'
       . wp_nonce_field( 'pps_po_settings', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="pps_po_settings">'
       . 'Overs <input name="overs_pct" type="number" step="0.5" min="0" max="50" value="' . esc_attr( $s['overs_pct'] ) . '" style="width:70px">% '
       . '&nbsp; Parent sheet <input name="parent_w" type="number" step="0.5" value="' . esc_attr( $s['parent_w'] ) . '" style="width:70px"> × '
       . '<input name="parent_h" type="number" step="0.5" value="' . esc_attr( $s['parent_h'] ) . '" style="width:70px"> '
       . '<button class="button">Save</button></form>';

    if ( $built['needs'] ) {
        echo '<div class="notice notice-warning inline"><p><strong>' . count( $built['needs'] ) . ' custom line'
           . ( count( $built['needs'] ) === 1 ? '' : 's' ) . ' not counted yet.</strong> '
           . '<a href="#pps-po-needs">Confirm their paper below</a> and they join the order.</p></div>';
    }

    // ── 1. copy-paste ──
    echo '<h2>To order — copy and paste</h2>';
    echo '<textarea id="pps-po-text" readonly rows="' . min( 30, 4 + substr_count( $text, "\n" ) ) . '" style="width:100%;max-width:820px;font-family:monospace">' . esc_textarea( $text ) . '</textarea>';
    echo '<textarea id="pps-po-text-all" hidden>' . esc_textarea( $text_all ) . '</textarea>';
    echo '<p><button type="button" class="button button-primary" onclick="(function(){var t=document.getElementById(\'pps-po-text\');t.select();(navigator.clipboard?navigator.clipboard.writeText(t.value):Promise.resolve(document.execCommand(\'copy\'))).then(function(){},function(){});})()">Copy</button> '
       . '<label><input type="checkbox" onchange="(function(c){var t=document.getElementById(\'pps-po-text\'),a=document.getElementById(\'pps-po-text-all\');if(!t.dataset.only)t.dataset.only=t.value;t.value=c.checked?a.value:t.dataset.only;})(this)"> Include stocked papers</label></p>';

    // ── 2. by paper ──
    echo '<h2>By paper</h2>';
    if ( ! $groups ) {
        echo '<p>No open job is counted yet.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Paper</th><th>Press sheet</th><th style="text-align:right">Sheets</th>'
           . '<th style="text-align:right">+ ' . esc_html( pps_po_dec( $s['overs_pct'] ) ) . '% overs</th>'
           . '<th style="text-align:right">' . esc_html( $parent ) . ' parents</th><th>Jobs</th></tr></thead><tbody>';
        foreach ( $groups as $g ) {
            $jobs = array();
            foreach ( $g['jobs'] as $j ) {
                $jobs[] = pps_po_order_link( $j['order'] ) . ' ' . esc_html( $j['customer'] ) . ' — ' . esc_html( $j['what'] )
                        . ' <span style="color:#777">(' . esc_html( $j['role'] ) . ': ' . esc_html( pps_po_n( $j['sheets'] ) ) . ' = ' . esc_html( $j['basis'] ) . ')</span>';
            }
            $tag = $g['in_stock'] ? '<span style="color:#2271b1">stocked</span>'
                 : '<span style="color:#b32d2e">order' . ( $g['days'] ? ' · +' . (int) $g['days'] . ' day' . ( (int) $g['days'] === 1 ? '' : 's' ) : '' ) . '</span>';
            echo '<tr><td><strong>' . esc_html( $g['label'] ) . '</strong><br>' . $tag . '</td>'
               . '<td>' . esc_html( str_replace( 'x', '×', $g['sheet'] ) ) . '</td>'
               . '<td style="text-align:right">' . esc_html( pps_po_n( $g['net'] ) ) . '</td>'
               . '<td style="text-align:right"><strong>' . esc_html( pps_po_n( $g['total'] ) ) . '</strong></td>'
               . '<td style="text-align:right">' . ( $g['parents'] !== null ? '<strong>' . esc_html( pps_po_n( $g['parents'] ) ) . '</strong><br><span style="color:#777">' . (int) $g['per_parent'] . ' out</span>' : '—' ) . '</td>'
               . '<td>' . implode( '<br>', $jobs ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ── 3. custom lines needing a paper ──
    echo '<h2 id="pps-po-needs">Custom orders — confirm the paper</h2>';
    if ( ! $built['needs'] ) {
        echo '<p>Every open line is counted.</p>';
    } else {
        echo '<p>These lines came in without a calculator, so the screen has guessed from their text. Check each row, '
           . 'correct it, and press <strong>Count it</strong>; the answer is saved on the order. A flat job needs paper, finished size '
           . 'and quantity. For a booklet or anything else, enter the press sheets instead. Use <em>No press paper</em> for banners '
           . 'and outsourced work, and <em>Already printed</em> for a job that is done but still open.</p>';
        $opts = '<option value="">— paper —</option>';
        $labels = array(); foreach ( $cat as $k => $row ) $labels[ $k ] = (string) ( $row['label'] ?? $k );
        asort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
        foreach ( $built['needs'] as $n ) {
            $sg = $n['suggest'];
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:10px 12px;margin:10px 0;max-width:1100px">';
            echo '<p style="margin:0 0 6px"><strong>' . pps_po_order_link( $n['order'] ) . ' ' . esc_html( $n['customer'] ) . '</strong> — '
               . esc_html( $n['product'] ) . ' <span style="color:#777">(' . esc_html( $n['status'] ) . ', ' . esc_html( $n['date'] ) . ')</span></p>';
            echo '<pre style="white-space:pre-wrap;margin:0 0 8px;background:#f6f7f7;padding:6px">' . esc_html( mb_substr( $n['text'], 0, 1200 ) ) . '</pre>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
               . wp_nonce_field( 'pps_po_item_' . (int) $n['item_id'], '_wpnonce', true, false )
               . '<input type="hidden" name="action" value="pps_po_item"><input type="hidden" name="item_id" value="' . (int) $n['item_id'] . '"><input type="hidden" name="do" value="plan">';
            for ( $i = 0; $i < 3; $i++ ) {
                $first = $i === 0;
                $sel = '';
                foreach ( $labels as $k => $l ) $sel .= '<option value="' . esc_attr( $k ) . '"' . ( $first && (string) $sg['paper'] === (string) $k ? ' selected' : '' ) . '>' . esc_html( $l ) . '</option>';
                echo '<div style="margin:3px 0' . ( $first ? '' : ';opacity:.85' ) . '">'
                   . '<select name="r[' . $i . '][paper]">' . $opts . $sel . '<option value="other">Other (type it) →</option></select> '
                   . '<input name="r[' . $i . '][other]" placeholder="other paper" style="width:150px"> '
                   . 'size <input name="r[' . $i . '][w]" value="' . esc_attr( $first ? $sg['w'] : '' ) . '" style="width:55px">×'
                   . '<input name="r[' . $i . '][h]" value="' . esc_attr( $first ? $sg['h'] : '' ) . '" style="width:55px">″ flat '
                   . 'qty <input name="r[' . $i . '][qty]" value="' . esc_attr( $first ? $sg['qty'] : '' ) . '" style="width:70px"> '
                   . '<em>or</em> <input name="r[' . $i . '][sheets]" placeholder="press sheets" style="width:95px"> '
                   . '<select name="r[' . $i . '][sheet]"><option>13x19</option><option>13x27.5</option><option>12x18</option></select>'
                   . ( $first ? '' : ' <span style="color:#777">(second job in the same line)</span>' ) . '</div>';
            }
            echo '<p style="margin:6px 0 0"><button class="button button-primary">Count it</button></p></form>';
            echo '<p style="margin:6px 0 0">' . pps_po_button( $n['item_id'], 'none', 'No press paper' ) . ' '
               . pps_po_button( $n['item_id'], 'done', 'Already printed' ) . '</p></div>';
        }
    }

    // ── 4. every counted job, with a way to leave a printed one off ──
    echo '<h2>Jobs counted</h2>';
    if ( $built['jobs'] ) {
        echo '<p>A job that is printed but still open (shipped in a combined box, picked up) can be left off here without changing the order.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Order</th><th>Job</th><th>Paper</th><th></th></tr></thead><tbody>';
        foreach ( $built['jobs'] as $j ) {
            $ps = array();
            foreach ( $j['rows'] as $r ) $ps[] = esc_html( $r['paper']['label'] ) . ' — ' . esc_html( pps_po_n( $r['sheets'] ) ) . ' × ' . esc_html( str_replace( 'x', '×', $r['sheet'] ) ) . ' <span style="color:#777">(' . esc_html( $r['basis'] ) . ')</span>';
            echo '<tr><td>' . pps_po_order_link( $j['order'] ) . '<br><span style="color:#777">' . esc_html( $j['date'] ) . '</span></td>'
               . '<td>' . esc_html( $j['customer'] ) . '<br>' . esc_html( $j['job'] !== '' ? $j['job'] : $j['product'] )
               . ( $j['size'] !== '' || $j['qty'] !== '' ? '<br><span style="color:#777">' . esc_html( trim( $j['size'] . ' · ' . $j['qty'] . ' qty', ' ·' ) ) . '</span>' : '' ) . '</td>'
               . '<td>' . implode( '<br>', $ps ) . '</td>'
               . '<td>' . pps_po_button( $j['item_id'], 'done', 'Already printed' ) . ( $j['source'] === 'plan' ? ' ' . pps_po_button( $j['item_id'], 'clear', 'Edit paper' ) : '' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if ( $built['parked'] ) {
        echo '<h2>Left off</h2><table class="widefat striped" style="max-width:1100px"><tbody>';
        foreach ( $built['parked'] as $p ) {
            echo '<tr><td>' . pps_po_order_link( $p['order'] ) . '</td><td>' . esc_html( $p['customer'] ) . ' — ' . esc_html( $p['product'] ) . '</td>'
               . '<td>' . esc_html( $p['why'] ) . '</td><td>' . pps_po_button( $p['item_id'], 'restore', 'Put back' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
