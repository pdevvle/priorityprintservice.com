<?php
/**
 * pps-job-health.php — the print file checked on the order, and the exceptions
 * that announce themselves.
 *
 * Everything the calculators found wrong this month was reported by a customer:
 * a 9×9 booklet that would not check out (2026-09-15), a QR code that would not
 * scan off a printed brochure (87171), another off a booklet (87152). Nothing in
 * the system said anything first. Two things here change that:
 *
 * 1. PRINT FILE CHECK. When an order is written, the print-ready PDF that came
 *    with it (or the raw PDF, when the booklet raw path shipped it) is opened
 *    and measured: pages, sheet size, and — for the raster files the calculators
 *    generate — the pixel size of each page image against the sheet, which is
 *    the effective DPI. The result is one line on the order ("Print File Check")
 *    and the last line of the Job Ticket, and a file below print resolution gets
 *    an order note. It measures the file that will be printed; it does not read
 *    the constant that claims 300 DPI.
 *
 *    What it cannot see, said plainly: a page image that has the right pixel
 *    count but was scaled UP from a smaller render. Order 87171's file measures
 *    6375×2175 px on a 21.25×7.25 in sheet — 300 DPI by count — and was 144 DPI
 *    pixels blown up. Catching that needs the image decoded and its edges
 *    measured, which is what tools-flat-print-dpi-test.mjs and
 *    tools-book-print-dpi-test.mjs do to every build before it ships. This check
 *    catches the other failures: a generator that emits the preview size, a
 *    proofer that renders at 150, a file with the wrong page count or sheet.
 *
 * 2. EXCEPTIONS DIGEST. Once a day, an email to the office listing every open
 *    job that needs a person before it runs: artwork NOT approved (prepress
 *    review), print files below resolution, staff proofs awaiting approval,
 *    hardcopy proofs with no usable address, deliveries promised on a day the
 *    shop is closed, and checkouts that were refused since the last digest. It
 *    sends only when there is something to say. The last digest is kept in
 *    `wp_options['pps_job_health_last']` for reading from here.
 *
 * Loaded by pps-calculators.php. tools-job-health-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// 1. PRINT FILE CHECK
// ═══════════════════════════════════════════════════════════════

/**
 * Measure a PDF the way production will meet it.
 *
 * Returns: kind (raster | vector | mixed | unknown), pages, sheet_in [w, h],
 * images [{w, h, dpi}], min_dpi, summary (one line for the ticket), warn ('' or
 * the reason), ok (true when the file was parsed).
 *
 * "raster" is the calculators' own print-ready shape: one sheet-sized JPEG per
 * page, so pixels ÷ inches is the DPI that will print. "vector" has no images at
 * all (the booklet raw path ships the customer's PDF untouched). "mixed" is a
 * customer PDF with placed images, where DPI cannot be read from the file alone.
 */
function pps_print_file_check( $abs_path ) {
    $out = array( 'ok' => false, 'kind' => 'unknown', 'pages' => 0, 'sheet_in' => null, 'images' => array(), 'min_dpi' => null, 'summary' => '', 'warn' => '' );
    if ( ! is_string( $abs_path ) || ! is_readable( $abs_path ) ) { $out['summary'] = 'print file not readable'; return $out; }
    $size = (int) filesize( $abs_path );
    if ( $size > 96 * 1048576 ) { $out['summary'] = 'print file too large to check (' . round( $size / 1048576 ) . ' MB)'; return $out; }
    $s = file_get_contents( $abs_path );
    if ( $s === false || strncmp( $s, '%PDF', 4 ) !== 0 ) { $out['summary'] = 'not a PDF'; return $out; }
    $out['ok'] = true;

    $out['pages'] = (int) preg_match_all( '/\/Type\s*\/Page(?![s\w])/', $s );

    if ( preg_match( '/\/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]/', $s, $m ) ) {
        $w = abs( (float) $m[3] - (float) $m[1] ) / 72;
        $h = abs( (float) $m[4] - (float) $m[2] ) / 72;
        if ( $w > 0 && $h > 0 ) $out['sheet_in'] = array( round( $w, 3 ), round( $h, 3 ) );
    }

    // Image XObjects: read /Width and /Height out of the dictionary around each one.
    $images = array();
    if ( preg_match_all( '/\/Subtype\s*\/Image\b/', $s, $mm, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $mm[0] as $hit ) {
            $win = substr( $s, max( 0, $hit[1] - 600 ), 1200 );
            if ( preg_match( '/\/Width\s+(\d+)/', $win, $wm ) && preg_match( '/\/Height\s+(\d+)/', $win, $hm ) ) {
                $images[] = array( 'w' => (int) $wm[1], 'h' => (int) $hm[1], 'dpi' => null );
            }
        }
    }

    $sheet = $out['sheet_in'];
    $sheet_imgs = 0; $min = null;
    foreach ( $images as &$im ) {
        if ( ! $sheet || $im['w'] < 1 || $im['h'] < 1 ) continue;
        $ar_img = $im['w'] / $im['h'];
        $ar_sheet = $sheet[0] / $sheet[1];
        // The same aspect as the sheet, either way round: the generator's page image.
        $matches = abs( $ar_img - $ar_sheet ) / $ar_sheet < 0.03 || abs( $ar_img - 1 / $ar_sheet ) / ( 1 / $ar_sheet ) < 0.03;
        if ( ! $matches ) continue;
        $long_px = max( $im['w'], $im['h'] ); $long_in = max( $sheet[0], $sheet[1] );
        $dpi = (int) round( $long_px / $long_in );
        if ( $dpi < 60 ) continue;                        // a thumbnail, not a page
        $im['dpi'] = $dpi; $sheet_imgs++;
        $min = $min === null ? $dpi : min( $min, $dpi );
    }
    unset( $im );
    $out['images'] = $images;

    $fmt_in = static function( $v ) { return rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' ); };
    $sheet_str = $sheet ? $fmt_in( $sheet[0] ) . '×' . $fmt_in( $sheet[1] ) . ' in' : 'sheet size unknown';
    $pg_str = $out['pages'] . ( $out['pages'] === 1 ? ' page' : ' pages' );

    if ( $out['pages'] > 0 && $sheet_imgs >= $out['pages'] && $min !== null ) {
        $out['kind'] = 'raster'; $out['min_dpi'] = $min;
        $first = null; foreach ( $images as $im ) { if ( $im['dpi'] !== null ) { $first = $im; break; } }
        $out['summary'] = 'raster · ' . $pg_str . ' · ' . $sheet_str . ' · ' . $first['w'] . '×' . $first['h'] . ' px · ' . $min . ' DPI';
        if ( $min < 280 ) $out['warn'] = 'BELOW PRINT RESOLUTION: ' . $min . ' DPI effective — re-render from the customer\'s file before plating';
    } elseif ( ! $images ) {
        $out['kind'] = 'vector';
        $out['summary'] = 'vector PDF · ' . $pg_str . ' · ' . $sheet_str;
    } else {
        $out['kind'] = 'mixed';
        $out['summary'] = 'PDF with ' . count( $images ) . ' placed image' . ( count( $images ) === 1 ? '' : 's' ) . ' · ' . $pg_str . ' · ' . $sheet_str . ' · resolution of placed images not measurable here';
    }
    return $out;
}

/**
 * Runs after the main line-item hook (priority 10) has written the Job Ticket.
 * Picks the print-ready PDF from the approval package, or the raw PDF when that is
 * what shipped, measures it while it is still on local disk, and writes the result
 * as its own visible line plus the last line of the ticket.
 */
function pps_job_health_line_item( $item, $cart_item_key, $values, $order ) {
    if ( ! is_array( $values ) || ! isset( $values['pps_metadata'] ) ) return;
    try {
        $files  = is_array( $values['pps_artwork_files'] ?? null ) ? $values['pps_artwork_files'] : array();
        $target = null; $label = '';
        foreach ( $files as $f ) {
            $p = is_array( $f ) ? (string) ( $f['path'] ?? '' ) : '';
            if ( $p !== '' && preg_match( '/_print-ready\.pdf$/i', $p ) ) { $target = $p; $label = 'print-ready'; break; }
        }
        if ( ! $target ) {
            $raw = (string) ( $values['pps_artwork_path'] ?? '' );
            if ( $raw !== '' && preg_match( '/\.pdf$/i', $raw ) ) { $target = $raw; $label = 'raw upload'; }
        }
        if ( ! $target ) return;                                   // images, emailed art, Canva: nothing to measure
        if ( strpos( $target, '..' ) !== false || strpos( $target, 'pps-artwork/' ) !== 0 ) return;

        $upload = wp_upload_dir();
        $abs = trailingslashit( $upload['basedir'] ) . $target;
        $check = null;
        if ( ! file_exists( $abs ) ) {
            $line = 'not checked — file already moved off the server';
        } else {
            $check = pps_print_file_check( $abs );
            $line = ( $label === 'raw upload' ? 'raw upload shipped as the print file · ' : '' ) . $check['summary'] . ( $check['warn'] ? ' — ' . $check['warn'] : '' );
        }

        $item->add_meta_data( 'Print File Check', $line, true );
        if ( $check ) {
            $slim = $check; unset( $slim['images'] ); $slim['file'] = $target;
            $item->add_meta_data( '_pps_print_check', wp_json_encode( $slim ), true );
            if ( $check['warn'] !== '' ) {
                $item->add_meta_data( '_pps_print_check_warn', $check['warn'], true );
                $GLOBALS['pps_job_health_warns'][] = $check['warn'] . ' (' . basename( $target ) . ')';
            }
        }
        $ticket = (string) $item->get_meta( 'Job Ticket' );
        if ( $ticket !== '' ) $item->update_meta_data( 'Job Ticket', $ticket . "\nPrint file: " . $line );
    } catch ( \Throwable $e ) {
        error_log( 'pps-job-health: line item check failed: ' . $e->getMessage() );
    }
}
add_action( 'woocommerce_checkout_create_order_line_item', 'pps_job_health_line_item', 20, 4 );

/**
 * The order note for a file below resolution — from the order-processed hooks,
 * where the order has an ID. (From the line-item hook it has none, and
 * add_order_note() returns 0 without writing; the delivery-date guard learned that.)
 */
function pps_job_health_note( $order ) {
    if ( is_numeric( $order ) ) $order = wc_get_order( $order );
    if ( ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) return;
    $warns = $GLOBALS['pps_job_health_warns'] ?? array();
    if ( ! $warns ) return;
    if ( $order->get_meta( '_pps_job_health_noted' ) ) return;
    $order->add_order_note( 'PRINT FILE CHECK: ' . implode( '; ', array_unique( $warns ) ) . '. The print-ready file on this order is below print resolution. Re-render from the customer\'s file before plating.' );
    $order->update_meta_data( '_pps_job_health_noted', 1 );
    $order->save();
    unset( $GLOBALS['pps_job_health_warns'] );
}
add_action( 'woocommerce_checkout_order_processed', 'pps_job_health_note', 26 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'pps_job_health_note', 26 );

// ═══════════════════════════════════════════════════════════════
// 2. EXCEPTIONS DIGEST
// ═══════════════════════════════════════════════════════════════

function pps_job_health_recipient() {
    if ( function_exists( 'pps_reorder_contact_recipient' ) ) return pps_reorder_contact_recipient();
    return 'Office@priorityprintservice.com';
}

function pps_job_health_open_statuses() {
    if ( function_exists( 'pps_paper_report_open_statuses' ) ) return pps_paper_report_open_statuses();
    return array( 'processing', 'on-hold', 'pending' );
}

/**
 * Every open job that needs a person before it runs, grouped by why. Pure: takes
 * the orders and the refusal log, returns sections; nothing is sent from here.
 */
function pps_job_health_collect( array $orders, array $refusals, $since_ts = 0, $tz = 'America/Phoenix' ) {
    $sec = array(
        'prepress' => array( 'title' => 'Artwork NOT approved — customer asked for prepress review', 'items' => array() ),
        'lowres'   => array( 'title' => 'Print file below print resolution', 'items' => array() ),
        'proof'    => array( 'title' => 'Awaiting a staff proof before printing', 'items' => array() ),
        'proofaddr'=> array( 'title' => 'Hardcopy proof with no usable address', 'items' => array() ),
        'closed'   => array( 'title' => 'Delivery promised on a day the shop is closed', 'items' => array() ),
        'refused'  => array( 'title' => 'Checkout refused (customer could not pay)', 'items' => array() ),
    );
    $zone = new DateTimeZone( $tz );

    foreach ( $orders as $order ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) continue;
        $who = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        foreach ( $order->get_items() as $item ) {
            $raw = $item->get_meta( '_pps_metadata' );
            if ( ! $raw ) continue;
            $meta = json_decode( (string) $raw, true );
            if ( ! is_array( $meta ) ) $meta = array();
            $ref = '#' . $order->get_id() . ' ' . $who . ' — ' . $item->get_name();

            if ( (string) $item->get_meta( 'PPS-Prepress-Review' ) !== '' ) $sec['prepress']['items'][] = $ref;

            $lw = (string) $item->get_meta( '_pps_print_check_warn' );
            if ( $lw !== '' ) $sec['lowres']['items'][] = $ref . ' — ' . $lw;

            $proof = (float) ( $meta['proof'] ?? 0 );
            if ( $proof > 0 ) {
                $sec['proof']['items'][] = $ref . ' — ' . ( $proof >= 3 ? 'hardcopy proof' : 'digital proof' );
                if ( $proof >= 3 ) {
                    if ( ! array_key_exists( 'proofAddrSame', $meta ) ) {
                        $sec['proofaddr']['items'][] = $ref . ' — address was never captured (older calculator build); ask the customer';
                    } elseif ( $meta['proofAddrSame'] === false ) {
                        $a = is_array( $meta['proofAddr'] ?? null ) ? $meta['proofAddr'] : array();
                        $street = trim( (string) ( $a['street'] ?? ( $a['street1'] ?? '' ) ) );
                        if ( $street === '' ) $sec['proofaddr']['items'][] = $ref . ' — a different address was chosen but left blank';
                    }
                }
            }

            $d = (string) $item->get_meta( '_pps_delivery_date' );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && function_exists( 'pps_is_business_day' ) ) {
                $dt = DateTime::createFromFormat( '!Y-m-d', $d, $zone );
                if ( $dt && ! pps_is_business_day( $dt ) ) $sec['closed']['items'][] = $ref . ' — ' . $dt->format( 'l, M j' );
            }
        }
    }

    foreach ( $refusals as $r ) {
        if ( ! is_array( $r ) ) continue;
        $t = strtotime( (string) ( $r['time'] ?? '' ) );
        if ( $t && $t <= $since_ts ) continue;
        $prod = is_array( $r['products'] ?? null ) ? implode( ', ', $r['products'] ) : (string) ( $r['products'] ?? '' );
        $errs = is_array( $r['errors'] ?? null ) ? implode( ' | ', array_map( 'wp_strip_all_tags', $r['errors'] ) ) : '';
        $sec['refused']['items'][] = trim( (string) ( $r['time'] ?? '' ) . ' — product ' . $prod . ( $errs !== '' ? ' — ' . $errs : '' ) );
    }

    return array_values( array_filter( $sec, static function( $s ) { return ! empty( $s['items'] ); } ) );
}

function pps_job_health_render( array $sections, $site = '' ) {
    if ( ! $sections ) return '';
    $n = 0; foreach ( $sections as $s ) $n += count( $s['items'] );
    $lines = array( 'PPS exceptions — ' . $n . ' item' . ( $n === 1 ? '' : 's' ) . ' need a look' . ( $site ? ' (' . $site . ')' : '' ), '' );
    foreach ( $sections as $s ) {
        $lines[] = strtoupper( $s['title'] ) . ' (' . count( $s['items'] ) . ')';
        foreach ( $s['items'] as $i ) $lines[] = '  • ' . $i;
        $lines[] = '';
    }
    $lines[] = 'Each order number opens in WooCommerce → Orders. This digest is sent once a day, only when there is something on it.';
    return implode( "\n", $lines );
}

/**
 * Build, store, and (when there is anything) send the digest. Returns the text.
 */
function pps_job_health_digest( $send = true ) {
    try {
        $since = (int) get_option( 'pps_job_health_last_ts', 0 );
        if ( ! $since ) $since = time() - 7 * 86400;      // first run: the last week of refusals, not the whole log
        $orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array(
            'limit' => 200, 'orderby' => 'date', 'order' => 'DESC', 'status' => pps_job_health_open_statuses(),
        ) ) : array();
        if ( ! is_array( $orders ) ) $orders = array();
        $refusals = get_option( 'pps_checkout_refusals', array() );
        if ( ! is_array( $refusals ) ) $refusals = array();
        $tz = 'America/Phoenix';
        if ( function_exists( 'pps_get_config' ) ) { $cfg = pps_get_config(); $tz = $cfg['pcf']['shop_timezone'] ?? $tz; }

        $sections = pps_job_health_collect( $orders, $refusals, $since, $tz );
        $site = function_exists( 'home_url' ) ? preg_replace( '#^https?://#', '', home_url() ) : '';
        $text = pps_job_health_render( $sections, $site );
        $count = 0; foreach ( $sections as $s ) $count += count( $s['items'] );

        update_option( 'pps_job_health_last', array( 'ts' => time(), 'count' => $count, 'text' => $text ), false );
        update_option( 'pps_job_health_last_ts', time(), false );

        if ( $send && $count > 0 ) {
            wp_mail( pps_job_health_recipient(), 'PPS exceptions: ' . $count . ' item' . ( $count === 1 ? '' : 's' ) . ' need a look', $text, array( 'Content-Type: text/plain; charset=UTF-8' ) );
        }
        return $text;
    } catch ( \Throwable $e ) {
        error_log( 'pps-job-health: digest failed: ' . $e->getMessage() );
        return '';
    }
}

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'pps_job_health_digest' ) ) {
        wp_schedule_event( time() + 300, 'daily', 'pps_job_health_digest' );
    }
} );
add_action( 'pps_job_health_digest', function () { pps_job_health_digest( true ); } );
