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
 *   /ppspay [Pads 3.74 x 8.27
 *            Color: Full Color / Full Color
 *            Paper: 80lb Matte Text
 *            10 pads of 50] $177.40
 *
 *   /ppspay *qbo [500 postcards, 16pt gloss] $250 #acme-october
 *
 * Every token carries a sigil, so nothing has to be inferred from position:
 * [ ] description, $ price, #reference, *qbo, !5 minimum production days.
 *
 * !5 means the job cannot be delivered sooner than five PRODUCTION days from
 * now, before transit is added on top. It opens the delivery-date picker on
 * the quote page with that floor enforced server-side — the min attribute on
 * a date input is a hint, not a constraint.
 *
 * BRACKETS ARE THE FIRM FORM
 * Everything between the first [ and the last ] is the description, verbatim.
 * Nothing inside is examined: a price, a quantity, a stray "qbo", a # — all of
 * it is just words the customer will read. Only what sits OUTSIDE the brackets
 * is parsed for the price and the flags. That removes the whole class of
 * question this used to have to guess at.
 *
 * WITHOUT BRACKETS
 * The older heuristic still applies, so a line typed the old way keeps working:
 * the $-prefixed amount is the price; with no $ and exactly one number that
 * number is the price; with no $ and several numbers it refuses. "500 postcards
 * $250" and "$250 500 postcards" are the same job, and a parser that took the
 * first number would bill $500 for one of them. A wrong price is worse than a
 * refusal.
 *
 * @return array|WP_Error {description, price, qbo, reference}
 */
function pps_paylink_parse_command( $text ) {
    $text = trim( (string) $text );
    if ( '' === $text ) return new WP_Error( 'empty', 'Nothing to read — send the job and its price.' );

    // Strip the command word so it cannot land in the description. The older
    // spellings still answer, because a link minted yesterday should not stop
    // working because the command was renamed.
    $text = preg_replace( '/^\s*\/?(ppspay|paylink|pay|quote)\b[:\s]*/i', '', $text, 1 );

    // ── The bracketed description, if there is one ───────────────────────
    // First [ to LAST ], and . matches newlines: a spec is one block, however
    // many lines it runs to.
    $bracketed = null;
    if ( false !== strpos( $text, '[' ) ) {
        if ( ! preg_match( '/\[(.*)\]/s', $text, $m ) ) {
            // An opened bracket that never closes is a typo, and guessing where
            // it ended would put half a spec on an invoice.
            return new WP_Error( 'unclosed',
                'That opens a [ but never closes it. Put the whole description inside [ ], with the price outside.' );
        }
        $bracketed = $m[1];
        $text = str_replace( $m[0], ' ', $text );
    }

    // ── Flags and price, from OUTSIDE the brackets only ──────────────────
    $reference = '';
    if ( preg_match( '/(?:^|\s)#([A-Za-z0-9_-]{2,60})\b/', $text, $m ) ) {
        $reference = $m[1];
        $text = str_replace( $m[0], ' ', $text );
    }

    // *qbo, matching the sigils the other tokens use: $ money, # reference,
    // * flag. The bare word still answers so nothing already typed breaks, and
    // both forms only match standing alone — a description saying
    // "quickbooks-style ledger books" must never route a payment.
    // !N — minimum production days before the job can be delivered. Parsed
    // before the price so its digits can never be read as money, for the same
    // reason #reference is.
    $min_days = 0;
    if ( preg_match( '/(?:^|\s)!\s*([0-9]{1,2})(?=$|\s)/', $text, $m ) ) {
        $min_days = (int) $m[1];
        $text = str_replace( $m[0], ' ', $text );
    }

    $qbo = false;
    if ( preg_match( '/(?:^|\s)\*?(qbo|quickbooks)(?=$|\s)/i', $text, $m ) ) {
        $qbo  = true;
        $text = preg_replace( '/(?:^|\s)\*?(qbo|quickbooks)(?=$|\s)/i', ' ', $text, 1 );
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
                null === $bracketed
                    ? 'More than one number and no $ — write the price as $250, or put the description in [ ] so only the price is left outside.'
                    : 'More than one number outside the brackets — the price should be the only thing there, written as $250.' );
        }
    }
    if ( null === $price || $price <= 0 ) {
        return new WP_Error( 'price', 'No price found. Write it as $250, outside the brackets.' );
    }

    // ── The description ──────────────────────────────────────────────────
    // Collapse runs of SPACES but keep the operator's line breaks: the quote
    // page renders the spec in a <pre> and the QuickBooks invoice line keeps
    // the newline, so structure typed here is structure both of them show.
    $description = ( null !== $bracketed ) ? $bracketed : $text;
    $description = preg_replace( '/[ \t]+/', ' ', $description );
    $description = preg_replace( '/[ \t]*\n[ \t]*/', "\n", $description );
    $description = preg_replace( '/\n{3,}/', "\n\n", $description );
    $description = trim( $description, " \t\n\r,;-" );

    if ( '' === $description ) {
        return new WP_Error( 'description',
            null === $bracketed
                ? 'No job description found — say what the job is as well as the price.'
                : 'The brackets are empty — put the job description inside them.' );
    }

    return array(
        'description' => $description,
        'price'       => $price,
        'qbo'         => $qbo,
        'reference'   => $reference,
        'min_days'    => $min_days,
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
    // A stated production minimum is what makes a delivery date meaningful, so
    // asking for one also turns the date picker on.
    $min_days = isset( $a['min_days'] ) ? max( 0, (int) $a['min_days'] ) : 0;

    $quote = pps_quote_create( array(
        'token'      => pps_paylink_token( isset( $a['reference'] ) ? $a['reference'] : '' ),
        'min_days'   => $min_days,
        'allow_date' => $min_days > 0,
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
 * Answering in the conversation
 * ───────────────────────────────────────────────────────────── */

/**
 * The conversation the command came from, if the payload names one.
 * Mirrors pps_assistant_missive_extract() for the same reason it exists.
 */
function pps_paylink_extract_conversation( $payload ) {
    if ( ! is_array( $payload ) ) return '';
    $nodes = array( $payload );
    foreach ( array( 'message', 'post', 'draft', 'data', 'comment' ) as $k ) {
        if ( isset( $payload[ $k ] ) && is_array( $payload[ $k ] ) ) $nodes[] = $payload[ $k ];
    }
    foreach ( $nodes as $n ) {
        if ( ! empty( $n['conversation'] ) && is_string( $n['conversation'] ) ) return $n['conversation'];
        if ( ! empty( $n['conversation']['id'] ) ) return (string) $n['conversation']['id'];
        if ( ! empty( $n['conversation_id'] ) ) return (string) $n['conversation_id'];
    }
    return '';
}

/**
 * Post an internal note back into the thread.
 *
 * WHY THIS EXISTS EVEN THOUGH THE LINK IS PREDICTABLE
 * On the happy path the operator already knows the link, so this is only a
 * convenience. On the UNHAPPY path it is the whole safety net: without it a
 * refused mint is completely silent in Missive, and the operator pastes a
 * timestamp link that 404s to a customer, having done nothing wrong that they
 * could see. A note costs one API call and turns a silent failure into a
 * visible one.
 *
 * Internal note, never a customer-facing message: this is shop-floor
 * information, and the thread may well be open in front of the buyer.
 *
 * Best effort by design — a failure here must never fail the mint. The link
 * exists either way, and the operator can still type it.
 */
/**
 * Queue a note to go out AFTER the response has been produced.
 *
 * The note is an outbound HTTPS call to Missive, and it used to happen inline,
 * which put Missive's own API latency inside our acknowledgement of Missive's
 * webhook. A sender that gives up before we answer records "failed to send
 * webhook" and may retry — for work we had already done. Decoupling the two
 * means the ack is as fast as the database, and a slow or unreachable Missive
 * costs a note rather than a delivery.
 *
 * Best effort remains best effort: if shutdown never runs, the link still
 * exists and is still in the response body.
 */
function pps_paylink_queue_note( $conversation_id, $text ) {
    if ( ! $conversation_id ) return;
    add_action( 'shutdown', function () use ( $conversation_id, $text ) {
        pps_paylink_missive_note( $conversation_id, $text );
    }, 20 );
}

function pps_paylink_missive_note( $conversation_id, $text ) {
    if ( ! $conversation_id ) return false;
    if ( ! function_exists( 'pps_assistant_config' ) ) return false;

    $cfg   = pps_assistant_config();
    $token = (string) ( $cfg['missive_token'] ?? '' );
    if ( '' === $token ) return false;

    $payload = array( 'posts' => array(
        'conversation' => (string) $conversation_id,
        'notification' => array( 'title' => 'Pay link', 'body' => wp_trim_words( (string) $text, 12 ) ),
        'text'         => (string) $text,
    ) );

    $res = wp_remote_post( 'https://public.missiveapp.com/v1/posts', array(
        'timeout' => 8,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( $payload ),
    ) );
    if ( is_wp_error( $res ) ) {
        pps_paylink_log_note( 'note_failed', $res->get_error_message() );
        return false;
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    $ok   = $code >= 200 && $code < 300;
    pps_paylink_log_note( $ok ? 'note_ok' : 'note_failed', 'HTTP ' . $code );
    return $ok;
}

/**
 * Record the OUTCOME of every authenticated request.
 *
 * Diagnostics used to depend on a note being postable: an authenticated
 * request that was refused with no conversation id in its payload left no
 * trace anywhere -- not in the rejects log (auth passed), not in the shapes
 * log (text was found), not in the notes log (nothing was posted). "It did
 * not mint" was then unanswerable.
 *
 * Shape only, never content: the job text is the operator's words about a
 * customer's work and does not belong in an options row. What is kept is
 * enough to tell a parse refusal from a mint refusal from a silent success.
 */
function pps_paylink_log_outcome( $outcome, array $facts = array() ) {
    $log = get_option( 'pps_paylink_outcomes', array() );
    if ( ! is_array( $log ) ) $log = array();
    array_unshift( $log, array( 'at' => gmdate( 'c' ), 'outcome' => (string) $outcome ) + $facts );
    update_option( 'pps_paylink_outcomes', array_slice( $log, 0, 20 ), false );
}

/** Whether the note went out, kept for the admin screen. No message bodies. */
function pps_paylink_log_note( $event, $detail = '' ) {
    $log = get_option( 'pps_paylink_notes', array() );
    if ( ! is_array( $log ) ) $log = array();
    array_unshift( $log, array( 'at' => gmdate( 'c' ), 'event' => (string) $event, 'detail' => (string) $detail ) );
    update_option( 'pps_paylink_notes', array_slice( $log, 0, 10 ), false );
}

/* ─────────────────────────────────────────────────────────────
 * REST route
 * ───────────────────────────────────────────────────────────── */

/** The signing secret, when the caller signs instead of presenting a key. */
function pps_paylink_sig_secret() { return (string) get_option( 'pps_paylink_sig_secret', '' ); }

/**
 * Does any signature header on this request prove knowledge of the signing
 * secret?
 *
 * WHY THIS IS WORTH HAVING OVER ?k=
 * A shared key in the query string is written to every access log it passes
 * through, and anyone who can read those logs can mint links. A signature
 * never puts the secret on the wire, and covers the body as well — a request
 * altered in flight stops verifying.
 *
 * WHY IT IS WRITTEN SO LOOSELY
 * Missive 403s automated fetches of its own documentation (see
 * pps-assistant-missive.php), so the header name and digest encoding are not
 * something that could be looked up. Rather than guess one and fail silently,
 * every header that looks like a signature is tried against both encodings.
 * This costs nothing — only a correct HMAC passes either way — and the header
 * NAMES of a failed attempt are recorded so the first miss is a one-line fix.
 *
 * The raw body must be hashed: re-encoding parsed JSON changes the bytes and
 * every signature fails.
 */
function pps_paylink_signature_ok( WP_REST_Request $request ) {
    $secret = pps_paylink_sig_secret();
    if ( '' === $secret ) return false;

    $raw = (string) $request->get_body();
    $hex = hash_hmac( 'sha256', $raw, $secret );
    $b64 = base64_encode( hash_hmac( 'sha256', $raw, $secret, true ) );

    foreach ( (array) $request->get_headers() as $name => $values ) {
        // Only headers that plausibly carry a signature, so an unrelated header
        // that happens to equal the digest cannot authenticate a request.
        if ( ! preg_match( '/sign|hmac|digest/i', (string) $name ) ) continue;
        foreach ( (array) $values as $v ) {
            $v = trim( (string) $v );
            if ( '' === $v ) continue;
            // "sha256=..." is a common wrapper; compare the bare digest too.
            $bare = preg_replace( '/^sha256[=:]\s*/i', '', $v );
            foreach ( array( $v, $bare ) as $candidate ) {
                if ( hash_equals( $hex, $candidate ) || hash_equals( $b64, $candidate ) ) return true;
            }
        }
    }
    return false;
}

/**
 * Authenticate a call.
 *
 * Either a signature (preferred — the secret never travels) or a shared key
 * from an X-PPS-Key header or ?k= query parameter. Both are offered because
 * not every rule engine can set headers, and a caller that silently drops one
 * is indistinguishable from a wrong secret without the reject log.
 */
function pps_paylink_authorized( WP_REST_Request $request ) {
    if ( pps_paylink_signature_ok( $request ) ) return true;

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
        // Names only. Which signature headers arrived is the whole question
        // when a signing secret is configured and nothing verified.
        'sig_configured'     => '' !== pps_paylink_sig_secret(),
        'header_names'       => array_slice( array_keys( (array) $request->get_headers() ), 0, 30 ),
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
        // GET answers as a reachability probe, the same reason
        // pps-assistant-webhook.php accepts one: when a rule engine reports
        // "failed to send webhook" with no detail, the first thing worth
        // knowing is whether anything can reach the route at all. Without a
        // probe that question cannot be separated from "the body was refused".
        'methods'             => array( 'GET', 'POST' ),
        // Open on purpose, with the real check inside the handler: a
        // permission_callback refuses before anything can be recorded, which
        // is how the Missive webhook once produced a 401 nobody could explain.
        'permission_callback' => '__return_true',
        'callback'            => 'pps_paylink_handle_request',
    ) );
} );

function pps_paylink_handle_request( WP_REST_Request $request ) {
    // Reachability only. Deliberately unauthenticated and deliberately empty:
    // it confirms the route is alive and says nothing about how it is
    // configured, who may call it, or what it has minted. The route's
    // existence is already implied by anyone holding the webhook URL.
    if ( 'GET' === $request->get_method() ) {
        return new WP_REST_Response( array(
            'ok'      => true,
            'service' => 'pps-pay-link',
            'method'  => 'POST to create a link',
        ), 200 );
    }

    if ( ! pps_paylink_authorized( $request ) ) {
        pps_paylink_log_reject( $request );
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
    }

    $body = $request->get_json_params();
    if ( ! is_array( $body ) ) $body = $request->get_body_params();
    if ( ! is_array( $body ) ) $body = array();

    // Held from here on so a REFUSAL can be reported too, not just a success.
    $conversation = pps_paylink_extract_conversation( $body );

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
            pps_paylink_log_outcome( 'no_text', array( 'conversation' => '' !== $conversation ) );
            return new WP_REST_Response( array(
                'ok' => false, 'error' => 'no_text',
                'message' => 'No description/price fields and no readable text in the payload. The keys received have been recorded on the Pay Links screen.',
            ), 400 );
        }
        $parsed = pps_paylink_parse_command( $text );
        if ( is_wp_error( $parsed ) ) {
            pps_paylink_log_outcome( 'parse_refused', array(
                'error'        => $parsed->get_error_code(),
                'text_len'     => strlen( $text ),
                'has_brackets' => ( false !== strpos( $text, '[' ) ) && ( false !== strpos( $text, ']' ) ),
                'has_dollar'   => ( false !== strpos( $text, '$' ) ),
                'conversation' => '' !== $conversation,
            ) );
            pps_paylink_queue_note( $conversation,
                "⚠️ No pay link was created.\n\n" . $parsed->get_error_message() );
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
        'min_days'    => isset( $body['min_days'] ) ? $body['min_days'] : 0,
    ) );

    if ( is_wp_error( $res ) ) {
        pps_paylink_log_outcome( 'mint_refused', array(
            'error'        => $res->get_error_code(),
            'conversation' => '' !== $conversation,
        ) );
        pps_paylink_queue_note( $conversation,
            "⚠️ No pay link was created.\n\n" . $res->get_error_message() );
        return new WP_REST_Response( array(
            'ok'    => false,
            'error' => $res->get_error_code(),
            // Safe to return: these are the operator's own validation messages,
            // and the caller is already authenticated by this point.
            'message' => $res->get_error_message(),
        ), 400 );
    }

    // The link is knowable without this, but seeing it confirms it was actually
    // minted — and says so when QuickBooks was asked for and could not be given,
    // which the operator would otherwise discover from the customer.
    $note = $res['url'];
    if ( ! empty( $res['qbo_fell_back'] ) ) {
        $note .= "\n\n⚠️ QuickBooks was requested but is not ready, so this link uses card checkout.";
    }
    pps_paylink_log_outcome( 'minted', array(
        'token'        => $res['token'],
        'pay_source'   => $res['pay_source'],
        'conversation' => '' !== $conversation,
    ) );
    pps_paylink_queue_note( $conversation, $note );

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

    // Only overwrite the signing secret when something was typed, so re-saving
    // the form does not silently disable signature checking.
    $sig = trim( (string) wp_unslash( $_POST['sig_secret'] ?? '' ) );
    if ( '' !== $sig ) update_option( 'pps_paylink_sig_secret', $sig, false );
    if ( ! empty( $_POST['clear_sig'] ) ) delete_option( 'pps_paylink_sig_secret' );

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

        <?php $outcomes = get_option( 'pps_paylink_outcomes', array() ); ?>
        <?php if ( is_array( $outcomes ) && $outcomes ) : ?>
            <hr>
            <h2>Recent requests</h2>
            <p class="description">Every authenticated call, and what became of it. If a command
            produced nothing at all and does not appear here, it never reached the site — look at
            the rule, or at anything between it and WordPress, rather than at the command.</p>
            <table class="widefat striped"><tbody>
            <?php foreach ( array_slice( $outcomes, 0, 12 ) as $row ) : ?>
                <tr>
                    <td style="width:180px"><?php echo esc_html( $row['at'] ?? '' ); ?></td>
                    <td style="width:120px"><code><?php echo esc_html( $row['outcome'] ?? '' ); ?></code></td>
                    <td><?php
                        $rest = $row; unset( $rest['at'], $rest['outcome'] );
                        echo esc_html( $rest ? wp_json_encode( $rest ) : '' );
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
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
                    <th scope="row">Command format</th>
                    <td>
                        <p class="description" style="margin-top:0">What an operator types in a conversation.
                        Every part carries a sigil, so nothing depends on word order:</p>
                        <pre style="background:#f6f7f7;padding:10px;border-left:3px solid #c3c4c7;overflow-x:auto;margin:6px 0"><code>/ppspay [Pads 3.74 &times; 8.27
Color: Full Color / Full Color
Paper: 80lb Matte Text
10 pads of 50] $177.40

/ppspay *qbo [500 postcards, 16pt gloss] $250 #acme-october</code></pre>
                        <p class="description">
                        <code>[&nbsp;]</code> the description, kept exactly as typed — line breaks and all.
                        Nothing inside is read as a command, so a spec may mention a price or a quantity freely.<br>
                        <code>$177.40</code> the price. Must sit outside the brackets.<br>
                        <code>*qbo</code> take payment through QuickBooks. Omit for card checkout.<br>
                        <code>#acme-october</code> use this instead of the timestamp in the link.</p>
                        <p class="description">Without a reference the link is the minute it was created, in site
                        time (<?php echo esc_html( wp_timezone_string() ); ?>) — so it can be written into a reply
                        from a clock rather than waited for.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Shared secret</th>
                    <td><input type="text" class="large-text code" readonly onclick="this.select()"
                        value="<?php echo esc_attr( pps_paylink_secret() ); ?>">
                        <p class="description">Send as an <code>X-PPS-Key</code> header, or <code>?k=</code>
                        on the URL when the caller cannot set headers.
                        <strong>A key in the URL is written to access logs</strong>, so prefer the signing
                        secret below where the caller supports it.</p>
                        <p><label><input type="checkbox" name="regenerate" value="1"> Generate a new secret
                        (immediately refuses anything using the old one)</label></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl-sig">Signing secret</label></th>
                    <td><input type="password" id="pl-sig" name="sig_secret" class="large-text code"
                        placeholder="<?php echo pps_paylink_sig_secret() ? 'stored — leave blank to keep' : 'optional'; ?>">
                        <p class="description">Paste the same value into the rule's signature-secret field.
                        The caller then signs each request instead of carrying a key, so the secret never
                        appears in a URL and a request altered in flight stops verifying.</p>
                        <p class="description">HMAC-SHA256 of the raw body. Both hex and base64 digests are
                        accepted, on any header whose name mentions <code>sign</code>, <code>hmac</code> or
                        <code>digest</code> — the exact scheme is not documented publicly, so if the first
                        call is refused the header names it sent are listed below.</p>
                        <?php if ( pps_paylink_sig_secret() ) : ?>
                            <p><label><input type="checkbox" name="clear_sig" value="1"> Remove the signing
                            secret (falls back to the shared key)</label></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary">Save settings</button></p>
        </form>
    </div>
    <?php
}
