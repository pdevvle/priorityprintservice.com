<?php
/**
 * PPS Missive Panel — the production view inside Missive's sidebar. READ-ONLY.
 *
 * Owner, 2026-10-17: "write the sidebar functions first so I can test it against live tasks
 * without interfering with anything". Missive's custom iFrame integration loads a page in
 * its right-hand sidebar and tells it which conversation is open. This file serves that page
 * and the data behind it:
 *
 * - The page (`/?pps_missive_panel=1&k=<key>`) finds order numbers in the open
 *   conversation's subject and latest message, asks the site for those jobs, and shows each
 *   one: the production window (start → must-ship → delivery), the Job Ticket, the paper it
 *   runs on, and the other open jobs on the same paper and press sheet whose windows overlap
 *   ("run together"). With no order in view it shows every open job by must-ship date.
 *   It also shows, greyed, what the planned automation WOULD write to Missive (task due
 *   date, title, labels), so it can be compared with today's tasks before anything writes.
 * - The data (`GET /wp-json/pps/v1/missive/panel`) answers only with the panel key, sent
 *   in the `X-PPS-Panel-Key` header.
 *
 * The page calls only Missive's read and navigation methods (on, fetchConversations,
 * search, openURL) — never createTask, addLabels, assign, comment or any other method that
 * changes Missive — and nothing here ever changes an order. The one write is a paired
 * subtask's STATE, made by the site through the owner's API token, only when the owner has
 * switched it on (see "Missive subtasks" below). tools-missive-panel-test.php checks all of it.
 *
 * Since 2026-10-18 (owner: "Base it on subtasks. The panel should show the board with
 * sortable job attributes") the board is always shown — sortable by must-ship, start,
 * delivery, paper, press sheet, sheets, quantity, task state, task due date, date mismatch,
 * order or customer; groupable by paper + sheet, sheet, task state, finishing, ship date or
 * rush; filterable by text — with the open conversation's order in full above it.
 *
 * The key lives in `wp_options['pps_missive_panel_key']` (autoload off) and is made on the
 * first visit to PPS Calculators → Missive Panel, which shows the URL to paste into
 * Missive (Settings → Integrations → Add integration → Custom). Regenerating it there cuts
 * off the old URL. The key is in the URL because Missive loads the page by URL; it lets the
 * holder read open-job schedules and tickets (names, ship-to), nothing more.
 *
 * Imposition (2026-10-18, owner: "lets add the imposition for jobs. It runs on the site and
 * the iframe pipes it in"): an Impose button on the order card and on each board row opens
 * the site's own imposition tool for that order inside the panel (pps-missive-impose.php).
 * It can file IMPOSED_/CLEAN_ PDFs into that order's Drive folder, and nothing else.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_MP_KEY_OPTION', 'pps_missive_panel_key' );
define( 'PPS_MP_CACHE', 'pps_missive_panel_open' );

// The imposition tool, run on the site and shown inside the panel (owner 2026-10-18).
require_once __DIR__ . '/pps-missive-impose.php';
require_once __DIR__ . '/pps-missive-ship.php';

/* ─────────────────────────────────────────────────────────────
 * Pure helpers — the test drives these directly
 * ───────────────────────────────────────────────────────────── */

/** A Y-m-d out of anything date-shaped, or ''. */
function pps_mp_ymd( $v ) {
    $v = trim( (string) $v );
    if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return "$m[1]-$m[2]-$m[3]";
    $t = $v !== '' ? strtotime( $v ) : false;
    return $t ? gmdate( 'Y-m-d', $t ) : '';
}

/** A Drive folder id → its web URL, or '' (ids are Drive's own: letters, digits, - and _). */
function pps_mp_drive_folder_url( $id ) {
    $id = (string) $id;
    return preg_match( '/^[A-Za-z0-9_-]{10,}$/', $id ) ? 'https://drive.google.com/drive/folders/' . $id : '';
}

/**
 * What the panel shows about an order's Google Drive folder (owner 2026-10-18: "an
 * integration that opens google drive to the order in question"). The folder is the one the
 * artwork uploader made (`_pps_gdrive_folder_id`); its files come from the imposition tool's
 * own read-only listing (`pps_impose_drive_list`, cached 3 min) — which, under the site's
 * `drive.file` scope, sees only files the SITE created, never ones added to the folder by hand
 * (87347's imposed PDF, added by hand, is not listed). Links open in the browser,
 * where Drive's own sign-in decides who can see them — the panel never downloads a file.
 */
function pps_mp_drive_info( $order, $list = null ) {
    $id  = (string) $order->get_meta( '_pps_gdrive_folder_id' );
    $out = array( 'folder' => pps_mp_drive_folder_url( $id ), 'files' => array(), 'state' => 'ok', 'note' => '',
                  'all' => function_exists( 'pps_gdrive_can_read_all' ) && pps_gdrive_can_read_all() );
    if ( $out['folder'] === '' ) {
        $out['state'] = $order->get_meta( '_pps_drive_failed' ) ? 'failed' : ( (int) $order->get_meta( '_pps_drive_attempts' ) > 0 ? 'waiting' : 'none' );
        $out['note']  = $out['state'] === 'failed' ? 'The artwork upload to Drive gave up — see the order notes.'
                      : ( $out['state'] === 'waiting' ? 'The artwork is still being uploaded to Drive (it retries).' : 'No Drive folder for this order (no uploaded artwork).' );
        return $out;
    }
    $files = $list !== null ? $list : ( function_exists( 'pps_impose_drive_list' ) ? pps_impose_drive_list( $id ) : null );
    if ( ! is_array( $files ) ) { $out['state'] = 'unlisted'; $out['note'] = 'Could not list the folder just now — open it in Drive.'; return $out; }
    foreach ( $files as $f ) {
        $fid = (string) ( $f['id'] ?? '' );
        if ( ! preg_match( '/^[A-Za-z0-9_-]{10,}$/', $fid ) ) continue;
        $name = (string) ( $f['name'] ?? '' );
        $out['files'][] = array( 'name' => $name, 'size' => (int) ( $f['size'] ?? 0 ),
            'url' => ( ( $f['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder' ? 'https://drive.google.com/drive/folders/' : 'https://drive.google.com/file/d/' ) . $fid . ( ( $f['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder' ? '' : '/view' ),
            'imposed' => (bool) preg_match( '/^IMPOSED[_\s-]/i', $name ) );
    }
    usort( $out['files'], function ( $a, $b ) { return ( $b['imposed'] <=> $a['imposed'] ) ?: strcasecmp( $a['name'], $b['name'] ); } );
    return $out;
}

/** "Label: value" lines of the Job Ticket → [[label, value], …]. */
function pps_mp_ticket_pairs( $text ) {
    $out = array();
    foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
        $line = trim( $line );
        if ( $line === '' ) continue;
        $p = strpos( $line, ': ' );
        $out[] = $p === false ? array( '', $line ) : array( substr( $line, 0, $p ), substr( $line, $p + 2 ) );
    }
    return $out;
}

/**
 * One record per order line: dates, ticket, paper rows, finishing. `$paper` maps item id →
 * paper rows already classified by pps_po_build() (the Paper Order's own reading), so the
 * panel and the supply order can never disagree about what a job runs on.
 */
function pps_mp_jobs( array $orders, array $paper = array() ) {
    $jobs = array();
    foreach ( $orders as $order ) {
        $customer = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_company();
        foreach ( $order->get_items() as $item_id => $item ) {
            $meta = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $j = array(
                'order' => (int) $order->get_id(), 'item' => (int) $item_id, 'status' => $order->get_status(),
                'customer' => $customer, 'product' => $item->get_name(),
                'job' => (string) ( $meta['jobName'] ?? '' ),
                'qty' => (string) ( $meta['totalQty'] ?? $meta['qty'] ?? '' ),
                'size' => (string) ( $meta['sizeLabel'] ?? '' ),
                'start' => pps_mp_ymd( $item->get_meta( 'PPS-Production-Start' ) ?: ( $meta['productionStartDate'] ?? '' ) ),
                'must_ship' => pps_mp_ymd( $meta['mustShipByDate'] ?? '' ),
                'delivery' => pps_mp_ymd( $item->get_meta( '_pps_delivery_date' ) ?: ( $meta['estimatedDeliveryDate'] ?? '' ) ),
                'rush' => (float) $item->get_meta( '_pps_rush' ) > 0 || (float) ( $meta['rushCost'] ?? 0 ) > 0,
                'addons' => (string) $item->get_meta( 'Add-ons' ),
                'ticket' => pps_mp_ticket_pairs( $item->get_meta( 'Job Ticket' ) ),
                'printed' => (string) $item->get_meta( '_pps_paper_done' ) !== '',
                'source' => $meta ? 'calculator' : 'custom',
                'papers' => array(),
                'drive' => pps_mp_drive_folder_url( method_exists( $order, 'get_meta' ) ? $order->get_meta( '_pps_gdrive_folder_id' ) : '' ),
            );
            $j += pps_mp_print_spec( $meta );
            if ( $j['delivery'] === '' && ! $meta ) $j['delivery'] = pps_mp_ymd( $item->get_meta( 'Requested delivery' ) );
            if ( ! $j['ticket'] && ! $meta ) {
                // A custom line has no ticket: show what a person can read on it.
                foreach ( $item->get_meta_data() as $m ) {
                    $d = $m->get_data();
                    if ( strpos( (string) $d['key'], '_' ) === 0 || ! is_scalar( $d['value'] ) || (string) $d['value'] === '' ) continue;
                    $j['ticket'][] = array( (string) $d['key'], (string) $d['value'] );
                }
            }
            foreach ( $paper[ (int) $item_id ] ?? array() as $r ) {
                $p = $r['paper'] ?? null;
                if ( ! is_array( $p ) ) continue;
                $j['papers'][] = array(
                    'key' => ( (string) ( $p['val'] ?? '' ) !== '' ? 'v' . $p['val'] : 'l' . strtolower( (string) $p['label'] ) ) . '|' . $r['sheet'],
                    'label' => (string) $p['label'], 'sheet' => str_replace( 'x', '×', (string) $r['sheet'] ),
                    'sheets' => (int) $r['sheets'], 'role' => (string) $r['role'], 'in_stock' => ! empty( $p['in_stock'] ),
                );
            }
            $jobs[] = $j;
        }
    }
    return $jobs;
}

/**
 * How a job prints and how many pages, in a few words for the board's pills, from the
 * same metadata keys PPS-Spec reads (pps_build_spec). Flats: colour per side ("Color 2-sided",
 * "Color / B&W", "B&W 1-sided"), no page count. Booklets: inside and cover colour, pages
 * per book (each distinct count when the sets differ). Custom lines: nothing.
 */
function pps_mp_print_spec( array $meta ) {
    $c = function ( $v ) { return $v === 'bw' ? 'B&W' : ( $v === 'mixed' ? 'Mixed' : 'Color' ); };
    $sets = is_array( $meta['sets'] ?? null ) ? $meta['sets'] : array();
    if ( ! $sets && isset( $meta['qty'] ) ) {
        $fc = $c( (string) ( $meta['frontColor'] ?? '' ) );
        if ( intval( $meta['sides'] ?? 2 ) === 1 ) return array( 'print' => $fc . ' 1-sided', 'pages' => '' );
        $bc = $c( (string) ( $meta['backColor'] ?? '' ) );
        return array( 'print' => $fc === $bc ? $fc . ' 2-sided' : $fc . ' / ' . $bc, 'pages' => '' );
    }
    if ( ! $sets && ! isset( $meta['pages'] ) ) return array( 'print' => '', 'pages' => '' );
    $pg = array_values( array_unique( array_filter( array_map( 'intval', $sets ? array_column( $sets, 'pages' ) : array( $meta['pages'] ) ) ) ) );
    $in = $c( (string) ( $meta['insideColor'] ?? '' ) );
    $self = ( $meta['coverMode'] ?? '' ) === 'same' || ! is_array( $meta['coverPaper'] ?? null ) || (string) ( $meta['coverPaper']['label'] ?? '' ) === '';
    return array( 'print' => $self ? $in . ', self-cover' : 'Inside ' . $in . ' · Cover ' . $c( (string) ( $meta['coverColor'] ?? '' ) ),
                  'pages' => $pg ? implode( ' / ', $pg ) . ' pp' : '' );
}

/** A job's window as [from, to] Y-m-d; from falls back to today, to to delivery. */
function pps_mp_window( array $j, $today ) {
    $to   = $j['must_ship'] !== '' ? $j['must_ship'] : $j['delivery'];
    $from = $j['start'] !== '' ? $j['start'] : $today;
    if ( $to !== '' && $from > $to ) $from = $to;
    return array( $from, $to );
}

/**
 * Open jobs that could run with this one: they share a paper AND press sheet, and their
 * windows overlap. A job with no end date, already printed, or the job itself, is left out.
 * Each mate lists the shared paper and its sheets; `combined` is per shared paper.
 */
function pps_mp_batch( array $job, array $open, $today ) {
    list( $f, $t ) = pps_mp_window( $job, $today );
    if ( $t === '' || ! $job['papers'] ) return array( 'mates' => array(), 'combined' => array() );
    $mine = array();
    foreach ( $job['papers'] as $p ) $mine[ $p['key'] ] = $p;
    $mates = array(); $combined = array();
    foreach ( $mine as $k => $p ) $combined[ $k ] = array( 'label' => $p['label'], 'sheet' => $p['sheet'], 'sheets' => 0, 'jobs' => 0 );
    foreach ( $open as $o ) {
        if ( $o['item'] === $job['item'] || $o['printed'] ) continue;
        list( $of, $ot ) = pps_mp_window( $o, $today );
        if ( $ot === '' || $of > $t || $ot < $f ) continue;
        $shared = array();
        foreach ( $o['papers'] as $p ) {
            if ( ! isset( $mine[ $p['key'] ] ) ) continue;
            $shared[] = array( 'label' => $p['label'], 'sheet' => $p['sheet'], 'sheets' => $p['sheets'] );
            $combined[ $p['key'] ]['sheets'] += $p['sheets'];
            $combined[ $p['key'] ]['jobs']++;
        }
        if ( ! $shared ) continue;
        $mates[] = array( 'order' => $o['order'], 'customer' => $o['customer'], 'what' => $o['job'] !== '' ? $o['job'] : $o['product'],
                          'start' => $of, 'must_ship' => $ot, 'rush' => $o['rush'], 'shared' => $shared );
    }
    foreach ( $job['papers'] as $p ) if ( isset( $combined[ $p['key'] ] ) ) $combined[ $p['key'] ]['sheets'] += $p['sheets'];
    usort( $mates, function ( $a, $b ) { return strcmp( $a['must_ship'], $b['must_ship'] ) ?: $a['order'] <=> $b['order']; } );
    $combined = array_values( array_filter( $combined, function ( $c ) { return $c['jobs'] > 0; } ) );
    return array( 'mates' => $mates, 'combined' => $combined );
}

/**
 * What the planned automation would write to Missive for this job. Shown greyed in the
 * panel so it can be checked against today's tasks; nothing sends it.
 */
function pps_mp_preview( array $j ) {
    $md = function ( $ymd ) { return $ymd !== '' ? gmdate( 'M j', strtotime( $ymd . ' 12:00 UTC' ) ) : ''; };
    $what  = $j['job'] !== '' ? $j['job'] : $j['product'];
    $title = '#' . $j['order'] . ' ' . $what . ( $j['start'] !== '' ? ' · start ' . $md( $j['start'] ) : '' )
           . ( $j['must_ship'] !== '' ? ' · ship ' . $md( $j['must_ship'] ) : '' )
           . ( $j['delivery'] !== '' ? ' · deliver ' . $md( $j['delivery'] ) : '' );
    $labels = array();
    foreach ( $j['papers'] as $p ) { $labels[] = $p['label']; $labels[] = $p['sheet']; }
    if ( $j['rush'] ) $labels[] = 'RUSH';
    foreach ( preg_split( '/;\s*/', $j['addons'] ) as $a ) {
        $a = trim( $a );
        if ( $a === '' ) continue;
        $labels[] = trim( strpos( $a, ':' ) !== false ? substr( $a, 0, strpos( $a, ':' ) ) : $a );
    }
    return array( 'due' => $j['start'] !== '' ? $j['start'] : $j['must_ship'], 'title' => $title, 'labels' => array_values( array_unique( array_filter( $labels ) ) ) );
}

/* ─────────────────────────────────────────────────────────────
 * WordPress side
 * ───────────────────────────────────────────────────────────── */

function pps_mp_key( $make = false ) {
    $k = (string) get_option( PPS_MP_KEY_OPTION, '' );
    if ( $k === '' && $make ) {
        $k = bin2hex( random_bytes( 20 ) );
        update_option( PPS_MP_KEY_OPTION, $k, false );
    }
    return $k;
}

function pps_mp_key_ok( $given ) {
    $k = pps_mp_key();
    return $k !== '' && is_string( $given ) && $given !== '' && hash_equals( $k, $given );
}

function pps_mp_today() {
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( 'now', new DateTimeZone( $tz ) ) )->format( 'Y-m-d' ); }
    catch ( \Throwable $e ) { return gmdate( 'Y-m-d' ); }
}

/** Paper rows by item id, from the Paper Order's reading of these orders. */
function pps_mp_paper_index( array $orders ) {
    $idx = array();
    if ( ! function_exists( 'pps_po_build' ) ) return $idx;
    $b = pps_po_build( $orders );
    foreach ( $b['jobs'] as $j ) $idx[ (int) $j['item_id'] ] = $j['rows'];
    return $idx;
}

/** Every open job (processing, on hold), cached five minutes — one scan serves many panels. */
function pps_mp_open_jobs() {
    $c = get_transient( PPS_MP_CACHE );
    if ( is_array( $c ) ) return $c;
    $orders = function_exists( 'wc_get_orders' )
        ? wc_get_orders( array( 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC', 'status' => array( 'processing', 'on-hold' ) ) ) : array();
    $orders = is_array( $orders ) ? $orders : array();
    $jobs = pps_mp_jobs( $orders, pps_mp_paper_index( $orders ) );
    set_transient( PPS_MP_CACHE, $jobs, 5 * 60 );
    return $jobs;
}

/* ─────────────────────────────────────────────────────────────
 * Missive subtasks (owner 2026-10-17: "Base it on subtasks")
 *
 * Each job's Missive task is a SUBTASK inside the order's conversation. The site reads the
 * organization's open subtasks with the owner's API token (`pps_missive_api_token`,
 * autoload off, never sent to a browser) and pairs each with its order through the
 * conversation it sits in. Conversation → order comes from, in order: what the panel saw
 * (it knows the open conversation's id and the order number in it), an order number in the
 * task's own title, and — at most 8 per refresh — the conversation's subject from the API.
 * Pairs are kept in `pps_missive_conv_orders` (a conversation with no order number is
 * remembered as 0 so it is not looked up again).
 *
 * Changing a subtask's state (Start / Done / Reopen) happens only when PPS Calculators →
 * Missive Panel → "Let the panel change subtask state" is ticked
 * (`pps_missive_allow_state`, off by default), only for a subtask the site has paired with
 * an order, and only its `state` — never its title, due date, assignees or anything else.
 * ───────────────────────────────────────────────────────────── */

define( 'PPS_MP_TOKEN_OPTION', 'pps_missive_api_token' );
define( 'PPS_MP_ORG_OPTION', 'pps_missive_org' );
define( 'PPS_MP_CONV_OPTION', 'pps_missive_conv_orders' );
define( 'PPS_MP_ALLOW_OPTION', 'pps_missive_allow_state' );
define( 'PPS_MP_TASKS_CACHE', 'pps_missive_tasks' );
define( 'PPS_MP_API', 'https://public.missiveapp.com/v1/' );
define( 'PPS_MP_ITEMS_OPTION', 'pps_missive_item_tasks' );   // order line item id → the subtask the panel created for it
define( 'PPS_MP_CREATE_OPTION', 'pps_missive_allow_create' );

/** The first order number in a piece of text ("#87347", "Order 87347"), or 0. */
function pps_mp_order_in( $text ) {
    return preg_match( '/(?:order\s*#?\s*|#\s?)(\d{5,6})\b/i', (string) $text, $m ) ? (int) $m[1] : 0;
}

/** A unix timestamp as a shop-day Y-m-d, or ''. */
/** A Missive timestamp as H:i shop time ('' when none) — the time on a subtask's due pill. */
function pps_mp_unix_hm( $ts ) {
    $ts = (int) $ts;
    if ( $ts <= 0 ) return '';
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'H:i' ); }
    catch ( \Throwable $e ) { return gmdate( 'H:i', $ts ); }
}

function pps_mp_unix_ymd( $ts ) {
    $ts = (int) $ts;
    if ( $ts <= 0 ) return '';
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d' ); }
    catch ( \Throwable $e ) { return gmdate( 'Y-m-d', $ts ); }
}

/**
 * Pair subtasks with orders. `$tasks` are Missive task objects; `$conv` maps conversation id →
 * order (0 = known to have none). A task whose title names an order wins over its
 * conversation. Where an order has several, the open one due soonest is kept.
 * A subtask the panel created for one job (`$items`: line item → task id) belongs to that job
 * alone and is kept apart. Returns order → task, the unpaired subtasks, and item → task.
 */
function pps_mp_pair_tasks( array $tasks, array $conv, array $items = array() ) {
    $by = array(); $unpaired = array(); $by_item = array();
    $item_of = array();
    foreach ( $items as $item => $tid ) $item_of[ (string) $tid ] = (int) $item;
    $rank = array( 'in_progress' => 0, 'todo' => 1, 'closed' => 2 );
    foreach ( $tasks as $t ) {
        if ( ! is_array( $t ) || empty( $t['id'] ) || empty( $t['conversation'] ) ) continue;   // subtasks only
        $cid   = is_array( $t['conversation'] ) ? (string) ( $t['conversation']['id'] ?? '' ) : (string) $t['conversation'];
        $order = pps_mp_order_in( $t['title'] ?? '' ) ?: (int) ( $conv[ $cid ] ?? 0 );
        $row = array( 'id' => (string) $t['id'], 'title' => (string) ( $t['title'] ?? '' ), 'state' => (string) ( $t['state'] ?? 'todo' ),
                      'due' => pps_mp_unix_ymd( $t['due_at'] ?? 0 ), 'due_time' => pps_mp_unix_hm( $t['due_at'] ?? 0 ), 'started' => pps_mp_unix_ymd( $t['started_at'] ?? 0 ), 'conversation' => $cid );
        if ( isset( $item_of[ $row['id'] ] ) ) { $by_item[ $item_of[ $row['id'] ] ] = $row; continue; }   // one created per job
        if ( ! $order ) { $unpaired[] = $row; continue; }
        $cur = $by[ $order ] ?? null;
        $better = ! $cur
            || ( $rank[ $row['state'] ] ?? 1 ) < ( $rank[ $cur['state'] ] ?? 1 )
            || ( ( $rank[ $row['state'] ] ?? 1 ) === ( $rank[ $cur['state'] ] ?? 1 ) && $row['due'] !== '' && ( $cur['due'] === '' || $row['due'] < $cur['due'] ) );
        if ( $better ) $by[ $order ] = $row;
    }
    return array( $by, $unpaired, $by_item );
}

/** One board row per open job, with its subtask and whether the task's due date agrees. */
function pps_mp_board_rows( array $open, array $tasks_by_order, $today, array $tasks_by_item = array() ) {
    $rows = array();
    foreach ( $open as $j ) {
        list( $f, $t ) = pps_mp_window( $j, $today );
        $task = $tasks_by_item[ $j['item'] ] ?? ( $tasks_by_order[ $j['order'] ] ?? null );
        $fin = array(); $add = array();
        foreach ( preg_split( '/;\s*/', (string) $j['addons'] ) as $a ) {
            $a = trim( $a );
            if ( $a === '' ) continue;
            $fin[] = trim( strpos( $a, ':' ) !== false ? substr( $a, 0, strpos( $a, ':' ) ) : $a );
            $add[] = $a;
        }
        $p0 = $j['papers'][0] ?? null;
        $rows[] = array(
            'order' => $j['order'], 'item' => $j['item'], 'customer' => $j['customer'], 'what' => $j['job'] !== '' ? $j['job'] : $j['product'],
            'qty' => $j['qty'], 'size' => $j['size'], 'print' => $j['print'] ?? '', 'pages' => $j['pages'] ?? '', 'start' => $f, 'must_ship' => $t, 'delivery' => $j['delivery'],
            'rush' => $j['rush'], 'printed' => $j['printed'], 'source' => $j['source'], 'finishing' => array_values( array_unique( $fin ) ), 'addons' => array_values( array_unique( $add ) ),
            'paper' => $p0 ? $p0['label'] : '', 'sheet' => $p0 ? $p0['sheet'] : '', 'paper_key' => $p0 ? $p0['key'] : '',
            'sheets' => array_sum( array_column( $j['papers'], 'sheets' ) ),
            'papers' => array_map( function ( $p ) { return array( 'label' => $p['label'], 'sheet' => $p['sheet'], 'sheets' => $p['sheets'], 'role' => $p['role'], 'key' => $p['key'], 'in_stock' => ! empty( $p['in_stock'] ) ); }, $j['papers'] ),
            'task' => $task, 'drive' => $j['drive'] ?? '',
            // The team sets a task's due date to the job's START date (12 of 16 live on 2026-10-18), so that is what it is checked against.
            'due_mismatch' => $task && $task['due'] !== '' && $j['start'] !== '' && $task['due'] !== $j['start'],
        );
    }
    return $rows;
}

/**
 * Open Missive subtasks that no board row carries (owner 2026-10-20: "I'm seeing some tasks on
 * missive's calendar that should be appearing in our integration but are not"). The board is
 * built from open WooCommerce jobs, so a subtask whose order is completed, pending, cancelled
 * or not on the site — or whose conversation names no order — never showed; it was only
 * counted. Each becomes a row of its own, dated by the task's due date, with the reason it is
 * not on a job. Order lookups are capped at `$max` a refresh.
 */
function pps_mp_extra_rows( array $tasks, array $conv, array $rows, $max = 40 ) {
    $used = array();
    foreach ( $rows as $r ) if ( ! empty( $r['task']['id'] ) ) $used[ $r['task']['id'] ] = 1;
    $open_orders = array();
    foreach ( $rows as $r ) $open_orders[ (int) $r['order'] ] = 1;
    $out = array(); $looked = array(); $n = 0;
    $words = array( 'completed' => 'is completed', 'pending' => 'is waiting for payment', 'cancelled' => 'was cancelled', 'refunded' => 'was refunded',
                    'failed' => 'failed payment', 'draft' => 'is a draft', 'checkout-draft' => 'is a draft' );
    foreach ( $tasks as $t ) {
        if ( ! is_array( $t ) || empty( $t['id'] ) || empty( $t['conversation'] ) ) continue;
        $state = (string) ( $t['state'] ?? 'todo' );
        if ( $state === 'closed' || isset( $used[ (string) $t['id'] ] ) ) continue;
        $used[ (string) $t['id'] ] = 1;
        $cid   = is_array( $t['conversation'] ) ? (string) ( $t['conversation']['id'] ?? '' ) : (string) $t['conversation'];
        $order = pps_mp_order_in( $t['title'] ?? '' ) ?: (int) ( $conv[ $cid ] ?? 0 );
        $customer = ''; $status = '';
        if ( ! $order ) $reason = array_key_exists( $cid, $conv ) ? 'No order number in this conversation' : 'Conversation not read yet — it pairs on a later refresh if it names an order';
        elseif ( isset( $open_orders[ $order ] ) ) $reason = 'Order #' . $order . ' has another subtask shown on its job';
        else {
            if ( ! array_key_exists( $order, $looked ) ) {
                $looked[ $order ] = ( $n++ < $max && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order ) : null;
            }
            $o = $looked[ $order ];
            if ( $o && is_object( $o ) && method_exists( $o, 'get_status' ) ) {
                $status   = (string) $o->get_status();
                $customer = trim( ( method_exists( $o, 'get_billing_first_name' ) ? $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() : '' ) );
                $reason   = 'Order #' . $order . ' ' . ( $words[ $status ] ?? 'is ' . $status ) . ( in_array( $status, array( 'processing', 'on-hold' ), true ) ? ' but has no job the board can read' : '' );
            } else $reason = $n > $max ? 'Order #' . $order . ' not checked this refresh' : '#' . $order . ' is not an order on the site';
        }
        $due = pps_mp_unix_ymd( $t['due_at'] ?? 0 );
        $out[] = array(
            'order' => $order, 'item' => 0, 'customer' => $customer, 'what' => (string) ( $t['title'] ?? '' ), 'qty' => 0, 'size' => '',
            'start' => $due, 'must_ship' => $due, 'delivery' => '', 'rush' => false, 'printed' => false, 'source' => 'missive',
            'finishing' => array(), 'addons' => array(), 'paper' => '', 'sheet' => '', 'paper_key' => '', 'sheets' => 0, 'papers' => array(),
            'task' => array( 'id' => (string) $t['id'], 'title' => (string) ( $t['title'] ?? '' ), 'state' => $state, 'due' => $due, 'due_time' => pps_mp_unix_hm( $t['due_at'] ?? 0 ),
                             'started' => pps_mp_unix_ymd( $t['started_at'] ?? 0 ), 'conversation' => $cid ),
            'drive' => '', 'due_mismatch' => false, 'missive_only' => true, 'reason' => $reason, 'order_status' => $status,
        );
    }
    return $out;
}

function pps_mp_token() { return trim( (string) get_option( PPS_MP_TOKEN_OPTION, '' ) ); }

/** One call to Missive's API. Returns the decoded body or a WP_Error. Never logs the token. */
function pps_mp_api( $method, $path, array $query = array(), $body = null ) {
    $token = pps_mp_token();
    if ( $token === '' ) return new WP_Error( 'no_token', 'No Missive API token saved.' );
    $url  = PPS_MP_API . ltrim( $path, '/' ) . ( $query ? '?' . http_build_query( $query ) : '' );
    $args = array( 'method' => $method, 'timeout' => 8, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) );
    if ( $body !== null ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
    $r = wp_remote_request( $url, $args );
    if ( is_wp_error( $r ) ) return $r;
    $code = (int) wp_remote_retrieve_response_code( $r );
    $data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
    if ( $code < 200 || $code >= 300 ) {
        $why = is_array( $data ) ? trim( (string) ( $data['error'] ?? $data['message'] ?? ( is_array( $data['errors'] ?? null ) ? implode( '; ', array_map( 'strval', array_filter( $data['errors'], 'is_scalar' ) ) ) : '' ) ) ) : '';
        return new WP_Error( 'missive_' . $code, 'Missive answered ' . $code . ( $code === 401 ? ' (the token was refused)' : '' ) . ( $why !== '' ? ': ' . mb_substr( $why, 0, 200 ) : '' ) . '.' );
    }
    return is_array( $data ) ? $data : array();
}

/** The organization the token belongs to (first one), remembered. */
function pps_mp_org() {
    $org = (string) get_option( PPS_MP_ORG_OPTION, '' );
    if ( $org !== '' ) return $org;
    $d = pps_mp_api( 'GET', 'organizations' );
    if ( is_wp_error( $d ) ) return $d;
    $org = (string) ( $d['organizations'][0]['id'] ?? '' );
    if ( $org === '' ) return new WP_Error( 'no_org', 'The token sees no Missive organization.' );
    update_option( PPS_MP_ORG_OPTION, $org, false );
    return $org;
}

/** Open subtasks (to do, in progress) plus recently closed ones, cached two minutes. */
function pps_mp_tasks( $fresh = false ) {
    if ( ! $fresh ) { $c = get_transient( PPS_MP_TASKS_CACHE ); if ( is_array( $c ) ) return $c; }
    $org = pps_mp_org();
    if ( is_wp_error( $org ) ) return $org;
    $all = array();
    foreach ( array( 'in_progress' => 4, 'todo' => 4, 'closed' => 1 ) as $state => $pages ) {
        $until = null;
        for ( $p = 0; $p < $pages; $p++ ) {
            $q = array( 'organization' => $org, 'type' => 'task', 'state' => $state, 'limit' => 50 );
            if ( $until ) $q['until'] = $until;
            $d = pps_mp_api( 'GET', 'tasks', $q );
            if ( is_wp_error( $d ) ) return $d;
            $batch = is_array( $d['tasks'] ?? null ) ? $d['tasks'] : array();
            foreach ( $batch as $t ) $all[] = $t;
            if ( count( $batch ) < 50 ) break;
            $last  = end( $batch );
            $until = (int) ( $last['last_activity_at'] ?? 0 ) - 1;
            if ( $until <= 0 ) break;
        }
    }
    set_transient( PPS_MP_TASKS_CACHE, $all, 2 * 60 );
    return $all;
}

/** Fill conversation → order for subtasks not yet known, from the API (at most `$max`). */
function pps_mp_learn_from_api( array $tasks, array $conv, $max = 8 ) {
    $asked = 0; $changed = false;
    foreach ( $tasks as $t ) {
        if ( $asked >= $max ) break;
        $cid = is_array( $t['conversation'] ?? null ) ? (string) ( $t['conversation']['id'] ?? '' ) : (string) ( $t['conversation'] ?? '' );
        if ( $cid === '' || array_key_exists( $cid, $conv ) || pps_mp_order_in( $t['title'] ?? '' ) ) continue;
        $asked++;
        $d = pps_mp_api( 'GET', 'conversations/' . rawurlencode( $cid ) );
        if ( is_wp_error( $d ) ) break;
        $c = $d['conversations'][0] ?? array();
        $conv[ $cid ] = pps_mp_order_in( ( $c['subject'] ?? '' ) . ' ' . ( $c['latest_message_subject'] ?? '' ) );
        $changed = true;
    }
    if ( $changed ) pps_mp_save_conv( $conv );
    return $conv;
}

function pps_mp_conv_map() { $m = get_option( PPS_MP_CONV_OPTION, array() ); return is_array( $m ) ? $m : array(); }
function pps_mp_save_conv( array $m ) {
    if ( count( $m ) > 3000 ) $m = array_slice( $m, -3000, null, true );
    update_option( PPS_MP_CONV_OPTION, $m, false );
}

/** The endpoint's answer. `$ids`: order numbers in the open conversation; `$conv`: its id. */
function pps_mp_answer( array $ids, $conv = '' ) {
    $today = pps_mp_today();
    $open  = pps_mp_open_jobs();
    $ids   = array_values( array_filter( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 5 ) ) );
    $out   = array( 'today' => $today, 'orders' => array(), 'missing' => array(), 'board' => array(),
                    'missive' => array( 'connected' => false, 'error' => '', 'can_change' => (bool) get_option( PPS_MP_ALLOW_OPTION, false ),
                                        'can_create' => (bool) get_option( PPS_MP_CREATE_OPTION, false ), 'unpaired' => 0 ) );

    // What the panel saw: this conversation holds this order.
    $map = pps_mp_conv_map();
    $conv = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $conv );
    if ( $conv !== '' && count( $ids ) === 1 && ( $map[ $conv ] ?? null ) !== $ids[0] ) { $map[ $conv ] = $ids[0]; pps_mp_save_conv( $map ); }

    $by = array(); $by_item = array();
    if ( pps_mp_token() !== '' ) {
        $tasks = pps_mp_tasks();
        if ( is_wp_error( $tasks ) ) {
            $out['missive']['error'] = $tasks->get_error_message();
        } else {
            $map = pps_mp_learn_from_api( $tasks, $map );
            list( $by, $unpaired, $by_item ) = pps_mp_pair_tasks( $tasks, $map, pps_mp_item_map() );
            $out['missive']['connected'] = true;
            $out['missive']['unpaired'] = count( $unpaired );
        }
    }

    foreach ( $ids as $id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
        if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) { $out['missing'][] = $id; continue; }
        $jobs = pps_mp_jobs( array( $order ), pps_mp_paper_index( array( $order ) ) );
        foreach ( $jobs as &$j ) {
            $j['window']  = pps_mp_window( $j, $today );
            $j['batch']   = pps_mp_batch( $j, $open, $today );
            $j['preview'] = pps_mp_preview( $j );
            $j['task']    = $by_item[ $j['item'] ] ?? ( $by[ $id ] ?? null );
            $j['own_task'] = isset( $by_item[ $j['item'] ] ) || isset( pps_mp_item_map()[ $j['item'] ] );
        }
        unset( $j );
        $needs = count( array_filter( $jobs, function ( $j ) { return ! $j['own_task']; } ) );
        $out['orders'][] = array( 'order' => $id, 'status' => $order->get_status(),
            'admin_url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $id ), 'jobs' => $jobs,
            'conv_ok' => $conv !== '' && (int) ( $map[ $conv ] ?? 0 ) === $id, 'needs' => $needs,
            'order_task' => $by[ $id ] ?? null, 'drive' => pps_mp_drive_info( $order ),
            'ship' => function_exists( 'pps_ms_summary' ) ? pps_ms_summary( $order ) : null,
            'ship_to' => function_exists( 'pps_st_view' ) ? pps_st_view( $order ) : null );
    }
    $out['board'] = pps_mp_board_rows( $open, $by, $today, $by_item );
    if ( isset( $tasks ) && is_array( $tasks ) ) {
        $extra = pps_mp_extra_rows( $tasks, $map, $out['board'] );
        $out['missive']['extra'] = count( $extra );
        $out['board'] = array_merge( $out['board'], $extra );
    }
    return $out;
}

/**
 * Whether a task id is one of the subtasks the board shows (owner 2026-10-25: "the top box has the
 * correct button but the lower tasks don't"): a paired job's or order's subtask, or a Missive-only
 * row's — any SUBTASK (it sits in a conversation) in the panel's own task list. A task the panel does
 * not list, or a standalone task with no conversation, is never changed.
 */
function pps_mp_task_listed( $tasks, $task_id ) {
    if ( ! is_array( $tasks ) || (string) $task_id === '' ) return false;
    foreach ( $tasks as $t ) if ( is_array( $t ) && (string) ( $t['id'] ?? '' ) === (string) $task_id && ! empty( $t['conversation'] ) ) return true;
    return false;
}

/** Change one subtask's state. Returns true or a WP_Error naming why not. */
function pps_mp_set_state( $task_id, $state ) {
    if ( ! get_option( PPS_MP_ALLOW_OPTION, false ) ) return new WP_Error( 'off', 'Changing subtasks from the panel is switched off (PPS Calculators → Missive Panel).' );
    if ( ! in_array( $state, array( 'todo', 'in_progress', 'closed' ), true ) ) return new WP_Error( 'state', 'Unknown state.' );
    $tasks = pps_mp_tasks();
    if ( is_wp_error( $tasks ) ) return $tasks;
    if ( ! pps_mp_task_listed( $tasks, $task_id ) ) return new WP_Error( 'unlisted', 'That task is not one of the subtasks the panel shows, so the panel will not change it.' );
    $r = pps_mp_api( 'PATCH', 'tasks/' . rawurlencode( $task_id ), array(), array( 'tasks' => array( 'state' => $state ) ) );
    if ( is_wp_error( $r ) ) return $r;
    delete_transient( PPS_MP_TASKS_CACHE );
    return true;
}

/**
 * Move one paired subtask's due date (owner 2026-10-24: "the pill on the subtask with the due date
 * should be editable and should change the task due date in missive"). Same switch and pairing rule
 * as a state change; PATCHes only `due_at`: the day chosen at one of PPS_MP_DUE_TIMES shop time (4, 5 or
 * 7 pm; 5 pm, the time subtasks are created with, when none is given). Returns true or a WP_Error.
 */
function pps_mp_set_due( $task_id, $ymd, $hm = '17:00' ) {
    if ( ! get_option( PPS_MP_ALLOW_OPTION, false ) ) return new WP_Error( 'off', 'Changing subtasks from the panel is switched off (PPS Calculators → Missive Panel).' );
    if ( ! in_array( (string) $hm, PPS_MP_DUE_TIMES, true ) ) return new WP_Error( 'time', 'Pick 4 pm, 5 pm or 7 pm.' );
    $at = pps_mp_due_unix( $ymd, $hm );
    if ( ! $at || $ymd !== gmdate( 'Y-m-d', strtotime( $ymd . ' 12:00 UTC' ) ) ) return new WP_Error( 'due', 'That is not a date.' );
    $tasks = pps_mp_tasks();
    if ( is_wp_error( $tasks ) ) return $tasks;
    if ( ! pps_mp_task_listed( $tasks, $task_id ) ) {
        // Not in the (up to 2-minute-old) list: ask Missive for that one task rather than refuse a
        // subtask the panel was showing a moment ago. Still only a subtask — one in a conversation.
        $one = pps_mp_api( 'GET', 'tasks/' . rawurlencode( $task_id ) );
        $t1  = is_wp_error( $one ) ? null : pps_mp_task_of( $one );
        if ( ! $t1 || (string) ( $t1['id'] ?? '' ) !== (string) $task_id || empty( $t1['conversation'] ) )
            return new WP_Error( 'unlisted', 'That task is not one of the subtasks the panel shows, so the panel will not change it.' );
    }
    // Owner 2026-10-25: "Ensure that the due date changes are also changing the task within missive in
    // all circumstances." So success is what MISSIVE says the due date now is, not a 200: the PATCH's
    // own answer, else a fresh read of the task; on a mismatch the change is sent once more and read
    // again, and a task still showing another date is reported, never shown as saved.
    $seen = null;
    for ( $try = 0; $try < 2; $try++ ) {
        $r = pps_mp_api( 'PATCH', 'tasks/' . rawurlencode( $task_id ), array(), array( 'tasks' => array( 'due_at' => $at ) ) );
        if ( is_wp_error( $r ) ) return $r;
        $seen = pps_mp_due_of( $r );
        if ( $seen !== $at ) {
            $g = pps_mp_api( 'GET', 'tasks/' . rawurlencode( $task_id ) );
            if ( ! is_wp_error( $g ) ) $seen = pps_mp_due_of( $g );
        }
        if ( $seen === $at ) break;
    }
    delete_transient( PPS_MP_TASKS_CACHE );
    if ( $seen !== $at ) {
        $now = $seen ? pps_mp_unix_ymd( $seen ) . ' ' . pps_mp_unix_hm( $seen ) : 'no due date';
        return new WP_Error( 'not_taken', 'Missive did not take the new due date — the task still shows ' . $now . '. Nothing was changed; try again or set it in Missive.' );
    }
    return true;
}

/** The task object in a Missive task answer (`tasks` as an object or a one-item list, or `task`). */
function pps_mp_task_of( $d ) {
    if ( ! is_array( $d ) ) return null;
    $t = $d['tasks'] ?? ( $d['task'] ?? null );
    if ( is_array( $t ) && isset( $t[0] ) && is_array( $t[0] ) ) $t = $t[0];
    return is_array( $t ) ? $t : null;
}

/** A Missive task answer's due_at as an int (0 when none), or null when the answer holds no task. */
function pps_mp_due_of( $d ) {
    $t = pps_mp_task_of( $d );
    return $t === null ? null : (int) ( $t['due_at'] ?? 0 );
}

/* ── Creating one subtask per job ─────────────────────────────
 * Owner 2026-10-18: "create the tasks - one task per line item - in missive". From the panel,
 * inside the order's own conversation (the panel tells the site which one is open, and the
 * site only accepts a conversation it has seen holding that order). Each job that has no
 * subtask of its own gets one: title "#87347 · Pt-Handbook · 500 × 8.5×11 · start Tue Oct 13 ·
 * ship Mon Oct 19", the Job Ticket as its description, due on the job's START date at 17:00
 * shop time (the team's convention). The created ids are kept in `pps_missive_item_tasks`, so
 * a job never gets a second one, and the board pairs it with that job alone. Switched on
 * separately from state changes (`pps_missive_allow_create`, off by default). */

function pps_mp_item_map() { $m = get_option( PPS_MP_ITEMS_OPTION, array() ); return is_array( $m ) ? $m : array(); }

/** The due times the panel offers (owner 2026-10-24: "just use the 4pm 5pm 7pm presets"); 17:00 is the default. */
const PPS_MP_DUE_TIMES = array( '16:00', '17:00', '19:00' );

/** A shop-day Y-m-d at a shop time (default 17:00), as a unix timestamp, or 0. */
function pps_mp_due_unix( $ymd, $hm = '17:00' ) {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) || ! preg_match( '/^\d{2}:\d{2}$/', (string) $hm ) ) return 0;
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { return ( new DateTimeImmutable( $ymd . ' ' . $hm, new DateTimeZone( $tz ) ) )->getTimestamp(); }
    catch ( \Throwable $e ) { return 0; }
}

/** The subtask a job would get: title, description, due. Pure. */
function pps_mp_subtask_for( array $j, $n, $of, $admin_url ) {
    $md = function ( $ymd ) { return $ymd !== '' ? gmdate( 'D M j', strtotime( $ymd . ' 12:00 UTC' ) ) : ''; };
    $what  = $j['job'] !== '' ? $j['job'] : $j['product'];
    $parts = array( '#' . $j['order'], $what );
    $size  = trim( ( $j['qty'] !== '' ? $j['qty'] . ' × ' : '' ) . $j['size'] );
    if ( $size !== '' && $size !== '×' ) $parts[] = rtrim( $size, ' ×' );
    if ( $j['start'] !== '' ) $parts[] = 'start ' . $md( $j['start'] );
    if ( $j['must_ship'] !== '' ) $parts[] = 'ship ' . $md( $j['must_ship'] );
    if ( $j['delivery'] !== '' ) $parts[] = 'deliver ' . $md( $j['delivery'] );
    if ( $j['rush'] ) $parts[] = 'RUSH';
    $title = implode( ' · ', $parts ) . ( $of > 1 ? ' (job ' . $n . ' of ' . $of . ')' : '' );
    $desc = array();
    foreach ( $j['ticket'] as $p ) $desc[] = ( $p[0] !== '' ? $p[0] . ': ' : '' ) . $p[1];
    if ( $admin_url !== '' ) $desc[] = 'Order: ' . $admin_url;
    return array( 'title' => mb_substr( $title, 0, 1000 ), 'description' => mb_substr( implode( "\n", $desc ), 0, 10000 ),
                  'due_at' => pps_mp_due_unix( $j['start'] !== '' ? $j['start'] : $j['must_ship'] ) );
}

/** Create the missing per-job subtasks for one order. Returns the number made, or a WP_Error. */
function pps_mp_create_subtasks( $order_id, $conv ) {
    $order_id = (int) $order_id;
    $conv = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $conv );
    if ( ! get_option( PPS_MP_CREATE_OPTION, false ) ) return new WP_Error( 'off', 'Creating subtasks from the panel is switched off (PPS Calculators → Missive Panel).' );
    if ( $order_id <= 0 || $conv === '' || (int) ( pps_mp_conv_map()[ $conv ] ?? 0 ) !== $order_id )
        return new WP_Error( 'conv', 'Open this order\'s own conversation in Missive first, so the subtasks land in it.' );
    $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
    if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) return new WP_Error( 'order', 'No such order.' );
    $lock = 'pps_mp_create_' . $order_id;
    if ( get_transient( $lock ) ) return new WP_Error( 'busy', 'Already creating this order\'s subtasks — give it a moment.' );
    set_transient( $lock, 1, 30 );
    $org = pps_mp_org();
    if ( is_wp_error( $org ) ) { delete_transient( $lock ); return $org; }
    $jobs  = pps_mp_jobs( array( $order ), pps_mp_paper_index( array( $order ) ) );
    $items = pps_mp_item_map();
    $admin = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
    $made = 0; $n = 0; $err = null;
    foreach ( $jobs as $j ) {
        $n++;
        if ( isset( $items[ $j['item'] ] ) ) continue;
        $t = pps_mp_subtask_for( $j, $n, count( $jobs ), $admin );
        $body = array( 'tasks' => array_filter( array( 'organization' => $org, 'title' => $t['title'], 'description' => $t['description'],
            'due_at' => $t['due_at'] ?: null, 'subtask' => true, 'conversation' => $conv ), function ( $v ) { return $v !== null && $v !== ''; } ) );
        $r = pps_mp_api( 'POST', 'tasks', array(), $body );
        if ( is_wp_error( $r ) ) { $err = $r; break; }
        $task = $r['tasks'] ?? array();
        if ( isset( $task[0] ) && is_array( $task[0] ) ) $task = $task[0];
        $tid = (string) ( $task['id'] ?? '' );
        if ( $tid === '' ) { $err = new WP_Error( 'noid', 'Missive did not return the new task.' ); break; }
        $items[ $j['item'] ] = $tid;
        update_option( PPS_MP_ITEMS_OPTION, $items, false );   // saved after each, so a failure part-way never duplicates
        $made++;
    }
    delete_transient( $lock );
    delete_transient( PPS_MP_TASKS_CACHE );
    if ( $err && ! $made ) return $err;
    return $err ? new WP_Error( 'partial', 'Made ' . $made . ', then: ' . $err->get_error_message() ) : $made;
}

add_action( 'rest_api_init', function () {
    $key_ok = function ( $req ) { return pps_mp_key_ok( (string) $req->get_header( 'x_pps_panel_key' ) ); };
    register_rest_route( 'pps/v1', '/missive/panel', array(
        'methods' => 'GET', 'permission_callback' => $key_ok,
        'callback' => function ( $req ) {
            $ids = array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'orders' ) ) ) );
            $res = new WP_REST_Response( pps_mp_answer( $ids, (string) $req->get_param( 'conv' ) ) );
            $res->header( 'Cache-Control', 'no-store' );
            return $res;
        },
    ) );
    register_rest_route( 'pps/v1', '/missive/create-subtasks', array(
        'methods' => 'POST', 'permission_callback' => $key_ok,
        'callback' => function ( $req ) {
            $r = pps_mp_create_subtasks( (int) $req->get_param( 'order' ), (string) $req->get_param( 'conv' ) );
            return is_wp_error( $r ) ? new WP_REST_Response( array( 'ok' => false, 'error' => $r->get_error_message() ), 200 )
                                     : new WP_REST_Response( array( 'ok' => true, 'made' => $r ), 200 );
        },
    ) );
    register_rest_route( 'pps/v1', '/missive/task-state', array(
        'methods' => 'POST', 'permission_callback' => $key_ok,
        'callback' => function ( $req ) {
            $r = $req->get_param( 'due' ) !== null
                ? pps_mp_set_due( (string) $req->get_param( 'task' ), (string) $req->get_param( 'due' ), (string) ( $req->get_param( 'time' ) ?? '17:00' ) )
                : pps_mp_set_state( (string) $req->get_param( 'task' ), (string) $req->get_param( 'state' ) );
            return is_wp_error( $r ) ? new WP_REST_Response( array( 'ok' => false, 'error' => $r->get_error_message() ), 200 )
                                     : new WP_REST_Response( array( 'ok' => true ), 200 );
        },
    ) );
} );

/* ── The page Missive loads ───────────────────────────────────── */

add_action( 'init', function () {
    if ( ! isset( $_GET['pps_missive_panel'] ) ) return;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    header_remove( 'X-Frame-Options' );
    $k = (string) wp_unslash( $_GET['k'] ?? '' );
    if ( ! pps_mp_key_ok( $k ) ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo 'This panel link is not valid. Get the current one from PPS Calculators → Missive Panel.'; exit; }
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'Referrer-Policy: no-referrer' );
    echo pps_mp_page_html( $k, rest_url( 'pps/v1/missive/panel' ), rest_url( 'pps/v1/missive/task-state' ), rest_url( 'pps/v1/missive/create-subtasks' ),
        add_query_arg( array( 'pps_missive_impose' => 1, 'k' => $k ), home_url( '/' ) ),
        add_query_arg( array( 'pps_missive_ship' => 1, 'k' => $k ), home_url( '/' ) ),
        function_exists( 'pps_an_frame_html' ) ? add_query_arg( array( 'pps_missive_analytics' => 1, 'k' => $k ), home_url( '/' ) ) : '' );
    exit;
}, 1 );

function pps_mp_page_html( $key, $api, $state_api = '', $create_api = '', $impose_url = '', $ship_url = '', $analytics_url = '' ) {
    $cfg = wp_json_encode( array( 'key' => $key, 'api' => $api, 'stateApi' => $state_api, 'createApi' => $create_api, 'impose' => $impose_url, 'ship' => $ship_url, 'analytics' => $analytics_url, 'shipTo' => function_exists( 'pps_st_view' ) ? rest_url( 'pps/v1/missive/ship-to' ) : '', 'build' => substr( md5_file( __FILE__ ), 0, 8 ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PPS Production</title>
<link href="https://integrations.missiveapp.com/missive.css" rel="stylesheet">
<style>
body{font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:10px;color:var(--missive-text-color-a,#222)}
h2{font-size:14px;margin:4px 0 6px} h3{font-size:12px;margin:12px 0 4px;text-transform:uppercase;letter-spacing:.04em;color:#777}
.card{border:1px solid rgba(128,128,128,.25);border-radius:6px;padding:8px;margin:0 0 10px}
.muted{color:#888} .small{font-size:12px} .rush{color:#c0392b;font-weight:600} .late{color:#c0392b} .ok{color:#2e7d32} .warn{color:#b45309}
.bar{position:relative;height:8px;background:rgba(128,128,128,.18);border-radius:4px;margin:6px 0 2px}
.bar i{position:absolute;top:0;bottom:0;background:#3b82f6;border-radius:4px}
.bar b{position:absolute;top:-3px;width:2px;height:14px;background:#c0392b}
table{border-collapse:collapse;width:100%} td{padding:2px 4px;vertical-align:top;border-top:1px solid rgba(128,128,128,.12);overflow-wrap:anywhere}
td:first-child{color:#888;white-space:nowrap}
a{color:#2563eb;cursor:pointer;text-decoration:none}
.pill{display:inline-block;border:1px solid;border-radius:10px;padding:0 7px;margin:2px 3px 0 0;font-size:11px;line-height:17px;white-space:nowrap}
.pill.k-paper{background:#e3effb;color:#123e6b;border-color:#a9c8ea} .pill.k-print{background:#efe7fb;color:#4a2a7a;border-color:#c9b4ea}
.pill.k-pages{background:#e2f5f1;color:#0f5348;border-color:#9fd6cb} .pill.k-qty{background:#e8f5e2;color:#24521a;border-color:#b0d8a2}
.pill.k-size{background:#fbf0dc;color:#6b4610;border-color:#e6c88f}
.subs .row:last-child{border-bottom:0} .subs b{font-weight:600}
button.pill.edit{cursor:pointer;font:inherit;font-size:11px} button.pill.edit:hover{border-color:#888} .duebox{display:inline-flex;flex-wrap:wrap;gap:3px;align-items:center} .duebox input{font-size:11px;padding:1px 2px} .pill.due.overdue{color:#b42318;border-color:#f3b4ae} .tchip{font:inherit;font-size:11px;border:1px solid #bbb;border-radius:10px;padding:0 7px;background:transparent;cursor:pointer} .tchip.on{background:#1f6feb;border-color:#1f6feb;color:#fff}
.pill.pill-flag{background:#7f1d1d;color:#fff;border-color:#5f1414;font-weight:600}
.pill.pill-plain{background:rgba(128,128,128,.12);color:inherit;border-color:rgba(128,128,128,.35)} .pill.pill-late{background:#c62828;color:#fff;border-color:#a51f1f;font-weight:600}
.tag{display:inline-block;border:1px solid rgba(128,128,128,.4);border-radius:3px;padding:0 4px;margin:1px 2px 1px 0;font-size:11px}
.st{display:inline-block;border-radius:3px;padding:0 5px;font-size:11px;font-weight:600}
.st-todo{background:rgba(128,128,128,.18)} .st-in_progress{background:#dbeafe;color:#1e40af} .st-closed{background:#dcfce7;color:#166534} .st-none{background:#fef3c7;color:#92400e}
.row{padding:6px 0;border-top:1px solid rgba(128,128,128,.15)} .row.here{background:rgba(59,130,246,.08)}
.ctl{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin:0 0 6px} .ctl select,.ctl input{font-size:12px;max-width:100%}
.tools{position:sticky;top:0;z-index:5;background:var(--missive-background-color,#fff);padding:6px 0 4px;margin:0 0 4px;border-bottom:1px solid rgba(128,128,128,.25)}
@media (prefers-color-scheme: dark){.tools{background:var(--missive-background-color,#1e1e1e)}}
.chip{font-size:11px;padding:2px 7px;border:1px solid rgba(128,128,128,.45);border-radius:10px;background:transparent;color:inherit;cursor:pointer} .chip.on{background:#2563eb;border-color:#2563eb;color:#fff}
details.oc>summary{cursor:pointer;font-weight:600;margin:0 0 6px} .ver{margin:14px 0 0;font-size:10px;color:#aaa}
.grp{margin:10px 0 2px;font-weight:600;font-size:12px} button.b{font-size:11px;padding:1px 6px;margin:2px 4px 0 0;cursor:pointer}
.preview{opacity:.65;font-size:12px}
.impbar{display:flex;gap:6px;align-items:center;margin:0 0 6px} iframe.imp{width:100%;height:calc(100vh - 52px);border:1px solid rgba(128,128,128,.25);border-radius:6px;background:#0f172a}
</style></head>
<body><div id="app" class="muted">Loading…</div>
<script data-cfasync="false" src="https://integrations.missiveapp.com/missive.js"></script>
<script data-cfasync="false">
(function(){
'use strict';
var CFG = {$cfg};
var app = document.getElementById('app');
var inMissive = typeof window.Missive !== 'undefined' && window.parent !== window;
var LAST = { ids: [], conv: '' }, DATA = null, IMPOSE = 0, FRAME = 'impose';
var PREF = { sort: 'ship', group: 'none', q: '', hide: true, st: 'all', when: 'all', paper: '', addon: '', rush: false, lateStart: false, subOnly: true, mOnly: true };
try { var sp = JSON.parse(localStorage.getItem('ppsMpPref')||'null'); if (sp) for (var k in sp) PREF[k]=sp[k]; } catch(e){}
function savePref(){ try { localStorage.setItem('ppsMpPref', JSON.stringify(PREF)); } catch(e){} }

/* Missive is only read here (on, fetchConversations, search, openURL). A subtask's state is
   changed by the SITE, through its own API token, and only when the owner has allowed it. */

function el(tag, attrs, kids){ var e=document.createElement(tag); attrs=attrs||{};
  for (var k in attrs){ if(k==='text') e.textContent=attrs[k]; else if(k==='cls') e.className=attrs[k]; else if(k==='on') e.addEventListener('click',attrs[k]);
    else if(k==='change') e.addEventListener('change',attrs[k]); else if(k==='input') e.addEventListener('input',attrs[k]); else e.setAttribute(k,attrs[k]); }
  (kids||[]).forEach(function(c){ if(c) e.appendChild(typeof c==='string'?document.createTextNode(c):c); }); return e; }
function md(ymd){ if(!ymd) return '—'; var d=new Date(ymd+'T12:00:00Z'); return d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',timeZone:'UTC'}); }
function days(a,b){ return Math.round((Date.parse(b+'T12:00:00Z')-Date.parse(a+'T12:00:00Z'))/864e5); }
function open(url){ if(inMissive && Missive.openURL) Missive.openURL(url); else window.open(url,'_blank','noopener'); }
function find(order){ if(inMissive && Missive.search) Missive.search(String(order)); }
/* The site's imposition tool for one order, shown in the panel; "Open wide" opens the same
   page in the browser. It files to that order's Drive folder only (pps-missive-impose.php). */
function imposeUrl(order){ return CFG.impose ? CFG.impose + (CFG.impose.indexOf('?')<0?'?':'&') + 'order=' + encodeURIComponent(order) : ''; }
function impose(order){ FRAME = 'impose'; IMPOSE = order; render(); window.scrollTo(0,0); }
/* Shipping for one order: rates from every Shippo account, and the label (pps-missive-ship.php).
   It shows in the same frame; its label and tracking links come back here to open through Missive. */
function shipUrl(order){ return CFG.ship ? CFG.ship + (CFG.ship.indexOf('?')<0?'?':'&') + 'order=' + encodeURIComponent(order) : ''; }
function ship(order){ FRAME = 'ship'; IMPOSE = order; render(); window.scrollTo(0,0); }
/* Ship-to (pps-shipto.php): change where the order goes. The site brings every copy in step —
   Job Tickets, the Ship page, the panel's subtasks, a note in the order's conversations — and
   says whether Shippo's copy has caught up. */
function shipToCard(o){
  var st = o.ship_to, box = el('div',{cls:'card small'}), msg = el('div',{cls:'small'});
  var form = el('div',{style:'display:none;margin-top:6px'});
  box.appendChild(el('div',{style:'display:flex;justify-content:space-between;align-items:flex-start;gap:6px'},[
    el('div',{},[ el('b',{text:'Ship to'}), ' · ', st.line ? st.line : el('span',{cls:'muted',text:'no shipping address on the order'}) ]),
    st.can_edit ? el('button',{cls:'b',text:'Change',on:function(){ form.style.display = form.style.display==='none' ? '' : 'none'; }}) : null ]));
  if(st.last){
    box.appendChild(el('div',{cls:'muted small',text:'Changed '+st.last.at+' by '+st.last.who+(st.last.from?' — was '+st.last.from:'')}));
    box.appendChild(el('div',{cls:'small '+(st.last.shippo==='stale'||st.last.shippo==='unknown'?'late':(st.last.shippo==='match'?'ok':'muted')),text:st.last.shippo_words}));
    if(st.last.shippo==='stale'||st.last.shippo==='unknown') box.appendChild(el('a',{cls:'small',text:'Check Shippo again',on:function(){ checkShippo(o.order, msg); }}));
  }
  var a = st.addr || {}, f = {};
  [['name','Name'],['company','Company'],['street1','Street'],['street2','Apt, suite'],['city','City'],['state','State (AZ)'],['zip','ZIP'],['phone','Phone']].forEach(function(x){
    f[x[0]] = el('input',{placeholder:x[1],value:a[x[0]]||'',title:x[1],style:'width:'+(x[0]==='state'?'60px':(x[0]==='zip'?'90px':'100%'))+';margin:2px 0;box-sizing:border-box'});
    form.appendChild(f[x[0]]);
  });
  var who = el('input',{placeholder:'Your name (for the note)',value:PREF.who||'',style:'width:100%;margin:2px 0;box-sizing:border-box'});
  form.appendChild(who);
  var save = el('button',{cls:'b',text:'Save the new ship-to'});
  save.addEventListener('click',function(){
    var addr = {}; for(var k in f) addr[k] = f[k].value;
    if(!confirm('Change the ship-to for order #'+o.order+' to:\\n'+[addr.name,addr.company,addr.street1,addr.street2,addr.city+', '+addr.state+' '+addr.zip].filter(Boolean).join(', ')+'?\\n\\nThe Job Tickets, the Ship page and the order\'s subtasks follow, and a note is posted in its conversations. The delivery date is not re-quoted.')) return;
    PREF.who = who.value; savePref();
    save.disabled = true; save.textContent = 'Saving…';
    fetch(CFG.shipTo,{method:'POST',headers:{'X-PPS-Panel-Key':CFG.key,'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify({order:o.order,address:addr,who:who.value})})
      .then(function(r){ return r.json(); })
      .then(function(d){
        save.disabled = false; save.textContent = 'Save the new ship-to';
        if(!d.ok){ msg.className='late small'; msg.textContent = d.error || 'Could not save.'; return; }
        var m = d.result && d.result.missive || {};
        alertDone('Saved. Updated on '+((d.result&&d.result.items)||0)+' job(s)'+(m.tasks?', '+m.tasks+' subtask(s)':'')+(m.posts?', note posted in '+m.posts+' conversation(s)':'')+'. '+((d.view&&d.view.last&&d.view.last.shippo_words)||''));
        load(LAST.ids, LAST.conv);
      })
      .catch(function(){ save.disabled = false; save.textContent = 'Save the new ship-to'; msg.className='late small'; msg.textContent = 'Could not reach the site — nothing was changed.'; });
  });
  form.appendChild(save);
  box.appendChild(form); box.appendChild(msg);
  return box;
}
function alertDone(t){ var n = el('div',{cls:'card small ok',text:t}); document.body.insertBefore(n, document.body.firstChild); setTimeout(function(){ if(n.parentNode) n.parentNode.removeChild(n); }, 12000); }
function checkShippo(order, msg){
  msg.className='muted small'; msg.textContent='Checking Shippo…';
  fetch(CFG.shipTo,{method:'POST',headers:{'X-PPS-Panel-Key':CFG.key,'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify({order:order,check:1})})
    .then(function(r){ return r.json(); })
    .then(function(d){ msg.className = 'small '+(d.shippo==='match'?'ok':'late'); msg.textContent = d.ok ? d.words : (d.error||'Could not check.'); if(d.shippo==='match') load(LAST.ids, LAST.conv); })
    .catch(function(){ msg.className='late small'; msg.textContent='Could not reach the site.'; });
}
// Production Analytics (pps-analytics.php) in the same frame, opened with the panel key.
var AN = false;
function analytics(){ AN = true; render(); window.scrollTo(0,0); }
// Job specs as pills, one fixed colour per KIND (owner 2026-10-22): paper, print, pages, qty and
// size each always look the same, so the eye reads a row by colour. RED with white text
// DARK red with white text (pill-flag) is kept for what is unusual — a paper not in stock, an
// add-on, rush (owner 2026-10-22); a late start keeps the brighter red (pill-late).
function pill(text, kind, title){ return el('span',{cls:'pill '+kind,text:text,title:title||''}); }
// One job's pills — a board row or a job of the open order (whose addons are one '; ' string).
function specPills(r){
  var specs = el('div',{cls:'specs'});
  (r.papers||[]).forEach(function(p){ var t = p.label+' · '+String(p.sheet).replace('x','×')+' · '+p.sheets+(p.role && p.role!=='stock' ? ' ('+p.role+')' : '');
    specs.appendChild(p.in_stock ? pill(t, 'k-paper', 'Paper: '+p.sheets+' press sheets of '+p.label+' on '+p.sheet)
      : pill(t+' · NOT STOCKED', 'pill-flag', 'Paper not inventoried — '+p.sheets+' press sheets of '+p.label+' on '+p.sheet+' to order')); });
  if(!(r.papers||[]).length && r.source==='custom') specs.appendChild(el('span',{cls:'pill pill-plain',text:'custom order'}));
  if(r.print) specs.appendChild(pill(r.print, 'k-print', 'Print'));
  if(r.pages) specs.appendChild(pill(r.pages, 'k-pages', 'Pages per book'));
  if(r.qty) specs.appendChild(pill(Number(r.qty).toLocaleString()+' qty', 'k-qty', 'Quantity'));
  if(r.size) specs.appendChild(pill(r.size, 'k-size', 'Finished size'));
  var adds = typeof r.addons === 'string' ? r.addons.split(/;\s*/).filter(function(a){ return a.trim(); }) : (r.addons && r.addons.length ? r.addons : (r.finishing||[]));
  adds.forEach(function(a){ specs.appendChild(pill(a.trim(), 'pill-flag', 'Add-on')); });
  if(r.rush) specs.appendChild(pill('RUSH', 'pill-flag', 'Rush shipping'));
  if(r.start) specs.appendChild(el('span',{cls:'pill pill-plain',text:'start '+md(r.start)}));
  if(lateStart(r)) specs.appendChild(el('span',{cls:'pill pill-late',text:'LATE START'}));
  return specs;
}
// The open conversation's subtasks, first thing in its order card (owner 2026-10-22): each job's
// subtask state with the same colour-coded pills as the board.
function subtaskStrip(o){
  var box = el('div',{cls:'card subs'});
  box.appendChild(el('div',{cls:'small muted',text:'Subtasks in this conversation'}));
  o.jobs.forEach(function(j){
    var r = el('div',{cls:'row'});
    r.appendChild(el('div',{},[ el('b',{text:j.task && j.own_task ? j.task.title : (j.job||j.product)}), j.printed?el('span',{cls:'muted',text:' · printed'}):null ]));
    var tl = taskLine(j.own_task ? j.task : null, j.start, j.own_task && j.task && j.task.due && j.start && j.task.due!==j.start); if(tl) r.appendChild(tl);
    r.appendChild(specPills(j));
    box.appendChild(r);
  });
  if(o.order_task){ var ot = el('div',{cls:'row'});
    ot.appendChild(el('div',{},[ el('span',{cls:'muted small',text:'Order subtask · '}), el('b',{text:o.order_task.title}) ]));
    var otl = taskLine(o.order_task, '', false); if(otl) ot.appendChild(otl);
    box.appendChild(ot); }
  return box;
}
window.addEventListener('message', function(e){ if(e.origin===location.origin && e.data && typeof e.data.ppsOpen==='string' && /^https:\/\//.test(e.data.ppsOpen)) open(e.data.ppsOpen); });

/* Order numbers in what the conversation says: "#87347", "Order 87347", "order #87347". */
function ppsOrderNumbers(text){
  var seen={}, out=[], re=/(?:order\s*#?\s*|#\s?)(\d{5,6})\b/gi, m;
  while((m=re.exec(String(text||'')))){ if(!seen[m[1]]){ seen[m[1]]=1; out.push(m[1]); } }
  return out.slice(0,5);
}
window.ppsOrderNumbers = ppsOrderNumbers;

function load(ids, conv){
  LAST = { ids: ids, conv: conv||'' };
  if(!DATA){ app.className='muted'; app.textContent='Loading…'; }
  var url = CFG.api + (CFG.api.indexOf('?')<0?'?':'&') + 'orders=' + encodeURIComponent(ids.join(',')) + '&conv=' + encodeURIComponent(conv||'');
  fetch(url,{headers:{'X-PPS-Panel-Key':CFG.key},credentials:'omit',cache:'no-store'})
    .then(function(r){ if(!r.ok) throw new Error('The site answered '+r.status+'.'); return r.json(); })
    .then(function(d){ DATA=d; if(!IMPOSE && !AN) render(); }) // never rebuild a frame (tool, shipping, analytics) mid-use; Back shows the fresh data
    .catch(function(e){ app.className=''; app.textContent=''; app.appendChild(el('div',{cls:'late',text:'Could not load: '+e.message})); });
}

// The due date AND time as a pill ("due Mon, Oct 26 at 5pm"). With state changes allowed the pill itself
// is the change control (owner 2026-10-24): clicking it opens the date and the 4pm / 5pm / 7pm presets
// in its place; Save moves the subtask's due date in Missive, Cancel puts the pill back.
var DUE_TIMES = [['16:00','4pm'],['17:00','5pm'],['19:00','7pm']];
function hm12(t){ if(!t) return ''; var h=+t.slice(0,2), m=t.slice(3,5); return (h%12||12)+(m!=='00'?':'+m:'')+(h<12?'am':'pm'); }
function dueLabel(t){ return 'due '+(t.due ? md(t.due)+(t.due_time ? ' at '+hm12(t.due_time) : '') : '—'); }
function duePill(t){
  var overdue = t.due && t.due < DATA.today && t.state !== 'closed';
  if(!DATA.missive.can_change) return el('span',{cls:'pill pill-plain due'+(overdue?' overdue':''),text:dueLabel(t)});
  var p = el('button',{cls:'pill pill-plain due edit'+(overdue?' overdue':''),text:dueLabel(t),title:'Click to change this subtask\'s due date and time in Missive'});
  p.addEventListener('click',function(){
    var f = el('span',{cls:'duebox'}), inp = el('input',{type:'date'}); inp.value = t.due || DATA.today;
    var pick = DUE_TIMES.some(function(x){ return x[0]===t.due_time; }) ? t.due_time : '17:00', chips = [];
    DUE_TIMES.forEach(function(x){ var c = el('button',{cls:'tchip'+(x[0]===pick?' on':''),text:x[1]});
      c.addEventListener('click',function(){ pick = x[0]; chips.forEach(function(k){ k.classList.toggle('on', k===c); }); }); chips.push(c); });
    var save = el('button',{cls:'b',text:'Save'}), cancel = el('button',{cls:'b',text:'Cancel'});
    save.addEventListener('click',function(){ if(!inp.value || (inp.value===t.due && pick===t.due_time)){ f.replaceWith(p); return; } setDue(t, inp.value, pick, save); });
    cancel.addEventListener('click',function(){ f.replaceWith(p); });
    f.appendChild(inp); chips.forEach(function(c){ f.appendChild(c); }); f.appendChild(save); f.appendChild(cancel); p.replaceWith(f); inp.focus();
  });
  return p;
}
function setDue(task, ymd, time, btn){
  btn.disabled = true; btn.textContent = '…';
  fetch(CFG.stateApi,{method:'POST',headers:{'X-PPS-Panel-Key':CFG.key,'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify({task:task.id,due:ymd,time:time})})
    .then(function(r){ return r.json(); })
    .then(function(d){ if(!d.ok){ alert(d.error||'Could not change the due date.'); } load(LAST.ids, LAST.conv); })
    .catch(function(){ alert('Could not reach the site.'); load(LAST.ids, LAST.conv); });
}

function setState(task, state, btn){
  btn.disabled = true; btn.textContent = '…';
  fetch(CFG.stateApi,{method:'POST',headers:{'X-PPS-Panel-Key':CFG.key,'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify({task:task.id,state:state})})
    .then(function(r){ return r.json(); })
    .then(function(d){ if(!d.ok){ alert(d.error||'Could not change the task.'); } load(LAST.ids, LAST.conv); })
    .catch(function(){ alert('Could not reach the site.'); load(LAST.ids, LAST.conv); });
}

function createSubtasks(o, btn){
  if(!confirm('Create '+o.needs+' Missive subtask'+(o.needs===1?'':'s')+' in this conversation — one per job, due on each job\'s start date?')) return;
  btn.disabled = true; btn.textContent = 'Creating…';
  fetch(CFG.createApi,{method:'POST',headers:{'X-PPS-Panel-Key':CFG.key,'Content-Type':'application/json'},credentials:'omit',body:JSON.stringify({order:o.order,conv:LAST.conv})})
    .then(function(r){ return r.json(); })
    .then(function(d){ if(!d.ok) alert(d.error||'Could not create the subtasks.'); load(LAST.ids, LAST.conv); })
    .catch(function(){ alert('Could not reach the site.'); load(LAST.ids, LAST.conv); });
}

var STATE_WORD = { todo:'To do', in_progress:'In progress', closed:'Done' };
function taskLine(t, mustShip, mismatch){
  var box = el('div',{cls:'small'});
  if(!DATA.missive.connected){ return null; }
  if(!t){ box.appendChild(el('span',{cls:'st st-none',text:'No subtask'})); return box; }
  box.appendChild(el('span',{cls:'st st-'+t.state,text:STATE_WORD[t.state]||t.state}));
  box.appendChild(document.createTextNode(' '));
  box.appendChild(duePill(t));
  if(t.started) box.appendChild(el('span',{cls:'muted',text:' · started '+md(t.started)}));
  if(mismatch) box.appendChild(el('span',{cls:'warn',text:' · ≠ start '+md(mustShip)}));
  if(DATA.missive.can_change){
    var acts = t.state==='todo' ? [['in_progress','Start'],['closed','Done']] : t.state==='in_progress' ? [['closed','Done'],['todo','Back to to-do']] : [['todo','Reopen']];
    acts.forEach(function(a){ var b=el('button',{cls:'b',text:a[1]}); b.addEventListener('click',function(){ setState(t,a[0],b); }); box.appendChild(el('br')); box.lastChild.remove(); box.appendChild(document.createTextNode(' ')); box.appendChild(b); });
  }
  return box;
}

function windowBar(j, today){
  var f=j.window[0], t=j.window[1]; if(!t) return el('div',{cls:'muted',text:'No ship date on this job.'});
  var lo = f<today?f:today, hi = t>today?t:today, span=Math.max(1,days(lo,hi));
  var bar=el('div',{cls:'bar'},[ el('i',{style:'left:'+(100*days(lo,f)/span)+'%;width:'+Math.max(2,100*days(f,t)/span)+'%'}),
    el('b',{style:'left:'+(100*days(lo,today)/span)+'%'}) ]);
  var left = days(today,t), state = left<0 ? el('span',{cls:'late',text:'LATE by '+(-left)+' day'+(left===-1?'':'s')}) :
    el('span',{cls:left<=1?'rush':'ok',text:left===0?'ships today':left+' day'+(left===1?'':'s')+' to ship'});
  return el('div',{},[ el('div',{},['Start ',el('b',{text:md(f)}),' → ship by ',el('b',{text:md(t)}),' · delivery ',md(j.delivery),' · ',state]), bar ]);
}

function jobCard(j, today){
  var c=el('div',{cls:'card'});
  c.appendChild(el('h2',{},[ (j.job||j.product), j.rush?el('span',{cls:'rush',text:' RUSH'}):null, j.printed?el('span',{cls:'muted',text:' · marked printed'}):null ]));
  c.appendChild(el('div',{cls:'muted',text:[j.qty?j.qty+' qty':'',j.size].filter(Boolean).join(' · ')}));
  c.appendChild(windowBar(j, today));
  var tl = taskLine(j.task, j.start, j.task && j.task.due && j.start && j.task.due!==j.start); if(tl) c.appendChild(tl);
  if(j.papers.length){ c.appendChild(el('h3',{text:'Paper'}));
    j.papers.forEach(function(p){ c.appendChild(el('div',{},[p.label+' · '+p.sheet+' · '+p.sheets+' sheets'+(p.role!=='stock'?' ('+p.role+')':''), p.in_stock?null:el('span',{cls:'rush',text:' to order'})])); }); }
  c.appendChild(el('h3',{text:'Run together'}));
  if(!j.batch.mates.length){ c.appendChild(el('div',{cls:'muted',text:'No other open job on the same paper and sheet in this window.'})); }
  else {
    j.batch.combined.forEach(function(x){ c.appendChild(el('div',{},[el('b',{text:x.sheets+' sheets'}),' of '+x.label+' '+x.sheet+' across '+(x.jobs+1)+' jobs'])); });
    j.batch.mates.forEach(function(m){ c.appendChild(el('div',{cls:'row'},[ el('a',{text:'#'+m.order,on:function(){find(m.order);}}), ' '+m.customer+' — '+m.what+' · ship '+md(m.must_ship), m.rush?el('span',{cls:'rush',text:' RUSH'}):null ])); });
  }
  if(j.addons){ c.appendChild(el('h3',{text:'Finishing'})); c.appendChild(el('div',{text:j.addons})); }
  if(j.ticket.length){ var det=el('details'); det.appendChild(el('summary',{cls:'small muted',text:'Job Ticket'})); var t=el('table');
    j.ticket.forEach(function(p){ t.appendChild(el('tr',{},[el('td',{text:p[0]}),el('td',{text:p[1]})])); }); det.appendChild(t); c.appendChild(det); }
  return c;
}

/* ── the board ── */
var SORTS = {
  ship:     ['Must-ship date', function(a,b){ return cmp(a.must_ship||'9999',b.must_ship||'9999') || a.order-b.order; }],
  start:    ['Start date',     function(a,b){ return cmp(a.start||'9999',b.start||'9999') || cmp(a.must_ship,b.must_ship); }],
  delivery: ['Delivery date',  function(a,b){ return cmp(a.delivery||'9999',b.delivery||'9999') || cmp(a.must_ship,b.must_ship); }],
  paper:    ['Paper',          function(a,b){ return cmp((a.paper||'~').toLowerCase(),(b.paper||'~').toLowerCase()) || cmp(a.sheet,b.sheet) || cmp(a.must_ship,b.must_ship); }],
  sheet:    ['Press sheet',    function(a,b){ return cmp(a.sheet||'~',b.sheet||'~') || cmp(a.paper,b.paper) || cmp(a.must_ship,b.must_ship); }],
  sheets:   ['Sheets (most first)', function(a,b){ return b.sheets-a.sheets || cmp(a.must_ship,b.must_ship); }],
  qty:      ['Quantity (most first)', function(a,b){ return (parseInt(b.qty,10)||0)-(parseInt(a.qty,10)||0); }],
  task:     ['Task state',     function(a,b){ return tr(a)-tr(b) || cmp(a.must_ship||'9999',b.must_ship||'9999'); }],
  due:      ['Task due date',  function(a,b){ return cmp((a.task&&a.task.due)||'9999',(b.task&&b.task.due)||'9999'); }],
  mismatch: ['Date mismatches first', function(a,b){ return (b.due_mismatch?1:0)-(a.due_mismatch?1:0) || cmp(a.must_ship||'9999',b.must_ship||'9999'); }],
  order:    ['Order number',   function(a,b){ return a.order-b.order; }],
  customer: ['Customer',       function(a,b){ return cmp(a.customer.toLowerCase(),b.customer.toLowerCase()); }]
};
var GROUPS = {
  none:      ['No grouping', function(){ return ''; }],
  paper:     ['Paper + sheet', function(r){ return r.paper ? r.paper+' · '+r.sheet : 'Paper not known'; }],
  sheet:     ['Press sheet', function(r){ return r.sheet || 'Sheet not known'; }],
  task:      ['Task state', function(r){ return r.task ? (STATE_WORD[r.task.state]||r.task.state) : 'No subtask'; }],
  finishing: ['Finishing', function(r){ return r.finishing.length ? r.finishing.join(' + ') : 'No finishing'; }],
  ship:      ['Ship date', function(r){ return r.must_ship ? md(r.must_ship) : 'No date'; }],
  rush:      ['Rush', function(r){ return r.rush ? 'RUSH' : 'Standard'; }]
};
function cmp(a,b){ a=a||''; b=b||''; return a<b?-1:a>b?1:0; }
function tr(r){ return r.task ? ({in_progress:0,todo:1,closed:3})[r.task.state] : 2; }

function boardRow(r, here){
  var late = r.must_ship && r.must_ship < DATA.today;
  if(r.missive_only){
    var m = el('div',{cls:'row'});
    // The due date is the subtask's own pill on the line below (clickable, with its time), so the
    // header no longer repeats it as plain text; it only says so when the task has no due date.
    m.appendChild(el('div',{},[ r.must_ship?null:el('span',{cls:'muted',text:'no due date '}),
      r.order?el('a',{text:'#'+r.order,on:function(){find(r.order);}}):null,
      r.order&&r.order_status&&CFG.ship?el('a',{cls:'small',text:' [Ship]',title:'Shipping rates and labels for this order',on:function(){ ship(r.order); }}):null,
      ' '+(r.customer?r.customer+' — ':'')+r.what ]));
    m.appendChild(el('div',{cls:'specs'},[ el('span',{cls:'pill pill-plain',text:'Missive task only'}), el('span',{cls:'muted small',text:' '+r.reason}),
      el('a',{cls:'small',text:' [Find in Missive]',title:'Search Missive for this task',on:function(){ if(inMissive && Missive.search) Missive.search(r.order?String(r.order):r.what); }}) ]));
    var tl0 = taskLine(r.task, r.start, false); if(tl0) m.appendChild(tl0);
    return m;
  }
  var d = el('div',{cls:'row'+(here?' here':'')});
  d.appendChild(el('div',{},[ el('span',{cls:late?'late':'',text:md(r.must_ship)}), ' ', el('a',{text:'#'+r.order,on:function(){find(r.order);}}),
    r.drive?el('a',{cls:'small',text:' [Drive]',title:'Open the order\'s Google Drive folder',on:function(){ open(r.drive); }}):null,
    r.drive&&CFG.impose?el('a',{cls:'small',text:' [Impose]',title:'Open the imposition tool for this order',on:function(){ impose(r.order); }}):null,
    CFG.ship?el('a',{cls:'small',text:' [Ship]',title:'Shipping rates and labels for this order',on:function(){ ship(r.order); }}):null,
    ' '+r.customer+' — '+r.what, r.printed?el('span',{cls:'muted',text:' · printed'}):null ]));
  d.appendChild(specPills(r));
  var tl = taskLine(r.task, r.start, r.due_mismatch); if(tl) d.appendChild(tl);
  return d;
}

function whenOk(r){
  var t = r.must_ship, today = DATA.today; if(PREF.when==='all') return true; if(!t) return false;
  var d = days(today, t);
  if(PREF.when==='late') return d < 0;
  if(PREF.when==='today') return d === 0;
  if(PREF.when==='week') return d >= 0 && d <= 7;
  if(PREF.when==='two') return d >= 0 && d <= 14;
  return true;
}
function stateOf(r){ return r.task ? r.task.state : 'none'; }
// Late start: the start date has passed and work has not begun — not marked printed, and the
// subtask (if there is one) still to do. In progress or done is not late, whatever the date.
function lateStart(r){ return !!(r.start && r.start < DATA.today && !r.printed && (!r.task || r.task.state==='todo')); }
function addonOk(r){
  var a = PREF.addon || '', fin = r.finishing || [];
  if(a==='') return true;
  if(a==='*') return fin.length > 0;
  if(a==='-') return fin.length === 0;
  if(a.indexOf('k:')===0) return fin.indexOf(a.slice(2)) >= 0;
  if(a.indexOf('v:')===0) return (r.addons||[]).indexOf(a.slice(2)) >= 0;
  return true;
}

function renderBoard(here){
  var wrap = el('div');
  var tools = el('div',{cls:'tools'});
  tools.appendChild(el('div',{style:'display:flex;justify-content:space-between;align-items:center;gap:6px'},[ el('h2',{text:'Open jobs',style:'margin:0 0 4px'}),
    CFG.analytics ? el('button',{cls:'b',text:'📊 Analytics',title:'What the shop is getting done and how fast: sheets, cost, on-time rate',on:function(){ analytics(); }}) : null ]));
  // status filter chips, with counts
  var counts = { all: 0, todo: 0, in_progress: 0, closed: 0, none: 0 };
  DATA.board.forEach(function(r){ counts.all++; counts[stateOf(r)]++; });
  var chips = el('div',{cls:'ctl'});
  [['all','All'],['todo','To do'],['in_progress','In progress'],['closed','Done'],['none','No subtask']].forEach(function(c){
    if(c[0]!=='all' && !DATA.missive.connected) return;
    var b = el('button',{cls:'chip'+(PREF.st===c[0]?' on':''),text:c[1]+' '+counts[c[0]]});
    b.addEventListener('click',function(){ PREF.st=c[0]; savePref(); render(); });
    chips.appendChild(b);
  });
  tools.appendChild(chips);
  var sortSel = el('select',{change:function(){ PREF.sort=sortSel.value; savePref(); render(); }});
  Object.keys(SORTS).forEach(function(k){ var o=el('option',{value:k,text:'Sort: '+SORTS[k][0]}); if(k===PREF.sort) o.selected=true; sortSel.appendChild(o); });
  var grpSel = el('select',{change:function(){ PREF.group=grpSel.value; savePref(); render(); }});
  Object.keys(GROUPS).forEach(function(k){ var o=el('option',{value:k,text:'Group: '+GROUPS[k][0]}); if(k===PREF.group) o.selected=true; grpSel.appendChild(o); });
  tools.appendChild(el('div',{cls:'ctl'},[sortSel, grpSel]));
  var whenSel = el('select',{change:function(){ PREF.when=whenSel.value; savePref(); render(); }});
  [['all','Any ship date'],['late','Late'],['today','Ships today'],['week','Ships within 7 days'],['two','Ships within 14 days']].forEach(function(w){ var o=el('option',{value:w[0],text:w[1]}); if(w[0]===PREF.when) o.selected=true; whenSel.appendChild(o); });
  var papers = {}; DATA.board.forEach(function(r){ r.papers.forEach(function(p){ papers[p.label+' · '+p.sheet]=1; }); });
  var paperSel = el('select',{change:function(){ PREF.paper=paperSel.value; savePref(); render(); }});
  paperSel.appendChild(el('option',{value:'',text:'Any paper'}));
  Object.keys(papers).sort().forEach(function(k){ var o=el('option',{value:k,text:k}); if(k===PREF.paper) o.selected=true; paperSel.appendChild(o); });
  // Add-ons: any / none, each kind ("Coating" — every coating), and each exact choice
  // ("Coating: UV Gloss (both sides)"). Values: '*' any, '-' none, 'k:<kind>', 'v:<choice>'.
  var kinds = {}, choices = {};
  DATA.board.forEach(function(r){ (r.finishing||[]).forEach(function(k){ kinds[k]=1; }); (r.addons||[]).forEach(function(a){ choices[a]=1; }); });
  var addSel = el('select',{change:function(){ PREF.addon=addSel.value; savePref(); render(); }});
  [['','Any add-ons'],['*','With add-ons'],['-','No add-ons']].forEach(function(a){ var o=el('option',{value:a[0],text:a[1]}); if(a[0]===(PREF.addon||'')) o.selected=true; addSel.appendChild(o); });
  Object.keys(kinds).sort().forEach(function(k){
    var og = document.createElement('optgroup'); og.label = k;
    var all = el('option',{value:'k:'+k,text:'Any '+k}); if('k:'+k===PREF.addon) all.selected=true; og.appendChild(all);
    Object.keys(choices).sort().forEach(function(a){
      if(a===k || a.indexOf(k+':')!==0) return;
      var o = el('option',{value:'v:'+a,text:a.slice(k.length+1).trim()}); if('v:'+a===PREF.addon) o.selected=true; og.appendChild(o);
    });
    addSel.appendChild(og);
  });
  tools.appendChild(el('div',{cls:'ctl'},[whenSel, paperSel, addSel]));
  var q = el('input',{type:'search',placeholder:'Search (name, order, UV…)'}); q.value=PREF.q;
  q.addEventListener('input',function(){ PREF.q=q.value; savePref(); clearTimeout(window.__ppsT); window.__ppsT=setTimeout(function(){ render(); var n=document.querySelector('.tools input[type=search]'); if(n){ n.focus(); n.setSelectionRange(n.value.length,n.value.length); } },300); });
  var ls = el('input',{type:'checkbox'}); ls.checked=!!PREF.lateStart; ls.addEventListener('change',function(){ PREF.lateStart=ls.checked; savePref(); render(); });
  var lsN = DATA.board.filter(lateStart).length;
  var rush = el('input',{type:'checkbox'}); rush.checked=PREF.rush; rush.addEventListener('change',function(){ PREF.rush=rush.checked; savePref(); render(); });
  var hide = el('input',{type:'checkbox'}); hide.checked=PREF.hide; hide.addEventListener('change',function(){ PREF.hide=hide.checked; savePref(); render(); });
  var mo = el('input',{type:'checkbox'}); mo.checked=PREF.mOnly!==false; mo.addEventListener('change',function(){ PREF.mOnly=mo.checked; savePref(); render(); });
  var moN = DATA.board.filter(function(r){ return r.missive_only; }).length;
  var sub = el('input',{type:'checkbox'}); sub.checked=PREF.subOnly!==false; sub.addEventListener('change',function(){ PREF.subOnly=sub.checked; savePref(); render(); });
  var reset = el('a',{cls:'small',text:'reset',on:function(){ PREF={ sort:'ship', group:'none', q:'', hide:true, st:'all', when:'all', paper:'', addon:'', rush:false, lateStart:false, subOnly:true, mOnly:true }; savePref(); render(); }});
  tools.appendChild(el('div',{cls:'ctl'},[q]));
  tools.appendChild(el('div',{cls:'ctl'},[DATA.missive.connected ? el('label',{cls:'small'},[sub,' subtasks only']) : null, moN ? el('label',{cls:'small',title:'Open Missive subtasks that are not on an open job: the order is completed or not on the site, or the conversation names no order'},[mo,' Missive-only tasks ('+moN+')']) : null, el('label',{cls:'small'},[rush,' rush only']), el('label',{cls:'small'+(lsN?' late':'')},[ls,' late start ('+lsN+')']), el('label',{cls:'small'},[hide,' hide printed / done']), reset]));
  var needle = PREF.q.trim().toLowerCase();
  var rows = DATA.board.filter(function(r){
    var st = stateOf(r);
    if(PREF.st!=='all' && st!==PREF.st) return false;
    // "subtasks only" (on by default): jobs with no Missive subtask are left out, unless the
    // "No subtask" chip is chosen, which asks for exactly those.
    if(DATA.missive.connected && PREF.subOnly!==false && PREF.st!=='none' && !r.task) return false;
    if(r.missive_only && PREF.mOnly===false) return false;
    if(PREF.hide && PREF.st!=='closed' && (r.printed || st==='closed')) return false;
    if(PREF.rush && !r.rush) return false;
    if(PREF.lateStart && !lateStart(r)) return false;
    if(!whenOk(r)) return false;
    if(PREF.paper && !r.papers.some(function(p){ return p.label+' · '+p.sheet===PREF.paper; })) return false;
    if(!addonOk(r)) return false;
    if(!needle) return true;
    var hay = [r.order, r.customer, r.what, r.paper, r.sheet, r.size, r.finishing.join(' '), r.rush?'rush':'', r.task?STATE_WORD[r.task.state]:'no subtask'].concat(r.papers.map(function(p){return p.label;})).join(' ').toLowerCase();
    return hay.indexOf(needle) >= 0;
  });
  rows.sort(SORTS[PREF.sort] ? SORTS[PREF.sort][1] : SORTS.ship[1]);
  var tot = rows.reduce(function(s,r){ return s+r.sheets; },0);
  // Say which filters are narrowing the board — they are remembered in this browser, so a
  // "Ships within 7 days" chosen last week still applies today.
  var on = [];
  if(PREF.when!=='all') on.push(({late:'late',today:'ships today',week:'ships within 7 days',two:'ships within 14 days'})[PREF.when]||PREF.when);
  if(PREF.st!=='all') on.push(({todo:'to do',in_progress:'in progress',closed:'done',none:'no subtask'})[PREF.st]||PREF.st);
  if(DATA.missive.connected && PREF.subOnly!==false && PREF.st!=='none') on.push('subtasks only');
  if(PREF.hide) on.push('hiding printed / done');
  if(PREF.paper) on.push('paper '+PREF.paper);
  if(PREF.addon) on.push('add-ons');
  if(PREF.rush) on.push('rush only');
  if(PREF.lateStart) on.push('late start');
  if(PREF.q.trim()) on.push('search "'+PREF.q.trim()+'"');
  if(PREF.mOnly===false && moN) on.push('Missive-only tasks hidden');
  var hidden = DATA.board.length - rows.length;
  tools.appendChild(el('div',{cls:'muted small'+(hidden&&PREF.when!=='all'?' late':''),text:rows.length+' of '+DATA.board.length+' jobs'+(tot?' · '+tot+' sheets':'')+(hidden?' · '+hidden+' hidden by: '+on.join(', '):'')}));
  wrap.appendChild(tools);
  var g = GROUPS[PREF.group] ? GROUPS[PREF.group][1] : GROUPS.none[1];
  var groups = {}, order = [];
  rows.forEach(function(r){ var k=g(r); if(!(k in groups)){ groups[k]=[]; order.push(k); } groups[k].push(r); });
  order.forEach(function(k){
    if(k!==''){ var sheets = groups[k].reduce(function(s,r){ return s+r.sheets; },0);
      wrap.appendChild(el('div',{cls:'grp'},[k+' ', el('span',{cls:'muted',text:'· '+groups[k].length+' job'+(groups[k].length===1?'':'s')+(sheets?' · '+sheets+' sheets':'')})])); }
    groups[k].forEach(function(r){ wrap.appendChild(boardRow(r, here[r.order])); });
  });
  if(!rows.length) wrap.appendChild(el('div',{cls:'muted',text:'No jobs match these filters.'}));
  return wrap;
}

// Copy tracking (owner 2026-10-24: "add a copy tracking button once shipping is started"): every
// tracking link of the order as text to paste to the customer — one line, or "Box n:" lines.
function trackingText(tracks){
  var line = function(t){ return t.url || t.tracking; };
  return tracks.length===1 ? line(tracks[0]) : tracks.map(function(t,i){ return 'Box '+(i+1)+': '+line(t); }).join('\\n');
}
function copyText(text, done){
  // Missive's frame may not be granted the clipboard API; a selected textarea and execCommand works there.
  var fallback = function(){ var ta = el('textarea',{style:'position:fixed;top:-1000px;opacity:0'}); ta.value = text; document.body.appendChild(ta); ta.select();
    var ok = false; try{ ok = document.execCommand('copy'); }catch(e){} ta.remove(); done(ok); };
  if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function(){ done(true); }, fallback); else fallback();
}
function copyTrackingButton(tracks){
  var n = tracks.length, b = el('button',{cls:'b',text:'Copy tracking'+(n>1?' ('+n+')':''),title:trackingText(tracks)});
  b.addEventListener('click',function(){ copyText(trackingText(tracks), function(ok){ b.textContent = ok ? 'Copied ✓' : 'Copy failed'; setTimeout(function(){ b.textContent='Copy tracking'+(n>1?' ('+n+')':''); }, 2000); }); });
  return b;
}

function render(){
  var d = DATA; app.className=''; app.textContent='';
  if(AN && CFG.analytics){
    app.appendChild(el('div',{cls:'impbar'},[ el('button',{cls:'b',text:'← Back to the panel',on:function(){ AN=false; render(); }}),
      el('b',{text:'Production analytics'}), el('a',{cls:'small',text:'Open wide ↗',title:'The same page in the browser, full width',on:function(){ open(CFG.analytics); }}) ]));
    app.appendChild(el('iframe',{cls:'imp',src:CFG.analytics,title:'Production analytics'}));
    return;
  }
  if(IMPOSE){
    var shipping = FRAME==='ship', u = shipping ? shipUrl(IMPOSE) : imposeUrl(IMPOSE);
    app.appendChild(el('div',{cls:'impbar'},[ el('button',{cls:'b',text:'← Back to the panel',on:function(){ IMPOSE=0; render(); load(LAST.ids, LAST.conv); }}),
      el('b',{text:(shipping?'Ship #':'Impose #')+IMPOSE}), el('a',{cls:'small',text:'Open wide ↗',title:'The same page in the browser, full width',on:function(){ open(u); }}) ]));
    app.appendChild(el('iframe',{cls:'imp',src:u,title:(shipping?'Shipping':'Imposition')+' — order #'+IMPOSE,style:shipping?'background:transparent':''}));
    app.appendChild(el('div',{cls:'muted small',text:shipping?'Labels are bought only after you confirm the carrier, account and price. The order\'s status is not changed.':'Send to Drive files the imposed PDF into this order\'s folder. Nothing else about the order changes.'}));
    return;
  }
  if(!inMissive){ var i=el('input',{placeholder:'Order #'}); var b=el('button',{text:'Look up',on:function(){ load(ppsOrderNumbers('#'+i.value), ''); }});
    app.appendChild(el('div',{cls:'card'},[el('div',{cls:'muted',text:'Outside Missive: type an order number to test.'}), i, ' ', b])); }
  if(d.missive.error) app.appendChild(el('div',{cls:'warn small',text:'Missive tasks: '+d.missive.error}));
  else if(!d.missive.connected) app.appendChild(el('div',{cls:'muted small',text:'Missive tasks are not connected (no API token saved).'}));
  var here = {};
  d.orders.forEach(function(o){
    here[o.order] = 1;
    var oc = el('details',{cls:'oc'}); if(PREF.cardOpen!==false) oc.open = true;
    oc.addEventListener('toggle',function(){ PREF.cardOpen = oc.open; savePref(); });
    oc.appendChild(el('summary',{},['Order #'+o.order+' · '+o.status+' · '+o.jobs.length+' job'+(o.jobs.length===1?'':'s')]));
    oc.appendChild(el('div',{style:'margin:0 0 6px'},[ el('a',{text:'Open in WooCommerce',on:function(){open(o.admin_url);}}) ]));
    if(o.jobs.length) oc.appendChild(subtaskStrip(o));
    if(CFG.ship){
      var sh = o.ship || { labels: 0 }, sb = el('div',{cls:'card small',style:'display:flex;justify-content:space-between;align-items:center;gap:6px'});
      sb.appendChild(el('div',{},[ el('b',{text:'Shipping'}), sh.labels ? el('span',{},[' · '+sh.labels+' label'+(sh.labels===1?'':'s')+' bought · '+sh.last+' ',
        sh.track_url?el('a',{text:sh.tracking,on:function(){ open(sh.track_url); }}):sh.tracking ]) : el('span',{cls:'muted',text:' · no label bought from the panel yet'}) ]));
      var acts = el('span',{style:'display:flex;gap:4px;flex-shrink:0'});
      if(sh.tracks && sh.tracks.length) acts.appendChild(copyTrackingButton(sh.tracks));
      acts.appendChild(el('button',{cls:'b',text:sh.labels?'Ship again':'Ship',title:'Rates from every carrier, and the label',on:function(){ ship(o.order); }}));
      sb.appendChild(acts);
      oc.appendChild(sb);
      if(sh.tracks && sh.tracks.length && !sh.labels) sb.firstChild.appendChild(el('span',{cls:'muted',text:' · '+sh.tracks.length+' label'+(sh.tracks.length===1?'':'s')+' bought in Shippo'}));
    }
    if(o.ship_to && CFG.shipTo) oc.appendChild(shipToCard(o));
    var holder = app; app = oc;
    if(d.missive.connected && d.missive.can_create && o.needs > 0){
      var box = el('div',{cls:'card small'});
      if(o.conv_ok){
        var cb = el('button',{cls:'b',text:'Create '+o.needs+' subtask'+(o.needs===1?'':'s')+' — one per job'});
        cb.addEventListener('click',function(){ createSubtasks(o, cb); });
        box.appendChild(el('div',{text:o.needs+' of '+o.jobs.length+' job'+(o.jobs.length===1?'':'s')+' in this order '+(o.needs===1?'has':'have')+' no subtask of '+(o.needs===1?'its':'their')+' own.'}));
        if(o.order_task) box.appendChild(el('div',{cls:'muted',text:'This conversation already has a subtask ("'+o.order_task.title.slice(0,60)+'"). The new ones are added beside it; nothing is removed.'}));
        box.appendChild(cb);
      } else {
        box.appendChild(el('div',{cls:'muted',text:'Open this order\'s own conversation in Missive to create its subtasks.'}));
      }
      app.appendChild(box);
    }
    if(o.drive){
      var dv = o.drive, db = el('div',{cls:'card small'});
      var head = el('div',{style:'display:flex;justify-content:space-between;align-items:center;gap:6px'},[ el('b',{text:'Google Drive'}) ]);
      if(dv.folder) head.appendChild(el('span',{},[ CFG.impose?el('button',{cls:'b',text:'Impose',title:'Open the imposition tool for this order',on:function(){ impose(o.order); }}):null,
        el('button',{cls:'b',text:'Open folder',on:function(){ open(dv.folder); }}) ]));
      db.appendChild(head);
      if(dv.note) db.appendChild(el('div',{cls:dv.state==='failed'?'late':'muted',text:dv.note}));
      dv.files.forEach(function(f){
        db.appendChild(el('div',{cls:'row',style:'padding:3px 0'},[ el('a',{text:f.name,on:function(){ open(f.url); }}),
          f.imposed?el('span',{cls:'st st-closed',text:' IMPOSED',style:'margin-left:4px'}):null,
          f.size?el('span',{cls:'muted',text:' · '+(f.size>1048576?(f.size/1048576).toFixed(1)+' MB':Math.max(1,Math.round(f.size/1024))+' KB')}):null ]));
      });
      if(dv.folder && !dv.files.length && dv.state==='ok') db.appendChild(el('div',{cls:'muted',text:dv.all?'The folder is empty.':'No files the website uploaded.'}));
      // The site's Drive connection (drive.file scope) sees only files the site itself put there.
      if(dv.folder && !dv.all) db.appendChild(el('div',{cls:'muted',style:'margin-top:4px',text:'Lists the files the website uploaded. Anything added to the folder by hand shows when you open the folder — or reconnect Drive (PPS Calculators → Google Drive) to list everything here.'}));
      app.appendChild(db);
    }
    o.jobs.forEach(function(j){ app.appendChild(jobCard(j, d.today)); });
    app = holder; app.appendChild(oc);
  });
  d.missing.forEach(function(id){ app.appendChild(el('div',{cls:'muted',text:'#'+id+' is not an order on the site.'})); });
  app.appendChild(renderBoard(here));
  app.appendChild(el('div',{cls:'ver',text:'PPS panel · build '+CFG.build}));
}

if(inMissive){
  Missive.on('change:conversations', function(ids){
    if(!ids || ids.length!==1){ load([], ''); return; }
    Missive.fetchConversations(ids).then(function(cs){
      var c=cs&&cs[0]; if(!c){ load([], ''); return; }
      var m=c.latest_message||{}, body=String(m.body||'').replace(/<[^>]+>/g,' ');
      load(ppsOrderNumbers([c.subject,m.subject,m.preview,body].join(' \\n ')), c.id||ids[0]);
    }, function(){ load([], ''); });
  }, { retroactive: true });
} else { load([], ''); }
})();
</script></body></html>
HTML;
}

/* ── Admin: the link to paste into Missive, and the API token ─── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Missive Panel', '🧭 Missive Panel', 'manage_woocommerce', 'pps-missive-panel', 'pps_mp_render_admin' );
}, 62 );

add_action( 'admin_post_pps_mp_regen', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_mp_regen' );
    delete_option( PPS_MP_KEY_OPTION );
    pps_mp_key( true );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-missive-panel&regen=1' ) );
    exit;
} );

add_action( 'admin_post_pps_mp_missive', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'pps_mp_missive' );
    $msg = 'saved';
    if ( isset( $_POST['remove_token'] ) ) {
        delete_option( PPS_MP_TOKEN_OPTION ); delete_option( PPS_MP_ORG_OPTION ); delete_transient( PPS_MP_TASKS_CACHE );
        $msg = 'removed';
    } else {
        $tok = trim( (string) wp_unslash( $_POST['token'] ?? '' ) );
        if ( $tok !== '' ) {
            update_option( PPS_MP_TOKEN_OPTION, preg_replace( '/\s+/', '', $tok ), false );
            delete_option( PPS_MP_ORG_OPTION ); delete_transient( PPS_MP_TASKS_CACHE );
        }
        update_option( PPS_MP_ALLOW_OPTION, empty( $_POST['allow_state'] ) ? 0 : 1, false );
        update_option( PPS_MP_CREATE_OPTION, empty( $_POST['allow_create'] ) ? 0 : 1, false );
        update_option( 'pps_missive_allow_address', empty( $_POST['allow_address'] ) ? 0 : 1, false );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=pps-missive-panel&missive=' . $msg ) );
    exit;
} );

function pps_mp_render_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    $url = add_query_arg( array( 'pps_missive_panel' => 1, 'k' => pps_mp_key( true ) ), home_url( '/' ) );
    $tok = pps_mp_token();
    echo '<div class="wrap"><h1>Missive Panel</h1>'
       . '<p>A production board in Missive\'s sidebar: every open job, sortable and groupable by date, paper, sheet, finishing and task state, with the open conversation\'s order shown in full at the top. Each job is paired with its Missive <strong>subtask</strong> in the order\'s conversation.</p>'
       . ( isset( $_GET['regen'] ) ? '<div class="notice notice-success"><p>New link made. The old one no longer works — update it in Missive.</p></div>' : '' )
       . ( ( $_GET['missive'] ?? '' ) === 'saved' ? '<div class="notice notice-success"><p>Missive settings saved.</p></div>' : '' )
       . ( ( $_GET['missive'] ?? '' ) === 'removed' ? '<div class="notice notice-success"><p>Missive API token removed.</p></div>' : '' );

    echo '<h2>1. Missive API token</h2>'
       . '<p>Create it in Missive: Preferences → <strong>API</strong> → <strong>Create a new token</strong> (needs Missive\'s Productive plan). Paste it here. It is stored on this site only, never shown again and never sent to a browser.</p>'
       . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pps_mp_missive', '_wpnonce', true, false )
       . '<input type="hidden" name="action" value="pps_mp_missive">'
       . '<p><input type="password" name="token" autocomplete="off" placeholder="' . esc_attr( $tok !== '' ? 'Saved — ends in …' . substr( $tok, -4 ) . ' (paste a new one to replace it)' : 'missive_pat-…' ) . '" style="width:100%;max-width:600px"></p>'
       . '<p><label><input type="checkbox" name="allow_state" value="1"' . ( get_option( PPS_MP_ALLOW_OPTION, false ) ? ' checked' : '' ) . '> Let the panel change subtask state and due date (Start / Done / Reopen buttons; click a due date to move it). '
       . 'Off: the panel only reads. On: it changes the <em>state</em> or <em>due date</em> of a subtask the site has paired with an order — nothing else.</label></p>'
       . '<p><label><input type="checkbox" name="allow_create" value="1"' . ( get_option( PPS_MP_CREATE_OPTION, false ) ? ' checked' : '' ) . '> Let the panel create subtasks — one per job, in the order\'s own conversation, '
       . 'titled with the order, job, quantity, size and window, the Job Ticket as the description, due on the job\'s start date. A job never gets a second one.</label></p>'
       . '<p><label><input type="checkbox" name="allow_address" value="1"' . ( get_option( 'pps_missive_allow_address', 1 ) ? ' checked' : '' ) . '> Let the panel change an order\'s ship-to (on unless switched off). '
       . 'Wherever it is changed — here or on the order screen — the Job Tickets, the Ship page, the panel\'s subtasks and a note in the order\'s conversations follow, and the panel says whether Shippo\'s copy has caught up.</label></p>'
       . '<p><button class="button button-primary">Save</button> '
       . ( $tok !== '' ? '<button class="button" name="remove_token" value="1" onclick="return confirm(\'Remove the Missive API token?\')">Remove token</button>' : '' ) . '</p></form>';

    if ( $tok !== '' ) {
        $tasks = pps_mp_tasks( true );
        if ( is_wp_error( $tasks ) ) {
            echo '<div class="notice notice-error inline"><p>Connection check failed: ' . esc_html( $tasks->get_error_message() ) . '</p></div>';
        } else {
            $map = pps_mp_learn_from_api( $tasks, pps_mp_conv_map(), 20 );
            list( $by, $unpaired ) = pps_mp_pair_tasks( $tasks, $map );
            $subs = 0; foreach ( $tasks as $t ) if ( ! empty( $t['conversation'] ) ) $subs++;
            echo '<div class="notice notice-success inline"><p>Connected. ' . esc_html( sprintf( '%d subtasks read (open and recently done); %d paired with an order; %d not yet paired.', $subs, count( $by ), count( $unpaired ) ) )
               . ' Unpaired subtasks pair themselves as their conversations are opened with the panel showing, or when their conversation subject names the order.</p></div>';
        }
    }

    echo '<h2>2. Add the panel to Missive</h2><ol>'
       . '<li>In Missive: Settings → Integrations → Add integration → <strong>Custom</strong> (iFrame).</li>'
       . '<li>Name it "PPS Production" and paste this URL:</li></ol>'
       . '<p><input type="text" readonly value="' . esc_attr( $url ) . '" style="width:100%;max-width:900px" onclick="this.select()"></p>'
       . '<p>Anyone with the link can read open-job schedules and tickets (and, if allowed above, start or finish a paired subtask), so share it only inside Missive.</p>'
       . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pps_mp_regen', '_wpnonce', true, false )
       . '<input type="hidden" name="action" value="pps_mp_regen"><button class="button">Make a new link (cuts off the old one)</button></form></div>';
}
