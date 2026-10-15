<?php
/**
 * pps-paper-order.php: the copy-paste paper order (owner, 2026-10-15).
 *
 * Drives the real file against stubs with the shapes of real open jobs (Peter Huang's
 * 21×7 accordion, Cameron Fleming's 16.5×5.5 trifold, a 3.5×5.5 saddle book, a self-cover
 * coupon book) and the real custom-order Specs text, and checks the numbers a supply
 * order is made of: sheets per job from the priced yield, oversize flats on 13×27.5,
 * booklet covers counted per spread, overs, parent sheets cut from 28×40, the text,
 * and the custom-line workflow (suggest → plan → counted; no paper; already printed).
 *
 * Run: php tools-paper-order-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['opts'] = array();
function add_action() {} function add_filter() {} function add_submenu_page() {}
function apply_filters( $h, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_next_scheduled() { return true; }
function current_user_can() { return ! empty( $GLOBALS['can'] ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_nonce_field( $a, $n = '_wpnonce', $r = true, $e = true ) { return '<input type="hidden" name="' . $n . '" value="nonce-' . $a . '">'; }
function wp_date( $f ) { return 'Oct 15, 2026'; }
function wp_die( $m ) { throw new Exception( $m ); }
function wc_get_orders( $a ) { return $GLOBALS['fake_orders'] ?? array(); }
function pps_get_config() {
    return array(
        'papers_nc' => array(
            array( 'val' => 0.01,  'label' => '100lb Gloss Text' ),
            array( 'val' => 0.005, 'label' => '80lb Matte Text' ),
            array( 'val' => 2.005, 'label' => '100lb Matte Factory Coated', 'factory' => true, 'days' => 2 ),
            array( 'val' => 2.003, 'label' => '80lb Offset Smooth Opaque', 'factory' => true, 'days' => 2 ),
        ),
        'papers_cs' => array(
            array( 'val' => 0.02,  'label' => '100lb Gloss Cover' ),
            array( 'val' => 2.22,  'label' => '100lb Matte Factory Coated (Cardstock)', 'factory' => true, 'days' => 2 ),
        ),
    );
}
function pps_paper_is_inventoried( $row ) { return empty( $row['factory'] ) && empty( $row['days'] ); }
require __DIR__ . '/pps-paper-report.php';
require getenv( 'PPS_PAPER_ORDER_PHP' ) ?: __DIR__ . '/pps-paper-order.php';

$fail = 0; $n = 0;
function ok( $c, $w, $got = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $got !== null ? ' — got ' . json_encode( $got, JSON_UNESCAPED_UNICODE ) : '' ) . "\n"; } }

// ── yields ──
ok( pps_po_parent_yield( 13, 19, 28, 40 ) === 4, '13×19 cuts 4 from 28×40' );
ok( pps_po_parent_yield( 13, 27.5, 28, 40 ) === 3, '13×27.5 cuts 3 from 28×40 (turned)' );
ok( pps_po_parent_yield( 12, 18, 28, 40 ) === 4, '12×18 cuts 4 from 28×40' );
ok( pps_po_parent_yield( 13, 19, 25, 38 ) === 2, 'another parent size is honoured (25×38 → 2)' );
ok( pps_po_flat_imp( 21, 7 ) == 0.5, '21×7 is half-up on 13×19, as the brochure calculator prices it', pps_po_flat_imp( 21, 7 ) );
ok( pps_po_flat_imp( 16.5, 5.5 ) == 2, '16.5×5.5 is 2-up on 13×19', pps_po_flat_imp( 16.5, 5.5 ) );
ok( pps_po_flat_imp( 11, 8.5 ) == 2, '8.5×11 is 2-up on 13×19' );
ok( pps_po_flat_imp( 21, 7, 27 ) == 1, '21×7 is 1-up on 13×27.5' );

// ── calculator lines, from the metadata the calculators post ──
$peter = array( 'calcType' => 'brochure', 'qty' => 250, 'totalQty' => 250, 'sizeLabel' => '21×7" custom',
    'paper' => array( 'val' => 2.22, 'label' => '100lb Matte Factory Coated (Cardstock)' ),
    'debug' => array( 'pressSheets' => 500, 'imp' => 0.5, 'qty' => 250 ) );
$r = pps_po_calc_rows( $peter );
ok( $r && $r[0]['sheet'] === '13x27.5' && $r[0]['sheets'] === 250, 'an oversize flat orders qty sheets of 13×27.5, not the 500 priced half-up sheets', $r );

$cam = array( 'calcType' => 'brochure', 'qty' => 250, 'paper' => array( 'val' => 0.01, 'label' => '100lb Gloss Text' ),
    'debug' => array( 'pressSheets' => 125, 'imp' => 2, 'qty' => 250 ) );
$r = pps_po_calc_rows( $cam );
ok( $r && $r[0]['sheet'] === '13x19' && $r[0]['sheets'] === 125, 'a flat orders its priced pressSheets on 13×19', $r );

$odd = array( 'calcType' => 'postcard', 'qty' => 100, 'paper' => array( 'val' => 0.02 ), 'debug' => array( 'pressSheets' => 100 / 3, 'imp' => 3, 'qty' => 100 ) );
$r = pps_po_calc_rows( $odd );
ok( $r && $r[0]['sheets'] === 34, 'a fractional sheet count rounds up (33.3 → 34)', $r );

$saddle = array( 'calcType' => 'saddle', 'totalQty' => 250, 'insidePaper' => array( 'val' => 0.005, 'label' => '80lb Matte Text' ),
    'coverMode' => 'separate', 'coverPaper' => array( 'val' => 0.02, 'label' => '100lb Gloss Cover' ),
    'debug' => array( 'parentSheets' => 250, 'imp' => 8, 'totalQty' => 250, 'totalPages' => 16, 'pressSheet' => '13x19' ) );
$r = pps_po_calc_rows( $saddle );
ok( count( $r ) === 2 && $r[0]['role'] === 'inside' && $r[0]['sheets'] === 250, 'a saddle book orders its inside parentSheets', $r );
ok( isset( $r[1] ) && $r[1]['role'] === 'cover' && $r[1]['sheets'] === 63, '250 covers at 4 spreads per 13×19 = 63 sheets (the price counts 32)', $r[1] ?? null );

$coupon = array( 'calcType' => 'coupon', 'totalQty' => 500, 'insidePaper' => array( 'val' => 2.005, 'label' => '100lb Matte Factory Coated' ),
    'coverMode' => 'same', 'coverPaper' => array( 'val' => 2.005 ),
    'debug' => array( 'parentSheets' => 1250, 'imp' => 8, 'totalQty' => 500, 'pressSheet' => '13x19' ) );
$r = pps_po_calc_rows( $coupon );
ok( count( $r ) === 1 && $r[0]['sheets'] === 1250, 'a self-cover book has no separate cover', $r );

$big = array( 'calcType' => 'perfect-bound', 'totalQty' => 150, 'insidePaper' => array( 'val' => 0.005 ), 'coverPaper' => array( 'val' => 0.02 ),
    'debug' => array( 'parentSheets' => 4500, 'imp' => 1, 'totalQty' => 150, 'pressSheet' => '13x27.5' ) );
$r = pps_po_calc_rows( $big );
ok( $r[0]['sheet'] === '13x27.5' && $r[1]['sheets'] === 150 && strpos( $r[1]['basis'], 'check' ) !== false, 'a 1-up book: one cover per sheet, on its large sheet, flagged to check', $r );

ok( pps_po_calc_rows( array( 'calcType' => 'saddle', 'insidePaper' => array( 'val' => 1 ) ) ) === null, 'an old order with no sheet count is not guessed at' );

// ── custom text → suggestion ──
$cat = pps_po_catalog();
$s = pps_po_suggest( 'Project Description: Reorder 82902- 8x8 (8x24 Opened) Trifold 4-4 100lb Cardstock Gloss 1000pcs', $cat );
ok( $s['w'] == 8 && $s['h'] == 24, 'the opened size is preferred over the folded one', $s );
ok( $s['qty'] === 1000, 'quantity read from "1000pcs"', $s );
ok( $s['paper'] === '0.02', '"100lb Cardstock Gloss" suggests 100lb Gloss Cover', $s );
$s = pps_po_suggest( 'Specs: Trifold to 8.5x11, Bifold RightAngle to 8.5x5.5 _ 1000pcs', $cat );
ok( $s['w'] == 8.5 && $s['h'] == 11 && $s['qty'] === 1000 && $s['paper'] === '', 'no paper named → none suggested', $s );
$s = pps_po_suggest( 'Bundle: 2x6 Bookmarks 14pt UVMTT2 Full Color, 4 24x36 Banners, single sided, hems', $cat );
ok( $s['paper'] === '' && $s['w'] == 2 && $s['h'] == 6, 'an unknown 14pt stock is not matched to a lb paper', $s );

// ── plans ──
$plan = array( 'rows' => array(
    array( 'paper' => array( 'val' => '0.02', 'label' => '100lb Gloss Cover' ), 'w' => 8, 'h' => 24, 'qty' => 1000 ),
    array( 'paper' => array( 'label' => '14pt C2S', 'typed' => 1 ), 'sheets' => 120, 'sheet' => '13x19' ),
    array( 'paper' => array( 'val' => '0.01' ), 'w' => 30, 'h' => 40, 'qty' => 10 ),
) );
$r = pps_po_plan_rows( $plan );
ok( count( $r ) === 2, 'a size bigger than any press sheet is not counted', $r );
ok( $r[0]['sheet'] === '13x27.5' && $r[0]['sheets'] === 1000, '8×24 flat: 1-up on 13×27.5 → 1,000 sheets', $r[0] );
ok( $r[1]['sheets'] === 120 && $r[1]['sheet'] === '13x19', 'press sheets entered directly are taken as given', $r[1] );

// ── a whole build against fake orders ──
class FakeMeta { public $k; public $v; function __construct( $k, $v ) { $this->k = $k; $this->v = $v; } function get_data() { return array( 'key' => $this->k, 'value' => $this->v ); } }
class FakeItem {
    public $name; public $meta;
    function __construct( $name, $meta ) { $this->name = $name; $this->meta = $meta; }
    function get_name() { return $this->name; }
    function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
    function get_meta_data() { $o = array(); foreach ( $this->meta as $k => $v ) $o[] = new FakeMeta( $k, $v ); return $o; }
}
class FakeDate { function date( $f ) { return '2026-10-12'; } }
class FakeOrder {
    public $id; public $items; public $name;
    function __construct( $id, $name, $items ) { $this->id = $id; $this->name = $name; $this->items = $items; }
    function get_id() { return $this->id; } function get_status() { return 'processing'; }
    function get_date_created() { return new FakeDate(); }
    function get_billing_first_name() { return $this->name; } function get_billing_last_name() { return ''; } function get_billing_company() { return ''; }
    function get_items() { return $this->items; }
}
$orders = array(
    new FakeOrder( 87253, 'Peter Huang', array( 11 => new FakeItem( 'Square Accordion Brochure', array( '_pps_metadata' => json_encode( $peter ) ) ) ) ),
    new FakeOrder( 87530, 'Matthew Mohondro', array( 12 => new FakeItem( 'Accordion Brochure', array( '_pps_metadata' => json_encode( array_merge( $peter, array( 'sizeLabel' => '20×8" custom' ) ) ) ) ) ) ),
    new FakeOrder( 87513, 'Cameron Fleming', array( 13 => new FakeItem( 'Low Cost Brochure', array( '_pps_metadata' => json_encode( $cam ) ) ) ) ),
    new FakeOrder( 87186, 'Justin Anthony', array( 14 => new FakeItem( 'Small Booklet', array( '_pps_metadata' => json_encode( $saddle ), '_pps_paper_done' => '2026-10-15' ) ) ) ),
    new FakeOrder( 87454, 'Patti Kelsey', array( 15 => new FakeItem( 'Custom Order', array( 'Specs' => 'Trifold to 8.5x11, Bifold RightAngle to 8.5x5.5 _ 1000pcs' ) ) ) ),
    new FakeOrder( 87271, 'Amanda Luckett', array( 16 => new FakeItem( 'Custom Order', array( 'Specs' => '4 24x36 Banners', '_pps_paper_plan' => json_encode( array( 'none' => 1 ) ) ) ) ) ),
    new FakeOrder( 85143, 'Kara Frederick', array( 17 => new FakeItem( 'Custom Order', array( 'Specs' => '8x24 trifold', '_pps_paper_plan' => json_encode( array( 'rows' => array( array( 'paper' => array( 'val' => '2.22', 'label' => 'x' ), 'w' => 8, 'h' => 24, 'qty' => 50 ) ) ) ) ) ) ) ),
);
$b = pps_po_build( $orders );
ok( $b['scanned'] === 7, 'every order scanned', $b['scanned'] );
ok( count( $b['jobs'] ) === 4, 'three calculator jobs and one planned custom job are counted', array_column( $b['jobs'], 'order' ) );
ok( count( $b['needs'] ) === 1 && $b['needs'][0]['order'] === 87454 && $b['needs'][0]['suggest']['qty'] === 1000, 'a custom line with no plan is listed with a suggestion', $b['needs'] );
ok( count( $b['parked'] ) === 2, 'already printed and no-paper lines are left off, and listed', $b['parked'] );
ok( strpos( $b['needs'][0]['text'], 'Specs: Trifold' ) !== false, 'the line text shown to the operator carries its Specs', $b['needs'][0]['text'] );

$s  = array( 'overs_pct' => 5, 'parent_w' => 28, 'parent_h' => 40 );
$g  = pps_po_aggregate( $b['jobs'], $s );
$by = array(); foreach ( $g as $x ) $by[ $x['label'] . '|' . $x['sheet'] ] = $x;
$m  = $by['100lb Matte Factory Coated (Cardstock)|13x27.5'] ?? null;
ok( $m && $m['net'] === 550, 'two oversize jobs and the planned custom one on the same stock add up (250 + 250 + 50)', $m );
ok( $m && $m['total'] === 578 && $m['parents'] === 193 && $m['per_parent'] === 3, '550 + 5% = 578 sheets of 13×27.5 = 193 parents at 3 out', $m );
ok( $m && count( $m['jobs'] ) === 3, 'the group lists the jobs it came from', $m['jobs'] ?? null );
$t = $by['100lb Gloss Text|13x19'] ?? null;
ok( $t && $t['in_stock'] && $t['total'] === 132 && $t['parents'] === 33, 'a stocked paper is still counted (125 + 5% = 132 → 33 parents)', $t );
ok( $g[0]['in_stock'] === false, 'papers to order come first' );

$txt = pps_po_text( $g, $s, 'Oct 15, 2026' );
ok( strpos( $txt, '578 sheets 13×27.5 = 193 parent sheets 28×40 (3 out)' ) !== false, 'the copy-paste line reads as a supply order', $txt );
ok( strpos( $txt, '#87253, #87530, #85143' ) !== false, 'the text names the orders it is for', $txt );
ok( strpos( $txt, '100lb Gloss Text' ) === false, 'stocked papers are left out of the order text by default', $txt );
ok( strpos( pps_po_text( $g, $s, '', true ), '100lb Gloss Text (stocked)' ) !== false, 'and included, marked, when asked' );
ok( strpos( pps_po_text( array(), $s ), 'Nothing to order' ) !== false, 'an empty order says so' );

// ── settings ──
$GLOBALS['opts'][ PPS_PO_SETTINGS ] = array( 'overs_pct' => 99, 'parent_w' => -1 );
$st = pps_po_settings();
ok( $st['overs_pct'] == 50 && $st['parent_w'] == 28 && $st['parent_h'] == 40, 'settings are clamped and defaulted', $st );
unset( $GLOBALS['opts'][ PPS_PO_SETTINGS ] );
ok( pps_po_settings()['overs_pct'] == 5, 'overs default to 5%' );

// ── formatting ──
ok( pps_po_dec( 40 ) === '40' && pps_po_dec( 27.5 ) === '27.5' && pps_po_dec( 2.0 ) === '2' && pps_po_dec( 100 ) === '100', 'numbers keep their zeros before the point (40 is not 4)' );
ok( pps_po_sheet_name( '13×27.50' ) === '13x27.5' && pps_po_sheet_name( '19 x 13' ) === '13x19', 'press sheet names are canonical' );

// ── the screen renders, end to end ──
$GLOBALS['fake_orders'] = $orders; $GLOBALS['can'] = true;
ob_start(); pps_po_render_admin(); $html = ob_get_clean();
ok( strpos( $html, '578 sheets 13×27.5 = 193 parent sheets 28×40' ) !== false, 'the screen carries the copy-paste order', substr( $html, 0, 300 ) );
ok( strpos( $html, 'name="r[0][qty]" value="1000"' ) !== false && strpos( $html, 'Patti Kelsey' ) !== false, 'the custom line is offered with its guessed quantity' );
ok( substr_count( $html, 'value="pps_po_item"' ) >= 6 && strpos( $html, 'nonce-pps_po_item_15' ) !== false, 'every line action is a nonce-protected post' );
ok( strpos( $html, 'Left off' ) !== false && strpos( $html, 'Put back' ) !== false, 'left-off lines can be put back' );
$GLOBALS['can'] = false;
$denied = false; try { pps_po_render_admin(); } catch ( Exception $e ) { $denied = true; }
ok( $denied, 'the screen refuses someone who cannot manage the shop' );

// ── the retired daily email ──
$jh = file_get_contents( __DIR__ . '/pps-job-health.php' );
ok( strpos( $jh, "wp_schedule_event( time() + 300, 'daily', 'pps_job_health_digest' )" ) === false && strpos( $jh, "wp_clear_scheduled_hook( 'pps_job_health_digest' )" ) !== false, 'the daily exceptions email is unscheduled, not scheduled' );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> PAPER ORDER OK ($n checks)\n";
exit( $fail ? 1 : 0 );
