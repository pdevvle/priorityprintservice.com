<?php
/**
 * pps-shipto.php — change where an order goes, and every copy follows.
 *
 * A fake WooCommerce order whose save() fires woocommerce_update_order as WooCommerce does,
 * with stand-ins for the Missive API and Shippo. Checks: the first save only records a
 * baseline; a change made on the order screen and one made from the panel both bring the
 * calculator metadata, the Job Ticket and Order Summary "Ship to" lines in step, drop a held
 * shipping quote, note the order once (who, from, to, what Shippo has), keep a history,
 * rewrite only the panel's own subtasks and post into the order's conversations; Shippo's
 * copy is reported as matching, stale, not imported or unknown; a save that does not move
 * the address does nothing; bad input is refused with the reason; the panel switch is
 * honoured; and the address check ignores case, punctuation and ZIP+4.
 *
 * Run: php tools-shipto-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array(); $GLOBALS['tr'] = array( 'pps_ms_q_501' => array( 'quote' ) ); $GLOBALS['opts'] = array(); $GLOBALS['api'] = array(); $GLOBALS['shippo_to'] = null;
function add_action( $h, $f, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $f; } function do_action( $h, ...$a ) { foreach ( $GLOBALS['hooks'][ $h ] ?? array() as $f ) $f( ...$a ); }
function register_rest_route() {} function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; } function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); } function current_time() { return '2026-10-19 09:30'; } function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); } function wp_json_encode( $v ) { return json_encode( $v ); }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_get_current_user() { return (object) array( 'ID' => $GLOBALS['uid'] ?? 0, 'display_name' => 'Pat (admin)' ); }
// Missive stand-ins
define( 'PPS_MP_TASKS_CACHE', 'pps_missive_tasks' );
function pps_mp_token() { return $GLOBALS['mtoken'] ?? 'tok'; } function pps_mp_org() { return 'org1'; }
function pps_mp_item_map() { return array( 11 => 'task-11' ); } function pps_mp_conv_map() { return array( 'convA' => 501, 'convB' => 999, 'convC' => 501 ); }
function pps_mp_paper_index() { return array(); }
function pps_mp_jobs( $orders ) { $o = $orders[0]; $out = array(); foreach ( $o->get_items() as $id => $it ) $out[] = array( 'item' => $id, 'ticket' => array( array( 'Ship to', $it->get_meta( 'Job Ticket' ) ) ) ); return $out; }
function pps_mp_subtask_for( $j ) { return array( 'title' => 't', 'description' => 'DESC ' . $j['ticket'][0][1], 'due_at' => 0 ); }
function pps_mp_api( $m, $path, $q = array(), $body = null ) { $GLOBALS['api'][] = array( $m, $path, $body ); return array( 'ok' => 1 ); }
// Shippo stand-ins
function pps_ms_shippo_key() { return 'k'; }
function pps_ms_shippo_order( $n ) { return $GLOBALS['shippo_to'] === null ? '' : 'shp1'; }
function pps_ms_api( $m, $path ) { return is_array( $GLOBALS['shippo_to'] ) ? array( 'to_address' => $GLOBALS['shippo_to'] ) : new WP_Error( 'x', 'down' ); }

class Item { public $m; function __construct( $m ) { $this->m = $m; } function get_meta( $k ) { return $this->m[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->m[ $k ] = $v; } function save_meta_data() {} }
class Order {
    public $s = array(); public $m = array(); public $items; public $notes = array(); public $saves = 0;
    function __construct( $s, $items ) { $this->s = $s; $this->items = $items; }
    function get_id() { return 501; } function get_order_number() { return '501'; } function get_items() { return $this->items; }
    function __call( $n, $a ) {
        if ( preg_match( '/^get_shipping_(.+)$/', $n, $x ) ) return $this->s[ $x[1] ] ?? '';
        if ( preg_match( '/^set_shipping_(.+)$/', $n, $x ) ) { $this->s[ $x[1] ] = $a[0]; return; }
        throw new Exception( "no $n" );
    }
    function get_meta( $k ) { return $this->m[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->m[ $k ] = $v; } function save_meta_data() {}
    function add_order_note( $n ) { $this->notes[] = $n; } function save() { $this->saves++; do_action( 'woocommerce_update_order', 501, $this ); }
}
foreach ( array( 'get_shipping_address_1' ) as $_ ) {}
require getenv( 'PPS_ST_PHP' ) ?: __DIR__ . '/pps-shipto.php';

$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }

$meta = array( 'calcType' => 'brochure', 'shipAddr' => array( 'name' => 'Ann Lee', 'street1' => '1 Old Rd', 'city' => 'Mesa', 'state' => 'AZ', 'zip' => '85201', 'extra' => 'kept' ), 'shipState' => 'AZ', 'shipZip' => '85201' );
$items = array(
    11 => new Item( array( '_pps_metadata' => json_encode( $meta ), 'Job Ticket' => "Product: Brochure\nShip to: Ann Lee, 1 Old Rd, Mesa AZ 85201\nShipment: 4 lb", 'Order Summary' => "500 x 8.5x11\nShip to: AZ 85201" ) ),
    12 => new Item( array( 'Specs' => 'custom' ) ),
);
$o = new Order( array( 'first_name' => 'Ann', 'last_name' => 'Lee', 'company' => '', 'address_1' => '1 Old Rd', 'address_2' => '', 'city' => 'Mesa', 'state' => 'AZ', 'postcode' => '85201', 'phone' => '' ), $items );

// 1. the first save only records a baseline
$o->save();
ok( $o->get_meta( '_pps_shipto_hash' ) !== '' && ! $o->notes && ! $GLOBALS['api'], 'the first save records a baseline and changes nothing', $o->notes );

// 2. a save that does not move the address (case, punctuation, ZIP+4) does nothing
$o->s['address_1'] = '1 OLD RD.'; $o->s['postcode'] = '85201-1234'; $o->save();
ok( ! $o->notes, 'case, punctuation and a ZIP+4 are not a change' );

// 3. changed on the order screen
$GLOBALS['uid'] = 7; $GLOBALS['shippo_to'] = array( 'name' => 'Ann Lee', 'street1' => '1 Old Rd', 'city' => 'Mesa', 'state' => 'AZ', 'zip' => '85201' );
$o->s['address_1'] = '22 New Ave'; $o->s['address_2'] = 'Suite 4'; $o->s['city'] = 'Tempe'; $o->s['postcode'] = '85281'; $o->save();
$m = json_decode( $items[11]->get_meta( '_pps_metadata' ), true );
ok( $m['shipAddr']['street1'] === '22 New Ave' && $m['shipAddr']['street2'] === 'Suite 4' && $m['shipAddr']['city'] === 'Tempe' && $m['shipZip'] === '85281' && $m['shipAddr']['extra'] === 'kept', 'the calculator line\'s own address follows (and keeps what else it had)', $m );
ok( strpos( $items[11]->get_meta( 'Job Ticket' ), "Ship to: Ann Lee, 22 New Ave, Suite 4, Tempe AZ 85281\nShipment: 4 lb" ) !== false && strpos( $items[11]->get_meta( 'Job Ticket' ), '1 Old Rd' ) === false, 'the Job Ticket\'s Ship to line is rewritten, the rest left alone', $items[11]->get_meta( 'Job Ticket' ) );
ok( $items[11]->get_meta( 'Order Summary' ) === "500 x 8.5x11\nShip to: AZ 85281", 'the Order Summary\'s Ship to line too', $items[11]->get_meta( 'Order Summary' ) );
ok( $items[12]->m === array( 'Specs' => 'custom' ), 'a custom line with no copies is left alone' );
ok( ! isset( $GLOBALS['tr']['pps_ms_q_501'] ), 'a shipping quote held for the old address is dropped' );
ok( count( $o->notes ) === 1 && strpos( $o->notes[0], 'Ship-to changed by Pat (admin) (order screen).' ) === 0 && strpos( $o->notes[0], 'From: Ann Lee, 1 Old Rd, Mesa AZ 85201' ) !== false && strpos( $o->notes[0], 'To: Ann Lee, 22 New Ave, Suite 4, Tempe AZ 85281' ) !== false, 'one order note: who, from, to', $o->notes );
ok( strpos( $o->notes[0], 'Shippo still shows the OLD address (Ann Lee, 1 Old Rd, Mesa AZ 85201)' ) !== false && strpos( $o->notes[0], 'not re-quoted' ) !== false, 'it says Shippo still has the old address, and that the date was not re-quoted', $o->notes[0] );
$patch = array_values( array_filter( $GLOBALS['api'], function ( $c ) { return $c[0] === 'PATCH'; } ) );
$posts = array_values( array_filter( $GLOBALS['api'], function ( $c ) { return $c[0] === 'POST'; } ) );
ok( count( $patch ) === 1 && $patch[0][1] === 'tasks/task-11' && strpos( $patch[0][2]['tasks']['description'], '22 New Ave' ) !== false && array_keys( $patch[0][2]['tasks'] ) === array( 'description' ), 'the panel\'s own subtask gets the new Job Ticket as its description, nothing else changed', $patch );
ok( count( $posts ) === 2 && $posts[0][2]['posts']['conversation'] === 'convA' && $posts[1][2]['posts']['conversation'] === 'convC' && strpos( $posts[0][2]['posts']['markdown'], '**Now:** Ann Lee, 22 New Ave' ) !== false && strpos( $posts[0][2]['posts']['markdown'], 'Shippo still shows the OLD address' ) !== false, 'a note is posted into each conversation that holds the order (not another order\'s)', $posts );
$h = $o->get_meta( '_pps_shipto_history' );
ok( is_array( $h ) && count( $h ) === 1 && $h[0]['who'] === 'Pat (admin) (order screen)', 'the change is kept in the history' );
$v = pps_st_view( $o );
ok( $v['line'] === 'Ann Lee, 22 New Ave, Suite 4, Tempe AZ 85281' && $v['last']['shippo'] === 'stale' && $v['can_edit'], 'the panel view: the address, the last change, Shippo stale', $v );

// 4. from the panel, Shippo caught up
$GLOBALS['api'] = array(); $o->notes = array(); $GLOBALS['uid'] = 0;
$GLOBALS['shippo_to'] = array( 'name' => 'ANN LEE', 'street1' => '9 Pine St', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85004-1111' );
$r = pps_st_change( $o, array( 'name' => 'Ann Lee', 'street1' => '9 Pine St', 'city' => 'Phoenix', 'state' => 'az', 'zip' => '85004 1111' ), 'Sam (Missive panel)' );
ok( is_array( $r ) && $r['to'] === 'Ann Lee, 9 Pine St, Phoenix AZ 85004-1111' && $r['shippo']['status'] === 'match', 'a panel change: the state upper-cased, ZIP+4 written with a dash, Shippo reported as matching', $r );
ok( count( $o->notes ) === 1 && strpos( $o->notes[0], 'Ship-to changed by Sam (Missive panel).' ) === 0 && strpos( $o->notes[0], 'Shippo has the new address.' ) !== false, 'noted once, with who changed it', $o->notes );
ok( $o->s['address_2'] === '' && $o->s['country'] === 'US', 'fields not given are cleared, the country is set' );
$GLOBALS['api'] = array(); $o->notes = array(); $o->save();
ok( ! $o->notes && ! $GLOBALS['api'], 'saving again without a change does nothing' );

// 5. refusals
foreach ( array(
    array( array( 'street1' => '1 A St', 'city' => 'X', 'state' => 'AZ', 'zip' => '85001' ), 'name or a company' ),
    array( array( 'name' => 'A', 'city' => 'X', 'state' => 'AZ', 'zip' => '85001' ), 'street' ),
    array( array( 'name' => 'A', 'street1' => '1 A St', 'city' => 'X', 'state' => 'Arizona', 'zip' => '85001' ), 'two-letter' ),
    array( array( 'name' => 'A', 'street1' => '1 A St', 'city' => 'X', 'state' => 'AZ', 'zip' => '8500' ), 'ZIP' ),
    array( array( 'name' => 'Ann Lee', 'street1' => '9 Pine St', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85004' ), 'already' ),
) as $c ) { $r = pps_st_change( $o, $c[0], 'x' ); ok( is_wp_error( $r ) && stripos( $r->get_error_message(), $c[1] ) !== false, 'refused: ' . $c[1], is_wp_error( $r ) ? $r->get_error_message() : $r ); }
ok( ! $o->notes, 'a refused change changes nothing' );

// 6. Shippo not imported / unreachable
$GLOBALS['shippo_to'] = null;
ok( pps_st_shippo_status( $o, pps_st_from_order( $o ) )['status'] === 'none' && strpos( pps_st_shippo_words( array( 'status' => 'none' ) ), 'will read the new address' ) !== false, 'an order Shippo has not imported yet will be read with the new address' );
$GLOBALS['shippo_to'] = 'down';
ok( pps_st_shippo_status( $o, pps_st_from_order( $o ) )['status'] === 'unknown', 'Shippo unreachable is said as unknown, not as fine' );

// 7. no Missive token: the site still follows
$GLOBALS['mtoken'] = ''; $GLOBALS['api'] = array();
$r = pps_st_change( $o, array( 'company' => 'Acme', 'street1' => '5 Elm', 'city' => 'Mesa', 'state' => 'AZ', 'zip' => '85203' ), 'Sam (Missive panel)' );
ok( is_array( $r ) && $r['missive'] === array( 'tasks' => 0, 'posts' => 0, 'errors' => array() ) && ! $GLOBALS['api'] && strpos( $items[11]->get_meta( 'Job Ticket' ), 'Ship to: Acme, 5 Elm, Mesa AZ 85203' ) !== false, 'without a Missive token the site copies still follow and Missive is left alone', $r );

// 8. the panel switch, and the wiring
$src = file_get_contents( getenv( 'PPS_ST_PHP' ) ?: __DIR__ . '/pps-shipto.php' );
ok( strpos( $src, "if ( ! get_option( PPS_ST_ALLOW_OPTION, 1 ) ) return new WP_REST_Response( array( 'ok' => false," ) !== false, 'the panel\'s switch (on unless turned off) is honoured' );
ok( strpos( $src, "pps_mp_key_ok( (string) \$req->get_header( 'x_pps_panel_key' ) )" ) !== false, 'the endpoint answers only with the panel key' );
ok( strpos( $src, "add_action( 'woocommerce_update_order', 'pps_st_on_save', 20, 2 );" ) !== false, 'every save of an order is watched, whoever makes it' );
$calc = file_get_contents( __DIR__ . '/pps-calculators.php' );
ok( strpos( $calc, "require_once PPS_CALC_DIR . 'pps-shipto.php';" ) !== false, 'the plugin loads it' );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> SHIP-TO OK ($n checks)\n";
exit( $fail ? 1 : 0 );
