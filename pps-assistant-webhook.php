<?php
/**
 * Plugin Name: PPS Assistant — Missive Webhook
 * Description: Receives agent replies from a Missive custom channel and delivers them into
 *              the visitor's chat session. Authenticates the caller, routes the payload,
 *              and records every delivery for diagnosis.
 * Version: 0.2.0
 * Author: Priority Print Service
 *
 * WHY THIS EXISTS SEPARATELY
 * Missive's "Connect your account" form wants an outgoing webhook URL before it will
 * create the channel, and a URL that 404s is not obviously acceptable to it. Keeping the
 * route in its own small file means the URL can be created, authenticated and debugged
 * without redeploying the 70KB engine — and it stays deactivatable on its own, which is a
 * useful kill switch for inbound traffic alone.
 *
 * WHAT IT DOES NOW
 * Authenticates, hands the payload to pps_assistant_missive_receive() for routing into the
 * visitor's session, logs the raw bytes, and acknowledges.
 *
 * WHY THE LOGGING IS STILL THE POINT
 * Missive's docs 403 automated fetchers, so the inbound payload shape is RECONSTRUCTED,
 * not read off a spec. The extractor walks a list of plausible field paths; when none
 * match, `routed` comes back "unmatched" and the raw body is in
 * wp_options['pps_assistant_webhook_log'] (last 10, newest first) plus
 * ['pps_assistant_missive_log']. That turns the first miss into a one-line fix in
 * pps_assistant_missive_extract() rather than an investigation.
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

    // Stage 2: try to deliver into the visitor's chat. The raw-body log below still runs
    // either way — a delivery that failed to route is exactly the one worth having bytes
    // for, and the payload shape is still reconstructed rather than documented.
    $routed = 'no_bridge';
    if ( function_exists( 'pps_assistant_missive_receive' ) ) {
        $parsed = json_decode( (string) $raw, true );
        $routed = is_array( $parsed ) ? pps_assistant_missive_receive( $parsed ) : 'unparseable';
    }

    $entry = array(
        'at'      => gmdate( 'c' ),
        'method'  => $request->get_method(),
        // What happened to it. "unmatched" beside a raw body is the pair that tells you
        // which field path pps_assistant_missive_extract() is missing.
        'routed'  => $routed,
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

    error_log( '[pps-assistant] missive webhook received ' . strlen( (string) $raw )
        . ' bytes, routed=' . $routed );

    // Always acknowledge, even when we could not route it. Missive retries on non-2xx, and
    // a retry storm changes nothing about a payload we failed to understand — the log is
    // what fixes that, not a redelivery.
    return rest_ensure_response( array( 'ok' => true, 'routed' => $routed ) );
}
