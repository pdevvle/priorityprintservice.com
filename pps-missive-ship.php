<?php
/**
 * PPS Missive Ship — Shippo labels from the Missive panel (owner, 2026-10-18).
 *
 * "Can we build a shippo integration into missive. It lifts the job info from the thread
 * and then we can buy labels for the address, view shipping times, multiple carriers, etc
 * all from within missive."
 *
 * The panel already finds the order in the open conversation. Its order card and board rows
 * carry a Ship button that opens `/?pps_missive_ship=1&k=<panel key>&order=<id>` inside the
 * panel (the same frame the imposition tool uses). That page shows what the order says about
 * shipping — ship-to, must-ship and promised delivery dates, the calculator's estimated
 * weight and carton count — lets staff set the boxes, and asks Shippo for rates:
 *
 *   - every ACTIVE carrier account on the Shippo account is quoted (owner 2026-10-18: "Every
 *     active account"), each rate naming the account it bills, so a customer's own FedEx or
 *     UPS account is never chosen by accident;
 *   - one Shippo shipment per box, and only services every box can use are offered, priced
 *     as the sum of the boxes;
 *   - each rate's arrival is the ship date plus the carrier's estimated business days, marked
 *     on time / late against the order's promised delivery date.
 *
 * Buying is OFF until "Let the panel buy labels" is ticked on PPS Calculators → Missive
 * Shipping, and every purchase is confirmed in the page with carrier, service, account and
 * price (owner: "No caps, just the tick + confirm"). A purchase buys one Shippo transaction per
 * box from the rates of THIS order's own quote (the browser names an option, never a rate id),
 * attaches it to the order's Shippo order when the store sync has one (as labels bought in
 * Shippo are), records each label in `_pps_shipments` as it is bought, and adds a private
 * order note. It never changes the order's status, never emails anyone, never voids or refunds
 * a label — those stay in Shippo.
 *
 * Missive's frame carries no WordPress login, so the page holds an ORDER-SCOPED token
 * (HMAC of 'pps-ship|<order>' with the panel key) and the two endpoints accept it for that
 * order only. The Shippo API token never leaves the server. tools-missive-ship-test.php is the
 * gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_MS_OPTION', 'pps_missive_ship' );
define( 'PPS_MS_API', 'https://api.goshippo.com/' );
define( 'PPS_MS_MAX_BOXES', 30 );

/* ── Settings ─────────────────────────────────────────────────────────────── */

function pps_ms_label_types() {
    return array( '' => 'Shippo account default', 'PDF_4x6' => '4×6 PDF (thermal)', 'PDF' => '8.5×11 PDF', 'PDF_4x8' => '4×8 PDF', 'PNG' => 'PNG', 'ZPLII' => 'ZPL (Zebra)' );
}

/** Ship-from defaults: the address the shop's carrier accounts and delivery estimates use. */
function pps_ms_defaults() {
    return array(
        'from'       => array( 'name' => 'Priority Print Service', 'company' => 'Priority Print Service', 'street1' => '2941 W Eastman Dr',
                               'street2' => '', 'city' => 'Anthem', 'state' => 'AZ', 'zip' => '85086', 'phone' => '(623) 267-9479', 'email' => 'Office@priorityprintservice.com' ),
        'allow_buy'  => false,
        'label_type' => '',
    );
}

function pps_ms_settings() {
    $d = pps_ms_defaults();
    $s = get_option( PPS_MS_OPTION, array() );
    $s = is_array( $s ) ? $s : array();
    $from = $d['from'];
    foreach ( $from as $k => $v ) if ( isset( $s['from'][ $k ] ) && is_string( $s['from'][ $k ] ) ) $from[ $k ] = $s['from'][ $k ];
    $lt = (string) ( $s['label_type'] ?? '' );
    return array( 'from' => $from, 'allow_buy' => ! empty( $s['allow_buy'] ), 'label_type' => array_key_exists( $lt, pps_ms_label_types() ) ? $lt : '' );
}

/* ── The page's token: good for one order ─────────────────────────────────── */

function pps_ms_token( $order_id ) {
    $k = function_exists( 'pps_mp_key' ) ? pps_mp_key() : '';
    return ( $k === '' || (int) $order_id <= 0 ) ? '' : hash_hmac( 'sha256', 'pps-ship|' . (int) $order_id, $k );
}

function pps_ms_token_ok( $order_id, $given ) {
    $t = pps_ms_token( $order_id );
    return $t !== '' && is_string( $given ) && $given !== '' && hash_equals( $t, $given );
}

/* ── Shippo ───────────────────────────────────────────────────────────────── */

/** The site's Shippo API token (PPS Config → Shippo Integration). Server side only. */
function pps_ms_shippo_key() {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $c = pps_get_config();
    return trim( (string) ( $c['pcf']['shippo_api_token'] ?? '' ) );
}

/** Shippo's error body as one readable line. */
function pps_ms_err_text( $j ) {
    $bits = array();
    array_walk_recursive( $j, function ( $v, $k ) use ( &$bits ) { if ( is_scalar( $v ) && (string) $v !== '' && ! in_array( $k, array( 'object_id', 'object_owner' ), true ) ) $bits[] = (string) $v; } );
    return substr( implode( ' · ', array_unique( $bits ) ), 0, 300 );
}

function pps_ms_api( $method, $path, $body = null ) {
    $key = pps_ms_shippo_key();
    if ( $key === '' ) return new WP_Error( 'nokey', 'No Shippo API token is set (PPS Config → Shippo Integration).' );
    $args = array( 'method' => $method, 'timeout' => 45,
                   'headers' => array( 'Authorization' => 'ShippoToken ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ) );
    if ( $body !== null ) $args['body'] = wp_json_encode( $body );
    $r = wp_remote_request( PPS_MS_API . ltrim( $path, '/' ), $args );
    if ( is_wp_error( $r ) ) return new WP_Error( 'http', 'Could not reach Shippo: ' . $r->get_error_message() );
    $code = (int) wp_remote_retrieve_response_code( $r );
    $j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
    if ( $code === 401 || $code === 403 ) return new WP_Error( 'auth', 'Shippo refused the API token.' );
    if ( $code < 200 || $code >= 300 ) return new WP_Error( 'shippo', 'Shippo answered ' . $code . ( is_array( $j ) ? ': ' . pps_ms_err_text( $j ) : '.' ) );
    return is_array( $j ) ? $j : array();
}

/** Active carrier accounts, by object id: carrier, whose account, Shippo's own or not. 1 h. */
function pps_ms_accounts( $fresh = false ) {
    $c = $fresh ? false : get_transient( 'pps_ms_accounts' );
    if ( is_array( $c ) ) return $c;
    $r = pps_ms_api( 'GET', 'carrier_accounts/?results=100' );
    if ( is_wp_error( $r ) ) return $r;
    $out = array();
    foreach ( (array) ( $r['results'] ?? array() ) as $a ) {
        $id = (string) ( $a['object_id'] ?? '' );
        if ( $id === '' || empty( $a['active'] ) ) continue;
        $own = ! empty( $a['is_shippo_account'] );
        $acct = (string) ( $a['account_id'] ?? '' );
        $out[ $id ] = array(
            'carrier' => (string) ( $a['carrier_name'] ?? $a['carrier'] ?? '' ),
            'shippo'  => $own,
            'name'    => $own ? 'Shippo rates' : ( trim( (string) ( $a['metadata'] ?? '' ) ) ?: ( strpos( $acct, '*' ) === false && $acct !== '' ? 'account ' . $acct : 'own account' ) ),
            // A carrier login Shippo is still waiting on ("authorization_pending"): its rates do not come back.
            'needs_auth' => ( $a['object_info']['authentication']['type'] ?? '' ) === 'oauth' && ! in_array( (string) ( $a['object_info']['authentication']['status'] ?? '' ), array( '', 'authorized', 'active' ), true ),
        );
    }
    set_transient( 'pps_ms_accounts', $out, HOUR_IN_SECONDS );
    return $out;
}

/** The boxes saved in Shippo (user parcel templates). 1 h. */
function pps_ms_boxes( $fresh = false ) {
    $c = $fresh ? false : get_transient( 'pps_ms_boxes' );
    if ( is_array( $c ) ) return $c;
    $r = pps_ms_api( 'GET', 'user-parcel-templates' );
    if ( is_wp_error( $r ) ) return $r;
    $out = array();
    foreach ( (array) ( $r['results'] ?? array() ) as $t ) {
        $in = ( $t['distance_unit'] ?? 'in' ) === 'cm' ? 1 / 2.54 : 1;
        $lb = ( $t['weight_unit'] ?? 'lb' ) === 'oz' ? 1 / 16 : ( ( $t['weight_unit'] ?? 'lb' ) === 'kg' ? 2.20462 : 1 );
        $out[] = array( 'id' => (string) ( $t['object_id'] ?? '' ), 'name' => (string) ( $t['name'] ?? '' ),
                        'l' => round( (float) ( $t['length'] ?? 0 ) * $in, 2 ), 'w' => round( (float) ( $t['width'] ?? 0 ) * $in, 2 ),
                        'h' => round( (float) ( $t['height'] ?? 0 ) * $in, 2 ), 'weight' => round( (float) ( $t['weight'] ?? 0 ) * $lb, 2 ) );
    }
    set_transient( 'pps_ms_boxes', $out, HOUR_IN_SECONDS );
    return $out;
}

/**
 * The order's Shippo order (made by the WooCommerce store sync), found by order number in
 * Shippo's most recent 300 orders. A label attached to it is what Shippo's own screens and
 * sync treat as "this order shipped". '' when the sync has not brought it in.
 */
function pps_ms_shippo_order( $number ) {
    $number = (string) $number;
    $map = get_transient( 'pps_ms_orders' );
    $map = is_array( $map ) ? $map : array();
    if ( isset( $map[ $number ] ) ) return $map[ $number ];
    if ( $number === '' || get_transient( 'pps_ms_miss_' . $number ) ) return '';
    for ( $page = 1; $page <= 3; $page++ ) {
        $r = pps_ms_api( 'GET', 'orders/?results=100&page=' . $page );
        if ( is_wp_error( $r ) ) break;
        foreach ( (array) ( $r['results'] ?? array() ) as $o ) {
            if ( ! empty( $o['order_number'] ) && ! empty( $o['object_id'] ) ) $map[ (string) $o['order_number'] ] = (string) $o['object_id'];
        }
        if ( isset( $map[ $number ] ) || empty( $r['next'] ) ) break;
    }
    set_transient( 'pps_ms_orders', array_slice( $map, -600, null, true ), DAY_IN_SECONDS );
    if ( ! isset( $map[ $number ] ) ) { set_transient( 'pps_ms_miss_' . $number, 1, 300 ); return ''; }
    return $map[ $number ];
}

/* ── Dates ────────────────────────────────────────────────────────────────── */

function pps_ms_today() {
    return function_exists( 'pps_mp_today' ) ? pps_mp_today() : gmdate( 'Y-m-d' );
}

/** Carriers count business days: Monday to Friday. */
function pps_ms_add_weekdays( $ymd, $n ) {
    $d = DateTime::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
    if ( ! $d ) return '';
    while ( (int) $d->format( 'N' ) >= 6 ) $d->modify( '+1 day' );   // a weekend ship date goes out Monday
    for ( $i = 0; $i < (int) $n; ) { $d->modify( '+1 day' ); if ( (int) $d->format( 'N' ) < 6 ) $i++; }
    return $d->format( 'Y-m-d' );
}

/**
 * Business days in transit for one rate. The carriers' own figure (`estimated_days`, what
 * Shippo's screen used to show as "2 business days"), except FedEx's express services: on a
 * live quote (2026-10-19) FedEx answered one day short across the board — overnight 0, 2Day
 * and 2Day A.M. 1, Express Saver (a three-day service) 2, while UPS's matched its services
 * (Next Day Air 1, 2nd Day Air 2, 3 Day Select 3). For those the service's own promise is
 * used. Ground services keep the carrier's figure; nothing arrives the day it ships.
 */
function pps_ms_transit_days( $provider, $token, $estimated ) {
    if ( strtolower( (string) $provider ) === 'fedex' ) {
        $t = strtolower( (string) $token );
        if ( strpos( $t, 'overnight' ) !== false ) return 1;
        if ( strpos( $t, 'fedex_2_day' ) === 0 ) return 2;
        if ( $t === 'fedex_express_saver' ) return 3;
    }
    return is_numeric( $estimated ) ? max( 1, (int) $estimated ) : null;
}

/** A ship date as Shippo wants it: noon shop time, in UTC. */
function pps_ms_shipment_date( $ymd ) {
    $tz = function_exists( 'pps_shop_timezone' ) ? pps_shop_timezone() : 'America/Phoenix';
    try { $d = new DateTime( $ymd . ' 12:00:00', new DateTimeZone( is_string( $tz ) ? $tz : 'America/Phoenix' ) ); }
    catch ( Exception $e ) { $d = new DateTime( $ymd . ' 19:00:00', new DateTimeZone( 'UTC' ) ); }
    $d->setTimezone( new DateTimeZone( 'UTC' ) );
    return $d->format( 'Y-m-d\TH:i:s\Z' );
}

/* ── What the order says about shipping ───────────────────────────────────── */

function pps_ms_order_address( $order ) {
    $use = trim( (string) $order->get_shipping_address_1() ) !== '' ? 'shipping' : 'billing';
    $g = function ( $f ) use ( $order, $use ) { $m = 'get_' . $use . '_' . $f; return is_callable( array( $order, $m ) ) ? trim( (string) $order->$m() ) : ''; };
    $phone = is_callable( array( $order, 'get_shipping_phone' ) ) ? trim( (string) $order->get_shipping_phone() ) : '';
    return array(
        'name' => trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) ), 'company' => $g( 'company' ),
        'street1' => $g( 'address_1' ), 'street2' => $g( 'address_2' ), 'city' => $g( 'city' ), 'state' => $g( 'state' ),
        'zip' => $g( 'postcode' ), 'country' => $g( 'country' ) ?: 'US',
        'phone' => $phone !== '' ? $phone : trim( (string) $order->get_billing_phone() ),
        'email' => trim( (string) $order->get_billing_email() ),
        'source' => $use,
    );
}

function pps_ms_shipments( $order ) {
    $s = $order->get_meta( '_pps_shipments' );
    return is_array( $s ) ? array_values( $s ) : array();
}

/** One line for the panel's order card: labels already bought here. */
function pps_ms_summary( $order ) {
    $s = pps_ms_shipments( $order );
    if ( ! $s ) return array( 'labels' => 0 );
    $last = end( $s );
    return array( 'labels' => count( $s ), 'last' => trim( ( $last['carrier'] ?? '' ) . ' ' . ( $last['service'] ?? '' ) ), 'tracking' => (string) ( $last['tracking'] ?? '' ),
                  'track_url' => (string) ( $last['track_url'] ?? '' ) );
}

/**
 * The boxes the page opens with: the calculator's carton count, the estimated weight split
 * between them, in a saved box named for the product ("Armadillo") or else the smallest
 * saved box whose weight is at least one carton's.
 */
function pps_ms_suggest_boxes( $weight, $cartons, array $boxes, array $products ) {
    $n = max( 1, min( PPS_MS_MAX_BOXES, (int) $cartons ) );
    $each = $weight > 0 ? ceil( $weight / $n * 10 ) / 10 : 0;
    $pick = null;
    $hay = strtolower( implode( ' ', $products ) );
    foreach ( $boxes as $b ) if ( $b['name'] !== '' && strpos( $hay, strtolower( $b['name'] ) ) !== false ) { $pick = $b; break; }
    if ( ! $pick && $boxes ) {
        $fit = array_filter( $boxes, function ( $b ) use ( $each ) { return $b['weight'] >= $each; } );
        $pool = $fit ?: $boxes;
        usort( $pool, function ( $a, $b ) use ( $fit ) {
            return $fit ? ( $a['l'] * $a['w'] * $a['h'] <=> $b['l'] * $b['w'] * $b['h'] ) : ( $b['weight'] <=> $a['weight'] );
        } );
        $pick = $pool[0];
    }
    $one = $pick ? array( 'box' => $pick['id'], 'l' => $pick['l'], 'w' => $pick['w'], 'h' => $pick['h'], 'weight' => $each ?: $pick['weight'] )
                 : array( 'box' => '', 'l' => 12, 'w' => 9, 'h' => 7, 'weight' => $each ?: 1 );
    return array( $one + array( 'count' => $n ) );
}

function pps_ms_view( $order ) {
    $jobs = function_exists( 'pps_mp_jobs' ) ? pps_mp_jobs( array( $order ) ) : array();
    $must = array_filter( array_column( $jobs, 'must_ship' ) ); $deliv = array_filter( array_column( $jobs, 'delivery' ) );
    sort( $must ); sort( $deliv );
    $products = array();
    foreach ( $order->get_items() as $it ) $products[] = $it->get_name();
    $boxes = pps_ms_boxes();
    $boxes = is_wp_error( $boxes ) ? array() : $boxes;
    $w = (float) $order->get_meta( '_pps_est_weight_lb' );
    $c = (int) $order->get_meta( '_pps_est_cartons' );
    return array(
        'order' => (int) $order->get_id(), 'status' => $order->get_status(),
        'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $order->get_billing_company(),
        'products' => $products, 'to' => pps_ms_order_address( $order ),
        'must_ship' => $must ? reset( $must ) : '', 'delivery' => $deliv ? reset( $deliv ) : '',
        'rush' => (bool) array_filter( array_column( $jobs, 'rush' ) ),
        'est_weight' => $w, 'est_cartons' => $c, 'multi_destination' => (int) $order->get_meta( '_pps_multi_destination' ),
        'boxes' => $boxes, 'suggest' => pps_ms_suggest_boxes( $w, $c, $boxes, $products ),
        'shipments' => pps_ms_shipments( $order ), 'today' => pps_ms_today(),
    );
}

/* ── Rates ────────────────────────────────────────────────────────────────── */

function pps_ms_clean_addr( $a ) {
    $a = is_array( $a ) ? $a : array();
    $t = function ( $k, $n ) use ( $a ) { return substr( trim( preg_replace( '/\s+/', ' ', (string) ( $a[ $k ] ?? '' ) ) ), 0, $n ); };
    $out = array( 'name' => $t( 'name', 60 ), 'company' => $t( 'company', 60 ), 'street1' => $t( 'street1', 80 ), 'street2' => $t( 'street2', 80 ),
                  'city' => $t( 'city', 50 ), 'state' => strtoupper( $t( 'state', 2 ) ), 'zip' => $t( 'zip', 10 ),
                  'country' => strtoupper( $t( 'country', 2 ) ) ?: 'US', 'phone' => $t( 'phone', 20 ), 'email' => $t( 'email', 80 ) );
    if ( $out['country'] !== 'US' ) return new WP_Error( 'intl', 'Only US addresses here. International labels need customs forms — buy them in Shippo.' );
    if ( $out['street1'] === '' || $out['city'] === '' || ! preg_match( '/^[A-Z]{2}$/', $out['state'] ) || ! preg_match( '/^\d{5}(-?\d{4})?$/', $out['zip'] ) )
        return new WP_Error( 'addr', 'The address needs a street, city, two-letter state and ZIP.' );
    if ( $out['name'] === '' && $out['company'] === '' ) return new WP_Error( 'addr', 'The address needs a name or a company.' );
    if ( $out['name'] === '' ) $out['name'] = $out['company'];
    return $out;
}

/**
 * Box rows → groups of identical boxes. A row may carry `count` ("12 boxes like this"),
 * so a 12-carton job is one row, not twelve. At most PPS_MS_MAX_BOXES labels in all.
 */
function pps_ms_clean_parcels( $p ) {
    if ( ! is_array( $p ) || ! $p ) return new WP_Error( 'box', 'Add at least one box.' );
    $groups = array(); $total = 0;
    foreach ( array_values( $p ) as $i => $b ) {
        $l = (float) ( $b['l'] ?? 0 ); $w = (float) ( $b['w'] ?? 0 ); $h = (float) ( $b['h'] ?? 0 ); $lb = (float) ( $b['weight'] ?? 0 );
        $n = isset( $b['count'] ) ? (int) $b['count'] : 1;
        if ( $l <= 0 || $w <= 0 || $h <= 0 || $l > 120 || $w > 120 || $h > 120 ) return new WP_Error( 'box', 'Box ' . ( $i + 1 ) . ' needs a length, width and height (inches).' );
        if ( $lb <= 0 || $lb > 150 ) return new WP_Error( 'box', 'Box ' . ( $i + 1 ) . ' needs a weight between 0 and 150 lb.' );
        if ( $n < 1 ) return new WP_Error( 'box', 'Box ' . ( $i + 1 ) . ' needs a count of at least 1.' );
        $parcel = array( 'length' => (string) round( $l, 2 ), 'width' => (string) round( $w, 2 ), 'height' => (string) round( $h, 2 ), 'distance_unit' => 'in',
                         'weight' => (string) round( $lb, 2 ), 'mass_unit' => 'lb' );
        $k = implode( '|', $parcel );
        if ( isset( $groups[ $k ] ) ) $groups[ $k ]['count'] += $n; else $groups[ $k ] = array( 'parcel' => $parcel, 'count' => $n );
        $total += $n;
    }
    if ( $total > PPS_MS_MAX_BOXES ) return new WP_Error( 'box', 'At most ' . PPS_MS_MAX_BOXES . ' labels at a time — buy the rest in a second run.' );
    return array_values( $groups );
}

function pps_ms_signatures() {
    return array( '' => 'No signature', 'STANDARD' => 'Signature required', 'ADULT' => 'Adult signature (21+)', 'INDIRECT' => 'Indirect signature (FedEx)', 'CERTIFIED' => 'Certified Mail (USPS)' );
}

/** Signature and insurance as Shippo's shipment `extra`; a carrier that cannot do them is not quoted. */
function pps_ms_clean_extra( $x ) {
    $x = is_array( $x ) ? $x : array();
    $out = array();
    $sig = strtoupper( (string) ( $x['signature'] ?? '' ) );
    if ( $sig !== '' ) {
        if ( ! array_key_exists( $sig, pps_ms_signatures() ) ) return new WP_Error( 'extra', 'Pick a signature option from the list.' );
        $out['signature_confirmation'] = $sig;
    }
    $ins = round( (float) ( $x['insure'] ?? 0 ), 2 );
    if ( $ins < 0 || $ins > 50000 ) return new WP_Error( 'extra', 'Insure each box for between $0 and $50,000.' );
    if ( $ins > 0 ) $out['insurance'] = array( 'amount' => number_format( $ins, 2, '.', '' ), 'currency' => 'USD', 'content' => 'Printed materials' );
    return $out;
}

/** Carrier notes worth a person's attention (the routine ones every quote carries are dropped). */
function pps_ms_notes( array $msgs, array $accounts = array() ) {
    // Shippo's "missing authorization … reconnect your carrier account" names no account; say which.
    $stuck = array();
    foreach ( $accounts as $a ) if ( ! empty( $a['needs_auth'] ) ) $stuck[ $a['carrier'] ][] = '"' . $a['name'] . '"';
    $skip = '/doesn.t support one or more shipment options|RatedShipmentAlert: Your invoice may vary|End of Day|master account doesn.t support shipments to inside|must not be empty|out of the service area|filtered from rate response due to|option is unavailable with the selected service/i';
    $out = array();
    foreach ( $msgs as $m ) {
        $t = trim( (string) ( $m['text'] ?? '' ) );
        if ( $t === '' || preg_match( $skip, $t ) ) continue;
        $src = trim( (string) ( $m['source'] ?? '' ) );
        if ( preg_match( '/missing authorization|reconnect your carrier account/i', $t ) ) {
            $names = $stuck[ $src ] ?? array();
            $t = ( $names ? $src . ' account ' . implode( ' and ', $names ) : 'A ' . $src . ' account' ) . ' needs reconnecting in Shippo (Settings → Carriers → Reconnect, signing in to that carrier account). Its rates are missing until then; the others are unaffected.';
            $out[ $t ] = 1; continue;
        }
        $out[ trim( $src . ': ' . $t, ': ' ) ] = 1;
    }
    return array_slice( array_keys( $out ), 0, 8 );
}

function pps_ms_rate_key( $rt ) {
    $sl = (array) ( $rt['servicelevel'] ?? array() );
    return ( $rt['carrier_account'] ?? '' ) . '|' . ( $sl['token'] ?? '' ) . '|' . ( $sl['terms'] ?? '' );
}

/** The Shippo shipment body for one box: everything but the box comes from the quote. */
function pps_ms_shipment_body( array $base, array $parcel, $account = '' ) {
    $b = $base + array( 'parcels' => array( $parcel ), 'async' => false );
    $b['parcels'] = array( $parcel );
    if ( $account !== '' ) $b['carrier_accounts'] = array( $account );   // a repeat label asks only the carrier already chosen
    return $b;
}

function pps_ms_rates( $order_id, $to, $parcels, $ship_ymd = '', $extra = array() ) {
    $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
    if ( ! $order ) return new WP_Error( 'order', 'No such order.' );
    $to = pps_ms_clean_addr( $to );
    if ( is_wp_error( $to ) ) return $to;
    $groups = pps_ms_clean_parcels( $parcels );
    if ( is_wp_error( $groups ) ) return $groups;
    $extra = pps_ms_clean_extra( $extra );
    if ( is_wp_error( $extra ) ) return $extra;
    $s = pps_ms_settings();
    $from = $s['from'] + array( 'country' => 'US' );
    if ( trim( $from['street1'] ) === '' || trim( $from['zip'] ) === '' ) return new WP_Error( 'from', 'The ship-from address is not set (PPS Calculators → Missive Shipping).' );
    $today = pps_ms_today();
    $ship = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ship_ymd ) && $ship_ymd >= $today ? $ship_ymd : $today;
    $view = pps_ms_view( $order );
    $accounts = pps_ms_accounts();
    $accounts = is_wp_error( $accounts ) ? array() : $accounts;
    $base = array( 'address_from' => $from, 'address_to' => $to, 'address_return' => $from,
                   'shipment_date' => pps_ms_shipment_date( $ship ), 'metadata' => 'Order ' . (int) $order_id );
    if ( $extra ) $base['extra'] = $extra;
    $boxes = array_sum( array_column( $groups, 'count' ) );

    // One shipment per DIFFERENT box: twelve identical cartons are quoted once.
    $sets = array(); $msgs = array();
    foreach ( $groups as $g ) {
        $r = pps_ms_api( 'POST', 'shipments/', pps_ms_shipment_body( $base, $g['parcel'] ) );
        if ( is_wp_error( $r ) ) return $r;
        $msgs = array_merge( $msgs, (array) ( $r['messages'] ?? array() ) );
        $set = array();
        foreach ( (array) ( $r['rates'] ?? array() ) as $rt ) {
            if ( empty( $rt['object_id'] ) ) continue;
            $msgs = array_merge( $msgs, (array) ( $rt['messages'] ?? array() ) );
            $set[ pps_ms_rate_key( $rt ) ] = $rt;
        }
        $sets[] = $set;
    }
    $keys = array_keys( $sets[0] );
    foreach ( $sets as $set ) $keys = array_values( array_intersect( $keys, array_keys( $set ) ) );

    $options = array(); $store = array();
    foreach ( $keys as $key ) {
        $rs = array_map( function ( $set ) use ( $key ) { return $set[ $key ]; }, $sets );
        $first = $rs[0]; $sl = (array) ( $first['servicelevel'] ?? array() );
        $amount = 0; foreach ( $rs as $gi => $r ) $amount += (float) ( $r['amount'] ?? 0 ) * $groups[ $gi ]['count'];
        $amount = round( $amount, 2 );
        $days = null;
        foreach ( $rs as $r ) {
            $rd = pps_ms_transit_days( $r['provider'] ?? '', ( (array) ( $r['servicelevel'] ?? array() ) )['token'] ?? '', $r['estimated_days'] ?? null );
            if ( $rd !== null ) $days = max( (int) $days, $rd );
        }
        $arrives = $days !== null ? pps_ms_add_weekdays( $ship, $days ) : '';
        $acct_id = (string) ( $first['carrier_account'] ?? '' );
        $acct = $accounts[ $acct_id ] ?? array( 'name' => 'account not listed', 'shippo' => false );
        $id = substr( md5( $key ), 0, 10 );
        $service = (string) ( ( $sl['display_name'] ?? '' ) ?: ( $sl['name'] ?? '' ) ) . ( ( $sl['terms'] ?? '' ) === 'ONERATE' ? ' (One Rate)' : '' );
        $ins = 0; foreach ( $rs as $gi => $r ) $ins += (float) ( $r['included_insurance_price'] ?? 0 ) * $groups[ $gi ]['count'];
        $options[] = array(
            'id' => $id, 'carrier' => (string) ( $first['provider'] ?? '' ), 'service' => $service,
            'account' => $acct['name'], 'own_account' => ! $acct['shippo'],
            'amount' => $amount, 'each' => $boxes > 1 && count( $groups ) === 1 ? round( (float) $first['amount'], 2 ) : null,
            'insurance' => $ins > 0 ? round( $ins, 2 ) : null,
            'currency' => (string) ( $first['currency'] ?? 'USD' ), 'days' => $days, 'arrives' => $arrives,
            'by' => (string) ( $first['arrives_by'] ?? '' ), 'terms' => (string) ( $first['duration_terms'] ?? '' ),
            'on_time' => ( $arrives !== '' && $view['delivery'] !== '' ) ? $arrives <= $view['delivery'] : null,
            'tags' => $boxes === 1 ? array_values( (array) ( $first['attributes'] ?? array() ) ) : array(),
        );
        $grp = array();
        foreach ( $rs as $gi => $r ) $grp[] = array( 'parcel' => $groups[ $gi ]['parcel'], 'count' => $groups[ $gi ]['count'], 'rate' => array( (string) $r['object_id'], (float) ( $r['amount'] ?? 0 ) ) );
        $store[ $id ] = array( 'key' => $key, 'account_id' => $acct_id, 'groups' => $grp, 'carrier' => (string) ( $first['provider'] ?? '' ),
                               'service' => $service, 'account' => $acct['name'], 'amount' => $amount );
    }
    usort( $options, function ( $a, $b ) { return $a['amount'] <=> $b['amount']; } );
    $qid = bin2hex( random_bytes( 6 ) );
    set_transient( 'pps_ms_q_' . (int) $order_id, array( 'qid' => $qid, 'ship' => $ship, 'to' => $to, 'boxes' => $boxes, 'base' => $base, 'extra' => $extra, 'options' => $store ), 30 * 60 );
    return array( 'quote' => $qid, 'ship_date' => $ship, 'delivery' => $view['delivery'], 'must_ship' => $view['must_ship'],
                  'boxes' => $boxes, 'extra' => array( 'signature' => $extra['signature_confirmation'] ?? '', 'insure' => isset( $extra['insurance'] ) ? (float) $extra['insurance']['amount'] : 0 ),
                  'options' => $options, 'notes' => pps_ms_notes( $msgs, $accounts ) );
}

/* ── Buy ──────────────────────────────────────────────────────────────────── */

/** How far a repeat label's price may differ from the quote before the run stops (carriers round). */
define( 'PPS_MS_DRIFT', 0.02 );

function pps_ms_buy( $order_id, $qid, $option, $again = false ) {
    $s = pps_ms_settings();
    if ( ! $s['allow_buy'] ) return new WP_Error( 'off', 'Buying labels from the panel is switched off (PPS Calculators → Missive Shipping).' );
    $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
    if ( ! $order ) return new WP_Error( 'order', 'No such order.' );
    $q = get_transient( 'pps_ms_q_' . (int) $order_id );
    if ( ! is_array( $q ) || ! is_string( $qid ) || ! hash_equals( (string) $q['qid'], $qid ) ) return new WP_Error( 'quote', 'These rates have expired. Get rates again.' );
    $opt = $q['options'][ (string) $option ] ?? null;
    if ( ! is_array( $opt ) ) return new WP_Error( 'option', 'That rate is not in this quote. Get rates again.' );
    $have = pps_ms_shipments( $order );
    if ( $have && ! $again ) return new WP_Error( 'again', 'This order already has ' . count( $have ) . ' label' . ( count( $have ) === 1 ? '' : 's' ) . ' from the panel. Confirm to buy another.' );
    $lock = 'pps_ms_lock_' . (int) $order_id;
    if ( get_transient( $lock ) ) return new WP_Error( 'busy', 'A label is already being bought for this order.' );
    set_transient( $lock, 1, 600 );
    delete_transient( 'pps_ms_q_' . (int) $order_id );   // one purchase per quote: a second press cannot buy twice
    if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );   // a dozen labels is two dozen Shippo calls

    $number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id();
    $sorder = pps_ms_shippo_order( $number );
    $n = (int) $q['boxes'];
    $made = array(); $err = null; $box = 0;
    $sig = (string) ( $q['extra']['signature_confirmation'] ?? '' );
    $ins = isset( $q['extra']['insurance'] ) ? (float) $q['extra']['insurance']['amount'] : 0;
    foreach ( $opt['groups'] as $g ) {
        for ( $c = 0; $c < (int) $g['count'] && ! $err; $c++ ) {
            $rate = $g['rate'];
            if ( $c > 0 ) {
                // A rate buys one label. Each further box of the same size gets its own shipment,
                // asked of the chosen account only, and must come back as the same service at
                // the quoted price (within PPS_MS_DRIFT).
                $r = pps_ms_api( 'POST', 'shipments/', pps_ms_shipment_body( (array) $q['base'], (array) $g['parcel'], (string) $opt['account_id'] ) );
                if ( is_wp_error( $r ) ) { $err = $r; break; }
                $match = null;
                foreach ( (array) ( $r['rates'] ?? array() ) as $rt ) if ( ! empty( $rt['object_id'] ) && pps_ms_rate_key( $rt ) === $opt['key'] ) { $match = $rt; break; }
                if ( ! $match ) { $err = new WP_Error( 'gone', $opt['carrier'] . ' ' . $opt['service'] . ' was not offered for box ' . ( $box + 1 ) . '.' ); break; }
                if ( abs( (float) $match['amount'] - (float) $g['rate'][1] ) > max( 0.01, PPS_MS_DRIFT * (float) $g['rate'][1] ) ) {
                    $err = new WP_Error( 'price', 'The price for box ' . ( $box + 1 ) . ' came back as $' . number_format( (float) $match['amount'], 2 ) . ' instead of $' . number_format( (float) $g['rate'][1], 2 ) . ' — stopped. Get rates again.' );
                    break;
                }
                $rate = array( (string) $match['object_id'], (float) $match['amount'] );
            }
            $body = array( 'rate' => $rate[0], 'async' => false, 'metadata' => 'Order ' . $number . ' · box ' . ( $box + 1 ) . ' of ' . $n );
            if ( $s['label_type'] !== '' ) $body['label_file_type'] = $s['label_type'];
            if ( $sorder !== '' ) $body['order'] = $sorder;
            $r = pps_ms_api( 'POST', 'transactions/', $body );
            if ( is_wp_error( $r ) ) { $err = $r; break; }
            if ( ( $r['status'] ?? '' ) !== 'SUCCESS' ) { $err = new WP_Error( 'label', 'Shippo could not make the label for box ' . ( $box + 1 ) . ': ' . ( pps_ms_err_text( (array) ( $r['messages'] ?? array() ) ) ?: 'status ' . ( $r['status'] ?? 'unknown' ) ) ); break; }
            $box++;
            $rec = array( 'at' => gmdate( 'c' ), 'carrier' => $opt['carrier'], 'service' => $opt['service'], 'account' => $opt['account'],
                          'amount' => round( $rate[1], 2 ), 'tracking' => (string) ( $r['tracking_number'] ?? '' ), 'track_url' => (string) ( $r['tracking_url_provider'] ?? '' ),
                          'label' => (string) ( $r['label_url'] ?? '' ), 'transaction' => (string) ( $r['object_id'] ?? '' ),
                          'box' => $box . ' of ' . $n, 'ship_date' => (string) $q['ship'], 'shippo_order' => $sorder, 'via' => 'missive',
                          'signature' => $sig, 'insured' => $ins );
            $have[] = $rec; $made[] = $rec;
            $order->update_meta_data( '_pps_shipments', $have );   // saved per label, so a failure part-way loses none
            $order->save();
        }
        if ( $err ) break;
    }
    delete_transient( $lock );
    if ( $made ) {
        $sum = array_sum( array_column( $made, 'amount' ) );
        $extras = trim( ( $sig !== '' ? ' ' . pps_ms_signatures()[ $sig ] . '.' : '' ) . ( $ins > 0 ? ' Insured for $' . number_format( $ins, 2 ) . ' a box.' : '' ) );
        $links = array();
        foreach ( $made as $m ) $links[] = 'Box ' . $m['box'] . ': ' . ( $m['tracking'] !== '' ? $m['tracking'] : '—' ) . ( $m['track_url'] !== '' ? ' ' . $m['track_url'] : '' );
        $order->add_order_note( sprintf( "Shipping label%s bought from the Missive panel: %s %s (%s), %d box%s, $%s.%s%s%s\n%s",
            count( $made ) === 1 ? '' : 's', $opt['carrier'], $opt['service'], $opt['account'], count( $made ), count( $made ) === 1 ? '' : 'es',
            number_format( $sum, 2 ), $extras !== '' ? ' ' . $extras : '',
            $sorder !== '' ? ' Attached to the order in Shippo.' : ' Not attached to a Shippo order (the store sync had not brought this order in).',
            $err ? ' Stopped after ' . count( $made ) . ' of ' . $n . ': ' . $err->get_error_message() : '',
            implode( "\n", $links ) ) );
    }
    if ( $err && ! $made ) return $err;
    return array( 'labels' => $made, 'error' => $err ? 'Bought ' . count( $made ) . ' of ' . $n . ', then: ' . $err->get_error_message() : '', 'attached' => $sorder !== '' );
}

/* ── Endpoints ────────────────────────────────────────────────────────────── */

add_action( 'rest_api_init', function () {
    $ok = function ( $req ) { return pps_ms_token_ok( (int) $req->get_param( 'order' ), (string) $req->get_header( 'x_pps_ship_token' ) ); };
    $out = function ( $r ) {
        $res = new WP_REST_Response( is_wp_error( $r ) ? array( 'ok' => false, 'error' => $r->get_error_message(), 'code' => method_exists( $r, 'get_error_code' ) ? $r->get_error_code() : '' ) : array( 'ok' => true ) + $r, 200 );
        $res->header( 'Cache-Control', 'no-store' );
        return $res;
    };
    register_rest_route( 'pps/v1', '/missive/ship/rates', array(
        'methods' => 'POST', 'permission_callback' => $ok,
        'callback' => function ( $req ) use ( $out ) { return $out( pps_ms_rates( (int) $req->get_param( 'order' ), $req->get_param( 'to' ), $req->get_param( 'boxes' ), (string) $req->get_param( 'ship_date' ), $req->get_param( 'extra' ) ) ); },
    ) );
    register_rest_route( 'pps/v1', '/missive/ship/buy', array(
        'methods' => 'POST', 'permission_callback' => $ok,
        'callback' => function ( $req ) use ( $out ) { return $out( pps_ms_buy( (int) $req->get_param( 'order' ), (string) $req->get_param( 'quote' ), (string) $req->get_param( 'option' ), (bool) $req->get_param( 'again' ) ) ); },
    ) );
} );

/* ── The page ─────────────────────────────────────────────────────────────── */

function pps_ms_page_html( $order, $rates_api, $buy_api ) {
    $s = pps_ms_settings();
    $cfg = wp_json_encode( array(
        'order' => (int) $order->get_id(), 'token' => pps_ms_token( $order->get_id() ), 'rates' => $rates_api, 'buy' => $buy_api,
        'view' => pps_ms_view( $order ), 'from' => $s['from'], 'canBuy' => $s['allow_buy'], 'hasKey' => pps_ms_shippo_key() !== '',
        'signatures' => pps_ms_signatures(), 'maxBoxes' => PPS_MS_MAX_BOXES,
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ship</title>
<style>
body{font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;padding:8px;color:#222;background:#fff}
@media (prefers-color-scheme: dark){body{color:#e5e7eb;background:#1e1e1e} input,select{background:#2a2a2a;color:#e5e7eb;border-color:#555}}
h3{font-size:11px;margin:12px 0 4px;text-transform:uppercase;letter-spacing:.04em;color:#888}
.card{border:1px solid rgba(128,128,128,.25);border-radius:6px;padding:8px;margin:0 0 8px}
.muted{color:#888} .small{font-size:12px} .late{color:#c0392b;font-weight:600} .ok{color:#2e7d32;font-weight:600} .warn{color:#b45309} .rush{color:#c0392b;font-weight:600}
input,select{font:inherit;font-size:12px;padding:2px 4px;border:1px solid rgba(128,128,128,.5);border-radius:3px;max-width:100%;box-sizing:border-box}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:4px} .grid .w{grid-column:1/3}
.box{display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:4px 0;border-top:1px solid rgba(128,128,128,.15)} .box input{width:52px}
button{font:inherit;font-size:12px;padding:3px 9px;cursor:pointer} button.go{background:#2563eb;color:#fff;border:1px solid #2563eb;border-radius:4px} button[disabled]{opacity:.5;cursor:default}
.rate{display:flex;gap:6px;align-items:flex-start;padding:6px 4px;border-top:1px solid rgba(128,128,128,.15);cursor:pointer} .rate.sel{background:rgba(37,99,235,.10)}
.rate .p{margin-left:auto;font-weight:700;white-space:nowrap} .tag{display:inline-block;border:1px solid rgba(128,128,128,.4);border-radius:3px;padding:0 4px;margin-left:4px;font-size:10px}
.own{background:#fef3c7;color:#92400e;border-color:#f59e0b} a{color:#2563eb;cursor:pointer;text-decoration:none}
.chips{display:flex;flex-wrap:wrap;gap:4px;margin:4px 0} .chip{font-size:11px;padding:1px 7px;border:1px solid rgba(128,128,128,.45);border-radius:10px;background:transparent;color:inherit;cursor:pointer} .chip.on{background:#2563eb;border-color:#2563eb;color:#fff}
.err{color:#c0392b;margin:6px 0} .box input.n{width:44px} textarea.links{width:100%;box-sizing:border-box;font:11px/1.4 ui-monospace,Menlo,monospace;margin-top:6px}
</style></head>
<body><div id="app"></div>
<script data-cfasync="false">
(function(){
'use strict';
var CFG = {$cfg};
var V = CFG.view, app = document.getElementById('app');
var ST = { to: JSON.parse(JSON.stringify(V.to)), boxes: JSON.parse(JSON.stringify(V.suggest)), ship: V.today, extra: { signature: '', insure: '' }, quote: null, sel: '', sort: 'price', carrier: '', busy: false, err: '', done: null };
function boxCount(){ return ST.boxes.reduce(function(n,b){ return n + Math.max(1, parseInt(b.count,10)||1); }, 0); }
function stale(){ ST.quote=null; ST.sel=''; }

function el(tag, attrs, kids){ var e=document.createElement(tag); attrs=attrs||{};
  for (var k in attrs){ if(k==='text') e.textContent=attrs[k]; else if(k==='cls') e.className=attrs[k]; else if(k==='on') e.addEventListener('click',attrs[k]);
    else if(k==='input') e.addEventListener('input',attrs[k]); else if(k==='change') e.addEventListener('change',attrs[k]); else e.setAttribute(k,attrs[k]); }
  (kids||[]).forEach(function(c){ if(c!==null&&c!==undefined&&c!==false) e.appendChild(typeof c==='string'||typeof c==='number'?document.createTextNode(String(c)):c); }); return e; }
function md(ymd){ if(!ymd) return '—'; var d=new Date(ymd+'T12:00:00Z'); return d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',timeZone:'UTC'}); }
function days(a,b){ return Math.round((Date.parse(b+'T12:00:00Z')-Date.parse(a+'T12:00:00Z'))/864e5); }
function money(n){ return '$'+Number(n).toFixed(2); }
/* Links open through the panel (Missive.openURL); outside Missive, in a new tab. */
function open(url){ if(!/^https:\/\//.test(url)) return; if(window.parent!==window) window.parent.postMessage({ppsOpen:url}, location.origin); else window.open(url,'_blank','noopener'); }
function post(url, body){
  return fetch(url,{method:'POST',headers:{'X-PPS-Ship-Token':CFG.token,'Content-Type':'application/json'},credentials:'omit',cache:'no-store',body:JSON.stringify(body)})
    .then(function(r){ if(!r.ok) throw new Error('The site answered '+r.status+'.'); return r.json(); });
}
function field(obj, k, ph, cls){ var i=el('input',{placeholder:ph,input:function(){ obj[k]=i.value; ST.quote=null; ST.sel=''; }}); i.value=obj[k]||''; var w=el('div',{cls:cls||''},[i]); i.style.width='100%'; return w; }

function getRates(){
  ST.busy=true; ST.err=''; ST.quote=null; ST.sel=''; render();
  post(CFG.rates,{order:CFG.order,to:ST.to,boxes:ST.boxes,ship_date:ST.ship,extra:{signature:ST.extra.signature,insure:Number(ST.extra.insure)||0}}).then(function(d){
    ST.busy=false; if(!d.ok){ ST.err=d.error||'Could not get rates.'; } else { ST.quote=d; } render();
  }).catch(function(e){ ST.busy=false; ST.err=e.message; render(); });
}
function buy(again){
  var o = ST.quote.options.filter(function(x){ return x.id===ST.sel; })[0]; if(!o) return;
  var t = ST.to, n = ST.quote.boxes;
  var x = ST.quote.extra || {};
  var msg = 'Buy '+n+' label'+(n===1?'':'s')+' for order #'+CFG.order+'?\\n\\n'+o.carrier+' '+o.service+' — '+money(o.amount)+(n>1?' ('+n+' labels)':'')+
    (x.signature?'\\n'+CFG.signatures[x.signature]:'')+(x.insure?'\\nInsured for '+money(x.insure)+' a box'+(o.insurance?' (insurance '+money(o.insurance)+', included)':''):'')+'\\nBilled to: '+o.carrier+' · '+o.account+
    (o.own_account?'  (NOT Shippo\'s own account)':'')+'\\nShips '+md(ST.quote.ship_date)+(o.arrives?', arrives '+md(o.arrives):'')+'\\n\\nTo: '+[t.name,t.company,t.street1,t.street2,t.city+', '+t.state+' '+t.zip].filter(Boolean).join(', ');
  if(!again && !confirm(msg)) return;
  ST.busy=true; ST.err=''; render();
  post(CFG.buy,{order:CFG.order,quote:ST.quote.quote,option:ST.sel,again:!!again}).then(function(d){
    ST.busy=false;
    if(!d.ok && d.code==='again'){ if(confirm(d.error)) { buy(true); return; } render(); return; }
    if(!d.ok){ ST.err=d.error||'Could not buy the label.'; if(/expired|not in this quote/.test(ST.err)) ST.quote=null; render(); return; }
    ST.done=d; V.shipments=V.shipments.concat(d.labels||[]); ST.quote=null; ST.sel=''; if(d.error) ST.err=d.error; render();
  }).catch(function(e){ ST.busy=false; ST.err=e.message+' Check the order\'s notes before trying again — a label may have been bought.'; render(); });
}

function header(){
  var c = el('div',{cls:'card'});
  c.appendChild(el('div',{},[el('b',{text:'Order #'+V.order}),' · '+V.customer+' · '+V.status, V.rush?el('span',{cls:'rush',text:' RUSH'}):null]));
  c.appendChild(el('div',{cls:'muted small',text:V.products.join(', ')}));
  var left = V.must_ship ? days(V.today, V.must_ship) : null;
  c.appendChild(el('div',{},['Must ship ',el('b',{text:md(V.must_ship)}), left===null?'':left<0?el('span',{cls:'late',text:' (LATE by '+(-left)+')'}):left===0?el('span',{cls:'rush',text:' (today)'}):' (in '+left+')',
    ' · promised delivery ',el('b',{text:md(V.delivery)})]));
  if(V.multi_destination>1) c.appendChild(el('div',{cls:'warn',text:'This order has '+V.multi_destination+' ship-to addresses — the order notes list them. One label run here ships to one address.'}));
  return c;
}

function shipped(){
  if(!V.shipments.length) return null;
  var c = el('div',{cls:'card'}); c.appendChild(el('h3',{text:'Labels bought here',style:'margin-top:0'}));
  V.shipments.forEach(function(s){
    c.appendChild(el('div',{cls:'small',style:'padding:3px 0'},[ el('b',{text:s.carrier+' '+s.service}),' · box '+s.box+' · '+money(s.amount)+' · ',
      s.track_url?el('a',{text:s.tracking||'track',on:function(){ open(s.track_url); }}):(s.tracking||''), ' · ',
      s.label?el('a',{text:'label PDF',on:function(){ open(s.label); }}):null, el('span',{cls:'muted',text:' · '+(s.at||'').slice(0,10)}) ]));
  });
  return c;
}

function addressCard(){
  var c = el('div',{cls:'card'}), t = ST.to;
  c.appendChild(el('h3',{text:'Ship to'+(V.to.source==='billing'?' (from the billing address — the order has no shipping address)':''),style:'margin-top:0'}));
  var g = el('div',{cls:'grid'});
  [['name','Name','w'],['company','Company','w'],['street1','Street','w'],['street2','Apt, suite','w'],['city','City'],['state','State'],['zip','ZIP'],['phone','Phone']].forEach(function(f){ g.appendChild(field(t,f[0],f[1],f[2])); });
  c.appendChild(g);
  var f = CFG.from;
  c.appendChild(el('div',{cls:'muted small',style:'margin-top:6px',text:'From: '+[f.company||f.name, f.street1, f.city+', '+f.state+' '+f.zip].filter(Boolean).join(', ')+(f.phone?' · '+f.phone:'')}));
  if(!f.phone) c.appendChild(el('div',{cls:'warn small',text:'No shop phone is set for the ship-from (PPS Calculators → Missive Shipping). USPS, UPS and FedEx may refuse a label without one.'}));
  return c;
}

function boxCard(){
  var c = el('div',{cls:'card'});
  c.appendChild(el('h3',{text:'Boxes',style:'margin-top:0'}));
  c.appendChild(el('div',{cls:'muted small',text: V.est_weight>0 ? 'The calculator estimated '+V.est_weight+' lb in '+Math.max(1,V.est_cartons)+' carton'+(V.est_cartons===1?'':'s')+'. Weigh the boxes and correct it.' : 'No weight estimate on this order — weigh the boxes.'}));
  ST.boxes.forEach(function(b, i){
    var sel = el('select',{change:function(){ var t = V.boxes.filter(function(x){ return x.id===sel.value; })[0]; b.box=sel.value; if(t){ b.l=t.l; b.w=t.w; b.h=t.h; } ST.quote=null; ST.sel=''; render(); }});
    sel.appendChild(el('option',{value:'',text:'Custom size'}));
    V.boxes.forEach(function(t){ var o=el('option',{value:t.id,text:t.name+' ('+t.l+'×'+t.w+'×'+t.h+')'}); if(t.id===b.box) o.selected=true; sel.appendChild(o); });
    var num = function(k, ph){ var x=el('input',{type:'number',step:'0.1',min:'0',placeholder:ph,title:ph,input:function(){ b[k]=x.value; stale(); }}); x.value=b[k]; return x; };
    // "× 12": twelve boxes like this one, each its own label to the same address.
    var cnt = el('input',{type:'number',step:'1',min:'1',max:String(CFG.maxBoxes),cls:'n',title:'How many boxes like this',change:function(){ b.count=Math.max(1,parseInt(cnt.value,10)||1); stale(); render(); }});
    cnt.value = b.count || 1;
    c.appendChild(el('div',{cls:'box'},[ sel, num('l','L'), '×', num('w','W'), '×', num('h','H'), 'in', num('weight','lb'), 'lb each ×', cnt,
      ST.boxes.length>1?el('a',{cls:'small',text:'remove',on:function(){ ST.boxes.splice(i,1); stale(); render(); }}):null ]));
  });
  var row = el('div',{style:'display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px'});
  var total = boxCount();
  row.appendChild(el('b',{cls:'small',text:total+' label'+(total===1?'':'s')}));
  if(ST.boxes.length<10) row.appendChild(el('a',{cls:'small',text:'+ a box of another size',on:function(){ var nb=JSON.parse(JSON.stringify(ST.boxes[ST.boxes.length-1])); nb.count=1; ST.boxes.push(nb); stale(); render(); }}));
  if(total>CFG.maxBoxes) row.appendChild(el('span',{cls:'late small',text:'At most '+CFG.maxBoxes+' labels at a time.'}));
  var d = el('input',{type:'date',min:V.today,change:function(){ ST.ship=d.value||V.today; ST.quote=null; render(); }}); d.value=ST.ship;
  row.appendChild(el('span',{cls:'small',style:'margin-left:auto'},['Ship on ', d]));
  c.appendChild(row);
  var sig = el('select',{change:function(){ ST.extra.signature=sig.value; stale(); render(); }});
  Object.keys(CFG.signatures).forEach(function(k){ var o=el('option',{value:k,text:CFG.signatures[k]}); if(k===ST.extra.signature) o.selected=true; sig.appendChild(o); });
  var ins = el('input',{type:'number',step:'1',min:'0',placeholder:'0',style:'width:80px',title:'Declared value of each box',change:function(){ ST.extra.insure=ins.value; stale(); render(); }}); ins.value=ST.extra.insure;
  c.appendChild(el('div',{style:'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px'},[ el('span',{cls:'small'},['Signature ', sig]), el('span',{cls:'small'},['Insure each box for $', ins]) ]));
  if(ST.extra.signature || Number(ST.extra.insure)>0) c.appendChild(el('div',{cls:'muted small',text:(ST.extra.signature?'Carriers that cannot take a signature are left out of the rates. ':'')+(Number(ST.extra.insure)>0?'Insurance is through Shippo; its cost is included in each rate below.':'')}));
  var go = el('button',{cls:'go',text:ST.busy&&!ST.quote?'Getting rates…':'Get rates',on:getRates}); if(ST.busy||!CFG.hasKey||total>CFG.maxBoxes) go.disabled=true;
  c.appendChild(el('div',{style:'margin-top:8px'},[go, CFG.hasKey?null:el('span',{cls:'warn small',text:' No Shippo API token is set (PPS Config → Shippo Integration).'})]));
  return c;
}

function ratesCard(){
  var q = ST.quote; if(!q) return null;
  var c = el('div',{cls:'card'});
  var qx = q.extra || {};
  c.appendChild(el('h3',{text:'Rates — '+q.boxes+' label'+(q.boxes===1?'':'s')+', ships '+md(q.ship_date)+(qx.signature?' · '+CFG.signatures[qx.signature]:'')+(qx.insure?' · insured '+money(qx.insure)+'/box':''),style:'margin-top:0'}));
  if(q.must_ship && q.ship_date>q.must_ship) c.appendChild(el('div',{cls:'late small',text:'This ship date is after the must-ship date ('+md(q.must_ship)+').'}));
  if(!q.options.length){ c.appendChild(el('div',{cls:'muted',text:'No carrier offered a service for '+(q.boxes>1?'every one of these boxes':'this box')+'.'})); }
  var carriers = {}; q.options.forEach(function(o){ carriers[o.carrier]=1; });
  var chips = el('div',{cls:'chips'});
  [''].concat(Object.keys(carriers).sort()).forEach(function(k){ chips.appendChild(el('button',{cls:'chip'+(ST.carrier===k?' on':''),text:k||'All carriers',on:function(){ ST.carrier=k; render(); }})); });
  var so = el('select',{change:function(){ ST.sort=so.value; render(); }});
  [['price','Cheapest first'],['fast','Fastest first']].forEach(function(s){ var o=el('option',{value:s[0],text:s[1]}); if(s[0]===ST.sort) o.selected=true; so.appendChild(o); });
  chips.appendChild(so); c.appendChild(chips);
  var list = q.options.filter(function(o){ return !ST.carrier || o.carrier===ST.carrier; }).slice();
  if(ST.sort==='fast') list.sort(function(a,b){ return (a.arrives||'9999').localeCompare(b.arrives||'9999') || a.amount-b.amount; });
  list.forEach(function(o){
    var r = el('div',{cls:'rate'+(ST.sel===o.id?' sel':''),on:function(){ ST.sel=o.id; render(); }});
    var radio = el('input',{type:'radio',name:'rate'}); radio.checked = ST.sel===o.id; r.appendChild(radio);
    var when = o.days ? o.days+' business day'+(o.days===1?'':'s')+' · arrives '+md(o.arrives)+(o.by&&o.by!=='23:59:00'?' by '+o.by.slice(0,5):'') : 'no delivery estimate';
    r.appendChild(el('div',{},[
      el('div',{},[el('b',{text:o.carrier+' '+o.service})].concat(o.tags.map(function(t){ return el('span',{cls:'tag',text:t==='BESTVALUE'?'BEST VALUE':t}); }),
        [el('span',{cls:'tag'+(o.own_account?' own':''),text:o.account})])),
      el('div',{cls:'small'},[when, o.on_time===true?el('span',{cls:'ok',text:' · on time'}):o.on_time===false?el('span',{cls:'late',text:' · LATE vs promised '+md(q.delivery)}):null]),
      o.insurance?el('div',{cls:'muted small',text:'includes '+money(o.insurance)+' insurance'}):null,
      o.terms?el('div',{cls:'muted small',text:o.terms}):null ]));
    r.appendChild(el('div',{cls:'p'},[money(o.amount), o.each?el('div',{cls:'muted small',style:'font-weight:400',text:money(o.each)+' each'}):null]));
    c.appendChild(r);
  });
  if(q.notes.length){ var dt=el('details',{},[el('summary',{cls:'muted small',text:'Carrier notes ('+q.notes.length+')'})]); q.notes.forEach(function(n){ dt.appendChild(el('div',{cls:'small muted',text:n})); }); c.appendChild(dt); }
  var o = q.options.filter(function(x){ return x.id===ST.sel; })[0];
  var b = el('button',{cls:'go',text:ST.busy?'Buying '+q.boxes+' label'+(q.boxes===1?'':'s')+'…':o?'Buy '+(q.boxes===1?'label':q.boxes+' labels')+' — '+money(o.amount):'Pick a rate',on:function(){ buy(false); }});
  if(!o || ST.busy || !CFG.canBuy) b.disabled = true;
  c.appendChild(el('div',{style:'margin-top:8px'},[b, CFG.canBuy?null:el('div',{cls:'muted small',text:'Buying is switched off. Turn on "Let the panel buy labels" on PPS Calculators → Missive Shipping — until then the panel only shows rates.'})]));
  return c;
}

function doneCard(){
  var d = ST.done; if(!d || !d.labels || !d.labels.length) return null;
  var c = el('div',{cls:'card',style:'border-color:#22c55e'});
  c.appendChild(el('div',{cls:'ok',text:'✓ '+d.labels.length+' label'+(d.labels.length===1?'':'s')+' bought'}));
  c.appendChild(el('h3',{text:'Tracking links'}));
  d.labels.forEach(function(s){ c.appendChild(el('div',{cls:'small',style:'padding:2px 0'},[ 'Box '+s.box+' · ', s.track_url?el('a',{text:s.tracking||s.track_url,on:function(){ open(s.track_url); }}):(s.tracking||'—'), ' · ', el('a',{text:'label PDF',on:function(){ open(s.label); }}) ])); });
  // the same list as text, to paste into a reply to the customer
  var text = d.labels.map(function(s){ return 'Box '+s.box+': '+(s.track_url||s.tracking); }).join('\\n');
  var ta = el('textarea',{cls:'links',rows:String(Math.min(12,d.labels.length+1)),readonly:'readonly'}); ta.value = text;
  var cp = el('button',{text:'Copy tracking links',on:function(){ ta.select(); var done=function(){ cp.textContent='Copied ✓'; };
    if(navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function(){ try{ document.execCommand('copy'); done(); }catch(e){} }); else { try{ document.execCommand('copy'); done(); }catch(e){} } }});
  var all = d.labels.length>1 ? el('button',{text:'Open all '+d.labels.length+' label PDFs',on:function(){ d.labels.forEach(function(s){ open(s.label); }); }}) : null;
  c.appendChild(ta); c.appendChild(el('div',{style:'display:flex;gap:6px;margin-top:4px'},[cp, all]));
  c.appendChild(el('div',{cls:'muted small',text:d.attached?'Attached to this order in Shippo, as a label bought there would be. A note is on the WooCommerce order.':'Shippo has no copy of this order from the store sync, so the label stands alone in Shippo. A note is on the WooCommerce order.'}));
  return c;
}

function render(){
  app.textContent='';
  [header(), shipped(), doneCard(), ST.err?el('div',{cls:'err',text:ST.err}):null, addressCard(), boxCard(), ratesCard()].forEach(function(x){ if(x) app.appendChild(x); });
}
render();
})();
</script></body></html>
HTML;
}

add_action( 'wp_loaded', function () {
    if ( ! isset( $_GET['pps_missive_ship'] ) ) return;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    header_remove( 'X-Frame-Options' );
    $deny = function ( $msg ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo $msg; exit; };
    if ( ! function_exists( 'pps_mp_key_ok' ) || ! pps_mp_key_ok( (string) wp_unslash( $_GET['k'] ?? '' ) ) ) $deny( 'This link is not valid. Open shipping from the Missive panel.' );
    $order = (int) ( $_GET['order'] ?? 0 ) > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $_GET['order'] ) : null;
    if ( ! $order ) $deny( 'No such order.' );
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'Referrer-Policy: no-referrer' );
    echo pps_ms_page_html( $order, rest_url( 'pps/v1/missive/ship/rates' ), rest_url( 'pps/v1/missive/ship/buy' ) );
    exit;
} );

/* ── Admin: PPS Calculators → Missive Shipping ────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page( 'pps-calculators', 'Missive Shipping', '🚚 Missive Shipping', 'manage_woocommerce', 'pps-missive-ship', 'pps_ms_render_admin' );
}, 31 );

add_action( 'admin_post_pps_ms_save', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_ms_save' );
    $from = array();
    foreach ( array_keys( pps_ms_defaults()['from'] ) as $k ) $from[ $k ] = sanitize_text_field( wp_unslash( $_POST['from'][ $k ] ?? '' ) );
    $from['state'] = strtoupper( substr( $from['state'], 0, 2 ) );
    $lt = sanitize_text_field( wp_unslash( $_POST['label_type'] ?? '' ) );
    update_option( PPS_MS_OPTION, array( 'from' => $from, 'allow_buy' => ! empty( $_POST['allow_buy'] ), 'label_type' => array_key_exists( $lt, pps_ms_label_types() ) ? $lt : '' ), false );
    delete_transient( 'pps_ms_accounts' ); delete_transient( 'pps_ms_boxes' );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-missive-ship&saved=1' ) );
    exit;
} );

function pps_ms_render_admin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
    $s = pps_ms_settings(); $f = $s['from'];
    echo '<div class="wrap"><h1>Missive Shipping</h1>'
       . '<p>The Missive panel\'s <strong>Ship</strong> button: rates from every active Shippo carrier account for the order in the conversation, with arrival dates against the promised delivery, and — when allowed below — buying the label. Uses the Shippo API token in PPS Config → Shippo Integration.</p>'
       . ( isset( $_GET['saved'] ) ? '<div class="notice notice-success"><p>Saved.</p></div>' : '' );
    if ( pps_ms_shippo_key() === '' ) echo '<div class="notice notice-error inline"><p>No Shippo API token is set (PPS Config → Shippo Integration). The panel cannot get rates until it is.</p></div>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'pps_ms_save', '_wpnonce', true, false )
       . '<input type="hidden" name="action" value="pps_ms_save"><h2>Ship from</h2><p>The return address printed on every label, and where carriers price and time the shipment from.</p><table class="form-table">';
    foreach ( array( 'name' => 'Name', 'company' => 'Company', 'street1' => 'Street', 'street2' => 'Suite', 'city' => 'City', 'state' => 'State', 'zip' => 'ZIP', 'phone' => 'Phone (carriers need one to make a label)', 'email' => 'Email (USPS needs one)' ) as $k => $label ) {
        echo '<tr><th><label>' . esc_html( $label ) . '</label></th><td><input type="text" name="from[' . esc_attr( $k ) . ']" value="' . esc_attr( $f[ $k ] ) . '" class="regular-text"></td></tr>';
    }
    echo '</table><h2>Labels</h2><p><label>Label format <select name="label_type">';
    foreach ( pps_ms_label_types() as $k => $label ) echo '<option value="' . esc_attr( $k ) . '"' . ( $k === $s['label_type'] ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
    echo '</select></label></p><p><label><input type="checkbox" name="allow_buy" value="1"' . ( $s['allow_buy'] ? ' checked' : '' ) . '> <strong>Let the panel buy labels.</strong> '
       . 'Off: the panel shows rates only. On: anyone using the Missive panel link can buy a label for the order in view, after a confirm that shows carrier, service, the account billed and the price. '
       . 'Labels are charged to the Shippo account (or to the carrier account the rate names). Voids and refunds stay in Shippo.</label></p>'
       . '<p><button class="button button-primary">Save</button></p></form>';
    if ( pps_ms_shippo_key() !== '' ) {
        $a = pps_ms_accounts( true ); $b = pps_ms_boxes( true );
        echo '<h2>What the panel will quote</h2>';
        if ( is_wp_error( $a ) ) echo '<p>' . esc_html( $a->get_error_message() ) . '</p>';
        else { echo '<ul>'; foreach ( $a as $x ) echo '<li>' . esc_html( $x['carrier'] . ' — ' . $x['name'] ) . '</li>'; echo '</ul>'; }
        echo '<h2>Saved boxes (Shippo parcel templates)</h2>';
        if ( is_wp_error( $b ) ) echo '<p>' . esc_html( $b->get_error_message() ) . '</p>';
        else { echo '<ul>'; foreach ( $b as $x ) echo '<li>' . esc_html( $x['name'] . ' — ' . $x['l'] . '×' . $x['w'] . '×' . $x['h'] . ' in, ' . $x['weight'] . ' lb' ) . '</li>'; echo '</ul>'; }
    }
    echo '</div>';
}
