<?php
/**
 * pps-analytics.php — the Production Analytics screen, against fake orders.
 *
 * The sheet reading is the Paper Order's own (pps_po_calc_rows / pps_po_plan_rows, lifted
 * out of pps-paper-order.php), so the two screens cannot count differently. Checks: a
 * booklet's inside and cover sheets and their paper + click cost in the calculators' shapes;
 * a two-sided flat with one side B&W; a paper with no price on the order priced from the
 * config by label, and one with no price anywhere said; a custom line with a plan costed as
 * 4/4 and said, one without a plan not counted and named; received by week paid, fulfilled
 * by week completed; on time / late against must-ship with business days; turnaround median;
 * the open backlog and what in it is late; cancelled orders ignored; and the screen renders
 * every tile, chart and table with no PHP warnings. Prices here are made up.
 *
 * Run: php tools-analytics-test.php
 */
define( 'ABSPATH', __DIR__ . '/' ); define( 'HOUR_IN_SECONDS', 3600 );
error_reporting( E_ALL ); set_error_handler( function ( $no, $str, $file, $line ) { global $warn; $warn[] = "$str @ $line"; return true; } );
$warn = array(); $GLOBALS['tr'] = array();
function add_action() {} function add_filter() {} function add_submenu_page() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; } function add_query_arg( $k, $v = null, $u = '' ) { return is_array( $k ) ? $v . '&' . http_build_query( $k ) : $u . '&' . $k . '=' . $v; }
function wp_nonce_url( $u ) { return $u . '&_wpnonce=n'; } function home_url( $p = '' ) { return 'https://x' . $p; } function check_admin_referer() { return 1; } function current_user_can() { return true; } function wp_die( $m ) { throw new Exception( $m ); }
define( 'MINUTE_IN_SECONDS', 60 );
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; } function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function pps_shop_timezone() { return 'America/Phoenix'; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; }
function current_time() { return '2026-10-19 08:00'; } function pps_mp_today() { return '2026-10-19'; }
function pps_get_config() { return array( 'pcf' => array( 'printing_black_cost' => 0.01, 'printing_fullcolor_cost' => 0.05 ),
    'papers_nc' => array( array( 'label' => 'Test Text', 'price' => 0.1 ) ), 'papers_cs' => array( array( 'label' => 'Config Card', 'price' => 0.3 ) ) ); }

$po = file_get_contents( getenv( 'PPS_PO_PHP' ) ?: __DIR__ . '/pps-paper-order.php' );
function lift( $src, $name ) {
    $at = strpos( $src, "function $name(" ); if ( $at === false ) { echo "FAIL: $name not found\n"; exit( 1 ); }
    $i = strpos( $src, '{', $at ); $d = 0;
    for ( $l = strlen( $src ); $i < $l; $i++ ) { if ( $src[ $i ] === '{' ) $d++; elseif ( $src[ $i ] === '}' && --$d === 0 ) break; }
    return substr( $src, $at, $i - $at + 1 );
}
foreach ( array( 'pps_po_flat_imp', 'pps_po_sheet_dims', 'pps_po_sheet_name', 'pps_po_dec', 'pps_po_n', 'pps_po_flat_sheets', 'pps_po_calc_rows', 'pps_po_plan_rows' ) as $fn ) eval( lift( $po, $fn ) );
require getenv( 'PPS_AN_PHP' ) ?: __DIR__ . '/pps-analytics.php';

class D { public $s; function __construct( $s ) { $this->s = $s; } function date( $f ) { return gmdate( $f, strtotime( $this->s . ' 12:00:00 UTC' ) ); } }
class I { public $m; public $n; public $t; function __construct( $n, $m, $t ) { $this->n = $n; $this->m = $m; $this->t = $t; }
    function get_meta( $k ) { return $this->m[ $k ] ?? ''; } function get_name() { return $this->n; } function get_total() { return $this->t; } }
class O { public $a; function __construct( $a ) { $this->a = $a; }
    function get_id() { return $this->a['id']; } function get_status() { return $this->a['status']; } function get_total() { return $this->a['total']; }
    function get_date_paid() { return isset( $this->a['paid'] ) ? new D( $this->a['paid'] ) : null; } function get_date_created() { return new D( $this->a['paid'] ?? '2026-10-01' ); }
    function get_date_completed() { return isset( $this->a['done'] ) ? new D( $this->a['done'] ) : null; } function get_date_modified() { return null; }
    function get_billing_first_name() { return $this->a['name']; } function get_billing_last_name() { return ''; } function get_billing_company() { return ''; }
    function get_items() { return $this->a['items']; } function get_edit_order_url() { return 'https://x/edit/' . $this->a['id']; } }

$book = array( 'calcType' => 'saddle', 'totalQty' => 500, 'insidePaper' => array( 'label' => 'Snap Text', 'price' => 0.1 ), 'coverPaper' => array( 'label' => 'Snap Card', 'price' => 0.2 ),
    'coverMode' => 'separate', 'insideColor' => 'color', 'coverColor' => 'color', 'mustShipByDate' => '2026-10-09', 'productionStartDate' => '2026-10-06',
    'debug' => array( 'parentSheets' => 250, 'imp' => 8, 'totalPages' => 8 ) );
$flat = array( 'calcType' => 'brochure', 'qty' => 400, 'paper' => array( 'label' => 'Flat Stock', 'price' => 0.05 ), 'sides' => 2, 'frontColor' => 'color', 'backColor' => 'bw',
    'mustShipByDate' => '2026-10-12', 'debug' => array( 'imp' => 4, 'pressSheets' => 100, 'qty' => 400 ) );
$flat_cfg = array( 'calcType' => 'postcard', 'qty' => 100, 'paper' => array( 'label' => 'Config Card' ), 'sides' => 1, 'frontColor' => 'bw', 'mustShipByDate' => '2026-10-20', 'debug' => array( 'imp' => 4, 'pressSheets' => 25, 'qty' => 100 ) );
$flat_nop = array( 'calcType' => 'postcard', 'qty' => 100, 'paper' => array( 'label' => 'Mystery Stock' ), 'sides' => 1, 'frontColor' => 'color', 'debug' => array( 'imp' => 4, 'pressSheets' => 10, 'qty' => 100 ) );
$J = function ( $m, $extra = array() ) { return array( '_pps_metadata' => json_encode( $m ) ) + $extra; };
$orders = array(
    new O( array( 'id' => 1, 'status' => 'completed', 'total' => 300, 'paid' => '2026-10-05', 'done' => '2026-10-08', 'name' => 'Ann', 'items' => array( new I( 'Booklet', $J( $book ), 300 ) ) ) ),
    new O( array( 'id' => 2, 'status' => 'completed', 'total' => 200, 'paid' => '2026-10-06', 'done' => '2026-10-14', 'name' => 'Bob', 'items' => array( new I( 'Brochure', $J( $flat ), 150 ), new I( 'Postcard', $J( $flat_cfg ), 50 ) ) ) ),
    new O( array( 'id' => 3, 'status' => 'processing', 'total' => 120, 'paid' => '2026-10-12', 'name' => 'Cy', 'items' => array( new I( 'Brochure', $J( $flat, array( 'PPS-Production-Start' => '2026-10-13' ) ), 120 ) ) ) ),
    new O( array( 'id' => 4, 'status' => 'completed', 'total' => 90, 'paid' => '2026-10-13', 'done' => '2026-10-15', 'name' => 'Di', 'items' => array(
        new I( 'Custom quote', array( '_pps_paper_plan' => json_encode( array( 'rows' => array( array( 'paper' => array( 'label' => 'Test Text' ), 'sheets' => 50, 'sheet' => '13x19' ) ) ) ) ), 60 ),
        new I( 'Pay link', array(), 30 ) ) ) ),
    new O( array( 'id' => 5, 'status' => 'completed', 'total' => 40, 'paid' => '2026-10-14', 'done' => '2026-10-16', 'name' => 'Ed', 'items' => array( new I( 'Postcard', $J( $flat_nop ), 40 ) ) ) ),
    new O( array( 'id' => 6, 'status' => 'cancelled', 'total' => 999, 'paid' => '2026-10-14', 'name' => 'Zed', 'items' => array( new I( 'Booklet', $J( $book ), 999 ) ) ) ),
    new O( array( 'id' => 7, 'status' => 'completed', 'total' => 80, 'paid' => '2026-06-01', 'done' => '2026-06-05', 'name' => 'Old', 'items' => array( new I( 'Booklet', $J( $book ), 80 ) ) ) ),
);

$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }
$near = function ( $a, $b ) { return abs( $a - $b ) < 0.001; };
$rates = pps_an_rates();
ok( $rates['black'] === 0.01 && $rates['color'] === 0.05 && $rates['prices']['Config Card'] === 0.3, 'click rates and paper prices come from the config', $rates );

$b = pps_an_item( $orders[0]->get_items()[0], $rates );
ok( $b['sheets'] === 375 && $b['by_sheet'] === array( '13x19' => 375 ), 'a booklet: 250 inside sheets + 125 covers (500 ÷ 4 per sheet), as the Paper Order counts them', $b );
ok( $near( $b['paper'], 250 * 0.1 + 125 * 0.2 ) && $near( $b['color'], 250 * ( 0.02 + 0.10 ) + 125 * 0.10 ), 'its cost: paper per sheet, inside black×2 + colour×2, cover colour×2 (the calculators\' shapes)', $b );
$f = pps_an_item( $orders[1]->get_items()[0], $rates );
ok( $f['sheets'] === 100 && $near( $f['paper'], 5 ) && $near( $f['color'], 100 * ( 0.06 + 0.01 ) ), 'a two-sided flat: colour front = black + colour, B&W back = black', $f );
$fc = pps_an_item( $orders[1]->get_items()[1], $rates );
ok( $fc['priced'] && $near( $fc['paper'], 25 * 0.3 ) && $near( $fc['color'], 25 * 0.01 ), 'a paper with no price on the order is priced from the config row of the same label; a one-sided B&W flat is one black click', $fc );
$np = pps_an_item( $orders[4]->get_items()[0], $rates );
ok( ! $np['priced'] && $np['sheets'] === 10 && $np['paper'] === 0.0 && $near( $np['color'], 10 * 0.06 ), 'a paper priced nowhere: sheets and clicks still counted, marked unpriced', $np );
$cp = pps_an_item( $orders[3]->get_items()[0], $rates );
ok( $cp['sheets'] === 50 && $cp['assumed'] && $near( $cp['color'], 50 * 0.12 ) && $near( $cp['paper'], 5 ), 'a custom line with a paper plan: its sheets, costed as full colour both sides and marked assumed', $cp );
$cu = pps_an_item( $orders[3]->get_items()[1], $rates );
ok( ! $cu['counted'] && $cu['sheets'] === 0, 'a custom line with no plan is not guessed at', $cu );
ok( pps_an_item( new I( 'x', array( '_pps_paper_plan' => json_encode( array( 'none' => 1 ) ) ), 1 ), $rates )['counted'], 'a line marked "no press paper" counts as zero sheets, not as missing' );

$d = pps_an_build( $orders, '2026-09-21', '2026-10-19', $rates );
$t = $d['totals'];
ok( $t['received'] === 5 && $t['jobs_in'] === 7 && $near( $t['revenue'], 750 ), 'received: five orders paid in the window (not the cancelled one, not June\'s)', $t );
ok( $t['done'] === 4 && $t['jobs_out'] === 6, 'fulfilled: four orders completed in the window', $t );
ok( $t['sheets'] === 375 + 125 + 50 + 10 && $near( $t['paper'], 50 + 5 + 7.5 + 5 ) && $near( $t['color'], 42.5 + 7 + 0.25 + 6 + 0.6 ), 'sheets and cost add up over the fulfilled jobs', array( $t['sheets'], $t['paper'], $t['color'] ) );
ok( count( $t['uncounted'] ) === 1 && $t['uncounted'][0]['order'] === 4 && $t['unpriced'] === 1 && $t['assumed'] === 1, 'the uncounted, unpriced and assumed lines are counted so the screen can say so', $t );
ok( $t['on_time'] === 1 && $t['late'] === 1 && $t['no_date'] === 2, 'on time vs must-ship: #1 on time, #2 late, #4 and #5 have no must-ship date', $t );
ok( $d['late'][0]['order'] === 2 && $d['late'][0]['days'] === 2 && $d['late'][0]['url'] === 'https://x/edit/2', 'the late one is named, late by 2 business days (Tue 13, Wed 14), with its edit link', $d['late'] );
ok( $d['turn']['n'] === 4 && $d['turn']['median'] === 2 && $d['turn']['p90'] === 6, 'turnaround in business days: 3, 6, 2, 2 → median 2, 90th 6', $d['turn'] );
$wk = $d['weeks'];
ok( array_key_first( $wk ) === '2026-09-21' && isset( $wk['2026-10-19'] ) && count( $wk ) === 5, 'weeks run Monday to Monday across the window', array_keys( $wk ) );
ok( $wk['2026-10-05']['received'] === 2 && $wk['2026-10-05']['done'] === 1 && $wk['2026-10-12']['done'] === 3 && $wk['2026-10-12']['sheets'] === 185, 'received by the week paid, fulfilled and sheets by the week completed', array( $wk['2026-10-05'], $wk['2026-10-12'] ) );
ok( $wk['2026-10-12']['late'] === 1 && $wk['2026-10-05']['on_time'] === 1, 'on time / late by week' );
$op = $d['open'];
ok( $op['orders'] === 1 && $op['jobs'] === 1 && $op['sheets'] === 100 && $op['past_start'] === 1 && count( $op['late'] ) === 1 && $op['late'][0]['days'] === 5, 'the backlog: one open order, 100 sheets, past its start date, 5 business days past must-ship', $op );
ok( $d['products']['saddle']['jobs'] === 1 && $d['products']['saddle']['sheets'] === 375 && $d['products']['brochure']['jobs'] === 2, 'by product: jobs received and sheets printed', $d['products'] );

// the screen
$GLOBALS['queries'] = array();
function wc_get_orders( $a ) { global $orders; $GLOBALS['queries'][] = $a; return array_map( function ( $o ) { return $o->get_id(); }, $orders ); }
function wc_get_order( $id ) { global $orders; foreach ( $orders as $o ) if ( $o->get_id() === $id ) return $o; return false; }
$_GET = array( 'days' => 30 );
ob_start(); pps_an_render(); $html = ob_get_clean();
foreach ( array( 'orders received', 'orders fulfilled', 'shipped by must-ship date (1 of 2)', 'median paid → fulfilled', 'press sheets printed (est.)', 'paper + colour (est.)', 'open orders past must-ship', 'Orders received vs fulfilled, per week', 'Press sheets printed (est.), per week', 'Paper + colour cost (est.), per week', 'Fulfilled on time vs late, per week', 'Turnaround: business days', 'By product', 'Late shipments in this window', 'How these are estimated', 'had no paper plan and is not counted', 'costed as full colour both sides', 'no price on file' ) as $w )
    ok( strpos( $html, $w ) !== false, "the screen shows: $w" );
ok( substr_count( $html, '<svg class="pps-an-chart"' ) === 6 && strpos( $html, '<script' ) === false, 'six charts, drawn as inline SVG with no script' );
ok( strpos( $html, '$560' ) !== false || strpos( $html, '560' ) !== false, 'the sheet count reaches the tiles' );
ok( ! $warn, 'no PHP warnings', $warn );
ok( isset( $GLOBALS['tr']['pps_analytics_30'] ), 'the build is cached per window' );
$qs = $GLOBALS['queries'];
ok( count( $qs ) === 3 && ! array_filter( $qs, function ( $q ) { return ( $q['return'] ?? '' ) !== 'ids'; } )
    && $qs[0]['date_created'] === '>=2026-09-17' && $qs[1]['status'] === array( 'completed' ) && $qs[1]['date_completed'] === '>=2026-09-20' && $qs[2]['status'] === array( 'processing', 'on-hold' ),
    'only ids are asked for, and only the orders the window needs: paid in it, completed in it, or open (not 120 days of everything — production ran out of memory)', $qs );
$gen = pps_an_orders( '2026-09-20' );
ok( $gen instanceof Generator && count( iterator_to_array( $gen, false ) ) === count( $orders ), 'orders come one at a time, each once' );
$src = file_get_contents( getenv( 'PPS_AN_PHP' ) ?: __DIR__ . '/pps-analytics.php' );
ok( strpos( $src, "add_action( 'pps_analytics_refresh', function () { pps_an_get( 90, true ); pps_an_get( 30, true ); pps_an_labels( true ); } );" ) !== false, 'the 30- and 90-day windows and the labels are built hourly in the background' );
ok( strpos( $src, "echo 'The analytics could not be built: '" ) !== false, 'the panel frame says what failed instead of a bare critical error' );
ok( pps_an_svg_bars( array(), array() ) === '<p class="description">No data in this window.</p>', 'an empty chart says so' );

// the same screen in the Missive panel's frame
$fr = pps_an_frame_html( pps_an_get( 30 ), 'KEY9' );
ok( strpos( $fr, '<!DOCTYPE html>' ) === 0 && strpos( $fr, 'Orders received vs fulfilled, per week' ) !== false && substr_count( $fr, '<svg class="pps-an-chart"' ) === 6, 'the panel frame page carries the whole screen' );
ok( strpos( $fr, 'pps_missive_analytics=1&amp;k=KEY9&amp;days=90' ) !== false && strpos( $fr, 'pps_missive_analytics=1&amp;k=KEY9&amp;days=30&amp;refresh=1' ) !== false && strpos( $fr, 'wp-admin/admin.php?page=pps-analytics' ) === false, 'its window and Refresh links stay on the key-scoped page, never wp-admin', $fr );
ok( strpos( $fr, 'window.parent.postMessage({ppsOpen:u},location.origin)' ) !== false, 'an order link is handed to the panel, which opens it through Missive' );
$src = file_get_contents( getenv( 'PPS_AN_PHP' ) ?: __DIR__ . '/pps-analytics.php' );
ok( strpos( $src, "if ( ! function_exists( 'pps_mp_key_ok' ) || ! pps_mp_key_ok( \$k ) ) { status_header( 403 );" ) !== false, 'the frame page answers only with the panel key' );
ok( strpos( $src, "\$refresh = ! empty( \$_GET['refresh'] ) && ! get_transient( 'pps_an_frame_refresh' );" ) !== false, 'a rebuild from the frame page is allowed once a minute' );
ok( strpos( $src, "minmax(min(100%,420px),1fr)" ) !== false, 'the chart grid folds to one column in a narrow sidebar' );


// shipping labels bought, per day (Shippo's transaction list)
ok( strpos( $html, 'Shipping labels bought, per day' ) !== false && strpos( $html, 'pps-missive-ship.php) is not loaded' ) !== false, 'without the shipping file the section says so instead of failing' );
$lab = function ( $id, $at, $rate, $meta, $order, $st = 'SUCCESS' ) {
    return array( 'object_id' => $id, 'object_created' => $at, 'status' => $st, 'rate' => $rate, 'metadata' => $meta, 'order' => $order,
                  'tracking_number' => 'TRK' . $id, 'tracking_url_provider' => 'https://track.example/' . $id, 'label_url' => 'https://deliver.goshippo.com/' . $id . '.pdf?Expires=1&Signature=s' );
};
$GLOBALS['shippo'] = array(
    'page1' => array( $lab( 'a', '2026-10-19T23:23:09.364Z', 'r1', 'Order 87485 · box 1 of 2', 'so1' ), $lab( 'b', '2026-10-19T23:24:09Z', 'r1', 'Order 87485 · box 2 of 2', 'so1' ) ),
    'page2' => array( $lab( 'c', '2026-10-19T06:30:00Z', 'r2', '', 'so9' ),                // 11:30 pm on the 18th in Phoenix
                      $lab( 'd', '2026-10-17T18:00:00Z', 'r3', '', 'so8', 'REFUNDED' ),
                      $lab( 'e', '2026-10-17T18:05:00Z', 'r3', '', '', 'ERROR' ) ) );
$GLOBALS['calls'] = array();
if ( true ) {
    function pps_ms_api( $m, $path ) {
        $GLOBALS['calls'][] = $path; $S = $GLOBALS['shippo'];
        if ( strpos( $path, 'transactions/' ) === 0 ) return strpos( $path, 'page=1&' ) ? array( 'results' => $S['page1'], 'next' => 'x' ) : array( 'results' => $S['page2'], 'next' => null );
        if ( $path === 'rates/r1' ) return array( 'provider' => 'UPS', 'servicelevel' => array( 'name' => 'Ground' ), 'amount' => '12.50' );
        if ( $path === 'rates/r2' ) return array( 'provider' => 'USPS', 'servicelevel' => array( 'name' => 'Priority Mail' ), 'amount' => '9.10' );
        if ( $path === 'rates/r3' ) return new WP_Error( 'shippo', 'gone' );
        if ( $path === 'orders/so9' ) return array( 'order_number' => '#87401' );
        if ( $path === 'orders/so8' ) return array( 'order_number' => '87390' );
        return new WP_Error( 'x', 'unexpected ' . $path );
    }
}
$L = pps_an_labels( true );
$tq = array_values( array_filter( $GLOBALS['calls'], function ( $c ) { return strpos( $c, 'transactions/' ) === 0; } ) );
ok( count( $tq ) === 2 && strpos( $tq[0], 'object_created_gte=2026-09-20T07%3A00%3A00' ) !== false, 'Shippo is asked for 30 days of labels from midnight shop time (07:00 UTC), page by page', $tq );
ok( count( $L['list'] ) === 4, 'every label is listed, a failed one (ERROR) is not', $L['list'] );
$byid = array(); foreach ( $L['list'] as $r ) $byid[ basename( parse_url( $r['label'], PHP_URL_PATH ), '.pdf' ) ] = $r;
ok( $byid['a']['day'] === '2026-10-19' && $byid['a']['time'] === '4:23 pm' && $byid['a']['order'] === '87485' && $byid['a']['from_panel'] && $byid['a']['carrier'] === 'UPS' && $byid['a']['service'] === 'Ground' && $byid['a']['amount'] === 12.5, 'a panel label: shop day and time, order from its metadata, carrier, service and postage from its rate', $byid['a'] );
ok( $byid['c']['day'] === '2026-10-18' && $byid['c']['order'] === '87401' && ! $byid['c']['from_panel'], 'a label bought in Shippo\'s app: the day is the shop\'s (11:30 pm the 18th, not UTC\'s 19th), the order from its Shippo order', $byid['c'] );
ok( $byid['d']['status'] === 'REFUNDED' && $byid['d']['amount'] === null && $L['pending'] === 1, 'a rate Shippo no longer gives is said as still to look up, not guessed', $L );
ok( count( array_filter( $GLOBALS['calls'], function ( $c ) { return $c === 'rates/r1'; } ) ) === 1, 'each rate is looked up once, though two labels share it' );
ok( ( $GLOBALS['opt']['pps_an_label_rates']['r1']['amount'] ?? 0 ) === 12.5 && ( $GLOBALS['opt']['pps_an_label_orders']['so9'] ?? '' ) === '87401', 'rates and Shippo orders are kept, so the next build does not ask again' );
$GLOBALS['calls'] = array();
pps_an_labels( true );
ok( ! array_filter( $GLOBALS['calls'], function ( $c ) { return in_array( $c, array( 'rates/r1', 'rates/r2', 'orders/so9', 'orders/so8' ), true ); } ), 'the next build reads the kept answers', $GLOBALS['calls'] );
ok( is_array( $GLOBALS['tr']['pps_an_labels'] ?? null ), 'the list is cached (10 minutes)' );
ob_start(); pps_an_labels_output( $L ); $lh = ob_get_clean();
ok( strpos( $lh, 'Mon Oct 19 — 2 labels · $25.00' ) !== false && strpos( $lh, 'Sun Oct 18 — 1 label · $9.10' ) !== false && strpos( $lh, 'Sat Oct 17 — 0 labels · $0.00 · 1 refunded' ) !== false, 'each day: labels and postage, refunded ones listed but not counted', $lh );
ok( substr_count( $lh, '>Label PDF</a>' ) === 4 && strpos( $lh, 'href="https://deliver.goshippo.com/a.pdf?Expires=1&amp;Signature=s"' ) !== false, 'every label links to its Shippo PDF', $lh );
ok( strpos( $lh, 'href="https://track.example/a">TRKa</a>' ) !== false && strpos( $lh, 'page=wc-orders&amp;action=edit&amp;id=87485">#87485</a>' ) !== false, 'tracking and the order link', $lh );
ok( substr_count( $lh, '<details' ) === 3 && substr_count( $lh, '<details open' ) === 1 && strpos( $lh, '<details open style="margin:6px 0"><summary style="cursor:pointer;font-weight:600">Mon Oct 19' ) !== false, 'one fold per day, the newest open' );
ok( substr_count( $lh, '<svg class="pps-an-chart"' ) === 2 && strpos( $lh, '<script' ) === false, 'labels and postage per day as two charts, no script' );
$GLOBALS['tr'] = array();
$fr2 = pps_an_frame_html( pps_an_get( 30 ) + array( 'labels' => $L ), 'KEY9' );
ok( strpos( $fr2, '>Label PDF</a>' ) !== false && strpos( $fr2, 'if(!/^https:\/\//.test(u))return;if(window.parent!==window)window.parent.postMessage({ppsOpen:u}' ) !== false, 'in the Missive frame, a label link is handed to the panel to open (https only)' );
ok( ! $warn, 'no PHP warnings in the labels section', $warn );
echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> ANALYTICS OK ($n checks)\n";
exit( $fail ? 1 : 0 );
