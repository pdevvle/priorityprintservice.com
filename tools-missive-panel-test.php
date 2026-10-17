<?php
/**
 * pps-missive-panel.php: the read-only Missive sidebar (owner, 2026-10-17).
 *
 * Fake open orders shaped like the real ones (two flats on the same 100lb Gloss 13×19 with
 * overlapping windows, one on another paper, one already printed, one shipping weeks later,
 * a custom quote line), driven through the real file with the real Paper Order reading
 * the paper. Checks: the window, "run together" (same paper AND sheet AND overlapping
 * window only), the automation preview, the key gate on page and endpoint, and that
 * nothing in the file can write to Missive or to an order.
 * The page's order-number finder is run under node.
 *
 * Run: php tools-missive-panel-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array();
function add_action() {} function add_filter() {} function add_submenu_page() {}
function apply_filters( $h, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function wp_next_scheduled() { return true; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function pps_get_config() { return array( 'papers_nc' => array( array( 'val' => 0.01, 'label' => '100lb Gloss Text' ), array( 'val' => 0.005, 'label' => '80lb Matte Text' ) ), 'papers_cs' => array() ); }
function wc_get_orders( $a ) { $GLOBALS['asked'] = $a; return $GLOBALS['orders']; }
function wc_get_order( $id ) { foreach ( $GLOBALS['orders'] as $o ) if ( $o->get_id() === $id ) return $o; return $GLOBALS['extra'][ $id ] ?? false; }
require __DIR__ . '/pps-paper-report.php';
require __DIR__ . '/pps-paper-order.php';
require __DIR__ . '/pps-missive-panel.php';

class M { public $k; public $v; function __construct( $k, $v ) { $this->k = $k; $this->v = $v; } function get_data() { return array( 'key' => $this->k, 'value' => $this->v ); } }
class I { public $n; public $m; function __construct( $n, $m ) { $this->n = $n; $this->m = $m; }
    function get_name() { return $this->n; } function get_meta( $k ) { return $this->m[ $k ] ?? ''; }
    function get_meta_data() { $o = array(); foreach ( $this->m as $k => $v ) $o[] = new M( $k, $v ); return $o; } }
class O { public $id; public $c; public $it; public $st;
    function __construct( $id, $c, $it, $st = 'processing' ) { $this->id = $id; $this->c = $c; $this->it = $it; $this->st = $st; }
    function get_id() { return $this->id; } function get_status() { return $this->st; } function get_items() { return $this->it; }
    function get_billing_first_name() { return $this->c; } function get_billing_last_name() { return ''; } function get_billing_company() { return ''; }
    function get_date_created() { return null; } function get_meta( $k ) { return ''; } }

function flat( $paper, $qty, $imp, $start, $ship, $extra = array() ) {
    return array( '_pps_metadata' => json_encode( $extra + array( 'paper' => $paper, 'qty' => $qty, 'jobName' => 'Job ' . $qty, 'sizeLabel' => '8.5×11',
        'productionStartDate' => $start, 'mustShipByDate' => $ship, 'debug' => array( 'imp' => $imp, 'qty' => $qty, 'pressSheets' => $qty / $imp ) ) ),
        'PPS-Production-Start' => $start, '_pps_delivery_date' => $ship, 'Job Ticket' => "Product: Flyer\nPaper: 100lb Gloss Text\nQuantity: $qty", 'Add-ons' => 'Coating: UV Gloss (both sides); Fold: Tri-fold' );
}
$gloss = array( 'val' => 0.01, 'label' => '100lb Gloss Text' );
$matte = array( 'val' => 0.005, 'label' => '80lb Matte Text' );
$GLOBALS['orders'] = array(
    new O( 87501, 'Ana', array( 11 => new I( 'Flyer', flat( $gloss, 1000, 2, '2026-10-19', '2026-10-22', array( 'rushCost' => 40 ) ) ) ) ),
    new O( 87502, 'Ben', array( 12 => new I( 'Flyer', flat( $gloss, 500, 2, '2026-10-21', '2026-10-23' ) ) ) ),
    new O( 87503, 'Cal', array( 13 => new I( 'Flyer', flat( $matte, 500, 2, '2026-10-19', '2026-10-22' ) ) ) ),
    new O( 87504, 'Dee', array( 14 => new I( 'Flyer', flat( $gloss, 400, 2, '2026-10-19', '2026-10-22' ) + array( '_pps_paper_done' => '2026-10-16' ) ) ) ),
    new O( 87505, 'Eve', array( 15 => new I( 'Flyer', flat( $gloss, 300, 2, '2026-11-09', '2026-11-12' ) ) ) ),
    new O( 87506, 'Fay', array( 16 => new I( 'Custom Order', array( 'Specs' => '500 postcards 4x6 14pt', 'Requested delivery' => '2026-10-30' ) ) ) ),
);
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

$open = pps_mp_open_jobs();
ok( $GLOBALS['asked']['status'] === array( 'processing', 'on-hold' ), 'only open orders are scanned' );
ok( count( $open ) === 6, 'one job per open line', count( $open ) );
$a = $open[0];
ok( $a['start'] === '2026-10-19' && $a['must_ship'] === '2026-10-22' && $a['rush'] === true, 'start, must-ship and rush are read from the line', $a );
ok( $a['papers'][0]['label'] === '100lb Gloss Text' && $a['papers'][0]['sheet'] === '13×19' && $a['papers'][0]['sheets'] === 500, 'paper comes from the Paper Order reading', $a['papers'] );
ok( $a['ticket'][1] === array( 'Paper', '100lb Gloss Text' ), 'the Job Ticket is split into label and value', $a['ticket'] );
ok( pps_mp_window( $a, '2026-10-17' ) === array( '2026-10-19', '2026-10-22' ), 'the window runs start → must-ship' );
ok( pps_mp_window( array( 'start' => '', 'must_ship' => '2026-10-22', 'delivery' => '' ), '2026-10-17' ) === array( '2026-10-17', '2026-10-22' ), 'no start date → the window starts today' );

$b = pps_mp_batch( $a, $open, '2026-10-17' );
ok( array_column( $b['mates'], 'order' ) === array( 87502 ), 'run together: same paper and sheet, overlapping window — not other paper, not printed, not weeks later', array_column( $b['mates'], 'order' ) );
ok( $b['combined'][0]['sheets'] === 750 && $b['combined'][0]['jobs'] === 1, 'the combined sheet count includes this job', $b['combined'] );
ok( pps_mp_batch( $open[2], $open, '2026-10-17' )['mates'] === array(), 'a job alone on its paper has no mates' );

$custom = $open[5];
ok( $custom['source'] === 'custom' && $custom['delivery'] === '2026-10-30' && $custom['ticket'][0] === array( 'Specs', '500 postcards 4x6 14pt' ), 'a custom line shows its own text and requested delivery', $custom );

$p = pps_mp_preview( $a );
ok( $p['due'] === '2026-10-22' && $p['title'] === '#87501 Job 1000 · start Oct 19 · ship Oct 22', 'the preview task has the must-ship due date and the window in its title', $p );
ok( $p['labels'] === array( '100lb Gloss Text', '13×19', 'RUSH', 'Coating', 'Fold' ), 'the preview labels: paper, sheet, rush, finishing', $p['labels'] );

$ans = pps_mp_answer( array( 87501, 99999 ) );
ok( count( $ans['orders'] ) === 1 && $ans['orders'][0]['jobs'][0]['batch']['mates'][0]['order'] === 87502, 'the endpoint answers for the order in view, with its batch', $ans['orders'] );
ok( $ans['missing'] === array( 99999 ), 'a number that is not an order is said, not guessed' );
$board = pps_mp_answer( array() )['board'];
ok( count( $board ) === 6 && $board[0]['must_ship'] === '2026-10-22' && end( $board )['must_ship'] === '2026-11-12', 'no order in view → every open job by ship date', array_column( $board, 'must_ship' ) );
$GLOBALS['extra'][ 87000 ] = new O( 87000, 'Old', array( 9 => new I( 'Flyer', flat( $gloss, 100, 2, '2026-09-01', '2026-09-04' ) ) ), 'completed' );
ok( pps_mp_answer( array( 87000 ) )['orders'][0]['status'] === 'completed', 'a completed order still shows (for reference)' );

// ── the key ──
ok( pps_mp_key_ok( 'anything' ) === false, 'with no key made, nothing gets in' );
$k = pps_mp_key( true );
ok( strlen( $k ) === 40 && pps_mp_key_ok( $k ) && ! pps_mp_key_ok( '' ) && ! pps_mp_key_ok( substr( $k, 0, 39 ) . 'x' ), 'only the exact key gets in' );
ok( pps_mp_key( true ) === $k, 'the key is made once' );

// ── read-only, by construction ──
$src = file_get_contents( __DIR__ . '/pps-missive-panel.php' );
$code = preg_replace( '#/\*.*?\*/#s', '', $src );
foreach ( array( 'createTask', 'addLabels', 'removeLabels', 'assign(', 'addAssignees', 'comment(', 'archive(', 'trash(', 'close(', 'setSubject', 'setColor', 'compose', 'reply(', 'forward(', 'insertText', 'storeSet' ) as $w ) {
    ok( strpos( $code, 'Missive.' . $w ) === false, "the page never calls Missive.$w" );
}
foreach ( array( 'update_status', '->save(', 'wc_update_order_item_meta', 'update_meta_data', 'add_order_note', 'wp_mail' ) as $w ) {
    ok( strpos( $code, $w ) === false, "the server never calls $w" );
}
ok( strpos( $src, "'methods' => 'GET'" ) !== false && strpos( $src, 'pps_mp_key_ok( (string) $req->get_header' ) !== false, 'the endpoint is GET-only and needs the key header' );
$html = pps_mp_page_html( 'KEY123', 'https://x/wp-json/pps/v1/missive/panel' );
ok( strpos( $html, '"key":"KEY123"' ) !== false && strpos( $html, 'textContent' ) !== false && strpos( $html, 'innerHTML' ) === false, 'the page carries its key and writes text, never HTML' );
ok( substr_count( strtolower( $html ), '</script>' ) === 2, 'no stray script close inside the page script' );
ok( substr_count( $html, 'data-cfasync="false"' ) === 2, 'Cloudflare Rocket Loader is told to leave both scripts alone' );

// ── the order-number finder, run as the browser runs it ──
if ( preg_match( '/function ppsOrderNumbers\(text\)\{.*?\n\}/s', $html, $m ) ) {
    $js = $m[0] . "\nconsole.log(JSON.stringify([ppsOrderNumbers('[Priority Print Service]: New order #87347'),ppsOrderNumbers('Re: Your order 87301 and order #87302, also #87301'),ppsOrderNumbers('Call 602-818-0021 about 12345 sheets'),ppsOrderNumbers('Order #  87455 is ready')]));";
    $f = tempnam( sys_get_temp_dir(), 'mp' ) . '.js'; file_put_contents( $f, $js );
    $got = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $f ) . ' 2>&1' ), true ); @unlink( $f );
    ok( $got === array( array( '87347' ), array( '87301', '87302' ), array(), array( '87455' ) ), 'order numbers come from "#87347" and "order 87301", never from a phone number or a sheet count', $got );
} else { ok( false, 'ppsOrderNumbers is in the page' ); }

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> MISSIVE PANEL OK ($n checks)\n";
exit( $fail ? 1 : 0 );
