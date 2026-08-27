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
    update_post_meta( $id, '_q_allow_date', ! empty( $a['allow_date'] ) ? 1 : 0 );
    update_post_meta( $id, '_q_min_days', max( 0, (int) ( $a['min_days'] ?? 0 ) ) );
    update_post_meta( $id, '_q_pay_source', $source );
    if ( $link ) update_post_meta( $id, '_q_pay_link', $link );
    update_post_meta( $id, '_q_by', sanitize_text_field( $a['by'] ?? 'console' ) );
    update_post_meta( $id, '_q_note', isset( $a['note'] ) ? sanitize_textarea_field( $a['note'] ) : '' );

    return $id;
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
        'allow_date' => (bool) get_post_meta( $id, '_q_allow_date', true ),
        'min_days'   => (int) get_post_meta( $id, '_q_min_days', true ),
        'pay_source' => (string) get_post_meta( $id, '_q_pay_source', true ),
        'pay_link'   => (string) get_post_meta( $id, '_q_pay_link', true ),
        'order'      => (int) get_post_meta( $id, '_q_order', true ),
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

/* ─────────────────────────────────────────────────────────────
 * Quote -> order
 * ───────────────────────────────────────────────────────────── */

function pps_quote_to_order( array $q, array $p ) {
    $tiers = pps_quote_normalise_tiers( $q['tiers'] );
    if ( ! $tiers ) return new WP_Error( 'tiers', 'This quote has no quantities on it. Please contact us.' );

    $idx  = isset( $p['tier'] ) ? absint( $p['tier'] ) : 0;
    if ( ! isset( $tiers[ $idx ] ) ) $idx = 0;
    $tier = $tiers[ $idx ];

    $email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
    if ( ! $email || ! is_email( $email ) ) return new WP_Error( 'email', 'Please enter a valid email address.' );
    foreach ( array( 'first' => 'first name', 'last' => 'last name', 'address_1' => 'street address', 'city' => 'city', 'state' => 'state', 'postcode' => 'ZIP code' ) as $k => $label ) {
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
            $date = $d;
        }
    }

    $product = wc_get_product( $q['product'] );
    if ( ! $product || ! $product->exists() ) return new WP_Error( 'product', 'That product is no longer available. Please contact us.' );

    $order = wc_create_order( array( 'customer_id' => get_current_user_id() ) );
    if ( is_wp_error( $order ) ) return new WP_Error( 'create', 'Could not start your order. Please contact us.' );

    $order->set_created_via( 'pps-job-quote' );
    $order->set_billing_first_name( sanitize_text_field( $p['first'] ) );
    $order->set_billing_last_name( sanitize_text_field( $p['last'] ) );
    $order->set_billing_email( $email );
    $order->set_billing_phone( isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '' );
    $order->set_billing_company( isset( $p['company'] ) ? sanitize_text_field( $p['company'] ) : '' );
    $order->set_billing_address_1( sanitize_text_field( $p['address_1'] ) );
    $order->set_billing_address_2( isset( $p['address_2'] ) ? sanitize_text_field( $p['address_2'] ) : '' );
    $order->set_billing_city( sanitize_text_field( $p['city'] ) );
    $order->set_billing_state( sanitize_text_field( $p['state'] ) );
    $order->set_billing_postcode( sanitize_text_field( $p['postcode'] ) );
    $order->set_billing_country( 'US' );

    // Ship-to defaults to billing, which is also what WooCommerce would do —
    // but these products are virtual, so nothing else would ever collect it.
    $same = ! empty( $p['ship_same'] );
    $g = function( $k ) use ( $p, $same ) {
        $src = $same ? $k : 's_' . $k;
        return isset( $p[ $src ] ) ? sanitize_text_field( $p[ $src ] ) : '';
    };
    $order->set_shipping_first_name( $g( 'first' ) );
    $order->set_shipping_last_name( $g( 'last' ) );
    $order->set_shipping_company( $g( 'company' ) );
    $order->set_shipping_address_1( $g( 'address_1' ) );
    $order->set_shipping_address_2( $g( 'address_2' ) );
    $order->set_shipping_city( $g( 'city' ) );
    $order->set_shipping_state( $g( 'state' ) );
    $order->set_shipping_postcode( $g( 'postcode' ) );
    $order->set_shipping_country( 'US' );

    $item_id = $order->add_product( $product, $tier['qty'], array(
        'subtotal' => $tier['price'],
        'total'    => $tier['price'],
    ) );
    if ( $item_id ) {
        $item = $order->get_item( $item_id );
        if ( $item ) {
            if ( $q['specs'] ) $item->add_meta_data( 'Specs', $q['specs'], true );
            if ( $date ) $item->add_meta_data( 'Requested delivery', $date, true );
            $item->save();
        }
    }

    // The alternatives travel with the order so a reorder can offer them.
    $order->update_meta_data( '_pps_qty_tiers', $tiers );
    $order->update_meta_data( '_pps_quote_token', $q['token'] );
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

    update_post_meta( $q['id'], '_q_order', $order->get_id() );
    return $order;
}

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

    // Already used: send them back to the same order rather than making a
    // second one. A customer who closed the tab mid-payment lands here.
    if ( $q['order'] ) {
        $existing = wc_get_order( $q['order'] );
        if ( $existing && ! pps_job_invoice_is_unpaid( $existing ) ) {
            return pps_quote_placed_view( $existing, true );
        }
        if ( $existing ) return pps_quote_placed_view( $existing, false );
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

function pps_quote_form_view( array $q, $error ) {
    $product  = wc_get_product( $q['product'] );
    $tiers    = pps_quote_normalise_tiers( $q['tiers'] );
    $earliest = $q['allow_date'] ? pps_quote_earliest_date( $q['min_days'] ) : '';
    $states   = array( 'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC','PR' );
    ob_start(); ?>
    <div class="pps-acct"><div style="max-width:640px;margin:0 auto">
        <p class="lookup-eyebrow">Your quote</p>
        <h2 class="lookup-title"><?php echo esc_html( $product ? $product->get_name() : 'Print job' ); ?></h2>
        <?php if ( $q['specs'] ) : ?><pre class="oc-specs"><?php echo esc_html( $q['specs'] ); ?></pre><?php endif; ?>

        <form method="post" class="form" style="margin-top:16px">
            <?php wp_nonce_field( 'pps_quote_' . $q['token'], 'pps_quote_nonce' ); ?>
            <?php if ( $error ) : ?>
                <div class="banner banner-error"><div><strong><?php echo esc_html( $error ); ?></strong></div></div>
            <?php endif; ?>

            <div class="field">
                <label for="q-tier">Quantity <span class="req">*</span></label>
                <?php if ( count( $tiers ) > 1 ) : ?>
                    <select id="q-tier" name="tier">
                        <?php foreach ( $tiers as $i => $t ) : ?>
                            <option value="<?php echo esc_attr( $i ); ?>">
                                <?php echo esc_html( number_format_i18n( $t['qty'] ) ); ?> — <?php echo esc_html( wp_strip_all_tags( wc_price( $t['price'] ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="hidden" name="tier" value="0">
                    <p style="margin:0"><strong><?php echo esc_html( number_format_i18n( $tiers[0]['qty'] ) ); ?></strong>
                        — <?php echo wp_kses_post( wc_price( $tiers[0]['price'] ) ); ?></p>
                <?php endif; ?>
            </div>

            <?php if ( $q['allow_date'] ) : ?>
                <div class="field">
                    <label for="q-date">Requested delivery date</label>
                    <input type="date" id="q-date" name="date" min="<?php echo esc_attr( $earliest ); ?>">
                    <span class="field-hint">Earliest we can deliver this job is <?php echo esc_html( date_i18n( 'l, M j, Y', strtotime( $earliest ) ) ); ?>. Leave blank for standard turnaround.</span>
                </div>
            <?php endif; ?>

            <h3 class="con-h" style="margin-top:20px">Billing</h3>
            <div class="con-row">
                <div class="field"><label for="q-first">First name <span class="req">*</span></label><input type="text" id="q-first" name="first" required></div>
                <div class="field"><label for="q-last">Last name <span class="req">*</span></label><input type="text" name="last" id="q-last" required></div>
            </div>
            <div class="field"><label for="q-email">Email <span class="req">*</span></label><input type="email" id="q-email" name="email" required>
                <span class="field-hint">Your receipt and order history use this address.</span></div>
            <div class="field"><label for="q-phone">Phone</label><input type="text" id="q-phone" name="phone"></div>
            <div class="field"><label for="q-company">Company</label><input type="text" id="q-company" name="company"></div>
            <div class="field"><label for="q-a1">Street address <span class="req">*</span></label><input type="text" id="q-a1" name="address_1" required></div>
            <div class="field"><label for="q-a2">Apt, suite, unit</label><input type="text" id="q-a2" name="address_2"></div>
            <div class="con-row">
                <div class="field"><label for="q-city">City <span class="req">*</span></label><input type="text" id="q-city" name="city" required></div>
                <div class="field"><label for="q-state">State <span class="req">*</span></label>
                    <select id="q-state" name="state" required>
                        <option value="">—</option>
                        <?php foreach ( $states as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="field"><label for="q-zip">ZIP <span class="req">*</span></label><input type="text" id="q-zip" name="postcode" required></div>
            </div>

            <div class="field">
                <label class="con-radio"><input type="checkbox" name="ship_same" value="1" checked id="q-same"> Ship to this address</label>
            </div>
            <div id="q-ship" hidden>
                <h3 class="con-h">Ship to</h3>
                <div class="con-row">
                    <div class="field"><label for="q-sfirst">First name</label><input type="text" id="q-sfirst" name="s_first"></div>
                    <div class="field"><label for="q-slast">Last name</label><input type="text" name="s_last" id="q-slast"></div>
                </div>
                <div class="field"><label for="q-scompany">Company</label><input type="text" id="q-scompany" name="s_company"></div>
                <div class="field"><label for="q-sa1">Street address</label><input type="text" id="q-sa1" name="s_address_1"></div>
                <div class="field"><label for="q-sa2">Apt, suite, unit</label><input type="text" id="q-sa2" name="s_address_2"></div>
                <div class="con-row">
                    <div class="field"><label for="q-scity">City</label><input type="text" id="q-scity" name="s_city"></div>
                    <div class="field"><label for="q-sstate">State</label>
                        <select id="q-sstate" name="s_state"><option value="">—</option>
                            <?php foreach ( $states as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="field"><label for="q-szip">ZIP</label><input type="text" id="q-szip" name="s_postcode"></div>
                </div>
            </div>

            <button type="submit" name="pps_quote_submit" value="1" class="btn btn-primary btn-submit">Continue to payment</button>
            <p class="form-foot">You'll confirm payment on the next screen.</p>
        </form>
    </div></div>
    <script>
    (function(){
        var same = document.getElementById('q-same'), ship = document.getElementById('q-ship');
        function sync(){
            ship.hidden = same.checked;
            // Required only while the panel is visible, or a hidden blank field
            // would block submission with an error nobody can see.
            ship.querySelectorAll('input,select').forEach(function(i){
                if (i.name === 's_address_1' || i.name === 's_city' || i.name === 's_state' || i.name === 's_postcode') i.required = !same.checked;
            });
        }
        same.addEventListener('change', sync); sync();
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
    wp_add_inline_style( 'pps-acct-ui', pps_acct_ui_css() . $extra );
}, 11 );
