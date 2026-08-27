<?php
/**
 * PPS Pay Link — mint a payment link for a job quoted in conversation.
 *
 * The job console assumes somebody is sitting at it. Most jobs are actually
 * agreed in a Missive thread, where the operator already knows the two facts
 * that matter — what the job is, and what it costs — and wants a link to paste
 * into the reply without leaving the conversation.
 *
 * This is that door: one authenticated REST call carrying a description and a
 * price, returning a /quote/?q=TOKEN URL.
 *
 * WHY A QUOTE AND NOT AN INVOICE
 * pps_job_invoice_create() needs the customer's name, email and address up
 * front, which is exactly what a conversation does not have yet — and it
 * creates the WooCommerce order immediately, so every link that is never used
 * leaves an abandoned order behind. A quote carries the job only; the customer
 * supplies billing and shipping on the quote page, and nothing exists in
 * WooCommerce until they submit. Links that go nowhere cost nothing.
 *
 * HOW IT GETS PAID
 * Per link, one of two routes:
 *
 *   qbo_api  QuickBooks Payments. When the customer submits the quote form we
 *            create the invoice in QuickBooks from the details they just gave
 *            us and send them to its hosted payment page. QuickBooks tells us
 *            it was paid; nobody presses Mark paid.
 *   site     the website's own card checkout, which WooCommerce reconciles
 *            itself. The fallback when QuickBooks is not connected.
 *
 * Note this is NOT the older 'quickbooks' source, which is a static payment
 * link pasted in by hand and settled with the manual Mark paid button. That
 * one is kept for jobs invoiced outside this flow; a link minted here never
 * needs a human to reconcile it, which is the entire point of minting from a
 * conversation.
 *
 * The invoice is created at SUBMIT, not at mint: at mint time we have a
 * description and a price and no idea who is buying, which is not enough to
 * raise an invoice against anybody.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PPS_PAYLINK_SECRET_OPT  = 'pps_paylink_secret';
const PPS_PAYLINK_PRODUCT_OPT = 'pps_paylink_product';
const PPS_PAYLINK_REJECTS     = 'pps_paylink_rejects';
const PPS_PAYLINK_QBO_META    = '_q_qbo';

/* ─────────────────────────────────────────────────────────────
 * Configuration
 * ───────────────────────────────────────────────────────────── */

/**
 * The shared secret the caller must present. Generated on first read so the
 * route is never accidentally live with an empty secret — an empty expected
 * value would make hash_equals() a formality.
 */
function pps_paylink_secret() {
    $s = (string) get_option( PPS_PAYLINK_SECRET_OPT, '' );
    if ( '' === $s ) {
        $s = wp_generate_password( 40, false, false );
        update_option( PPS_PAYLINK_SECRET_OPT, $s, false );
    }
    return $s;
}

/**
 * The product every conversation-minted job hangs off.
 *
 * A WooCommerce order line needs a product, but a job agreed in a thread is
 * described in prose and priced as a lump. So one generic product carries them
 * all, and the description rides in the line's Specs meta.
 *
 * Deliberately NOT auto-created: this runs against a live shop, and inventing
 * a published product as a side effect of the first API call is not something
 * the operator asked for. Refuse clearly instead and let them pick one.
 */
function pps_paylink_product_id() {
    $pid = absint( get_option( PPS_PAYLINK_PRODUCT_OPT, 0 ) );
    if ( ! $pid ) return 0;
    $p = wc_get_product( $pid );
    return ( $p && $p->exists() ) ? $pid : 0;
}

/**
 * Whether QuickBooks can actually take a payment right now. Connection alone
 * is not enough — an OAuth token proves we can reach the company file, not
 * that QuickBooks Payments is switched on for it, and only the latter puts a
 * Pay Now button on an invoice.
 */
function pps_paylink_qbo_ready() {
    return function_exists( 'pps_qbo_can_take_payment' ) && pps_qbo_can_take_payment();
}

/* ─────────────────────────────────────────────────────────────
 * Input parsing
 * ───────────────────────────────────────────────────────────── */

/**
 * Read a price the way a human types it into a chat window: "250", "$250",
 * "1,250.00", " 250.00 ". Returns a float, or null when there is no number in
 * there at all — which must be distinguishable from a legitimate zero so the
 * caller can refuse rather than mint a free job.
 */
function pps_paylink_parse_price( $raw ) {
    if ( is_int( $raw ) || is_float( $raw ) ) return round( (float) $raw, 2 );
    $s = trim( (string) $raw );
    if ( '' === $s ) return null;
    // Strip everything that cannot be part of a decimal number.
    $s = preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '', $s ) );
    if ( '' === $s || ! is_numeric( $s ) ) return null;
    return round( (float) $s, 2 );
}

/**
 * Whether this link should mirror into QuickBooks. Accepts the several shapes
 * a webhook body might carry a boolean in, because the caller is a rule engine
 * we do not control and "false" arriving as the string "false" is the classic
 * way a flag ends up permanently on.
 */
function pps_paylink_wants_qbo( $raw ) {
    if ( is_bool( $raw ) ) return $raw;
    if ( is_int( $raw ) ) return 1 === $raw;
    $s = strtolower( trim( (string) $raw ) );
    return in_array( $s, array( '1', 'true', 'yes', 'on', 'qbo', 'quickbooks' ), true );
}

/* ─────────────────────────────────────────────────────────────
 * Minting
 * ───────────────────────────────────────────────────────────── */

/**
 * @param array $a description (required), price (required), qty, qbo, by, note
 * @return array{url:string,token:string,quote_id:int}|WP_Error
 */
function pps_paylink_create( array $a ) {
    if ( ! function_exists( 'pps_quote_create' ) ) {
        return new WP_Error( 'unavailable', 'The quote engine is not loaded.' );
    }

    $description = isset( $a['description'] ) ? trim( (string) $a['description'] ) : '';
    if ( '' === $description ) {
        return new WP_Error( 'description', 'Describe the job — it is what the customer sees on the quote page.' );
    }

    $price = pps_paylink_parse_price( isset( $a['price'] ) ? $a['price'] : '' );
    if ( null === $price || $price <= 0 ) {
        return new WP_Error( 'price', 'Give a price greater than $0.' );
    }

    $qty = isset( $a['qty'] ) ? absint( $a['qty'] ) : 1;
    if ( $qty < 1 ) $qty = 1;

    $pid = pps_paylink_product_id();
    if ( ! $pid ) {
        return new WP_Error(
            'product',
            'No pay-link product is configured. Set one in WP Admin → PPS Calculators → Invoice a Job.'
        );
    }

    // Route to QuickBooks only when it is actually connected. A link that
    // promises a QuickBooks payment page and cannot produce one is worse than
    // one that quietly uses site checkout, because the failure lands on the
    // customer at the moment they try to pay.
    $wants  = pps_paylink_wants_qbo( isset( $a['qbo'] ) ? $a['qbo'] : false );
    $source = ( $wants && pps_paylink_qbo_ready() ) ? 'qbo_api' : 'site';

    // The price quoted is the price for the whole job, and pps_quote_create
    // stores a tier as (qty, price) where price is the LINE total for that
    // quantity — the same figure the frozen-price reorder later divides by qty.
    $quote = pps_quote_create( array(
        'product'    => $pid,
        'tiers'      => array( array( 'qty' => $qty, 'price' => $price ) ),
        'specs'      => $description,
        'pay_source' => $source,
        'by'         => isset( $a['by'] ) ? (string) $a['by'] : 'missive',
        'note'       => isset( $a['note'] ) ? (string) $a['note'] : '',
    ) );
    if ( is_wp_error( $quote ) ) return $quote;

    // Stored even when false, so a quote minted while the integration was off
    // is distinguishable from one minted before the flag existed — and so a
    // link that ASKED for QuickBooks but fell back to site checkout is visible
    // as exactly that rather than looking like it never asked.
    update_post_meta( $quote, PPS_PAYLINK_QBO_META, $wants ? 1 : 0 );
    update_post_meta( $quote, '_q_origin', 'paylink' );

    $token = (string) get_post_meta( $quote, '_q_token', true );
    return array(
        'url'         => pps_quote_url( $token ),
        'token'       => $token,
        'quote_id'    => (int) $quote,
        'pay_source'  => $source,
        // True when QuickBooks was asked for and could not be given, so the
        // caller can say so in the thread instead of the operator finding out
        // from the customer.
        'qbo_fell_back' => ( $wants && 'qbo_api' !== $source ),
    );
}

/* ─────────────────────────────────────────────────────────────
 * REST route
 * ───────────────────────────────────────────────────────────── */

/**
 * Authenticate a call. Accepted from an X-PPS-Key header OR a ?k= query
 * parameter: not every rule engine lets you set headers, and a caller that
 * silently drops one is indistinguishable from a wrong secret without this.
 */
function pps_paylink_authorized( WP_REST_Request $request ) {
    $expected = pps_paylink_secret();
    if ( '' === $expected ) return false;
    foreach ( array( $request->get_header( 'x_pps_key' ), $request->get_param( 'k' ) ) as $given ) {
        if ( is_string( $given ) && '' !== $given && hash_equals( $expected, $given ) ) return true;
    }
    return false;
}

/**
 * Record WHY a call was turned away — names and lengths only, never values.
 * A rejected request is untrusted input, and storing its secret attempt would
 * just be a second copy of the secret sitting in the options table.
 */
function pps_paylink_log_reject( WP_REST_Request $request ) {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    $uri = preg_replace( '/([?&]k=)[^&]*/i', '$1[value-present]', $uri );

    $given = $request->get_param( 'k' );
    $entry = array(
        'at'                 => gmdate( 'c' ),
        'method'             => $request->get_method(),
        'uri'                => mb_substr( $uri, 0, 300 ),
        'k_present'          => is_string( $given ) && '' !== $given,
        'k_length'           => is_string( $given ) ? strlen( $given ) : 0,
        'header_key_present' => '' !== (string) $request->get_header( 'x_pps_key' ),
        'query_keys'         => array_keys( (array) $request->get_query_params() ),
    );

    $log = get_option( PPS_PAYLINK_REJECTS, array() );
    if ( ! is_array( $log ) ) $log = array();
    array_unshift( $log, $entry );
    update_option( PPS_PAYLINK_REJECTS, array_slice( $log, 0, 5 ), false );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/pay-link', array(
        'methods'             => array( 'POST' ),
        // Open on purpose, with the real check inside the handler: a
        // permission_callback refuses before anything can be recorded, which
        // is how the Missive webhook once produced a 401 nobody could explain.
        'permission_callback' => '__return_true',
        'callback'            => 'pps_paylink_handle_request',
    ) );
} );

function pps_paylink_handle_request( WP_REST_Request $request ) {
    if ( ! pps_paylink_authorized( $request ) ) {
        pps_paylink_log_reject( $request );
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
    }

    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) $body = $request->get_body_params();
    if ( ! is_array( $body ) ) $body = array();

    $res = pps_paylink_create( array(
        'description' => isset( $body['description'] ) ? $body['description'] : '',
        'price'       => isset( $body['price'] ) ? $body['price'] : '',
        'qty'         => isset( $body['qty'] ) ? $body['qty'] : 1,
        'qbo'         => isset( $body['qbo'] ) ? $body['qbo'] : false,
        'by'          => isset( $body['by'] ) ? $body['by'] : 'missive',
        'note'        => isset( $body['note'] ) ? $body['note'] : '',
    ) );

    if ( is_wp_error( $res ) ) {
        return new WP_REST_Response( array(
            'ok'    => false,
            'error' => $res->get_error_code(),
            // Safe to return: these are the operator's own validation messages,
            // and the caller is already authenticated by this point.
            'message' => $res->get_error_message(),
        ), 400 );
    }

    return new WP_REST_Response( array( 'ok' => true ) + $res, 201 );
}
