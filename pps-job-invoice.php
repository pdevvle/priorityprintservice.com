<?php
/**
 * PPS Job Invoice — quote a job sold over email, hand the customer a payment
 * link, and have the finished sale land in their /reorders history.
 *
 * Jobs sold by email had no home: quoting in a mail thread meant the sale
 * never became a WooCommerce order, so the customer had no payment link, no
 * receipt, and no reorder record. Producing one by hand through WooCommerce's
 * Add Order screen is a ten-field detour nobody takes mid-conversation.
 *
 * This is forward-looking by design — it creates orders AWAITING PAYMENT, not
 * records of sales already made. The order opens as pending, which gives it a
 * WooCommerce customer-pay URL: paste that into the email reply and the
 * customer pays with the same Stripe checkout the site uses. On payment the
 * order moves to processing on its own and behaves like any web order —
 * including the frozen-price "Reorder (same as before)" button in the lookup,
 * priced at exactly what was quoted here.
 *
 * Until it is paid the lookup shows the job as "Awaiting payment" with a Pay
 * now button (see pps_render_pps_item_card), so a customer who loses the email
 * can still find and settle the invoice.
 *
 * Deliberately not wired into production automation: no PPS-Spec, no
 * production start, no Drive folder. This sells a job; the calculator remains
 * the route for anything needing artwork intake.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─────────────────────────────────────────────────────────────
 * Admin screen
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

    $first  = isset( $_POST['inv_first'] )   ? sanitize_text_field( wp_unslash( $_POST['inv_first'] ) )  : '';
    $last   = isset( $_POST['inv_last'] )    ? sanitize_text_field( wp_unslash( $_POST['inv_last'] ) )   : '';
    $email  = isset( $_POST['inv_email'] )   ? sanitize_email( wp_unslash( $_POST['inv_email'] ) )       : '';
    $phone  = isset( $_POST['inv_phone'] )   ? sanitize_text_field( wp_unslash( $_POST['inv_phone'] ) )  : '';
    $pid    = isset( $_POST['inv_product'] ) ? absint( $_POST['inv_product'] ) : 0;
    $qty    = isset( $_POST['inv_qty'] )     ? max( 1, absint( $_POST['inv_qty'] ) ) : 1;
    $total  = isset( $_POST['inv_total'] )   ? round( (float) wp_unslash( $_POST['inv_total'] ), 2 )     : 0;
    $specs  = isset( $_POST['inv_specs'] )   ? sanitize_textarea_field( wp_unslash( $_POST['inv_specs'] ) ) : '';
    $note   = isset( $_POST['inv_note'] )    ? sanitize_textarea_field( wp_unslash( $_POST['inv_note'] ) )  : '';
    $send   = ! empty( $_POST['inv_send_invoice'] );

    $back = admin_url( 'admin.php?page=pps-job-invoice' );

    // The billing email is the lookup key AND where the invoice goes — without
    // it this produces an order nobody can find or pay.
    if ( ! $email || ! is_email( $email ) ) {
        wp_safe_redirect( add_query_arg( 'inv_err', 'email', $back ) ); exit;
    }
    $product = $pid ? wc_get_product( $pid ) : false;
    if ( ! $product || ! $product->exists() ) {
        wp_safe_redirect( add_query_arg( 'inv_err', 'product', $back ) ); exit;
    }
    if ( $total <= 0 ) {
        wp_safe_redirect( add_query_arg( 'inv_err', 'total', $back ) ); exit;
    }

    $order = wc_create_order( array( 'customer_id' => 0 ) );
    if ( is_wp_error( $order ) ) {
        wp_safe_redirect( add_query_arg( 'inv_err', 'create', $back ) ); exit;
    }

    $order->set_created_via( 'pps-job-invoice' );
    $order->set_billing_first_name( $first );
    $order->set_billing_last_name( $last );
    $order->set_billing_email( $email );
    if ( $phone ) $order->set_billing_phone( $phone );

    // The line's subtotal is what the frozen-price reorder divides by qty, so
    // the figure quoted here is the price a future reorder will carry.
    $item_id = $order->add_product( $product, $qty, array(
        'subtotal' => $total,
        'total'    => $total,
    ) );
    if ( $specs && $item_id ) {
        $item = $order->get_item( $item_id );
        if ( $item ) {
            // Visible (non-underscore) meta: the lookup card renders it as the
            // spec lines, and Contact Us carries it in the message payload.
            $item->add_meta_data( 'Specs', $specs, true );
            $item->save();
        }
    }

    $order->update_meta_data( '_pps_job_invoice_by', wp_get_current_user()->user_login );
    $order->calculate_totals( false );

    // Pending is what makes this a payable invoice rather than a record: it is
    // the status WooCommerce hands a customer-pay URL for.
    $order->set_status( 'pending' );
    $order->save();

    $who = wp_get_current_user()->user_login;
    $order->add_order_note( sprintf(
        'Job invoiced by %s — awaiting customer payment.%s',
        $who, $note ? "\n" . $note : ''
    ) );

    if ( $send && function_exists( 'WC' ) && WC()->mailer() ) {
        // WooCommerce's own customer-invoice email; its body carries the pay link.
        $mails = WC()->mailer()->get_emails();
        if ( isset( $mails['WC_Email_Customer_Invoice'] ) ) {
            $mails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
            $order->add_order_note( 'Invoice email sent to ' . $email . '.' );
        }
    }

    wp_safe_redirect( add_query_arg( array(
        'inv_done' => $order->get_id(),
        'inv_sent' => $send ? 1 : 0,
    ), $back ) ); exit;
} );

function pps_job_invoice_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $done = isset( $_GET['inv_done'] ) ? absint( $_GET['inv_done'] ) : 0;
    $sent = ! empty( $_GET['inv_sent'] );
    $err  = isset( $_GET['inv_err'] )  ? sanitize_key( $_GET['inv_err'] ) : '';

    $products = wc_get_products( array(
        'limit'   => 200,
        'status'  => 'publish',
        'orderby' => 'title',
        'order'   => 'ASC',
    ) );
    ?>
    <div class="wrap" style="max-width:680px">
        <h1>💳 Invoice a Job</h1>
        <p style="color:#50575e;max-width:56em">For work sold over email. Creates a real order
        <strong>awaiting payment</strong> and gives you a payment link to paste into your reply.
        The customer pays through the normal checkout; the order then behaves like any web order
        and appears in their <code>/reorders</code> history with one-click reorder at this price.</p>

        <?php if ( $done ) :
            $o = wc_get_order( $done );
            $pay = $o ? $o->get_checkout_payment_url() : '';
            ?>
            <div class="notice notice-success" style="padding-bottom:6px">
                <p style="margin-top:8px">
                    <strong>Order #<?php echo esc_html( $o ? $o->get_order_number() : $done ); ?> created</strong>
                    — awaiting payment<?php echo $sent ? ', invoice emailed to ' . esc_html( $o ? $o->get_billing_email() : '' ) : ''; ?>.
                    <a href="<?php echo esc_url( $o ? $o->get_edit_order_url() : '#' ); ?>">Open in WooCommerce</a>
                </p>
                <?php if ( $pay ) : ?>
                    <p style="margin:10px 0 4px"><label for="pps-pay-link" style="font-weight:600">Payment link</label></p>
                    <p style="display:flex;gap:8px;align-items:center;margin-top:0">
                        <input type="text" id="pps-pay-link" readonly value="<?php echo esc_attr( $pay ); ?>"
                               style="flex:1;max-width:520px;padding:6px 8px;font-family:monospace;font-size:12px"
                               onfocus="this.select()">
                        <button type="button" class="button button-primary" id="pps-pay-copy">Copy</button>
                    </p>
                    <p class="description" style="margin-bottom:10px">Paste into your email reply. The link stays valid until the order is paid or cancelled.</p>
                    <script>
                    document.getElementById('pps-pay-copy').addEventListener('click', function () {
                        var f = document.getElementById('pps-pay-link');
                        f.select();
                        var ok = false;
                        try { ok = document.execCommand('copy'); } catch (e) {}
                        if (navigator.clipboard && !ok) { navigator.clipboard.writeText(f.value); ok = true; }
                        this.textContent = ok ? 'Copied' : 'Press ⌘/Ctrl+C';
                        var b = this;
                        setTimeout(function () { b.textContent = 'Copy'; }, 2000);
                    });
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ( $err ) :
            $msgs = array(
                'email'   => 'A valid customer email is required — it is where the invoice goes and how the reorder lookup finds the order.',
                'product' => 'Pick a product for the line item.',
                'total'   => 'Enter the price quoted (must be more than $0).',
                'create'  => 'WooCommerce refused to create the order — check the error logs.',
            ); ?>
            <div class="notice notice-error"><p><?php echo esc_html( $msgs[ $err ] ?? 'Something went wrong.' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_job_invoice' ); ?>
            <input type="hidden" name="action" value="pps_job_invoice">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="inv_first">Customer name</label></th>
                    <td>
                        <input type="text" id="inv_first" name="inv_first" class="regular-text" style="width:170px" placeholder="First">
                        <input type="text" name="inv_last" class="regular-text" style="width:170px" placeholder="Last">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_email">Email <span style="color:#d63638">*</span></label></th>
                    <td>
                        <input type="email" id="inv_email" name="inv_email" class="regular-text" required>
                        <p class="description">Where the invoice goes, and the key the reorder lookup searches on.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_phone">Phone</label></th>
                    <td><input type="text" id="inv_phone" name="inv_phone" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_product">Product <span style="color:#d63638">*</span></label></th>
                    <td>
                        <select id="inv_product" name="inv_product" required style="max-width:340px">
                            <option value="">— choose —</option>
                            <?php foreach ( $products as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The closest matching product — this is what a future reorder re-adds to the cart.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_qty">Quantity</label></th>
                    <td><input type="number" id="inv_qty" name="inv_qty" value="1" min="1" style="width:110px"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_total">Price quoted ($) <span style="color:#d63638">*</span></label></th>
                    <td>
                        <input type="number" id="inv_total" name="inv_total" step="0.01" min="0.01" style="width:130px" required>
                        <p class="description">For the whole line, as quoted. A reorder carries this price (per unit = total ÷ quantity).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_specs">Specs</label></th>
                    <td>
                        <textarea id="inv_specs" name="inv_specs" rows="3" class="large-text" placeholder="e.g. 3.5×5 saddle booklet, 8pp, 100lb gloss text, full color"></textarea>
                        <p class="description">Shown on the customer's reorder card and included if they use Contact Us about the order.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Invoice email</th>
                    <td>
                        <label><input type="checkbox" name="inv_send_invoice" value="1" checked> Email the customer the invoice now</label>
                        <p class="description">WooCommerce's invoice email, containing the same payment link. Untick if you would rather paste the link into your own reply.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="inv_note">Private note</label></th>
                    <td><textarea id="inv_note" name="inv_note" rows="2" class="large-text" placeholder="Internal only — added as an order note"></textarea></td>
                </tr>
            </table>

            <?php submit_button( 'Create Order &amp; Payment Link' ); ?>
        </form>
    </div>
    <?php
}
