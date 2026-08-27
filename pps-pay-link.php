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

/**
 * The token the link will carry.
 *
 * Defaults to the minute it was minted, in SITE time — 20260827-0215. The
 * point is that the operator can write the link into their reply straight
 * away from a clock, instead of waiting for something to hand it back to
 * them. An explicit reference wins when given, because "acme-october" is
 * easier to say on the phone than a timestamp.
 *
 * The trade this makes is deliberate and worth stating: a predictable token
 * can be enumerated, so a quote link is closer to unlisted than to private.
 * What it protects is a job description and a price. It is NOT a password,
 * and nothing behind it may assume otherwise — which is why the used-quote
 * page masks the customer's email.
 *
 * Two links minted in the same minute do not collide into one another:
 * pps_quote_create() reads the stored slug back, so the second becomes
 * -2 and its real link is the one returned.
 */
function pps_paylink_token( $reference = '' ) {
    $reference = sanitize_title( (string) $reference );
    if ( '' !== $reference ) return $reference;
    return current_time( 'Ymd-Hi' );
}

/* ─────────────────────────────────────────────────────────────
 * Reading a command typed in a conversation
 * ───────────────────────────────────────────────────────────── */

/**
 * Find the operator's typed text inside whatever the caller posted.
 *
 * A rule engine posts ITS payload, not ours: a Missive rule delivers the
 * message/comment envelope, and the text is somewhere inside it. Mirrors
 * pps_assistant_missive_extract(), which walks a list of plausible paths for
 * exactly this reason — Missive 403s automated fetches of its own docs, so the
 * shape is reconstructed rather than read off a spec.
 *
 * A caller that already sends {description, price} never reaches this.
 */
function pps_paylink_extract_text( $payload ) {
    if ( ! is_array( $payload ) ) return '';

    // An explicit field wins over anything found by walking.
    foreach ( array( 'text', 'command', 'message' ) as $k ) {
        if ( ! empty( $payload[ $k ] ) && is_string( $payload[ $k ] ) ) return $payload[ $k ];
    }

    $nodes = array( $payload );
    foreach ( array( 'message', 'post', 'draft', 'data', 'comment' ) as $k ) {
        if ( isset( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) $nodes[] = $payload[ $k ];
    }
    foreach ( $nodes as $n ) {
        foreach ( array( 'body', 'text', 'delivered_body', 'preview', 'markdown' ) as $bk ) {
            if ( ! empty( $n[ $bk ] ) && is_string( $n[ $bk ] ) ) return $n[ $bk ];
        }
    }
    return '';
}

/**
 * Pull a job out of a line an operator typed.
 *
 *   /pay $250 500 postcards, 16pt gloss
 *   pay $1,250.00 qbo 2000 booklets #acme-october
 *
 * WHY THE PRICE MUST BE UNAMBIGUOUS
 * "500 postcards $250" and "$250 500 postcards" both mean the same job, and a
 * parser that takes the first number would charge 500 dollars for one of them.
 * A wrong price is worse than a refusal, so: the $-prefixed amount wins; with
 * no $ and exactly one number in the line that number is the price; with no $
 * and several numbers this refuses and says why.
 *
 * @return array|WP_Error {description, price, qbo, reference}
 */
function pps_paylink_parse_command( $text ) {
    $text = trim( (string) $text );
    if ( '' === $text ) return new WP_Error( 'empty', 'Nothing to read — send the job and its price.' );

    // Strip a leading command word so "/pay" does not land in the description.
    $text = preg_replace( '/^\s*\/?(pay|paylink|quote)\b[:\s]*/i', '', $text, 1 );

    // #reference, pulled out before anything else so its digits cannot be read
    // as a price.
    $reference = '';
    if ( preg_match( '/(?:^|\s)#([A-Za-z0-9_-]{2,60})\b/', $text, $m ) ) {
        $reference = $m[1];
        $text = str_replace( $m[0], ' ', $text );
    }

    // The QuickBooks flag as a standalone word, so "quickbooks-style booklets"
    // in a description does not silently route a payment.
    $qbo = false;
    if ( preg_match( '/(?:^|\s)(qbo|quickbooks)(?=$|\s)/i', $text, $m ) ) {
        $qbo  = true;
        $text = preg_replace( '/(?:^|\s)(qbo|quickbooks)(?=$|\s)/i', ' ', $text, 1 );
    }

    $price = null;
    if ( preg_match( '/\$\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $text, $m ) ) {
        $price = pps_paylink_parse_price( $m[1] );
        $text  = str_replace( $m[0], ' ', $text );
    } else {
        preg_match_all( '/(?:^|\s)([0-9][0-9,]*(?:\.[0-9]{1,2})?)(?=$|\s|,)/', $text, $all );
        $nums = isset( $all[1] ) ? $all[1] : array();
        if ( 1 === count( $nums ) ) {
            $price = pps_paylink_parse_price( $nums[0] );
            $text  = preg_replace( '/(?:^|\s)' . preg_quote( $nums[0], '/' ) . '(?=$|\s|,)/', ' ', $text, 1 );
        } elseif ( count( $nums ) > 1 ) {
            return new WP_Error( 'ambiguous',
                'More than one number and no $ — write the price as $250 so it cannot be confused with a quantity.' );
        }
    }
    if ( null === $price || $price <= 0 ) {
        return new WP_Error( 'price', 'No price found. Write it as $250.' );
    }

    $description = trim( preg_replace( '/\s+/', ' ', $text ), " \t\n\r,;-" );
    if ( '' === $description ) {
        return new WP_Error( 'description', 'No job description found — say what the job is as well as the price.' );
    }

    return array(
        'description' => $description,
        'price'       => $price,
        'qbo'         => $qbo,
        'reference'   => $reference,
    );
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
            'No pay-link product is configured. Set one in WP Admin → PPS Calculators → Pay Links.'
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
        'token'      => pps_paylink_token( isset( $a['reference'] ) ? $a['reference'] : '' ),
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

/**
 * Record the SHAPE of a payload we could not read -- key names only, never
 * values. The point is to learn where a rule engine puts the text, not to keep
 * a copy of what was said in somebody's conversation.
 */
function pps_paylink_log_shape( array $body ) {
    $shape = array();
    foreach ( $body as $k => $v ) {
        $shape[] = $k . ':' . ( is_array( $v ) ? '{' . implode( ',', array_slice( array_keys( $v ), 0, 12 ) ) . '}' : gettype( $v ) );
    }
    $log = get_option( 'pps_paylink_shapes', array() );
    if ( ! is_array( $log ) ) $log = array();
    array_unshift( $log, array( 'at' => gmdate( 'c' ), 'keys' => array_slice( $shape, 0, 25 ) ) );
    update_option( 'pps_paylink_shapes', array_slice( $log, 0, 5 ), false );
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

    // A caller that sends the fields uses them. A rule engine that posts its
    // own envelope gets the command read out of whatever text it carried.
    if ( empty( $body['description'] ) || ! isset( $body['price'] ) || '' === $body['price'] ) {
        $text = pps_paylink_extract_text( $body );
        if ( '' === $text ) {
            // The one failure worth recording in full: we could not find the
            // operator's words anywhere in the payload, which is a one-line fix
            // to pps_paylink_extract_text() once the shape is known -- but only
            // if the shape was kept. Keys only; values may carry customer text.
            pps_paylink_log_shape( $body );
            return new WP_REST_Response( array(
                'ok' => false, 'error' => 'no_text',
                'message' => 'No description/price fields and no readable text in the payload. The keys received have been recorded on the Pay Links screen.',
            ), 400 );
        }
        $parsed = pps_paylink_parse_command( $text );
        if ( is_wp_error( $parsed ) ) {
            return new WP_REST_Response( array(
                'ok' => false, 'error' => $parsed->get_error_code(), 'message' => $parsed->get_error_message(),
            ), 400 );
        }
        $body = array_merge( $body, $parsed );
    }

    $res = pps_paylink_create( array(
        'description' => isset( $body['description'] ) ? $body['description'] : '',
        'price'       => isset( $body['price'] ) ? $body['price'] : '',
        'qty'         => isset( $body['qty'] ) ? $body['qty'] : 1,
        'qbo'         => isset( $body['qbo'] ) ? $body['qbo'] : false,
        'by'          => isset( $body['by'] ) ? $body['by'] : 'missive',
        'note'        => isset( $body['note'] ) ? $body['note'] : '',
        'reference'   => isset( $body['reference'] ) ? $body['reference'] : '',
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

/* ─────────────────────────────────────────────────────────────
 * Admin — configure, and mint one by hand to test
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'Pay Links',
        'Pay Links',
        'manage_options',
        'pps-pay-link',
        'pps_paylink_admin_page'
    );
}, 21 );

add_action( 'admin_post_pps_paylink_settings', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_paylink_settings' );
    $back = admin_url( 'admin.php?page=pps-pay-link' );

    update_option( PPS_PAYLINK_PRODUCT_OPT, absint( $_POST['product'] ?? 0 ), false );

    // Rotating the secret immediately breaks any caller still holding the old
    // one, which is the point — but it is not something to do by accident, so
    // it needs its own deliberate button rather than riding on a settings save.
    if ( ! empty( $_POST['regenerate'] ) ) {
        update_option( PPS_PAYLINK_SECRET_OPT, wp_generate_password( 40, false, false ), false );
        wp_safe_redirect( add_query_arg( 'pl_done', 'secret', $back ) ); exit;
    }

    wp_safe_redirect( add_query_arg( 'pl_done', 'saved', $back ) ); exit;
} );

add_action( 'admin_post_pps_paylink_mint', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_paylink_mint' );
    $back = admin_url( 'admin.php?page=pps-pay-link' );

    $res = pps_paylink_create( array(
        'description' => wp_unslash( $_POST['description'] ?? '' ),
        'price'       => wp_unslash( $_POST['price'] ?? '' ),
        'qty'         => $_POST['qty'] ?? 1,
        'qbo'         => ! empty( $_POST['qbo'] ),
        'reference'   => wp_unslash( $_POST['reference'] ?? '' ),
        'by'          => wp_get_current_user()->user_login,
    ) );

    if ( is_wp_error( $res ) ) {
        wp_safe_redirect( add_query_arg( 'pl_err', rawurlencode( $res->get_error_message() ), $back ) ); exit;
    }
    wp_safe_redirect( add_query_arg( array(
        'pl_link' => rawurlencode( $res['url'] ),
        'pl_back' => $res['qbo_fell_back'] ? '1' : '0',
    ), $back ) ); exit;
} );

function pps_paylink_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $done = isset( $_GET['pl_done'] ) ? sanitize_key( $_GET['pl_done'] ) : '';
    $err  = isset( $_GET['pl_err'] ) ? sanitize_text_field( wp_unslash( $_GET['pl_err'] ) ) : '';
    $link = isset( $_GET['pl_link'] ) ? esc_url_raw( wp_unslash( $_GET['pl_link'] ) ) : '';
    $fell = ! empty( $_GET['pl_back'] );

    $pid      = pps_paylink_product_id();
    $product  = $pid ? wc_get_product( $pid ) : null;
    $products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
    $qbo_ok   = pps_paylink_qbo_ready();
    ?>
    <div class="wrap" style="max-width:780px">
        <h1>Pay Links</h1>

        <?php if ( 'saved' === $done ) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
        <?php if ( 'secret' === $done ) : ?><div class="notice notice-warning"><p>New secret generated. Any caller still using the old one will now be refused.</p></div><?php endif; ?>
        <?php if ( $err ) : ?><div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div><?php endif; ?>
        <?php if ( $link ) : ?>
            <div class="notice notice-success">
                <p><strong>Link created.</strong> Send this to the customer:</p>
                <p><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $link ); ?>"></p>
                <?php if ( $fell ) : ?>
                    <p><em>QuickBooks was requested but is not ready, so this link uses card checkout.
                    Check the QuickBooks screen.</em></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p style="color:#50575e">Turns a job agreed in a conversation into a link. The customer opens it,
        enters their details, and pays — by card checkout, or through QuickBooks when that is switched on
        for the link. Nothing exists in WooCommerce until they submit, so links that go nowhere cost nothing.</p>

        <div class="notice notice-info inline"><p><strong>Predictable links are unlisted, not private.</strong>
        A timestamped or named link can be guessed, so treat what is behind it — the job description and the
        price — as readable by anyone who tries. The customer's email is masked on the page for that reason.
        Both forms here are guessable — blank gives the timestamp, not a random link. The console's full quote
        form still mints unguessable links, so use that for anything you would not want enumerated.</p></div>

        <h2>Mint a link</h2>
        <?php if ( ! $product ) : ?>
            <div class="notice notice-error inline"><p>Choose a product below first — a WooCommerce order
            line needs one, and links cannot be minted until it is set.</p></div>
        <?php else : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_paylink_mint' ); ?>
            <input type="hidden" name="action" value="pps_paylink_mint">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pl-desc">Description</label></th>
                    <td><textarea id="pl-desc" name="description" rows="3" class="large-text"
                        placeholder="500 postcards, 4/4, 16pt gloss cover"></textarea>
                        <p class="description">What the customer sees on the quote page, and what appears on the invoice line.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl-price">Price</label></th>
                    <td><input type="text" id="pl-price" name="price" class="regular-text" placeholder="250.00">
                        <p class="description">The total for the job. <code>$250</code> and <code>1,250.00</code> are both fine.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl-qty">Quantity</label></th>
                    <td><input type="number" id="pl-qty" name="qty" value="1" min="1" class="small-text">
                        <p class="description">Only affects how the line reads; the price above is the total either way.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl-ref">Reference</label></th>
                    <td><input type="text" id="pl-ref" name="reference" class="regular-text" placeholder="acme-october">
                        <p class="description">Optional. Leave blank and the link is stamped with the minute
                        it was created, so you can write it into a reply from a clock without waiting for
                        anything to hand it back:<br>
                        <code><?php echo esc_html( pps_quote_url( pps_paylink_token() ) ); ?></code><br>
                        A reference replaces the timestamp when you would rather the link read as something
                        you can say out loud.</p></td>
                </tr>
                <tr>
                    <th scope="row">QuickBooks</th>
                    <td>
                        <label><input type="checkbox" name="qbo" value="1" <?php disabled( ! $qbo_ok ); ?>>
                            Take payment through QuickBooks</label>
                        <?php if ( ! $qbo_ok ) : ?>
                            <p class="description">Unavailable — QuickBooks is not connected, has no service item,
                            or Payments has not been confirmed. See <a href="<?php echo esc_url( admin_url( 'admin.php?page=pps-qbo' ) ); ?>">the QuickBooks screen</a>.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Create link</button></p>
        </form>
        <?php endif; ?>

        <hr>
        <h2>Settings</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_paylink_settings' ); ?>
            <input type="hidden" name="action" value="pps_paylink_settings">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="pl-product">Line-item product</label></th>
                    <td>
                        <select id="pl-product" name="product">
                            <option value="0">— none —</option>
                            <?php foreach ( $products as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( $pid, $p->get_id() ); ?>>
                                    <?php echo esc_html( $p->get_name() ); ?><?php echo $p->is_virtual() ? '' : ' (not virtual)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Every conversation-minted job hangs off this one product; the
                        description rides on the line. A generic &ldquo;Custom Print Job&rdquo; is the intent.</p>
                        <?php if ( $product && ! $product->is_virtual() ) : ?>
                            <p class="description" style="color:#b32d2e"><strong>This product is not virtual.</strong>
                            WooCommerce will try to run its own shipping on the order, which can stop the
                            customer completing checkout. Mark it virtual — the quote page collects the
                            delivery address itself.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Endpoint</th>
                    <td><input type="text" class="large-text code" readonly onclick="this.select()"
                        value="<?php echo esc_attr( rest_url( 'pps/v1/pay-link' ) ); ?>">
                        <p class="description">POST JSON: <code>description</code>, <code>price</code>,
                        optional <code>qbo</code>, <code>qty</code>, <code>note</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row">Shared secret</th>
                    <td><input type="text" class="large-text code" readonly onclick="this.select()"
                        value="<?php echo esc_attr( pps_paylink_secret() ); ?>">
                        <p class="description">Send as an <code>X-PPS-Key</code> header, or <code>?k=</code>
                        on the URL when the caller cannot set headers.</p>
                        <p><label><input type="checkbox" name="regenerate" value="1"> Generate a new secret
                        (immediately refuses anything using the old one)</label></p></td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Save settings</button></p>
        </form>
    </div>
    <?php
}
