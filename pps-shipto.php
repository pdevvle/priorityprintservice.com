<?php
/**
 * PPS Ship-to — one place to change where an order goes, and everything that copies it follows.
 *
 * Owner, 2026-10-19: "Need to be able to change order shipping addresses proactively and have
 * that populate across the site, the task, and the shippo integration (both webui and api
 * interface), since one person may change the address and another, days later may do the
 * label purchase."
 *
 * The WooCommerce order's shipping address is the one source. It can be changed from the
 * order screen in wp-admin (as always) or from the Missive panel's order card. Either way,
 * `woocommerce_update_order` sees the address differ from the last one recorded
 * (`_pps_shipto_hash` / `_pps_shipto_snapshot`) and brings every copy in step:
 *
 *   - Site: each calculator line's `_pps_metadata` (`shipAddr`, `shipState`, `shipZip`), the
 *     "Ship to:" line of its Job Ticket and Order Summary, a private order note naming who
 *     changed it, from what, to what, and the history in `_pps_shipto_history`. A shipping
 *     quote the Missive Ship page was holding (`pps_ms_q_<order>`) is dropped, so a label can
 *     never be bought against the old address. The delivery date is NOT re-quoted; the note
 *     says transit may differ.
 *   - Missive: the per-job subtasks the panel created get their description (the Job Ticket)
 *     rewritten; a note is posted into every conversation known to hold the order, so whoever
 *     buys the label later sees it in the thread. Order-level tasks someone else wrote are
 *     not edited — the posted note covers them.
 *   - Shippo, API: the panel's Ship page reads the order's address when it opens, so labels
 *     bought there use the new one.
 *   - Shippo, web app: Shippo's API cannot edit an order (create and read only), and its
 *     WooCommerce sync decides on its own when to re-read one. So the change is checked:
 *     the Shippo order's to-address is compared with the new one and the result is said —
 *     in the order note, the Missive note and the panel ("Shippo has the new address" /
 *     "Shippo still shows the OLD address — buy from the panel, or correct it in Shippo
 *     before buying there"), with a "Check Shippo again" button.
 *
 * The first time an order is saved after this ships, its address is only recorded (a
 * baseline), never propagated; open orders are given that baseline once on admin_init.
 * tools-shipto-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_ST_ALLOW_OPTION', 'pps_missive_allow_address' );   // the panel may change ship-to (default on)

/** The order's ship-to as a plain array. */
function pps_st_from_order( $order ) {
    $g = function ( $f ) use ( $order ) { $m = 'get_shipping_' . $f; return is_callable( array( $order, $m ) ) ? trim( (string) $order->$m() ) : ''; };
    return array(
        'name' => trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) ), 'company' => $g( 'company' ),
        'street1' => $g( 'address_1' ), 'street2' => $g( 'address_2' ), 'city' => $g( 'city' ),
        'state' => strtoupper( $g( 'state' ) ), 'zip' => $g( 'postcode' ), 'phone' => $g( 'phone' ),
    );
}

function pps_st_line( array $a ) {
    return implode( ', ', array_filter( array( $a['name'] ?? '', $a['company'] ?? '', $a['street1'] ?? '', $a['street2'] ?? '',
        trim( ( $a['city'] ?? '' ) . ' ' . ( $a['state'] ?? '' ) . ' ' . ( $a['zip'] ?? '' ) ) ), function ( $v ) { return trim( (string) $v ) !== ''; } ) );
}

/** What makes two addresses the same place (case, spacing and punctuation ignored; ZIP+4 ignored). */
function pps_st_hash( array $a ) {
    $n = function ( $v ) { return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) ); };
    return md5( implode( '|', array( $n( $a['name'] ?? '' ), $n( $a['company'] ?? '' ), $n( $a['street1'] ?? '' ), $n( $a['street2'] ?? '' ),
        $n( $a['city'] ?? '' ), $n( $a['state'] ?? '' ), substr( preg_replace( '/\D/', '', (string) ( $a['zip'] ?? '' ) ), 0, 5 ) ) ) );
}

/** A typed address cleaned and checked: [ address, '' ] or [ null, why ]. US only. */
function pps_st_clean( $in ) {
    $in = is_array( $in ) ? $in : array();
    $t = function ( $k, $max = 100 ) use ( $in ) { return mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $in[ $k ] ?? '' ) ) ) ), 0, $max ); };
    $a = array( 'name' => $t( 'name' ), 'company' => $t( 'company' ), 'street1' => $t( 'street1' ), 'street2' => $t( 'street2' ),
                'city' => $t( 'city', 60 ), 'state' => strtoupper( $t( 'state', 20 ) ) /* checked whole: cut to 2, "Arizona" became AR (Arkansas) */, 'zip' => $t( 'zip', 10 ), 'phone' => $t( 'phone', 30 ) );
    if ( $a['name'] === '' && $a['company'] === '' ) return array( null, 'Add a name or a company — carriers need one on the label.' );
    if ( $a['street1'] === '' ) return array( null, 'Add the street address.' );
    if ( $a['city'] === '' ) return array( null, 'Add the city.' );
    if ( ! preg_match( '/^[A-Z]{2}$/', $a['state'] ) ) return array( null, 'Use the two-letter state (e.g. AZ).' );
    if ( preg_match( '/^(\d{5})\s?-?\s?(\d{4})$/', $a['zip'], $m ) ) $a['zip'] = $m[1] . '-' . $m[2];
    if ( ! preg_match( '/^\d{5}(-\d{4})?$/', $a['zip'] ) ) return array( null, 'The ZIP should be 5 digits (or ZIP+4).' );
    return array( $a, '' );
}

function pps_st_set_order( $order, array $a ) {
    $parts = preg_split( '/\s+/', $a['name'], 2 );
    $order->set_shipping_first_name( $parts[0] ?? '' );
    $order->set_shipping_last_name( $parts[1] ?? '' );
    $order->set_shipping_company( $a['company'] );
    $order->set_shipping_address_1( $a['street1'] );
    $order->set_shipping_address_2( $a['street2'] );
    $order->set_shipping_city( $a['city'] );
    $order->set_shipping_state( $a['state'] );
    $order->set_shipping_postcode( $a['zip'] );
    $order->set_shipping_country( 'US' );
    if ( $a['phone'] !== '' && is_callable( array( $order, 'set_shipping_phone' ) ) ) $order->set_shipping_phone( $a['phone'] );
}

/* ── Bringing the copies in step ────────────────────────────── */

/** Each calculator line's own copies: metadata, Job Ticket and Order Summary lines. Returns lines changed. */
function pps_st_update_items( $order, array $a ) {
    $n = 0;
    $line = pps_st_line( $a );
    foreach ( $order->get_items() as $item_id => $item ) {
        $raw  = $item->get_meta( '_pps_metadata' );
        $meta = $raw ? json_decode( (string) $raw, true ) : null;
        $touched = false;
        if ( is_array( $meta ) ) {
            $sa = is_array( $meta['shipAddr'] ?? null ) ? $meta['shipAddr'] : array();
            foreach ( array( 'name', 'company', 'street1', 'street2', 'city', 'state', 'zip' ) as $k ) $sa[ $k ] = $a[ $k ];
            if ( $a['phone'] !== '' ) $sa['phone'] = $a['phone'];
            $meta['shipAddr'] = $sa; $meta['shipState'] = $a['state']; $meta['shipZip'] = $a['zip'];
            $item->update_meta_data( '_pps_metadata', wp_json_encode( $meta ) );
            $touched = true;
        }
        $ticket = (string) $item->get_meta( 'Job Ticket' );
        if ( $ticket !== '' && preg_match( '/^Ship to: .*$/m', $ticket ) ) {
            $item->update_meta_data( 'Job Ticket', preg_replace( '/^Ship to: .*$/m', 'Ship to: ' . str_replace( '$', '\\$', $line ), $ticket, 1 ) );
            $touched = true;
        }
        $sum = (string) $item->get_meta( 'Order Summary' );
        if ( $sum !== '' && preg_match( '/^Ship to: .*$/m', $sum ) ) {
            $item->update_meta_data( 'Order Summary', preg_replace( '/^Ship to: .*$/m', 'Ship to: ' . $a['state'] . ' ' . $a['zip'], $sum, 1 ) );
            $touched = true;
        }
        if ( $touched ) { $item->save_meta_data(); $n++; }
    }
    return $n;
}

/**
 * Does Shippo's copy of the order carry this address? 'match', 'stale' (it still has
 * another), 'none' (no Shippo order found yet — the store sync has not picked it up, so it
 * will read the new address when it does) or 'unknown' (Shippo not reachable or not set up).
 */
function pps_st_shippo_status( $order, array $a ) {
    if ( ! function_exists( 'pps_ms_shippo_order' ) || ! function_exists( 'pps_ms_api' ) ) return array( 'status' => 'unknown', 'shippo' => '' );
    if ( function_exists( 'pps_ms_shippo_key' ) && pps_ms_shippo_key() === '' ) return array( 'status' => 'unknown', 'shippo' => '' );
    $id = pps_ms_shippo_order( $order->get_order_number() );
    if ( $id === '' ) return array( 'status' => 'none', 'shippo' => '' );
    $r = pps_ms_api( 'GET', 'orders/' . rawurlencode( $id ) );
    if ( is_wp_error( $r ) ) return array( 'status' => 'unknown', 'shippo' => '' );
    $to = (array) ( $r['to_address'] ?? array() );
    $theirs = array( 'name' => (string) ( $to['name'] ?? '' ), 'company' => (string) ( $to['company'] ?? '' ), 'street1' => (string) ( $to['street1'] ?? '' ),
                     'street2' => (string) ( $to['street2'] ?? '' ), 'city' => (string) ( $to['city'] ?? '' ), 'state' => (string) ( $to['state'] ?? '' ), 'zip' => (string) ( $to['zip'] ?? '' ) );
    // Same street, city, state and 5-digit ZIP is the same place, whatever Shippo did to the name.
    $place = function ( $x ) { $x['name'] = ''; $x['company'] = ''; return pps_st_hash( $x ); };
    $theirs['phone'] = (string) ( $to['phone'] ?? '' );
    return array( 'status' => $place( $theirs ) === $place( $a ) ? 'match' : 'stale', 'shippo' => pps_st_line( $theirs ), 'addr' => $theirs );
}

function pps_st_shippo_words( array $s ) {
    switch ( $s['status'] ) {
        case 'match': return 'Shippo has the new address.';
        case 'stale': return 'Shippo still shows the OLD address (' . $s['shippo'] . ') — its store sync has not caught up. Buy the label from the Missive panel (it uses the new address), or correct the address in Shippo before buying there.';
        case 'none':  return 'Shippo has not imported this order yet; it will read the new address when it does.';
        default:      return 'Shippo could not be checked — confirm the address in Shippo before buying a label there.';
    }
}

/** Missive: rewrite the panel's own per-job subtasks, and post a note into the order's conversations. */
function pps_st_missive( $order, array $old, array $new, $who, array $shippo ) {
    $out = array( 'tasks' => 0, 'posts' => 0, 'errors' => array() );
    if ( ! function_exists( 'pps_mp_token' ) || pps_mp_token() === '' ) return $out;
    $oid = (int) $order->get_id();
    if ( function_exists( 'pps_mp_item_map' ) && function_exists( 'pps_mp_jobs' ) && function_exists( 'pps_mp_subtask_for' ) ) {
        $items = pps_mp_item_map();
        $jobs  = pps_mp_jobs( array( $order ), function_exists( 'pps_mp_paper_index' ) ? pps_mp_paper_index( array( $order ) ) : array() );
        $admin = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid );
        $n = 0;
        foreach ( $jobs as $j ) {
            $n++;
            $tid = $items[ $j['item'] ] ?? '';
            if ( $tid === '' ) continue;
            $t = pps_mp_subtask_for( $j, $n, count( $jobs ), $admin );
            $r = pps_mp_api( 'PATCH', 'tasks/' . rawurlencode( (string) $tid ), array(), array( 'tasks' => array( 'description' => $t['description'] ) ) );
            if ( is_wp_error( $r ) ) $out['errors'][] = 'subtask: ' . $r->get_error_message(); else $out['tasks']++;
        }
    }
    $convs = array();
    if ( function_exists( 'pps_mp_conv_map' ) ) foreach ( pps_mp_conv_map() as $cid => $o ) if ( (int) $o === $oid ) $convs[] = (string) $cid;
    if ( $convs ) {
        $org = function_exists( 'pps_mp_org' ) ? pps_mp_org() : '';
        $md  = '**📦 Ship-to changed** for order #' . $order->get_order_number() . ' by ' . $who . "\n\n"
             . '**Now:** ' . pps_st_line( $new ) . "\n\n" . '~~Was: ' . pps_st_line( $old ) . "~~\n\n"
             . pps_st_shippo_words( $shippo ) . "\n\nThe delivery date was not re-quoted — transit to the new address may differ.";
        foreach ( array_slice( $convs, 0, 5 ) as $cid ) {
            $post = array( 'conversation' => $cid, 'username' => 'PPS site', 'markdown' => $md,
                           'notification' => array( 'title' => 'Ship-to changed — #' . $order->get_order_number(), 'body' => pps_st_line( $new ) ) );
            if ( is_string( $org ) && $org !== '' ) $post['organization'] = $org;
            $r = pps_mp_api( 'POST', 'posts', array(), array( 'posts' => $post ) );
            if ( is_wp_error( $r ) ) $out['errors'][] = 'note: ' . $r->get_error_message(); else $out['posts']++;
        }
    }
    if ( function_exists( 'delete_transient' ) && defined( 'PPS_MP_TASKS_CACHE' ) ) delete_transient( PPS_MP_TASKS_CACHE );
    return $out;
}

/** Everything that follows a change from $old to $new. Returns what was done. */
function pps_st_propagate( $order, array $old, array $new, $who ) {
    $res = array( 'at' => current_time( 'Y-m-d H:i' ), 'who' => $who, 'from' => pps_st_line( $old ), 'to' => pps_st_line( $new ) );
    $res['items']  = pps_st_update_items( $order, $new );
    delete_transient( 'pps_ms_q_' . $order->get_id() );                // a held shipping quote was for the old address
    $res['shippo'] = pps_st_shippo_status( $order, $new );
    $res['missive'] = pps_st_missive( $order, $old, $new, $who, $res['shippo'] );
    $hist = $order->get_meta( '_pps_shipto_history' );
    $hist = is_array( $hist ) ? $hist : array();
    $hist[] = array( 'at' => $res['at'], 'who' => $who, 'from' => $res['from'], 'to' => $res['to'] );
    $order->update_meta_data( '_pps_shipto_history', array_slice( $hist, -20 ) );
    $order->update_meta_data( '_pps_shipto_last', $res );
    $m = $res['missive'];
    $order->add_order_note( 'Ship-to changed by ' . $who . ".\nFrom: " . $res['from'] . "\nTo: " . $res['to']
        . "\nUpdated on " . $res['items'] . ' job' . ( $res['items'] === 1 ? '' : 's' ) . ' (Job Ticket, order summary).'
        . ( $m['tasks'] || $m['posts'] ? "\nMissive: " . $m['tasks'] . ' subtask' . ( $m['tasks'] === 1 ? '' : 's' ) . ' updated, note posted in ' . $m['posts'] . ' conversation' . ( $m['posts'] === 1 ? '' : 's' ) . '.' : '' )
        . ( $m['errors'] ? "\nMissive problems: " . implode( '; ', $m['errors'] ) : '' )
        . "\n" . pps_st_shippo_words( $res['shippo'] )
        . "\nThe delivery date was not re-quoted — transit to the new address may differ.", 0, false );
    return $res;
}

/* ── Watching every save of an order ────────────────────────── */

$GLOBALS['pps_st_who'] = '';

function pps_st_on_save( $order_id, $order = null ) {
    static $busy = false;
    if ( $busy ) return;
    $order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
    if ( ! $order || ! is_callable( array( $order, 'get_shipping_address_1' ) ) ) return;
    $now  = pps_st_from_order( $order );
    if ( trim( $now['street1'] ) === '' ) return;                     // no shipping address yet (or a billing-only order)
    $hash = pps_st_hash( $now );
    $was  = (string) $order->get_meta( '_pps_shipto_hash' );
    if ( $was === $hash ) return;
    $busy = true;
    try {
        if ( $was !== '' ) {
            $old = $order->get_meta( '_pps_shipto_snapshot' );
            $who = $GLOBALS['pps_st_who'] !== '' ? $GLOBALS['pps_st_who']
                 : ( function_exists( 'wp_get_current_user' ) && wp_get_current_user()->ID ? wp_get_current_user()->display_name . ' (order screen)' : 'WooCommerce' );
            pps_st_propagate( $order, is_array( $old ) ? $old : array(), $now, $who );
        }
        $order->update_meta_data( '_pps_shipto_hash', $hash );
        $order->update_meta_data( '_pps_shipto_snapshot', $now );
        $order->save_meta_data();
    } catch ( \Throwable $e ) {
        error_log( 'PPS ship-to: ' . $e->getMessage() );
    }
    $busy = false;
}
add_action( 'woocommerce_update_order', 'pps_st_on_save', 20, 2 );

/** Open orders get a baseline once, so the first change made to them is seen as a change. */
add_action( 'admin_init', function () {
    if ( get_option( 'pps_shipto_baseline_v1' ) || ! function_exists( 'wc_get_orders' ) ) return;
    update_option( 'pps_shipto_baseline_v1', 1, false );
    foreach ( wc_get_orders( array( 'limit' => 500, 'status' => array( 'processing', 'on-hold', 'pending' ) ) ) as $o ) {
        if ( (string) $o->get_meta( '_pps_shipto_hash' ) !== '' ) continue;
        $a = pps_st_from_order( $o );
        if ( trim( $a['street1'] ) === '' ) continue;
        $o->update_meta_data( '_pps_shipto_hash', pps_st_hash( $a ) );
        $o->update_meta_data( '_pps_shipto_snapshot', $a );
        $o->save_meta_data();
    }
} );

/** Change an order's ship-to (the panel's path; the order screen saves fields itself). */
function pps_st_change( $order, $input, $who ) {
    list( $a, $why ) = pps_st_clean( $input );
    if ( ! $a ) return new WP_Error( 'bad', $why );
    if ( pps_st_hash( $a ) === pps_st_hash( pps_st_from_order( $order ) ) && ( $a['phone'] === '' || $a['phone'] === pps_st_from_order( $order )['phone'] ) )
        return new WP_Error( 'same', 'That is already the order\'s ship-to.' );
    if ( (string) $order->get_meta( '_pps_shipto_hash' ) === '' ) {         // no baseline yet: record the current one first
        $cur = pps_st_from_order( $order );
        if ( trim( $cur['street1'] ) !== '' ) { $order->update_meta_data( '_pps_shipto_hash', pps_st_hash( $cur ) ); $order->update_meta_data( '_pps_shipto_snapshot', $cur ); }
    }
    $GLOBALS['pps_st_who'] = $who;
    pps_st_set_order( $order, $a );
    $order->save();                                                     // pps_st_on_save does the rest
    $GLOBALS['pps_st_who'] = '';
    return $order->get_meta( '_pps_shipto_last' );
}

/** What the panel shows on an order card. */
function pps_st_view( $order ) {
    $last = $order->get_meta( '_pps_shipto_last' );
    return array( 'addr' => pps_st_from_order( $order ), 'line' => pps_st_line( pps_st_from_order( $order ) ),
                  'last' => is_array( $last ) ? array( 'at' => $last['at'] ?? '', 'who' => $last['who'] ?? '', 'from' => $last['from'] ?? '',
                      'shippo' => $last['shippo']['status'] ?? '', 'shippo_words' => isset( $last['shippo'] ) ? pps_st_shippo_words( $last['shippo'] ) : '' ) : null,
                  'can_edit' => (bool) get_option( PPS_ST_ALLOW_OPTION, 1 ) );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/missive/ship-to', array(
        'methods' => 'POST',
        'permission_callback' => function ( $req ) { return function_exists( 'pps_mp_key_ok' ) && pps_mp_key_ok( (string) $req->get_header( 'x_pps_panel_key' ) ); },
        'callback' => function ( $req ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $req->get_param( 'order' ) ) : null;
            if ( ! $order ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'No such order.' ), 200 );
            if ( $req->get_param( 'check' ) ) {                          // re-check Shippo only
                $s = pps_st_shippo_status( $order, pps_st_from_order( $order ) );
                $last = $order->get_meta( '_pps_shipto_last' );
                if ( is_array( $last ) ) { $last['shippo'] = $s; $order->update_meta_data( '_pps_shipto_last', $last ); $order->save_meta_data(); }
                return new WP_REST_Response( array( 'ok' => true, 'shippo' => $s['status'], 'words' => pps_st_shippo_words( $s ) ), 200 );
            }
            if ( ! get_option( PPS_ST_ALLOW_OPTION, 1 ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => 'Changing the ship-to from the panel is switched off (PPS Calculators → Missive Panel). Change it on the order screen.' ), 200 );
            $who = sanitize_text_field( (string) $req->get_param( 'who' ) );
            $r = pps_st_change( $order, (array) $req->get_param( 'address' ), ( $who !== '' ? mb_substr( $who, 0, 60 ) : 'someone' ) . ' (Missive panel)' );
            if ( is_wp_error( $r ) ) return new WP_REST_Response( array( 'ok' => false, 'error' => $r->get_error_message() ), 200 );
            return new WP_REST_Response( array( 'ok' => true, 'result' => $r, 'view' => pps_st_view( $order ) ), 200 );
        },
    ) );
} );
