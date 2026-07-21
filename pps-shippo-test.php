<?php
/**
 * PPS Shippo Integration Test Runner (staging diagnostic tool)
 *
 * Runs a full integration-test battery against the Shippo shipping stack
 * ON THE SERVER (the only place with both the API token and egress to
 * api.goshippo.com), then stores structured results in wp_options.
 *
 * How to run (no UI — driven via options, e.g. through the MCP bridge):
 *   1. Set option  'pps_shippo_test_trigger'  to any non-empty string.
 *   2. Make any WordPress request (the suite runs on the next request's
 *      wp_loaded hook, then consumes the trigger).
 *   3. Read option 'pps_shippo_test_results' for the outcome.
 *
 * Spend profile: uses only Shippo's rating + address APIs, which are free —
 * a typical run makes ~4 external calls and never purchases a label.
 * Deterministic: clears its own test-ZIP caches at the start of each run;
 * the rate-limit test pre-seeds the counter instead of burning 20 lookups.
 *
 * Safe-by-default: does nothing unless the trigger option is set (setting
 * options requires admin/MCP), takes a 120s lock, and consumes the trigger
 * BEFORE running so a mid-run fatal can never loop the suite.
 *
 * See docs/SHIPPO_TESTING.md for the full test regime.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_loaded', 'pps_shippo_test_maybe_run', 99 );

function pps_shippo_test_maybe_run() {
    if ( '' === (string) get_option( 'pps_shippo_test_trigger', '' ) ) return;
    if ( get_transient( 'pps_shippo_test_lock' ) ) return;
    set_transient( 'pps_shippo_test_lock', 1, 120 );
    delete_option( 'pps_shippo_test_trigger' ); // consume first — a fatal must not re-run forever
    if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 180 ); } // loopback probes + live calls can exceed the default limit
    if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); } // stale full-page cache can pin shippo_enabled:false in the served HTML
    $results = pps_shippo_test_run_suite();
    update_option( 'pps_shippo_test_results', $results, false );
    delete_transient( 'pps_shippo_test_lock' );
}

/** POST an internal REST request through the real dispatch (permissions included). */
function pps_shippo_test_rest( $body, $route = '/pps/v1/shipping/transit-estimate' ) {
    $req = new WP_REST_Request( 'POST', $route );
    $req->set_header( 'Content-Type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res = rest_do_request( $req );
    if ( is_wp_error( $res ) ) {
        return array( 'status' => 0, 'data' => array( 'error' => $res->get_error_message() ) );
    }
    $data = $res->get_data();
    return array( 'status' => (int) $res->get_status(), 'data' => is_array( $data ) ? $data : (array) $data );
}

/** One raw rates call straight at Shippo — proves egress + auth independent of our REST layer. */
function pps_shippo_test_raw_rate( $token, $from_zip, $to_zip, $to_state ) {
    $t0   = microtime( true );
    $resp = wp_remote_post( 'https://api.goshippo.com/shipments/', array(
        'headers' => array(
            'Authorization' => 'ShippoToken ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( array(
            'address_from' => array( 'zip' => $from_zip, 'country' => 'US' ),
            'address_to'   => array( 'zip' => $to_zip, 'state' => $to_state, 'country' => 'US' ),
            'parcels'      => array( array( 'length' => '12', 'width' => '10', 'height' => '2', 'distance_unit' => 'in', 'weight' => '2', 'mass_unit' => 'lb' ) ),
            'async'        => false,
        ) ),
        'timeout' => 12,
    ) );
    $ms = round( ( microtime( true ) - $t0 ) * 1000 );
    if ( is_wp_error( $resp ) ) {
        return array( 'pass' => false, 'detail' => 'egress/transport error: ' . $resp->get_error_message() . " ({$ms}ms)" );
    }
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( 401 === $code ) {
        return array( 'pass' => false, 'detail' => "401 UNAUTHORIZED — token invalid or rotated ({$ms}ms)" );
    }
    if ( $code < 200 || $code >= 300 ) {
        return array( 'pass' => false, 'detail' => "HTTP $code " . substr( (string) wp_json_encode( $body ), 0, 160 ) . " ({$ms}ms)" );
    }
    $rates  = isset( $body['rates'] ) && is_array( $body['rates'] ) ? $body['rates'] : array();
    if ( ! count( $rates ) ) {
        return array( 'pass' => false, 'detail' => "HTTP $code but 0 rates — no carrier enabled on the Shippo account? shipment status=" . ( $body['status'] ?? '?' ) . ' messages=' . substr( (string) wp_json_encode( $body['messages'] ?? array() ), 0, 200 ) . " ({$ms}ms)" );
    }
    // Mirror the endpoint's selection: UPS only, Ground Saver / SurePost excluded.
    $ups = array();
    $saver_excluded = 0;
    foreach ( $rates as $rate ) {
        if ( strtolower( $rate['provider'] ?? '' ) !== 'ups' ) continue;
        $tok  = strtolower( $rate['servicelevel']['token'] ?? '' );
        $name = strtolower( $rate['servicelevel']['name'] ?? '' );
        if ( false !== strpos( $tok, 'ground_saver' ) || false !== strpos( $name, 'ground saver' ) || false !== strpos( $tok, 'surepost' ) ) { $saver_excluded++; continue; }
        $ups[] = $rate;
    }
    $ground = null;
    foreach ( $ups as $rate ) {
        if ( false !== strpos( strtolower( $rate['servicelevel']['token'] ?? '' ), 'ground' ) ) { $ground = $rate; break; }
    }
    $desc = $ground
        ? ( ( $ground['provider'] ?? '?' ) . ' ' . ( $ground['servicelevel']['name'] ?? '' ) . ' ' . ( $ground['estimated_days'] ?? '?' ) . 'd $' . ( $ground['amount'] ?? '?' ) )
        : ( 'no true UPS Ground among ' . count( $ups ) . ' eligible UPS rates' );
    return array( 'pass' => (bool) $ground, 'detail' => "HTTP $code, " . count( $rates ) . ' rates (' . count( $ups ) . " UPS eligible, $saver_excluded Saver/SurePost excluded); ground: $desc ({$ms}ms)" );
}

function pps_shippo_test_run_suite() {
    $t0    = microtime( true );
    $tests = array();
    $add   = function ( $id, $name, $pass, $detail = '' ) use ( &$tests ) {
        $tests[] = array( 'id' => $id, 'name' => $name, 'pass' => (bool) $pass, 'detail' => (string) $detail );
    };

    // ── A. Config & code-level guards ────────────────────────────────
    $cfg    = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $token  = (string) ( $cfg['pcf']['shippo_api_token'] ?? '' );
    $origin = (string) ( $cfg['pcf']['shippo_origin_zip'] ?? '' );
    $add( 'A1', 'Shippo token configured', '' !== $token,
        '' !== $token ? 'token ' . substr( $token, 0, 12 ) . '…' . substr( $token, -4 ) . ' (' . strlen( $token ) . ' chars)' : 'EMPTY — set in Central Config → Production → Shippo Integration' );
    $add( 'A2', 'Origin ZIP is 5 digits', (bool) preg_match( '/^\d{5}$/', $origin ), 'origin=' . $origin );

    $pub_ok = function_exists( 'pps_get_public_config' );
    $add( 'A3', 'pps_get_public_config() present (token-strip fix deployed)', $pub_ok, $pub_ok ? '' : 'STALE pps-calculators.php on this server' );
    if ( $pub_ok ) {
        $pub  = pps_get_public_config();
        $json = (string) wp_json_encode( $pub );
        $leak = ( '' !== $token && false !== strpos( $json, $token ) ) || false !== strpos( $json, 'shippo_api_token' );
        $add( 'A4', 'Browser config payload leaks no Shippo token', ! $leak,
            $leak ? 'TOKEN PRESENT IN window.PPS_CONFIG PAYLOAD' : 'stripped; shippo_enabled=' . var_export( $pub['pcf']['shippo_enabled'] ?? null, true ) );
        $add( 'A5', 'question_recipient_email stripped from browser payload', ! isset( $pub['pcf']['question_recipient_email'] ) );
    }

    // ── B. Raw Shippo connectivity (server egress + auth) ────────────
    $conn = array( 'pass' => false, 'detail' => 'skipped (no token)' );
    if ( '' !== $token ) {
        $conn = pps_shippo_test_raw_rate( $token, preg_match( '/^\d{5}$/', $origin ) ? $origin : '85086', '85027', 'AZ' );
    }
    $add( 'B1', 'Live Shippo API call (auth + rates round-trip)', $conn['pass'], $conn['detail'] );

    // ── C. REST endpoint contract (front-door code path, internal dispatch) ──
    delete_transient( 'pps_transit_v2_US_10001' ); // deterministic reruns (v2 = UPS-only cache)
    delete_transient( 'pps_transit_v2_US_60601' );
    delete_transient( 'pps_shipcost_v1_US_10001_90' ); // weighted-quote cache (C7)

    // ── C0: the guest path through the REAL web stack. rest_do_request (C1)
    // bypasses nginx/Varnish/security layers; this hits the public URL the
    // calculators actually fetch, from the server itself.
    delete_transient( 'pps_transit_v2_US_30301' );
    $ext = wp_remote_post( rest_url( 'pps/v1/shipping/transit-estimate' ), array(
        'headers'   => array( 'Content-Type' => 'application/json' ),
        'body'      => wp_json_encode( array( 'zip' => '30301', 'state' => 'GA', 'country' => 'US' ) ),
        'timeout'   => 8,
        'sslverify' => false,
    ) );
    if ( is_wp_error( $ext ) ) {
        $add( 'C0', 'PUBLIC endpoint reachable through the web stack (guest loopback)', false, 'transport: ' . $ext->get_error_message() . ' url=' . rest_url( 'pps/v1/shipping/transit-estimate' ) );
    } else {
        $ext_code = (int) wp_remote_retrieve_response_code( $ext );
        $ext_body = json_decode( wp_remote_retrieve_body( $ext ), true );
        $add( 'C0', 'PUBLIC endpoint reachable through the web stack (guest loopback)', 200 === $ext_code && isset( $ext_body['transit_days'] ),
            'HTTP ' . $ext_code . ' ' . substr( (string) wp_json_encode( $ext_body ), 0, 140 ) . ' url=' . rest_url( 'pps/v1/shipping/transit-estimate' ) );
    }

    // ── C0b: what a guest's browser actually receives on a calculator page —
    // the served HTML must carry shippo_enabled:true and a current build stamp.
    $pp = get_permalink( 24107 );
    if ( $pp ) {
        $pg = wp_remote_get( $pp, array( 'timeout' => 8, 'sslverify' => false ) );
        if ( is_wp_error( $pg ) ) {
            $add( 'C0b', 'Served product page carries shippo_enabled:true + current build', false, 'transport: ' . $pg->get_error_message() );
        } else {
            $html    = (string) wp_remote_retrieve_body( $pg );
            $flag_ok = false !== strpos( $html, '"shippo_enabled":true' );
            $root_ok = false !== strpos( $html, 'pps-calculator-root' );
            // Real-leak check: the token VALUE must never appear. The literal key
            // name legitimately exists in the calc's own fallback PCF ("" value),
            // and build-stamp chips only ship on the standalone/Pages copies.
            $leak    = '' !== $token && false !== strpos( $html, $token );
            $add( 'C0b', 'Served product page: calculator mounts, shippo_enabled:true, token value absent',
                $flag_ok && $root_ok && ! $leak,
                'HTTP ' . wp_remote_retrieve_response_code( $pg ) . ' flag=' . var_export( $flag_ok, true ) . ' root=' . var_export( $root_ok, true ) . ' token_value_leak=' . var_export( $leak, true ) . ' url=' . $pp );
        }
    } else {
        $add( 'C0b', 'Served product page carries shippo_enabled:true + current build', false, 'product 24107 has no permalink' );
    }

    wp_set_current_user( 0 ); // guest — most customers are guests

    $r1 = pps_shippo_test_rest( array( 'zip' => '10001', 'state' => 'NY' ) );
    $c1 = 200 === $r1['status'] && isset( $r1['data']['transit_days'] );
    $add( 'C1', 'transit-estimate open to guests and returns transit_days', $c1,
        'HTTP ' . $r1['status'] . ' ' . wp_json_encode( array_intersect_key( (array) $r1['data'], array_flip( array( 'transit_days', 'carrier', 'service', 'cached', 'domestic' ) ) ) ) );
    $svc_name = (string) ( $r1['data']['service'] ?? '' );
    $add( 'C1b', 'Selected rate is UPS and not Ground Saver (owner rule)',
        ( $r1['data']['carrier'] ?? '' ) === 'UPS' && false === stripos( $svc_name, 'saver' ),
        'carrier=' . var_export( $r1['data']['carrier'] ?? null, true ) . ' service=' . var_export( $svc_name, true ) );
    $days = $r1['data']['transit_days'] ?? null;
    $add( 'C2', 'NY transit days sane (1–8)', is_numeric( $days ) && $days >= 1 && $days <= 8,
        'transit_days=' . var_export( $days, true ) . ' (static state map says NY=5)' );

    $r2 = pps_shippo_test_rest( array( 'zip' => '10001', 'state' => 'NY' ) );
    $add( 'C3', 'Repeat lookup served from 30-day cache (no Shippo spend)', 200 === $r2['status'] && ! empty( $r2['data']['cached'] ),
        'cached=' . var_export( $r2['data']['cached'] ?? null, true ) );

    // Weighted quote: 90 lb → two 45 lb cartons, real UPS Ground cost for the shipment.
    $r2b = pps_shippo_test_rest( array( 'zip' => '10001', 'state' => 'NY', 'weight_lb' => 90 ) );
    $amt = isset( $r2b['data']['amount'] ) ? floatval( $r2b['data']['amount'] ) : 0;
    $add( 'C7', 'Weighted cost estimate (90 lb → 2 cartons, UPS non-Saver, amount sane)',
        200 === $r2b['status'] && 2 === (int) ( $r2b['data']['parcels'] ?? 0 )
            && ( $r2b['data']['carrier'] ?? '' ) === 'UPS'
            && false === stripos( (string) ( $r2b['data']['service'] ?? '' ), 'saver' )
            && $amt > 5 && $amt < 2000,
        'HTTP ' . $r2b['status'] . ' parcels=' . var_export( $r2b['data']['parcels'] ?? null, true )
            . ' est_weight_lb=' . var_export( $r2b['data']['est_weight_lb'] ?? null, true )
            . ' ' . ( $r2b['data']['carrier'] ?? '?' ) . ' ' . ( $r2b['data']['service'] ?? '?' ) . ' $' . $amt );

    $r3 = pps_shippo_test_rest( array( 'zip' => '123' ) );
    $add( 'C4', 'Bad ZIP rejected with 400', 400 === $r3['status'], 'HTTP ' . $r3['status'] );

    // 501-when-unconfigured — simulated via option filter; live config untouched.
    $no_token_cfg = $cfg;
    unset( $no_token_cfg['pcf']['shippo_api_token'] );
    $filter = function () use ( $no_token_cfg ) { return $no_token_cfg; };
    add_filter( 'pre_option_pps_calc_config', $filter );
    $r4 = pps_shippo_test_rest( array( 'zip' => '20500', 'state' => 'DC' ) );
    remove_filter( 'pre_option_pps_calc_config', $filter );
    $add( 'C5', 'No token → clean 501 (never a fatal)', 501 === $r4['status'], 'HTTP ' . $r4['status'] );

    // Rate limit: pre-seed the per-IP counter to the cap; a fresh ZIP must bounce
    // with 429 BEFORE any Shippo call is spent.
    $ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
    $rl_key = 'pps_transit_rl_' . md5( $ip );
    set_transient( $rl_key, 20, MINUTE_IN_SECONDS );
    $r5 = pps_shippo_test_rest( array( 'zip' => '60601', 'state' => 'IL' ) );
    delete_transient( $rl_key );
    $add( 'C6', 'Per-IP rate limit trips at cap (429)', 429 === $r5['status'], 'HTTP ' . $r5['status'] );

    // ── D. Authenticated endpoints stay locked down ──────────────────
    wp_set_current_user( 0 );
    $r6 = pps_shippo_test_rest(
        array( 'name' => 'T', 'street1' => 'x', 'city' => 'x', 'state' => 'AZ', 'zip' => '85027' ),
        '/pps/v1/shipping/validate'
    );
    $add( 'D1', 'validate refuses guests (401/403)', in_array( $r6['status'], array( 401, 403 ), true ), 'HTTP ' . $r6['status'] );

    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    if ( $admins && '' !== $token ) {
        wp_set_current_user( (int) $admins[0] );
        $r7 = pps_shippo_test_rest(
            array( 'name' => 'Priority Print', 'street1' => '2 N Central Ave', 'city' => 'Phoenix', 'state' => 'AZ', 'zip' => '85004', 'country' => 'US' ),
            '/pps/v1/shipping/validate'
        );
        wp_set_current_user( 0 );
        $keys = array_slice( array_keys( (array) $r7['data'] ), 0, 8 );
        $v_ok = 200 === $r7['status'] && ( isset( $r7['data']['validation_results'] ) || isset( $r7['data']['object_id'] ) || isset( $r7['data']['is_complete'] ) );
        $add( 'D2', 'validate proxies a real Shippo address check (as admin)', $v_ok, 'HTTP ' . $r7['status'] . ' keys=' . implode( ',', $keys ) );
    } else {
        $add( 'D2', 'validate proxies a real Shippo address check (as admin)', false, $admins ? 'skipped — no token' : 'skipped — no admin user found' );
    }

    $passed = 0;
    foreach ( $tests as $t ) { if ( $t['pass'] ) $passed++; }

    return array(
        'ran_at'      => current_time( 'mysql' ),
        'duration_ms' => round( ( microtime( true ) - $t0 ) * 1000 ),
        'php'         => PHP_VERSION,
        'summary'     => $passed . '/' . count( $tests ) . ' passed',
        'passed'      => $passed,
        'failed'      => count( $tests ) - $passed,
        'tests'       => $tests,
    );
}
