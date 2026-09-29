<?php
/**
 * The Finishing Report (pps-finishing-report.php) against fake open orders, one per way a
 * job carries its finishing: a calculator's `addons`, an older build's summary text, a
 * flat's fold, a quote's free-text "Specs", a WCPA-era option — plus the traps: a paper
 * named "Gloss Coated" is not a coating, and an older summary's artwork line is not a
 * finishing step. Answers "which orders need UV coating" through the same filter the
 * screen uses.
 *
 * Run: php tools-finishing-report-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail !== '' ? "\n       $detail" : '' ) . "\n";
}
function add_action() {} function add_filter() {}
function apply_filters( $h, $v ) { return $v; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }

// pps_order_addons() and pps_clean_text() come from the plugin itself.
$src = file_get_contents( __DIR__ . '/pps-calculators.php' );
function lift( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' ); if ( $at === false ) return '';
    $toks = token_get_all( '<?php ' . substr( $src, $at ) ); $out = ''; $d = 0; $started = false;
    foreach ( $toks as $i => $t ) { if ( $i === 0 ) continue; $s = is_array( $t ) ? $t[1] : $t; $out .= $s;
        if ( $s === '{' || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { $d++; $started = true; }
        elseif ( $s === '}' && --$d === 0 && $started ) return $out; }
    return '';
}
foreach ( array( 'pps_clean_text', 'pps_order_addons' ) as $f ) eval( lift( $src, $f ) );
require __DIR__ . '/pps-finishing-report.php';

class FMeta { private $k; private $v; function __construct( $k, $v ) { $this->k = $k; $this->v = $v; } function get_data() { return array( 'key' => $this->k, 'value' => $this->v ); } }
class FItem {
    public $m; public $name;
    function __construct( $name, $m ) { $this->name = $name; $this->m = $m; }
    function get_meta( $k ) { return $this->m[ $k ] ?? ''; }
    function get_meta_data() { $o = array(); foreach ( $this->m as $k => $v ) $o[] = new FMeta( $k, $v ); return $o; }
    function get_name() { return $this->name; }
    function get_quantity() { return 1; }
}
class FDate { private $d; function __construct( $d ) { $this->d = $d; } function date( $f ) { return $this->d; } }
class FOrder {
    public $id; public $items; public $status;
    function __construct( $id, $items, $status = 'processing' ) { $this->id = $id; $this->items = $items; $this->status = $status; }
    function get_id() { return $this->id; } function get_items() { return $this->items; } function get_status() { return $this->status; }
    function get_date_created() { return new FDate( '2026-09-25' ); }
    function get_billing_first_name() { return 'Cust'; } function get_billing_last_name() { return (string) $this->id; }
}
$GLOBALS['orders'] = array(
    // Brochure from the current calculator: UV coating as an add-on, trifold, on a Gloss Coated paper.
    new FOrder( 101, array( new FItem( 'Low Cost Brochure Printing', array(
        '_pps_metadata' => json_encode( array( 'qty' => 500, 'sizeLabel' => '8.5×11', 'paper' => array( 'label' => '100lb Gloss Coated' ),
            'foldType' => 'trifold', 'foldLabel' => 'Trifold (3 Panel)', 'rushCost' => 40, 'jobName' => 'Gala',
            'addons' => array( 'Coating: UV Gloss (both sides)' ) ) ),
        '_pps_delivery_date' => '2026-10-02' ) ) ) ),
    // Postcard, current calculator, no finishing at all, on a coated paper.
    new FOrder( 102, array( new FItem( 'Postcards', array(
        '_pps_metadata' => json_encode( array( 'qty' => 250, 'paper' => array( 'label' => '16pt Gloss Coated Cover' ), 'foldType' => 'flat', 'addons' => array() ) ),
        '_pps_delivery_date' => '2026-10-01' ) ) ) ),
    // Booklet from a build before `addons`: finishing only in the summary, beside the artwork line.
    new FOrder( 103, array( new FItem( 'Saddle Stitch Booklet', array(
        '_pps_metadata' => json_encode( array( 'sets' => array( array( 'qty' => 100, 'pages' => 16, 'name' => 'Program' ) ), 'coverPaper' => array( 'label' => '100lb Gloss Coated Cover' ) ) ),
        '_pps_summary' => "8.5×11 · 100 · 16pg · Program\nInside: 100lb Gloss Text\nCoating: Aqueous (cover only)\nUpload Art with Order\nPerforation: 1 Perforation Line\nRush: 3 business days",
        '_pps_delivery_date' => '2026-10-06' ) ) ) ),
    // A quote-born Custom Order: the job is free text.
    new FOrder( 104, array( new FItem( 'Custom Order', array(
        'Project' => 'Trade show', 'Specs' => '1,000 rack cards, 4x9, 14pt Gloss Coated, Spot UV front, round corners' ) ) ) ),
    // A WCPA-era on-hold order: an option field.
    new FOrder( 105, array( new FItem( 'Door Hangers', array( 'Paper' => '100lb Gloss Coated Cover', 'Die Cut' => 'Standard hanger' ) ) ), 'on-hold' ),
    // A WCPA order whose options say no finishing, in the words those forms use.
    new FOrder( 107, array( new FItem( 'Flyers', array( 'UV Coating' => 'No UV', 'Folding' => 'None', 'Corners' => 'N/A' ) ) ) ),
    // A quote whose text only mentions coated paper — not a coating.
    new FOrder( 106, array( new FItem( 'Custom Order', array( 'Specs' => '500 flyers on 100lb Gloss Coated Text, full color both sides' ) ) ) ),
);
function wc_get_orders( $args ) { return $GLOBALS['orders']; }

$r    = pps_finishing_report_build();
$byid = array(); foreach ( $r['rows'] as $row ) $byid[ $row['order'] ][] = $row;
$cats = function( $id ) use ( $byid ) { $o = array(); foreach ( $byid[ $id ][0]['steps'] ?? array() as $s ) $o[] = $s['cat'] . ( $s['detail'] !== '' ? ': ' . $s['detail'] : '' ); return $o; };

ok( 'a calculator brochure lists its coating and its fold', $cats( 101 ) === array( 'Coating: UV Gloss (both sides)', 'Fold: Trifold (3 Panel)' ), json_encode( $cats( 101 ) ) );
ok( 'a job with no finishing is not listed, though its paper is "Gloss Coated"', ! isset( $byid[102] ) );
ok( 'an older booklet keeps its summary finishing and drops the artwork line', $cats( 103 ) === array( 'Coating: Aqueous (cover only)', 'Perforation: 1 Perforation Line' ), json_encode( $cats( 103 ) ) );
$q = $byid[104][0]['steps'] ?? array();
ok( 'a quote\'s Specs text is scanned for coating and corners, and marked as text',
    count( $q ) === 2 && $q[0]['cat'] === 'Coating' && stripos( $q[0]['detail'], 'Spot UV' ) !== false && $q[1]['cat'] === 'Round Corner' && $q[0]['source'] === 'text', json_encode( $q ) );
ok( 'a WCPA option names its die cut', ( $byid[105][0]['steps'][0]['cat'] ?? '' ) === 'Die Cut', json_encode( $byid[105] ?? null ) );
ok( 'options answered "No UV" / "None" / "N/A" are not finishing', ! isset( $byid[107] ), json_encode( $byid[107] ?? null ) );
ok( '"Gloss Coated" paper in a quote is not a coating', ! isset( $byid[106] ), json_encode( $byid[106] ?? null ) );
ok( 'soonest delivery first, undated after', array_column( $r['rows'], 'order' ) === array( 101, 103, 105, 104 ), json_encode( array_column( $r['rows'], 'order' ) ) );
ok( 'rush is carried', $byid[101][0]['rush'] === true && $byid[103][0]['rush'] === false );

$uv = array_column( pps_finishing_report_filter( $r['rows'], '', 'uv' ), 'order' );
ok( '"which orders need UV coating": the calculator job and the quote, not the aqueous one', $uv === array( 101, 104 ), json_encode( $uv ) );
$coat = array_column( pps_finishing_report_filter( $r['rows'], 'coating' ), 'order' );
ok( 'the Coating step lists every coated job', $coat === array( 101, 103, 104 ), json_encode( $coat ) );
ok( 'counts per step', pps_finishing_report_counts( $r['rows'] )['Coating'] === 3 && pps_finishing_report_counts( $r['rows'] )['Fold'] === 1 );
ok( 'a word must start a word: "uv" does not match inside another word', pps_finishing_report_filter( array( array( 'steps' => array( array( 'cat' => 'Note', 'detail' => 'fluvial' ) ) ) ), '', 'uv' ) === array() );

ok( 'the module is loaded by the plugin', strpos( $src, "require_once PPS_CALC_DIR . 'pps-finishing-report.php';" ) !== false );
$mod = file_get_contents( __DIR__ . '/pps-finishing-report.php' );
ok( 'read-only: nothing in it writes to an order', ! preg_match( '/->(update_meta_data|add_meta_data|save|add_order_note|set_status)\(/', $mod ) );

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> FINISHING REPORT FAILED\n" : ">>> FINISHING REPORT OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );
