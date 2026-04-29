<?php
/**
 * PPS High-Value Order Approval
 *
 * Adds a "Submit for Approval" payment gateway and a custom order status
 * (wc-pps-approval) for orders above a configurable dollar threshold.
 *
 * Flow:
 *   1. Customer cart total >= threshold → only "Submit for Approval" is offered.
 *   2. process_payment() places the order in the pps-approval status, no funds captured.
 *   3. Admin reviews on the order edit screen and clicks Approve or Reject.
 *   4. Approve → order moves to pending; customer is emailed a pay link
 *      that exposes the regular gateways (Stripe, etc.).
 *   5. Reject → order moves to cancelled with a customer-visible reason.
 *
 * Sub-threshold orders are unaffected. Pair with the official Stripe plugin
 * set to "authorize, capture later" for additional review on smaller orders.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════

define( 'PPS_APPROVAL_GATEWAY_ID', 'pps_approval' );
define( 'PPS_APPROVAL_STATUS',     'pps-approval' );        // WC slug (no wc- prefix)
define( 'PPS_APPROVAL_POST_STATUS', 'wc-pps-approval' );     // post_status with prefix
define( 'PPS_APPROVAL_DEFAULT_THRESHOLD', 5000 );

// ═══════════════════════════════════════════════════════════════
// SETTINGS HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Read gateway settings without instantiating the gateway class.
 * The gateway class is only loaded after WooCommerce init, so direct
 * option reads are needed for early hooks.
 */
function pps_approval_get_settings() {
    $opts = get_option( 'woocommerce_' . PPS_APPROVAL_GATEWAY_ID . '_settings', array() );
    if ( ! is_array( $opts ) ) $opts = array();
    return wp_parse_args( $opts, array(
        'enabled'      => 'yes',
        'title'        => 'Submit for Approval',
        'description'  => 'Large orders are reviewed by our team before payment. We will email you a secure payment link once your order is approved (typically within one business day).',
        'threshold'    => PPS_APPROVAL_DEFAULT_THRESHOLD,
        'admin_email'  => get_option( 'admin_email' ),
    ) );
}

function pps_approval_get_threshold() {
    $s = pps_approval_get_settings();
    $t = floatval( $s['threshold'] );
    return $t > 0 ? $t : PPS_APPROVAL_DEFAULT_THRESHOLD;
}

// ═══════════════════════════════════════════════════════════════
// CUSTOM ORDER STATUS
// ═══════════════════════════════════════════════════════════════

add_action( 'init', function() {
    register_post_status( PPS_APPROVAL_POST_STATUS, array(
        'label'                     => _x( 'Awaiting Approval', 'Order status', 'pps-calculators' ),
        'public'                    => false,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: number of orders */
        'label_count'               => _n_noop(
            'Awaiting Approval <span class="count">(%s)</span>',
            'Awaiting Approval <span class="count">(%s)</span>',
            'pps-calculators'
        ),
    ) );
});

// Insert into the WC status dropdown right after "Pending payment"
add_filter( 'wc_order_statuses', function( $statuses ) {
    $new = array();
    foreach ( $statuses as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'wc-pending' ) {
            $new[ PPS_APPROVAL_POST_STATUS ] = _x( 'Awaiting Approval', 'Order status', 'pps-calculators' );
        }
    }
    if ( ! isset( $new[ PPS_APPROVAL_POST_STATUS ] ) ) {
        $new[ PPS_APPROVAL_POST_STATUS ] = _x( 'Awaiting Approval', 'Order status', 'pps-calculators' );
    }
    return $new;
});

// ═══════════════════════════════════════════════════════════════
// GATEWAY CLASS
// ═══════════════════════════════════════════════════════════════

add_action( 'plugins_loaded', function() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class WC_Gateway_PPS_Approval extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = PPS_APPROVAL_GATEWAY_ID;
            $this->method_title       = 'Submit for Approval (PPS)';
            $this->method_description = 'Routes orders above a dollar threshold to a manual approval queue. No payment is captured until an admin approves and the customer pays via the emailed link.';
            $this->has_fields         = false;
            $this->icon               = '';

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled     = $this->get_option( 'enabled' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Enable / Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable Submit for Approval',
                    'default' => 'yes',
                ),
                'threshold' => array(
                    'title'       => 'Approval Threshold ($)',
                    'type'        => 'number',
                    'description' => 'Orders at or above this cart total will be required to use this method. Other gateways will be hidden.',
                    'default'     => PPS_APPROVAL_DEFAULT_THRESHOLD,
                    'desc_tip'    => false,
                    'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
                ),
                'title' => array(
                    'title'       => 'Customer-Facing Title',
                    'type'        => 'text',
                    'description' => 'Shown on the checkout page.',
                    'default'     => 'Submit for Approval',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Customer-Facing Description',
                    'type'        => 'textarea',
                    'description' => 'Shown beneath the gateway label at checkout.',
                    'default'     => 'Large orders are reviewed by our team before payment. We will email you a secure payment link once your order is approved (typically within one business day).',
                    'desc_tip'    => true,
                ),
                'admin_email' => array(
                    'title'       => 'Admin Notification Email',
                    'type'        => 'email',
                    'description' => 'Where to send "new approval needed" notifications. Leave blank to use the WordPress admin email.',
                    'default'     => get_option( 'admin_email' ),
                    'desc_tip'    => true,
                ),
                'pairing_note' => array(
                    'title'       => 'Stripe Authorize-Only (sub-threshold)',
                    'type'        => 'title',
                    'description' => 'For additional review on orders <em>below</em> the threshold, pair with the official Stripe plugin set to "authorize, capture later" — no extra code needed. This module only handles the high-value approval queue.',
                ),
            );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wc_add_notice( 'Order not found.', 'error' );
                return array( 'result' => 'failure' );
            }

            // Move to awaiting-approval. No payment captured.
            $order->update_status(
                PPS_APPROVAL_STATUS,
                'Order placed via Submit for Approval gateway. Awaiting admin review.'
            );

            // Stamp the order so we can tell on approval that it came through this flow.
            $order->update_meta_data( '_pps_approval_submitted_at', current_time( 'mysql' ) );
            $order->save();

            // Empty cart so the customer doesn't accidentally re-submit.
            if ( WC()->cart ) WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }
    }
});

// Register the gateway with WC
add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
    $gateways[] = 'WC_Gateway_PPS_Approval';
    return $gateways;
});

// ═══════════════════════════════════════════════════════════════
// CONDITIONAL GATEWAY VISIBILITY
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_available_payment_gateways', function( $gateways ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return $gateways;
    if ( empty( $gateways ) ) return $gateways;

    $approval_id = PPS_APPROVAL_GATEWAY_ID;

    // On the customer pay-for-order page (post-approval), never offer the
    // approval gateway — the order has already been approved.
    if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
        unset( $gateways[ $approval_id ] );
        return $gateways;
    }

    // Determine the relevant total
    $total = 0;
    if ( WC()->cart ) {
        $total = floatval( WC()->cart->get_total( 'edit' ) );
    }

    $threshold = pps_approval_get_threshold();

    if ( $total >= $threshold ) {
        // Above threshold: keep ONLY the approval gateway (if it's enabled)
        if ( isset( $gateways[ $approval_id ] ) ) {
            return array( $approval_id => $gateways[ $approval_id ] );
        }
        // Approval gateway disabled but threshold tripped — fall through and
        // let WC show the normal gateways rather than break checkout.
        return $gateways;
    }

    // Below threshold: hide the approval gateway
    unset( $gateways[ $approval_id ] );
    return $gateways;
}, 100 );

// ═══════════════════════════════════════════════════════════════
// EMAIL HELPER (uses WC mailer wrap_message for native styling)
// ═══════════════════════════════════════════════════════════════

function pps_approval_send_email( $to, $subject, $heading, $body_html ) {
    if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
        wp_mail( $to, $subject, wp_strip_all_tags( $body_html ) );
        return;
    }
    $mailer  = WC()->mailer();
    $message = $mailer->wrap_message( $heading, $body_html );
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    $mailer->send( $to, $subject, $message, $headers );
}

function pps_approval_format_order_summary( $order ) {
    $rows = '';
    foreach ( $order->get_items() as $item ) {
        $rows .= '<tr>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee">' . esc_html( $item->get_name() ) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">' . wc_price( $item->get_total() ) . '</td>'
            . '</tr>';
    }
    return '<table style="border-collapse:collapse;width:100%;font-size:14px">'
        . $rows
        . '<tr><td style="padding:8px;font-weight:600;text-align:right">Total</td>'
        . '<td style="padding:8px;font-weight:600;text-align:right">' . wc_price( $order->get_total() ) . '</td></tr>'
        . '</table>';
}

// ═══════════════════════════════════════════════════════════════
// EMAIL: ON SUBMISSION → CUSTOMER + ADMIN
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_order_status_' . PPS_APPROVAL_STATUS, function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( $order->get_meta( '_pps_approval_emails_sent' ) ) return;

    $settings = pps_approval_get_settings();

    // ── Customer: "we received your order, awaiting approval" ──
    $customer_email = $order->get_billing_email();
    if ( $customer_email ) {
        $body  = '<p>Thank you for your order with Priority Print Service. Because of the order size, our team will review the specs before payment is requested.</p>';
        $body .= '<p>You should hear back within one business day with either an approval and a secure payment link, or a follow-up question. Your card has not been charged.</p>';
        $body .= '<h3 style="margin:20px 0 8px">Order #' . $order->get_order_number() . '</h3>';
        $body .= pps_approval_format_order_summary( $order );
        $body .= '<p style="color:#666;font-size:13px;margin-top:20px">If you need to make changes or have questions, just reply to this email.</p>';

        pps_approval_send_email(
            $customer_email,
            'Order #' . $order->get_order_number() . ' received — awaiting approval',
            'Awaiting approval',
            $body
        );
    }

    // ── Admin: "review needed" ──
    $admin_to = $settings['admin_email'] ?: get_option( 'admin_email' );
    if ( $admin_to ) {
        $edit_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
        // HPOS edit URL fallback
        if ( function_exists( 'wc_get_container' ) ) {
            $hpos_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );
            $edit_url = $hpos_url;
        }

        $body  = '<p>A new high-value order is awaiting approval.</p>';
        $body .= '<p><strong>Customer:</strong> ' . esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) . '<br>';
        $body .= '<strong>Email:</strong> ' . esc_html( $customer_email ) . '<br>';
        if ( $order->get_billing_company() ) {
            $body .= '<strong>Company:</strong> ' . esc_html( $order->get_billing_company() ) . '<br>';
        }
        $body .= '<strong>Total:</strong> ' . wc_price( $order->get_total() ) . '</p>';
        $body .= pps_approval_format_order_summary( $order );
        $body .= '<p style="margin-top:20px"><a href="' . esc_url( $edit_url ) . '" style="display:inline-block;background:#007eff;color:#fff;padding:10px 18px;border-radius:4px;text-decoration:none;font-weight:600">Review order →</a></p>';

        $total_plain = html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ), ENT_QUOTES, 'UTF-8' );
        pps_approval_send_email(
            $admin_to,
            '[Approval needed] Order #' . $order->get_order_number() . ' — ' . $total_plain,
            'Order awaiting approval',
            $body
        );
    }

    $order->update_meta_data( '_pps_approval_emails_sent', current_time( 'mysql' ) );
    $order->save();
});

// ═══════════════════════════════════════════════════════════════
// EMAIL: ON APPROVAL → CUSTOMER (with payment link)
// ═══════════════════════════════════════════════════════════════

function pps_approval_send_approved_email( $order ) {
    $customer_email = $order->get_billing_email();
    if ( ! $customer_email ) return;

    $pay_url = $order->get_checkout_payment_url();

    $body  = '<p>Good news — your order has been approved by our team and is ready for payment.</p>';
    $body .= '<p>Click the button below to pay securely. Production starts as soon as payment clears.</p>';
    $body .= '<p style="margin:24px 0"><a href="' . esc_url( $pay_url ) . '" style="display:inline-block;background:#46882c;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;font-size:15px">Pay for order #' . $order->get_order_number() . ' →</a></p>';
    $body .= '<h3 style="margin:20px 0 8px">Order summary</h3>';
    $body .= pps_approval_format_order_summary( $order );
    $body .= '<p style="color:#666;font-size:13px;margin-top:20px">Questions? Just reply to this email.</p>';

    pps_approval_send_email(
        $customer_email,
        'Order #' . $order->get_order_number() . ' approved — ready for payment',
        'Approved — ready for payment',
        $body
    );
}

// ═══════════════════════════════════════════════════════════════
// ADMIN META BOX: APPROVE / REJECT
// ═══════════════════════════════════════════════════════════════

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'pps_approval_actions',
        '✋ Approval Decision',
        'pps_approval_meta_box',
        'shop_order',
        'side',
        'high'
    );
    add_meta_box(
        'pps_approval_actions',
        '✋ Approval Decision',
        'pps_approval_meta_box',
        'woocommerce_page_wc-orders',
        'side',
        'high'
    );
});

function pps_approval_meta_box( $post_or_order ) {
    $order = ( $post_or_order instanceof WP_Post )
        ? wc_get_order( $post_or_order->ID )
        : $post_or_order;

    if ( ! $order ) {
        echo '<p style="color:#999">Order not loaded.</p>';
        return;
    }

    $status = $order->get_status();

    // Show full UI only for awaiting-approval orders
    if ( $status !== PPS_APPROVAL_STATUS ) {
        $approved_at = $order->get_meta( '_pps_approval_approved_at' );
        $approved_by = $order->get_meta( '_pps_approval_approved_by' );
        $rejected_at = $order->get_meta( '_pps_approval_rejected_at' );
        $rejected_by = $order->get_meta( '_pps_approval_rejected_by' );
        $reason      = $order->get_meta( '_pps_approval_rejection_reason' );

        if ( $approved_at ) {
            echo '<p style="margin:0;color:#46882c"><strong>✓ Approved</strong></p>';
            echo '<p style="margin:4px 0 0;font-size:12px;color:#666">' . esc_html( $approved_at );
            if ( $approved_by ) echo ' &middot; by ' . esc_html( $approved_by );
            echo '</p>';
        } elseif ( $rejected_at ) {
            echo '<p style="margin:0;color:#b32d2e"><strong>✗ Rejected</strong></p>';
            echo '<p style="margin:4px 0 0;font-size:12px;color:#666">' . esc_html( $rejected_at );
            if ( $rejected_by ) echo ' &middot; by ' . esc_html( $rejected_by );
            echo '</p>';
            if ( $reason ) echo '<p style="margin:8px 0 0;font-size:12px"><em>' . esc_html( $reason ) . '</em></p>';
        } else {
            echo '<p style="color:#999;font-style:italic;margin:0">Not an approval-flow order. Current status: <strong>' . esc_html( wc_get_order_status_name( $status ) ) . '</strong>.</p>';
        }
        return;
    }

    $submitted_at = $order->get_meta( '_pps_approval_submitted_at' );
    if ( $submitted_at ) {
        echo '<p style="margin:0 0 10px;font-size:12px;color:#666">Submitted ' . esc_html( $submitted_at ) . '</p>';
    }

    echo '<p style="margin:0 0 12px;font-size:13px"><strong>' . wc_price( $order->get_total() ) . '</strong> awaiting your decision.</p>';

    $action_url = admin_url( 'admin-post.php' );
    ?>
    <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin:0 0 8px">
        <?php wp_nonce_field( 'pps_approval_decision_' . $order->get_id() ); ?>
        <input type="hidden" name="action" value="pps_approval_decision">
        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
        <input type="hidden" name="decision" value="approve">
        <button type="submit" class="button button-primary" style="width:100%;background:#46882c;border-color:#46882c;text-shadow:none;box-shadow:none">
            ✓ Approve &amp; Send Payment Link
        </button>
    </form>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin:0">
        <?php wp_nonce_field( 'pps_approval_decision_' . $order->get_id() ); ?>
        <input type="hidden" name="action" value="pps_approval_decision">
        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
        <input type="hidden" name="decision" value="reject">
        <label style="display:block;font-size:12px;font-weight:600;color:#555;margin:10px 0 4px">Rejection reason (optional, shown to customer)</label>
        <textarea name="reason" rows="3" style="width:100%;font-size:12px" placeholder="e.g. Specs need revision before we can accept this order."></textarea>
        <button type="submit" class="button" style="width:100%;margin-top:6px;color:#b32d2e;border-color:#b32d2e"
            onclick="return confirm('Reject this order? The customer will receive a cancellation email.')">
            ✗ Reject Order
        </button>
    </form>
    <p style="margin:10px 0 0;font-size:11px;color:#888">Approving moves the order to <strong>Pending payment</strong> and emails the customer a secure pay link with the regular gateways available.</p>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// ADMIN-POST HANDLER: APPROVE / REJECT
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_post_pps_approval_decision', function() {
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( 'Unauthorized', 403 );
    }

    $order_id = absint( $_POST['order_id'] ?? 0 );
    $decision = sanitize_key( $_POST['decision'] ?? '' );
    if ( ! $order_id || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
        wp_die( 'Invalid request.' );
    }

    check_admin_referer( 'pps_approval_decision_' . $order_id );

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_die( 'Order not found.' );

    if ( $order->get_status() !== PPS_APPROVAL_STATUS ) {
        wp_safe_redirect( pps_approval_admin_order_url( $order_id ) );
        exit;
    }

    $user      = wp_get_current_user();
    $user_disp = $user && $user->display_name ? $user->display_name : 'admin';

    if ( $decision === 'approve' ) {
        $order->update_meta_data( '_pps_approval_approved_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_pps_approval_approved_by', $user_disp );
        $order->save();

        $order->update_status(
            'pending',
            sprintf( 'Approved by %s. Customer emailed pay link.', $user_disp )
        );

        pps_approval_send_approved_email( $order );
    } else {
        $reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
        $order->update_meta_data( '_pps_approval_rejected_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_pps_approval_rejected_by', $user_disp );
        if ( $reason ) {
            $order->update_meta_data( '_pps_approval_rejection_reason', $reason );
        }
        $order->save();

        $note = sprintf( 'Rejected by %s.', $user_disp );
        if ( $reason ) $note .= ' Reason: ' . $reason;
        $order->update_status( 'cancelled', $note );

        // Email the customer
        $customer_email = $order->get_billing_email();
        if ( $customer_email ) {
            $body  = '<p>Thank you for your interest in Priority Print Service. After review, we are unable to fulfill order #' . $order->get_order_number() . ' as submitted.</p>';
            if ( $reason ) {
                $body .= '<p><strong>Reason:</strong> ' . esc_html( $reason ) . '</p>';
            }
            $body .= '<p>No payment was charged. If you would like to revise the order or discuss alternatives, please reply to this email and we will be glad to help.</p>';

            pps_approval_send_email(
                $customer_email,
                'Order #' . $order->get_order_number() . ' — unable to fulfill',
                'Unable to fulfill order',
                $body
            );
        }
    }

    wp_safe_redirect( pps_approval_admin_order_url( $order_id ) );
    exit;
});

/**
 * Returns the correct edit URL for an order, respecting HPOS if active.
 */
function pps_approval_admin_order_url( $order_id ) {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
        return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
    }
    return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
}

// ═══════════════════════════════════════════════════════════════
// ADMIN ORDER LIST: STATUS BADGE STYLING
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order', 'woocommerce_page_wc-orders--edit' ), true ) ) return;
    echo '<style>
        mark.order-status.status-pps-approval,
        .order-status.status-pps-approval {
            background: #fff3e0;
            color: #8a4b00;
        }
    </style>';
});

// ═══════════════════════════════════════════════════════════════
// HIDE INTERNAL META KEYS FROM ORDER EMAILS / FRONTEND
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_hidden_order_itemmeta', function( $hidden ) {
    return array_merge( $hidden, array(
        '_pps_approval_submitted_at',
        '_pps_approval_emails_sent',
        '_pps_approval_approved_at',
        '_pps_approval_approved_by',
        '_pps_approval_rejected_at',
        '_pps_approval_rejected_by',
        '_pps_approval_rejection_reason',
    ) );
});
