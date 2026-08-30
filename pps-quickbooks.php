<?php
/**
 * Plugin Name: PPS QuickBooks
 * Description: Raise the invoice in QuickBooks and let QuickBooks take the payment. Loads itself so a redeploy of the calculators plugin cannot unload it.
 * Version: 1.0.0
 * Author: Priority Print Service
 *
 * PPS QuickBooks — raise the invoice in QuickBooks and let QuickBooks take the
 * payment.
 *
 * WHAT THIS IS NOT
 * Not a WooCommerce payment gateway, and not the older 'quickbooks' pay source
 * (a static link pasted in by hand and settled with the manual Mark paid
 * button). Here QuickBooks is the payment surface: when the customer submits a
 * QBO-routed quote we create the invoice from the details they just gave us,
 * hand them its hosted payment link, and QuickBooks tells us when it is paid.
 * Nobody presses Mark paid.
 *
 * WHY SUB-CUSTOMERS AND NOT ONE SHARED "PPS ONLINE"
 * Booking every online order to a single shared customer is tidy right up to
 * the point where somebody saves a card. Stored payment methods hang off the
 * CUSTOMER record, so one shared record is exactly the condition where one
 * buyer's card can be selected against another buyer's invoice — and holding a
 * consumer's card on a record that does not represent them is a consent
 * problem as much as a security one.
 *
 * So "PPS ONLINE" is a PARENT and each buyer is a sub-customer under it
 * (ParentRef + Job=true, which QBO nests up to four deep). The books still roll
 * up under PPS ONLINE; each buyer is their own record, so anything stored is
 * scoped to them. This is deliberately not a setting — the shared-record
 * variant is not safe to offer.
 *
 * CREDENTIALS
 * Client id, secret, refresh token and webhook verifier live in wp_options and
 * never in this file. Configure at WP Admin → PPS Calculators → QuickBooks.
 *
 * THE REFRESH TOKEN ROTATES
 * Unlike Google, Intuit issues a NEW refresh token on most refreshes and the
 * old one dies. pps-gdrive.php saves a refresh token only when one comes back,
 * which is right for Google and would strand this connection within about a
 * day. Always store what came back. See pps_qbo_store_tokens().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Own plugin directory, for the reason set out in pps-pay-link.php: a redeploy
// of pps-calculators.php by another session must not be able to unload this.
if ( defined( 'PPS_QBO_LOADED' ) ) return;
define( 'PPS_QBO_LOADED', '1.0.0' );

/**
 * Endpoints. Named constants because they are the first thing to check if a
 * connect attempt fails — Intuit has moved them before.
 */
const PPS_QBO_AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
const PPS_QBO_TOKEN_URL     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
const PPS_QBO_API_PROD      = 'https://quickbooks.api.intuit.com';
const PPS_QBO_API_SANDBOX   = 'https://sandbox-quickbooks.api.intuit.com';
const PPS_QBO_SCOPE         = 'com.intuit.quickbooks.accounting';
const PPS_QBO_MINORVERSION  = '65';

const PPS_QBO_PARENT_NAME   = 'PPS ONLINE';

/* ─────────────────────────────────────────────────────────────
 * Configuration
 * ───────────────────────────────────────────────────────────── */

function pps_qbo_client_id()     { return (string) get_option( 'pps_qbo_client_id', '' ); }
function pps_qbo_client_secret() { return (string) get_option( 'pps_qbo_client_secret', '' ); }
function pps_qbo_realm_id()      { return (string) get_option( 'pps_qbo_realm_id', '' ); }
function pps_qbo_item_ref()      { return (string) get_option( 'pps_qbo_item_ref', '' ); }
function pps_qbo_webhook_token() { return (string) get_option( 'pps_qbo_webhook_token', '' ); }

/** Sandbox until deliberately switched, so a half-configured install cannot
 *  write to the real company file. */
function pps_qbo_is_production() { return 'production' === get_option( 'pps_qbo_environment', 'sandbox' ); }

function pps_qbo_api_base() {
    return pps_qbo_is_production() ? PPS_QBO_API_PROD : PPS_QBO_API_SANDBOX;
}

function pps_qbo_is_connected() {
    return '' !== (string) get_option( 'pps_qbo_refresh_token', '' ) && '' !== pps_qbo_realm_id();
}

/**
 * Whether QuickBooks can actually take a payment right now — the seam
 * pps-pay-link.php routes on.
 *
 * Being connected proves we can reach the company file, not that QuickBooks
 * Payments is switched on for it, and only the latter puts a Pay Now button on
 * an invoice. There is no cheap API call that answers this honestly, so it is
 * an explicit operator assertion. Defaulting it to false means the failure mode
 * of a fresh install is "link quietly used site checkout", not "customer opened
 * an invoice they could read but not pay".
 */
function pps_qbo_can_take_payment() {
    if ( ! pps_qbo_is_connected() ) return false;
    if ( ! pps_qbo_item_ref() ) return false;   // an invoice line needs an item
    return (bool) get_option( 'pps_qbo_payments_enabled', false );
}

/* ─────────────────────────────────────────────────────────────
 * Tokens
 * ───────────────────────────────────────────────────────────── */

/**
 * Persist a token response. ALWAYS writes the refresh token when one came
 * back — see the file header; treating it as optional is how this connection
 * dies quietly a day after it is made.
 */
function pps_qbo_store_tokens( array $body ) {
    if ( ! empty( $body['refresh_token'] ) ) {
        update_option( 'pps_qbo_refresh_token', (string) $body['refresh_token'], false );
        // Rolling 100-day expiry, extended on each use. Recorded so the admin
        // screen can warn before an idle connection lapses.
        update_option( 'pps_qbo_refresh_seen', time(), false );
    }
    if ( ! empty( $body['access_token'] ) ) {
        $ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3500;
        set_transient( 'pps_qbo_access_token', (string) $body['access_token'], $ttl );
    }
}

/**
 * A valid access token, refreshing if needed.
 *
 * Serialised with a short lock because the refresh token rotates: two requests
 * refreshing at once (a checkout and a webhook, easily) would each be handed a
 * different new token and the loser would persist a dead one. The lock is
 * best-effort — a stampede is rare and the cost of missing it is one failed
 * call, not corruption.
 */
function pps_qbo_get_access_token() {
    $cached = get_transient( 'pps_qbo_access_token' );
    if ( $cached ) return (string) $cached;

    $refresh = (string) get_option( 'pps_qbo_refresh_token', '' );
    if ( '' === $refresh ) return '';

    if ( ! pps_qbo_lock_acquire() ) {
        // Somebody else is refreshing. Give them a moment and take their token
        // rather than racing them for a new one.
        usleep( 750000 );
        $cached = get_transient( 'pps_qbo_access_token' );
        if ( $cached ) return (string) $cached;
    }

    $res = wp_remote_post( PPS_QBO_TOKEN_URL, array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( pps_qbo_client_id() . ':' . pps_qbo_client_secret() ),
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Accept'        => 'application/json',
        ),
        'body' => array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ),
    ) );

    pps_qbo_lock_release();

    if ( is_wp_error( $res ) ) {
        pps_qbo_log( 'token_refresh_failed', array( 'error' => $res->get_error_message() ) );
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( 400 === $code || 401 === $code ) {
        // The refresh token is dead — lapsed, revoked, or overwritten by a
        // rotation we lost. Clear it so the admin screen says "reconnect"
        // instead of every call failing for an unexplained reason.
        delete_option( 'pps_qbo_refresh_token' );
        delete_transient( 'pps_qbo_access_token' );
        pps_qbo_log( 'token_rejected', array( 'status' => $code ) );
        return '';
    }
    if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
        pps_qbo_log( 'token_malformed', array( 'status' => $code ) );
        return '';
    }

    pps_qbo_store_tokens( $body );
    return (string) $body['access_token'];
}

function pps_qbo_lock_acquire() {
    if ( get_transient( 'pps_qbo_refresh_lock' ) ) return false;
    set_transient( 'pps_qbo_refresh_lock', 1, 30 );
    return true;
}
function pps_qbo_lock_release() { delete_transient( 'pps_qbo_refresh_lock' ); }

/* ─────────────────────────────────────────────────────────────
 * API
 * ───────────────────────────────────────────────────────────── */

/**
 * One call against the company file.
 *
 * @return array|WP_Error decoded response body
 */
function pps_qbo_request( $method, $path, $body = null, array $query = array() ) {
    $token = pps_qbo_get_access_token();
    if ( '' === $token ) return new WP_Error( 'qbo_auth', 'QuickBooks is not connected.' );

    $realm = pps_qbo_realm_id();
    if ( '' === $realm ) return new WP_Error( 'qbo_realm', 'No QuickBooks company id stored.' );

    $query['minorversion'] = PPS_QBO_MINORVERSION;
    $url = pps_qbo_api_base() . '/v3/company/' . rawurlencode( $realm ) . '/' . ltrim( $path, '/' );
    $url = add_query_arg( $query, $url );

    $args = array(
        'method'  => $method,
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ),
    );
    if ( null !== $body ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode( $body );
    }

    $res = wp_remote_request( $url, $args );
    if ( is_wp_error( $res ) ) return $res;

    $code = (int) wp_remote_retrieve_response_code( $res );
    $out  = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code < 200 || $code >= 300 ) {
        // Intuit puts the useful part in Fault.Error[].Detail; surface it
        // rather than a bare status, because "400" alone is unactionable.
        $detail = '';
        if ( isset( $out['Fault']['Error'][0] ) ) {
            $e = $out['Fault']['Error'][0];
            $detail = trim( ( $e['Message'] ?? '' ) . ' — ' . ( $e['Detail'] ?? '' ) );
        }
        // A 401/403 from Intuit is not a JSON Fault, so $detail comes out empty
        // precisely when the reason matters most. Keep a short raw snippet for
        // those -- it names the failure (AuthenticationFailed, realm mismatch)
        // where the parsed field cannot.
        if ( '' === $detail && ( 401 === $code || 403 === $code ) ) {
            $detail = trim( substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $res ) ), 0, 300 ) );
            if ( '' === $detail ) $detail = 'empty body; ' . wp_remote_retrieve_response_message( $res );
        }
        pps_qbo_log( 'api_error', array( 'status' => $code, 'path' => $path, 'detail' => $detail ) );
        return new WP_Error( 'qbo_http_' . $code, $detail ?: ( 'QuickBooks returned ' . $code ), array( 'status' => $code ) );
    }

    return is_array( $out ) ? $out : array();
}

/** Escape a value for a QBO SQL-ish query string. Single quotes are the whole
 *  risk: an apostrophe in a display name would otherwise break the query. */
function pps_qbo_q( $v ) {
    return str_replace( "'", "\\'", (string) $v );
}

/* ─────────────────────────────────────────────────────────────
 * Customers
 * ───────────────────────────────────────────────────────────── */

/**
 * The sub-customer's display name.
 *
 * QBO enforces DisplayName uniqueness across ALL customers, so the buyer's name
 * alone collides the second time two people share one. The email is the only
 * thing we hold that is actually unique, so it goes in the name.
 */
function pps_qbo_display_name( $first, $last, $email ) {
    $person = trim( $first . ' ' . $last );
    if ( '' === $person ) $person = 'Customer';
    $name = $person . ' (' . $email . ')';
    // QBO caps DisplayName at 100 characters and rejects anything longer.
    return mb_substr( $name, 0, 100 );
}

/**
 * Whether a failed write was QuickBooks refusing a name that already exists.
 * That error is not a dead end -- it is proof the record is there, and tells us
 * to go looking again rather than give up.
 */
function pps_qbo_is_duplicate_name( $err ) {
    return is_wp_error( $err ) && false !== stripos( $err->get_error_message(), 'Duplicate Name Exists' );
}

/**
 * Find a customer by display name, seeing everything QuickBooks will refuse a
 * new name against.
 *
 * A plain equality query is narrower than QuickBooks' own uniqueness rule in two
 * ways that both bit us on the first production run: the query returns only
 * ACTIVE records, and it matches case-sensitively while the uniqueness check
 * does not. So "PPS ONLINE" could be simultaneously not-found and rejected as a
 * duplicate -- which is exactly what happened, and left the message "could not
 * find or create" on an order while the record sat in the books all along.
 *
 * @return array|null ['id' => string, 'name' => string, 'active' => bool]
 */
function pps_qbo_find_customer_by_name( $name ) {
    $cols = 'select Id, DisplayName, Active from Customer where ';
    $q    = pps_qbo_q( $name );

    // Inactive records still hold the name, so ask for them explicitly.
    foreach ( array(
        $cols . "DisplayName = '{$q}' and Active in (true, false)",
        $cols . "DisplayName like '{$q}' and Active in (true, false)",
    ) as $sql ) {
        $res = pps_qbo_request( 'GET', 'query', null, array( 'query' => $sql ) );
        if ( is_wp_error( $res ) ) continue;
        foreach ( (array) ( $res['QueryResponse']['Customer'] ?? array() ) as $c ) {
            // Case-insensitive on our side, because QuickBooks' uniqueness is.
            if ( empty( $c['Id'] ) || 0 !== strcasecmp( (string) ( $c['DisplayName'] ?? '' ), $name ) ) continue;
            return array(
                'id'     => (string) $c['Id'],
                'name'   => (string) ( $c['DisplayName'] ?? $name ),
                'active' => ! isset( $c['Active'] ) || (bool) $c['Active'],
            );
        }
    }
    return null;
}

/** The "PPS ONLINE" parent, found or created once and then remembered. */
function pps_qbo_parent_customer_id() {
    $cached = (string) get_option( 'pps_qbo_parent_id', '' );
    if ( '' !== $cached ) return $cached;

    $hit = pps_qbo_find_customer_by_name( PPS_QBO_PARENT_NAME );
    if ( $hit && $hit['active'] ) {
        update_option( 'pps_qbo_parent_id', $hit['id'], false );
        return $hit['id'];
    }
    if ( $hit ) {
        // Present but switched off. Adopting it would raise invoices against a
        // record the owner deliberately retired, and reactivating their books
        // from here is not ours to decide -- so name it and stop.
        return new WP_Error( 'qbo_parent_inactive', sprintf(
            'The QuickBooks customer "%s" (id %s) is marked inactive. Make it active in QuickBooks, then try again.',
            $hit['name'], $hit['id']
        ) );
    }

    $made = pps_qbo_request( 'POST', 'customer', array(
        'DisplayName'  => PPS_QBO_PARENT_NAME,
        'CompanyName'  => PPS_QBO_PARENT_NAME,
        'Notes'        => 'Parent for orders taken through the website. Each buyer is a sub-customer so stored payment methods stay scoped to them.',
    ) );

    if ( pps_qbo_is_duplicate_name( $made ) ) {
        // The name is taken by something a Customer query cannot see -- most
        // often a vendor or employee, since QuickBooks shares one namespace
        // across all of them. Say so; a generic failure sent us hunting.
        return new WP_Error( 'qbo_parent_taken', sprintf(
            'QuickBooks already has a name list entry called "%s" that is not an active customer — often a vendor or employee. Rename it, or reuse it as a customer, then try again.',
            PPS_QBO_PARENT_NAME
        ) );
    }
    if ( is_wp_error( $made ) ) return $made;
    if ( empty( $made['Customer']['Id'] ) ) return new WP_Error( 'qbo_parent', 'QuickBooks did not return a customer id for the parent.' );

    $id = (string) $made['Customer']['Id'];
    update_option( 'pps_qbo_parent_id', $id, false );
    return $id;
}

/**
 * The buyer, as a sub-customer of PPS ONLINE. Found by email first so a repeat
 * customer does not accumulate records.
 */
function pps_qbo_resolve_customer( $first, $last, $email ) {
    $email = sanitize_email( $email );
    if ( ! $email || ! is_email( $email ) ) return new WP_Error( 'qbo_email', 'A customer email is required.' );

    $found = pps_qbo_request( 'GET', 'query', null, array(
        'query' => "select Id from Customer where PrimaryEmailAddr = '" . pps_qbo_q( $email ) . "'",
    ) );
    if ( ! is_wp_error( $found ) && ! empty( $found['QueryResponse']['Customer'][0]['Id'] ) ) {
        return (string) $found['QueryResponse']['Customer'][0]['Id'];
    }

    // Propagate the parent's own error rather than flattening every cause into
    // "could not find or create" -- that sentence was true and useless.
    $parent = pps_qbo_parent_customer_id();
    if ( is_wp_error( $parent ) ) return $parent;
    if ( '' === $parent ) return new WP_Error( 'qbo_parent', 'Could not find or create the PPS ONLINE parent customer.' );

    $payload = array(
        'DisplayName'      => pps_qbo_display_name( $first, $last, $email ),
        'GivenName'        => $first,
        'FamilyName'       => $last,
        'PrimaryEmailAddr' => array( 'Address' => $email ),
        'Job'              => true,
        'ParentRef'        => array( 'value' => $parent ),
    );
    $made = pps_qbo_request( 'POST', 'customer', $payload );

    // Same trap as the parent: the buyer can be un-findable by email yet still
    // own the display name (an existing record with no email on it, or an
    // inactive one). The duplicate error proves it exists, so look again by the
    // name we just tried instead of failing the checkout.
    if ( pps_qbo_is_duplicate_name( $made ) ) {
        $hit = pps_qbo_find_customer_by_name( $payload['DisplayName'] );
        if ( $hit && $hit['active'] ) return $hit['id'];
        if ( $hit ) return new WP_Error( 'qbo_customer_inactive', sprintf(
            'The QuickBooks customer "%s" (id %s) is inactive. Make it active in QuickBooks, then try again.',
            $hit['name'], $hit['id']
        ) );
        return new WP_Error( 'qbo_customer_taken', sprintf(
            'QuickBooks already has a name list entry called "%s" that is not an active customer — often a vendor or employee.',
            $payload['DisplayName']
        ) );
    }

    if ( is_wp_error( $made ) ) return $made;
    if ( empty( $made['Customer']['Id'] ) ) return new WP_Error( 'qbo_customer', 'QuickBooks did not return a customer id.' );

    return (string) $made['Customer']['Id'];
}

/* ─────────────────────────────────────────────────────────────
 * Logging — never tokens, never card data
 * ───────────────────────────────────────────────────────────── */

function pps_qbo_log( $event, array $data = array() ) {
    $log = get_option( 'pps_qbo_log', array() );
    if ( ! is_array( $log ) ) $log = array();
    array_unshift( $log, array( 'at' => gmdate( 'c' ), 'event' => (string) $event ) + $data );
    update_option( 'pps_qbo_log', array_slice( $log, 0, 25 ), false );
}

/* ─────────────────────────────────────────────────────────────
 * Invoices
 * ───────────────────────────────────────────────────────────── */

/**
 * Raise the invoice for a WooCommerce order and return its hosted payment link.
 *
 * Idempotent: an order that already carries an invoice id returns that
 * invoice's link rather than raising a second one. Worth being strict about —
 * a customer refreshing the confirmation page must not generate a second
 * invoice, and QuickBooks will happily create one.
 *
 * @return string|WP_Error the payment link
 */
function pps_qbo_invoice_order( $order ) {
    if ( ! $order instanceof WC_Order ) return new WP_Error( 'qbo_order', 'No order.' );

    $existing = (string) $order->get_meta( '_pps_qbo_invoice_id' );
    if ( '' !== $existing ) return pps_qbo_invoice_link( $existing );

    if ( ! pps_qbo_can_take_payment() ) {
        return new WP_Error( 'qbo_unavailable', 'QuickBooks cannot take a payment right now.' );
    }

    $customer = pps_qbo_resolve_customer(
        $order->get_billing_first_name(),
        $order->get_billing_last_name(),
        $order->get_billing_email()
    );
    if ( is_wp_error( $customer ) ) return $customer;

    $lines = array();
    foreach ( $order->get_items() as $item ) {
        $amount = round( (float) $item->get_total(), 2 );
        if ( $amount <= 0 ) continue;
        $qty  = max( 1, (int) $item->get_quantity() );
        $desc = $item->get_name();
        // The customer's own name for the job leads, when they gave one: it is
        // the phrase they will use on the phone, so it is the one worth having
        // at the top of the invoice line rather than buried under the spec.
        $project = $item->get_meta( 'Project' );
        if ( $project ) $desc = $project . ' — ' . $desc;
        // The Specs meta is the job as the operator described it in the thread;
        // it is what makes the QuickBooks line readable months later.
        $specs = $item->get_meta( 'Specs' );
        if ( $specs ) $desc .= "\n" . $specs;

        $lines[] = array(
            'DetailType'          => 'SalesItemLineDetail',
            'Amount'              => $amount,
            'Description'         => mb_substr( $desc, 0, 4000 ),
            'SalesItemLineDetail' => array(
                'ItemRef'   => array( 'value' => pps_qbo_item_ref() ),
                'Qty'       => $qty,
                'UnitPrice' => round( $amount / $qty, 2 ),
            ),
        );
    }
    if ( ! $lines ) return new WP_Error( 'qbo_lines', 'The order has nothing to invoice.' );

    $shipping = round( (float) $order->get_shipping_total(), 2 );

    $payload = array(
        'CustomerRef'                  => array( 'value' => $customer ),
        'Line'                         => $lines,
        'BillEmail'                    => array( 'Address' => $order->get_billing_email() ),
        // The whole point: these are what put a Pay Now button on the invoice.
        'AllowOnlineCreditCardPayment' => true,
        'AllowOnlineACHPayment'        => true,
        'PrivateNote'                  => 'WooCommerce order #' . $order->get_order_number(),
        'CustomerMemo'                 => array( 'value' => 'Order #' . $order->get_order_number() ),
    );
    if ( $shipping > 0 ) {
        $payload['ShipDate'] = null;
        $payload['Line'][]   = array(
            'DetailType'          => 'SalesItemLineDetail',
            'Amount'              => $shipping,
            'Description'         => 'Shipping',
            'SalesItemLineDetail' => array(
                'ItemRef'   => array( 'value' => pps_qbo_item_ref() ),
                'Qty'       => 1,
                'UnitPrice' => $shipping,
            ),
        );
    }
    if ( $order->get_shipping_address_1() ) {
        $payload['ShipAddr'] = array_filter( array(
            'Line1'                  => $order->get_shipping_address_1(),
            'Line2'                  => $order->get_shipping_address_2(),
            'City'                   => $order->get_shipping_city(),
            'CountrySubDivisionCode' => $order->get_shipping_state(),
            'PostalCode'             => $order->get_shipping_postcode(),
        ) );
    }

    // The order number as the invoice number, as asked. QBO only honours this
    // when custom transaction numbers are enabled; with it off QBO silently
    // substitutes its own sequence, and it rejects a duplicate outright. So try
    // it, and on a DocNumber complaint fall back to letting QBO number the
    // invoice rather than failing the customer's checkout over bookkeeping.
    $withDoc = $payload;
    $withDoc['DocNumber'] = (string) $order->get_order_number();

    $made = pps_qbo_request( 'POST', 'invoice', $withDoc );
    if ( is_wp_error( $made ) && false !== stripos( $made->get_error_message(), 'DocNumber' ) ) {
        pps_qbo_log( 'docnumber_rejected', array( 'order' => $order->get_id() ) );
        $order->add_order_note( 'QuickBooks would not accept the order number as the invoice number; it numbered the invoice itself. Enable custom transaction numbers in QuickBooks to change this.' );
        $made = pps_qbo_request( 'POST', 'invoice', $payload );
    }
    if ( is_wp_error( $made ) ) return $made;
    if ( empty( $made['Invoice']['Id'] ) ) return new WP_Error( 'qbo_invoice', 'QuickBooks did not return an invoice id.' );

    $invoice_id = (string) $made['Invoice']['Id'];
    $order->update_meta_data( '_pps_qbo_invoice_id', $invoice_id );
    $order->update_meta_data( '_pps_qbo_invoice_no', (string) ( $made['Invoice']['DocNumber'] ?? '' ) );

    // Money guard: an invoice that does not match the order is a bookkeeping
    // error the customer will never see and nobody will catch later. Record it
    // loudly rather than trusting the round trip.
    $qbo_total = round( (float) ( $made['Invoice']['TotalAmt'] ?? 0 ), 2 );
    $wc_total  = round( (float) $order->get_total(), 2 );
    if ( abs( $qbo_total - $wc_total ) >= 0.01 ) {
        $order->update_meta_data( '_pps_qbo_total_mismatch', $qbo_total );
        $order->add_order_note( sprintf(
            'QuickBooks invoice total (%s) does not match the order total (%s). Check the invoice before treating this as settled.',
            wc_price( $qbo_total ), wc_price( $wc_total )
        ) );
        pps_qbo_log( 'total_mismatch', array( 'order' => $order->get_id(), 'qbo' => $qbo_total, 'wc' => $wc_total ) );
    }

    $order->save();
    pps_qbo_log( 'invoice_created', array( 'order' => $order->get_id(), 'invoice' => $invoice_id ) );

    return pps_qbo_invoice_link( $invoice_id );
}

/**
 * The customer-facing payment page for an invoice.
 *
 * Fetched on demand rather than stored: nothing authoritative says how long one
 * of these stays valid, and a link that has gone stale in the database is worse
 * than one round trip.
 */
function pps_qbo_invoice_link( $invoice_id ) {
    $res = pps_qbo_request( 'GET', 'invoice/' . rawurlencode( $invoice_id ), null, array( 'include' => 'invoiceLink' ) );
    if ( is_wp_error( $res ) ) return $res;
    $link = (string) ( $res['Invoice']['invoiceLink'] ?? '' );
    if ( '' === $link ) {
        return new WP_Error( 'qbo_link', 'QuickBooks did not return a payment link for that invoice. Confirm QuickBooks Payments is enabled on the company.' );
    }
    return $link;
}

/** Is this invoice settled? Balance, not the event, is the truth. */
function pps_qbo_invoice_is_paid( $invoice_id ) {
    $res = pps_qbo_request( 'GET', 'invoice/' . rawurlencode( $invoice_id ) );
    if ( is_wp_error( $res ) ) return null;              // unknown, not unpaid
    if ( ! isset( $res['Invoice']['Balance'] ) ) return null;
    return round( (float) $res['Invoice']['Balance'], 2 ) <= 0.0;
}

/** The order carrying a given QuickBooks invoice id. */
function pps_qbo_order_for_invoice( $invoice_id ) {
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'status'     => array( 'pending', 'on-hold', 'failed', 'processing', 'completed' ),
        'meta_key'   => '_pps_qbo_invoice_id',
        'meta_value' => (string) $invoice_id,
    ) );
    return $orders ? $orders[0] : null;
}

/* ─────────────────────────────────────────────────────────────
 * Webhook — how a payment reaches WooCommerce
 * ───────────────────────────────────────────────────────────── */

/**
 * Verify Intuit's signature: base64 HMAC-SHA256 of the raw body, keyed with the
 * verifier token. Compared with hash_equals because a leaky compare is how a
 * secret gets guessed a byte at a time.
 *
 * The RAW body must be hashed — re-encoding the parsed JSON changes the bytes
 * and every signature fails.
 */
function pps_qbo_webhook_signature_valid( $raw_body, $signature ) {
    $token = pps_qbo_webhook_token();
    if ( '' === $token || ! is_string( $signature ) || '' === $signature ) return false;
    $expected = base64_encode( hash_hmac( 'sha256', (string) $raw_body, $token, true ) );
    return hash_equals( $expected, $signature );
}

/** Invoice ids implicated by a notification payload. */
function pps_qbo_webhook_invoice_ids( array $payload ) {
    $ids = array();
    foreach ( (array) ( $payload['eventNotifications'] ?? array() ) as $note ) {
        foreach ( (array) ( $note['dataChangeEvent']['entities'] ?? array() ) as $entity ) {
            $name = (string) ( $entity['name'] ?? '' );
            $id   = (string) ( $entity['id'] ?? '' );
            if ( '' === $id ) continue;
            if ( 'Invoice' === $name ) {
                $ids[] = $id;
            } elseif ( 'Payment' === $name ) {
                // A payment names the invoices it settles, so follow it rather
                // than guessing which invoice moved.
                $pay = pps_qbo_request( 'GET', 'payment/' . rawurlencode( $id ) );
                if ( is_wp_error( $pay ) ) continue;
                foreach ( (array) ( $pay['Payment']['Line'] ?? array() ) as $line ) {
                    foreach ( (array) ( $line['LinkedTxn'] ?? array() ) as $txn ) {
                        if ( 'Invoice' === ( $txn['TxnType'] ?? '' ) && ! empty( $txn['TxnId'] ) ) {
                            $ids[] = (string) $txn['TxnId'];
                        }
                    }
                }
            }
        }
    }
    return array_values( array_unique( $ids ) );
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/qbo-webhook', array(
        'methods'             => array( 'POST' ),
        // Checked inside the handler so a refusal can leave a note; a
        // permission_callback rejects before anything can be recorded.
        'permission_callback' => '__return_true',
        'callback'            => 'pps_qbo_handle_webhook',
    ) );
} );

function pps_qbo_handle_webhook( WP_REST_Request $request ) {
    $raw = $request->get_body();
    $sig = (string) $request->get_header( 'intuit_signature' );

    if ( ! pps_qbo_webhook_signature_valid( $raw, $sig ) ) {
        pps_qbo_log( 'webhook_rejected', array( 'sig_present' => '' !== $sig, 'bytes' => strlen( (string) $raw ) ) );
        return new WP_REST_Response( array( 'ok' => false ), 401 );
    }

    $payload = json_decode( $raw, true );
    if ( ! is_array( $payload ) ) return new WP_REST_Response( array( 'ok' => true ), 200 );

    $settled = 0;
    foreach ( pps_qbo_webhook_invoice_ids( $payload ) as $invoice_id ) {
        $order = pps_qbo_order_for_invoice( $invoice_id );
        if ( ! $order ) continue;
        if ( ! pps_job_invoice_is_unpaid( $order ) ) continue;

        // Trust the invoice, not the notification: the event says something
        // changed, and only the balance says it was paid.
        if ( true !== pps_qbo_invoice_is_paid( $invoice_id ) ) continue;

        $order->update_meta_data( '_pps_qbo_paid_at', current_time( 'mysql' ) );
        $order->add_order_note( 'Paid in QuickBooks — invoice ' . $invoice_id . ' has a zero balance.' );
        $order->payment_complete( 'qbo-' . $invoice_id );
        $order->save();
        $settled++;
        pps_qbo_log( 'order_settled', array( 'order' => $order->get_id(), 'invoice' => $invoice_id ) );
    }

    // Always 200 on an authenticated delivery: a non-2xx makes Intuit retry the
    // whole batch, and "no order of ours" is not a failure.
    return new WP_REST_Response( array( 'ok' => true, 'settled' => $settled ), 200 );
}

/* ─────────────────────────────────────────────────────────────
 * Admin — connect, configure, diagnose
 * ───────────────────────────────────────────────────────────── */

function pps_qbo_redirect_uri() {
    // Must match the Redirect URI registered on the Intuit app exactly,
    // including scheme and trailing characters — Intuit compares it literally.
    return admin_url( 'admin.php?page=pps-qbo' );
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'QuickBooks',
        'QuickBooks',
        'manage_options',
        'pps-qbo',
        'pps_qbo_admin_page'
    );
}, 20 );

function pps_qbo_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $notice = '';
    $error  = '';

    // ---- Save settings -------------------------------------------------
    if ( isset( $_POST['pps_qbo_save'] ) && check_admin_referer( 'pps_qbo_save' ) ) {
        update_option( 'pps_qbo_client_id', sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ), false );
        // Only overwrite the secret when something was actually typed, so
        // re-saving the form does not blank it out.
        $secret = trim( (string) wp_unslash( $_POST['client_secret'] ?? '' ) );
        if ( '' !== $secret ) update_option( 'pps_qbo_client_secret', $secret, false );

        update_option( 'pps_qbo_environment', 'production' === ( $_POST['environment'] ?? '' ) ? 'production' : 'sandbox', false );
        update_option( 'pps_qbo_item_ref', sanitize_text_field( wp_unslash( $_POST['item_ref'] ?? '' ) ), false );

        $verifier = trim( (string) wp_unslash( $_POST['webhook_token'] ?? '' ) );
        if ( '' !== $verifier ) update_option( 'pps_qbo_webhook_token', $verifier, false );

        update_option( 'pps_qbo_payments_enabled', ! empty( $_POST['payments_enabled'] ), false );
        $notice = 'Settings saved.';
    }

    // ---- Disconnect ----------------------------------------------------
    if ( isset( $_POST['pps_qbo_disconnect'] ) && check_admin_referer( 'pps_qbo_save' ) ) {
        delete_option( 'pps_qbo_refresh_token' );
        delete_option( 'pps_qbo_realm_id' );
        delete_option( 'pps_qbo_parent_id' );
        delete_transient( 'pps_qbo_access_token' );
        $notice = 'Disconnected from QuickBooks.';
    }

    // ---- Test the connection -------------------------------------------
    // Cheapest honest question we can ask the company file: who are you. It
    // exercises exactly the path an invoice uses -- token, realm, base URL --
    // so a pass here means the next failure is about the invoice, not the
    // connection.
    if ( isset( $_POST['pps_qbo_test'] ) && check_admin_referer( 'pps_qbo_save' ) ) {
        $probe = pps_qbo_request( 'GET', 'companyinfo/' . rawurlencode( pps_qbo_realm_id() ) );
        if ( is_wp_error( $probe ) ) {
            $error = 'Test failed: ' . $probe->get_error_message()
                . ' (' . $probe->get_error_code() . '). The log below has the detail.';
        } else {
            $ci = $probe['CompanyInfo'] ?? array();
            $notice = 'Connection works — reached "' . ( $ci['CompanyName'] ?? 'unknown company' ) . '"'
                . ( isset( $ci['Country'] ) ? ', ' . $ci['Country'] : '' ) . '.';
        }
    }

    // ---- OAuth callback ------------------------------------------------
    if ( isset( $_GET['code'] ) ) {
        if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'pps_qbo_oauth' ) ) {
            $error = 'OAuth state mismatch — please try connecting again.';
        } else {
            $realm = isset( $_GET['realmId'] ) ? sanitize_text_field( wp_unslash( $_GET['realmId'] ) ) : '';
            $res = wp_remote_post( PPS_QBO_TOKEN_URL, array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( pps_qbo_client_id() . ':' . pps_qbo_client_secret() ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ),
                'body' => array(
                    'grant_type'   => 'authorization_code',
                    'code'         => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
                    'redirect_uri' => pps_qbo_redirect_uri(),
                ),
            ) );
            if ( is_wp_error( $res ) ) {
                $error = 'Could not reach Intuit: ' . $res->get_error_message();
            } else {
                $body = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( is_array( $body ) && ! empty( $body['refresh_token'] ) ) {
                    pps_qbo_store_tokens( $body );
                    if ( $realm ) update_option( 'pps_qbo_realm_id', $realm, false );
                    delete_option( 'pps_qbo_parent_id' );   // may be a different company now
                    $notice = 'Connected to QuickBooks.';
                } else {
                    // Surface what Intuit actually said. The reason matters more
                    // here than anywhere else in this file: invalid_grant means
                    // the code was spent or expired (retry the connect),
                    // invalid_client means the keys are wrong (a different fix
                    // entirely). Reporting one generic sentence for both sent us
                    // checking credentials that were fine.
                    $why = '';
                    if ( is_array( $body ) && ! empty( $body['error'] ) ) {
                        $why = (string) $body['error'];
                        if ( ! empty( $body['error_description'] ) ) $why .= ' — ' . $body['error_description'];
                    }
                    pps_qbo_log( 'oauth_failed', array( 'error' => $why ?: 'no refresh_token in response' ) );
                    $error = 'Intuit did not return a refresh token'
                        . ( $why ? ': ' . $why : '.' )
                        . ( false !== strpos( $why, 'invalid_grant' )
                            ? ' That code was already used — click Connect to QuickBooks again from a clean page.'
                            : ' Check the client id, secret and redirect URI.' );
                }
            }
        }
    }

    $connected = pps_qbo_is_connected();
    $state     = wp_create_nonce( 'pps_qbo_oauth' );
    $auth_url  = add_query_arg( array(
        'client_id'     => rawurlencode( pps_qbo_client_id() ),
        'response_type' => 'code',
        'scope'         => rawurlencode( PPS_QBO_SCOPE ),
        'redirect_uri'  => rawurlencode( pps_qbo_redirect_uri() ),
        'state'         => $state,
    ), PPS_QBO_AUTHORIZE_URL );

    $seen = (int) get_option( 'pps_qbo_refresh_seen', 0 );
    ?>
    <div class="wrap" style="max-width:780px">
        <h1>QuickBooks</h1>
        <?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
        <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>

        <p style="color:#50575e">Raises the invoice in QuickBooks when a customer submits a QuickBooks-routed
        quote, hands them its payment page, and marks the order paid when QuickBooks says it settled.
        Buyers are created as sub-customers of <strong><?php echo esc_html( PPS_QBO_PARENT_NAME ); ?></strong>
        so a saved payment method stays scoped to the person who saved it.</p>

        <h2>Connection</h2>
        <p><strong>Status:</strong>
            <?php if ( $connected ) : ?>
                <span style="color:#008a20">Connected</span> — company <code><?php echo esc_html( pps_qbo_realm_id() ); ?></code>,
                <?php echo esc_html( pps_qbo_is_production() ? 'production' : 'sandbox' ); ?>
                <?php if ( $seen ) : ?>
                    <br><span style="color:#50575e">Token last refreshed <?php echo esc_html( human_time_diff( $seen ) ); ?> ago.
                    The refresh token lapses after 100 days unused.</span>
                <?php endif; ?>
            <?php else : ?>
                <span style="color:#b32d2e">Not connected</span>
            <?php endif; ?>
        </p>

        <p><strong>Redirect URI</strong> — register this on the Intuit app exactly as shown:<br>
            <code><?php echo esc_html( pps_qbo_redirect_uri() ); ?></code></p>
        <p><strong>Webhook URL</strong> — register this as the app's webhook endpoint:<br>
            <code><?php echo esc_url( rest_url( 'pps/v1/qbo-webhook' ) ); ?></code></p>

        <?php if ( pps_qbo_client_id() && pps_qbo_client_secret() ) : ?>
            <p><a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
                <?php echo $connected ? 'Reconnect' : 'Connect to QuickBooks'; ?></a></p>
        <?php else : ?>
            <p><em>Enter the client id and secret below, save, then connect.</em></p>
        <?php endif; ?>

        <?php /* Post to the bare page URL, never the current one: after the OAuth
                 callback the address still carries ?code=, and a form with no
                 action would replay that spent code on every Save. */ ?>
        <form method="post" action="<?php echo esc_url( pps_qbo_redirect_uri() ); ?>">
            <?php wp_nonce_field( 'pps_qbo_save' ); ?>
            <h2>Settings</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="qbo-cid">Client ID</label></th>
                    <td><input type="text" id="qbo-cid" name="client_id" class="regular-text"
                        value="<?php echo esc_attr( pps_qbo_client_id() ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="qbo-sec">Client secret</label></th>
                    <td><input type="password" id="qbo-sec" name="client_secret" class="regular-text"
                        placeholder="<?php echo pps_qbo_client_secret() ? 'stored — leave blank to keep' : ''; ?>">
                        <p class="description">Stored in the options table, never in the plugin source.</p></td>
                </tr>
                <tr>
                    <th scope="row">Environment</th>
                    <td>
                        <label><input type="radio" name="environment" value="sandbox"
                            <?php checked( ! pps_qbo_is_production() ); ?>> Sandbox</label><br>
                        <label><input type="radio" name="environment" value="production"
                            <?php checked( pps_qbo_is_production() ); ?>> Production</label>
                        <p class="description">Reconnect after changing this — a token belongs to one environment.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="qbo-item">Service item ID</label></th>
                    <td><input type="text" id="qbo-item" name="item_ref" class="regular-text"
                        value="<?php echo esc_attr( pps_qbo_item_ref() ); ?>">
                        <p class="description">The QuickBooks item every invoice line is booked to (e.g. Printing).
                        Invoices cannot be raised without it.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="qbo-hook">Webhook verifier token</label></th>
                    <td><input type="password" id="qbo-hook" name="webhook_token" class="regular-text"
                        placeholder="<?php echo pps_qbo_webhook_token() ? 'stored — leave blank to keep' : ''; ?>">
                        <p class="description">From the Intuit app's webhooks screen. Without it no payment
                        notification is trusted, and orders stay unpaid.</p></td>
                </tr>
                <tr>
                    <th scope="row">QuickBooks Payments</th>
                    <td>
                        <label><input type="checkbox" name="payments_enabled" value="1"
                            <?php checked( (bool) get_option( 'pps_qbo_payments_enabled', false ) ); ?>>
                            QuickBooks Payments is enabled on this company</label>
                        <p class="description">Only tick this if invoices actually show a Pay Now button.
                        With it off, links asking for QuickBooks quietly use site checkout instead —
                        which is the safe failure, rather than a customer opening an invoice they cannot pay.</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="pps_qbo_save" class="button button-primary">Save settings</button>
                <?php if ( $connected ) : ?>
                    <button type="submit" name="pps_qbo_test" class="button">Test connection</button>
                    <button type="submit" name="pps_qbo_disconnect" class="button"
                        onclick="return confirm('Disconnect from QuickBooks?')">Disconnect</button>
                <?php endif; ?>
            </p>
        </form>

        <?php $log = get_option( 'pps_qbo_log', array() ); if ( is_array( $log ) && $log ) : ?>
            <h2>Recent activity</h2>
            <table class="widefat striped"><tbody>
            <?php foreach ( array_slice( $log, 0, 15 ) as $row ) : ?>
                <tr>
                    <td style="width:180px"><?php echo esc_html( $row['at'] ?? '' ); ?></td>
                    <td><code><?php echo esc_html( $row['event'] ?? '' ); ?></code></td>
                    <td><?php
                        $rest = $row; unset( $rest['at'], $rest['event'] );
                        echo esc_html( $rest ? wp_json_encode( $rest ) : '' );
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>
    <?php
}
