<?php
/**
 * PPS Job Quote — a link you send before you know who is buying.
 *
 * The invoice screen needed the customer's details up front, which is the
 * wrong way round for work sold over email: you know the job and the price
 * long before you know their billing address. A quote is the job only —
 * product, specs, one or more quantity/price tiers, and whether the customer
 * may choose a delivery date. Send the link; they fill in the rest.
 *
 * Flow: operator creates a quote -> /quote/?q=TOKEN -> customer picks a
 * quantity tier and (optionally) a delivery date, enters billing and shipping
 * -> a real WooCommerce order is created from those details -> they pay, by
 * site checkout or the QuickBooks link attached to the quote.
 *
 * The quantity tiers travel onto the order as _pps_qty_tiers, which is what
 * lets a future reorder offer the same alternatives instead of only the exact
 * quantity bought. Those stored prices are historical, so reorders multiply
 * them by pps_past_order_multiplier — the knob that lets old prices drift up
 * with costs instead of being honoured forever at the original figure.
 *
 * A quote is not an order. Nothing exists in WooCommerce until the customer
 * submits, so unsent or ignored quotes never pollute the order list.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PPS_QUOTE_CPT = 'pps_quote';

add_action( 'init', function () {
    register_post_type( PPS_QUOTE_CPT, array(
        'label'           => 'PPS Quotes',
        'public'          => false,
        'show_ui'         => false,
        'capability_type' => 'post',
        'supports'        => array( 'title' ),
    ) );
} );

/* ─────────────────────────────────────────────────────────────
 * Pricing helpers
 * ───────────────────────────────────────────────────────────── */

/**
 * How much a historical price is worth today. 1.00 by default, so nothing
 * moves until it is deliberately set. Clamped to a sane band because a typo
 * here silently re-prices every reorder on the site.
 */
function pps_past_order_multiplier() {
    $m = (float) get_option( 'pps_past_order_multiplier', 1.0 );
    if ( $m <= 0 ) $m = 1.0;
    return max( 0.5, min( 3.0, $m ) );
}

/** Apply the multiplier and round to cents. */
function pps_apply_past_multiplier( $price ) {
    return round( ( (float) $price ) * pps_past_order_multiplier(), 2 );
}

/**
 * Normalise a list of line items to [ ['description'=>string,'qty'=>int,'price'=>float], ... ].
 *
 * Unlike tiers, order is PRESERVED and nothing is deduped: these are the jobs
 * as the operator listed them, and two identical lines are two real line items
 * (two hundred of the same card for two departments, say). Anything without a
 * description or a positive price is dropped, because it would otherwise reach
 * an invoice as a blank or free row.
 */
function pps_quote_normalise_lines( $raw ) {
    $out = array();
    if ( ! is_array( $raw ) ) return $out;
    foreach ( $raw as $l ) {
        if ( ! is_array( $l ) ) continue;
        $d = isset( $l['description'] ) ? trim( (string) $l['description'] ) : '';
        $q = isset( $l['qty'] ) ? absint( $l['qty'] ) : 0;
        $p = isset( $l['price'] ) ? round( (float) $l['price'], 2 ) : 0;
        if ( '' === $d || $p <= 0 ) continue;
        $out[] = array( 'description' => $d, 'qty' => max( 1, $q ), 'price' => $p );
    }
    return $out;
}

/** What a multi-line quote costs: the sum of its lines. */
function pps_quote_lines_total( array $lines ) {
    $t = 0.0;
    foreach ( $lines as $l ) $t += (float) $l['price'];
    return round( $t, 2 );
}

/**
 * Normalise a tier list to [ ['qty'=>int,'price'=>float], ... ], sorted by
 * quantity, deduped, positives only. Accepts the raw shape posted by the form.
 */
function pps_quote_normalise_tiers( $raw ) {
    $out = array();
    if ( ! is_array( $raw ) ) return $out;
    foreach ( $raw as $t ) {
        if ( ! is_array( $t ) ) continue;
        $q = isset( $t['qty'] ) ? absint( $t['qty'] ) : 0;
        $p = isset( $t['price'] ) ? round( (float) $t['price'], 2 ) : 0;
        if ( $q < 1 || $p <= 0 ) continue;
        $out[ $q ] = array( 'qty' => $q, 'price' => $p );  // keyed: later wins, dedupes
    }
    ksort( $out );
    return array_values( $out );
}

/** Business days from now — "min production days" means working days. */
function pps_quote_earliest_date( $min_days ) {
    $min_days = max( 0, (int) $min_days );

    // Defer to the shared helper, which honours pps_get_closures() — the same
    // holiday list the calculators use. This used to run its own loop that
    // skipped weekends only, so a quote could offer a delivery date on
    // Christmas Day and the shop would find out when the customer did.
    if ( function_exists( 'pps_add_business_days' ) ) {
        $start = new DateTime( current_time( 'Y-m-d' ) );
        return pps_add_business_days( $start, $min_days )->format( 'Y-m-d' );
    }

    // Only reached if this file is loaded without the main plugin.
    $ts = current_time( 'timestamp' );
    while ( $min_days > 0 ) {
        $ts = strtotime( '+1 day', $ts );
        if ( (int) gmdate( 'N', $ts ) < 6 ) $min_days--;
    }
    return gmdate( 'Y-m-d', $ts );
}

/* ─────────────────────────────────────────────────────────────
 * Quote storage
 * ───────────────────────────────────────────────────────────── */

function pps_quote_create( array $a ) {
    $pid   = isset( $a['product'] ) ? absint( $a['product'] ) : 0;
    $tiers = pps_quote_normalise_tiers( isset( $a['tiers'] ) ? $a['tiers'] : array() );

    $product = $pid ? wc_get_product( $pid ) : false;
    if ( ! $product || ! $product->exists() ) {
        return new WP_Error( 'product', 'Pick a product for the quote.' );
    }
    if ( ! $tiers ) {
        return new WP_Error( 'tiers', 'Enter at least one quantity and price.' );
    }

    // Three routes, not two. 'quickbooks' is the legacy pasted link settled by
    // hand; 'qbo_api' raises the invoice through the API and is settled by a
    // webhook. Anything unrecognised falls to site checkout rather than
    // guessing, because the wrong guess strands a customer at payment.
    $asked  = isset( $a['pay_source'] ) ? (string) $a['pay_source'] : 'site';
    $source = in_array( $asked, array( 'quickbooks', 'qbo_api' ), true ) ? $asked : 'site';
    $link   = isset( $a['pay_link'] ) ? esc_url_raw( trim( (string) $a['pay_link'] ) ) : '';
    if ( 'quickbooks' === $source ) {
        if ( ! $link || ! wp_http_validate_url( $link ) || 0 !== stripos( $link, 'https://' ) ) {
            return new WP_Error( 'paylink', 'Paste the full https:// QuickBooks payment link, or switch to card checkout.' );
        }
    } else {
        $link = '';
    }

    // A caller may supply the token so the link can be known before it is
    // created (see pps_paylink_token). Anything unsupplied stays random.
    $token = isset( $a['token'] ) ? sanitize_title( (string) $a['token'] ) : '';
    if ( '' === $token ) $token = wp_generate_password( 24, false, false );

    $id = wp_insert_post( array(
        'post_type'   => PPS_QUOTE_CPT,
        'post_status' => 'publish',
        'post_title'  => $product->get_name() . ' — ' . $tiers[0]['qty'],
        'post_name'   => $token,
    ), true );
    if ( is_wp_error( $id ) ) return new WP_Error( 'create', 'Could not store the quote.' );

    // Read the slug BACK rather than trusting what we asked for. WordPress
    // appends -2, -3 to make post_name unique, and pps_quote_get() looks the
    // quote up by name — so a suffixed slug with the original in _q_token
    // produces a link that 404s. Random tokens never collided and hid this;
    // predictable ones collide whenever two are minted in the same minute.
    $saved = get_post( $id );
    if ( $saved && $saved->post_name ) $token = $saved->post_name;

    update_post_meta( $id, '_q_token', $token );
    update_post_meta( $id, '_q_product', $pid );
    update_post_meta( $id, '_q_specs', isset( $a['specs'] ) ? sanitize_textarea_field( $a['specs'] ) : '' );
    update_post_meta( $id, '_q_tiers', $tiers );
    // Several line items, when the command carried them. Absent on every quote
    // made before this existed, which is exactly how a one-line quote reads.
    $lines = pps_quote_normalise_lines( isset( $a['lines'] ) ? $a['lines'] : array() );
    if ( $lines ) update_post_meta( $id, '_q_lines', $lines );
    // A reusable link keeps working after its order is paid, so one link can
    // serve a standing job. Off unless asked for: the price is frozen into the
    // link, so a reusable one quotes today's figure indefinitely.
    if ( ! empty( $a['reusable'] ) ) update_post_meta( $id, '_q_reusable', 1 );
    // The parcel weight, used ONLY to rate a foreign destination. Absent on
    // every domestic link, which is normal: a US pay link quotes delivery
    // inside its price and never asks a carrier anything.
    $wt = isset( $a['weight_lb'] ) ? (float) $a['weight_lb'] : 0;
    if ( $wt > 0 && $wt <= 2000 ) update_post_meta( $id, '_q_weight_lb', round( $wt, 2 ) );
    // Nothing ships: billing only, and no delivery date to choose, so the
    // date picker is forced off whatever min_days said.
    if ( ! empty( $a['noship'] ) ) {
        update_post_meta( $id, '_q_noship', 1 );
        update_post_meta( $id, '_q_allow_date', 0 );
    }
    update_post_meta( $id, '_q_allow_date', ! empty( $a['allow_date'] ) ? 1 : 0 );
    update_post_meta( $id, '_q_min_days', max( 0, (int) ( $a['min_days'] ?? 0 ) ) );
    update_post_meta( $id, '_q_pay_source', $source );
    if ( $link ) update_post_meta( $id, '_q_pay_link', $link );
    update_post_meta( $id, '_q_by', sanitize_text_field( $a['by'] ?? 'console' ) );
    update_post_meta( $id, '_q_note', isset( $a['note'] ) ? sanitize_textarea_field( $a['note'] ) : '' );

    return $id;
}

/**
 * The orders a quote has produced, as order IDs.
 *
 * get_post_meta() returns '' for a key never written, and (array) '' is
 * array( '' ) -- one empty element, which intval() turns into 0. So the FIRST
 * order placed from any quote left a phantom 0 ahead of it in the history.
 * Invisible in storage; not invisible on the page. A reusable link counts this
 * list to tell the customer how many times they have ordered before, so one
 * order read as two, and the page invited them to order again having told them
 * something untrue about what they had already done.
 *
 * Filtering on read heals the rows already stored, so there is nothing to
 * migrate -- 0 is not an order ID any more than '' is.
 */
function pps_quote_order_history( $raw ) {
    $out = array();
    foreach ( (array) $raw as $id ) {
        $id = (int) $id;
        if ( $id > 0 ) $out[] = $id;
    }
    return array_values( array_unique( $out ) );
}

function pps_quote_get( $token ) {
    $token = sanitize_title( (string) $token );
    if ( ! $token ) return null;
    $posts = get_posts( array(
        'post_type'      => PPS_QUOTE_CPT,
        'name'           => $token,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
    ) );
    if ( ! $posts ) return null;
    $id = $posts[0]->ID;
    return array(
        'id'         => $id,
        'token'      => $token,
        'product'    => (int) get_post_meta( $id, '_q_product', true ),
        'specs'      => (string) get_post_meta( $id, '_q_specs', true ),
        'tiers'      => (array) get_post_meta( $id, '_q_tiers', true ),
        'lines'      => (array) get_post_meta( $id, '_q_lines', true ),
        'allow_date' => (bool) get_post_meta( $id, '_q_allow_date', true ),
        'min_days'   => (int) get_post_meta( $id, '_q_min_days', true ),
        'pay_source' => (string) get_post_meta( $id, '_q_pay_source', true ),
        'pay_link'   => (string) get_post_meta( $id, '_q_pay_link', true ),
        'order'      => (int) get_post_meta( $id, '_q_order', true ),
        'orders'     => pps_quote_order_history( get_post_meta( $id, '_q_orders', true ) ),
        'reusable'   => (bool) get_post_meta( $id, '_q_reusable', true ),
        'noship'     => (bool) get_post_meta( $id, '_q_noship', true ),
        'weight_lb'  => (float) get_post_meta( $id, '_q_weight_lb', true ),
        'revoked'    => (bool) get_post_meta( $id, '_q_revoked', true ),
        'note'       => (string) get_post_meta( $id, '_q_note', true ),
    );
}

/**
 * Enough of an address for the person who typed it to recognise, and useless
 * to anyone else.
 *
 * This page is reachable by anyone holding the quote token, and tokens are no
 * longer necessarily unguessable — a predictable one can be enumerated, which
 * would otherwise turn this line into a list of customer email addresses.
 * The real customer only needs to be reminded which address to use.
 */
function pps_quote_mask_email( $email ) {
    $email = (string) $email;
    $at = strpos( $email, '@' );
    if ( false === $at || $at < 1 ) return '';
    $user = substr( $email, 0, $at );
    $rest = substr( $email, $at );
    $keep = mb_substr( $user, 0, 1 );
    return $keep . str_repeat( '•', max( 3, min( 6, mb_strlen( $user ) - 1 ) ) ) . $rest;
}

function pps_quote_url( $token ) {
    $page = get_page_by_path( 'quote' );
    $base = $page ? get_permalink( $page ) : home_url( '/quote/' );
    return add_query_arg( 'q', rawurlencode( $token ), $base );
}

/**
 * Take a quote out of service.
 *
 * REVOKE, NOT DELETE. Deleting the post would orphan any order placed from it:
 * the order carries _pps_quote_token, and /reorders, the console and the paper
 * report all read it. A revoked quote that still resolves and says so is also
 * better for a customer holding an old URL than a 404 they cannot interpret.
 *
 * Refused while its order is UNPAID. That customer is mid-payment and the quote
 * page is how they get back to their pay link; revoking it would strand them
 * with an order they cannot complete and no way to say so.
 *
 * @return true|WP_Error
 */
function pps_quote_revoke( $token ) {
    $q = pps_quote_get( $token );
    if ( ! $q ) return new WP_Error( 'not_found', 'No link with that reference. Check the token in the URL you sent.' );
    if ( ! empty( $q['revoked'] ) ) return new WP_Error( 'already', 'That link was already revoked.' );

    if ( $q['order'] ) {
        $order = wc_get_order( $q['order'] );
        if ( $order && pps_job_invoice_is_unpaid( $order ) ) {
            return new WP_Error( 'unpaid', sprintf(
                'Order #%s was placed from this link and is not paid yet. Revoking it would leave that customer unable to finish paying. Cancel the order first if that is what you want.',
                $order->get_order_number()
            ) );
        }
    }

    update_post_meta( $q['id'], '_q_revoked', 1 );
    update_post_meta( $q['id'], '_q_revoked_at', current_time( 'mysql' ) );
    return true;
}

/**
 * Which posted block is the billing address and which is the destination.
 *
 * WHY THIS IS A FUNCTION OF ITS OWN
 *
 * Getting it backwards ships the job to the cardholder instead of the
 * recipient, which is the shape of the fault behind order 87032. It used to be
 * three closures inside pps_quote_to_order(), reachable only by building a
 * WooCommerce order, so the suite that covered it tested a RETYPED COPY -- a
 * copy that would have gone on passing while the real code said something else.
 *
 * The form version decides, and it travels with the markup rather than being
 * read off the quote, so a page already open in somebody's tab is interpreted
 * exactly as it was rendered:
 *
 *   v1  the primary block is BILLING;     s_ overrides the destination
 *   v2  the primary block is the DESTINATION; b_ overrides billing
 *   v3  the primary block is BILLING and nothing ships at all
 *
 * @return array{bill:array<string,string>,ship:array<string,string>,ships:bool}
 */
function pps_quote_resolve_addresses( array $p ) {
    $keys = array( 'first', 'last', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
    $v    = (string) ( $p['pps_form_v'] ?? '' );
    $v3   = ( '3' === $v );
    $v2   = ( '2' === $v );

    $get = function ( $k ) use ( $p ) {
        return isset( $p[ $k ] ) ? sanitize_text_field( $p[ $k ] ) : '';
    };
    // The alternate block: billing overrides in v2, the destination in v1.
    $alt_prefix = $v2 ? 'b_' : 's_';
    $alt_same   = $v2 ? ! empty( $p['bill_same'] ) : ! empty( $p['ship_same'] );

    $bill = array();
    $ship = array();
    foreach ( $keys as $k ) {
        $primary = $get( $k );
        $alt     = $alt_same ? $primary : $get( $alt_prefix . $k );
        if ( $v3 ) {
            // Nothing ships, and the destination is left genuinely EMPTY rather
            // than copied from billing: a shipping address that is really the
            // cardholder's is how something gets posted to the wrong place.
            $bill[ $k ] = $primary;
            $ship[ $k ] = '';
        } elseif ( $v2 ) {
            $bill[ $k ] = $alt;
            $ship[ $k ] = $primary;
        } else {
            $bill[ $k ] = $primary;
            $ship[ $k ] = $alt;
        }
    }
    return array( 'bill' => $bill, 'ship' => $ship, 'ships' => ! $v3 );
}

/**
 * What a visit to an already-used quote should do.
 *
 *   resume  -- go back to the order already placed
 *   settled -- show what they bought; the link is spent
 *   form    -- start a new order
 *
 * THE LOAD-BEARING RULE IS THE MIDDLE ONE. An unfinished payment always returns
 * to its own order, reusable or not: a customer who closed the tab mid-payment,
 * or opened the link twice, must land back on the order they already placed.
 * Without that a reusable link produces two orders against one payment, and the
 * second looks as legitimate as the first.
 *
 * It also means a reusable link cannot be double-ordered by accident, because a
 * new order can only begin once the previous one is actually settled.
 *
 * A pure function because this is the decision that governs whether money and
 * orders line up, and it should be checkable without a WooCommerce order, a
 * session or a page render.
 */
function pps_quote_visit_action( $has_order, $is_unpaid, $reusable, $is_dead = false ) {
    if ( ! $has_order ) return 'form';
    // A cancelled or refunded order is not a live order, so the link is free
    // again whether or not it is reusable. Without this the page told the
    // customer "This order is paid. Thank you" about an order that had been
    // cancelled -- 'cancelled' is not in the unpaid list, so it read as settled.
    // True before *cancel existed, for anything cancelled in wp-admin.
    if ( $is_dead )     return 'form';
    if ( $is_unpaid )   return 'resume';
    return $reusable ? 'form' : 'settled';
}

/** Statuses that mean there is no live order behind this quote any more. */
function pps_quote_order_is_dead( $order ) {
    return $order && in_array( $order->get_status(), array( 'cancelled', 'refunded', 'trash' ), true );
}

/* ─────────────────────────────────────────────────────────────
 * International shipping — a live UPS rate for a foreign destination
 *
 * A pay link quotes one figure with delivery inside it, which is true for the
 * contiguous US and false everywhere else. So a foreign destination is rated
 * live and the carrier's price is added on top, at cost, as its own line the
 * customer can see before they pay.
 *
 * Two rules govern everything below:
 *
 *   1. The amount is derived SERVER-SIDE from the quote's own stored weight.
 *      The browser is told what it will be charged; it never says.
 *   2. A rate that cannot be obtained refuses the order. It never falls back to
 *      zero -- quoting free international freight is the one outcome worse than
 *      asking the customer to wait for a human.
 * ───────────────────────────────────────────────────────────── */

/**
 * How long a rated international price may be reused.
 *
 * Deliberately far shorter than the 30 days the calculators cache domestic
 * transit for, because that figure is a DURATION and this one is a PRICE. UPS
 * ground transit between two US ZIPs does not move; an international rate
 * carries fuel surcharges that do. Drift between quote and label is absorbed by
 * the shop -- the rate is passed through at cost, no margin, by owner rule --
 * so this number is the size of that exposure, and three days is about a day of
 * surcharge movement.
 *
 * Seconds written out rather than DAY_IN_SECONDS so this file can be loaded by
 * a test harness without WordPress's constants.
 */
function pps_quote_intl_ttl() { return 3 * 86400; }

/**
 * Is this the domestic lane the quoted price already covers?
 *
 * An EMPTY country reads as domestic on purpose. Until this feature existed the
 * form posted no country at all and the order was stamped 'US' unconditionally,
 * so a page still open in a tab from before the change must keep meaning what
 * it meant when it was rendered -- the same reasoning as pps_form_v.
 */
function pps_quote_is_domestic( $country ) {
    $c = strtoupper( trim( (string) $country ) );
    return '' === $c || 'US' === $c;
}

/** A two-letter ISO country code, or '' if what was posted is not one. */
function pps_quote_country_code( $raw ) {
    $c = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $raw ) );
    return 2 === strlen( $c ) ? $c : '';
}

/**
 * Countries where a region is required before we will try to rate.
 *
 * Not a style preference: UPS cannot price a Canadian lane without the
 * province, and Canada is overwhelmingly the most likely foreign destination
 * for a US print shop. Everywhere else the field is optional, because a
 * required region is a checkout nobody in Singapore can complete, and an
 * address Shippo cannot rate already fails safe into "contact us".
 *
 * The same reasoning governs the postal code, which is required for the US and
 * optional elsewhere rather than checked against a list of countries that have
 * one. Getting such a list wrong in the strict direction locks a real customer
 * out; getting it wrong in the lax direction just means no rate comes back.
 */
function pps_quote_requires_region( $country ) {
    return in_array( strtoupper( (string) $country ), array( 'US', 'CA' ), true );
}

/**
 * The cartons a weight ships in.
 *
 * A VERBATIM PORT of the parcel math in the transit-estimate endpoint
 * (pps-calculators.php): 45 lb cartons at 19x13x6, owner rule 2026-07-19, where
 * a carton is 900 text-weight or 400 cardstock 13x19 sheets. If that rule ever
 * changes, change it here in the same commit -- two carton sizes one grep apart
 * would quote one shipment and ship another.
 */
function pps_quote_intl_parcels( $weight_lb ) {
    $weight_lb = (float) $weight_lb;
    $carton_lb = 45.0;
    $n         = min( 20, max( 1, (int) ceil( $weight_lb / $carton_lb ) ) );
    $parcels   = array();
    $rem       = $weight_lb;
    for ( $i = 0; $i < $n; $i++ ) {
        $w   = min( $carton_lb, $rem );
        $rem = $rem - $w;
        $parcels[] = array(
            'length' => '19', 'width' => '13', 'height' => '6', 'distance_unit' => 'in',
            'weight' => (string) max( 1, round( $w, 1 ) ), 'mass_unit' => 'lb',
        );
    }
    return $parcels;
}

/**
 * The rate we will charge, chosen from everything Shippo returned.
 *
 * UPS only, and never Ground Saver or SurePost (owner rule 2026-07-19: those
 * hand the final mile to USPS and are not how we ship). Then the CHEAPEST
 * remaining service, with the price AND the transit days taken from the same
 * rate object -- the fallback in the domestic endpoint picks the slowest
 * service, which is harmless while the rate is only a duration and a
 * correctness bug the moment it is also the price. Quoting one service's speed
 * against another's cost promises a delivery that was not bought.
 *
 * A non-USD rate is skipped rather than converted. The store charges USD; an
 * amount in CAD added to a USD order is simply a wrong number, and a silent
 * one.
 */
function pps_quote_pick_intl_rate( $rates ) {
    $best = null;
    foreach ( (array) $rates as $rate ) {
        if ( ! is_array( $rate ) ) continue;
        if ( 'ups' !== strtolower( (string) ( $rate['provider'] ?? '' ) ) ) continue;
        $tok  = strtolower( (string) ( $rate['servicelevel']['token'] ?? '' ) );
        $name = strtolower( (string) ( $rate['servicelevel']['name'] ?? '' ) );
        if ( false !== strpos( $tok, 'ground_saver' ) || false !== strpos( $name, 'ground saver' ) ) continue;
        if ( false !== strpos( $tok, 'surepost' ) ) continue;
        $cur = strtoupper( (string) ( $rate['currency'] ?? 'USD' ) );
        if ( 'USD' !== $cur ) continue;
        // A zero or missing amount is a response we failed to read, not a free
        // shipment. Charging nothing because a field moved is how the shop ends
        // up paying international freight out of margin.
        $amount = isset( $rate['amount'] ) ? (float) $rate['amount'] : 0;
        if ( $amount <= 0 ) continue;
        if ( null !== $best && $amount >= $best['amount'] ) continue;
        $best = array(
            'amount'       => round( $amount, 2 ),
            'currency'     => 'USD',
            'carrier'      => (string) ( $rate['provider'] ?? 'UPS' ),
            'service'      => (string) ( $rate['servicelevel']['name'] ?? '' ),
            'transit_days' => isset( $rate['estimated_days'] ) ? (int) $rate['estimated_days'] : 0,
        );
    }
    return $best;
}

/**
 * The customs declaration a UPS international rate needs.
 *
 * FOUND THE HARD WAY, 2026-11-04, against the live Shippo account. Rating
 * Phoenix -> Toronto with a parcel and nothing else returned ELEVEN rates and
 * not one of them UPS. UPS answered only:
 *
 *   111549  Hard: Invalid Shipment Contents Value.
 *   111210  Hard: The requested service is unavailable between the selected locations.
 *   (and ShippoCommon: Attribute "customs_declaration" must not be empty.)
 *
 * FedEx, USPS and DHL quoted happily, which is what made it dangerous: the
 * response looked healthy, and this code filters to UPS, so every foreign
 * address would have been refused as unservable. The feature would have read
 * as "no carrier serves Canada" and nobody could have traced it.
 *
 * The same lane WITH this declaration returns four UPS services, cheapest
 * Standard at $53.08 / 4 days. So the owner's UPS-only rule is sound
 * internationally; the request was simply incomplete.
 *
 * THIS IS FOR RATING ONLY. The real customs paperwork is filled in by a human
 * when the label is bought in Shippo, because international labels need forms
 * the ship panel does not do. So the commodity is described generically and
 * accurately rather than by pasting the operator's spec into a declaration the
 * shop is not actually filing -- FedEx rejected a spec-derived description as
 * inconsistent with its HS code, and a customs form is not the place to
 * discover that.
 *
 * @param float  $weight_lb Parcel weight, which is also the declared net weight.
 * @param float  $value     What the goods are worth: the quoted print price.
 *                          Declared in full. Under-declaring to spare the
 *                          recipient duty is fraud, and the page already tells
 *                          them duties are theirs to pay.
 * @param string $country   Destination, which picks the export-exemption code.
 */
function pps_quote_intl_customs( $weight_lb, $value, $country ) {
    $country = strtoupper( (string) $country );
    $signer  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
    if ( '' === trim( $signer ) ) $signer = 'Priority Print Service';
    return array(
        'certify'            => true,
        'certify_signer'     => $signer,
        'contents_type'      => 'MERCHANDISE',
        // RETURN, not ABANDON: an undeliverable job comes back to us rather
        // than being destroyed at the border.
        'non_delivery_option' => 'RETURN',
        // DDU -- the recipient pays import duties and taxes, which is exactly
        // what the payment page tells them. DDP would bill the shop for duties
        // nobody quoted.
        'incoterm'           => 'DDU',
        // Shippo's own guidance: NOEEI_30_36 for most US->CA shipments,
        // NOEEI_30_37_a for most other US exports.
        'eel_pfc'            => 'CA' === $country ? 'NOEEI_30_36' : 'NOEEI_30_37_a',
        'items'              => array( array(
            'description'    => 'Printed material',
            'quantity'       => 1,
            'net_weight'     => (string) round( (float) $weight_lb, 2 ),
            'mass_unit'      => 'lb',
            'value_amount'   => (string) round( (float) $value, 2 ),
            'value_currency' => 'USD',
            'origin_country' => 'US',
            // Printed books, brochures, leaflets and similar printed matter.
            'hs_code'        => '4901.99',
        ) ),
    );
}

/** The Shippo token, or '' when the integration is not configured. */
function pps_quote_shippo_token() {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $cfg = pps_get_config();
    return (string) ( $cfg['pcf']['shippo_api_token'] ?? '' );
}

/**
 * The origin we export from.
 *
 * The domestic transit lookup sends zip + country and nothing else, which
 * Shippo is happy with. An international rate is a different question -- it is
 * an export, and the carrier wants to know where from -- so the WooCommerce
 * store address is sent with it when the shop has one.
 *
 * The configured Shippo origin ZIP still wins over the store's postcode: that
 * setting exists precisely because the place goods ship from need not be the
 * address on the invoice. Everything else is a best-effort addition, and an
 * empty store address leaves this exactly as thin as the domestic call.
 */
function pps_quote_intl_origin( $zip ) {
    $out = array( 'zip' => (string) $zip, 'country' => 'US' );
    if ( ! function_exists( 'get_option' ) ) return $out;
    $street = trim( (string) get_option( 'woocommerce_store_address', '' ) );
    $city   = trim( (string) get_option( 'woocommerce_store_city', '' ) );
    $raw    = trim( (string) get_option( 'woocommerce_default_country', '' ) );
    $name   = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
    // WooCommerce stores this as "US:AZ" -- country and state in one option.
    $state = '';
    if ( false !== strpos( $raw, ':' ) ) {
        list( , $state ) = explode( ':', $raw, 2 );
    }
    if ( '' !== $street ) $out['street1'] = $street;
    if ( '' !== $city )   $out['city']    = $city;
    if ( '' !== $state )  $out['state']   = $state;
    if ( '' !== $name )   $out['name']    = $name;
    return $out;
}

/**
 * A live UPS rate for one foreign destination, cached.
 *
 * Keyed on the destination AND the weight, because the weight is half the
 * price. The address is hashed into the key: a postcode may be any length and
 * carry spaces and letters, and an option name has a length limit.
 *
 * @return array|WP_Error
 */
function pps_quote_intl_rate( $country, $postcode, $state, $city, $weight_lb, $street = '', $value = 0, $name = '' ) {
    $country   = pps_quote_country_code( $country );
    $weight_lb = (float) $weight_lb;
    if ( '' === $country || pps_quote_is_domestic( $country ) ) {
        return new WP_Error( 'intl_domestic', 'That is not a foreign destination.' );
    }
    if ( $weight_lb <= 0 || $weight_lb > 2000 ) {
        return new WP_Error( 'intl_weight', 'This quote has no shipping weight on it.' );
    }
    $token = pps_quote_shippo_token();
    if ( '' === $token ) {
        return new WP_Error( 'intl_unconfigured', 'Shipping rates are not available right now.' );
    }

    $postcode = sanitize_text_field( (string) $postcode );
    $state    = sanitize_text_field( (string) $state );
    $city     = sanitize_text_field( (string) $city );
    $street   = sanitize_text_field( (string) $street );
    $name     = sanitize_text_field( (string) $name );
    $value    = (float) $value;

    // The street is in the key as well as the request. Rates are mostly
    // postcode-driven, but a residential surcharge is address-driven, so two
    // addresses in one postcode can differ -- and a key that ignored the
    // street would serve one of them the other's price. v2 because adding the
    // street and the customs declaration changes what the cached figure means,
    // and a v1 entry must not be read as if it were this.
    $key = 'pps_qintl_v2_' . $country . '_'
         . substr( md5( strtolower( $postcode . '|' . $state . '|' . $city . '|' . $street ) ), 0, 12 )
         . '_' . (string) ceil( $weight_lb );
    $hit = get_transient( $key );
    if ( is_array( $hit ) ) {
        $hit['cached'] = true;
        return $hit;
    }

    // A cache miss costs a Shippo call, so it is rate-limited per IP. The page
    // asks on every change of country or postal code, and a page left open must
    // not be able to burn the quota.
    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
    $rlk = 'pps_qintl_rl_' . md5( $ip );
    $n   = (int) get_transient( $rlk );
    if ( $n >= 15 ) {
        return new WP_Error( 'intl_rate_limited', 'Too many shipping lookups — please wait a moment and try again.' );
    }
    set_transient( $rlk, $n + 1, 60 );

    $cfg    = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $origin = (string) ( $cfg['pcf']['shippo_origin_zip'] ?? '85027' );

    $resp = wp_remote_post( 'https://api.goshippo.com/shipments/', array(
        'headers' => array(
            'Authorization' => 'ShippoToken ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( array(
            'address_from' => pps_quote_intl_origin( $origin ),
            // City and street are sent, unlike the domestic transit lookup:
            // outside the US a postal code alone often does not identify a
            // lane, and some served countries have no postal code at all.
            'address_to'   => array_filter( array(
                // The recipient's name is collected by the form anyway, and
                // every carrier wants it on an international consignment.
                'name'    => $name,
                'street1' => $street,
                'zip'     => $postcode,
                'state'   => $state,
                'city'    => $city,
                'country' => $country,
            ), function ( $v ) { return '' !== $v; } ),
            'parcels'      => pps_quote_intl_parcels( $weight_lb ),
            // Without this UPS returns NO RATES AT ALL on an international
            // lane -- see pps_quote_intl_customs() for what that cost to find.
            'customs_declaration' => pps_quote_intl_customs( $weight_lb, $value, $country ),
            'async'        => false,
        ) ),
        'timeout' => 20,
    ) );
    if ( is_wp_error( $resp ) ) {
        return new WP_Error( 'intl_unreachable', 'We could not reach the carrier for a shipping price. Please try again in a moment.' );
    }
    $body  = json_decode( wp_remote_retrieve_body( $resp ), true );
    $rates = is_array( $body ) && isset( $body['rates'] ) ? $body['rates'] : array();
    $pick  = pps_quote_pick_intl_rate( $rates );
    if ( ! $pick ) {
        // Distinguished from a network failure because it means something
        // different to the customer: this address cannot be served, rather than
        // we could not ask. The spec's "foreign" class, arrived at by asking UPS
        // instead of maintaining a list of countries.
        return new WP_Error( 'intl_unservable',
            'We could not get a shipping price for that address. Check the postal code, or contact us and we will quote it by hand.' );
    }
    $pick['cached'] = false;
    set_transient( $key, $pick, pps_quote_intl_ttl() );
    return $pick;
}

/**
 * What shipping to charge for this submission, if any.
 *
 * The single place that decides, used by both the live lookup the page makes
 * and the order it eventually posts, so the figure shown and the figure charged
 * cannot come from different reasoning.
 *
 * @return array|WP_Error|null  null when nothing is owed: a domestic lane, or a
 *                              *virtual link with no destination at all.
 */
function pps_quote_ship_charge( array $q, array $p ) {
    if ( ! empty( $q['noship'] ) ) return null;
    $addr = pps_quote_resolve_addresses( $p );
    if ( empty( $addr['ships'] ) ) return null;

    $ship    = $addr['ship'];
    $country = pps_quote_country_code( $ship['country'] );
    if ( pps_quote_is_domestic( $country ) ) return null;

    $weight = isset( $q['weight_lb'] ) ? (float) $q['weight_lb'] : 0;
    if ( $weight <= 0 ) {
        // The honest refusal. The operator did not give a weight, so there is
        // nothing to rate, and inventing one would put a real charge on a real
        // card for a number nobody computed.
        return new WP_Error( 'intl_no_weight',
            'This quote cannot be shipped outside the US as it stands. Reply to us and we will send you an updated link with shipping included.' );
    }
    // The declared customs value is what the customer is paying for the
    // printing -- the goods' actual worth. UPS will not price an international
    // lane without it.
    $lines = pps_quote_normalise_lines( isset( $q['lines'] ) ? $q['lines'] : array() );
    if ( $lines ) {
        $value = pps_quote_lines_total( $lines );
    } else {
        $tiers = pps_quote_normalise_tiers( isset( $q['tiers'] ) ? $q['tiers'] : array() );
        $idx   = isset( $p['tier'] ) ? absint( $p['tier'] ) : 0;
        if ( ! isset( $tiers[ $idx ] ) ) $idx = 0;
        $value = isset( $tiers[ $idx ]['price'] ) ? (float) $tiers[ $idx ]['price'] : 0;
    }

    // The name is NOT part of the cache key: it cannot change a rate, and
    // keying on it would miss the cache for every new customer at an address
    // already priced.
    return pps_quote_intl_rate(
        $country, $ship['postcode'], $ship['state'], $ship['city'], $weight,
        $ship['address_1'], $value, trim( $ship['first'] . ' ' . $ship['last'] )
    );
}

/* ─────────────────────────────────────────────────────────────
 * Quote -> order
 * ───────────────────────────────────────────────────────────── */

function pps_quote_to_order( array $q, array $p ) {
    // A multi-line quote has no quantity choice -- the operator listed the jobs
    // and their prices, and the customer is paying for all of them. A one-line
    // quote keeps its tier dropdown exactly as before.
    $lines = pps_quote_normalise_lines( isset( $q['lines'] ) ? $q['lines'] : array() );

    $tiers = pps_quote_normalise_tiers( $q['tiers'] );
    if ( ! $tiers && ! $lines ) return new WP_Error( 'tiers', 'This quote has no quantities on it. Please contact us.' );

    if ( $lines ) {
        // Synthesised so everything downstream -- the order's _pps_qty_tiers,
        // the reorder card, the total check -- sees one coherent figure for the
        // whole job rather than the first line's price.
        $tier = array( 'qty' => 1, 'price' => pps_quote_lines_total( $lines ) );
    } else {
        $idx  = isset( $p['tier'] ) ? absint( $p['tier'] ) : 0;
        if ( ! isset( $tiers[ $idx ] ) ) $idx = 0;
        $tier = $tiers[ $idx ];
    }

    $email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
    if ( ! $email || ! is_email( $email ) ) return new WP_Error( 'email', 'Please enter a valid email address.' );
    // Phone is collected FOR THE CARRIER -- the label says so. Nothing ships on
    // a *virtual order, so requiring it would be asking for something no one
    // will use. Everything else still applies: the billing address is wanted
    // for the card and for the invoice.
    //
    // Which fields can be insisted on depends on the country. A required state
    // locks out every customer in a country that has none, and a required
    // 5-digit ZIP locks out everybody outside the US. Both are therefore
    // relaxed abroad rather than checked against a list of which countries have
    // what: getting such a list wrong in the strict direction stops a real
    // customer paying, while getting it wrong the lax way only means no rate
    // comes back, which already fails safe into "contact us".
    //
    // This is the PRIMARY block's country, which is the billing address in v1
    // and v3 and the destination in v2 -- the same block these field names
    // refer to in every version.
    $form_country = pps_quote_country_code( isset( $p['country'] ) ? $p['country'] : '' );
    if ( '' === $form_country ) $form_country = 'US';
    $need = array( 'first' => 'first name', 'last' => 'last name', 'phone' => 'phone number', 'address_1' => 'street address', 'city' => 'city', 'state' => 'state', 'postcode' => 'ZIP code' );
    if ( ! empty( $q['noship'] ) ) unset( $need['phone'] );
    if ( ! pps_quote_requires_region( $form_country ) ) {
        unset( $need['state'] );
    } elseif ( 'US' !== $form_country ) {
        $need['state'] = 'province';
    }
    if ( 'US' !== $form_country ) {
        unset( $need['postcode'] );
    }
    foreach ( $need as $k => $label ) {
        if ( empty( $p[ $k ] ) ) return new WP_Error( $k, 'Please enter your ' . $label . '.' );
    }

    // A chosen date must respect the production minimum, whatever the form
    // said — the min attribute on a date input is a hint, not a constraint.
    $date = '';
    if ( ! empty( $q['allow_date'] ) && ! empty( $p['date'] ) ) {
        $d = sanitize_text_field( $p['date'] );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
            $earliest = pps_quote_earliest_date( $q['min_days'] );
            if ( strcmp( $d, $earliest ) < 0 ) {
                return new WP_Error( 'date', 'The earliest we can deliver this job is ' . $earliest . '.' );
            }
            // Weekends and shop closures are not deliverable. Checked here and
            // not only in the browser: the date input's min attribute is a hint,
            // the JS guard is a courtesy, and a posted date is user input like
            // any other. Accepting a Saturday would put a promise on the order
            // that production cannot keep.
            if ( function_exists( 'pps_is_business_day' )
                 && ! pps_is_business_day( new DateTime( $d ) ) ) {
                return new WP_Error( 'date', 'We are closed that day — please choose a weekday we are open.' );
            }
            $date = $d;
        }
    }

    $product = wc_get_product( $q['product'] );
    if ( ! $product || ! $product->exists() ) return new WP_Error( 'product', 'That product is no longer available. Please contact us.' );

    // International shipping, rated live and charged on top of the quoted
    // price, which covers printing and domestic delivery only.
    //
    // Rated HERE, before wc_create_order(), because a refusal after the order
    // exists leaves an orphan pending order behind every failed attempt -- and
    // this path refuses rather than guessing: no rate means no order, never a
    // free international shipment.
    //
    // The amount comes from the quote's own stored weight and the posted
    // address. Nothing the browser sends decides what is charged; the lookup
    // the page makes while the customer types is the same function, so the
    // figure shown and the figure charged come from one place.
    $ship_charge = pps_quote_ship_charge( $q, $p );
    if ( is_wp_error( $ship_charge ) ) return $ship_charge;
    // Checked here rather than at the point of use, where a missing class would
    // quietly drop the charge and let the customer pay the domestic price --
    // the one outcome everything else in this path refuses.
    if ( is_array( $ship_charge ) && $ship_charge['amount'] > 0 && ! class_exists( 'WC_Order_Item_Shipping' ) ) {
        return new WP_Error( 'intl_unavailable',
            'We cannot take an order to that country right now. Please contact us and we will complete it by hand.' );
    }

    $order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
    if ( is_wp_error( $order ) ) return new WP_Error( 'create', 'Could not start your order. Please contact us.' );

    $order->set_created_via( 'pps-job-quote' );

    $addr = pps_quote_resolve_addresses( $p );
    $v3   = ! $addr['ships'];

    // Contact details are contact, not address — they belong to the person
    // buying whichever way round the form asked.
    $order->set_billing_email( $email );
    $order->set_billing_phone( isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '' );

    $b = $addr['bill'];
    $order->set_billing_first_name( $b['first'] );
    $order->set_billing_last_name( $b['last'] );
    $order->set_billing_company( $b['company'] );
    $order->set_billing_address_1( $b['address_1'] );
    $order->set_billing_address_2( $b['address_2'] );
    $order->set_billing_city( $b['city'] );
    $order->set_billing_state( $b['state'] );
    $order->set_billing_postcode( $b['postcode'] );
    // Defaults to US when the form posted no country, which is what a page
    // rendered before this feature existed does -- and what the hardcoded 'US'
    // here used to mean for every order.
    $order->set_billing_country( pps_quote_country_code( $b['country'] ) ?: 'US' );

    // No shipping address on a *virtual order. Left genuinely EMPTY rather than
    // copied from billing: a shipping address that is really the cardholder's
    // is how something gets posted to the wrong place, and an addendum has
    // nowhere to go by definition.
    if ( $v3 ) {
        $order->set_shipping_country( '' );
    } else {
        $h = $addr['ship'];
        $order->set_shipping_first_name( $h['first'] );
        $order->set_shipping_last_name( $h['last'] );
        $order->set_shipping_company( $h['company'] );
        $order->set_shipping_address_1( $h['address_1'] );
        $order->set_shipping_address_2( $h['address_2'] );
        $order->set_shipping_city( $h['city'] );
        $order->set_shipping_state( $h['state'] );
        $order->set_shipping_postcode( $h['postcode'] );
        // Carriers read the shipping phone, not the billing one, and a delivery
        // exception is resolved by whoever answers at the destination. Same number
        // on both: it is collected once, in the shipping block, for that purpose.
        if ( is_callable( array( $order, 'set_shipping_phone' ) ) ) {
            $order->set_shipping_phone( isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '' );
        }
        $order->set_shipping_country( pps_quote_country_code( $h['country'] ) ?: 'US' );
    }

    // One WooCommerce line per quote line. The spec, project name and delivery
    // date hang off the FIRST line only: they describe the order, and repeating
    // them on every row would clutter the invoice and the packing list.
    $add = $lines ? $lines : array( array(
        'description' => '',
        'qty'         => $tier['qty'],
        'price'       => $tier['price'],
    ) );

    $first_item_id = 0;
    foreach ( $add as $i => $ln ) {
        $item_id = $order->add_product( $product, $ln['qty'], array(
            'subtotal' => $ln['price'],
            'total'    => $ln['price'],
        ) );
        if ( ! $item_id ) continue;
        if ( ! $first_item_id ) $first_item_id = $item_id;
        $it = $order->get_item( $item_id );
        if ( ! $it ) continue;
        // Each line carries its OWN description, so wp-admin, the receipt and
        // the QuickBooks invoice line all name the job on that row.
        if ( '' !== (string) $ln['description'] ) {
            $it->add_meta_data( 'Item', $ln['description'], true );
        }
        $it->save();
    }

    $item_id = $first_item_id;
    if ( $item_id ) {
        $item = $order->get_item( $item_id );
        if ( $item ) {
            // Non-underscore, so WooCommerce renders it wherever line meta is
            // shown. Capped because a runaway paste would otherwise widen every
            // order table it appears in.
            $project = isset( $p['project'] ) ? sanitize_text_field( $p['project'] ) : '';
            if ( '' !== $project ) {
                $item->add_meta_data( 'Project', mb_substr( $project, 0, 120 ), true );
            }
            if ( $q['specs'] ) $item->add_meta_data( 'Specs', $q['specs'], true );
            if ( $date ) $item->add_meta_data( 'Requested delivery', $date, true );
            $item->save();
        }
    }

    // A real WooCommerce shipping line, so the total, the receipt, the
    // QuickBooks invoice and wp-admin all agree without any of them being
    // taught about this feature. pps-quickbooks.php already invoices
    // get_shipping_total() as its own row, which is also what keeps its
    // total-mismatch guard quiet.
    if ( is_array( $ship_charge ) && $ship_charge['amount'] > 0 ) {
        $ship_item = new WC_Order_Item_Shipping();
        $ship_item->set_method_id( 'pps_intl' );
        $ship_item->set_method_title( trim( $ship_charge['carrier'] . ' ' . $ship_charge['service'] ) ?: 'International shipping' );
        $ship_item->set_total( (string) $ship_charge['amount'] );
        $order->add_item( $ship_item );
        // The whole rate, kept for the person who has to buy the label: the
        // service quoted is the service the customer paid for, and the label
        // module cannot buy international labels itself.
        $order->update_meta_data( '_pps_intl_ship', $ship_charge );
    }

    // The alternatives travel with the order so a reorder can offer them.
    $order->update_meta_data( '_pps_qty_tiers', $tiers );
    $order->update_meta_data( '_pps_quote_token', $q['token'] );
    if ( ! empty( $p['project'] ) ) {
        $order->update_meta_data( '_pps_project_name', mb_substr( sanitize_text_field( $p['project'] ), 0, 120 ) );
    }
    if ( $date ) $order->update_meta_data( '_pps_requested_date', $date );
    $order->update_meta_data( '_pps_pay_source', $q['pay_source'] ?: 'site' );
    if ( ! empty( $q['pay_link'] ) ) $order->update_meta_data( '_pps_pay_link', $q['pay_link'] );
    if ( 'quickbooks' === $q['pay_source'] ) {
        $order->set_payment_method( 'pps_quickbooks' );
        $order->set_payment_method_title( 'QuickBooks payment link' );
    } elseif ( 'qbo_api' === $q['pay_source'] ) {
        $order->set_payment_method( 'pps_qbo' );
        $order->set_payment_method_title( 'QuickBooks' );
    }

    $order->calculate_totals( false );
    $order->set_status( 'pending' );
    $order->save();

    $order->add_order_note( sprintf(
        'Placed from quote %s by the customer. %s%s',
        $q['token'],
        in_array( $q['pay_source'], array( 'quickbooks', 'qbo_api' ), true )
            ? 'Awaiting QuickBooks payment.' : 'Awaiting card payment.',
        $date ? ' Requested delivery ' . $date . '.' : ''
    ) );

    if ( is_array( $ship_charge ) && $ship_charge['amount'] > 0 ) {
        $order->add_order_note( sprintf(
            'International destination (%s). %s quoted %s at cost and charged on top of the quoted price%s.'
            . ' The customer was told import duties and taxes are not included.'
            . ' This label cannot be bought from the ship panel — international labels need customs forms, so buy it in Shippo.',
            $order->get_shipping_country() ?: '??',
            trim( $ship_charge['carrier'] . ' ' . $ship_charge['service'] ),
            wc_price( $ship_charge['amount'] ),
            $ship_charge['transit_days'] > 0 ? ', ' . (int) $ship_charge['transit_days'] . ' days in transit' : ''
        ) );
    }

    // _q_order is the MOST RECENT order, which is what the return-visit check
    // needs. A reusable link can produce many, so the full list is kept too --
    // otherwise the second order would erase any trace of the first.
    update_post_meta( $q['id'], '_q_order', $order->get_id() );
    $history   = pps_quote_order_history( get_post_meta( $q['id'], '_q_orders', true ) );
    $history[] = $order->get_id();
    update_post_meta( $q['id'], '_q_orders', pps_quote_order_history( $history ) );
    return $order;
}

/* ─────────────────────────────────────────────────────────────
 * The page's shipping lookup
 * ───────────────────────────────────────────────────────────── */

/**
 * POST /pps/v1/quote/ship-quote — what will this cost to ship there?
 *
 * Public, because the people who use it are guests holding a quote link, and
 * scoped to one token so the weight comes from the quote rather than from
 * whoever is asking. The answer is advisory: pps_quote_to_order() rates the
 * submission again from the same function, so a tampered request only lies to
 * the person making it.
 *
 * Answers 200 with ok:false for a refusal rather than 4xx. The page shows the
 * message to a customer mid-payment, and a 4xx here reads as "the lookup
 * broke" to anything watching -- the same reasoning that made the Missive
 * refusals 200.
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'pps/v1', '/quote/ship-quote', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function ( $request ) {
            $b     = (array) $request->get_json_params();
            $token = isset( $b['token'] ) ? sanitize_title( (string) $b['token'] ) : '';
            $q     = $token ? pps_quote_get( $token ) : null;
            if ( ! $q ) {
                return new WP_REST_Response( array( 'ok' => false, 'error' => 'no_quote' ), 200 );
            }
            if ( ! empty( $q['revoked'] ) ) {
                return new WP_REST_Response( array( 'ok' => false, 'error' => 'revoked' ), 200 );
            }

            // Read through the real resolver, with the form version the page
            // actually rendered, so this lookup classifies the destination by
            // exactly the rule the order will use. Reading $b['country']
            // directly would be right for v2 and wrong for v1.
            $post = array(
                'pps_form_v' => isset( $b['form_v'] ) ? (string) $b['form_v'] : '2',
                'country'    => isset( $b['country'] ) ? (string) $b['country'] : '',
                'postcode'   => isset( $b['postcode'] ) ? (string) $b['postcode'] : '',
                'state'      => isset( $b['state'] ) ? (string) $b['state'] : '',
                'city'       => isset( $b['city'] ) ? (string) $b['city'] : '',
                // The street reaches the carrier (a residential surcharge is
                // address-driven) and the tier decides the declared customs
                // value, which UPS will not rate an international lane without.
                'address_1'  => isset( $b['street'] ) ? (string) $b['street'] : '',
                'tier'       => isset( $b['tier'] ) ? $b['tier'] : 0,
            );
            $charge = pps_quote_ship_charge( $q, $post );

            if ( null === $charge ) {
                // Domestic, or a link that never ships. Delivery is inside the
                // quoted price, which is what the page says by default.
                return new WP_REST_Response( array( 'ok' => true, 'domestic' => true, 'amount' => 0 ), 200 );
            }
            if ( is_wp_error( $charge ) ) {
                return new WP_REST_Response( array(
                    'ok'      => false,
                    'error'   => $charge->get_error_code(),
                    'message' => $charge->get_error_message(),
                ), 200 );
            }
            return new WP_REST_Response( array(
                'ok'           => true,
                'domestic'     => false,
                'amount'       => $charge['amount'],
                'currency'     => $charge['currency'],
                'carrier'      => $charge['carrier'],
                'service'      => $charge['service'],
                'transit_days' => $charge['transit_days'],
            ), 200 );
        },
    ) );
} );

/* ─────────────────────────────────────────────────────────────
 * Public quote page — [pps_job_quote]
 * ───────────────────────────────────────────────────────────── */

add_shortcode( 'pps_job_quote', 'pps_job_quote_shortcode' );

function pps_job_quote_shortcode() {
    nocache_headers();
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    add_filter( 'wp_robots', 'wp_robots_no_robots' );

    $token = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $q = $token ? pps_quote_get( $token ) : null;
    if ( ! $q ) {
        return '<div class="pps-acct"><div class="lookup-shell"><div class="empty"><span>This quote link is not valid. Please check the link or contact us.</span></div></div></div>';
    }

    // Revoked: checked BEFORE the order branches, and before the POST handler
    // below, so a revoked link can neither be ordered from nor be submitted by
    // a form somebody already had open. A paid order placed from it is still
    // reachable from /reorders and the receipt; this page is closed.
    if ( ! empty( $q['revoked'] ) ) {
        return '<div class="pps-acct"><div class="lookup-shell"><div class="empty">'
            . '<span>This quote is no longer available. Please contact us for a current price.</span>'
            . '</div></div></div>';
    }

    if ( $q['order'] ) {
        $existing = wc_get_order( $q['order'] );
        if ( $existing ) {
            $act = pps_quote_visit_action(
                true,
                pps_job_invoice_is_unpaid( $existing ),
                $q['reusable'],
                pps_quote_order_is_dead( $existing )
            );
            if ( 'resume'   === $act ) return pps_quote_placed_view( $existing, false );
            if ( 'settled'  === $act ) return pps_quote_placed_view( $existing, true );
            // 'form' -- paid and reusable, so fall through to a fresh order.
        }
    }

    $error = '';
    if ( isset( $_POST['pps_quote_submit'] ) && check_admin_referer( 'pps_quote_' . $q['token'], 'pps_quote_nonce' ) ) {
        $res = pps_quote_to_order( $q, wp_unslash( $_POST ) );
        if ( is_wp_error( $res ) ) $error = $res->get_error_message();
        else return pps_quote_placed_view( $res, false );
    }

    return pps_quote_form_view( $q, $error );
}

function pps_quote_placed_view( $order, $settled ) {
    $link = function_exists( 'pps_job_invoice_pay_link' ) ? pps_job_invoice_pay_link( $order ) : '';
    ob_start(); ?>
    <div class="pps-acct"><div class="lookup-shell">
        <h2 class="lookup-title">Order #<?php echo esc_html( $order->get_order_number() ); ?></h2>
        <?php // Echo their own name for the job back, so they can see it was
              // recorded rather than having to trust that it was. ?>
        <?php $proj = (string) $order->get_meta( '_pps_project_name' ); ?>
        <?php if ( '' !== $proj ) : ?>
            <p class="pps-q-note"><strong><?php echo esc_html( $proj ); ?></strong></p>
        <?php endif; ?>
        <?php if ( $settled ) : ?>
            <div class="banner banner-success"><div><strong>This order is paid.</strong> Thank you — we'll be in touch about artwork and production.</div></div>
        <?php else : ?>
            <p class="form-intro">Your order is reserved. It is not confirmed until payment is received.</p>
            <?php if ( $link ) : ?>
                <p><a class="btn btn-primary btn-submit" href="<?php echo esc_url( $link ); ?>">Pay <?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></a></p>
            <?php else : ?>
                <?php // A reserved order with no way to pay is a dead end. Say so,
                      // rather than showing a page with no button and leaving the
                      // customer to guess whether they are finished. ?>
                <div class="banner banner-error"><div><strong>We could not start the payment for this order.</strong>
                    Nothing has been charged. Please reply to your quote email and we will send a payment link —
                    your order number is <?php echo esc_html( $order->get_order_number() ); ?>.</div></div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="form-foot">A copy of this order is in your history at
            <a href="<?php echo esc_url( home_url( '/reorders/' ) ); ?>">/reorders</a>, using
            <strong><?php echo esc_html( pps_quote_mask_email( $order->get_billing_email() ) ); ?></strong>.</p>
    </div></div>
    <?php return ob_get_clean();
}

/**
 * The part of an address block whose shape depends on the country.
 *
 * Shared by the destination and the billing override because the two must
 * behave identically — a region field that works on one block and not the other
 * is the kind of difference nobody notices until an order lands with an empty
 * province and a label cannot be bought.
 *
 * THE REGION IS TWO FIELDS, not one: a US state select and a free-text box for
 * everywhere else, both named `state`, with the inactive one disabled so it
 * does not post. One field cannot do both jobs — the US needs a two-letter code
 * a carrier will accept, and most of the world needs whatever the customer
 * writes. Disabled rather than merely hidden, because a hidden required field
 * blocks submission with an error nobody can see.
 *
 * With JavaScript off the US pair stays active and the country select stays on
 * US, so the form behaves exactly as it did before any of this existed.
 */
function pps_quote_country_fields( $idp, $np, $ac, array $countries, array $states, $req ) {
    $r = $req ? ' required' : '';
    ob_start(); ?>
    <?php // One country is not a choice. A link with no shipping weight cannot
          // be rated abroad, so its page keeps exactly the form it had before
          // any of this existed rather than growing a dropdown whose only entry
          // is United States. The field still POSTS, as a hidden input, so the
          // order is stamped US explicitly instead of by a default. ?>
    <?php if ( count( $countries ) < 2 ) : ?>
        <input type="hidden" id="<?php echo esc_attr( $idp ); ?>country" name="<?php echo esc_attr( $np ); ?>country" value="US">
    <?php else : ?>
    <div class="field">
        <label for="<?php echo esc_attr( $idp ); ?>country">Country <?php if ( $req ) : ?><span class="req">*</span><?php endif; ?></label>
        <select id="<?php echo esc_attr( $idp ); ?>country" name="<?php echo esc_attr( $np ); ?>country"
                class="pps-q-country"<?php echo $r; ?> autocomplete="<?php echo esc_attr( $ac ); ?> country">
            <?php foreach ( $countries as $code => $name ) : ?>
                <option value="<?php echo esc_attr( $code ); ?>"<?php echo 'US' === $code ? ' selected' : ''; ?>><?php echo esc_html( $name ); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="con-row">
        <div class="field"><label for="<?php echo esc_attr( $idp ); ?>city">City <?php if ( $req ) : ?><span class="req">*</span><?php endif; ?></label>
            <input type="text" id="<?php echo esc_attr( $idp ); ?>city" name="<?php echo esc_attr( $np ); ?>city"<?php echo $r; ?>
                   autocomplete="<?php echo esc_attr( $ac ); ?> address-level2"></div>
        <div class="field">
            <label for="<?php echo esc_attr( $idp ); ?>state"><span class="pps-q-region-text">State</span> <?php if ( $req ) : ?><span class="req">*</span><?php endif; ?></label>
            <select id="<?php echo esc_attr( $idp ); ?>state" name="<?php echo esc_attr( $np ); ?>state" class="pps-q-region-us"<?php echo $r; ?>
                    autocomplete="<?php echo esc_attr( $ac ); ?> address-level1">
                <option value="">—</option>
                <?php foreach ( $states as $st ) : ?><option value="<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st ); ?></option><?php endforeach; ?>
            </select>
            <input type="text" id="<?php echo esc_attr( $idp ); ?>state-intl" name="<?php echo esc_attr( $np ); ?>state"
                   class="pps-q-region-intl" hidden disabled
                   autocomplete="<?php echo esc_attr( $ac ); ?> address-level1" placeholder="Province / region">
        </div>
        <div class="field">
            <label for="<?php echo esc_attr( $idp ); ?>zip"><span class="pps-q-zip-text">ZIP</span> <?php if ( $req ) : ?><span class="req">*</span><?php endif; ?></label>
            <input type="text" id="<?php echo esc_attr( $idp ); ?>zip" name="<?php echo esc_attr( $np ); ?>postcode" class="pps-q-zip"<?php echo $r; ?>
                   inputmode="numeric" autocomplete="<?php echo esc_attr( $ac ); ?> postal-code">
            <span class="field-hint pps-q-zip-hint" hidden>Leave blank if your country does not use one.</span>
        </div>
    </div>
    <?php return ob_get_clean();
}

function pps_quote_form_view( array $q, $error ) {
    $product  = wc_get_product( $q['product'] );
    $tiers    = pps_quote_normalise_tiers( $q['tiers'] );
    $earliest = $q['allow_date'] ? pps_quote_earliest_date( $q['min_days'] ) : '';
    $states   = array( 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC','PR' );
    // The FULL country list, not WooCommerce's allowed-selling-countries: a
    // shop restricted to US selling would otherwise leave this form unable to
    // do the one thing it is here for. Falls back to US-only when WooCommerce
    // is not loaded, so the page renders rather than fatals.
    $countries = array( 'US' => 'United States' );
    if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
        $all = WC()->countries->get_countries();
        if ( is_array( $all ) && $all ) $countries = $all;
    }
    // Only a link that carries a weight can be rated abroad, so only that link
    // offers a country choice. Without one the form stays US-only and the
    // customer is told to contact us, which is better than a country list that
    // leads to a refusal three fields later.
    $can_intl = empty( $q['noship'] ) && ! empty( $q['weight_lb'] ) && (float) $q['weight_lb'] > 0;
    if ( ! $can_intl ) $countries = array( 'US' => $countries['US'] ?? 'United States' );
    // A multi-line quote is a fixed set of jobs: no quantity choice, one total.
    $lines    = pps_quote_normalise_lines( isset( $q['lines'] ) ? $q['lines'] : array() );
    $single   = $lines ? true : ( count( $tiers ) === 1 );
    ob_start(); ?>
    <div class="pps-acct"><div class="pps-q-wrap" style="max-width:640px;margin:0 auto">
        <?php // A reusable link that has already been ordered from looks exactly
              // like a fresh one, so say which it is. Without this a repeat
              // customer cannot tell whether their last order went through, and
              // the safe guess -- submitting again -- is the wrong one. ?>
        <?php $placed = $q['reusable'] ? count( $q['orders'] ) : 0; ?>
        <p class="lookup-eyebrow"><?php echo $placed ? 'Order again' : 'Your quote'; ?></p>
        <?php if ( $placed ) : ?>
            <p class="pps-q-again">You have ordered this
                <?php echo 1 === $placed ? 'once' : esc_html( number_format_i18n( $placed ) . ' times' ); ?>
                before. Filling this in again places a new order at the same price.</p>
        <?php endif; ?>

        <?php // The description IS the product here -- a conversation-minted job has
              // no catalogue page to explain it, so it carries the whole spec and is
              // set at reading size rather than as a caption under a heading.
              //
              // A multi-line quote prints NOTHING here. Its spec was the first
              // line's description, so the top of the page announced one item as
              // though it were the whole job, with the other items appearing only
              // further down -- a customer reading just the top would have thought
              // they were paying $395 for the business cards. The itemised list
              // below is the description. ?>
        <?php if ( $lines ) : ?>
        <?php elseif ( $q['specs'] ) : ?>
            <pre class="oc-specs pps-q-spec"><?php echo esc_html( $q['specs'] ); ?></pre>
        <?php else : ?>
            <h2 class="lookup-title"><?php echo esc_html( $product ? $product->get_name() : 'Print job' ); ?></h2>
        <?php endif; ?>
        <?php if ( ! empty( $q['note'] ) ) : ?>
            <p class="pps-q-note"><?php echo esc_html( $q['note'] ); ?></p>
        <?php endif; ?>

        <?php // A single tier is not a choice, so it is shown as the price of the job
              // rather than as a quantity field the customer has to acknowledge.
              //
              // The price a pay link carries is the WHOLE job, delivery included:
              // the operator quoted one figure in a conversation and that is what
              // gets charged. No shipping line is ever added, so the total on the
              // next screen matches this exactly. Said out loud here because the
              // page asks for a shipping address immediately afterwards, which
              // otherwise invites "is delivery extra?" at the moment of payment. ?>
        <?php if ( $lines ) : ?>
            <?php // Itemised, because a customer paying one figure for several jobs
                  // needs to see that the figure is the sum of what was quoted. The
                  // quantity sits with its line rather than in a column of its own:
                  // these read as sentences, not as a spreadsheet. ?>
            <ul class="pps-q-lines">
                <?php foreach ( $lines as $ln ) : ?>
                    <li>
                        <span class="pps-q-line-desc"><?php echo nl2br( esc_html( $ln['description'] ) ); ?></span>
                        <span class="pps-q-line-qty"><?php echo esc_html( number_format_i18n( $ln['qty'] ) ); ?></span>
                        <span class="pps-q-line-price"><?php echo wp_kses_post( wc_price( $ln['price'] ) ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="pps-q-price pps-q-total">
                <span class="pps-q-total-label">Total</span>
                <?php echo wp_kses_post( wc_price( pps_quote_lines_total( $lines ) ) ); ?>
            </p>
            <p class="pps-q-incl js-q-incl"><?php echo $q['noship'] ? 'This is the total.' : 'Delivery included — this is the total.'; ?></p>
        <?php elseif ( $single ) : ?>
            <p class="pps-q-price"><?php echo wp_kses_post( wc_price( $tiers[0]['price'] ) ); ?></p>
            <p class="pps-q-incl js-q-incl"><?php echo $q['noship'] ? 'This is the total.' : 'Delivery included — this is the total.'; ?></p>
        <?php endif; ?>

        <form method="post" class="form" id="pps-q-form" style="margin-top:16px">
            <?php wp_nonce_field( 'pps_quote_' . $q['token'], 'pps_quote_nonce' ); ?>
            <?php // v3 is the billing-only form. The version travels with the markup
                  // so a page already open in a tab is read exactly as rendered. ?>
            <input type="hidden" name="pps_form_v" value="<?php echo $q['noship'] ? '3' : '2'; ?>">
            <?php // Inside the form, not hoisted out with a form= attribute: the tier
                  // index must post even if that attribute is ever unsupported, or the
                  // order silently takes tier 0 by fallback instead of by intent. ?>
            <?php if ( $single ) : ?><input type="hidden" name="tier" value="0"><?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="banner banner-error"><div><strong><?php echo esc_html( $error ); ?></strong></div></div>
            <?php endif; ?>

            <?php if ( ! $single ) : ?>
                <div class="field">
                    <label for="q-tier">Quantity <span class="req">*</span></label>
                    <select id="q-tier" name="tier">
                        <?php foreach ( $tiers as $i => $t ) : ?>
                            <option value="<?php echo esc_attr( $i ); ?>">
                                <?php echo esc_html( number_format_i18n( $t['qty'] ) ); ?> — <?php echo esc_html( wp_strip_all_tags( wc_price( $t['price'] ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint js-q-incl"><?php echo $q['noship'] ? 'The price shown is the total.' : 'Delivery included — the price shown is the total.'; ?></span>
                </div>
            <?php endif; ?>

            <?php // The customer's own name for the job. Optional, and deliberately
                  // first: it is about the work, not about paying for it, and it is
                  // the one thing on this page they know better than we do.
                  //
                  // Stored as VISIBLE line-item meta, so it follows the order into
                  // wp-admin, the confirmation email and the /reorders card without
                  // anything else having to be taught about it. ?>
            <div class="field">
                <label for="q-project">Project name</label>
                <input type="text" id="q-project" name="project" maxlength="120"
                       placeholder="e.g. Spring mailer">
                <span class="field-hint">Optional — what you call this job. It appears on your
                    receipt and order history, which makes it easier to find later.</span>
            </div>

            <?php // Destination first: it is what the job needs, it drives transit, and
                  // the customer knows it before they think about a card.
                  //
                  // On a *virtual order there is no destination at all, so this one
                  // block is the billing address and the autocomplete hints say so --
                  // a browser offering a shipping address into a billing field is how
                  // the wrong address gets saved to somebody's profile. ?>
            <?php $ac = $q['noship'] ? 'billing' : 'shipping'; ?>
            <h3 class="con-h" style="margin-top:20px"><?php echo $q['noship'] ? 'Billing' : 'Shipping'; ?></h3>
            <div class="con-row">
                <div class="field"><label for="q-first">First name <span class="req">*</span></label><input type="text" id="q-first" name="first" required autocomplete="<?php echo esc_attr( $ac ); ?> given-name"></div>
                <div class="field"><label for="q-last">Last name <span class="req">*</span></label><input type="text" name="last" id="q-last" required autocomplete="<?php echo esc_attr( $ac ); ?> family-name"></div>
            </div>
            <div class="con-row">
                <div class="field"><label for="q-company">Company</label><input type="text" id="q-company" name="company" autocomplete="<?php echo esc_attr( $ac ); ?> organization"></div>
                <?php // Phone is collected FOR THE CARRIER, which is why the hint names
                      // them. Nothing ships on a *virtual order, so it is optional there
                      // and the hint would be a lie. ?>
                <div class="field"><label for="q-phone">Phone <?php if ( ! $q['noship'] ) : ?><span class="req">*</span><?php endif; ?></label>
                    <input type="tel" id="q-phone" name="phone" <?php if ( ! $q['noship'] ) : ?>required<?php endif; ?> autocomplete="tel" inputmode="tel">
                    <span class="field-hint"><?php echo $q['noship'] ? 'Optional — only if we need to reach you about this charge.' : 'Given to UPS / FedEx for delivery.'; ?></span></div>
            </div>
            <div class="field"><label for="q-a1">Street address <span class="req">*</span></label><input type="text" id="q-a1" name="address_1" required autocomplete="<?php echo esc_attr( $ac ); ?> address-line1"></div>
            <div class="field"><label for="q-a2">Apt, suite, unit</label><input type="text" id="q-a2" name="address_2" autocomplete="<?php echo esc_attr( $ac ); ?> address-line2"></div>
            <?php echo pps_quote_country_fields( 'q-', '', $ac, $countries, $states, true ); ?>

            <?php if ( ! $q['noship'] ) : ?>
                <?php // Filled in by the same transit endpoint the calculators use, so the
                      // date quoted here and the date quoted on a product page come from
                      // one source rather than two that can drift apart. ?>
                <p class="field-hint pps-q-transit" id="q-transit" hidden></p>
            <?php endif; ?>

            <?php // Shipping abroad, itemised. Placed HERE rather than beside the
                  // price at the top of the page: it appears as a consequence of
                  // the country the customer has just chosen, a few fields up,
                  // which is where they are looking. The top of the page instead
                  // stops claiming delivery is included, so nothing up there is
                  // ever left saying something untrue.
                  //
                  // Hidden until a foreign country is picked. Almost every
                  // customer is domestic and should never meet this copy. ?>
            <?php if ( $can_intl ) : ?>
                <div class="pps-q-intl" id="q-intl" hidden>
                    <ul class="pps-q-lines">
                        <li><span class="pps-q-line-desc">Printing</span>
                            <span class="pps-q-line-price" id="q-intl-base"></span></li>
                        <li><span class="pps-q-line-desc" id="q-intl-svc">International shipping</span>
                            <span class="pps-q-line-price" id="q-intl-ship"></span></li>
                    </ul>
                    <p class="pps-q-price pps-q-total">
                        <span class="pps-q-total-label">Total</span>
                        <span id="q-intl-total"></span>
                    </p>
                    <p class="pps-q-incl">Import duties and taxes are not included — the carrier
                        collects those from the recipient on delivery.</p>
                </div>
                <?php // A failed lookup must be loud. The alternative is a customer
                      // paying the domestic figure for a foreign address, which is
                      // the shop silently eating the freight. ?>
                <div class="banner banner-error pps-q-intl-err" id="q-intl-err" hidden><div></div></div>
            <?php endif; ?>

            <?php if ( $q['allow_date'] ) : ?>
                <div class="field">
                    <label for="q-date">Requested delivery date</label>
                    <input type="date" id="q-date" name="date" min="<?php echo esc_attr( $earliest ); ?>">
                    <span class="field-hint">Earliest delivery is
                        <?php echo esc_html( date_i18n( 'l, M j, Y', strtotime( $earliest ) ) ); ?>.
                        Leave blank for standard turnaround.</span>
                </div>
            <?php endif; ?>

            <h3 class="con-h" style="margin-top:20px"><?php echo $q['noship'] ? 'Contact' : 'Billing'; ?></h3>
            <div class="field"><label for="q-email">Email <span class="req">*</span></label><input type="email" id="q-email" name="email" required autocomplete="email">
                <span class="field-hint">Your receipt and order history use this address.</span></div>

            <?php // Only a shipping form needs a separate billing address. On a
                  // *virtual order the one block above IS the billing address, so
                  // offering to make billing "same as shipping" would be asking
                  // about a shipping address that does not exist. ?>
            <?php if ( ! $q['noship'] ) : ?>
            <div class="field">
                <label class="con-radio"><input type="checkbox" name="bill_same" value="1" checked id="q-same"> Billing address same as shipping</label>
            </div>
            <div id="q-bill" hidden>
                <div class="con-row">
                    <div class="field"><label for="q-bfirst">First name</label><input type="text" id="q-bfirst" name="b_first" autocomplete="billing given-name"></div>
                    <div class="field"><label for="q-blast">Last name</label><input type="text" name="b_last" id="q-blast" autocomplete="billing family-name"></div>
                </div>
                <div class="field"><label for="q-bcompany">Company</label><input type="text" id="q-bcompany" name="b_company" autocomplete="billing organization"></div>
                <div class="field"><label for="q-ba1">Street address</label><input type="text" id="q-ba1" name="b_address_1" autocomplete="billing address-line1"></div>
                <div class="field"><label for="q-ba2">Apt, suite, unit</label><input type="text" id="q-ba2" name="b_address_2" autocomplete="billing address-line2"></div>
                <?php echo pps_quote_country_fields( 'q-b', 'b_', 'billing', $countries, $states, false ); ?>
            </div>
            <?php endif; ?>

            <?php // Disabled while a foreign destination has no price yet, so the
                  // one thing a customer cannot do is pay a total we have not
                  // finished working out. ?>
            <button type="submit" name="pps_quote_submit" value="1" class="btn btn-primary btn-submit" id="q-submit">Continue to payment</button>
            <p class="form-foot">You'll confirm payment on the next screen.</p>
        </form>
    </div></div>
    <style>
      .pps-q-spec{font-size:1.05rem;line-height:1.55;white-space:pre-wrap;word-break:break-word;margin:0 0 10px}
      .pps-q-note{margin:0 0 10px;opacity:.85}
      .pps-q-again{margin:0 0 12px;padding:10px 12px;border-radius:8px;
        background:rgba(0,0,0,.04);font-size:.98rem;line-height:1.45}
      .pps-q-price{font-size:1.6rem;font-weight:700;margin:0 0 2px}
      /* Itemised lines. Grid rather than a table: it collapses to one column on
         a phone without a horizontal scroller, and these are read, not scanned. */
      .pps-q-lines{list-style:none;margin:0 0 10px;padding:0}
      .pps-q-lines li{display:grid;grid-template-columns:1fr auto auto;gap:4px 14px;
        align-items:baseline;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.09)}
      .pps-q-line-desc{font-size:1.05rem;line-height:1.5;word-break:break-word}
      .pps-q-line-qty{opacity:.7;font-size:.95rem;white-space:nowrap}
      .pps-q-line-price{font-weight:600;white-space:nowrap}
      .pps-q-total{display:flex;justify-content:space-between;align-items:baseline;gap:12px;padding-top:4px}
      .pps-q-total-label{font-size:1rem;font-weight:600;opacity:.75}
      @media (max-width:430px){
        .pps-q-lines li{grid-template-columns:1fr auto}
        .pps-q-line-desc{grid-column:1/-1}
      }
      .pps-q-incl{margin:0 0 10px;opacity:.75;font-size:.92rem}
      /* The international summary reads as a receipt rather than as a form
         field, because that is what it is: the number being charged. */
      .pps-q-intl{margin:4px 0 14px;padding:12px 14px;border-radius:8px;background:rgba(0,0,0,.04)}
      .pps-q-intl .pps-q-lines li{padding:7px 0}
      .pps-q-intl .pps-q-price{font-size:1.35rem}
      .pps-q-intl .pps-q-incl{margin:6px 0 0}
      .pps-q-intl-err{margin:0 0 14px}
      .pps-q-transit{margin:-4px 0 12px;font-size:1rem}
      /* Scoped to this page: it is read once, by somebody deciding whether to
         spend money, often on a phone. Comfortable beats compact. */
      .pps-q-wrap{font-size:1.05rem}
      .pps-q-wrap .con-h{font-size:1.3rem}
      .pps-q-wrap .field label,
      .pps-q-wrap .con-radio{font-size:1.02rem}
      .pps-q-wrap input,
      .pps-q-wrap select,
      .pps-q-wrap textarea{font-size:1.05rem;padding:11px 12px}
      .pps-q-wrap .field-hint{font-size:.98rem;line-height:1.45}
      .pps-q-wrap .btn-submit{font-size:1.1rem;padding:13px 22px}
      .pps-q-wrap .form-foot{font-size:.98rem}
    </style>
    <script>
    (function(){
        // Closed days, so a weekend or holiday can be refused as it is picked
        // rather than after a round trip. The server checks this too — this is
        // only here to save the customer a rejected submission.
        var CLOSED = <?php echo wp_json_encode( array_values( (array) ( function_exists( 'pps_get_closures' ) ? pps_get_closures() : array() ) ) ); ?>;
        var dateEl = document.getElementById('q-date');
        if (dateEl) {
            var msg = document.createElement('span');
            msg.className = 'field-hint'; msg.style.color = '#b32d2e'; msg.hidden = true;
            dateEl.parentNode.appendChild(msg);
            dateEl.addEventListener('change', function(){
                msg.hidden = true;
                var v = dateEl.value; if (!v) return;
                // Parsed as local parts, not Date(v), which reads a bare
                // YYYY-MM-DD as UTC and can land on the previous day.
                var p = v.split('-'), dt = new Date(+p[0], +p[1] - 1, +p[2]);
                var dow = dt.getDay();
                var closed = (dow === 0 || dow === 6)
                    || CLOSED.indexOf(v) !== -1
                    || CLOSED.indexOf(v.slice(5)) !== -1;
                if (closed) {
                    dateEl.value = '';
                    msg.hidden = false;
                    msg.textContent = (dow === 0 || dow === 6)
                        ? 'We do not deliver at weekends — please choose a weekday.'
                        : 'We are closed that day — please choose another.';
                }
            });
        }

        // ── Country ──────────────────────────────────────────────────────
        // The destination decides the shape of the rest of the address AND
        // whether delivery is inside the quoted price or added on top of it.
        //
        // Both address blocks carry two region fields — a US state select and
        // a free-text box — with the inactive one DISABLED so it does not
        // post. Disabled rather than merely hidden: a hidden required field
        // blocks submission with a message nobody can see.
        var QCFG = <?php echo wp_json_encode( array(
            'token' => $q['token'],
            'formV' => $q['noship'] ? '3' : '2',
            'lines' => $lines ? round( pps_quote_lines_total( $lines ), 2 ) : 0,
            'tiers' => array_map( function ( $t ) { return round( (float) $t['price'], 2 ); }, $tiers ),
            'intl'  => (bool) $can_intl,
            'url'   => rest_url( 'pps/v1/quote/ship-quote' ),
        ) ); ?>;

        function qMoney(n){
            return '$' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        // What the printing costs right now: a fixed sum for a multi-line
        // quote, else whichever tier is currently selected.
        function qBase(){
            if (QCFG.lines > 0) return QCFG.lines;
            var sel = document.getElementById('q-tier');
            var i = sel ? (parseInt(sel.value, 10) || 0) : 0;
            return QCFG.tiers[i] !== undefined ? QCFG.tiers[i] : (QCFG.tiers[0] || 0);
        }
        function qText(sel, txt){ var e = document.querySelector(sel); if (e) e.textContent = txt; }

        // Canada needs the province before UPS will price the lane, and Canada
        // is by far the likeliest foreign destination for a US print shop.
        // Everywhere else the region is optional, because a required one is a
        // checkout nobody in Singapore can complete. This must agree with
        // pps_quote_requires_region() in PHP, which is what actually decides.
        function qNeedsRegion(c){ return c === 'US' || c === 'CA'; }

        function qApplyCountry(p, required){
            var c = document.getElementById(p + 'country');
            if (!c) return 'US';
            var code = c.value || 'US',
                us   = document.getElementById(p + 'state'),
                intl = document.getElementById(p + 'state-intl'),
                zip  = document.getElementById(p + 'zip'),
                hint = null,
                domestic = (code === 'US');
            if (us && intl) {
                us.hidden    = !domestic;  us.disabled   = !domestic;
                intl.hidden  = domestic;   intl.disabled = domestic;
                us.required   = domestic && required;
                intl.required = !domestic && required && qNeedsRegion(code);
                if (domestic) { intl.value = ''; } else { us.value = ''; }
            }
            if (zip) {
                // A five-digit ZIP is a US idea. Required only there, and never
                // numeric-only elsewhere — "M5V 3L9" has letters and a space.
                zip.required = !!required && domestic;
                if (domestic) zip.setAttribute('inputmode', 'numeric');
                else zip.removeAttribute('inputmode');
                hint = zip.parentNode.querySelector('.pps-q-zip-hint');
                if (hint) hint.hidden = domestic;
            }
            qText('label[for="' + p + 'state"] .pps-q-region-text', domestic ? 'State' : 'Province / region');
            qText('label[for="' + p + 'zip"] .pps-q-zip-text',      domestic ? 'ZIP'   : 'Postal code');
            return code;
        }

        // ── What shipping abroad costs ────────────────────────────────────
        // Asked of the server, which rates it from the weight stored on the
        // quote. The browser never computes or supplies the amount; the order
        // is rated again by the same function when it is placed, so this is
        // what the customer will actually be charged rather than a preview of
        // a separate calculation that could drift from it.
        var qIntl    = document.getElementById('q-intl'),
            qIntlErr = document.getElementById('q-intl-err'),
            qSubmit  = document.getElementById('q-submit'),
            qCountry = document.getElementById('q-country'),
            qCity    = document.getElementById('q-city'),
            qZip     = document.getElementById('q-zip'),
            qStreet  = document.getElementById('q-a1'),
            qPaid    = null,      // the rate currently displayed
            qTimer   = null,
            qSeq     = 0;

        function qShowIncl(foreign){
            var all = document.querySelectorAll('.js-q-incl');
            for (var i = 0; i < all.length; i++) {
                // Each line remembers its own wording. The multi-tier page says
                // "the price shown is the total" where the single-price page
                // says "this is the total", and one hardcoded restore string
                // would quietly rewrite one into the other.
                if (!all[i].hasAttribute('data-orig')) all[i].setAttribute('data-orig', all[i].textContent);
                all[i].textContent = foreign
                    ? 'Delivery is included for US addresses. Shipping to this country is added below.'
                    : all[i].getAttribute('data-orig');
            }
        }
        function qFail(msg){
            qPaid = null;
            if (qIntl) qIntl.hidden = true;
            if (qIntlErr) { qIntlErr.hidden = false; qIntlErr.firstElementChild.textContent = msg; }
            // No price, no payment. Letting this through would charge the
            // domestic figure for a foreign address, which is the shop
            // silently paying the freight.
            if (qSubmit) { qSubmit.disabled = true; }
        }
        function qClear(){
            qPaid = null;
            if (qIntl) qIntl.hidden = true;
            if (qIntlErr) qIntlErr.hidden = true;
            if (qSubmit) qSubmit.disabled = false;
            qShowIncl(false);
        }
        function qPaint(){
            if (!qPaid || !qIntl) return;
            var base = qBase();
            qText('#q-intl-base',  qMoney(base));
            qText('#q-intl-ship',  qMoney(qPaid.amount));
            qText('#q-intl-total', qMoney(base + qPaid.amount));
            qText('#q-intl-svc', (qPaid.carrier ? qPaid.carrier + ' ' : '') + (qPaid.service || 'International shipping')
                + (qPaid.transit_days > 0 ? ' — about ' + qPaid.transit_days + ' days' : ''));
            qIntl.hidden = false;
            if (qIntlErr) qIntlErr.hidden = true;
            if (qSubmit) qSubmit.disabled = false;
            qShowIncl(true);
        }

        function qAskShip(){
            if (!QCFG.intl || !qCountry) return;
            var code = qCountry.value || 'US';
            if (code === 'US') { qClear(); return; }
            var intlRegion = document.getElementById('q-state-intl');
            var tierSel = document.getElementById('q-tier');
            var body = {
                token:    QCFG.token,
                form_v:   QCFG.formV,
                country:  code,
                postcode: qZip ? qZip.value : '',
                state:    intlRegion && !intlRegion.disabled ? intlRegion.value : '',
                city:     qCity ? qCity.value : '',
                street:   qStreet ? qStreet.value : '',
                // Decides the declared customs value, so a quantity change
                // changes what UPS is asked about, not just what we display.
                tier:     tierSel ? (parseInt(tierSel.value, 10) || 0) : 0
            };
            // A street and a city are the minimum worth asking on. Many lanes
            // cannot be priced from a country alone, a residential surcharge
            // needs the street, and asking on every keystroke of an address
            // burns the rate limit for nothing.
            if (!body.street || !body.city) { qClear(); qShowIncl(true); return; }
            // Each request carries a sequence number and only the newest answer
            // is allowed to paint. Without it a slow reply for Germany can land
            // after a fast one for Canada and overwrite the right price.
            var mine = ++qSeq;
            fetch(QCFG.url, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin', body: JSON.stringify(body)
            }).then(function(r){ return r ? r.json() : null; }).then(function(j){
                if (mine !== qSeq) return;
                if (!j) { qFail('We could not work out shipping to that address. Please try again.'); return; }
                if (j.ok && j.domestic) { qClear(); return; }
                if (!j.ok) {
                    qFail(j.message || 'We could not get a shipping price for that address. Please contact us.');
                    return;
                }
                qPaid = j;
                qPaint();
            }).catch(function(){
                if (mine !== qSeq) return;
                qFail('We could not reach the carrier for a shipping price. Please try again in a moment.');
            });
        }
        function qQueueShip(){ clearTimeout(qTimer); qTimer = setTimeout(qAskShip, 700); }

        // Both of the blocks below belong to the SHIPPING form. A *virtual order
        // renders neither the billing-override panel nor the transit line, so
        // guard on the elements themselves: a check against what is actually in
        // the document cannot disagree with what was rendered, the way a flag
        // duplicated into JS eventually would.
        var same = document.getElementById('q-same'), bill = document.getElementById('q-bill');
        if (same && bill) {
        function sync(){
            bill.hidden = same.checked;
            // Required only while the panel is visible, or a hidden blank field
            // would block submission with an error nobody can see.
            bill.querySelectorAll('input,select').forEach(function(i){
                if (i.name === 'b_address_1' || i.name === 'b_city' || i.name === 'b_state' || i.name === 'b_postcode') i.required = !same.checked;
            });
            // ...and then let the country have the last word. The loop above
            // would otherwise insist on a ZIP and a state for a billing address
            // in a country that has neither, which is a submit button that
            // silently does nothing.
            qApplyCountry('q-b', !same.checked);
        }
        same.addEventListener('change', sync); sync();
        }

        // Live transit, from the same endpoint the calculators call. Debounced
        // and asked once per ZIP: the answer is cached server-side for 30 days
        // because UPS ground transit does not move, and a per-IP limit means a
        // page left open cannot burn the Shippo quota. Any failure is silent --
        // an estimate is a courtesy, and a broken one must not block a sale.
        var zip = document.getElementById('q-zip'),
            st  = document.getElementById('q-state'),
            out = document.getElementById('q-transit'),
            seen = {}, timer = null;
        if (zip && st && out) {
        function ask(){
            // US ONLY. The endpoint behind this takes a 5-digit ZIP and is
            // hardwired to country US, so a German "10115" would sail through
            // and come back with a US ground estimate for an address that is
            // not in the US -- a confident, wrong delivery promise next to a
            // correct international one.
            if (qCountry && qCountry.value && qCountry.value !== 'US') { out.hidden = true; return; }
            var z = (zip.value || '').replace(/[^0-9]/g,'').slice(0,5);
            if (z.length !== 5) return;
            if (seen[z] !== undefined) {
                // Already asked. Repainted rather than ignored, so switching to
                // a foreign country and back restores the line.
                if (seen[z] >= 1) {
                    out.hidden = false;
                    out.textContent = 'About ' + seen[z] + ' business ' + (seen[z] === 1 ? 'day' : 'days') + ' in transit once it ships.';
                }
                return;
            }
            seen[z] = 0;
            fetch('<?php echo esc_js( rest_url( 'pps/v1/shipping/transit-estimate' ) ); ?>', {
                method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin',
                body: JSON.stringify({ zip:z, state: st.value || '', country:'US' })
            }).then(function(r){ return r && r.ok ? r.json() : null; }).then(function(j){
                var d = j ? Number(j.transit_days) : NaN;
                if (!(d >= 1 && d <= 30)) return;
                seen[z] = d;
                out.hidden = false;
                out.textContent = 'About ' + d + ' business ' + (d === 1 ? 'day' : 'days') + ' in transit once it ships.';
            }).catch(function(){});
        }
        function queue(){ clearTimeout(timer); timer = setTimeout(ask, 600); }
        zip.addEventListener('input', queue);
        st.addEventListener('change', queue);
        }

        // ── Wiring ───────────────────────────────────────────────────────
        // Both address blocks get the country swap. Only the destination drives
        // the shipping lookup -- the billing address is where the card lives
        // and has nothing to do with what the carrier charges.
        ['q-', 'q-b'].forEach(function(pfx){
            var c = document.getElementById(pfx + 'country');
            if (!c) return;
            var req = function(){
                if ('q-' === pfx) return true;
                return same ? !same.checked : false;
            };
            c.addEventListener('change', function(){
                qApplyCountry(pfx, req());
                if ('q-' === pfx) { qAskShip(); queue(); }
            });
            qApplyCountry(pfx, req());
        });
        // The city and postal code move the price abroad, so they ask again.
        // Debounced, because this one costs a carrier call on a cache miss.
        if (QCFG.intl) {
            if (qCity) qCity.addEventListener('input', qQueueShip);
            if (qZip)  qZip.addEventListener('input', qQueueShip);
            var qRegion = document.getElementById('q-state-intl');
            if (qRegion) qRegion.addEventListener('input', qQueueShip);
            if (qStreet) qStreet.addEventListener('input', qQueueShip);
            // A different quantity is a different declared customs value, so
            // the carrier has to be asked again -- it is not only the printing
            // half of the sum that moves.
            var qTier = document.getElementById('q-tier');
            if (qTier) qTier.addEventListener('change', function(){ qPaint(); qQueueShip(); });
        }
    })();
    </script>
    <?php return ob_get_clean();
}

/* Quote page rides on the lookup stylesheet plus the console's extras. */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_singular() ) return;
    $post = get_post();
    if ( ! $post || ! has_shortcode( (string) $post->post_content, 'pps_job_quote' ) ) return;
    if ( ! function_exists( 'pps_acct_ui_css' ) ) return;
    $ver = defined( 'PPS_CALC_VERSION' ) ? PPS_CALC_VERSION : '1.0.0';
    wp_register_style( 'pps-acct-ui', false, array(), $ver );
    wp_enqueue_style( 'pps-acct-ui' );
    $extra = function_exists( 'pps_job_console_css' ) ? pps_job_console_css() : '';
    wp_add_inline_style( 'pps-acct-ui', pps_acct_ui_css() . $extra . pps_quote_chrome_css() );
}, 11 );

/**
 * Strip the site footer from the quote page.
 *
 * This page has one job — take a payment — and the footer is a set of exits
 * from it. Nothing below the button helps somebody decide, and the shop links,
 * policy pages and newsletter form all lead away mid-checkout.
 *
 * Astra's selectors, because that is the active theme; pps-theme's footer.php
 * emits no footer at all, so this is a no-op there rather than a mistake if
 * the theme is ever switched. Scoped by the enqueue above, which only runs on
 * the page carrying the shortcode, so no other page loses its footer.
 */
function pps_quote_chrome_css() {
    return '
    #colophon,
    footer.site-footer,
    .site-footer,
    .ast-small-footer,
    .footer-adv,
    .ast-footer-overlay { display: none !important; }

    /* Room below the form. Removing the footer took away the only thing that
       was standing between the submit button and the bottom of the window, so
       the last field sat hard against the edge -- which reads as a page that
       has been cut off rather than one that has ended. Applied to .pps-acct so
       the confirmation screen gets it too; that view is shorter and would look
       worse. Scales with the viewport but never collapses on a phone. */
    .pps-acct { padding-bottom: clamp(80px, 14vh, 180px); }
    ';
}
