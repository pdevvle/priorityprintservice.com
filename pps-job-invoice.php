<?php
/**
 * PPS Job Invoice — quote a job sold over email, hand the customer a payment
 * link, and have the finished sale land in their /reorders history.
 *
 * Two ways to get paid, because the shop already had two:
 *
 *   site        the order opens pending, which gives it a WooCommerce
 *               customer-pay URL; the customer pays with the same checkout
 *               the website uses and WooCommerce marks the order itself.
 *   quickbooks  an external QuickBooks Online payment link, pasted in here.
 *               QBO cannot tell WooCommerce anything, so the order stays
 *               pending until somebody presses Mark paid — that button is
 *               the whole reason the console lists unpaid invoices.
 *
 * Either way the order is a real WooCommerce order, so once paid it behaves
 * like any web order: it appears in the customer's /reorders history with the
 * frozen-price "Reorder (same as before)" button at exactly the quoted figure.
 *
 * Two front doors onto the same creator (pps_job_invoice_create) so the two
 * cannot drift: the wp-admin screen, and a password-gated console at a public
 * URL for quoting from a phone without signing in to WordPress.
 *
 * Deliberately not wired into production automation: no PPS-Spec, no
 * production start, no Drive folder. This sells a job; the calculator remains
 * the route for anything needing artwork intake.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PPS_JOB_INVOICE_VIA = 'pps-job-invoice';

/* ─────────────────────────────────────────────────────────────
 * Shared creator — the one implementation both surfaces call
 * ───────────────────────────────────────────────────────────── */

/**
 * @param array $a first,last,email,phone,product,qty,total,specs,note,
 *                 pay_source ('site'|'quickbooks'), pay_link, send_invoice (bool), by (string)
 * @return WC_Order|WP_Error
 */
function pps_job_invoice_create( array $a ) {
    $email = isset( $a['email'] ) ? sanitize_email( $a['email'] ) : '';
    $pid   = isset( $a['product'] ) ? absint( $a['product'] ) : 0;
    $qty   = isset( $a['qty'] ) ? max( 1, absint( $a['qty'] ) ) : 1;
    $total = isset( $a['total'] ) ? round( (float) $a['total'], 2 ) : 0;

    // The billing email is the lookup key AND where an invoice would go —
    // without it this produces an order nobody can find or pay.
    if ( ! $email || ! is_email( $email ) ) {
        return new WP_Error( 'email', 'A valid customer email is required — it is how the reorder lookup finds the order.' );
    }
    $product = $pid ? wc_get_product( $pid ) : false;
    if ( ! $product || ! $product->exists() ) {
        return new WP_Error( 'product', 'Pick a product for the line item.' );
    }
    if ( $total <= 0 ) {
        return new WP_Error( 'total', 'Enter the price quoted (must be more than $0).' );
    }

    $source = ( isset( $a['pay_source'] ) && 'quickbooks' === $a['pay_source'] ) ? 'quickbooks' : 'site';
    $link   = isset( $a['pay_link'] ) ? esc_url_raw( trim( (string) $a['pay_link'] ) ) : '';
    if ( 'quickbooks' === $source ) {
        // A half-entered QBO invoice would leave the customer with a Pay now
        // button that goes nowhere, so refuse rather than fall back silently.
        if ( ! $link || ! wp_http_validate_url( $link ) || 0 !== stripos( $link, 'https://' ) ) {
            return new WP_Error( 'paylink', 'Paste the full https:// QuickBooks payment link, or switch to card checkout.' );
        }
    } else {
        $link = '';
    }

    $order = wc_create_order( array( 'customer_id' => 0 ) );
    if ( is_wp_error( $order ) ) {
        return new WP_Error( 'create', 'WooCommerce refused to create the order — check the error logs.' );
    }

    $order->set_created_via( PPS_JOB_INVOICE_VIA );
    $order->set_billing_first_name( isset( $a['first'] ) ? sanitize_text_field( $a['first'] ) : '' );
    $order->set_billing_last_name( isset( $a['last'] ) ? sanitize_text_field( $a['last'] ) : '' );
    $order->set_billing_email( $email );
    if ( ! empty( $a['phone'] ) ) $order->set_billing_phone( sanitize_text_field( $a['phone'] ) );

    // The line's subtotal is what the frozen-price reorder divides by qty, so
    // the figure quoted here is the price a future reorder will carry.
    $item_id = $order->add_product( $product, $qty, array( 'subtotal' => $total, 'total' => $total ) );
    $specs   = isset( $a['specs'] ) ? sanitize_textarea_field( $a['specs'] ) : '';
    if ( $specs && $item_id ) {
        $item = $order->get_item( $item_id );
        if ( $item ) {
            // Visible (non-underscore) meta: the lookup card renders it as the
            // spec lines, and Contact Us carries it in the message payload.
            $item->add_meta_data( 'Specs', $specs, true );
            $item->save();
        }
    }

    $by = isset( $a['by'] ) ? sanitize_text_field( $a['by'] ) : 'console';
    $order->update_meta_data( '_pps_job_invoice_by', $by );
    $order->update_meta_data( '_pps_pay_source', $source );
    if ( $link ) $order->update_meta_data( '_pps_pay_link', $link );

    if ( 'quickbooks' === $source ) {
        $order->set_payment_method( 'pps_quickbooks' );
        $order->set_payment_method_title( 'QuickBooks payment link' );
    }

    $order->calculate_totals( false );

    // Pending is what makes this a payable invoice rather than a record: it is
    // the status WooCommerce hands a customer-pay URL for, and the one the
    // console lists as still owing.
    $order->set_status( 'pending' );
    $order->save();

    $order->add_order_note( sprintf(
        'Job invoiced by %s — awaiting payment via %s.%s',
        $by,
        'quickbooks' === $source ? 'QuickBooks link' : 'site checkout',
        ! empty( $a['note'] ) ? "\n" . sanitize_textarea_field( $a['note'] ) : ''
    ) );

    // WooCommerce's invoice email carries the SITE checkout link. Sending it
    // for a QuickBooks job would hand the customer a second, contradictory way
    // to pay, so that combination is refused rather than quietly allowed.
    if ( ! empty( $a['send_invoice'] ) && 'site' === $source && function_exists( 'WC' ) && WC()->mailer() ) {
        $mails = WC()->mailer()->get_emails();
        if ( isset( $mails['WC_Email_Customer_Invoice'] ) ) {
            $mails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
            $order->add_order_note( 'Invoice email sent to ' . $email . '.' );
        }
    }

    return $order;
}

/** Statuses where money is still owed. WooCommerce's own needs_payment() covers
 *  pending and failed but not on-hold, and an on-hold invoice is still unpaid. */
function pps_job_invoice_is_unpaid( $order ) {
    return $order && in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true );
}

/**
 * The link to give the customer: the QuickBooks one when set, else
 * WooCommerce's own. Empty once the order is settled — an external link lives
 * in meta forever, so without the unpaid check a paid job would keep showing a
 * live Pay now button and could be paid twice.
 */
function pps_job_invoice_pay_link( $order ) {
    if ( ! $order || ! pps_job_invoice_is_unpaid( $order ) ) return '';
    $ext = (string) $order->get_meta( '_pps_pay_link' );
    if ( $ext ) return $ext;
    return $order->needs_payment() ? $order->get_checkout_payment_url() : '';
}

/** Mark an externally-paid invoice as paid. Returns true when it moved. */
function pps_job_invoice_mark_paid( $order, $by ) {
    if ( ! $order ) return false;
    if ( ! pps_job_invoice_is_unpaid( $order ) ) return false;
    $src = (string) $order->get_meta( '_pps_pay_source' );
    $order->update_meta_data( '_pps_paid_offsite_by', sanitize_text_field( $by ) );
    $order->update_meta_data( '_pps_paid_offsite_at', current_time( 'mysql' ) );
    $order->add_order_note( sprintf(
        'Marked paid by %s — payment received via %s.',
        sanitize_text_field( $by ),
        'quickbooks' === $src ? 'QuickBooks' : 'an external channel'
    ) );
    $order->set_status( 'processing' );
    $order->save();
    return true;
}

/** Unpaid invoices this tool created, newest first. */
function pps_job_invoice_unpaid( $limit = 25 ) {
    $orders = wc_get_orders( array(
        'limit'   => 60,
        'status'  => array( 'pending', 'on-hold', 'failed' ),
        'orderby' => 'date',
        'order'   => 'DESC',
        'type'    => 'shop_order',
    ) );
    $out = array();
    foreach ( $orders as $o ) {
        if ( PPS_JOB_INVOICE_VIA !== $o->get_created_via() ) continue;
        $out[] = $o;
        if ( count( $out ) >= $limit ) break;
    }
    return $out;
}

/**
 * The repeating QTY/PRICE rows, as posted by either surface. Empty rows are
 * normal — the form ships a few blanks so there is always one to type into.
 */
function pps_job_console_posted_tiers() {
    $qs = isset( $_POST['q_qty'] ) ? (array) wp_unslash( $_POST['q_qty'] ) : array();
    $ps = isset( $_POST['q_price'] ) ? (array) wp_unslash( $_POST['q_price'] ) : array();
    $out = array();
    foreach ( $qs as $i => $qv ) {
        $out[] = array( 'qty' => $qv, 'price' => isset( $ps[ $i ] ) ? $ps[ $i ] : 0 );
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
 * Console authentication (public page, no WordPress login)
 * ───────────────────────────────────────────────────────────── */

/**
 * Stored as a bcrypt hash in wp_options — never in source, since this repo is
 * public. Set it from the admin screen; rotating it invalidates every open
 * console session automatically, because the cookie signature is bound to it.
 */
function pps_console_hash() { return (string) get_option( 'pps_console_key', '' ); }

function pps_console_rate_key() {
    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'pps';
    return 'pps_con_' . substr( hash( 'sha256', $ip . $salt ), 0, 24 );
}
function pps_console_is_locked() { return (int) get_transient( pps_console_rate_key() ) >= 5; }
function pps_console_record_fail() {
    $k = pps_console_rate_key();
    set_transient( $k, (int) get_transient( $k ) + 1, 15 * MINUTE_IN_SECONDS );
}

function pps_console_sign( $exp ) {
    return hash_hmac( 'sha256', $exp . '|' . pps_console_hash(), wp_salt( 'auth' ) );
}

function pps_console_grant() {
    $exp = time() + 8 * HOUR_IN_SECONDS;
    setcookie( 'pps_console', $exp . '|' . pps_console_sign( $exp ), array(
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Strict',
    ) );
    $_COOKIE['pps_console'] = $exp . '|' . pps_console_sign( $exp );
}

function pps_console_revoke() {
    setcookie( 'pps_console', '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
    unset( $_COOKIE['pps_console'] );
}

function pps_console_is_authed() {
    // A WordPress admin already has every right this console grants.
    if ( current_user_can( 'manage_options' ) ) return true;
    if ( ! pps_console_hash() ) return false;
    $raw = isset( $_COOKIE['pps_console'] ) ? (string) $_COOKIE['pps_console'] : '';
    if ( ! $raw || strpos( $raw, '|' ) === false ) return false;
    list( $exp, $sig ) = explode( '|', $raw, 2 );
    if ( ! ctype_digit( (string) $exp ) || (int) $exp < time() ) return false;
    return hash_equals( pps_console_sign( (int) $exp ), $sig );
}

/* ─────────────────────────────────────────────────────────────
 * Console page — [pps_job_console]
 * ───────────────────────────────────────────────────────────── */

add_shortcode( 'pps_job_console', 'pps_job_console_shortcode' );

function pps_job_console_shortcode() {
    // Never cache and never index: this page takes a password and lists
    // customer contact details.
    nocache_headers();
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    add_filter( 'wp_robots', 'wp_robots_no_robots' );

    $notice = '';
    $error  = '';

    // ── auth actions ──
    if ( isset( $_POST['pps_console_login'] ) ) {
        if ( pps_console_is_locked() ) {
            $error = 'Too many attempts. Try again in 15 minutes.';
        } elseif ( ! pps_console_hash() ) {
            $error = 'No console password has been set yet. Set one in WP Admin → PPS Calculators → Invoice a Job.';
        } else {
            $pw = isset( $_POST['pps_console_pw'] ) ? (string) wp_unslash( $_POST['pps_console_pw'] ) : '';
            if ( $pw !== '' && password_verify( $pw, pps_console_hash() ) ) {
                delete_transient( pps_console_rate_key() );
                pps_console_grant();
            } else {
                pps_console_record_fail();
                $error = 'Wrong password.';
            }
        }
    }
    if ( isset( $_POST['pps_console_logout'] ) ) {
        pps_console_revoke();
    }

    if ( ! pps_console_is_authed() ) {
        return pps_job_console_login_form( $error );
    }

    // ── authenticated actions ──
    $created = null;
    $quote   = null;
    if ( isset( $_POST['pps_console_quote'] ) && check_admin_referer( 'pps_console_action', 'pps_console_nonce' )
         && function_exists( 'pps_quote_create' ) ) {
        $res = pps_quote_create( array(
            'product'    => isset( $_POST['q_product'] ) ? $_POST['q_product'] : 0,
            'specs'      => isset( $_POST['q_specs'] ) ? wp_unslash( $_POST['q_specs'] ) : '',
            'tiers'      => pps_job_console_posted_tiers(),
            'allow_date' => ! empty( $_POST['q_allow_date'] ),
            'min_days'   => isset( $_POST['q_min_days'] ) ? $_POST['q_min_days'] : 0,
            'pay_source' => isset( $_POST['q_pay_source'] ) ? wp_unslash( $_POST['q_pay_source'] ) : 'site',
            'pay_link'   => isset( $_POST['q_pay_link'] ) ? wp_unslash( $_POST['q_pay_link'] ) : '',
            'note'       => isset( $_POST['q_note'] ) ? wp_unslash( $_POST['q_note'] ) : '',
            'by'         => 'console',
        ) );
        if ( is_wp_error( $res ) ) $error = $res->get_error_message();
        else $quote = $res;
    }
    if ( isset( $_POST['pps_console_create'] ) && check_admin_referer( 'pps_console_action', 'pps_console_nonce' ) ) {
        $res = pps_job_invoice_create( array(
            'first'        => isset( $_POST['inv_first'] ) ? wp_unslash( $_POST['inv_first'] ) : '',
            'last'         => isset( $_POST['inv_last'] ) ? wp_unslash( $_POST['inv_last'] ) : '',
            'email'        => isset( $_POST['inv_email'] ) ? wp_unslash( $_POST['inv_email'] ) : '',
            'phone'        => isset( $_POST['inv_phone'] ) ? wp_unslash( $_POST['inv_phone'] ) : '',
            'product'      => isset( $_POST['inv_product'] ) ? $_POST['inv_product'] : 0,
            'qty'          => isset( $_POST['inv_qty'] ) ? $_POST['inv_qty'] : 1,
            'total'        => isset( $_POST['inv_total'] ) ? wp_unslash( $_POST['inv_total'] ) : 0,
            'specs'        => isset( $_POST['inv_specs'] ) ? wp_unslash( $_POST['inv_specs'] ) : '',
            'note'         => isset( $_POST['inv_note'] ) ? wp_unslash( $_POST['inv_note'] ) : '',
            'pay_source'   => isset( $_POST['inv_pay_source'] ) ? wp_unslash( $_POST['inv_pay_source'] ) : 'site',
            'pay_link'     => isset( $_POST['inv_pay_link'] ) ? wp_unslash( $_POST['inv_pay_link'] ) : '',
            'send_invoice' => ! empty( $_POST['inv_send_invoice'] ),
            'by'           => 'console',
        ) );
        if ( is_wp_error( $res ) ) $error = $res->get_error_message();
        else $created = $res;
    }
    if ( isset( $_POST['pps_console_paid'] ) && check_admin_referer( 'pps_console_action', 'pps_console_nonce' ) ) {
        $oid = absint( $_POST['pps_console_paid'] );
        $o   = $oid ? wc_get_order( $oid ) : null;
        if ( $o && PPS_JOB_INVOICE_VIA === $o->get_created_via() && pps_job_invoice_mark_paid( $o, 'console' ) ) {
            $notice = 'Order #' . $o->get_order_number() . ' marked paid.';
        } else {
            $error = 'Could not mark that order paid.';
        }
    }

    return pps_job_console_body( $created, $notice, $error, $quote );
}

function pps_job_console_login_form( $error ) {
    ob_start(); ?>
    <div class="pps-acct"><div class="lookup-shell">
        <h2 class="h-page" style="text-align:center">Job Console</h2>
        <p class="h-sub" style="text-align:center">Enter the console password.</p>
        <form method="post" class="form">
            <?php if ( $error ) : ?>
                <div class="banner banner-error"><div><strong><?php echo esc_html( $error ); ?></strong></div></div>
            <?php endif; ?>
            <div class="field">
                <label for="pps-con-pw">Password</label>
                <input type="password" id="pps-con-pw" name="pps_console_pw" autocomplete="current-password" required autofocus>
            </div>
            <button type="submit" name="pps_console_login" value="1" class="btn btn-primary btn-submit">Unlock</button>
        </form>
    </div></div>
    <?php return ob_get_clean();
}

function pps_job_console_body( $created, $notice, $error, $quote = null ) {
    $products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
    $unpaid   = pps_job_invoice_unpaid( 25 );
    ob_start(); ?>
    <div class="pps-acct"><div style="max-width:760px;margin:0 auto">
        <div class="auth-strip">
            <div><strong>Job Console</strong></div>
            <form method="post" style="margin:0">
                <button type="submit" name="pps_console_logout" value="1" class="btn btn-ghost" style="padding:6px 12px;font-size:12px">Lock</button>
            </form>
        </div>

        <?php if ( $notice ) : ?><div class="banner banner-success"><div><?php echo esc_html( $notice ); ?></div></div><?php endif; ?>
        <?php if ( $error ) : ?><div class="banner banner-error"><div><strong><?php echo esc_html( $error ); ?></strong></div></div><?php endif; ?>

        <?php if ( $created ) :
            $link = pps_job_invoice_pay_link( $created );
            $qbo  = 'quickbooks' === (string) $created->get_meta( '_pps_pay_source' ); ?>
            <div class="con-done">
                <div class="con-done-head">Order #<?php echo esc_html( $created->get_order_number() ); ?> created — awaiting payment</div>
                <?php if ( $link ) : ?>
                    <label class="con-lbl" for="con-link"><?php echo $qbo ? 'QuickBooks payment link' : 'Payment link'; ?></label>
                    <div class="con-copy">
                        <input type="text" id="con-link" readonly value="<?php echo esc_attr( $link ); ?>" onfocus="this.select()">
                        <button type="button" class="btn btn-primary" data-copy="con-link">Copy</button>
                    </div>
                    <p class="con-hint"><?php echo $qbo
                        ? 'Send this to the customer, then press Mark paid below once QuickBooks shows the payment.'
                        : 'Paste into your reply. WooCommerce marks the order paid by itself.'; ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $quote && function_exists( 'pps_quote_url' ) ) :
            $qurl = pps_quote_url( get_post_meta( $quote, '_q_token', true ) ); ?>
            <div class="con-done">
                <div class="con-done-head">Quote link ready</div>
                <div class="con-copy">
                    <input type="text" id="con-qlink" readonly value="<?php echo esc_attr( $qurl ); ?>" onfocus="this.select()">
                    <button type="button" class="btn btn-primary" data-copy="con-qlink">Copy</button>
                </div>
                <p class="con-hint">Send this to the customer. They choose a quantity, enter their own
                billing and shipping, and pay — the order appears below only once they submit.</p>
            </div>
        <?php endif; ?>

        <form method="post" class="form con-form">
            <?php wp_nonce_field( 'pps_console_action', 'pps_console_nonce' ); ?>
            <h3 class="con-h">New quote link</h3>
            <p class="form-intro" style="margin-bottom:14px">The job only — no customer details.
            They fill those in themselves when they open the link.</p>

            <div class="field"><label for="q-product">Product <span class="req">*</span></label>
                <select id="q-product" name="q_product" required>
                    <option value="">— choose —</option>
                    <?php foreach ( $products as $p ) : ?>
                        <option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option>
                    <?php endforeach; ?>
                </select></div>

            <div class="field"><label for="q-specs">Specs</label>
                <textarea id="q-specs" name="q_specs" rows="3" placeholder="e.g. 3.5×5 saddle booklet, 8pp, 100lb gloss text"></textarea>
                <span class="field-hint">Shown on the quote page and kept on the order.</span></div>

            <div class="field">
                <label>Quantity &amp; price <span class="req">*</span></label>
                <div class="tier-rows">
                    <div class="tier-row">
                        <input type="number" name="q_qty[]" min="1" placeholder="Qty" required>
                        <input type="number" name="q_price[]" step="0.01" min="0.01" placeholder="Price $" required>
                    </div>
                </div>
                <label class="con-radio" style="margin-top:6px"><input type="checkbox" id="q-addqty"> Offer additional quantities</label>
                <div id="q-extra" hidden>
                    <div class="tier-rows">
                        <?php for ( $i = 0; $i < 3; $i++ ) : ?>
                            <div class="tier-row">
                                <input type="number" name="q_qty[]" min="1" placeholder="Qty">
                                <input type="number" name="q_price[]" step="0.01" min="0.01" placeholder="Price $">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <span class="field-hint">Extra tiers let the customer pick a different quantity now,
                    and are offered again on any future reorder. Leave blank rows empty.</span>
                </div>
            </div>

            <div class="field">
                <label class="con-radio"><input type="checkbox" name="q_allow_date" value="1" id="q-allowdate"> Let the customer choose a delivery date</label>
                <div id="q-mindays" hidden style="margin-top:6px">
                    <label for="q-min">Minimum production days</label>
                    <input type="number" id="q-min" name="q_min_days" min="0" value="5" style="max-width:120px">
                    <span class="field-hint">Working days. Their date picker will not accept anything sooner.</span>
                </div>
            </div>

            <div class="field">
                <label>Payment</label>
                <label class="con-radio"><input type="radio" name="q_pay_source" value="site" checked> Card — website checkout</label>
                <label class="con-radio"><input type="radio" name="q_pay_source" value="quickbooks"> QuickBooks payment link</label>
            </div>
            <div class="field con-qbo" hidden>
                <label for="q-paylink">QuickBooks link</label>
                <input type="url" id="q-paylink" name="q_pay_link" placeholder="https://connect.intuit.com/pay/...">
                <span class="field-hint">The order stays unpaid until you press Mark paid.</span>
            </div>

            <div class="field"><label for="q-note">Private note</label><textarea id="q-note" name="q_note" rows="2"></textarea></div>
            <button type="submit" name="pps_console_quote" value="1" class="btn btn-primary btn-submit">Create quote link</button>
        </form>

        <h3 class="con-h" style="margin-top:26px">Awaiting payment (<?php echo count( $unpaid ); ?>)</h3>
        <?php if ( ! $unpaid ) : ?>
            <div class="empty"><span>Nothing outstanding.</span></div>
        <?php else : ?>
            <?php foreach ( $unpaid as $o ) :
                $link = pps_job_invoice_pay_link( $o );
                $qbo  = 'quickbooks' === (string) $o->get_meta( '_pps_pay_source' );
                $id   = 'lnk' . $o->get_id(); ?>
                <div class="con-inv">
                    <div class="con-inv-top">
                        <strong>#<?php echo esc_html( $o->get_order_number() ); ?></strong>
                        <span><?php echo esc_html( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ); ?></span>
                        <span class="dot">&middot;</span>
                        <span><?php echo wp_kses_post( wc_price( $o->get_total() ) ); ?></span>
                        <span class="pill <?php echo $qbo ? 'pill-printing' : 'pill-processing'; ?>"><?php echo $qbo ? 'QuickBooks' : 'Card'; ?></span>
                        <span class="con-date"><?php echo esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( 'M j' ) : '' ); ?></span>
                    </div>
                    <div class="con-inv-mail"><?php echo esc_html( $o->get_billing_email() ); ?></div>
                    <?php if ( $link ) : ?>
                        <div class="con-copy">
                            <input type="text" id="<?php echo esc_attr( $id ); ?>" readonly value="<?php echo esc_attr( $link ); ?>" onfocus="this.select()">
                            <button type="button" class="btn btn-ghost" data-copy="<?php echo esc_attr( $id ); ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                    <form method="post" style="margin:8px 0 0">
                        <?php wp_nonce_field( 'pps_console_action', 'pps_console_nonce' ); ?>
                        <button type="submit" name="pps_console_paid" value="<?php echo esc_attr( $o->get_id() ); ?>"
                                class="btn btn-pay">Mark paid</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div></div>
    <script>
    (function(){
        document.querySelectorAll('[data-copy]').forEach(function(b){
            b.addEventListener('click', function(){
                var f = document.getElementById(b.getAttribute('data-copy'));
                if (!f) return;
                f.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch(e) {}
                if (!ok && navigator.clipboard) { navigator.clipboard.writeText(f.value); ok = true; }
                var t = b.textContent; b.textContent = ok ? 'Copied' : '⌘/Ctrl+C';
                setTimeout(function(){ b.textContent = t; }, 1800);
            });
        });
        // The QuickBooks link field and WooCommerce's invoice email are mutually
        // exclusive: that email would carry the site checkout link.
        var qbo = document.querySelector('.con-qbo');
        function sync(){
            var r = document.querySelector('input[name=q_pay_source][value=quickbooks]');
            var isQ = r && r.checked;
            if (qbo) qbo.hidden = !isQ;
            var f = document.getElementById('q-paylink');
            if (f) f.required = !!isQ;
        }
        document.querySelectorAll('input[name=q_pay_source]').forEach(function(r){ r.addEventListener('change', sync); });
        var add = document.getElementById('q-addqty'), extra = document.getElementById('q-extra');
        if (add) { add.addEventListener('change', function(){ extra.hidden = !add.checked; }); }
        var ad = document.getElementById('q-allowdate'), md = document.getElementById('q-mindays');
        if (ad) { ad.addEventListener('change', function(){ md.hidden = !ad.checked; }); }
        sync();
    })();
    </script>
    <?php return ob_get_clean();
}

/* Console styling rides on the same sheet as the lookup, enqueued under the
   same handle — the two pages never both match, so it registers once. */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_singular() ) return;
    $post = get_post();
    if ( ! $post || ! has_shortcode( (string) $post->post_content, 'pps_job_console' ) ) return;
    if ( ! function_exists( 'pps_acct_ui_css' ) ) return;
    $ver = defined( 'PPS_CALC_VERSION' ) ? PPS_CALC_VERSION : '1.0.0';
    wp_register_style( 'pps-acct-ui', false, array(), $ver );
    wp_enqueue_style( 'pps-acct-ui' );
    wp_add_inline_style( 'pps-acct-ui', pps_acct_ui_css() . pps_job_console_css() );
}, 11 );

function pps_job_console_css() {
    return <<<'CSS'
.pps-acct .con-h { font-size: 15px; font-weight: 700; margin: 0 0 12px; color: var(--key); }
.pps-acct .con-row { display: flex; gap: 12px; }
.pps-acct .con-row .field { flex: 1; }
.pps-acct .con-form select,
.pps-acct .con-form textarea {
  padding: 9px 11px; border: 1px solid var(--border); border-radius: 4px;
  font-size: 14px; font-family: var(--font-ui); background: var(--white); color: var(--key);
}
.pps-acct .con-form select:focus,
.pps-acct .con-form textarea:focus { border-color: var(--process-cyan); box-shadow: 0 0 0 3px rgba(0,126,255,0.18); outline: none; }
.pps-acct .tier-rows { display: flex; flex-direction: column; gap: 6px; }
.pps-acct .tier-row { display: flex; gap: 8px; }
.pps-acct .tier-row input { flex: 1; min-width: 0; padding: 9px 11px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; font-family: var(--font-ui); }
.pps-acct .tier-row input:focus { border-color: var(--process-cyan); box-shadow: 0 0 0 3px rgba(0,126,255,0.18); outline: none; }
.pps-acct .con-radio { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--key); margin: 2px 0; }
.pps-acct .con-radio input { width: auto; }
.pps-acct .con-copy { display: flex; gap: 8px; align-items: center; margin-top: 4px; }
.pps-acct .con-copy input {
  flex: 1; min-width: 0; padding: 8px 10px; border: 1px solid var(--border); border-radius: 4px;
  font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; background: var(--white); color: var(--key);
}
.pps-acct .con-lbl { font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px; }
.pps-acct .con-hint { font-size: 12px; color: var(--mid); margin: 8px 0 0; }
.pps-acct .con-done {
  border: 2px solid var(--process-yellow); background: var(--process-yellow-light);
  border-radius: 6px; padding: 14px 16px; margin-bottom: 18px;
}
.pps-acct .con-done-head { font-weight: 700; font-size: 14px; margin-bottom: 10px; }
.pps-acct .con-inv {
  border: 1px solid var(--border); border-left: 3px solid var(--process-yellow);
  border-radius: 6px; padding: 12px 14px; margin-bottom: 10px; background: var(--white);
}
.pps-acct .con-inv-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--key); }
.pps-acct .con-inv-mail { font-size: 12px; color: var(--mid); margin: 2px 0 6px; word-break: break-all; }
.pps-acct .con-date { margin-left: auto; font-size: 12px; color: var(--light); }
.pps-acct .banner-success { background: #eaf6e5; border: 1px solid #bcdcae; color: #3c6b28; }
@media (max-width: 639px) {
  .pps-acct .con-row { flex-direction: column; gap: 0; }
  .pps-acct .con-date { margin-left: 0; }
}
CSS;
}

/* ─────────────────────────────────────────────────────────────
 * wp-admin screen
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'Invoice a Job',
        '💳 Invoice a Job',
        'manage_options',
        'pps-job-invoice',
        'pps_job_invoice_render_page'
    );
}, 21 );

add_action( 'admin_post_pps_job_invoice', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_job_invoice' );
    $back = admin_url( 'admin.php?page=pps-job-invoice' );

    $res = pps_job_invoice_create( array(
        'first'        => isset( $_POST['inv_first'] ) ? wp_unslash( $_POST['inv_first'] ) : '',
        'last'         => isset( $_POST['inv_last'] ) ? wp_unslash( $_POST['inv_last'] ) : '',
        'email'        => isset( $_POST['inv_email'] ) ? wp_unslash( $_POST['inv_email'] ) : '',
        'phone'        => isset( $_POST['inv_phone'] ) ? wp_unslash( $_POST['inv_phone'] ) : '',
        'product'      => isset( $_POST['inv_product'] ) ? $_POST['inv_product'] : 0,
        'qty'          => isset( $_POST['inv_qty'] ) ? $_POST['inv_qty'] : 1,
        'total'        => isset( $_POST['inv_total'] ) ? wp_unslash( $_POST['inv_total'] ) : 0,
        'specs'        => isset( $_POST['inv_specs'] ) ? wp_unslash( $_POST['inv_specs'] ) : '',
        'note'         => isset( $_POST['inv_note'] ) ? wp_unslash( $_POST['inv_note'] ) : '',
        'pay_source'   => isset( $_POST['inv_pay_source'] ) ? wp_unslash( $_POST['inv_pay_source'] ) : 'site',
        'pay_link'     => isset( $_POST['inv_pay_link'] ) ? wp_unslash( $_POST['inv_pay_link'] ) : '',
        'send_invoice' => ! empty( $_POST['inv_send_invoice'] ),
        'by'           => wp_get_current_user()->user_login,
    ) );

    if ( is_wp_error( $res ) ) {
        wp_safe_redirect( add_query_arg( 'inv_err', $res->get_error_code(), $back ) ); exit;
    }
    wp_safe_redirect( add_query_arg( 'inv_done', $res->get_id(), $back ) ); exit;
} );

add_action( 'admin_post_pps_job_invoice_key', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_job_invoice_key' );
    $pw = isset( $_POST['con_pw'] ) ? (string) wp_unslash( $_POST['con_pw'] ) : '';
    $back = admin_url( 'admin.php?page=pps-job-invoice' );
    // Deliberately a low floor rather than a policy: the owner picked a short
    // password knowingly, and a form that refuses to set the password actually
    // in use is worse than a short one. Brute force is handled by the lockout
    // (five tries per IP per fifteen minutes), not by length.
    if ( strlen( $pw ) < 4 ) {
        wp_safe_redirect( add_query_arg( 'key_err', 'short', $back ) ); exit;
    }
    // Only the hash is stored, and changing it invalidates every open console
    // session because the cookie signature is derived from it.
    update_option( 'pps_console_key', password_hash( $pw, PASSWORD_DEFAULT ), false );
    wp_safe_redirect( add_query_arg( 'key_done', '1', $back ) ); exit;
} );

add_action( 'admin_post_pps_job_multiplier', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_job_multiplier' );
    $m = isset( $_POST['multiplier'] ) ? (float) wp_unslash( $_POST['multiplier'] ) : 1.0;
    // The reader clamps to 0.5–3.0 too; storing the clamped value keeps the
    // field honest about what is actually in force.
    $m = max( 0.5, min( 3.0, $m > 0 ? $m : 1.0 ) );
    update_option( 'pps_past_order_multiplier', $m, false );
    wp_safe_redirect( add_query_arg( 'mult_done', '1', admin_url( 'admin.php?page=pps-job-invoice' ) ) ); exit;
} );

add_action( 'admin_post_pps_job_invoice_paid', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_job_invoice_paid' );
    $oid = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $o   = $oid ? wc_get_order( $oid ) : null;
    if ( $o ) pps_job_invoice_mark_paid( $o, wp_get_current_user()->user_login );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-job-invoice' ) ); exit;
} );

function pps_job_invoice_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $done = isset( $_GET['inv_done'] ) ? absint( $_GET['inv_done'] ) : 0;
    $err  = isset( $_GET['inv_err'] ) ? sanitize_key( $_GET['inv_err'] ) : '';
    $products = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
    $unpaid   = pps_job_invoice_unpaid( 25 );
    ?>
    <div class="wrap" style="max-width:720px">
        <h1>💳 Invoice a Job</h1>
        <p style="color:#50575e;max-width:56em">For work sold over email. Creates a real order
        <strong>awaiting payment</strong> and gives you a link to send — either the website's card
        checkout or a QuickBooks payment link you paste in. Once paid it appears in the customer's
        <code>/reorders</code> history with one-click reorder at this price.</p>

        <?php if ( $done ) :
            $o = wc_get_order( $done );
            $link = $o ? pps_job_invoice_pay_link( $o ) : '';
            $qbo  = $o && 'quickbooks' === (string) $o->get_meta( '_pps_pay_source' ); ?>
            <div class="notice notice-success" style="padding-bottom:8px">
                <p style="margin-top:8px"><strong>Order #<?php echo esc_html( $o ? $o->get_order_number() : $done ); ?> created</strong>
                — awaiting payment. <a href="<?php echo esc_url( $o ? $o->get_edit_order_url() : '#' ); ?>">Open in WooCommerce</a></p>
                <?php if ( $link ) : ?>
                    <p style="margin:8px 0 4px"><strong><?php echo $qbo ? 'QuickBooks payment link' : 'Payment link'; ?></strong></p>
                    <p style="display:flex;gap:8px;align-items:center;margin-top:0">
                        <input type="text" id="pps-pay-link" readonly value="<?php echo esc_attr( $link ); ?>"
                               style="flex:1;max-width:520px;padding:6px 8px;font-family:monospace;font-size:12px" onfocus="this.select()">
                        <button type="button" class="button button-primary" id="pps-pay-copy">Copy</button>
                    </p>
                    <p class="description" style="margin-bottom:10px"><?php echo $qbo
                        ? 'Send this to the customer, then use Mark paid below once QuickBooks shows the payment.'
                        : 'Paste into your email reply. WooCommerce marks the order paid by itself.'; ?></p>
                    <script>
                    document.getElementById('pps-pay-copy').addEventListener('click', function () {
                        var f = document.getElementById('pps-pay-link'); f.select();
                        var ok = false; try { ok = document.execCommand('copy'); } catch (e) {}
                        if (!ok && navigator.clipboard) { navigator.clipboard.writeText(f.value); ok = true; }
                        var b = this; b.textContent = ok ? 'Copied' : 'Press ⌘/Ctrl+C';
                        setTimeout(function () { b.textContent = 'Copy'; }, 2000);
                    });
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ( $err ) :
            $msgs = array(
                'email'   => 'A valid customer email is required — it is how the reorder lookup finds the order.',
                'product' => 'Pick a product for the line item.',
                'total'   => 'Enter the price quoted (must be more than $0).',
                'paylink' => 'Paste the full https:// QuickBooks payment link, or switch to card checkout.',
                'create'  => 'WooCommerce refused to create the order — check the error logs.',
            ); ?>
            <div class="notice notice-error"><p><?php echo esc_html( $msgs[ $err ] ?? 'Something went wrong.' ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['key_done'] ) ) : ?>
            <div class="notice notice-success"><p>Console password saved. Any open console sessions have been signed out.</p></div>
        <?php elseif ( isset( $_GET['key_err'] ) ) : ?>
            <div class="notice notice-error"><p>Console password must be at least 4 characters.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_job_invoice' ); ?>
            <input type="hidden" name="action" value="pps_job_invoice">
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="inv_first">Customer name</label></th>
                    <td><input type="text" id="inv_first" name="inv_first" style="width:170px" placeholder="First">
                        <input type="text" name="inv_last" style="width:170px" placeholder="Last"></td></tr>
                <tr><th scope="row"><label for="inv_email">Email <span style="color:#d63638">*</span></label></th>
                    <td><input type="email" id="inv_email" name="inv_email" class="regular-text" required>
                        <p class="description">Where the invoice goes, and the key the reorder lookup searches on.</p></td></tr>
                <tr><th scope="row"><label for="inv_phone">Phone</label></th>
                    <td><input type="text" id="inv_phone" name="inv_phone" class="regular-text"></td></tr>
                <tr><th scope="row"><label for="inv_product">Product <span style="color:#d63638">*</span></label></th>
                    <td><select id="inv_product" name="inv_product" required style="max-width:340px">
                        <option value="">— choose —</option>
                        <?php foreach ( $products as $p ) : ?>
                            <option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">The closest matching product — this is what a future reorder re-adds to the cart.</p></td></tr>
                <tr><th scope="row"><label for="inv_qty">Quantity</label></th>
                    <td><input type="number" id="inv_qty" name="inv_qty" value="1" min="1" style="width:110px"></td></tr>
                <tr><th scope="row"><label for="inv_total">Price quoted ($) <span style="color:#d63638">*</span></label></th>
                    <td><input type="number" id="inv_total" name="inv_total" step="0.01" min="0.01" style="width:130px" required>
                        <p class="description">For the whole line, as quoted. A reorder carries this price (per unit = total ÷ quantity).</p></td></tr>
                <tr><th scope="row"><label for="inv_specs">Specs</label></th>
                    <td><textarea id="inv_specs" name="inv_specs" rows="3" class="large-text" placeholder="e.g. 3.5×5 saddle booklet, 8pp, 100lb gloss text"></textarea>
                        <p class="description">Shown on the customer's reorder card and included if they use Contact Us.</p></td></tr>
                <tr><th scope="row">Payment</th>
                    <td>
                        <label><input type="radio" name="inv_pay_source" value="site" checked> Card — website checkout</label><br>
                        <label><input type="radio" name="inv_pay_source" value="quickbooks"> QuickBooks payment link</label>
                        <div id="pps-qbo-row" style="margin-top:8px" hidden>
                            <input type="url" id="inv_pay_link" name="inv_pay_link" class="regular-text" style="width:100%;max-width:520px" placeholder="https://connect.intuit.com/pay/...">
                            <p class="description">Create the link in QuickBooks and paste it here. The order stays unpaid until you press Mark paid.</p>
                        </div>
                    </td></tr>
                <tr id="pps-site-row"><th scope="row">Invoice email</th>
                    <td><label><input type="checkbox" name="inv_send_invoice" value="1" checked> Email the customer WooCommerce's invoice now</label>
                        <p class="description">Not available for QuickBooks jobs — that email carries the website checkout link.</p></td></tr>
                <tr><th scope="row"><label for="inv_note">Private note</label></th>
                    <td><textarea id="inv_note" name="inv_note" rows="2" class="large-text"></textarea></td></tr>
            </table>
            <?php submit_button( 'Create Order &amp; Payment Link' ); ?>
        </form>
        <script>
        (function(){
            function sync(){
                var isQ = document.querySelector('input[name=inv_pay_source][value=quickbooks]').checked;
                document.getElementById('pps-qbo-row').hidden = !isQ;
                document.getElementById('pps-site-row').hidden = isQ;
                document.getElementById('inv_pay_link').required = isQ;
            }
            document.querySelectorAll('input[name=inv_pay_source]').forEach(function(r){ r.addEventListener('change', sync); });
            sync();
        })();
        </script>

        <hr style="margin:26px 0">
        <h2>Awaiting payment (<?php echo count( $unpaid ); ?>)</h2>
        <?php if ( ! $unpaid ) : ?>
            <p style="color:#50575e">Nothing outstanding.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:720px">
                <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Via</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $unpaid as $o ) :
                    $qbo = 'quickbooks' === (string) $o->get_meta( '_pps_pay_source' ); ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo esc_html( $o->get_order_number() ); ?></a></td>
                        <td><?php echo esc_html( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ); ?><br>
                            <small><?php echo esc_html( $o->get_billing_email() ); ?></small></td>
                        <td><?php echo wp_kses_post( wc_price( $o->get_total() ) ); ?></td>
                        <td><?php echo $qbo ? 'QuickBooks' : 'Card'; ?></td>
                        <td style="text-align:right">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                                <?php wp_nonce_field( 'pps_job_invoice_paid' ); ?>
                                <input type="hidden" name="action" value="pps_job_invoice_paid">
                                <input type="hidden" name="order_id" value="<?php echo esc_attr( $o->get_id() ); ?>">
                                <button type="submit" class="button">Mark paid</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr style="margin:26px 0">
        <h2>Past order price multiple</h2>
        <?php $mult = function_exists( 'pps_past_order_multiplier' ) ? pps_past_order_multiplier() : 1.0; ?>
        <p style="color:#50575e;max-width:56em">What a historical price is worth today. Every reorder —
        the quantity tiers on a quote, and the frozen "same as before" price on any past order — is
        multiplied by this at the moment of reorder. <strong>1.00 changes nothing.</strong> 1.05 quietly
        adds 5% to everything reordered from here on. Clamped to 0.50–3.00, because a typo here
        re-prices every reorder on the site.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_job_multiplier' ); ?>
            <input type="hidden" name="action" value="pps_job_multiplier">
            <input type="number" name="multiplier" step="0.01" min="0.5" max="3" value="<?php echo esc_attr( number_format( $mult, 2, '.', '' ) ); ?>" style="width:110px">
            <?php submit_button( 'Save Multiple', 'secondary', 'submit', false ); ?>
            <?php if ( isset( $_GET['mult_done'] ) ) : ?><span style="color:#3c6b28;margin-left:10px">Saved.</span><?php endif; ?>
        </form>

        <hr style="margin:26px 0">
        <h2>Console password</h2>
        <p style="color:#50575e;max-width:56em">Password for the no-login console page
        (the page carrying the <code>[pps_job_console]</code> shortcode). Stored as a hash only.
        Changing it signs out every open console session. Five wrong attempts lock that IP out for fifteen minutes, which is what actually stops guessing — but a longer password is still worth having.
        <?php echo pps_console_hash() ? '<strong>A password is currently set.</strong>' : '<strong>No password set — the console is closed.</strong>'; ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_job_invoice_key' ); ?>
            <input type="hidden" name="action" value="pps_job_invoice_key">
            <input type="password" name="con_pw" class="regular-text" placeholder="New console password" autocomplete="new-password" required minlength="4">
            <?php submit_button( 'Save Password', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}
