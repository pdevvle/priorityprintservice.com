<?php
/**
 * PPS Missive Impose — the imposition tool, run on the site, piped into the Missive panel.
 *
 * Owner, 2026-10-18: "lets add the imposition for jobs. It runs on the site and the iframe
 * pipes it in." The panel's order card and board rows carry an Impose button; it opens
 * `/?pps_missive_impose=1&k=<panel key>&order=<id>` inside the panel (or in the browser,
 * "Open wide"). That page is the SAME imposition-tool.html the wp-admin screen serves, from
 * the same file on disk — nothing about the engine is copied or changed here.
 *
 * Missive's iframe carries no WordPress login (a cross-site frame is not sent the cookie), so
 * the tool's five calls cannot use the wp-admin nonce. Instead the page is given an ORDER-
 * SCOPED token (HMAC of the order id with the panel key) and its calls go to the usual
 * admin-ajax.php with `pps_mpi=<order>` on the URL. This file answers them first (priority
 * 0, logged in or not) and only for that one order:
 *
 *   pps_impose_list       — that order's jobs, the same rows pps-imposition.php builds
 *   pps_impose_download   — a file only if it is IN that order's Drive folder (the Drive
 *                           connection can read the whole Drive since 2026-10-18; the panel
 *                           key must never become a way to read any of it)
 *   pps_impose_upload     — IMPOSED_*.pdf / CLEAN_*.pdf into that order's folder, with an
 *                           order note saying it came from the Missive panel
 *   pps_impose_setups     — the office's saved setups (v1.37), the same shared list the
 *                           wp-admin screen reads and saves, through its own sanitiser
 *   pps_impose_set_status — refused: the panel never changes an order's status
 *   pps_impose_set_hidden — refused, likewise
 *
 * A call without `pps_mpi` falls through to pps-imposition.php untouched, so the wp-admin
 * Imposition screen works exactly as before. tools-missive-impose-test.php is the gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_MPI_ACTIONS', 'pps_impose_list,pps_impose_download,pps_impose_upload,pps_impose_setups,pps_impose_set_status,pps_impose_set_hidden' );

/** The token a page for $order_id carries; '' when there is no panel key yet. */
function pps_mpi_token( $order_id ) {
    $k = function_exists( 'pps_mp_key' ) ? pps_mp_key() : '';
    return ( $k === '' || (int) $order_id <= 0 ) ? '' : hash_hmac( 'sha256', 'pps-impose|' . (int) $order_id, $k );
}

function pps_mpi_token_ok( $order_id, $given ) {
    $t = pps_mpi_token( $order_id );
    return $t !== '' && is_string( $given ) && $given !== '' && hash_equals( $t, $given );
}

/**
 * The queue rows for ONE order — field for field what pps-imposition.php's list builds
 * (v1.31, live since 2026-09-05), so the tool treats them identically. Keep in step with it.
 */
function pps_mpi_rows( $order, $files ) {
    $pps_items = array();
    foreach ( $order->get_items() as $iid => $it ) {
        if ( $it->get_meta( '_pps_metadata' ) ) $pps_items[ $iid ] = $it;
    }
    $sole_item = count( $pps_items ) === 1;
    $folder_id = (string) $order->get_meta( '_pps_gdrive_folder_id' );
    $items = array();
    foreach ( $pps_items as $item_id => $item ) {
        $m = json_decode( (string) $item->get_meta( '_pps_metadata' ), true );
        if ( ! is_array( $m ) ) continue;
        $art     = function_exists( 'pps_impose_item_artwork' ) ? pps_impose_item_artwork( $files, $item, $sole_item ) : null;
        $imposed = function_exists( 'pps_impose_item_imposed' ) ? pps_impose_item_imposed( $files, $order->get_id(), $item_id, $sole_item ) : false;

        $long = 0; $short = 0;
        if ( ( $m['sizeMode'] ?? '' ) !== 'preset' && ! empty( $m['longEdge'] ) && ! empty( $m['shortEdge'] ) ) {
            $long = floatval( $m['longEdge'] ); $short = floatval( $m['shortEdge'] );
        } elseif ( ! empty( $m['customLong'] ) && ! empty( $m['customShort'] ) ) {
            $long = floatval( $m['customLong'] ); $short = floatval( $m['customShort'] );
        } elseif ( preg_match( '/(\d+(?:\.\d+)?)\s*[×x]\s*(\d+(?:\.\d+)?)/u', (string) ( $m['sizeLabel'] ?? '' ), $mm ) ) {
            $long = floatval( $mm[1] ); $short = floatval( $mm[2] );
        }
        if ( $long && $short && $long < $short ) { $t = $long; $long = $short; $short = $t; }

        $qty = intval( $m['totalQty'] ?? $m['qty'] ?? 0 );
        if ( ! $qty && is_array( $m['sets'] ?? null ) ) $qty = array_sum( array_map( 'intval', array_column( $m['sets'], 'qty' ) ) );
        if ( ! $qty && method_exists( $item, 'get_quantity' ) ) $qty = $item->get_quantity();

        $items[] = array(
            'order_id'      => $order->get_id(),
            'item_id'       => $item_id,
            'status'        => $order->get_status(),
            'status_label'  => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status(),
            'hidden'        => function_exists( 'pps_impose_is_hidden' ) ? pps_impose_is_hidden( $order ) : false,
            'order_url'     => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '',
            'product'       => $item->get_name(),
            'job_name'      => (string) ( $m['jobName'] ?? '' ),
            'calc'          => function_exists( 'pps_impose_calc_type' ) ? pps_impose_calc_type( $m, $item ) : (string) ( $m['calcType'] ?? '' ),
            'size_label'    => (string) ( $m['sizeLabel'] ?? '' ),
            'long_edge'     => $long,
            'short_edge'    => $short,
            'sides'         => intval( $m['sides'] ?? 1 ) === 2 ? 2 : 1,
            'bind_dir'      => (string) ( $m['bindDir'] ?? '' ),
            'qty'           => $qty,
            'folder_url'    => 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id ),
            'art_file_id'   => $art['id'] ?? '',
            'art_file_name' => $art['name'] ?? '',
            'proof_hash'    => (string) $item->get_meta( '_pps_proof_hash' ),
            'imposed'       => $imposed,
            'files_listed'  => is_array( $files ),
        );
    }
    return $items;
}

/** Is $file_id one of the files in this folder listing? */
function pps_mpi_in_folder( $files, $file_id ) {
    if ( ! is_array( $files ) ) return false;
    foreach ( $files as $f ) if ( (string) ( $f['id'] ?? '' ) === (string) $file_id ) return true;
    return false;
}

/* ── The bridge: answers the tool's calls when they carry pps_mpi ─────────── */

function pps_mpi_ajax() {
    if ( ! isset( $_GET['pps_mpi'] ) ) return; // the wp-admin screen's own call — not ours
    $order_id = (int) $_GET['pps_mpi'];
    $action   = (string) ( $_REQUEST['action'] ?? '' );
    if ( ! pps_mpi_token_ok( $order_id, (string) wp_unslash( $_POST['nonce'] ?? '' ) ) ) {
        wp_send_json_error( array( 'message' => 'This imposition link is not valid any more — open it again from the Missive panel.' ), 403 );
    }
    if ( $action === 'pps_impose_set_status' || $action === 'pps_impose_set_hidden' ) {
        wp_send_json_error( array( 'message' => 'The Missive panel does not change orders. Change the status in WooCommerce, or use PPS Calculators → Imposition.' ), 403 );
    }
    $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
    if ( ! $order ) wp_send_json_error( array( 'message' => 'Order not found' ) );
    $folder_id = (string) $order->get_meta( '_pps_gdrive_folder_id' );

    // Saved setups are imposition settings, not order data — one office-wide list
    // (`pps_impose_setups`), read and saved exactly as pps-imposition.php v1.37+ does it.
    if ( $action === 'pps_impose_setups' ) {
        if ( ! function_exists( 'pps_impose_setups_sanitize' ) ) wp_send_json_error( array( 'message' => 'Saved setups need imposition v1.37 or later on the site.' ) );
        if ( sanitize_key( $_POST['op'] ?? 'get' ) === 'save' ) {
            $raw = (string) wp_unslash( $_POST['setups'] ?? '' );
            if ( strlen( $raw ) > 512 * 1024 ) wp_send_json_error( array( 'message' => 'Setups list is too large (512 KB cap) — delete some.' ) );
            $list = json_decode( $raw, true );
            if ( ! is_array( $list ) ) wp_send_json_error( array( 'message' => 'Setups must be a JSON list.' ) );
            $clean = pps_impose_setups_sanitize( $list );
            update_option( 'pps_impose_setups', wp_json_encode( $clean ), false );
            wp_send_json_success( array( 'setups' => $clean ) );
        }
        $stored = json_decode( (string) get_option( 'pps_impose_setups', '[]' ), true );
        wp_send_json_success( array( 'setups' => is_array( $stored ) ? pps_impose_setups_sanitize( $stored ) : array() ) );
    }
    if ( $action === 'pps_impose_list' ) {
        $files = ( $folder_id !== '' && function_exists( 'pps_impose_drive_list' ) ) ? pps_impose_drive_list( $folder_id, ! empty( $_POST['refresh'] ) ) : null;
        wp_send_json_success( array( 'items' => $folder_id !== '' ? pps_mpi_rows( $order, $files ) : array() ) );
    }
    if ( $folder_id === '' ) wp_send_json_error( array( 'message' => 'Order has no Drive folder' ) );

    if ( $action === 'pps_impose_download' ) {
        $file_id = sanitize_text_field( $_POST['file_id'] ?? '' );
        if ( ! $file_id || ! preg_match( '/^[\w-]+$/', $file_id ) ) wp_send_json_error( array( 'message' => 'Bad file id' ), 400 );
        // Only a file in THIS order's folder; a fresh listing before refusing (a file added a minute ago).
        if ( ! pps_mpi_in_folder( pps_impose_drive_list( $folder_id ), $file_id ) && ! pps_mpi_in_folder( pps_impose_drive_list( $folder_id, true ), $file_id ) ) {
            wp_send_json_error( array( 'message' => 'That file is not in order #' . $order_id . '\'s Drive folder.' ), 403 );
        }
        $token = function_exists( 'pps_gdrive_get_access_token' ) ? pps_gdrive_get_access_token() : false;
        if ( ! $token ) wp_send_json_error( array( 'message' => 'Google Drive not connected — reconnect under PPS Calculators → Google Drive' ), 502 );
        $resp = wp_remote_get( 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 120, 'stream' => true, 'filename' => wp_tempnam( 'pps-impose-art' ),
        ) );
        if ( is_wp_error( $resp ) ) wp_send_json_error( array( 'message' => 'Drive download failed: ' . $resp->get_error_message() ), 502 );
        $tmp = $resp['filename'] ?? ''; $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 || ! $tmp || ! file_exists( $tmp ) ) {
            if ( $tmp && file_exists( $tmp ) ) @unlink( $tmp );
            wp_send_json_error( array( 'message' => 'Drive returned HTTP ' . intval( $code ) ), 502 );
        }
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="artwork.pdf"' );
        header( 'Content-Length: ' . filesize( $tmp ) );
        readfile( $tmp ); // binary PDF bytes — not HTML output
        @unlink( $tmp );
        exit;
    }

    if ( $action === 'pps_impose_upload' ) {
        if ( (int) ( $_POST['order_id'] ?? 0 ) !== $order_id ) wp_send_json_error( array( 'message' => 'This page files only to order #' . $order_id . '.' ), 403 );
        if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) wp_send_json_error( array( 'message' => 'No file received' ) );
        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename || ! preg_match( '/^(IMPOSED|CLEAN)_.*\.pdf$/', $filename ) ) wp_send_json_error( array( 'message' => 'Filename must be IMPOSED_*.pdf or CLEAN_*.pdf' ) );
        $fh = fopen( $_FILES['file']['tmp_name'], 'rb' ); $magic = $fh ? fread( $fh, 5 ) : ''; if ( $fh ) fclose( $fh );
        if ( strpos( (string) $magic, '%PDF' ) !== 0 ) wp_send_json_error( array( 'message' => 'Upload is not a PDF' ) );
        if ( ! function_exists( 'pps_gdrive_upload_file' ) ) wp_send_json_error( array( 'message' => 'Drive integration not loaded' ) );
        $file_id = pps_gdrive_upload_file( $_FILES['file']['tmp_name'], $filename, $folder_id );
        if ( ! $file_id ) wp_send_json_error( array( 'message' => 'Drive upload failed — see error log' ) );
        delete_transient( 'pps_impose_ls_' . md5( $folder_id ) );
        $order->add_order_note( ( stripos( $filename, 'CLEAN_' ) === 0 ? 'Sanitized (CLEAN) copy' : 'Imposed press-ready PDF' ) . ' filed to Drive from the Missive panel: ' . $filename );
        wp_send_json_success( array( 'file_id' => $file_id, 'url' => 'https://drive.google.com/file/d/' . rawurlencode( $file_id ) . '/view' ) );
    }
    wp_send_json_error( array( 'message' => 'Unknown action' ), 400 );
}
foreach ( explode( ',', PPS_MPI_ACTIONS ) as $a ) {
    add_action( 'wp_ajax_' . $a, 'pps_mpi_ajax', 0 );
    add_action( 'wp_ajax_nopriv_' . $a, 'pps_mpi_ajax', 0 );
}

/* ── The page: the imposition tool for one order ──────────────────────────── */

function pps_mpi_page( $html, $order_id, $ajax_url ) {
    $cfg = wp_json_encode( array(
        'ajaxUrl'       => $ajax_url,
        'nonce'         => pps_mpi_token( $order_id ),
        'gdrive'        => function_exists( 'pps_gdrive_is_connected' ) ? pps_gdrive_is_connected() : false,
        'sizePresets'   => ( function_exists( 'pps_get_config' ) && is_array( pps_get_config()['size_presets'] ?? null ) ) ? pps_get_config()['size_presets'] : null,
        'orderStatuses' => array(), // the status menu has nothing to move to: the panel never changes an order
        'doneStatuses'  => function_exists( 'pps_impose_done_statuses' ) ? pps_impose_done_statuses() : array( 'completed', 'cancelled', 'refunded', 'failed', 'trash' ),
        // v1.34: the imposed PDF comes back as a form POST, so PHP's upload limit applies —
        // the tool refuses an oversize one with the real reason instead of a failed upload.
        'maxUpload'     => function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0,
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
    $html = str_replace( '</head>', '<script>window.PPS_IMPOSE_CFG = ' . $cfg . ";</script>\n</head>", $html );
    // Cloudflare's Rocket Loader rewrites every script on a front-end page (wp-admin is exempt),
    // which left React and Babel unloaded and the tool blank (2026-10-18). Tell it to leave
    // them all alone, as the panel's own page does.
    // v1.58: the libraries the site keeps in imposition-vendor/ (pinned by size), as the
    // wp-admin stream uses them, so the tool opens even if a public CDN is slow or blocked.
    // Only files already provisioned there; anything missing stays on its CDN tag.
    if ( function_exists( 'pps_impose_vendor_map' ) && defined( 'PPS_CALC_DIR' ) && defined( 'PPS_CALC_URL' ) ) {
        foreach ( pps_impose_vendor_map() as $cdn => $lib ) {
            $path = PPS_CALC_DIR . 'imposition-vendor/' . $lib['file'];
            if ( file_exists( $path ) && filesize( $path ) === $lib['bytes'] ) {
                $html = str_replace( $cdn, esc_url( PPS_CALC_URL . 'imposition-vendor/' . $lib['file'] . '?v=' . filemtime( $path ) ), $html );
            }
        }
    }
    return preg_replace( '/<script(?![^>]*data-cfasync)(?=[\s>])/i', '<script data-cfasync="false"', $html );
}

// wp_loaded, not init: the page looks the order up, and WooCommerce registers its order types
// on init (priority 5) — at init 1 every order read as "No such order" (2026-10-18).
add_action( 'wp_loaded', function () {
    if ( ! isset( $_GET['pps_missive_impose'] ) ) return;
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
    header_remove( 'X-Frame-Options' );
    $order_id = (int) ( $_GET['order'] ?? 0 );
    $deny = function ( $msg ) { status_header( 403 ); header( 'Content-Type: text/plain; charset=UTF-8' ); echo $msg; exit; };
    if ( ! function_exists( 'pps_mp_key_ok' ) || ! pps_mp_key_ok( (string) wp_unslash( $_GET['k'] ?? '' ) ) ) $deny( 'This link is not valid. Open the imposition from the Missive panel.' );
    if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) || ! wc_get_order( $order_id ) ) $deny( 'No such order.' );
    $file = ( defined( 'PPS_CALC_DIR' ) ? PPS_CALC_DIR : __DIR__ . '/' ) . 'imposition-tool.html';
    if ( ! file_exists( $file ) ) $deny( 'imposition-tool.html is not on the site.' );
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'Referrer-Policy: no-referrer' );
    echo pps_mpi_page( file_get_contents( $file ), $order_id, add_query_arg( 'pps_mpi', $order_id, admin_url( 'admin-ajax.php' ) ) );
    exit;
} );
