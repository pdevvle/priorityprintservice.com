<?php
/**
 * PPS Finishing Report — which OPEN jobs need a finishing step, grouped by the step.
 *
 * The question this answers (owner, 2026-09-29): "which orders require UV coating right
 * now?" — and the same for every other step that is not printing: coating, folding,
 * perforation, round corners, bundling, outfold, magnetic backer, envelopes. Finishing
 * lives inside each line item (the calculators' `addons`, the "Add-ons" meta, the summary
 * text of older builds, a quote's free-text "Specs"), which is on no list screen and in no
 * report — so the only way to answer was to open every order.
 *
 * Read-only and cached, the same shape as pps-paper-report.php: the built report is kept
 * in an option, rebuilt at most hourly by cron and on staff traffic, and on demand from the
 * screen. Nothing here writes to an order.
 *
 * Sources, most reliable first:
 *   1. `addons` in `_pps_metadata` (every calculator since 2026-09-21), via
 *      pps_order_addons(), which also reads the finishing lines of an older summary.
 *   2. The flats' fold (`foldType` / `foldLabel`), which is a finishing step but not an add-on.
 *   3. Anything else — a quote's "Specs", a WCPA-era option — is free text, scanned for
 *      finishing words and shown as "from the order text" so staff read it before trusting
 *      it. "Coated" alone is a paper ("100lb Gloss Coated"), not a coating, and is ignored.
 *
 * `tools-finishing-report-test.php` is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'PPS_FINISHING_REPORT_OPTION' ) ) define( 'PPS_FINISHING_REPORT_OPTION', 'pps_finishing_report' );
if ( ! defined( 'PPS_FINISHING_REPORT_TTL' ) ) define( 'PPS_FINISHING_REPORT_TTL', HOUR_IN_SECONDS );

/** Open = paid work that is or will be on the floor. Unpaid (pending) carts are not jobs yet. */
function pps_finishing_report_open_statuses() {
    return apply_filters( 'pps_finishing_report_statuses', array( 'processing', 'on-hold' ) );
}

/**
 * The finishing steps free text is scanned for, as [category, pattern]. Order matters: the
 * first match names the category. Coating words are the specific ones — "UV", "aqueous",
 * "laminat…", "soft touch", "coating" — never "coated", which names a paper.
 */
function pps_finishing_text_patterns() {
    return array(
        array( 'Coating',        '/\b(spot\s*uv|uv\b|u\.v\.|aqueous|laminat\w*|soft[\s-]?touch|coating)/i' ),
        array( 'Fold',           '/\b(tri[\s-]?fold|bi[\s-]?fold|half[\s-]?fold|z[\s-]?fold|gate[\s-]?fold|accordion|roll[\s-]?fold|folded|folding|fold)\b/i' ),
        array( 'Score',          '/\bscor(e|ed|ing)\b/i' ),
        array( 'Perforation',    '/\bperf(orat\w*|s)?\b/i' ),
        array( 'Round Corner',   '/\bround(ed)?[\s-]*corners?\b/i' ),
        array( 'Drilling',       '/\b(drill\w*|hole[\s-]?punch\w*|3[\s-]?hole)\b/i' ),
        array( 'Foil',           '/\bfoil\w*\b/i' ),
        array( 'Embossing',      '/\b(de)?emboss\w*\b/i' ),
        array( 'Die Cut',        '/\bdie[\s-]?cut\w*\b/i' ),
        array( 'Numbering',      '/\bnumber(ed|ing)\b/i' ),
        array( 'Bundling',       '/\b(bundl\w*|shrink[\s-]?wrap\w*|banded|banding)\b/i' ),
    );
}

/** A calculator add-on label ("Coating: UV Gloss (both sides)") as [category, detail]. */
function pps_finishing_split_label( $label ) {
    $label = trim( (string) $label );
    if ( $label === '' ) return null;
    $parts = explode( ':', $label, 2 );
    if ( count( $parts ) === 2 && trim( $parts[0] ) !== '' ) {
        return array( trim( $parts[0] ), trim( $parts[1] ) );
    }
    return array( $label, '' );
}

/**
 * Every finishing step on one line item, as a list of
 * array( 'cat' => 'Coating', 'detail' => 'UV Gloss (both sides)', 'source' => 'calculator'|'text' ).
 *
 * @param array  $meta    decoded `_pps_metadata`, or array() for a line that has none
 * @param string $summary `_pps_summary`
 * @param array  $texts   other visible meta of the line, key => value (quote "Specs", WCPA options)
 */
function pps_finishing_item_steps( array $meta, $summary, array $texts ) {
    $out  = array();
    $seen = array();
    $add  = static function( $cat, $detail, $source ) use ( &$out, &$seen ) {
        $k = strtolower( $cat . '|' . $detail );
        if ( isset( $seen[ $k ] ) ) return;
        $seen[ $k ] = true;
        $out[] = array( 'cat' => $cat, 'detail' => $detail, 'source' => $source );
    };

    if ( $meta || trim( (string) $summary ) !== '' ) {
        $labels = function_exists( 'pps_order_addons' ) ? pps_order_addons( $meta, (string) $summary ) : array();
        // Builds before `addons` (2026-09-21) are read from the summary, where the artwork
        // option and the binding sit beside the finishing lines; keep only finishing there.
        if ( ! is_array( $meta['addons'] ?? null ) ) {
            $labels = array_values( array_filter( $labels, static function( $l ) {
                if ( preg_match( '/^(Coating|Bundling|Perforation|Round Corner|Blank Envelopes|Envelopes|Outfold|Magnetic Backer)\b/i', $l ) ) return true;
                foreach ( pps_finishing_text_patterns() as $p ) if ( preg_match( $p[1], $l ) ) return true;
                return false;
            } ) );
        }
        foreach ( $labels as $l ) {
            $s = pps_finishing_split_label( $l );
            if ( $s ) $add( $s[0], $s[1], 'calculator' );
        }
        $fold = (string) ( $meta['foldType'] ?? '' );
        if ( $fold !== '' && strtolower( $fold ) !== 'flat' && strtolower( $fold ) !== 'none' ) {
            $add( 'Fold', trim( (string) ( $meta['foldLabel'] ?? $fold ) ), 'calculator' );
        }
        if ( $meta ) return $out;          // a calculator line: its own words are the whole story
    }

    // Free text: a quote's Specs, a WCPA option. Scanned, and labelled as such.
    foreach ( $texts as $key => $value ) {
        // An option answered "None" / "No" / "No UV" asks for nothing.
        if ( preg_match( '/^\s*(none|no|n\/?a|-+|0|false|without)\b/i', (string) $value ) ) continue;
        $text = trim( wp_strip_all_tags( (string) $key . ': ' . (string) $value ) );
        if ( $text === '' ) continue;
        foreach ( pps_finishing_text_patterns() as $p ) {
            if ( preg_match( $p[1], $text, $m, PREG_OFFSET_CAPTURE ) ) {
                // A few words either side of the match, so the row reads without opening the order.
                $at    = max( 0, $m[0][1] - 40 );
                $snip  = trim( mb_substr( $text, $at, 110 ) );
                $add( $p[0], ( $at > 0 ? '…' : '' ) . $snip . ( mb_strlen( $text ) > $at + 110 ? '…' : '' ), 'text' );
            }
        }
    }
    return $out;
}

/** Visible, non-PPS meta of an order line, key => value — where quotes and WCPA keep the job. */
function pps_finishing_item_texts( $item ) {
    $skip  = array( 'Job Ticket', 'Order Summary', 'PPS-Spec', 'PPS-Production-Start', 'Estimated Delivery', 'Add-ons',
                    'Print File Check', 'Quote updated', 'PPS-Prepress-Review', 'PPS-Data-Error', 'Preset', 'Ship to', 'Requested delivery' );
    $texts = array();
    foreach ( $item->get_meta_data() as $md ) {
        $d = $md->get_data();
        $k = (string) ( $d['key'] ?? '' );
        if ( $k === '' || $k[0] === '_' || in_array( $k, $skip, true ) ) continue;
        if ( ! is_scalar( $d['value'] ?? null ) ) continue;
        $texts[ $k ] = (string) $d['value'];
    }
    return $texts;
}

function pps_finishing_report_build() {
    $report = array( 'built' => time(), 'rows' => array(), 'scanned' => 0, 'items' => 0, 'error' => '' );
    if ( ! function_exists( 'wc_get_orders' ) ) { $report['error'] = 'WooCommerce not active'; return $report; }

    $orders = wc_get_orders( array(
        'limit'   => 300,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => pps_finishing_report_open_statuses(),
    ) );
    if ( ! is_array( $orders ) ) return $report;

    foreach ( $orders as $order ) {
        $report['scanned']++;
        foreach ( $order->get_items() as $item ) {
            $report['items']++;
            try {
                $raw   = $item->get_meta( '_pps_metadata' );
                $meta  = $raw ? json_decode( (string) $raw, true ) : array();
                if ( ! is_array( $meta ) ) $meta = array();
                $steps = pps_finishing_item_steps( $meta, (string) $item->get_meta( '_pps_summary' ), $meta ? array() : pps_finishing_item_texts( $item ) );
            } catch ( \Throwable $e ) {
                continue;
            }
            if ( ! $steps ) continue;
            $date = $order->get_date_created();
            $sets = is_array( $meta['sets'] ?? null ) ? $meta['sets'] : array();
            $qty  = $sets ? array_sum( array_map( 'intval', array_column( $sets, 'qty' ) ) ) : ( $meta['qty'] ?? $item->get_quantity() );
            $job  = isset( $sets[0]['name'] ) ? trim( (string) $sets[0]['name'] ) : trim( (string) ( $meta['jobName'] ?? '' ) );
            $report['rows'][] = array(
                'order'    => $order->get_id(),
                'date'     => $date ? $date->date( 'Y-m-d' ) : '',
                'status'   => $order->get_status(),
                'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'product'  => $item->get_name(),
                'job'      => $job,
                'qty'      => (string) $qty,
                'size'     => (string) ( $meta['sizeLabel'] ?? '' ),
                'deliver'  => (string) $item->get_meta( '_pps_delivery_date' ),
                'rush'     => ( (float) ( $meta['rushCost'] ?? 0 ) ) > 0,
                'steps'    => $steps,
            );
        }
    }

    // Soonest delivery first; lines with no date (quotes, old on-hold) after.
    usort( $report['rows'], static function( $a, $b ) {
        $x = $a['deliver'] !== '' ? $a['deliver'] : '9999';
        $y = $b['deliver'] !== '' ? $b['deliver'] : '9999';
        return $x === $y ? $b['order'] <=> $a['order'] : strcmp( $x, $y );
    } );
    return $report;
}

/** Counts per finishing category, most common first. */
function pps_finishing_report_counts( array $rows ) {
    $c = array();
    foreach ( $rows as $r ) {
        $cats = array();
        foreach ( $r['steps'] as $s ) $cats[ $s['cat'] ] = true;
        foreach ( array_keys( $cats ) as $cat ) $c[ $cat ] = ( $c[ $cat ] ?? 0 ) + 1;
    }
    arsort( $c );
    return $c;
}

/**
 * The rows a filter keeps: `$cat` a category name (exact, case-insensitive), `$q` words
 * that must all appear in a row's finishing text ("uv" finds "UV Gloss" and "Spot UV").
 */
function pps_finishing_report_filter( array $rows, $cat = '', $q = '' ) {
    $cat   = strtolower( trim( (string) $cat ) );
    $words = array_filter( preg_split( '/\s+/', strtolower( trim( (string) $q ) ) ) );
    return array_values( array_filter( $rows, static function( $r ) use ( $cat, $words ) {
        $hit = false;
        foreach ( $r['steps'] as $s ) {
            if ( $cat !== '' && strtolower( $s['cat'] ) !== $cat ) continue;
            $text = strtolower( $s['cat'] . ' ' . $s['detail'] );
            $all  = true;
            foreach ( $words as $w ) {
                if ( ! preg_match( '/(^|[^a-z0-9])' . preg_quote( $w, '/' ) . '/', $text ) ) { $all = false; break; }
            }
            if ( $all ) { $hit = true; break; }
        }
        return $hit;
    } ) );
}

function pps_finishing_report_get( $force = false ) {
    $cached = get_option( PPS_FINISHING_REPORT_OPTION, null );
    if ( ! $force && is_array( $cached ) && isset( $cached['built'] ) && ( time() - (int) $cached['built'] ) < PPS_FINISHING_REPORT_TTL ) {
        return $cached;
    }
    $report = pps_finishing_report_build();
    update_option( PPS_FINISHING_REPORT_OPTION, $report, false );
    return $report;
}

add_action( 'init', function () {
    if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'pps_finishing_report_refresh' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'pps_finishing_report_refresh' );
    }
} );
add_action( 'pps_finishing_report_refresh', function () {
    try { pps_finishing_report_get( true ); } catch ( \Throwable $e ) {}
} );

/* ─────────────────────────────────────────────────────────────
 * Admin screen — PPS Calculators → Finishing Report
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Finishing Report', '✂️ Finishing Report', 'manage_woocommerce', 'pps-finishing-report', 'pps_finishing_report_render_admin' );
}, 61 );

function pps_finishing_report_render_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );

    $force  = isset( $_GET['refresh'] ) && check_admin_referer( 'pps_finishing_report_refresh' );
    $report = pps_finishing_report_get( $force );
    $cat    = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : '';
    $q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $base   = admin_url( 'admin.php?page=pps-finishing-report' );

    echo '<div class="wrap"><h1>Finishing Report</h1>';
    echo '<p>Open jobs (' . esc_html( implode( ', ', pps_finishing_report_open_statuses() ) )
       . ') that need a step besides printing, soonest delivery first.</p>';

    if ( ! empty( $report['error'] ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html( $report['error'] ) . '</p></div></div>';
        return;
    }

    $refresh_url = wp_nonce_url( add_query_arg( array( 'refresh' => 1, 'step' => $cat, 'q' => $q ), $base ), 'pps_finishing_report_refresh' );
    echo '<p><em>' . esc_html( sprintf( '%d open orders, %d line items scanned. Built %s.',
        (int) $report['scanned'], (int) $report['items'],
        $report['built'] ? human_time_diff( (int) $report['built'] ) . ' ago' : 'never' ) )
       . ' <a href="' . esc_url( $refresh_url ) . '">Refresh now</a></em></p>';

    // One link per step, with how many jobs need it.
    $counts = pps_finishing_report_counts( $report['rows'] );
    $links  = array( '<a href="' . esc_url( $base ) . '"' . ( $cat === '' ? ' style="font-weight:700"' : '' ) . '>All (' . count( $report['rows'] ) . ')</a>' );
    foreach ( $counts as $c => $n ) {
        $links[] = '<a href="' . esc_url( add_query_arg( 'step', $c, $base ) ) . '"' . ( strcasecmp( $c, $cat ) === 0 ? ' style="font-weight:700"' : '' ) . '>'
                 . esc_html( $c ) . ' (' . (int) $n . ')</a>';
    }
    echo '<p class="subsubsub" style="float:none">' . implode( ' | ', $links ) . '</p>';

    echo '<form method="get" style="margin:8px 0 14px"><input type="hidden" name="page" value="pps-finishing-report">'
       . ( $cat !== '' ? '<input type="hidden" name="step" value="' . esc_attr( $cat ) . '">' : '' )
       . '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="e.g. UV, gloss, trifold"> '
       . '<button class="button">Filter</button></form>';

    $rows = pps_finishing_report_filter( $report['rows'], $cat, $q );
    if ( ! $rows ) {
        echo '<div class="notice notice-success inline"><p><strong>No open jobs match.</strong></p></div></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Deliver by</th><th>Customer</th><th>Job</th><th>Finishing</th></tr></thead><tbody>';
    foreach ( $rows as $r ) {
        $steps = array();
        foreach ( $r['steps'] as $s ) {
            $line = '<strong>' . esc_html( $s['cat'] ) . '</strong>' . ( $s['detail'] !== '' ? ': ' . esc_html( $s['detail'] ) : '' );
            if ( $s['source'] === 'text' ) $line .= ' <span style="color:#a15c00">(from the order text — check it)</span>';
            $steps[] = $line;
        }
        $job  = $r['job'] !== '' ? $r['job'] . ' — ' . $r['product'] : $r['product'];
        $sub  = trim( $r['size'] . ( $r['qty'] !== '' && $r['qty'] !== '0' ? ' · ' . $r['qty'] . ' qty' : '' ), ' ·' );
        $when = $r['deliver'] !== '' ? esc_html( $r['deliver'] ) . ( $r['rush'] ? ' <span style="color:#b32d2e">RUSH</span>' : '' ) : '<span style="color:#777">—</span>';
        echo '<tr>'
           . '<td><a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order'] ) ) . '">#' . (int) $r['order'] . '</a>'
           . '<br><span style="color:#777">' . esc_html( $r['status'] . ' · ' . $r['date'] ) . '</span></td>'
           . '<td>' . $when . '</td>'
           . '<td>' . esc_html( $r['customer'] ) . '</td>'
           . '<td>' . esc_html( $job ) . ( $sub !== '' ? '<br><span style="color:#777">' . esc_html( $sub ) . '</span>' : '' ) . '</td>'
           . '<td>' . implode( '<br>', $steps ) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';
}
