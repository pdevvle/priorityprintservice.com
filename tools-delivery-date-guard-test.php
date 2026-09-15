<?php
/**
 * pps-delivery-date-guard.php, exercised rather than eyeballed.
 *
 * The guard's job is to stop a PPS order carrying a delivery date on a day the
 * shop is shut, and to say so on the order when it moves one. Reviewing it on
 * 2026-09-15 turned up a defect in exactly the half that was meant to prevent a
 * silent correction:
 *
 *   `$order->add_order_note()` was called from
 *   `woocommerce_checkout_create_order_line_item`, where on a first checkout
 *   attempt the order has no ID yet — WC_Checkout::create_order() builds the line
 *   items and only then calls save(). WC_Order::add_order_note() returns 0 without
 *   writing anything when there is no ID, so the note was dropped every time
 *   except on a retry after a failed payment.
 *
 * So the note now waits for `woocommerce_checkout_order_processed` (and the Store
 * API twin), and this file drives the whole sequence against stubs to prove the
 * date moves, the note lands exactly once, and a hopeless closure list leaves the
 * original date alone rather than replacing it with a worse one.
 *
 * It also pins the two pi-edd filter NAMES. A filter that does not exist fails
 * silently, which would leave that section looking like it worked.
 *
 * Run: php tools-delivery-date-guard-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_checks'] = 0;
$GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

// ── the smallest WordPress that will hold this file up ───────────────────────
$GLOBALS['hooks'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][$h][] = array( $cb, $p, $a ); }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][$h][] = array( $cb, $p, $a ); }
function wc_get_order( $id ) { return $GLOBALS['fake_orders'][ $id ] ?? false; }
function fire( $hook, ...$args ) {
    $out = null;
    foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $h ) { $out = call_user_func_array( $h[0], $args ); }
    return $out;
}
function hooked( $hook ) { return $GLOBALS['hooks'][ $hook ] ?? array(); }

// The shop: closed weekends, plus Thanksgiving as a recurring m-d closure.
function pps_is_business_day( DateTime $d ): bool {
    if ( (int) $d->format( 'N' ) >= 6 ) return false;
    if ( ! empty( $GLOBALS['closed_everything'] ) ) return false;
    return ! in_array( $d->format( 'm-d' ), array( '11-26' ), true );
}
function pps_get_calculator_for_product( $id ) {
    return in_array( (int) $id, array( 22754, 33670 ), true ) ? 'calc-preview-test.html' : false;
}
function pps_get_config() { return array( 'pcf' => array( 'shop_timezone' => 'America/Phoenix' ) ); }

// A line item and an order, only as far as this guard touches them.
class FakeItem {
    public $meta = array(); public $pid;
    function __construct( $pid, $meta = array() ) { $this->pid = $pid; $this->meta = $meta; }
    function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function get_product_id() { return $this->pid; }
}
class FakeOrder {
    public $notes = array(); public $meta = array(); public $items = array(); public $saves = 0;
    private $id;
    function __construct( $id = 0, $items = array() ) { $this->id = $id; $this->items = $items; }
    function get_id() { return $this->id; }
    function add_order_note( $n ) { if ( ! $this->id ) return 0; $this->notes[] = $n; return count( $this->notes ); }
    function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    function save() { $this->saves++; return $this->id; }
    function get_items() { return $this->items; }
}

require __DIR__ . '/pps-delivery-date-guard.php';

// ── helpers ──────────────────────────────────────────────────────────────────
echo "── working days and snapping forward ──\n";
ok( 'a Saturday is not a working day',
    ! pps_ddg_is_working_day( new DateTime( '2026-09-19' ) ) );
ok( 'a Tuesday is',
    pps_ddg_is_working_day( new DateTime( '2026-09-15' ) ) );
ok( 'a Sunday snaps to the Monday',
    pps_ddg_snap_forward( new DateTime( '2026-08-30' ) )->format( 'Y-m-d' ) === '2026-08-31',
    '2026-08-30 -> ' . pps_ddg_snap_forward( new DateTime( '2026-08-30' ) )->format( 'Y-m-d' ) );
ok( 'a working day snaps to itself, not the next one',
    pps_ddg_snap_forward( new DateTime( '2026-09-15' ) )->format( 'Y-m-d' ) === '2026-09-15' );
ok( 'it steps over a closure onto the next open day',
    pps_ddg_snap_forward( new DateTime( '2026-11-26' ) )->format( 'Y-m-d' ) === '2026-11-27',
    'Thanksgiving -> ' . pps_ddg_snap_forward( new DateTime( '2026-11-26' ) )->format( 'Y-m-d' ) );
{
    $GLOBALS['closed_everything'] = true;
    $r = pps_ddg_snap_forward( new DateTime( '2026-09-19' ) );
    $GLOBALS['closed_everything'] = false;
    ok( 'a shop that is never open returns null rather than a date a year out', $r === null );
}

// ── the floor ────────────────────────────────────────────────────────────────
echo "\n── flooring the delivery date on a line item ──\n";
$hook = 'woocommerce_checkout_create_order_line_item';
ok( 'the floor is hooked after the main plugin writes at 10',
    ! empty( hooked( $hook ) ) && hooked( $hook )[0][1] === 99,
    'priority ' . ( hooked( $hook )[0][1] ?? '?' ) );

{
    unset( $GLOBALS['pps_ddg_moved'] );
    $item = new FakeItem( 22754, array( '_pps_delivery_date' => '2026-08-30' ) );  // a Sunday
    fire( $hook, $item, 'ck', array( 'pps_metadata' => array() ), new FakeOrder( 0 ) );
    ok( 'a Sunday delivery date is moved to the Monday',
        $item->meta['_pps_delivery_date'] === '2026-08-31', $item->meta['_pps_delivery_date'] );
    ok( 'and the human-readable twin moves with it — one date, not two',
        $item->meta['Estimated Delivery'] === 'Monday, Aug 31, 2026', $item->meta['Estimated Delivery'] );
    ok( 'the move is remembered for the note', count( $GLOBALS['pps_ddg_moved'] ?? array() ) === 1 );
}
{
    unset( $GLOBALS['pps_ddg_moved'] );
    $item = new FakeItem( 22754, array( '_pps_delivery_date' => '2026-09-15' ) );  // a Tuesday
    fire( $hook, $item, 'ck', array( 'pps_metadata' => array() ), new FakeOrder( 0 ) );
    ok( 'a date that is already fine is left exactly as it was',
        $item->meta['_pps_delivery_date'] === '2026-09-15' && ! isset( $item->meta['Estimated Delivery'] ) );
    ok( 'and nothing is queued for the note', empty( $GLOBALS['pps_ddg_moved'] ) );
}
{
    unset( $GLOBALS['pps_ddg_moved'] );
    $item = new FakeItem( 22754, array( '_pps_delivery_date' => '2026-13-45' ) );
    fire( $hook, $item, 'ck', array( 'pps_metadata' => array() ), new FakeOrder( 0 ) );
    ok( 'a date that only looks like a date is refused, not rolled over',
        $item->meta['_pps_delivery_date'] === '2026-13-45', $item->meta['_pps_delivery_date'] );
}
{
    unset( $GLOBALS['pps_ddg_moved'] );
    $item = new FakeItem( 22754, array( '_pps_delivery_date' => '2026-08-30' ) );
    fire( $hook, $item, 'ck', array(), new FakeOrder( 0 ) );   // no pps_metadata: not ours
    ok( 'a line item that is not a calculator line is untouched',
        $item->meta['_pps_delivery_date'] === '2026-08-30' );
}

// ── the note ─────────────────────────────────────────────────────────────────
echo "\n── saying so on the order ──\n";
ok( 'the note is deferred to BOTH checkout paths, not written during line items',
    ! empty( hooked( 'woocommerce_checkout_order_processed' ) )
    && ! empty( hooked( 'woocommerce_store_api_checkout_order_processed' ) ) );

{
    // The regression this was written for: during line-item creation the order has
    // no ID, so a note written there is silently thrown away.
    $idless = new FakeOrder( 0 );
    ok( 'an order with no ID would have swallowed the note — the old bug',
        $idless->add_order_note( 'x' ) === 0 && $idless->notes === array() );
}
{
    unset( $GLOBALS['pps_ddg_moved'] );
    $item = new FakeItem( 22754, array( '_pps_delivery_date' => '2026-08-30' ) );
    fire( $hook, $item, 'ck', array( 'pps_metadata' => array() ), new FakeOrder( 0 ) );

    $order = new FakeOrder( 87105 );
    $GLOBALS['fake_orders'][87105] = $order;
    pps_ddg_note_moved_dates( 87105 );
    ok( 'once the order exists the note lands', count( $order->notes ) === 1 );
    ok( 'and it names both dates',
        strpos( $order->notes[0] ?? '', 'Sunday, Aug 30, 2026' ) !== false
        && strpos( $order->notes[0] ?? '', 'Monday, Aug 31, 2026' ) !== false,
        $order->notes[0] ?? '(none)' );
    ok( 'the order is saved, or the note never reaches the database', $order->saves === 1 );

    // Store API fires its own hook; both must not produce two notes.
    pps_ddg_note_moved_dates( 87105 );
    ok( 'firing the second checkout hook does not duplicate it', count( $order->notes ) === 1 );
}
{
    unset( $GLOBALS['pps_ddg_moved'] );
    $order = new FakeOrder( 99999 );
    $GLOBALS['fake_orders'][99999] = $order;
    pps_ddg_note_moved_dates( 99999 );
    ok( 'an order where nothing moved gets no note at all', $order->notes === array() );
}

// ── pi-edd ───────────────────────────────────────────────────────────────────
echo "\n── keeping pi-edd off our line items ──\n";
ok( 'the storage filter is registered under the name pi-edd actually calls',
    ! empty( hooked( 'pi_edd_disable_product_estimate_storage' ) ) );
ok( 'with the five arguments pi-edd passes it',
    ( hooked( 'pi_edd_disable_product_estimate_storage' )[0][2] ?? 0 ) === 5 );
ok( 'the hide filter is registered under the name pi-edd actually calls',
    ! empty( hooked( 'pisol_edd_hide_estimate_in_order' ) ) );
ok( 'with the two arguments pi-edd passes it',
    ( hooked( 'pisol_edd_hide_estimate_in_order' )[0][2] ?? 0 ) === 2 );

{
    $cb = hooked( 'pi_edd_disable_product_estimate_storage' )[0][0];
    ok( 'storage is refused on a calculator product',
        $cb( false, new FakeItem( 22754 ), 'ck', array(), null ) === true );
    ok( 'and left alone on a product that is not ours',
        $cb( false, new FakeItem( 58317 ), 'ck', array(), null ) === false );
}
{
    $cb = hooked( 'pisol_edd_hide_estimate_in_order' )[0][0];
    ok( 'an order that is entirely ours hides pi-edd\'s estimate',
        $cb( false, new FakeOrder( 1, array( new FakeItem( 22754 ), new FakeItem( 33670 ) ) ) ) === true );
    ok( 'a mixed order keeps it — the non-PPS line has no other source for one',
        $cb( false, new FakeOrder( 1, array( new FakeItem( 22754 ), new FakeItem( 58317 ) ) ) ) === false );
    ok( 'an empty order is left to pi-edd\'s own judgement',
        $cb( false, new FakeOrder( 1, array() ) ) === false );
}
{
    $cb = hooked( 'woocommerce_hidden_order_itemmeta' )[0][0];
    $hidden = $cb( array( 'something_else' ) );
    ok( 'pi-edd\'s unprefixed item keys are hidden in wp-admin',
        in_array( 'pi_item_min_date', $hidden, true ) && in_array( 'estimate_details', $hidden, true ) );
    ok( 'and existing entries survive', in_array( 'something_else', $hidden, true ) );
}

echo "\n{$GLOBALS['t_checks']} checks, {$GLOBALS['t_failed']} failed\n";
echo $GLOBALS['t_failed'] ? ">>> DELIVERY DATE GUARD FAILED\n" : ">>> DELIVERY DATE GUARD OK\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );
