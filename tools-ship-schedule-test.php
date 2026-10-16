<?php
/**
 * pps-ship-schedule.php: open jobs by must-ship date (owner, 2026-10-16).
 * Fake orders shaped like the real ones: a calculator line with mustShipByDate and a
 * guarded _pps_delivery_date, a rush line, a custom quote with only "Requested delivery",
 * a line left off the paper order as printed. Run: php tools-ship-schedule-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array();
function add_action() {} function add_submenu_page() {} function wp_next_scheduled() { return true; }
function apply_filters( $h, $v ) { return $v; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wc_get_orders( $a ) { $GLOBALS['asked'] = $a; return $GLOBALS['orders']; }
require __DIR__ . '/pps-ship-schedule.php';

class I { public $n; public $m; function __construct( $n, $m ) { $this->n = $n; $this->m = $m; } function get_name() { return $this->n; } function get_meta( $k ) { return $this->m[ $k ] ?? ''; } }
class O { public $id; public $c; public $it; public $om;
    function __construct( $id, $c, $it, $om = array() ) { $this->id = $id; $this->c = $c; $this->it = $it; $this->om = $om; }
    function get_id() { return $this->id; } function get_status() { return 'processing'; } function get_items() { return $this->it; }
    function get_billing_first_name() { return $this->c; } function get_billing_last_name() { return ''; } function get_billing_company() { return ''; }
    function get_meta( $k ) { return $this->om[ $k ] ?? ''; } }

$GLOBALS['orders'] = array(
    new O( 87513, 'Cameron Fleming', array( new I( 'Low Cost Brochure', array( '_pps_metadata' => json_encode( array( 'qty' => 250, 'sizeLabel' => '16.5×5.5" custom', 'mustShipByDate' => '2026-10-20', 'estimatedDeliveryDate' => '2026-10-23' ) ), '_pps_delivery_date' => '2026-10-23' ) ) ) ),
    new O( 87485, 'Delaney Small', array( new I( 'Small Booklet', array( '_pps_metadata' => json_encode( array( 'totalQty' => 250, 'mustShipByDate' => '2026-10-16', 'rushCost' => 40 ) ), '_pps_delivery_date' => '2026-10-21' ) ) ) ),
    new O( 87455, 'Bahan Sadegh', array( new I( 'Custom Order', array( 'Requested delivery' => '2026-10-17' ) ) ) ),
    new O( 87240, 'Tom Merritt', array( new I( 'Low Cost Brochure', array( '_pps_metadata' => json_encode( array( 'mustShipByDate' => '2026-09-29' ) ), '_pps_paper_done' => '2026-10-15' ) ) ) ),
    new O( 87541, 'Sherry Baker', array( new I( 'Custom Order', array() ) ), array( '_pps_requested_date' => '2026-10-30' ) ),
);
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

$s = pps_ship_schedule_build();
$r = $s['rows'];
ok( $GLOBALS['asked']['status'] === array( 'processing', 'on-hold' ), 'only open orders are read' );
ok( count( $r ) === 5, 'one row per line', count( $r ) );
ok( array_column( $r, 'order' ) === array( 87240, 87485, 87455, 87513, 87541 ), 'soonest must-ship first (a late one at the top), undated custom by delivery', array_column( $r, 'order' ) );
ok( $r[1]['must_ship'] === '2026-10-16' && $r[1]['rush'] === true && $r[1]['delivery'] === '2026-10-21', 'must-ship, delivery and rush are read from the line' , $r[1] );
ok( $r[2]['source'] === 'custom' && $r[2]['must_ship'] === '' && $r[2]['delivery'] === '2026-10-17', 'a custom quote is listed by its requested delivery', $r[2] );
ok( $r[4]['delivery'] === '2026-10-30', 'an order-level requested date is used when the line has none', $r[4] );
ok( $r[0]['printed'] === true, 'a job left off the paper order as printed is shown as printed' );
ok( $r[3]['size'] === '16.5×5.5" custom' && $r[3]['qty'] === '250', 'size and quantity ride along' );
ok( isset( $GLOBALS['opts'][ PPS_SHIP_SCHEDULE_OPTION ]['rows'] ), 'the list is stored where wp_get_option can read it' );
ok( pps_ship_ymd( 'October 16, 2026' ) === '2026-10-16' && pps_ship_ymd( '2026-13-45' ) === '' && pps_ship_ymd( '' ) === '', 'dates are normalised; a bad one is refused' );
echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> SHIP SCHEDULE OK ($n checks)\n";
exit( $fail ? 1 : 0 );
