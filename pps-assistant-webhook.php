<?php
/**
 * Plugin Name: PPS Assistant — Missive Webhook
 * Description: Receives agent replies from a Missive custom channel. Currently a verified
 *              stub: it authenticates the caller, records the payload, and returns 200 so
 *              Missive's setup form accepts the URL. Delivery into the chat widget lands
 *              with stage 2.
 * Version: 0.1.0
 * Author: Priority Print Service
 *
 * WHY THIS EXISTS SEPARATELY
 * Missive's "Connect your account" form wants an outgoing webhook URL before it will
 * create the channel, and a URL that 404s is not obviously acceptable to it. Shipping the
 * route as its own small file unblocks channel creation without redeploying the 52KB
 * engine, and it folds into pps-assistant.php at stage 2.
 *
 * WHAT IT DOES NOW
 * Authenticates, logs, acknowledges. It does NOT deliver anything to a customer — nothing
 * is wired to the widget yet, so an agent reply typed in Missive goes nowhere. That is
 * deliberate: better a channel that visibly does nothing than one that half-works.
 *
 * WHY THE LOGGING IS THE POINT
 * Missive's docs 403 automated fetchers, so the exact payload shape is unknown. Every
 * authenticated delivery is recorded to wp_options['pps_assistant_webhook_log'] (last 10,
 * newest first). One real agent reply on staging tells us the field names stage 2 needs —
 * which beats guessing at them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_ASSISTANT_WEBHOOK_LOG', 'pps_assistant_webhook_log' );

/**
 * Authenticate a delivery.
 *
 * Missive has no nonce and no WordPress session, so the shared secret in the query string
 * is the whole of the authentication. Compared with hash_equals() because a timing-safe
 * compare costs nothing here and a leaky one is how a secret gets guessed a byte at a time.
 *
 * If Missive turns out to sign deliveries (an HMAC header), that becomes the primary check
 * and this drops to a fallback — the log below will show us whether such a header arrives.
 */
function pps_assistant_webhook_authorized( WP_REST_Request $request ) {
    if ( ! function_exists( 'pps_assistant_config' ) ) return false;   // engine not loaded

    $cfg      = pps_assistant_config();
    $expected = (string) ( $cfg['missive_webhook_secret'] ?? '' );
    $given    = (string) $request->get_param( 'k' );

    if ( $expected === '' || $given === '' ) return false;
    return hash_equals( $expected, $given );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/assistant/missive-webhook', array(
        // Missive sends JSON; accept GET too so their setup form can probe the URL
        // without us rejecting it as a bad method.
        'methods'             => array( 'GET', 'POST' ),
        'permission_callback' => 'pps_assistant_webhook_authorized',
        'callback'            => 'pps_assistant_handle_missive_webhook',
    ) );
} );

function pps_assistant_handle_missive_webhook( WP_REST_Request $request ) {
    // Record the RAW body, not the parsed array — if Missive ever signs deliveries the MAC
    // is computed over exact bytes, and re-serialising a parsed array changes them.
    $raw = $request->get_body();

    $entry = array(
        'at'      => gmdate( 'c' ),
        'method'  => $request->get_method(),
        // Header names that might carry a signature or delivery id. Recorded so we can see
        // what Missive actually sends rather than guessing from documentation we can't read.
        'headers' => array_intersect_key(
            array_map( function ( $v ) { return is_array( $v ) ? implode( ', ', $v ) : $v; }, $request->get_headers() ),
            array_flip( array( 'content_type', 'user_agent', 'x_hook_signature', 'x_missive_signature',
                               'x_signature', 'webhook_id', 'webhook_timestamp', 'webhook_signature' ) )
        ),
        'body'    => mb_substr( (string) $raw, 0, 8000 ),
    );

    $log = get_option( PPS_ASSISTANT_WEBHOOK_LOG, array() );
    if ( is_string( $log ) ) $log = json_decode( $log, true );
    if ( ! is_array( $log ) ) $log = array();

    array_unshift( $log, $entry );
    $log = array_slice( $log, 0, 10 );          // keep it bounded; this is a diagnostic, not storage
    update_option( PPS_ASSISTANT_WEBHOOK_LOG, $log, false );   // autoload off — it can get chunky

    error_log( '[pps-assistant] missive webhook received ' . strlen( (string) $raw ) . ' bytes' );

    // Acknowledge. Missive retries on non-2xx, and a retry storm against a stub helps nobody.
    return rest_ensure_response( array( 'ok' => true, 'stage' => 'stub' ) );
}
