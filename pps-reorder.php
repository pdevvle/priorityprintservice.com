<?php
/**
 * PPS Reorder & Guest Lookup
 *
 * Self-contained module loaded by pps-calculators.php. Provides:
 *   - Shared helpers (pps_build_reorder_url, pps_render_pps_item_card,
 *     pps_status_to_pill_kind, pps_get_order_refund_date,
 *     pps_build_single_item_reorder_url, pps_reorder_field_whitelist).
 *   - [pps_order_lookup] shortcode + form handler (order # + billing
 *     email + order date verification, rate-limited, honeypot, nonce,
 *     30-min WC session grant).
 *   - Single-item reorder handler for legacy/WCPA-era orders, fired on
 *     template_redirect, with original unit-price preserved across the
 *     cart calculation.
 *   - Scoped .pps-acct stylesheet, enqueued only on pages containing the
 *     [pps_order_lookup] shortcode.
 *
 * Depends on: WordPress, WooCommerce, and pps_thumb_url() from
 * pps-gdrive.php. The PPS_CALC_VERSION constant from pps-calculators.php
 * is used as the asset version on the enqueued style.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// SHARED: PPS ORDER VIEW HELPERS
// ═══════════════════════════════════════════════════════════════

function pps_reorder_field_whitelist() {
    return array(
        'sizeLabel', 'customLong', 'customShort', 'bindDir',
        'sets',
        'insideColor', 'coverColor',
        'insidePaper', 'insidePaperType',
        'coverMode', 'coverPaper', 'coverPaperType',
        'twoStaple', 'vividPrint',
        'coating', 'bundling', 'roundCorner',
        'artwork', 'artEditPages', 'bleed', 'proof',
        'shipState',
    );
}

function pps_build_reorder_url( $item ) {
    $metadata_json = $item->get_meta( '_pps_metadata' );
    if ( ! $metadata_json ) return '';

    $product = wc_get_product( $item->get_product_id() );
    if ( ! $product ) return '';

    $full = json_decode( $metadata_json, true );
    if ( ! is_array( $full ) ) return '';

    $reorder_config = array();
    foreach ( pps_reorder_field_whitelist() as $key ) {
        if ( isset( $full[ $key ] ) ) {
            $reorder_config[ $key ] = $full[ $key ];
        }
    }

    $encoded = rtrim( strtr( base64_encode( json_encode( $reorder_config ) ), '+/', '-_' ), '=' );
    return add_query_arg( 'pps_reorder', $encoded, $product->get_permalink() );
}

function pps_build_single_item_reorder_url( $order, $item ) {
    $product = wc_get_product( $item->get_product_id() );
    if ( ! $product || ! $product->exists() ) return '';

    $url = add_query_arg( array(
        'pps_reorder_order' => $order->get_id(),
        'pps_reorder_item'  => $item->get_id(),
    ), wc_get_cart_url() );

    return wp_nonce_url( $url, 'pps_reorder_item_' . $order->get_id() . '_' . $item->get_id() );
}

function pps_status_to_pill_kind( $status ) {
    $status = (string) $status;
    if ( strpos( $status, 'wc-' ) === 0 ) $status = substr( $status, 3 );
    switch ( $status ) {
        case 'completed':
            return 'delivered';
        case 'cancelled':
            return 'cancelled';
        case 'refunded':
            return 'refunded';
        case 'failed':
            return 'cancelled';
        case 'pending':
        case 'on-hold':
        case 'processing':
        default:
            return 'processing';
    }
}

function pps_get_order_refund_date( $order ) {
    if ( ! $order ) return null;
    if ( ! function_exists( 'wc_get_order_refunds' ) ) return null;
    $refunds = wc_get_order_refunds( $order );
    if ( empty( $refunds ) ) return null;
    $latest = null;
    foreach ( $refunds as $refund ) {
        $d = $refund->get_date_created();
        if ( $d && ( ! $latest || $d->getTimestamp() > $latest->getTimestamp() ) ) {
            $latest = $d;
        }
    }
    return $latest;
}

function pps_render_pps_item_card( $item, $order ) {
    $metadata_json = $item->get_meta( '_pps_metadata' );
    $is_pps = (bool) $metadata_json;

    // Legacy (WCPA-era) fallback: skip cards for items whose product is gone
    if ( ! $is_pps ) {
        $product = wc_get_product( $item->get_product_id() );
        if ( ! $product || ! $product->exists() ) return '';
    } else {
        $product = wc_get_product( $item->get_product_id() );
    }

    $product_url = ( $product && $product->exists() ) ? $product->get_permalink() : '';

    $summary         = $is_pps ? (string) $item->get_meta( '_pps_summary' ) : '';
    $delivery        = $is_pps ? (string) $item->get_meta( '_pps_delivery_date' ) : '';
    $thumb_name      = $is_pps ? (string) $item->get_meta( '_pps_artwork_thumb' ) : '';
    $rush            = $is_pps ? (float) $item->get_meta( '_pps_rush' ) : 0;
    $reorder         = $is_pps ? pps_build_reorder_url( $item ) : '';
    $legacy_reorder  = $is_pps ? '' : pps_build_single_item_reorder_url( $order, $item );
    $legacy_meta     = $is_pps ? array() : $item->get_formatted_meta_data( '' );

    $status      = $order->get_status();
    $is_inactive = in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true );
    $pill_kind   = pps_status_to_pill_kind( $status );
    $status_lbl  = wc_get_order_status_name( $status );

    $delivery_pretty = '';
    if ( $delivery && ! $is_inactive ) {
        try {
            $d = new DateTime( $delivery );
            $delivery_pretty = $d->format( 'l, M j, Y' );
        } catch ( Exception $e ) {
            $delivery_pretty = $delivery;
        }
    }

    $refund_pretty = '';
    if ( $is_inactive ) {
        $refund_dt = pps_get_order_refund_date( $order );
        if ( $refund_dt ) {
            $refund_pretty = wc_format_datetime( $refund_dt, get_option( 'date_format' ) );
        }
    }

    $thumb_url = '';
    if ( $thumb_name && function_exists( 'pps_thumb_url' ) ) {
        $thumb_url = trailingslashit( pps_thumb_url() ) . $thumb_name;
    }

    $date_created = $order->get_date_created();
    $date_str = $date_created ? wc_format_datetime( $date_created, get_option( 'date_format' ) ) : '';

    $card_classes = 'order-card';
    if ( $is_inactive ) {
        $card_classes .= ' cancelled';
    } elseif ( ! $is_pps ) {
        $card_classes .= ' legacy';
    }

    ob_start();
    ?>
    <article class="<?php echo esc_attr( $card_classes ); ?>">
        <?php if ( $is_pps && $thumb_url ) : ?>
            <div class="oc-thumb">
                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy" />
            </div>
        <?php else : ?>
            <div class="oc-thumb-empty" aria-hidden="true"></div>
        <?php endif; ?>

        <div class="oc-body">
            <?php if ( $product_url ) : ?>
                <a href="<?php echo esc_url( $product_url ); ?>" class="oc-title"><?php echo esc_html( $item->get_name() ); ?></a>
            <?php else : ?>
                <span class="oc-title"><?php echo esc_html( $item->get_name() ); ?></span>
            <?php endif; ?>

            <div class="oc-meta">
                <span>Order #<?php echo esc_html( $order->get_order_number() ); ?></span>
                <?php if ( $date_str ) : ?>
                    <span class="dot">&middot;</span>
                    <span><?php echo esc_html( $date_str ); ?></span>
                <?php endif; ?>
                <span class="dot">&middot;</span>
                <span class="pill pill-<?php echo esc_attr( $pill_kind ); ?>"><?php echo esc_html( $status_lbl ); ?></span>
                <?php if ( $rush > 0 ) : ?>
                    <span class="pill pill-rush">Rush</span>
                <?php endif; ?>
            </div>

            <?php if ( $is_inactive ) : ?>
                <div class="oc-eta" style="color:var(--mid)">
                    <?php if ( $refund_pretty ) : ?>
                        Refunded on <?php echo esc_html( $refund_pretty ); ?> &middot; No further action needed.
                    <?php else : ?>
                        No further action needed.
                    <?php endif; ?>
                </div>
            <?php elseif ( $delivery_pretty ) : ?>
                <div class="oc-eta">Estimated delivery: <strong><?php echo esc_html( $delivery_pretty ); ?></strong></div>
            <?php endif; ?>

            <?php if ( $summary ) : ?>
                <pre class="oc-specs"><?php echo esc_html( $summary ); ?></pre>
            <?php elseif ( ! empty( $legacy_meta ) ) : ?>
                <div class="oc-specs">
                    <?php foreach ( $legacy_meta as $m ) : ?>
                        <div><strong><?php echo esc_html( wp_strip_all_tags( $m->display_key ) ); ?>:</strong> <?php echo esc_html( wp_strip_all_tags( $m->display_value ) ); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="oc-actions">
            <?php if ( $is_inactive ) : ?>
                <button type="button" class="btn btn-ghost" disabled style="opacity:.6;cursor:not-allowed">Reorder unavailable</button>
            <?php elseif ( $reorder ) : ?>
                <a href="<?php echo esc_url( $reorder ); ?>" class="btn btn-primary">Reorder</a>
            <?php elseif ( $legacy_reorder ) : ?>
                <a href="<?php echo esc_url( $legacy_reorder ); ?>" class="btn btn-primary">Reorder (same as before)</a>
                <p class="oc-caveat">Specs can&rsquo;t be changed &mdash; contact us for edits.</p>
            <?php endif; ?>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════
// GUEST ORDER LOOKUP (shortcode + handler)
// ═══════════════════════════════════════════════════════════════

function pps_order_lookup_rate_key() {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'pps';
    return 'pps_lookup_' . substr( hash( 'sha256', $ip . $salt ), 0, 24 );
}

function pps_order_lookup_is_rate_limited() {
    return (int) get_transient( pps_order_lookup_rate_key() ) >= 5;
}

function pps_order_lookup_record_attempt() {
    $key = pps_order_lookup_rate_key();
    $attempts = (int) get_transient( $key );
    set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
}

function pps_order_lookup_grant( $email ) {
    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'pps_lookup_email', sanitize_email( $email ) );
        WC()->session->set( 'pps_lookup_granted_at', time() );
    }
}

function pps_order_lookup_revoke() {
    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'pps_lookup_email', null );
        WC()->session->set( 'pps_lookup_granted_at', null );
    }
}

function pps_order_lookup_active_email() {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return '';
    $email = WC()->session->get( 'pps_lookup_email' );
    $granted = (int) WC()->session->get( 'pps_lookup_granted_at' );
    if ( ! $email || ! $granted ) return '';
    if ( ( time() - $granted ) > 30 * MINUTE_IN_SECONDS ) {
        pps_order_lookup_revoke();
        return '';
    }
    return $email;
}

add_shortcode( 'pps_order_lookup', 'pps_order_lookup_shortcode' );

function pps_order_lookup_shortcode() {
    $error_kind = '';

    if ( isset( $_POST['pps_lookup_signout'] )
         && isset( $_POST['pps_lookup_signout_nonce'] )
         && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pps_lookup_signout_nonce'] ) ), 'pps_order_lookup_signout' ) ) {
        pps_order_lookup_revoke();
    }

    if ( isset( $_POST['pps_lookup_submit'] )
         && isset( $_POST['pps_lookup_nonce'] )
         && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pps_lookup_nonce'] ) ), 'pps_order_lookup' ) ) {

        $honey = isset( $_POST['pps_lookup_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['pps_lookup_hp'] ) ) : '';

        if ( $honey !== '' ) {
            $error_kind = 'mismatch';
        } elseif ( pps_order_lookup_is_rate_limited() ) {
            $error_kind = 'rate';
        } else {
            pps_order_lookup_record_attempt();

            $num_raw   = absint( $_POST['pps_lookup_order'] ?? 0 );
            $email_raw = sanitize_email( wp_unslash( $_POST['pps_lookup_email'] ?? '' ) );
            $date_raw  = sanitize_text_field( wp_unslash( $_POST['pps_lookup_date'] ?? '' ) );

            $order = $num_raw ? wc_get_order( $num_raw ) : null;
            $ok = false;
            $matched_email = '';

            if ( $order ) {
                $order_email = strtolower( (string) $order->get_billing_email() );
                $sub_email   = strtolower( $email_raw );
                $created     = $order->get_date_created();
                $order_date  = $created ? $created->format( 'Y-m-d' ) : '';

                $email_match = ( $sub_email !== '' ) && hash_equals( $order_email, $sub_email );
                $date_match  = ( $date_raw !== '' ) && hash_equals( $order_date, $date_raw );
                $ok = $email_match && $date_match;
                if ( $ok ) $matched_email = $order->get_billing_email();
            }

            if ( $ok ) {
                pps_order_lookup_grant( $matched_email );
            } else {
                $error_kind = 'mismatch';
            }
        }
    }

    ob_start();
    $active_email = pps_order_lookup_active_email();
    if ( $active_email ) {
        pps_order_lookup_render_orders( $active_email );
    } else {
        pps_order_lookup_render_form( $error_kind );
    }
    return ob_get_clean();
}

function pps_order_lookup_render_banner( $kind ) {
    if ( ! $kind ) return;
    $is_rate = ( $kind === 'rate' );
    ?>
    <div class="banner banner-error" role="alert">
        <svg class="banner-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
            <path d="M8 4v5M8 11.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <div>
            <?php if ( $is_rate ) : ?>
                <strong>Too many attempts.</strong>
                <div style="font-size:12px;margin-top:2px;color:var(--mid)">Please try again in 15 minutes.</div>
            <?php else : ?>
                <strong>We couldn&rsquo;t find a matching order.</strong>
                <div style="font-size:12px;margin-top:2px;color:var(--mid)">Double-check the order number, billing email, and order date.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function pps_order_lookup_render_form( $error_kind = '' ) {
    $err_class  = ( $error_kind === 'mismatch' ) ? ' has-error' : '';
    $signin_url = wc_get_page_permalink( 'myaccount' );
    ?>
    <div class="pps-acct">
        <div class="lookup-shell">
            <p class="lookup-eyebrow">Guest order lookup</p>
            <h2 class="lookup-title">Find your print orders</h2>

            <form class="form" method="post" novalidate>
                <?php pps_order_lookup_render_banner( $error_kind ); ?>

                <p class="form-intro">Enter your order details to view your print orders. <strong style="color:var(--key)">All three fields must match.</strong></p>

                <?php wp_nonce_field( 'pps_order_lookup', 'pps_lookup_nonce' ); ?>
                <input class="honeypot" type="text" tabindex="-1" autocomplete="off" name="pps_lookup_hp" value="" aria-hidden="true" />

                <div class="field<?php echo esc_attr( $err_class ); ?>">
                    <label for="pps_lookup_order">Order number <span class="req">*</span></label>
                    <input type="text" id="pps_lookup_order" name="pps_lookup_order" inputmode="numeric" pattern="[0-9]*" placeholder="e.g. 2204" required>
                </div>

                <div class="field<?php echo esc_attr( $err_class ); ?>">
                    <label for="pps_lookup_email">Billing email <span class="req">*</span></label>
                    <input type="email" id="pps_lookup_email" name="pps_lookup_email" placeholder="you@example.com" required>
                </div>

                <div class="field<?php echo esc_attr( $err_class ); ?>">
                    <label for="pps_lookup_date">Order date <span class="req">*</span></label>
                    <input type="date" id="pps_lookup_date" name="pps_lookup_date" required>
                    <span class="field-hint">The date the order was placed.</span>
                </div>

                <button type="submit" name="pps_lookup_submit" value="1" class="btn btn-primary btn-submit">View my orders</button>

                <?php if ( $signin_url ) : ?>
                    <p class="form-foot">Have an account? <a href="<?php echo esc_url( $signin_url ); ?>">Sign in</a> for the full dashboard.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php
}

function pps_order_lookup_render_orders( $email ) {
    $orders = wc_get_orders( array(
        'billing_email' => $email,
        'limit'         => 20,
        'orderby'       => 'date',
        'order'         => 'DESC',
        'type'          => 'shop_order',
        'status'        => array_keys( wc_get_order_statuses() ),
    ) );

    $buffer = '';
    $rendered = 0;
    foreach ( $orders as $order ) {
        foreach ( $order->get_items() as $item ) {
            $card = pps_render_pps_item_card( $item, $order );
            if ( $card ) {
                $buffer .= $card;
                $rendered++;
            }
        }
    }

    $signin_url = wc_get_page_permalink( 'myaccount' );
    ?>
    <div class="pps-acct">
        <div style="max-width:760px;margin:0 auto">
            <div class="auth-strip">
                <div>Showing orders for <strong><?php echo esc_html( $email ); ?></strong></div>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field( 'pps_order_lookup_signout', 'pps_lookup_signout_nonce' ); ?>
                    <button type="submit" name="pps_lookup_signout" value="1" class="btn btn-ghost" style="padding:6px 12px;font-size:12px">Sign out of lookup</button>
                </form>
            </div>

            <?php if ( $rendered === 0 ) : ?>
                <div class="empty"><span>No print orders found for this email.</span></div>
            <?php else : ?>
                <?php echo $buffer; // already-escaped per-card ?>
                <?php if ( $signin_url ) : ?>
                    <p class="results-foot">Want to edit a pending order? <a href="<?php echo esc_url( $signin_url ); ?>">Sign in to your account.</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// LEGACY (WCPA) SINGLE-ITEM REORDER HANDLER
// ═══════════════════════════════════════════════════════════════

add_action( 'template_redirect', 'pps_handle_single_item_reorder' );

function pps_handle_single_item_reorder() {
    if ( empty( $_GET['pps_reorder_item'] ) || empty( $_GET['pps_reorder_order'] ) ) return;
    if ( empty( $_GET['_wpnonce'] ) ) return;

    $order_id = absint( $_GET['pps_reorder_order'] );
    $item_id  = absint( $_GET['pps_reorder_item'] );
    $nonce    = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

    if ( ! $order_id || ! $item_id ) return;

    $redirect = wc_get_cart_url();

    if ( ! wp_verify_nonce( $nonce, 'pps_reorder_item_' . $order_id . '_' . $item_id ) ) {
        wc_add_notice( 'That reorder link has expired. Please reload your orders page and try again.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wc_add_notice( 'Order not found.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    $allowed = false;
    if ( is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) {
        $allowed = true;
    }
    $lookup_email = pps_order_lookup_active_email();
    if ( $lookup_email && strtolower( (string) $order->get_billing_email() ) === strtolower( $lookup_email ) ) {
        $allowed = true;
    }
    if ( ! $allowed ) {
        wc_add_notice( 'You do not have access to that order.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    $item = $order->get_item( $item_id );
    if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
        wc_add_notice( 'Order item not found.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    $product_id   = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $quantity     = max( 1, (int) $item->get_quantity() );
    $product      = wc_get_product( $variation_id ? $variation_id : $product_id );

    if ( ! $product || ! $product->exists() ) {
        wc_add_notice( 'That product is no longer available.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    if ( ! WC()->cart ) {
        wp_safe_redirect( $redirect );
        exit;
    }

    $variations = array();
    if ( $variation_id ) {
        foreach ( $item->get_meta_data() as $meta ) {
            if ( taxonomy_is_product_attribute( $meta->key ) ) {
                $variations[ $meta->key ] = $meta->value;
            } elseif ( meta_is_product_attribute( $meta->key, $meta->value, $product_id ) ) {
                $variations[ $meta->key ] = $meta->value;
            }
        }
    }

    // Let WCPA (and any other add-ons) restore their cart item data from the line item
    $cart_item_data = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $item, $order );

    // Preserve the original unit price so totals don't drift from the historical order
    $unit_price = $quantity > 0 ? ( (float) $item->get_subtotal() / $quantity ) : (float) $item->get_subtotal();
    $cart_item_data['pps_legacy_unit_price'] = $unit_price;
    $cart_item_data['pps_legacy_source']     = array(
        'order_id' => $order_id,
        'item_id'  => $item_id,
    );

    $cart_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, $cart_item_data );

    if ( ! $cart_key ) {
        wc_add_notice( 'Could not add that item to your cart.', 'error' );
        wp_safe_redirect( $redirect );
        exit;
    }

    wc_add_notice( 'Added to your cart from order #' . $order->get_order_number() . '.', 'success' );
    wp_safe_redirect( $redirect );
    exit;
}

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
    if ( isset( $values['pps_legacy_unit_price'] ) ) {
        $cart_item['pps_legacy_unit_price'] = $values['pps_legacy_unit_price'];
    }
    if ( isset( $values['pps_legacy_source'] ) ) {
        $cart_item['pps_legacy_source'] = $values['pps_legacy_source'];
    }
    return $cart_item;
}, 10, 2 );

add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( ! $cart ) return;
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['pps_legacy_unit_price'] ) && isset( $cart_item['data'] ) ) {
            $cart_item['data']->set_price( (float) $cart_item['pps_legacy_unit_price'] );
        }
    }
}, 20 );

// ═══════════════════════════════════════════════════════════════
// ACCOUNT UI: SCOPED STYLES (loaded on pages with [pps_order_lookup])
// ═══════════════════════════════════════════════════════════════

function pps_should_load_acct_styles() {
    if ( is_singular() ) {
        $post = get_post();
        if ( $post && has_shortcode( (string) $post->post_content, 'pps_order_lookup' ) ) return true;
    }
    return false;
}

add_action( 'wp_enqueue_scripts', function() {
    if ( ! pps_should_load_acct_styles() ) return;
    $ver = defined( 'PPS_CALC_VERSION' ) ? PPS_CALC_VERSION : '1.0.0';
    wp_register_style( 'pps-acct-ui', false, array(), $ver );
    wp_enqueue_style( 'pps-acct-ui' );
    wp_add_inline_style( 'pps-acct-ui', pps_acct_ui_css() );
});

function pps_acct_ui_css() {
    return <<<'CSS'
.pps-acct {
  --process-cyan: #007eff;
  --process-cyan-light: #e0edff;
  --process-cyan-dark: #0062c4;
  --process-magenta: #d1246a;
  --process-magenta-light: #fce8ef;
  --process-yellow: #f0a830;
  --process-yellow-light: #fef5e0;
  --key: #2b2b2b;
  --mid: #555;
  --light: #999;
  --bg: #f4f4f4;
  --card-bg: #f0faff;
  --card-border: #b8e0f5;
  --border: #d8d8d8;
  --white: #ffffff;
  --error: #c25050;
  --error-bg: #fbeded;
  --font-ui: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
  font-family: var(--font-ui);
  color: var(--key);
  -webkit-font-smoothing: antialiased;
}
.pps-acct *, .pps-acct *::before, .pps-acct *::after { box-sizing: border-box; }

.pps-acct .h-page { font-size: 26px; font-weight: 700; letter-spacing: -0.01em; color: var(--key); margin: 0 0 6px; }
.pps-acct .h-sub { color: var(--mid); font-size: 13px; margin: 0 0 22px; }

.pps-acct .order-card {
  background: var(--card-bg);
  border: 1px solid var(--card-border);
  border-radius: 6px;
  padding: 18px;
  display: grid;
  grid-template-columns: 140px 1fr auto;
  gap: 20px;
  margin-bottom: 14px;
  align-items: start;
}
.pps-acct .order-card.legacy { background: var(--white); border-color: var(--border); }
.pps-acct .order-card.cancelled { background: var(--white); border-color: var(--border); opacity: 0.65; }

.pps-acct .oc-thumb {
  width: 140px; height: 140px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: 4px;
  overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.pps-acct .oc-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pps-acct .oc-thumb-empty { width: 140px; height: 140px; }

.pps-acct .oc-body { min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.pps-acct .oc-title { font-size: 16px; font-weight: 600; color: var(--process-cyan); margin: 0; text-decoration: none; }
.pps-acct .oc-title:hover { text-decoration: underline; }
.pps-acct .oc-meta { font-size: 13px; color: var(--mid); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pps-acct .oc-meta .dot { color: var(--light); }
.pps-acct .oc-eta { font-size: 13px; color: var(--key); margin-top: 2px; }
.pps-acct .oc-eta strong { font-weight: 600; }

.pps-acct .oc-specs {
  margin: 8px 0 0;
  padding: 10px 12px;
  background: rgba(255,255,255,0.6);
  border-radius: 4px;
  font-family: var(--font-ui);
  font-size: 13px;
  line-height: 1.55;
  color: var(--mid);
  white-space: pre-wrap;
}
.pps-acct .order-card.legacy .oc-specs { background: var(--bg); }
.pps-acct .oc-specs > div { margin: 0 0 2px; }
.pps-acct .oc-specs strong { color: var(--key); font-weight: 600; }

.pps-acct .oc-actions { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; min-width: 160px; }

.pps-acct .pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 9px; border-radius: 10px;
  font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
}
.pps-acct .pill-processing { background: var(--process-cyan-light); color: var(--process-cyan-dark); }
.pps-acct .pill-printing   { background: var(--process-yellow-light); color: #8a5e10; }
.pps-acct .pill-shipped    { background: #e6f3e0; color: #4a7a2c; }
.pps-acct .pill-delivered  { background: #e8e8e8; color: var(--mid); }
.pps-acct .pill-rush       { background: var(--process-magenta-light); color: var(--process-magenta); }
.pps-acct .pill-cancelled  { background: #f0e0e0; color: var(--error); }
.pps-acct .pill-refunded   { background: #f0e0e0; color: var(--error); }

.pps-acct .btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 9px 16px; border-radius: 6px;
  font-size: 13px; font-weight: 600;
  border: 1px solid transparent; cursor: pointer; text-decoration: none; white-space: nowrap;
  font-family: var(--font-ui);
  transition: background .12s, border-color .12s, color .12s, box-shadow .12s;
}
.pps-acct .btn-primary { background: var(--process-cyan); color: var(--white); }
.pps-acct .btn-primary:hover { background: var(--process-cyan-dark); color: var(--white); }
.pps-acct .btn-primary:focus-visible { background: var(--process-cyan-dark); outline: 3px solid rgba(0,126,255,0.32); outline-offset: 1px; }
.pps-acct .btn-ghost { background: transparent; color: var(--mid); border-color: var(--border); }
.pps-acct .btn-ghost:hover { color: var(--key); border-color: var(--light); }
.pps-acct .btn-link { background: none; border: none; color: var(--process-cyan); padding: 0; font-weight: 600; text-decoration: none; }
.pps-acct .btn-link:hover { text-decoration: underline; }

.pps-acct .oc-caveat { font-size: 11px; color: var(--light); text-align: right; max-width: 180px; line-height: 1.45; margin: 0; }

.pps-acct .pager {
  display: flex; justify-content: space-between; align-items: center;
  padding: 18px 4px 4px;
  border-top: 1px solid var(--border);
  margin-top: 8px;
  font-size: 13px; color: var(--mid);
}
.pps-acct .pager a, .pps-acct .pager span { color: var(--mid); text-decoration: none; font-weight: 600; }
.pps-acct .pager a:hover { color: var(--process-cyan); }
.pps-acct .pager .disabled { color: var(--light); cursor: default; }

.pps-acct .empty {
  padding: 36px 4px;
  font-size: 14px;
  color: var(--mid);
  display: flex; align-items: center; gap: 14px;
}
.pps-acct .empty .btn { margin-left: auto; }

.pps-acct .form { background: var(--white); border: 1px solid var(--border); border-radius: 6px; padding: 24px; }
.pps-acct .form-intro { font-size: 13px; color: var(--mid); margin: 0 0 18px; line-height: 1.55; }
.pps-acct .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.pps-acct .field label { font-size: 12px; font-weight: 600; color: var(--key); }
.pps-acct .field .req { color: var(--process-magenta); }
.pps-acct .field input {
  padding: 9px 11px; border: 1px solid var(--border); border-radius: 4px;
  font-size: 14px; font-family: var(--font-ui); background: var(--white); color: var(--key);
  outline: none;
}
.pps-acct .field input:focus { border-color: var(--process-cyan); box-shadow: 0 0 0 3px rgba(0,126,255,0.18); }
.pps-acct .field.has-error input { border-color: var(--error); }
.pps-acct .field-hint { font-size: 11px; color: var(--light); }
.pps-acct .honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; }

.pps-acct .banner { padding: 11px 13px; border-radius: 4px; font-size: 13px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; }
.pps-acct .banner-error { background: var(--error-bg); border: 1px solid #e8c5c5; color: var(--error); }
.pps-acct .banner-error strong { font-weight: 700; }
.pps-acct .banner-icon { width: 16px; height: 16px; flex: none; margin-top: 1px; }

.pps-acct .form-foot { margin-top: 6px; font-size: 12px; color: var(--light); text-align: center; }
.pps-acct .form-foot a { color: var(--process-cyan); text-decoration: none; }
.pps-acct .form-foot a:hover { text-decoration: underline; }

.pps-acct .btn-submit { width: 100%; padding: 11px 16px; font-size: 14px; }

.pps-acct .lookup-shell { max-width: 480px; margin: 0 auto; }
.pps-acct .lookup-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--process-magenta); margin: 0 0 6px; }
.pps-acct .lookup-title { font-size: 24px; font-weight: 700; margin: 0 0 18px; color: var(--key); }

.pps-acct .auth-strip {
  background: var(--white); border: 1px solid var(--border); border-radius: 6px;
  padding: 12px 16px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 18px; font-size: 13px; color: var(--mid);
  flex-wrap: wrap; gap: 10px;
}
.pps-acct .auth-strip strong { color: var(--key); font-weight: 600; }
.pps-acct .results-foot { margin-top: 10px; font-size: 12px; color: var(--light); text-align: center; }
.pps-acct .results-foot a { color: var(--process-cyan); text-decoration: none; }
.pps-acct .results-foot a:hover { text-decoration: underline; }

@media (max-width: 639px) {
  .pps-acct .order-card { grid-template-columns: 96px 1fr; padding: 14px; gap: 14px; }
  .pps-acct .oc-thumb,
  .pps-acct .oc-thumb-empty { width: 96px; height: 96px; }
  .pps-acct .oc-actions { grid-column: 1 / -1; flex-direction: row; align-items: center; justify-content: space-between; min-width: 0; gap: 12px; }
  .pps-acct .oc-actions .btn { flex: 1; }
  .pps-acct .oc-caveat { max-width: none; text-align: left; }
  .pps-acct .h-page { font-size: 22px; }
  .pps-acct .lookup-title { font-size: 20px; }
  .pps-acct .lookup-shell { max-width: 100%; }
}
CSS;
}
