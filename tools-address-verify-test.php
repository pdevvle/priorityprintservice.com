<?php
/**
 * The server's half of the 2026-10-04 address checks.
 *
 *   - /pps/v1/shipping/verify: off unless PPS Config says so; Shippo v2 with the token
 *     server-side; cached per address; 10 a minute per IP and a daily cap, because the
 *     endpoint is public and every call costs; every failure answers "unavailable" with
 *     HTTP 200, so the calculator lets the order through.
 *   - What Shippo's answer means, tuned on 50 real orders (2026-10-04): a standardised
 *     spelling, a street suffix, a direction word, "#" for "Apt" and an added ZIP+4 are
 *     not corrections; a building postal data does not hold (campus, dealership) is not
 *     "not found"; a suite it does not list is noted, not asked about; a wrong state or
 *     ZIP is a correction (87238 was entered as AZ with a South Dakota ZIP).
 *   - PO Box recognition, which adds Ground Advantage's extra day and goes on the order note.
 *   - The checkout's address and the order's ship-to come from the same cart line. Until
 *     now the checkout showed the line added last and the order shipped to the first.
 *   - What the daily email says when the customer kept an address the check doubted.
 *
 * Lifts the functions out of pps-calculators.php. PPS_CALC_PHP points at another copy.
 *
 * Run: php tools-address-verify-test.php
 */
$GLOBALS['t_checks'] = 0; $GLOBALS['t_failed'] = 0;
function ok( $label, $cond, $detail = '' ) {
    $GLOBALS['t_checks']++;
    if ( $cond ) { echo "PASS $label" . ( $detail ? "  $detail" : '' ) . "\n"; return; }
    $GLOBALS['t_failed']++;
    echo "FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}
$src = file_get_contents( __DIR__ . '/' . ( getenv( 'PPS_CALC_PHP' ) ?: 'pps-calculators.php' ) );
function lift_at( $src, $at ) {
    $open = strpos( $src, '{', $at ); $depth = 0;
    for ( $i = $open; $i < strlen( $src ); $i++ ) {
        if ( $src[$i] === '{' ) $depth++;
        elseif ( $src[$i] === '}' && --$depth === 0 ) return substr( $src, $at, $i - $at + 1 );
    }
    return '';
}
function lift( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    return $at === false ? '' : lift_at( $src, $at );
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
define( 'MINUTE_IN_SECONDS', 60 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'PPS_TEST', 1 );

$want = array( 'pps_clean_text', 'pps_is_po_box', 'pps_addr_norm', 'pps_addr_loose', 'pps_addr_unit', 'pps_addr_verify_classify',
               'pps_addr_verify_enabled', 'pps_addr_check_problems', 'pps_prefill_customer_shipping', 'pps_prefill_from_cart',
               'pps_addr_google_key', 'pps_addr_provider', 'pps_addr_verify_classify_google', 'pps_zip_city_table', 'pps_zip_city_hint' );
$have = array();
foreach ( $want as $n ) { $f = lift( $src, $n ); if ( $f !== '' ) { eval( $f ); $have[ $n ] = true; } }
$missing = array_diff( $want, array_keys( $have ) );
ok( 'every function the address checks need is in the plugin', ! $missing, 'missing: ' . implode( ', ', $missing ) );
if ( $missing ) { echo "\n>>> " . ( $GLOBALS['t_checks'] - $GLOBALS['t_failed'] ) . '/' . $GLOBALS['t_checks'] . " passed — {$GLOBALS['t_failed']} FAILED\n"; exit( 1 ); }
if ( ! defined( 'PPS_ADDR_VERIFY_DAILY_CAP' ) ) define( 'PPS_ADDR_VERIFY_DAILY_CAP', 300 );

// ── 1. What a Shippo answer means ──
echo "── classifying Shippo's answers ──\n";
$rec = function( $l1, $l2, $city, $st, $zip, $score = 'high' ) {
    return array( 'address_line_1' => $l1, 'address_line_2' => $l2, 'city_locality' => $city, 'state_province' => $st, 'postal_code' => $zip,
                  'confidence_result' => array( 'score' => $score ) );
};
$res = function( $value, $reasons, $recd = null, $type = 'commercial' ) {
    $r = array( 'analysis' => array( 'validation_result' => array( 'value' => $value, 'reasons' => array_map( function( $c ) { return array( 'code' => $c ); }, $reasons ) ), 'address_type' => $type ) );
    if ( $recd ) $r['recommended_address'] = $recd;
    return $r;
};
// The trade-show order the owner's first test used (87284): standardised, ZIP+4 added.
$c = pps_addr_verify_classify( array( 'street1' => '7000 Lindell Road', 'city' => 'Las Vegas', 'state' => 'NV', 'zip' => '89118' ),
    $res( 'partially_valid', array( 'address_abbreviation_fixed' ), $rec( '7000 Lindell Rd', '', 'Las Vegas', 'NV', '89118-4702' ) ) );
ok( 'a spelling standardised and a ZIP+4 added is "verified", not a correction to ask about', $c['status'] === 'verified' && $c['type'] === 'commercial', json_encode( $c ) );
$c = pps_addr_verify_classify( array( 'street1' => '1 Main St', 'city' => 'Boston', 'state' => 'MA', 'zip' => '02108' ), $res( 'valid', array(), null, 'residential' ) );
ok( 'a valid answer (which carries no recommendation) is verified, residential', $c['status'] === 'verified' && $c['type'] === 'residential', json_encode( $c ) );
$c = pps_addr_verify_classify( array( 'street1' => '123 Main Street', 'city' => 'Phoenix', 'state' => 'TX', 'zip' => '85001' ), $res( 'invalid', array( 'address_not_found' ), null, 'po_box' ) );
ok( 'an address not in postal data is "not_found"', $c['status'] === 'not_found', json_encode( $c ) );
// 87238: a South Dakota ZIP entered as Arizona.
$c = pps_addr_verify_classify( array( 'street1' => '222 Disk Drive', 'city' => 'RAPID CITY', 'state' => 'AZ', 'zip' => '57701' ),
    $res( 'partially_valid', array( 'city_state_corrected', 'address_abbreviation_fixed' ), $rec( '222 Disk Dr', '', 'Rapid City', 'SD', '57701-7805' ) ) );
ok( 'a wrong state is a correction, with the right one suggested (87238)', $c['status'] === 'corrected' && $c['suggested']['state'] === 'SD', json_encode( $c ) );
// 87301: a campus ZIP the post office files under another.
$c = pps_addr_verify_classify( array( 'street1' => '3401 Watkins Drive', 'city' => 'Riverside', 'state' => 'CA', 'zip' => '92521' ),
    $res( 'partially_valid', array( 'zip_post_code_corrected' ), $rec( '3401 Watkins Dr', '', 'Riverside', 'CA', '92507-4633' ) ) );
ok( 'a different five-digit ZIP is a correction (87301)', $c['status'] === 'corrected' && $c['suggested']['zip'] === '92507-4633', json_encode( $c ) );
// 87300: "113" in line 2, which USPS does not list for the building.
$c = pps_addr_verify_classify( array( 'street1' => '7307 Melrose Ave', 'street2' => '113', 'city' => 'Los Angeles', 'state' => 'CA', 'zip' => '90046-7512' ),
    $res( 'partially_valid', array( 'address_confirmed_invalid_secondary' ), $rec( '7307 Melrose Ave', '# 113', 'Los Angeles', 'CA', '90046-7512' ) ) );
ok( 'a suite postal data does not list is verified and noted, not asked about (87300)', $c['status'] === 'verified' && ! empty( $c['unitUnconfirmed'] ), json_encode( $c ) );
// 87321: a campus building — low confidence, and the "suggestion" drops the building name.
$c = pps_addr_verify_classify( array( 'street1' => '501 South Nedderman Drive', 'street2' => 'Life Sciences Bldg. Rm 206', 'city' => 'Arlington', 'state' => 'TX', 'zip' => '76010' ),
    $res( 'partially_valid', array( 'address_found_non_postal_match' ), $rec( '501 S Nedderman Dr', 'Rm 206', 'Arlington', 'TX', '76010', 'low' ), 'unknown' ) );
ok( 'a building postal data does not hold is not offered a suggestion that drops its name (87321)', $c['status'] === 'verified' && ! empty( $c['lowConfidence'] ) && $c['suggested'] === null, json_encode( $c ) );
// 87324: "#1E" for "Apt 1E", and no street suffix typed.
$c = pps_addr_verify_classify( array( 'street1' => '7068 N Ashland #1E', 'city' => 'Chicago', 'state' => 'IL', 'zip' => '60626' ),
    $res( 'partially_valid', array( 'address_found_suffix_modified', 'street_name_corrected' ), $rec( '7068 N Ashland Ave', 'Apt 1E', 'Chicago', 'IL', '60626-2512' ) ) );
ok( 'a missing suffix and "#" for "Apt" are not corrections (87324)', $c['status'] === 'verified', json_encode( $c ) );
$c = pps_addr_verify_classify( array( 'street1' => '100 Main St', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701' ),
    $res( 'partially_valid', array( 'address_missing_secondary' ), $rec( '100 Main St', '', 'Springfield', 'IL', '62701-1234' ), 'residential' ) );
ok( 'an apartment building with no unit typed asks for one', $c['status'] === 'unit', json_encode( $c ) );
$c = pps_addr_verify_classify( array( 'street1' => '100 Main St', 'street2' => 'Apt 4', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701' ),
    $res( 'partially_valid', array( 'address_missing_secondary' ), $rec( '100 Main St', 'Apt 4', 'Springfield', 'IL', '62701-1234' ), 'residential' ) );
ok( 'but not when the customer gave one', $c['status'] !== 'unit', json_encode( $c ) );
$c = pps_addr_verify_classify( array( 'street1' => '12 Oak St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701' ),
    $res( 'partially_valid', array( 'street_name_corrected' ), $rec( '12 Elm St', '', 'Austin', 'TX', '78701' ) ) );
ok( 'a different street name is a correction', $c['status'] === 'corrected' && $c['suggested']['street1'] === '12 Elm St', json_encode( $c ) );
ok( 'an empty or unreadable answer is "unavailable"', pps_addr_verify_classify( array(), array() )['status'] === 'unavailable' );

// ── 2. PO Boxes ──
echo "── PO Box ──\n";
foreach ( array( 'PO Box 12', 'P.O. Box 5', 'p o box 77', 'POB 7', 'Post Office Box 9', 'Box 44', 'PO BOX #3' ) as $s ) ok( "\"$s\" is a PO Box", pps_is_po_box( $s ) );
foreach ( array( '123 Box Elder St', '1 Poblano Way', 'PMB 200', '4 Postal Way', '55 Boxwood Ln' ) as $s ) ok( "\"$s\" is not", ! pps_is_po_box( $s ) );
ok( 'a PO Box in line 2 counts', pps_is_po_box( 'Acme Inc', 'PO Box 9' ) );
ok( 'the order note says to ship a PO Box Ground Advantage', strpos( $src, "PO BOX — UPS cannot deliver; ship Ground Advantage." ) !== false );
ok( 'the order note carries line 2 (it dropped the unit: 87287, 87251, 87300)', strpos( $src, "\$addr['street1'] . ( \$addr['street2'] !== '' ? ', ' . \$addr['street2'] : '' )" ) !== false );

// ── 2b. Google Address Validation answers ──
echo "── classifying Google's answers ──\n";
$g = function( $dpv, $gran, $action, $sa = null, $md = array( 'business' => true ), $missing = array() ) {
    $r = array( 'result' => array( 'verdict' => array( 'validationGranularity' => $gran, 'possibleNextAction' => $action ), 'metadata' => $md,
        'address' => array( 'missingComponentTypes' => $missing ) ) );
    if ( $dpv !== null ) $r['result']['uspsData'] = array( 'dpvConfirmation' => $dpv, 'standardizedAddress' => $sa ?: array() );
    return $r;
};
$lv = array( 'firstAddressLine' => '7000 LINDELL RD', 'city' => 'LAS VEGAS', 'state' => 'NV', 'zipCode' => '89118', 'zipCodeExtension' => '4702' );
$c = pps_addr_verify_classify_google( array( 'street1' => '7000 Lindell Road', 'city' => 'Las Vegas', 'state' => 'NV', 'zip' => '89118' ), $g( 'Y', 'PREMISE', 'ACCEPT', $lv ) );
ok( 'Google: deliverable and only standardised is verified, commercial', $c['status'] === 'verified' && $c['type'] === 'commercial', json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '222 Disk Drive', 'city' => 'Rapid City', 'state' => 'AZ', 'zip' => '57701' ),
    $g( 'Y', 'PREMISE', 'CONFIRM', array( 'firstAddressLine' => '222 DISK DR', 'city' => 'RAPID CITY', 'state' => 'SD', 'zipCode' => '57701', 'zipCodeExtension' => '7805' ) ) );
ok( 'Google: a wrong state is a correction with the USPS form suggested (87238)', $c['status'] === 'corrected' && $c['suggested']['state'] === 'SD' && $c['suggested']['zip'] === '57701-7805', json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '99999 Nowhere Rd', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85003' ), $g( 'N', 'ROUTE', 'FIX' ) );
ok( 'Google: not deliverable and not placed is not_found', $c['status'] === 'not_found', json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '501 S Nedderman Dr', 'street2' => 'Life Sciences Bldg Rm 206', 'city' => 'Arlington', 'state' => 'TX', 'zip' => '76010' ), $g( 'N', 'PREMISE', 'CONFIRM', null, array() ) );
ok( 'Google: a building Google places but USPS does not deliver by name is verified, low confidence (87321)', $c['status'] === 'verified' && ! empty( $c['lowConfidence'] ) && $c['suggested'] === null, json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '100 Main St', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701' ), $g( 'D', 'PREMISE', 'CONFIRM_ADD_SUBPREMISES', null, array( 'residential' => true ) ) );
ok( 'Google: a missing apartment number asks for one', $c['status'] === 'unit' && $c['type'] === 'residential', json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '100 Main St', 'street2' => 'Apt 4', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701' ), $g( 'D', 'PREMISE', 'CONFIRM_ADD_SUBPREMISES' ) );
ok( 'Google: but not when one was typed', $c['status'] !== 'unit', json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '3510 Scotts Lane', 'street2' => 'STE 3019', 'city' => 'Philadelphia', 'state' => 'PA', 'zip' => '19129' ),
    $g( 'S', 'PREMISE', 'ACCEPT', array( 'firstAddressLine' => '3510 SCOTTS LN STE 3019', 'city' => 'PHILADELPHIA', 'state' => 'PA', 'zipCode' => '19129' ) ) );
ok( 'Google: a suite USPS does not list is verified and noted (87318)', $c['status'] === 'verified' && ! empty( $c['unitUnconfirmed'] ), json_encode( $c ) );
$c = pps_addr_verify_classify_google( array( 'street1' => '1 X', 'zip' => '85003' ), $g( null, 'OTHER', 'FIX' ) );
ok( 'Google: no USPS answer and no building found says nothing rather than guess', in_array( $c['status'], array( 'unavailable', 'not_found' ), true ), json_encode( $c ) );
ok( 'Google: an empty answer is unavailable', pps_addr_verify_classify_google( array(), array() )['status'] === 'unavailable' );
$c = pps_addr_verify_classify_google( array( 'street1' => 'PO Box 12', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85001' ), $g( 'Y', 'PREMISE', 'ACCEPT', null, array( 'poBox' => true ) ) );
ok( 'Google: a PO Box is typed po_box', $c['type'] === 'po_box', json_encode( $c ) );

// ── 2c. The free ZIP → city hint ──
echo "── ZIP → city ──\n";
ok( 'the ZIP → city table loads', count( pps_zip_city_table()['z'] ?? array() ) > 40000 );
ok( 'the right city gives no hint', pps_zip_city_hint( '85003', 'Phoenix' ) === null );
ok( 'case, punctuation and Saint/St do not matter', pps_zip_city_hint( '63101', 'St. Louis' ) === null && pps_zip_city_hint( '85003', 'PHOENIX' ) === null );
ok( 'another accepted name in the same ZIP area gives no hint (the shop: New River 85086, which GeoNames files as Phoenix)', pps_zip_city_hint( '85086', 'New River' ) === null );
$h = pps_zip_city_hint( '85003', 'Pheonix' );
ok( 'a misspelling is offered the ZIP\'s city', is_array( $h ) && $h['zipCity'] === 'Phoenix' && $h['typo'] === true, json_encode( $h ) );
$h = pps_zip_city_hint( '85003', 'Tucson' );
ok( 'a city from elsewhere gets a hint that is not called a typo', is_array( $h ) && $h['zipCity'] === 'Phoenix' && $h['typo'] === false, json_encode( $h ) );
ok( 'an unknown ZIP or a blank city gives no hint', pps_zip_city_hint( '00000', 'X' ) === null && pps_zip_city_hint( '85003', '' ) === null );
ok( 'the table is attributed to GeoNames (CC BY 4.0)', strpos( file_get_contents( __DIR__ . '/pps-zip-city.php', false, null, 0, 400 ), 'GeoNames' ) !== false );

// ── 3. The endpoint ──
echo "── /pps/v1/shipping/verify ──\n";
$at = strpos( $src, "register_rest_route( 'pps/v1', '/shipping/verify'" );
ok( 'the endpoint is registered', $at !== false );
$cbat = strpos( $src, "'callback'", $at );
$fnat = strpos( $src, 'function( $request )', $cbat );
ok( 'and is public (most customers are guests)', strpos( substr( $src, $at, $cbat - $at ), "'permission_callback' => '__return_true'" ) !== false );
eval( '$cb = ' . lift_at( $src, $fnat ) . ';' );
$GLOBALS['cfg'] = array( 'pcf' => array( 'shippo_api_token' => 'shippo_live_x', 'address_verify' => 0 ) );
function pps_get_config() { return $GLOBALS['cfg']; }
$GLOBALS['tr'] = array(); $GLOBALS['opt'] = array(); $GLOBALS['http'] = array(); $GLOBALS['http_reply'] = null;
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function wp_remote_get( $url, $args ) { $GLOBALS['http'][] = array( $url, $args ); $r = $GLOBALS['http_reply']; if ( $r instanceof \Throwable ) throw $r; return $r; }
function wp_remote_post( $url, $args ) { $GLOBALS['http'][] = array( $url, $args, 'POST' ); $r = $GLOBALS['http_reply']; if ( $r instanceof \Throwable ) throw $r; return $r; }
function wp_json_encode( $x ) { return json_encode( $x ); }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function rest_ensure_response( $x ) { return $x; }
class FakeReq { public $p; function __construct( $p ) { $this->p = $p; } function get_json_params() { return $this->p; } }
$addr = array( 'street1' => '7000 Lindell Road', 'city' => 'Las Vegas', 'state' => 'NV', 'zip' => '89118' );
$good = array( 'code' => 200, 'body' => json_encode( $res( 'partially_valid', array( 'address_abbreviation_fixed' ), $rec( '7000 Lindell Rd', '', 'Las Vegas', 'NV', '89118-4702' ) ) ) );

$r = $cb( new FakeReq( $addr ) );
ok( 'off by default: answers "off" and spends nothing', ( $r['status'] ?? '' ) === 'off' && ! $GLOBALS['http'], json_encode( $r ) );
$GLOBALS['cfg']['pcf']['address_verify'] = 1.0;   // the admin form saves numbers as floats
$GLOBALS['cfg']['pcf']['shippo_api_token'] = '';
$r = $cb( new FakeReq( $addr ) );
ok( 'on without any provider key: still "off"', ( $r['status'] ?? '' ) === 'off' && ! $GLOBALS['http'], json_encode( $r ) );
$GLOBALS['cfg']['pcf']['shippo_api_token'] = 'shippo_live_x';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$GLOBALS['http_reply'] = $good;
$r = $cb( new FakeReq( $addr ) );
$call = $GLOBALS['http'][0] ?? array( '', array() );
ok( 'on: asks Shippo v2 once and answers verified', ( $r['status'] ?? '' ) === 'verified' && count( $GLOBALS['http'] ) === 1, json_encode( $r ) );
ok( 'with the address in the query and the token in the header, never in the URL',
    strpos( $call[0], 'https://api.goshippo.com/v2/addresses/validate?' ) === 0 && strpos( $call[0], 'address_line_1=7000%20Lindell%20Road' ) !== false
    && strpos( $call[0], 'postal_code=89118' ) !== false && strpos( $call[0], 'shippo_live_x' ) === false
    && ( $call[1]['headers']['Authorization'] ?? '' ) === 'ShippoToken shippo_live_x', $call[0] );
ok( 'with a short timeout, so a slow Shippo cannot hold the customer', ( $call[1]['timeout'] ?? 99 ) <= 6 );
$r = $cb( new FakeReq( $addr ) );
ok( 'the same address again is served from cache, free', ! empty( $r['cached'] ) && count( $GLOBALS['http'] ) === 1, json_encode( $r ) );
ok( 'and what was spent is counted', ( $GLOBALS['opt']['pps_addrv_spend']['n'] ?? 0 ) === 1 && ( $GLOBALS['opt']['pps_addrv_spend']['total'] ?? 0 ) === 1, json_encode( $GLOBALS['opt']['pps_addrv_spend'] ?? null ) );

$GLOBALS['http_reply'] = new WP_Error( 'x', 'timeout' );
$r = $cb( new FakeReq( array( 'street1' => '1 Other St', 'zip' => '85003' ) ) );
ok( 'a Shippo failure answers "unavailable" (the order goes through)', ( $r['status'] ?? '' ) === 'unavailable', json_encode( $r ) );
$GLOBALS['http_reply'] = $good;
$n = count( $GLOBALS['http'] );
$cb( new FakeReq( array( 'street1' => '1 Other St', 'zip' => '85003' ) ) );
ok( 'and is not cached, so the next try asks again', count( $GLOBALS['http'] ) === $n + 1 );
$GLOBALS['http_reply'] = array( 'code' => 401, 'body' => '{"detail":"bad token"}' );
$r = $cb( new FakeReq( array( 'street1' => '2 Other St', 'zip' => '85003' ) ) );
ok( 'a refused token answers "unavailable"', ( $r['status'] ?? '' ) === 'unavailable' );
$GLOBALS['http_reply'] = new \RuntimeException( 'boom' );
$r = $cb( new FakeReq( array( 'street1' => '3 Other St', 'zip' => '85003' ) ) );
ok( 'anything that throws answers "unavailable"', ( $r['status'] ?? '' ) === 'unavailable' );
$r = $cb( new FakeReq( array( 'city' => 'Phoenix' ) ) );
ok( 'too little to check answers "unavailable" without spending', ( $r['status'] ?? '' ) === 'unavailable' );

$GLOBALS['http_reply'] = $good; $GLOBALS['tr'] = array();
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$spent0 = count( $GLOBALS['http'] );
for ( $i = 0; $i < 12; $i++ ) $last = $cb( new FakeReq( array( 'street1' => ( 100 + $i ) . ' Main St', 'zip' => '85003' ) ) );
ok( 'one visitor gets 10 lookups a minute, then "unavailable"', count( $GLOBALS['http'] ) - $spent0 === 10 && ( $last['status'] ?? '' ) === 'unavailable', 'calls=' . ( count( $GLOBALS['http'] ) - $spent0 ) );
$GLOBALS['tr'] = array();
$GLOBALS['opt']['pps_addrv_spend'] = array( 'day' => gmdate( 'Y-m-d' ), 'n' => PPS_ADDR_VERIFY_DAILY_CAP, 'total' => 999 );
$spent0 = count( $GLOBALS['http'] );
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$r = $cb( new FakeReq( array( 'street1' => '9 Cap St', 'zip' => '85003' ) ) );
ok( 'the daily cap stops spending for the day', ( $r['status'] ?? '' ) === 'unavailable' && count( $GLOBALS['http'] ) === $spent0 );
$GLOBALS['opt']['pps_addrv_spend']['day'] = '2000-01-01';
$cb( new FakeReq( array( 'street1' => '9 Cap St', 'zip' => '85003' ) ) );
ok( 'and resets the next day, keeping the running total', count( $GLOBALS['http'] ) === $spent0 + 1 && $GLOBALS['opt']['pps_addrv_spend']['n'] === 1 && $GLOBALS['opt']['pps_addrv_spend']['total'] === 1000, json_encode( $GLOBALS['opt']['pps_addrv_spend'] ) );

// Google as the provider
$GLOBALS['tr'] = array(); $GLOBALS['opt'] = array(); $GLOBALS['http'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$GLOBALS['cfg'] = array( 'pcf' => array( 'shippo_api_token' => 'shippo_live_x', 'address_verify' => 1 ), 'seo' => array( 'places_api_key' => 'AIzaPLACES' ) );
ok( 'a Google key (the Places key, when no dedicated one) makes Google the provider, ahead of Shippo', pps_addr_provider( $GLOBALS['cfg'] ) === 'google' );
$GLOBALS['http_reply'] = array( 'code' => 200, 'body' => json_encode( $g( 'Y', 'PREMISE', 'ACCEPT', $lv ) ) );
$r = $cb( new FakeReq( $addr ) );
$call = $GLOBALS['http'][0] ?? array( '', array(), '' );
$sent = json_decode( $call[1]['body'] ?? '{}', true );
ok( 'Google: one POST to validateAddress, key in the header and never the URL',
    count( $GLOBALS['http'] ) === 1 && ( $call[2] ?? '' ) === 'POST' && $call[0] === 'https://addressvalidation.googleapis.com/v1:validateAddress'
    && ( $call[1]['headers']['X-Goog-Api-Key'] ?? '' ) === 'AIzaPLACES' && strpos( $call[0], 'AIza' ) === false, $call[0] );
ok( 'Google: asks for the USPS answer, US region, the lines as typed',
    ! empty( $sent['enableUspsCass'] ) && ( $sent['address']['regionCode'] ?? '' ) === 'US' && ( $sent['address']['addressLines'] ?? array() ) === array( '7000 Lindell Road' )
    && ( $sent['address']['postalCode'] ?? '' ) === '89118', json_encode( $sent ) );
ok( 'Google: answers verified and says which provider', ( $r['status'] ?? '' ) === 'verified' && ( $r['provider'] ?? '' ) === 'google', json_encode( $r ) );
ok( 'and the spend counts it under google', ( $GLOBALS['opt']['pps_addrv_spend']['google'] ?? 0 ) === 1 );
$GLOBALS['cfg']['pcf']['google_address_api_key'] = 'AIzaDEDICATED';
ok( 'a dedicated address key wins over the Places key', pps_addr_google_key( $GLOBALS['cfg'] ) === 'AIzaDEDICATED' );

// The free half, always on
$GLOBALS['http'] = array();
$r = $cb( new FakeReq( array( 'street1' => '100 N 1st Ave', 'city' => 'Pheonix', 'state' => 'AZ', 'zip' => '85003', 'localOnly' => 1 ) ) );
ok( 'localOnly answers the city hint and spends nothing', ( $r['status'] ?? '' ) === 'off' && ( $r['cityHint']['zipCity'] ?? '' ) === 'Phoenix' && ! $GLOBALS['http'], json_encode( $r ) );
$GLOBALS['cfg']['pcf']['address_verify'] = 0;
$r = $cb( new FakeReq( array( 'street1' => '100 N 1st Ave', 'city' => 'Pheonix', 'state' => 'AZ', 'zip' => '85003' ) ) );
ok( 'with verification off the hint still comes back, free', ( $r['status'] ?? '' ) === 'off' && ( $r['cityHint']['typo'] ?? false ) === true && ! $GLOBALS['http'], json_encode( $r ) );

// Keys never reach the browser
ok( 'the public config drops the Google address key and the Places key',
    strpos( $src, "\$cfg['pcf']['google_address_api_key'],\n            \$cfg['pcf']['question_recipient_email']" ) !== false
    && strpos( $src, "unset( \$cfg['seo']['places_api_key'] );" ) !== false );

// ── 4. One ship-to per cart ──
echo "── the checkout's address and the order's ship-to ──\n";
class FakeCustomer { public $v = array(); public $saves = 0;
    function __call( $n, $a ) { if ( strpos( $n, 'set_' ) === 0 ) $this->v[ substr( $n, 4 ) ] = $a[0]; }
    function save() { $this->saves++; } }
class FakeSession { public $d = array(); function get( $k ) { return $this->d[ $k ] ?? null; } function set( $k, $v ) { $this->d[ $k ] = $v; } }
class FakeCart { public $items = array(); function get_cart() { return $this->items; } }
class FakeWC { public $customer, $cart, $session; }
$GLOBALS['wc'] = new FakeWC(); $GLOBALS['wc']->customer = new FakeCustomer(); $GLOBALS['wc']->cart = new FakeCart(); $GLOBALS['wc']->session = new FakeSession();
function WC() { return $GLOBALS['wc']; }
$line = function( $street, $zip, $state ) { return array( 'pps_metadata' => json_encode( array( 'shipState' => $state, 'shipAddr' => array( 'name' => 'A B', 'street1' => $street, 'city' => 'X', 'zip' => $zip ) ) ) ); };
$GLOBALS['wc']->cart->items = array( 'k1' => $line( '1 First St', '85003', 'AZ' ) );
pps_prefill_from_cart();
ok( 'one line: the checkout gets its address', ( $GLOBALS['wc']->customer->v['shipping_address_1'] ?? '' ) === '1 First St' );
$GLOBALS['wc']->cart->items['k2'] = $line( '2 Second St', '10001', 'NY' );
pps_prefill_from_cart();
ok( 'a second line to another address does not replace it — the order ships to the first line, so the checkout shows the first',
    ( $GLOBALS['wc']->customer->v['shipping_address_1'] ?? '' ) === '1 First St', $GLOBALS['wc']->customer->v['shipping_address_1'] ?? '' );
$saves = $GLOBALS['wc']->customer->saves;
pps_prefill_from_cart();
ok( 'running again with nothing changed writes nothing (a typed-over checkout address is left alone)', $GLOBALS['wc']->customer->saves === $saves );
unset( $GLOBALS['wc']->cart->items['k1'] );
pps_prefill_from_cart();
ok( 'removing the first line moves the checkout to the new first line', ( $GLOBALS['wc']->customer->v['shipping_address_1'] ?? '' ) === '2 Second St' && ( $GLOBALS['wc']->customer->v['billing_state'] ?? '' ) === 'NY' );
ok( 'and runs when a line is removed', preg_match( "/add_action\( 'woocommerce_cart_item_removed', function\(\) \{\s*try \{ pps_prefill_from_cart\(\);/", $src ) === 1 );
ok( 'add to cart fills the checkout after an edit has removed the old line, not before',
    strpos( $src, "WC()->session->set( 'pps_edit_key_' . \$product_id, null );\n        }\n\n" ) !== false
    && strpos( $src, 'pps_prefill_from_cart();', strpos( $src, "WC()->session->set( 'pps_edit_key_' . \$product_id, null );" ) ) !== false
    && strpos( $src, 'pps_prefill_customer_shipping( $metadata );' ) === false );

// ── 5. What the daily email says ──
echo "── the exceptions email ──\n";
ok( 'nothing to say about a verified address', pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'verified' ) ) ) === array() );
ok( 'nothing to say when the check was off or unavailable', pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'unavailable' ) ) ) === array() );
$p = pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'not_found', 'used' => 'entered' ) ) );
ok( 'a not-found address the customer kept is listed', count( $p ) === 1 && strpos( $p[0], 'not found in postal data' ) !== false, json_encode( $p ) );
$p = pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'corrected', 'used' => 'entered', 'suggested' => array( 'street1' => '222 Disk Dr', 'city' => 'Rapid City', 'state' => 'SD', 'zip' => '57701' ) ) ) );
ok( 'a suggestion the customer declined is listed with the suggestion', count( $p ) === 1 && strpos( $p[0], 'Rapid City SD 57701' ) !== false, json_encode( $p ) );
ok( 'a suggestion the customer took is not', pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'corrected', 'used' => 'suggested' ) ) ) === array() );
$p = pps_addr_check_problems( array( 'addrCheck' => array( 'status' => 'off', 'zipState' => 'SD' ), 'proofAddrCheck' => array( 'status' => 'unit', 'used' => 'entered' ) ) );
ok( 'a ZIP kept against its state is listed even with verification off, and the proof address is checked too',
    count( $p ) === 2 && strpos( $p[0], 'ZIP belongs to SD' ) !== false && strpos( $p[1], 'Proof address' ) === 0, json_encode( $p ) );
$jh = file_get_contents( __DIR__ . '/pps-job-health.php' );
ok( 'the daily email has the section and fills it from every calculator line',
    strpos( $jh, "'address'  => array( 'title' => 'Address the postal check could not confirm (customer kept it)'" ) !== false
    && strpos( $jh, "foreach ( pps_addr_check_problems( \$meta ) as \$p ) \$sec['address']['items'][] = \$ref . ' — ' . \$p;" ) !== false );

// ── 6. The switch ──
$adm = file_get_contents( __DIR__ . '/pps-config-admin.php' );
ok( 'PPS Config has the switch, off by default, and the PO Box day, 1 by default',
    preg_match( "/'address_verify'\s+=> 0,/", $adm ) && preg_match( "/'po_box_extra_days'\s+=> 1,/", $adm )
    && strpos( $adm, "'address_verify'       => array( 'Verify Addresses" ) !== false );
ok( 'the token is never sent to the browser with the switch', strpos( $src, "unset(\n            \$cfg['pcf']['shippo_api_token']" ) !== false );

echo "\n>>> " . ( $GLOBALS['t_checks'] - $GLOBALS['t_failed'] ) . '/' . $GLOBALS['t_checks'] . ' passed' . ( $GLOBALS['t_failed'] ? " — {$GLOBALS['t_failed']} FAILED" : '' ) . "\n";
exit( $GLOBALS['t_failed'] ? 1 : 0 );
