<?php
/**
 * pps-missive-ship.php: Shippo labels from the Missive panel (owner, 2026-10-18).
 *
 * The real file against a fake Shippo (wp_remote_request) and fake orders. Checks: the page
 * token is good for one order only; the order's ship-to, dates, weight and cartons; the boxes
 * the page opens with; rates — one shipment per box, only services every box can use, summed,
 * every active account named (a customer's own account marked), arrival by business days and
 * on time / late against the promised delivery, routine carrier noise dropped; bad input
 * refused without calling Shippo; buying — off by default and refused without a Shippo call,
 * only rates from this order's own quote, one purchase per quote, a second label only when
 * confirmed, attached to the order's Shippo order, every label recorded as bought, a partial
 * failure kept, a private note, the status never changed. Then the page in a browser
 * (tools-missive-ship-page.mjs) and the panel's Ship button.
 *
 * Run: php tools-missive-ship-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['opts'] = array(); $GLOBALS['tr'] = array(); $GLOBALS['http'] = array();
function add_action() {} function add_filter() {} function add_submenu_page() {} function register_rest_route() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); return true; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
class WP_Error { public $c; public $m; function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function pps_get_config() { return array( 'pcf' => array( 'shippo_api_token' => 'shippo_live_TESTKEY' ) ); }
function pps_mp_key() { return 'PANELKEY'; }
function pps_mp_today() { return $GLOBALS['today'] ?? '2026-10-19'; }
function pps_shop_timezone() { return 'America/Phoenix'; }
function pps_mp_jobs( array $orders ) { $o = $orders[0]; return $o->jobs; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }

// ── a fake Shippo ──
$GLOBALS['shippo'] = array( 'shipments' => array(), 'transactions' => array(), 'fail_txn_after' => 0 );
function fake_rate( $id, $acct, $prov, $tok, $name, $amt, $days, $extra = array() ) {
    return $extra + array( 'object_id' => $id, 'carrier_account' => $acct, 'provider' => $prov, 'amount' => (string) $amt, 'currency' => 'USD', 'estimated_days' => $days,
        'arrives_by' => null, 'duration_terms' => '', 'attributes' => array(), 'messages' => array(), 'servicelevel' => array( 'token' => $tok, 'name' => $name, 'display_name' => null, 'terms' => '' ) );
}
function wp_remote_request( $url, $args ) {
    $GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
    $S = &$GLOBALS['shippo'];
    if ( ( $args['headers']['Authorization'] ?? '' ) !== 'ShippoToken shippo_live_TESTKEY' ) return array( 'code' => 401, 'body' => '{}' );
    $path = parse_url( $url, PHP_URL_PATH ); parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
    $body = json_decode( (string) ( $args['body'] ?? 'null' ), true );
    if ( $path === '/carrier_accounts/' ) return array( 'code' => 200, 'body' => json_encode( array( 'results' => array(
        array( 'object_id' => 'acc-usps', 'carrier_name' => 'USPS', 'active' => true, 'is_shippo_account' => true ),
        array( 'object_id' => 'acc-ups', 'carrier_name' => 'UPS', 'active' => true, 'is_shippo_account' => true ),
        array( 'object_id' => 'acc-whph', 'carrier_name' => 'FedEx', 'active' => true, 'is_shippo_account' => false, 'metadata' => 'Work Hard Play Hard Dec 24', 'account_id' => '228964051' ),
        array( 'object_id' => 'acc-old', 'carrier_name' => 'FedEx', 'active' => false, 'is_shippo_account' => false, 'metadata' => 'Old customer' ),
    ) ) ) );
    if ( $path === '/user-parcel-templates' ) return array( 'code' => 200, 'body' => json_encode( array( 'results' => array(
        array( 'object_id' => 'box-arma', 'name' => 'Armadillo', 'length' => '14', 'width' => '14', 'height' => '14', 'distance_unit' => 'in', 'weight' => '49', 'weight_unit' => 'lb' ),
        array( 'object_id' => 'box-small', 'name' => '12x9x7', 'length' => '12', 'width' => '9', 'height' => '7', 'distance_unit' => 'in', 'weight' => '9', 'weight_unit' => 'lb' ),
        array( 'object_id' => 'box-big', 'name' => '13x13x13', 'length' => '13', 'width' => '13', 'height' => '13', 'distance_unit' => 'in', 'weight' => '40', 'weight_unit' => 'lb' ),
    ) ) ) );
    if ( $path === '/orders/' ) {
        $page = (int) ( $q['page'] ?? 1 );
        $res = $page === 1 ? array( array( 'object_id' => 'so-1', 'order_number' => '87001' ) ) : array( array( 'object_id' => 'so-armadillo', 'order_number' => '87551' ) );
        return array( 'code' => 200, 'body' => json_encode( array( 'results' => $res, 'next' => $page < 2 ? 'more' : null ) ) );
    }
    if ( $path === '/shipments/' ) {
        $n = count( $S['shipments'] ) + 1; $S['shipments'][] = $body;
        $heavy = (float) $body['parcels'][0]['weight'] > 30;
        $rates = array(
            fake_rate( "r$n-ga", 'acc-usps', 'USPS', 'usps_ground_advantage', 'Ground Advantage', 9.10 + $n, 5 ),
            fake_rate( "r$n-upsg", 'acc-ups', 'UPS', 'ups_ground', 'Ground', 12.40, 3, array( 'attributes' => array( 'BESTVALUE' ), 'included_insurance_price' => isset( $body['extra']['insurance'] ) ? '3.64' : null ) ),
            fake_rate( "r$n-fxo", 'acc-ups', 'UPS', 'ups_next_day_air', 'Next Day Air', 90.00, 0 ),
            fake_rate( "r$n-fx2", 'acc-whph', 'FedEx', 'fedex_2_day', '2Day®', 31.00, 2, array( 'servicelevel' => array( 'token' => 'fedex_2_day', 'name' => '2Day®', 'display_name' => null, 'terms' => 'ONERATE' ) ) ),
        );
        if ( ! $heavy ) $rates[] = fake_rate( "r$n-pm", 'acc-usps', 'USPS', 'usps_priority', 'Priority Mail', 14.00, 2 );
        return array( 'code' => 201, 'body' => json_encode( array( 'object_id' => "shp-$n", 'status' => 'SUCCESS', 'rates' => $rates, 'messages' => array(
            array( 'source' => 'Shippo', 'text' => 'Carrier account FASTWAY_SHIPPO_TIER_1 doesn\'t support one or more shipment options' ),
            array( 'source' => 'UPS', 'text' => 'RatedShipmentAlert: Your invoice may vary from the displayed reference rates' ),
            array( 'source' => 'UPS', 'text' => 'Request failed due to missing authorization. Please reconnect your carrier account.' ),
        ) ) ) );
    }
    if ( $path === '/transactions/' ) {
        $S['transactions'][] = $body; $n = count( $S['transactions'] );
        if ( $S['fail_txn_after'] && $n > $S['fail_txn_after'] ) return array( 'code' => 201, 'body' => json_encode( array( 'status' => 'ERROR', 'messages' => array( array( 'text' => 'The address could not be validated' ) ) ) ) );
        return array( 'code' => 201, 'body' => json_encode( array( 'status' => 'SUCCESS', 'object_id' => "txn-$n", 'tracking_number' => "1ZTEST$n",
            'tracking_url_provider' => "https://ups.example/track/1ZTEST$n", 'label_url' => "https://deliver.goshippo.com/txn-$n.pdf" ) ) );
    }
    return array( 'code' => 404, 'body' => '{}' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

class I { public $n; function __construct( $n ) { $this->n = $n; } function get_name() { return $this->n; } }
class O {
    public $id; public $a; public $meta; public $jobs; public $notes = array(); public $saved = 0; public $st = 'processing';
    function __construct( $id, $a, $meta, $jobs, $items ) { $this->id = $id; $this->a = $a; $this->meta = $meta; $this->jobs = $jobs; $this->items = $items; }
    function __call( $m, $x ) { if ( preg_match( '/^get_(shipping|billing)_(\w+)$/', $m, $g ) ) return $this->a[ $g[1] ][ $g[2] ] ?? ''; throw new Exception( "no $m" ); }
    function get_id() { return $this->id; } function get_order_number() { return (string) $this->id; } function get_status() { return $this->st; }
    function get_items() { return $this->items; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
    function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() { $this->saved++; }
    function add_order_note( $t, $c = 0 ) { $this->notes[] = array( $t, $c ); return 1; }
    function update_status() { throw new Exception( 'the status must never change' ); }
}
$arma_addr = array( 'shipping' => array( 'first_name' => 'Angela', 'last_name' => 'Waggoner', 'address_1' => '900 Henderson Ave #610', 'city' => 'Houston', 'state' => 'TX', 'postcode' => '77058', 'country' => 'US', 'phone' => '' ),
                    'billing' => array( 'first_name' => 'Theron', 'last_name' => 'A', 'phone' => '3188015899', 'email' => 'theron@example.com', 'address_1' => '1 Bill St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701' ) );
$GLOBALS['orders'] = array(
    87551 => new O( 87551, $arma_addr, array( '_pps_est_weight_lb' => 64.4, '_pps_est_cartons' => 2 ),
        array( array( 'must_ship' => '2026-10-22', 'delivery' => '2026-10-29', 'rush' => false ) ), array( new I( 'Armadillo 4-4 8p Booklets' ) ) ),
    87600 => new O( 87600, array( 'shipping' => array(), 'billing' => array( 'first_name' => 'Bo', 'last_name' => 'B', 'address_1' => '5 Main', 'city' => 'Mesa', 'state' => 'AZ', 'postcode' => '85201', 'phone' => '480', 'email' => 'b@x' ) ),
        array(), array( array( 'must_ship' => '2026-10-20', 'delivery' => '2026-10-21', 'rush' => true ), array( 'must_ship' => '2026-10-19', 'delivery' => '2026-10-22', 'rush' => false ) ), array( new I( 'Flyers' ) ) ),
);
require __DIR__ . '/pps-missive-ship.php';
$fail = 0; $n = 0;
function ok( $c, $w, $g = null ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $w" . ( $g !== null ? ' — ' . json_encode( $g ) : '' ) . "\n"; } }
function calls( $path ) { return array_values( array_filter( $GLOBALS['http'], function ( $h ) use ( $path ) { return strpos( $h['url'], 'https://api.goshippo.com/' . $path ) === 0; } ) ); }

// ── the page's token ──
$t = pps_ms_token( 87551 );
ok( strlen( $t ) === 64 && pps_ms_token_ok( 87551, $t ), 'the page token is good for its order' );
ok( ! pps_ms_token_ok( 87600, $t ) && ! pps_ms_token_ok( 87551, '' ) && ! pps_ms_token_ok( 87551, 'PANELKEY' ), 'and for no other order, and the panel key is not it' );
ok( $t !== pps_mpi_token_like( 87551 ), 'it differs from the imposition token for the same order' );
function pps_mpi_token_like( $id ) { return hash_hmac( 'sha256', 'pps-impose|' . $id, 'PANELKEY' ); }

// ── what the order says ──
$v = pps_ms_view( $GLOBALS['orders'][87551] );
ok( $v['to']['name'] === 'Angela Waggoner' && $v['to']['street1'] === '900 Henderson Ave #610' && $v['to']['zip'] === '77058' && $v['to']['source'] === 'shipping', 'the ship-to is the order\'s shipping address', $v['to'] );
ok( $v['to']['phone'] === '3188015899' && $v['to']['email'] === 'theron@example.com', 'with the billing phone and email when the shipping address has none' );
ok( $v['must_ship'] === '2026-10-22' && $v['delivery'] === '2026-10-29' && $v['est_weight'] === 64.4 && $v['est_cartons'] === 2, 'must-ship, promised delivery, and the calculator\'s weight and cartons' );
ok( count( $v['suggest'] ) === 1 && $v['suggest'][0]['count'] === 2 && $v['suggest'][0]['box'] === 'box-arma' && $v['suggest'][0]['weight'] === 32.2 && $v['suggest'][0]['l'] === 14.0, 'it opens with "Armadillo box × 2", the weight split between them', $v['suggest'] );
$v2 = pps_ms_view( $GLOBALS['orders'][87600] );
ok( $v2['to']['source'] === 'billing' && $v2['to']['city'] === 'Mesa', 'an order with no shipping address falls back to billing, and says so' );
ok( $v2['must_ship'] === '2026-10-19' && $v2['delivery'] === '2026-10-21' && $v2['rush'] === true, 'several jobs: the earliest must-ship and delivery, rush if any is' );
ok( count( $v2['suggest'] ) === 1 && $v2['suggest'][0]['box'] === 'box-small', 'no estimate: one box, the smallest saved one', $v2['suggest'] );
$sb = pps_ms_suggest_boxes( 30, 1, $v['boxes'], array( 'Flyers' ) );
ok( $sb[0]['box'] === 'box-big' && $sb[0]['weight'] === 30.0, 'a 30 lb carton goes in the smallest saved box rated for it', $sb );
ok( pps_ms_suggest_boxes( 900, 40, $v['boxes'], array() )[0]['count'] === PPS_MS_MAX_BOXES, 'never more than ' . PPS_MS_MAX_BOXES . ' boxes' );

// ── the Shippo token stays on the server ──
$GLOBALS['http'] = array();
$html = pps_ms_page_html( $GLOBALS['orders'][87551], 'https://x/rates', 'https://x/buy' );
ok( strpos( $html, 'TESTKEY' ) === false && strpos( $html, 'PANELKEY' ) === false && strpos( $html, $t ) !== false, 'the page carries its order token — never the Shippo token or the panel key' );
ok( strpos( $html, 'innerHTML' ) === false && strpos( $html, 'textContent' ) !== false && substr_count( strtolower( $html ), '</script>' ) === 1 && strpos( $html, 'data-cfasync="false"' ) !== false, 'it writes text, never HTML, in one script Rocket Loader leaves alone' );

// ── rates ──
$GLOBALS['http'] = array();
$boxes = array( array( 'l' => 14, 'w' => 14, 'h' => 14, 'weight' => 32.2, 'count' => 2 ) );
$q = pps_ms_rates( 87551, $v['to'], $boxes, '2026-10-21' );
$ship = calls( 'shipments/' );
$auth = array_unique( array_map( function ( $h ) { return $h['args']['headers']['Authorization']; }, $GLOBALS['http'] ) );
ok( $auth === array( 'ShippoToken shippo_live_TESTKEY' ) && ! array_filter( $GLOBALS['http'], function ( $h ) { return strpos( $h['url'], 'https://api.goshippo.com/' ) !== 0 || strpos( $h['url'], 'KEY' ) !== false; } ), 'the token goes only in the Authorization header, only to Shippo', $auth );
ok( ! is_wp_error( $q ) && count( $ship ) === 1 && $q['boxes'] === 2, 'two identical boxes ("× 2") are quoted with ONE shipment, for two labels', is_wp_error( $q ) ? $q->m : count( $ship ) );
$b0 = json_decode( $ship[0]['args']['body'], true );
ok( $b0['address_from']['street1'] === '2941 W Eastman Dr' && $b0['address_from']['zip'] === '85086' && $b0['address_from']['name'] === 'Priority Print Service' && $b0['address_from']['email'] === 'Office@priorityprintservice.com' && $b0['address_return']['street1'] !== $b0['address_from']['street1'], 'it ships from the shop (2941 W Eastman Dr, 85086, Office@ for USPS)', $b0['address_from'] );
ok( $b0['address_return']['street1'] === '8380 W Emile Zola Ave' && $b0['address_return']['street2'] === '#5224' && $b0['address_return']['city'] === 'Peoria' && $b0['address_return']['zip'] === '85381' && $b0['address_return']['country'] === 'US' && $b0['address_return']['phone'] !== '', 'the label return address is 8380 W Emile Zola Ave #5224, Peoria 85381 (owner 2026-10-19)', $b0['address_return'] );
$sv = get_option( PPS_MS_OPTION, array() );
update_option( PPS_MS_OPTION, array_merge( (array) $sv, array( 'return' => array( 'street1' => '', 'zip' => '' ) ) ) );
ok( pps_ms_settings()['return']['street1'] === pps_ms_settings()['from']['street1'], 'a cleared return address falls back to the ship-from' );
update_option( PPS_MS_OPTION, $sv );
ok( $b0['address_to']['name'] === 'Angela Waggoner' && $b0['address_to']['country'] === 'US' && $b0['parcels'] === array( array( 'length' => '14', 'width' => '14', 'height' => '14', 'distance_unit' => 'in', 'weight' => '32.2', 'mass_unit' => 'lb' ) ) && $b0['metadata'] === 'Order 87551', 'to the order\'s address, the box as entered, named for the order', $b0 );
ok( $b0['shipment_date'] === '2026-10-21T19:00:00Z' && $b0['async'] === false, 'on the chosen ship date (noon in Phoenix)', $b0['shipment_date'] );
ok( ! isset( $b0['carrier_accounts'] ) && ! isset( $b0['extra'] ), 'every active account is asked (owner: "Every active account"); no extras unless chosen' );
$byid = array(); foreach ( $q['options'] as $o ) $byid[ $o['carrier'] . ' ' . $o['service'] ] = $o;
ok( array_keys( $byid ) === array( 'USPS Ground Advantage', 'UPS Ground', 'FedEx 2Day® (One Rate)', 'UPS Next Day Air' ), 'cheapest first; Priority Mail, not offered for a heavy box, is not offered', array_keys( $byid ) );
ok( $byid['USPS Ground Advantage']['amount'] === 20.2 && $byid['UPS Ground']['amount'] === 24.8 && $byid['UPS Ground']['each'] === 12.4, 'the price is per box × boxes, with the price each shown', array( $byid['USPS Ground Advantage'], $byid['UPS Ground']['each'] ) );
ok( $byid['UPS Ground']['account'] === 'Shippo rates' && $byid['UPS Ground']['own_account'] === false, 'Shippo\'s own accounts say so' );
ok( $byid['FedEx 2Day® (One Rate)']['account'] === 'Work Hard Play Hard Dec 24' && $byid['FedEx 2Day® (One Rate)']['own_account'] === true, 'another account is named on its rate and marked, so it is never picked by accident' );
ok( $byid['UPS Ground']['arrives'] === '2026-10-26' && $byid['UPS Ground']['on_time'] === true, 'Wed + 3 business days arrives Mon Oct 26 — on time for the promised Oct 29', $byid['UPS Ground'] );
ok( $byid['USPS Ground Advantage']['arrives'] === '2026-10-28' && $byid['FedEx 2Day® (One Rate)']['arrives'] === '2026-10-23', 'weekends are skipped', array( $byid['USPS Ground Advantage']['arrives'] ) );
ok( $byid['UPS Next Day Air']['days'] === 1 && $byid['UPS Next Day Air']['arrives'] === '2026-10-22', 'a carrier\'s "0 days" (FedEx overnight) arrives the next business day, not the ship day', $byid['UPS Next Day Air'] );
// FedEx's express services answer one day short (live quote 2026-10-19); their promise is used
$td = array( pps_ms_transit_days( 'FedEx', 'fedex_priority_overnight', 0 ), pps_ms_transit_days( 'FedEx', 'fedex_2_day', 1 ), pps_ms_transit_days( 'FedEx', 'fedex_2_day_am', 1 ), pps_ms_transit_days( 'FedEx', 'fedex_express_saver', 2 ),
             pps_ms_transit_days( 'FedEx', 'fedex_ground', 3 ), pps_ms_transit_days( 'UPS', 'ups_second_day_air', 2 ), pps_ms_transit_days( 'UPS', 'ups_next_day_air', 1 ), pps_ms_transit_days( 'USPS', 'usps_ground_advantage', 3 ), pps_ms_transit_days( 'UPS', 'ups_ground', null ) );
ok( $td === array( 1, 2, 2, 3, 3, 2, 1, 3, null ), 'business days: FedEx overnight 1, 2Day 2, Express Saver 3 (their promise); FedEx Ground, UPS and USPS as the carrier says; none when none given', $td );
ok( $byid['UPS Ground']['tags'] === array(), 'a single box\'s "best value" tag is not claimed for a set of boxes' );
ok( count( $q['notes'] ) === 1 && strpos( $q['notes'][0], 'A UPS account needs reconnecting in Shippo' ) === 0, 'routine carrier messages are dropped; one a person should act on is kept', $q['notes'] );
$stored = $GLOBALS['tr']['pps_ms_q_87551'];
$sg = $stored['options'][ $byid['UPS Ground']['id'] ];
ok( $stored['qid'] === $q['quote'] && count( $stored['options'] ) === 4 && $sg['groups'][0]['count'] === 2 && $sg['groups'][0]['rate'] === array( 'r1-upsg', 12.4 ) && $sg['account_id'] === 'acc-ups', 'the quote keeps, on the server, each option\'s box groups and their rate', $sg );
ok( strpos( json_encode( $q ), 'r1-upsg' ) === false, 'the browser never sees a rate id — only this quote\'s option ids' );
// boxes of different sizes: one shipment each, only services every size can use
$GLOBALS['http'] = array();
$mix = pps_ms_rates( 87551, $v['to'], array( $boxes[0], array( 'l' => 12, 'w' => 9, 'h' => 7, 'weight' => 5 ), array( 'l' => 14, 'w' => 14, 'h' => 14, 'weight' => 32.2 ) ) );
$mb = array(); foreach ( $mix['options'] as $o ) $mb[ $o['carrier'] . ' ' . $o['service'] ] = $o;
ok( count( calls( 'shipments/' ) ) === 2 && $mix['boxes'] === 4, 'two sizes, four boxes: two shipments (the third row joins the first, same size)', count( calls( 'shipments/' ) ) );
ok( ! isset( $mb['USPS Priority Mail'] ) && $mb['UPS Ground']['amount'] === 49.6 && $mb['UPS Ground']['each'] === null, 'only services every size can use, summed over all four (no single "each" price)', $mb['UPS Ground'] );
// signature and insurance
$GLOBALS['http'] = array();
$qx = pps_ms_rates( 87551, $v['to'], $boxes, '2026-10-21', array( 'signature' => 'adult', 'insure' => '250' ) );
$bx = json_decode( calls( 'shipments/' )[0]['args']['body'], true );
ok( $bx['extra'] === array( 'signature_confirmation' => 'ADULT', 'insurance' => array( 'amount' => '250.00', 'currency' => 'USD', 'content' => 'Printed materials' ) ), 'a signature and insurance go to Shippo with the shipment, so the rates include them', $bx['extra'] ?? null );
ok( $qx['extra'] === array( 'signature' => 'ADULT', 'insure' => 250.0 ), 'and the quote says what it was priced with', $qx['extra'] );
$ups = null; foreach ( $qx['options'] as $x ) if ( $x['service'] === 'Ground' && $x['carrier'] === 'UPS' ) $ups = $x;
ok( $ups['insurance'] === 7.28, 'the insurance Shippo includes in a rate is shown, for every box ($3.64 × 2)', $ups );
$GLOBALS['http'] = array();
ok( is_wp_error( pps_ms_rates( 87551, $v['to'], $boxes, '', array( 'signature' => 'notary' ) ) ) && is_wp_error( pps_ms_rates( 87551, $v['to'], $boxes, '', array( 'insure' => -5 ) ) ) && ! calls( 'shipments/' ), 'an unknown signature or a negative value is refused without calling Shippo' );
ok( is_wp_error( pps_ms_rates( 87551, $v['to'], array( $boxes[0] + array(), array( 'l' => 1, 'w' => 1, 'h' => 1, 'weight' => 1, 'count' => PPS_MS_MAX_BOXES ) ) ) ), 'at most ' . PPS_MS_MAX_BOXES . ' labels in one run' );
$q = pps_ms_rates( 87551, $v['to'], $boxes, '2026-10-21' );   // the quote bought below
$byid = array(); foreach ( $q['options'] as $o ) $byid[ $o['carrier'] . ' ' . $o['service'] ] = $o;

// ── bad input never reaches Shippo ──
$GLOBALS['http'] = array();
$e1 = pps_ms_rates( 87551, array( 'name' => 'A', 'street1' => '1 Rue', 'city' => 'Paris', 'state' => 'IF', 'zip' => '75001', 'country' => 'FR' ), $boxes );
$e2 = pps_ms_rates( 87551, array( 'name' => 'A', 'street1' => '', 'city' => 'Mesa', 'state' => 'AZ', 'zip' => '85201' ), $boxes );
$e3 = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 0, 'w' => 1, 'h' => 1, 'weight' => 1 ) ) );
$e4 = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 1, 'w' => 1, 'h' => 1, 'weight' => 900 ) ) );
$e5 = pps_ms_rates( 99999, $v['to'], $boxes );
$e6 = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 1, 'w' => 1, 'h' => 1, 'weight' => 1, 'count' => 0 ) ) );
ok( is_wp_error( $e1 ) && strpos( $e1->m, 'customs' ) !== false, 'an international address is sent to Shippo itself (customs forms)', $e1 );
ok( is_wp_error( $e2 ) && is_wp_error( $e3 ) && is_wp_error( $e4 ) && is_wp_error( $e5 ) && is_wp_error( $e6 ), 'a missing street, a box with no size, a 900 lb box, a count of 0 and an unknown order are refused' );
ok( ! calls( 'shipments/' ), 'without calling Shippo' );

// ── buying ──
$o = $GLOBALS['orders'][87551];
$GLOBALS['http'] = array();
$r = pps_ms_buy( 87551, $q['quote'], $byid['UPS Ground']['id'] );
ok( is_wp_error( $r ) && strpos( $r->m, 'switched off' ) !== false && ! calls( 'transactions/' ), 'buying is off by default and refused without calling Shippo', $r );
update_option( PPS_MS_OPTION, array( 'from' => pps_ms_defaults()['from'], 'allow_buy' => true, 'label_type' => 'PDF_4x6' ) );
ok( is_wp_error( pps_ms_buy( 87551, 'not-the-quote', $byid['UPS Ground']['id'] ) ), 'a quote id that is not this order\'s current one is refused' );
ok( is_wp_error( pps_ms_buy( 87551, $q['quote'], 'r1-upsg' ) ) && ! calls( 'transactions/' ), 'a raw rate id is refused: only options from this order\'s quote can be bought' );
ok( is_wp_error( pps_ms_buy( 87600, $q['quote'], $byid['UPS Ground']['id'] ) ), 'another order\'s quote buys nothing here' );
$quoted = $GLOBALS['tr']['pps_ms_q_87551']['options'][ $byid['UPS Ground']['id'] ]['groups'][0]['rate'][0];
$r = pps_ms_buy( 87551, $q['quote'], $byid['UPS Ground']['id'] );
$tx = calls( 'transactions/' ); $rs = calls( 'shipments/' );
ok( ! is_wp_error( $r ) && count( $r['labels'] ) === 2 && count( $tx ) === 2, 'two boxes like this: two labels to the same address in one purchase', is_wp_error( $r ) ? $r->m : $r );
$t0 = json_decode( $tx[0]['args']['body'], true ); $t1 = json_decode( $tx[1]['args']['body'], true ); $s1 = json_decode( $rs[0]['args']['body'], true );
ok( count( $rs ) === 1 && $s1['carrier_accounts'] === array( 'acc-ups' ) && $s1['parcels'][0]['weight'] === '32.2' && $s1['address_to']['zip'] === '77058', 'a rate buys one label, so the second box gets its own shipment — the same box and address, asked of the chosen account only', $s1 );
ok( $t0['rate'] === $quoted && $t1['rate'] !== $quoted && preg_match( '/-upsg$/', $t1['rate'] ) && $t0['label_file_type'] === 'PDF_4x6' && $t0['async'] === false, 'each box buys its own rate, in the label format set', array( $t0, $t1 ) );
ok( $t0['order'] === 'so-armadillo' && $t1['order'] === 'so-armadillo' && $t0['metadata'] === 'Order 87551 · box 1 of 2' && $t1['metadata'] === 'Order 87551 · box 2 of 2' && $r['attached'] === true, 'both attached to the order\'s Shippo order (found on the second page), each named "box n of 2"', array( $t0, $t1 ) );
$sh = $o->meta['_pps_shipments'];
ok( count( $sh ) === 2 && $sh[0]['tracking'] !== $sh[1]['tracking'] && $sh[1]['box'] === '2 of 2' && $sh[0]['carrier'] === 'UPS' && $sh[0]['amount'] === 12.4 && strpos( $sh[0]['label'], 'https://deliver.goshippo.com/txn-' ) === 0 && $o->saved === 2, 'each label is recorded on the order as it is bought, with its own tracking number', $sh );
$note = $o->notes[0][0];
ok( count( $o->notes ) === 1 && $o->notes[0][1] === 0 && strpos( $note, 'Shipping labels bought from the Missive panel: UPS Ground (Shippo rates), 2 boxes, $24.80. Attached to the order in Shippo.' ) === 0, 'one private note says what was bought', $note );
ok( strpos( $note, "\nBox 1 of 2: " . $sh[0]['tracking'] . ' ' . $sh[0]['track_url'] . "\nBox 2 of 2: " . $sh[1]['tracking'] . ' ' . $sh[1]['track_url'] ) !== false, 'and lists every tracking link', $note );
ok( $o->st === 'processing', 'the order\'s status is not changed' );
ok( is_wp_error( pps_ms_buy( 87551, $q['quote'], $byid['UPS Ground']['id'] ) ) && count( calls( 'transactions/' ) ) === 2, 'a second press on the same quote buys nothing' );
ok( pps_ms_summary( $o )['labels'] === 2 && pps_ms_summary( $o )['last'] === 'UPS Ground', 'the panel\'s order card shows the labels bought', pps_ms_summary( $o ) );

$q2 = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 14, 'w' => 14, 'h' => 14, 'weight' => 32.2 ) ) );
$r = pps_ms_buy( 87551, $q2['quote'], $q2['options'][0]['id'] );
ok( is_wp_error( $r ) && $r->c === 'again' && count( calls( 'transactions/' ) ) === 2, 'an order that already has a label asks before buying another' );
$q2 = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 14, 'w' => 14, 'h' => 14, 'weight' => 32.2 ) ), '', array( 'signature' => 'STANDARD', 'insure' => 100 ) );
$r = pps_ms_buy( 87551, $q2['quote'], $q2['options'][1]['id'], true );
$last = end( $o->meta['_pps_shipments'] );
ok( ! is_wp_error( $r ) && count( $o->meta['_pps_shipments'] ) === 3 && $last['signature'] === 'STANDARD' && $last['insured'] === 100.0 && strpos( end( $o->notes )[0], 'Signature required. Insured for $100.00 a box.' ) !== false, 'and buys it when confirmed — with its signature and insurance recorded and noted', array( $last, end( $o->notes ) ) );

// a repeat label whose price moved stops the run (USPS here prices each new shipment differently)
$o3 = $GLOBALS['orders'][87600]; $o3->meta = array();
$q5 = pps_ms_rates( 87600, $v2['to'], array( array( 'l' => 12, 'w' => 9, 'h' => 7, 'weight' => 5, 'count' => 3 ) ) );
$ga = null; foreach ( $q5['options'] as $x ) if ( $x['service'] === 'Ground Advantage' ) $ga = $x;
$before = count( calls( 'transactions/' ) );
$r = pps_ms_buy( 87600, $q5['quote'], $ga['id'] );
ok( ! is_wp_error( $r ) && count( $r['labels'] ) === 1 && count( calls( 'transactions/' ) ) === $before + 1 && strpos( $r['error'], 'Bought 1 of 3, then: The price for box 2 came back as $' ) === 0, 'a repeat label that would cost more than quoted is not bought: one label, and the stop is said', $r );
ok( count( $o3->meta['_pps_shipments'] ) === 1 && strpos( end( $o3->notes )[0], 'Stopped after 1 of 3' ) !== false, 'and the one bought is recorded and noted' );

// a failure part-way is kept, said, and noted
$o2 = $GLOBALS['orders'][87600]; $o2->meta = array();
$GLOBALS['shippo']['fail_txn_after'] = count( $GLOBALS['shippo']['transactions'] ) + 1;
$q3 = pps_ms_rates( 87600, $v2['to'], array( array( 'l' => 12, 'w' => 9, 'h' => 7, 'weight' => 5 ), array( 'l' => 12, 'w' => 9, 'h' => 6, 'weight' => 5 ) ) );
$ug = null; foreach ( $q3['options'] as $x ) if ( $x['service'] === 'Ground' && $x['carrier'] === 'UPS' ) $ug = $x;
$r = pps_ms_buy( 87600, $q3['quote'], $ug['id'], true );
ok( ! is_wp_error( $r ) && count( $r['labels'] ) === 1 && strpos( $r['error'], 'Bought 1 of 2, then: Shippo could not make the label for box 2: The address could not be validated' ) === 0, 'a label that fails part-way: the one bought is kept and the stop is said', $r );
ok( count( $o2->meta['_pps_shipments'] ) === 1 && strpos( end( $o2->notes )[0], 'Stopped after 1 of 2' ) !== false, 'and recorded on the order and in its note' );
$txs = calls( 'transactions/' ); $lastTx = json_decode( $txs[ count( $txs ) - 1 ]['args']['body'], true );
ok( strpos( end( $o2->notes )[0], 'Not attached to a Shippo order' ) !== false && ! isset( $lastTx['order'] ), 'an order the store sync has not brought in is bought standalone, and the note says so' );
ok( ! isset( $GLOBALS['tr']['pps_ms_lock_87600'] ), 'the purchase lock is released' );
$GLOBALS['shippo']['fail_txn_after'] = 0;
$GLOBALS['tr']['pps_ms_lock_87551'] = 1;
$q4 = pps_ms_rates( 87551, $v['to'], array( $boxes[0] ) );
ok( is_wp_error( pps_ms_buy( 87551, $q4['quote'], $q4['options'][0]['id'], true ) ), 'two purchases for one order never run at once' );
unset( $GLOBALS['tr']['pps_ms_lock_87551'] );

$GLOBALS['today'] = '2026-10-23';
$late = pps_ms_rates( 87551, $v['to'], array( array( 'l' => 12, 'w' => 9, 'h' => 7, 'weight' => 5 ) ), '2026-10-20' );
$lb = array(); foreach ( $late['options'] as $x ) $lb[ $x['carrier'] . ' ' . $x['service'] ] = $x;
ok( $late['ship_date'] === '2026-10-23', 'a ship date in the past becomes today' );
ok( $lb['USPS Ground Advantage']['arrives'] === '2026-10-30' && $lb['USPS Ground Advantage']['on_time'] === false && $lb['USPS Priority Mail']['on_time'] === true, 'and a rate arriving after the promised delivery is marked late (Fri + 5 business days), a faster one on time', $lb );
$GLOBALS['today'] = '2026-10-19';

// ── nothing in the file voids, refunds or changes an order ──
$code = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( __DIR__ . '/pps-missive-ship.php' ) );
foreach ( array( "'refunds", 'update_status', 'wp_mail', 'set_status', "'DELETE'" ) as $w ) ok( strpos( $code, $w ) === false, "the file never calls $w" );
ok( substr_count( $code, "pps_ms_api( 'POST'" ) === 3 && strpos( $code, "pps_ms_api( 'POST', 'shipments/'" ) !== false && strpos( $code, "pps_ms_api( 'POST', 'transactions/'" ) !== false, 'its only Shippo writes are a quote (shipment) and a label (transaction)' );

// ── the panel offers it ──
$panel = file_get_contents( __DIR__ . '/pps-missive-panel.php' );
ok( strpos( $panel, "require_once __DIR__ . '/pps-missive-ship.php';" ) !== false, 'the panel loads it' );
ok( strpos( $panel, "text:' [Ship]'" ) !== false && strpos( $panel, "text:sh.labels?'Ship again':'Ship'" ) !== false && strpos( $panel, "u = shipping ? shipUrl(IMPOSE) : imposeUrl(IMPOSE)" ) !== false, 'a Ship button on the order card and each board row, in the panel\'s frame' );
ok( strpos( $panel, "e.origin===location.origin && e.data && typeof e.data.ppsOpen==='string'" ) !== false, 'label and tracking links open through Missive, only from the site\'s own pages' );
ok( strpos( $panel, "DATA=d; if(!IMPOSE && !AN) render();" ) !== false, 'new panel data never reloads the shipping page mid-purchase' );

// a carrier account whose login Shippo is still waiting on is named, not left as Shippo's raw error
$acc = array( 'a1' => array( 'carrier' => 'UPS', 'shippo' => true, 'name' => 'Shippo rates', 'needs_auth' => false ),
              'a2' => array( 'carrier' => 'UPS', 'shippo' => false, 'name' => 'Cogent Promo', 'needs_auth' => true ) );
$nt = pps_ms_notes( array( array( 'source' => 'UPS', 'text' => 'Request failed due to missing authorization. Please reconnect your carrier account and try again or contact Shippo support at https://shippo.com/get-help for assistance.' ),
                           array( 'source' => 'UPS', 'text' => 'Request failed due to missing authorization. Please reconnect your carrier account and try again.' ) ), $acc );
ok( count( $nt ) === 1 && strpos( $nt[0], 'UPS account "Cogent Promo" needs reconnecting in Shippo' ) === 0 && strpos( $nt[0], 'the others are unaffected' ) !== false, 'Shippo\'s "missing authorization" note names the account to reconnect, once', $nt );
ok( strpos( pps_ms_notes( array( array( 'source' => 'FedEx', 'text' => 'missing authorization, reconnect your carrier account' ) ) )[0], 'A FedEx account needs reconnecting' ) === 0, 'and still says what to do when the account is not known' );
$src_ms = file_get_contents( __DIR__ . '/pps-missive-ship.php' );
ok( strpos( $src_ms, "'needs_auth' => ( \$a['object_info']['authentication']['type'] ?? '' ) === 'oauth'" ) !== false, 'an OAuth account Shippo has not authorized is marked from the account list' );

// ── the page in a browser ──
$tmp = sys_get_temp_dir() . '/pps-ms-' . getmypid() . '.html';
$GLOBALS['opts'][ PPS_MS_OPTION ]['allow_buy'] = true;
file_put_contents( $tmp, pps_ms_page_html( $GLOBALS['orders'][87551], 'https://site.test/wp-json/pps/v1/missive/ship/rates', 'https://site.test/wp-json/pps/v1/missive/ship/buy' ) );
$out = shell_exec( 'node ' . escapeshellarg( __DIR__ . '/tools-missive-ship-page.mjs' ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
@unlink( $tmp );
$res = json_decode( (string) substr( (string) $out, (int) strpos( (string) $out, '{' ) ), true );
if ( ! is_array( $res ) ) { ok( false, 'the browser run reports', $out ); }
else foreach ( $res['checks'] as $c ) ok( $c[0], 'page: ' . $c[1], $c[2] ?? null );


// ── the Ship page's address tools (owner 2026-10-20) ──
$r = pps_ms_address_call( 87551, 1, null, '', '' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'pps-shipto.php' ) !== false, 'without the ship-to file the address call says so' );
$GLOBALS['stc'] = array();
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( true ) {
    function pps_st_from_order( $o ) { return array( 'street1' => 'x' ); }
    function pps_st_shippo_status( $o, $a ) { return array( 'status' => 'stale', 'shippo' => '12 New Rd, Austin TX 78701', 'addr' => array( 'street1' => '12 New Rd', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701' ) ); }
    function pps_st_shippo_words( $s ) { return 'Shippo words.'; }
    function pps_st_change( $o, $a, $who ) { $GLOBALS['stc'][] = array( $a, $who ); return array( 'at' => 'now', 'shippo' => array( 'status' => 'match' ) ); }
}
$r = pps_ms_address_call( 87551, 1, null, '', '' );
ok( $r['shippo'] === 'stale' && $r['shippo_addr']['street1'] === '12 New Rd', 'a check answers with Shippo\'s own address when it differs', $r );
$GLOBALS['tr']['pps_ms_q_87551'] = array( 'held' );
$r = pps_ms_address_call( 87551, 0, array( 'street1' => '12 New Rd' ), 'Pat', 'shippo' );
ok( ! is_wp_error( $r ) && $GLOBALS['stc'][0][1] === "Pat (Ship page, from Shippo's address)" && $r['shippo_words'] === 'Shippo words.' && isset( $r['to'] ), 'a save goes through the ship-to change (which the tickets, subtasks and conversations follow), naming who and from where', $GLOBALS['stc'] );
ok( ! isset( $GLOBALS['tr']['pps_ms_q_87551'] ), 'and drops the held quote, so no label is bought to the old address' );
$GLOBALS['opts']['pps_missive_allow_address'] = 0;
$r = pps_ms_address_call( 87551, 0, array( 'street1' => 'y' ), '', '' );
ok( is_wp_error( $r ) && strpos( $r->get_error_message(), 'switched off' ) !== false && count( $GLOBALS['stc'] ) === 1, 'with the panel\'s ship-to switch off, nothing is saved' );
unset( $GLOBALS['opts']['pps_missive_allow_address'] );
ok( strpos( $src_ms, "register_rest_route( 'pps/v1', '/missive/ship/address', array(\n        'methods' => 'POST', 'permission_callback' => \$ok," ) !== false, 'the address endpoint takes the same order-scoped token as rates and buy' );
$tmp = sys_get_temp_dir() . '/pps-msa-' . getmypid() . '.html';
file_put_contents( $tmp, pps_ms_page_html( $GLOBALS['orders'][87551], 'https://site.test/rates', 'https://site.test/buy', 'https://site.test/address' ) );
$out = shell_exec( 'node ' . escapeshellarg( __DIR__ . '/tools-missive-ship-address-page.mjs' ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
@unlink( $tmp );
$res = json_decode( (string) substr( (string) $out, (int) strpos( (string) $out, '{' ) ), true );
if ( ! is_array( $res ) ) { ok( false, 'the address browser run reports', $out ); }
else foreach ( $res['checks'] as $c ) ok( $c[0], 'address page: ' . $c[1], $c[2] ?? null );

echo $fail ? ">>> $fail of $n checks FAILED\n" : ">>> MISSIVE SHIP OK ($n checks)\n";
exit( $fail ? 1 : 0 );
