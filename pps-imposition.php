<?php
/**
 * PPS Imposition — wp-admin host for the browser imposition tool.
 *
 * Serves imposition-tool.html inside an admin iframe and provides three
 * AJAX endpoints that bridge it to WooCommerce + the existing Google Drive
 * connection (pps-gdrive.php OAuth — no separate Drive login):
 *
 *   pps_impose_app       — stream the tool HTML with PPS_IMPOSE_CFG injected
 *   pps_impose_list      — recent orders with Drive artwork + parsed spec
 *   pps_impose_download  — proxy an artwork file from Drive to the browser
 *   pps_impose_upload    — file the imposed PDF back into the order's folder
 *
 * The imposition engine itself runs entirely in the browser
 * (imposition-tool.html, pdf-lib) — no PDF processing happens in PHP.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_IMPOSE_NONCE', 'pps_impose' );

// ═══════════════════════════════════════════════════════════════
// ADMIN MENU — iframe host page
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_submenu_page(
        'pps-calculators',
        'Imposition',
        'Imposition',
        'manage_options',
        'pps-imposition',
        'pps_imposition_admin_page'
    );
}, 20 );

function pps_imposition_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    $src = admin_url( 'admin-ajax.php?action=pps_impose_app&nonce=' . wp_create_nonce( PPS_IMPOSE_NONCE ) );
    echo '<div class="wrap" style="margin:0;padding:0">';
    echo '<iframe src="' . esc_url( $src ) . '" style="width:calc(100% + 20px);height:calc(100vh - 65px);border:0;margin-left:-20px;display:block" title="PPS Imposition Tool"></iframe>';
    echo '</div>';
}

// ═══════════════════════════════════════════════════════════════
// APP SHELL — stream the tool HTML with config injected
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_impose_app', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( PPS_IMPOSE_NONCE, 'nonce', false ) ) {
        wp_die( 'Unauthorized' );
    }
    $file = PPS_CALC_DIR . 'imposition-tool.html';
    if ( ! file_exists( $file ) ) wp_die( 'imposition-tool.html not found in plugin directory' );
    $html = file_get_contents( $file );
    $cfg  = wp_json_encode( array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( PPS_IMPOSE_NONCE ),
        'gdrive'  => function_exists( 'pps_gdrive_is_connected' ) ? pps_gdrive_is_connected() : false,
    ), JSON_HEX_TAG );
    $inject = '<script>window.PPS_IMPOSE_CFG = ' . $cfg . ';</script>';
    $html   = str_replace( '</head>', $inject . "\n</head>", $html );
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    echo $html;
    wp_die();
});

// ═══════════════════════════════════════════════════════════════
// DRIVE HELPERS (read side — upload reuses pps_gdrive_upload_file)
// ═══════════════════════════════════════════════════════════════

function pps_impose_drive_list( $folder_id ) {
    if ( ! function_exists( 'pps_gdrive_get_access_token' ) ) return null;
    $token = pps_gdrive_get_access_token();
    if ( ! $token ) return null;
    $url = 'https://www.googleapis.com/drive/v3/files?q=' . rawurlencode( "'" . $folder_id . "' in parents and trashed=false" )
         . '&fields=' . rawurlencode( 'files(id,name,mimeType,size)' ) . '&pageSize=100';
    $resp = wp_remote_get( $url, array(
        'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        'timeout' => 30,
    ) );
    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    return isset( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : null;
}

/**
 * Calc type for an order item. Newer calculators stamp calcType into
 * _pps_metadata; older ones (saddle/perfect-bound/coupon) don't — fall back
 * to the product registry (calculator filename → calc type).
 */
function pps_impose_calc_type( $meta, $item ) {
    if ( ! empty( $meta['calcType'] ) ) return (string) $meta['calcType'];
    if ( ! function_exists( 'pps_get_calculator_for_product' ) ) return '';
    $product_id = method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : 0;
    $file = $product_id ? pps_get_calculator_for_product( $product_id ) : false;
    $map  = array(
        'calc-preview-test.html'  => 'saddle',
        'calc-perfect-bound.html' => 'perfect-bound',
        'calc-coupon-book.html'   => 'coupon',
        'calc-brochure.html'      => 'brochure',
        'calc-postcard.html'      => 'postcard',
        'calc-letterhead.html'    => 'letterhead',
        'calc-greeting-card.html' => 'greeting-card',
        'calc-sticker.html'       => 'sticker',
    );
    return $file && isset( $map[ $file ] ) ? $map[ $file ] : '';
}

/**
 * Pick the imposition input among an order folder's files.
 * Preference: *_print-ready.pdf (customer-approved transforms baked in),
 * else any other PDF that isn't one of our outputs or a preview deliverable.
 */
function pps_impose_pick_artwork( $files ) {
    $print_ready = null;
    $raw         = null;
    foreach ( $files as $f ) {
        $name = $f['name'] ?? '';
        if ( ! preg_match( '/\.pdf$/i', $name ) ) continue;
        if ( preg_match( '/^IMPOSED[_\s-]/i', $name ) ) continue;
        if ( preg_match( '/_preview/i', $name ) ) continue;
        if ( preg_match( '/_print-ready\.pdf$/i', $name ) ) {
            $print_ready = $f;
        } elseif ( ! $raw ) {
            $raw = $f;
        }
    }
    return $print_ready ?: $raw;
}

// ═══════════════════════════════════════════════════════════════
// LIST — recent orders with Drive artwork + parsed spec
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_impose_list', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( PPS_IMPOSE_NONCE, 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }
    if ( ! function_exists( 'wc_get_orders' ) ) {
        wp_send_json_error( array( 'message' => 'WooCommerce not active' ) );
    }

    $orders = wc_get_orders( array(
        'limit'   => 40,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => array( 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-completed' ),
    ) );

    $items = array();
    foreach ( $orders as $order ) {
        $folder_id = $order->get_meta( '_pps_gdrive_folder_id' );
        if ( ! $folder_id ) continue;

        $files = pps_impose_drive_list( $folder_id );
        $art   = $files ? pps_impose_pick_artwork( $files ) : null;
        $imposed = false;
        if ( $files ) {
            foreach ( $files as $f ) {
                if ( preg_match( '/^IMPOSED[_\s-]/i', $f['name'] ?? '' ) ) { $imposed = true; break; }
            }
        }

        foreach ( $order->get_items() as $item_id => $item ) {
            $meta_json = $item->get_meta( '_pps_metadata' );
            if ( ! $meta_json ) continue;
            $m = json_decode( $meta_json, true );
            if ( ! is_array( $m ) ) continue;

            // Trim dims: flat customs carry longEdge/shortEdge; saddle customs
            // carry customLong/customShort; presets parse out of the size
            // label ("8.5×11 …"). Client re-derives width/height for saddle
            // (spine orientation) from size_label / bind_dir.
            $long = 0; $short = 0;
            if ( ( $m['sizeMode'] ?? '' ) !== 'preset' && ! empty( $m['longEdge'] ) && ! empty( $m['shortEdge'] ) ) {
                $long  = floatval( $m['longEdge'] );
                $short = floatval( $m['shortEdge'] );
            } elseif ( ! empty( $m['customLong'] ) && ! empty( $m['customShort'] ) ) {
                $long  = floatval( $m['customLong'] );
                $short = floatval( $m['customShort'] );
            } elseif ( preg_match( '/(\d+(?:\.\d+)?)\s*[×x]\s*(\d+(?:\.\d+)?)/u', (string) ( $m['sizeLabel'] ?? '' ), $mm ) ) {
                $long  = floatval( $mm[1] );
                $short = floatval( $mm[2] );
            }
            if ( $long && $short && $long < $short ) { $t = $long; $long = $short; $short = $t; }

            // Booklets: quantity lives in sets[] (qty per set)
            $qty = intval( $m['totalQty'] ?? $m['qty'] ?? 0 );
            if ( ! $qty && is_array( $m['sets'] ?? null ) ) {
                $qty = array_sum( array_map( 'intval', array_column( $m['sets'], 'qty' ) ) );
            }
            if ( ! $qty ) $qty = $item->get_quantity();

            $items[] = array(
                'order_id'      => $order->get_id(),
                'item_id'       => $item_id,
                'product'       => $item->get_name(),
                'job_name'      => (string) ( $m['jobName'] ?? '' ),
                'calc'          => pps_impose_calc_type( $m, $item ),
                'size_label'    => (string) ( $m['sizeLabel'] ?? '' ),
                'long_edge'     => $long,
                'short_edge'    => $short,
                'sides'         => intval( $m['sides'] ?? 1 ) === 2 ? 2 : 1,
                'bind_dir'      => (string) ( $m['bindDir'] ?? '' ),
                'qty'           => $qty,
                'folder_url'    => 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id ),
                'art_file_id'   => $art['id'] ?? '',
                'art_file_name' => $art['name'] ?? '',
                'imposed'       => $imposed,
                'files_listed'  => is_array( $files ),
            );
        }
    }

    wp_send_json_success( array( 'items' => $items ) );
});

// ═══════════════════════════════════════════════════════════════
// DOWNLOAD — proxy an artwork PDF from Drive to the browser
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_impose_download', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( PPS_IMPOSE_NONCE, 'nonce', false ) ) {
        wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
    }
    $file_id = sanitize_text_field( $_POST['file_id'] ?? '' );
    if ( ! $file_id || ! preg_match( '/^[\w-]+$/', $file_id ) ) {
        wp_die( 'Bad file id', '', array( 'response' => 400 ) );
    }
    $token = function_exists( 'pps_gdrive_get_access_token' ) ? pps_gdrive_get_access_token() : false;
    if ( ! $token ) wp_die( 'Google Drive not connected', '', array( 'response' => 502 ) );

    $resp = wp_remote_get( 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media', array(
        'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        'timeout' => 120,
    ) );
    if ( is_wp_error( $resp ) ) wp_die( esc_html( $resp->get_error_message() ), '', array( 'response' => 502 ) );
    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code !== 200 ) wp_die( 'Drive returned HTTP ' . intval( $code ), '', array( 'response' => 502 ) );

    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="artwork.pdf"' );
    echo wp_remote_retrieve_body( $resp ); // binary PDF bytes — not HTML output
    wp_die();
});

// ═══════════════════════════════════════════════════════════════
// UPLOAD — file the imposed PDF back into the order's Drive folder
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_impose_upload', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( PPS_IMPOSE_NONCE, 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }
    $order_id = intval( $_POST['order_id'] ?? 0 );
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    if ( ! $order ) wp_send_json_error( array( 'message' => 'Order not found' ) );

    $folder_id = $order->get_meta( '_pps_gdrive_folder_id' );
    if ( ! $folder_id ) wp_send_json_error( array( 'message' => 'Order has no Drive folder' ) );

    if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
        wp_send_json_error( array( 'message' => 'No file received' ) );
    }
    $filename = sanitize_file_name( $_POST['filename'] ?? '' );
    if ( ! $filename || ! preg_match( '/^IMPOSED_.*\.pdf$/', $filename ) ) {
        wp_send_json_error( array( 'message' => 'Filename must be IMPOSED_*.pdf' ) );
    }
    // Cheap validity check: PDF magic bytes
    $fh    = fopen( $_FILES['file']['tmp_name'], 'rb' );
    $magic = $fh ? fread( $fh, 5 ) : '';
    if ( $fh ) fclose( $fh );
    if ( strpos( (string) $magic, '%PDF' ) !== 0 ) {
        wp_send_json_error( array( 'message' => 'Upload is not a PDF' ) );
    }
    if ( ! function_exists( 'pps_gdrive_upload_file' ) ) {
        wp_send_json_error( array( 'message' => 'Drive integration not loaded' ) );
    }

    $file_id = pps_gdrive_upload_file( $_FILES['file']['tmp_name'], $filename, $folder_id );
    if ( ! $file_id ) wp_send_json_error( array( 'message' => 'Drive upload failed — see error log' ) );

    $order->add_order_note( 'Imposed press-ready PDF filed to Drive: ' . $filename );

    wp_send_json_success( array(
        'file_id' => $file_id,
        'url'     => 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/view',
    ) );
});
