<?php
/**
 * PPS Offline Order — log phone / walk-in / email transactions as real
 * WooCommerce orders so they appear in the /reorders guest lookup.
 *
 * The lookup (pps-reorder.php) needs nothing exotic: any order with a billing
 * email, a line item pointing at an existing product, a quantity and a
 * subtotal renders a card and gets the frozen-price "Reorder (same as
 * before)" button. Offline transactions never got that far because nobody is
 * going to click through WooCommerce's ten-field Add Order screen for a phone
 * sale — so those customers had no history and no reorder path.
 *
 * One screen, one submit: customer, product, qty, total charged, optional
 * spec text (stored as VISIBLE item meta, which the lookup card renders as
 * its spec lines and carries into the Contact Us payload). Creates the order
 * through WC CRUD (HPOS-safe), marked created_via 'pps-offline'.
 *
 * Deliberately NOT wired into production automation: no PPS-Spec order meta,
 * no PPS-Production-Start, no Drive folder — an offline order is a record of
 * a transaction that already happened, not a job ticket. Status emails are
 * suppressed unless the operator explicitly asks to send a receipt.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─────────────────────────────────────────────────────────────
 * Admin screen
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'Log Offline Order',
        '☎ Offline Order',
        'manage_options',
        'pps-offline-order',
        'pps_offline_order_render_page'
    );
}, 21 );

add_action( 'admin_post_pps_offline_order', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_offline_order' );

    $first  = isset( $_POST['off_first'] )  ? sanitize_text_field( wp_unslash( $_POST['off_first'] ) )  : '';
    $last   = isset( $_POST['off_last'] )   ? sanitize_text_field( wp_unslash( $_POST['off_last'] ) )   : '';
    $email  = isset( $_POST['off_email'] )  ? sanitize_email( wp_unslash( $_POST['off_email'] ) )       : '';
    $phone  = isset( $_POST['off_phone'] )  ? sanitize_text_field( wp_unslash( $_POST['off_phone'] ) )  : '';
    $pid    = isset( $_POST['off_product'] ) ? absint( $_POST['off_product'] ) : 0;
    $qty    = isset( $_POST['off_qty'] )    ? max( 1, absint( $_POST['off_qty'] ) ) : 1;
    $total  = isset( $_POST['off_total'] )  ? round( (float) wp_unslash( $_POST['off_total'] ), 2 )     : 0;
    $specs  = isset( $_POST['off_specs'] )  ? sanitize_textarea_field( wp_unslash( $_POST['off_specs'] ) ) : '';
    $pay    = isset( $_POST['off_payment'] ) ? sanitize_text_field( wp_unslash( $_POST['off_payment'] ) ) : 'Other';
    $date   = isset( $_POST['off_date'] )   ? sanitize_text_field( wp_unslash( $_POST['off_date'] ) )   : '';
    $status = ( isset( $_POST['off_status'] ) && 'processing' === $_POST['off_status'] ) ? 'processing' : 'completed';
    $email_receipt = ! empty( $_POST['off_send_receipt'] );
    $note   = isset( $_POST['off_note'] )   ? sanitize_textarea_field( wp_unslash( $_POST['off_note'] ) ) : '';

    $back = admin_url( 'admin.php?page=pps-offline-order' );

    // The billing email is the lookup key — without it the order is invisible
    // to /reorders and this whole exercise is pointless. Hard requirement.
    if ( ! $email || ! is_email( $email ) ) {
        wp_safe_redirect( add_query_arg( 'off_err', 'email', $back ) ); exit;
    }
    $product = $pid ? wc_get_product( $pid ) : false;
    if ( ! $product || ! $product->exists() ) {
        wp_safe_redirect( add_query_arg( 'off_err', 'product', $back ) ); exit;
    }
    if ( $total <= 0 ) {
        wp_safe_redirect( add_query_arg( 'off_err', 'total', $back ) ); exit;
    }

    $order = wc_create_order( array( 'customer_id' => 0 ) );
    if ( is_wp_error( $order ) ) {
        wp_safe_redirect( add_query_arg( 'off_err', 'create', $back ) ); exit;
    }

    $order->set_created_via( 'pps-offline' );
    $order->set_billing_first_name( $first );
    $order->set_billing_last_name( $last );
    $order->set_billing_email( $email );
    if ( $phone ) $order->set_billing_phone( $phone );

    // The line's subtotal is what the frozen-price reorder divides by qty, so
    // the totals entered here ARE the price a future reorder will carry.
    $item_id = $order->add_product( $product, $qty, array(
        'subtotal' => $total,
        'total'    => $total,
    ) );
    if ( $specs && $item_id ) {
        $item = $order->get_item( $item_id );
        if ( $item ) {
            // Visible (non-underscore) meta: the lookup card renders it as the
            // spec lines, and Contact Us includes it in the message payload.
            $item->add_meta_data( 'Specs', $specs, true );
            $item->save();
        }
    }

    $order->set_payment_method( 'pps_offline' );
    $order->set_payment_method_title( $pay . ' (offline)' );

    if ( $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        $order->set_date_created( $date . ' 12:00:00' );
        $order->set_date_paid( $date . ' 12:00:00' );
    } else {
        $order->set_date_paid( time() );
    }

    $order->update_meta_data( '_pps_offline_logged_by', wp_get_current_user()->user_login );
    $order->calculate_totals( false );

    // A logged historical sale should not surprise the customer with "your
    // order is complete!" months later, and the operator does not need a
    // "new order" email about a sale they just typed in. Suppress both unless
    // the receipt box is ticked — via filters, so nothing global changes.
    $mail_off = '__return_false';
    $mail_ids = array(
        'woocommerce_email_enabled_new_order',
        'woocommerce_email_enabled_customer_processing_order',
        'woocommerce_email_enabled_customer_completed_order',
    );
    if ( ! $email_receipt ) {
        foreach ( $mail_ids as $f ) add_filter( $f, $mail_off );
    }
    $order->set_status( $status );
    $order->save();
    if ( ! $email_receipt ) {
        foreach ( $mail_ids as $f ) remove_filter( $f, $mail_off );
    }

    $who = wp_get_current_user()->user_login;
    $order->add_order_note( sprintf(
        'Offline order logged by %s. Payment: %s.%s',
        $who, $pay, $note ? "\n" . $note : ''
    ) );

    wp_safe_redirect( add_query_arg( 'off_done', $order->get_id(), $back ) ); exit;
} );

function pps_offline_order_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $done = isset( $_GET['off_done'] ) ? absint( $_GET['off_done'] ) : 0;
    $err  = isset( $_GET['off_err'] )  ? sanitize_key( $_GET['off_err'] ) : '';

    $products = wc_get_products( array(
        'limit'   => 200,
        'status'  => 'publish',
        'orderby' => 'title',
        'order'   => 'ASC',
    ) );
    ?>
    <div class="wrap" style="max-width:640px">
        <h1>☎ Log Offline Order</h1>
        <p style="color:#50575e;max-width:56em">Records a phone, walk-in or email sale as a real
        WooCommerce order. It appears in the customer's <code>/reorders</code> lookup under the
        email entered below, with a one-click reorder at the price charged here. No production
        automation fires and no email is sent unless you tick the receipt box.</p>

        <?php if ( $done ) :
            $o = wc_get_order( $done ); ?>
            <div class="notice notice-success"><p>
                Logged as <a href="<?php echo esc_url( $o ? $o->get_edit_order_url() : '#' ); ?>">order
                #<?php echo esc_html( $o ? $o->get_order_number() : $done ); ?></a>
                <?php if ( $o ) : ?> — visible in the reorder lookup for
                <strong><?php echo esc_html( $o->get_billing_email() ); ?></strong>.<?php endif; ?>
            </p></div>
        <?php endif; ?>
        <?php if ( $err ) :
            $msgs = array(
                'email'   => 'A valid customer email is required — it is how the reorder lookup finds the order.',
                'product' => 'Pick a product for the line item.',
                'total'   => 'Enter the total charged (must be more than $0).',
                'create'  => 'WooCommerce refused to create the order — check the error logs.',
            ); ?>
            <div class="notice notice-error"><p><?php echo esc_html( $msgs[ $err ] ?? 'Something went wrong.' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'pps_offline_order' ); ?>
            <input type="hidden" name="action" value="pps_offline_order">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="off_first">Customer name</label></th>
                    <td>
                        <input type="text" id="off_first" name="off_first" class="regular-text" style="width:170px" placeholder="First">
                        <input type="text" name="off_last" class="regular-text" style="width:170px" placeholder="Last">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_email">Email <span style="color:#d63638">*</span></label></th>
                    <td>
                        <input type="email" id="off_email" name="off_email" class="regular-text" required>
                        <p class="description">The reorder lookup finds orders by this email — it must be the customer's.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_phone">Phone</label></th>
                    <td><input type="text" id="off_phone" name="off_phone" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_product">Product <span style="color:#d63638">*</span></label></th>
                    <td>
                        <select id="off_product" name="off_product" required style="max-width:340px">
                            <option value="">— choose —</option>
                            <?php foreach ( $products as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The closest matching product — this is what a future reorder re-adds to the cart.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_qty">Quantity</label></th>
                    <td><input type="number" id="off_qty" name="off_qty" value="1" min="1" style="width:110px"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_total">Total charged ($) <span style="color:#d63638">*</span></label></th>
                    <td>
                        <input type="number" id="off_total" name="off_total" step="0.01" min="0.01" style="width:130px" required>
                        <p class="description">For the whole line, tax included as charged. A reorder carries this price (per unit = total ÷ quantity).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_specs">Specs</label></th>
                    <td>
                        <textarea id="off_specs" name="off_specs" rows="3" class="large-text" placeholder="e.g. 3.5×5 saddle booklet, 8pp, 100lb gloss text, full color"></textarea>
                        <p class="description">Shown on the customer's reorder card and included if they use Contact Us about the order.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_payment">Payment</label></th>
                    <td>
                        <select id="off_payment" name="off_payment">
                            <?php foreach ( array( 'Cash', 'Check', 'Card (terminal)', 'Invoice / net terms', 'Other' ) as $pm ) : ?>
                                <option><?php echo esc_html( $pm ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_date">Order date</label></th>
                    <td><input type="date" id="off_date" name="off_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Status</th>
                    <td>
                        <label><input type="radio" name="off_status" value="completed" checked> Completed (already produced &amp; delivered)</label><br>
                        <label><input type="radio" name="off_status" value="processing"> Processing (still in production)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Receipt</th>
                    <td><label><input type="checkbox" name="off_send_receipt" value="1"> Email the customer a WooCommerce receipt now</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="off_note">Private note</label></th>
                    <td><textarea id="off_note" name="off_note" rows="2" class="large-text" placeholder="Internal only — added as an order note"></textarea></td>
                </tr>
            </table>

            <?php submit_button( 'Log Order' ); ?>
        </form>
    </div>
    <?php
}
