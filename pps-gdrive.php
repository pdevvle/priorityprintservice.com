<?php
/**
 * PPS Google Drive Integration (OAuth)
 *
 * Moves artwork files to Google Drive after order placement.
 * Uses OAuth 2.0 with a personal Google account (one-time authorization).
 * Generates a thumbnail for reorder reference and deletes the local file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════

// Credentials loaded from wp_options — configure in WP Admin → PPS Calculators → Google Drive
function pps_gdrive_client_id()     { return get_option( 'pps_gdrive_client_id', '' ); }
function pps_gdrive_client_secret() { return get_option( 'pps_gdrive_client_secret', '' ); }
function pps_gdrive_parent_folder() { return get_option( 'pps_gdrive_parent_folder', '' ); }
define( 'PPS_GDRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file' );

// ═══════════════════════════════════════════════════════════════
// THUMBNAIL DIRECTORY
// ═══════════════════════════════════════════════════════════════

function pps_thumb_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . 'pps-artwork-thumbs';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
    }
    return $dir;
}

function pps_thumb_url() {
    $upload = wp_upload_dir();
    return trailingslashit( $upload['baseurl'] ) . 'pps-artwork-thumbs';
}

// ═══════════════════════════════════════════════════════════════
// OAUTH TOKEN MANAGEMENT
// ═══════════════════════════════════════════════════════════════

function pps_gdrive_get_access_token() {
    $cached = get_transient( 'pps_gdrive_access_token' );
    if ( $cached ) return $cached;

    $refresh_token = get_option( 'pps_gdrive_refresh_token' );
    if ( ! $refresh_token ) {
        error_log( 'PPS Drive: Not authorized. Go to PPS Calculators → Google Drive to connect.' );
        return false;
    }

    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'timeout' => 15,
        'body'    => array(
            'client_id'     => pps_gdrive_client_id(),
            'client_secret' => pps_gdrive_client_secret(),
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        error_log( 'PPS Drive: Token refresh failed: ' . $response->get_error_message() );
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $body['error'] ) ) {
        error_log( 'PPS Drive: Token refresh error: ' . $body['error'] . ' — ' . ( $body['error_description'] ?? '' ) );
        if ( in_array( $body['error'], array( 'invalid_grant', 'invalid_client' ), true ) ) {
            delete_option( 'pps_gdrive_refresh_token' );
            delete_option( 'pps_gdrive_user_email' );
        }
        return false;
    }

    if ( empty( $body['access_token'] ) ) {
        error_log( 'PPS Drive: No access token in refresh response.' );
        return false;
    }

    $expires = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 60 : 3500;
    set_transient( 'pps_gdrive_access_token', $body['access_token'], $expires );

    if ( ! empty( $body['refresh_token'] ) ) {
        update_option( 'pps_gdrive_refresh_token', $body['refresh_token'], false );
    }

    return $body['access_token'];
}

function pps_gdrive_is_connected() {
    return (bool) get_option( 'pps_gdrive_refresh_token' );
}

// ═══════════════════════════════════════════════════════════════
// ADMIN PAGE: AUTHORIZATION
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_submenu_page(
        'pps-calculators',
        'Google Drive',
        '📁 Google Drive',
        'manage_options',
        'pps-gdrive-auth',
        'pps_gdrive_auth_page'
    );
}, 20 );

function pps_gdrive_auth_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $redirect_uri = admin_url( 'admin.php?page=pps-gdrive-auth' );

    // Handle disconnect
    if ( isset( $_POST['pps_gdrive_disconnect'] ) ) {
        check_admin_referer( 'pps_gdrive_auth' );
        delete_option( 'pps_gdrive_refresh_token' );
        delete_option( 'pps_gdrive_user_email' );
        delete_transient( 'pps_gdrive_access_token' );
        echo '<div class="notice notice-warning is-dismissible"><p>Google Drive disconnected.</p></div>';
    }

    // Handle OAuth callback
    if ( isset( $_GET['code'] ) && ! isset( $_POST['pps_gdrive_disconnect'] ) ) {
        $code = sanitize_text_field( $_GET['code'] );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body'    => array(
                'code'          => $code,
                'client_id'     => pps_gdrive_client_id(),
                'client_secret' => pps_gdrive_client_secret(),
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            echo '<div class="notice notice-error"><p>OAuth error: ' . esc_html( $response->get_error_message() ) . '</p></div>';
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( ! empty( $body['refresh_token'] ) ) {
                update_option( 'pps_gdrive_refresh_token', $body['refresh_token'], false );

                if ( ! empty( $body['access_token'] ) ) {
                    $expires = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 60 : 3500;
                    set_transient( 'pps_gdrive_access_token', $body['access_token'], $expires );

                    // Get user email for display
                    $info = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
                        'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
                    ) );
                    if ( ! is_wp_error( $info ) ) {
                        $user = json_decode( wp_remote_retrieve_body( $info ), true );
                        if ( ! empty( $user['email'] ) ) {
                            update_option( 'pps_gdrive_user_email', $user['email'], false );
                        }
                    }
                }

                echo '<div class="notice notice-success is-dismissible"><p><strong>Google Drive connected!</strong> Artwork uploads will now go to Drive automatically.</p></div>';
            } elseif ( ! empty( $body['error'] ) ) {
                echo '<div class="notice notice-error"><p>OAuth error: ' . esc_html( $body['error'] ) . ' — ' . esc_html( $body['error_description'] ?? '' ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>No refresh token received. Try disconnecting and reconnecting with the "prompt=consent" flow.</p></div>';
            }
        }
    }

    // Build authorization URL
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
        'client_id'     => pps_gdrive_client_id(),
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => PPS_GDRIVE_SCOPE . ' https://www.googleapis.com/auth/userinfo.email',
        'access_type'   => 'offline',
        'prompt'        => 'consent',
    ) );

    // Handle credential save
    if ( isset( $_POST['pps_gdrive_save_creds'] ) ) {
        check_admin_referer( 'pps_gdrive_auth' );
        $cid   = sanitize_text_field( $_POST['pps_gdrive_cid'] ?? '' );
        $csec  = sanitize_text_field( $_POST['pps_gdrive_csec'] ?? '' );
        $folder = sanitize_text_field( $_POST['pps_gdrive_folder'] ?? '' );
        if ( ! $cid || ! $csec ) {
            echo '<div class="notice notice-error"><p>Client ID and Client Secret are required.</p></div>';
        } else {
            update_option( 'pps_gdrive_client_id', $cid, false );
            update_option( 'pps_gdrive_client_secret', $csec, false );
            update_option( 'pps_gdrive_parent_folder', $folder, false );
            echo '<div class="notice notice-success is-dismissible"><p>Credentials saved.</p></div>';
        }
        // Rebuild auth URL with new client ID
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
            'client_id'     => pps_gdrive_client_id(),
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => PPS_GDRIVE_SCOPE . ' https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ) );
    }

    $connected = pps_gdrive_is_connected();
    $email     = get_option( 'pps_gdrive_user_email', '' );

    ?>
    <div class="wrap" style="max-width:600px">
        <h1>📁 Google Drive Integration</h1>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:24px;margin-top:16px">
            <h3 style="margin:0 0 12px">API Credentials</h3>
            <p style="font-size:12px;color:#666;margin:0 0 12px">From Google Cloud Console → APIs &amp; Services → Credentials. These are stored in your WordPress database, not in code.</p>
            <form method="post">
                <?php wp_nonce_field( 'pps_gdrive_auth' ); ?>
                <table class="form-table" style="margin:0">
                    <tr><th style="padding:8px 0;width:120px"><label>Client ID</label></th>
                        <td><input type="text" name="pps_gdrive_cid" value="<?php echo esc_attr( pps_gdrive_client_id() ); ?>" class="regular-text" style="width:100%" /></td></tr>
                    <tr><th style="padding:8px 0"><label>Client Secret</label></th>
                        <td><input type="password" name="pps_gdrive_csec" value="<?php echo esc_attr( pps_gdrive_client_secret() ); ?>" class="regular-text" style="width:100%" /></td></tr>
                    <tr><th style="padding:8px 0"><label>Parent Folder ID</label></th>
                        <td><input type="text" name="pps_gdrive_folder" value="<?php echo esc_attr( pps_gdrive_parent_folder() ); ?>" class="regular-text" style="width:100%" />
                        <p class="description">The Google Drive folder ID where order folders are created.</p></td></tr>
                </table>
                <p><button type="submit" name="pps_gdrive_save_creds" class="button">Save Credentials</button></p>
            </form>
        </div>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:24px;margin-top:16px">
            <?php if ( $connected ) : ?>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <span style="width:12px;height:12px;background:#4ade80;border-radius:50%;display:inline-block"></span>
                    <div>
                        <strong style="font-size:14px;color:#1d2327">Connected</strong>
                        <?php if ( $email ) : ?>
                            <div style="font-size:12px;color:#666;margin-top:2px"><?php echo esc_html( $email ); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <p style="font-size:13px;color:#555;margin:0 0 16px">
                    Artwork files upload to Google Drive automatically after order payment.
                    Local files are deleted after successful upload.
                </p>
                <form method="post">
                    <?php wp_nonce_field( 'pps_gdrive_auth' ); ?>
                    <button type="submit" name="pps_gdrive_disconnect" class="button button-link-delete"
                        onclick="return confirm('Disconnect Google Drive? Pending uploads will fail until reconnected.')">
                        Disconnect
                    </button>
                </form>

            <?php else : ?>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <span style="width:12px;height:12px;background:#fbbf24;border-radius:50%;display:inline-block"></span>
                    <strong style="font-size:14px;color:#1d2327">Not Connected</strong>
                </div>
                <p style="font-size:13px;color:#555;margin:0 0 16px">
                    Connect your Google account to automatically upload artwork to Drive when orders are placed.
                </p>
                <?php if ( pps_gdrive_client_id() && pps_gdrive_client_secret() ) : ?>
                    <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary" style="font-size:14px;padding:8px 24px;height:auto">
                        🔗 Connect Google Drive
                    </a>
                <?php else : ?>
                    <p style="color:#b91c1c;font-size:13px"><strong>Enter your API credentials above before connecting.</strong></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// GOOGLE DRIVE OPERATIONS
// ═══════════════════════════════════════════════════════════════

function pps_gdrive_create_folder( $name, $parent_id = '' ) {
    $token = pps_gdrive_get_access_token();
    if ( ! $token ) return false;

    $metadata = array(
        'name'     => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
    );
    if ( $parent_id ) {
        $metadata['parents'] = array( $parent_id );
    }

    $response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode( $metadata ),
    ) );

    if ( is_wp_error( $response ) ) return false;

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    return $body['id'] ?? false;
}

function pps_gdrive_upload_file( $local_path, $filename, $folder_id ) {
    $token = pps_gdrive_get_access_token();
    if ( ! $token ) return false;

    $file_size = filesize( $local_path );
    $mime_type = wp_check_filetype( $local_path )['type'] ?: 'application/octet-stream';

    $metadata = array( 'name' => $filename, 'parents' => array( $folder_id ) );

    $init = wp_remote_post(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
        array(
            'timeout' => 30,
            'headers' => array(
                'Authorization'           => 'Bearer ' . $token,
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => $mime_type,
                'X-Upload-Content-Length' => $file_size,
            ),
            'body' => json_encode( $metadata ),
        )
    );

    if ( is_wp_error( $init ) ) return false;

    $upload_url = wp_remote_retrieve_header( $init, 'location' );
    if ( ! $upload_url ) return false;

    $file_data = file_get_contents( $local_path );
    if ( $file_data === false ) return false;

    $upload = wp_remote_request( $upload_url, array(
        'method'  => 'PUT',
        'timeout' => 120,
        'headers' => array(
            'Content-Length' => $file_size,
            'Content-Type'  => $mime_type,
        ),
        'body' => $file_data,
    ) );

    if ( is_wp_error( $upload ) || wp_remote_retrieve_response_code( $upload ) !== 200 ) return false;

    $body = json_decode( wp_remote_retrieve_body( $upload ), true );
    return $body['id'] ?? false;
}

// ═══════════════════════════════════════════════════════════════
// THUMBNAIL GENERATION
// ═══════════════════════════════════════════════════════════════

function pps_generate_thumbnail( $file_path, $token ) {
    $thumb_dir  = pps_thumb_dir();
    $thumb_name = $token . '.jpg';
    $thumb_path = trailingslashit( $thumb_dir ) . $thumb_name;
    $ext        = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

    if ( $ext === 'pdf' && class_exists( 'Imagick' ) ) {
        try {
            $im = new Imagick();
            $im->setResolution( 150, 150 );
            $im->readImage( $file_path . '[0]' );
            $im->setImageFormat( 'jpeg' );
            $im->setImageCompressionQuality( 80 );
            $im->thumbnailImage( 400, 0 );
            $im->writeImage( $thumb_path );
            $im->clear();
            $im->destroy();
            return $thumb_name;
        } catch ( Exception $e ) {
            error_log( 'PPS Thumb: Imagick PDF failed: ' . $e->getMessage() );
        }
    }

    if ( in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
        $editor = wp_get_image_editor( $file_path );
        if ( ! is_wp_error( $editor ) ) {
            $editor->resize( 400, 0 );
            $editor->set_quality( 80 );
            $result = $editor->save( $thumb_path, 'image/jpeg' );
            if ( ! is_wp_error( $result ) ) return $thumb_name;
        }
    }

    if ( in_array( $ext, array( 'tiff', 'tif' ), true ) && class_exists( 'Imagick' ) ) {
        try {
            $im = new Imagick( $file_path . '[0]' );
            $im->setImageFormat( 'jpeg' );
            $im->thumbnailImage( 400, 0 );
            $im->writeImage( $thumb_path );
            $im->clear();
            $im->destroy();
            return $thumb_name;
        } catch ( Exception $e ) {
            error_log( 'PPS Thumb: TIFF failed: ' . $e->getMessage() );
        }
    }

    return false;
}

// ═══════════════════════════════════════════════════════════════
// SCHEDULE DRIVE UPLOAD ON ORDER PAYMENT
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_payment_complete', 'pps_schedule_artwork_upload' );
add_action( 'woocommerce_order_status_processing', 'pps_schedule_artwork_upload' );

function pps_schedule_artwork_upload( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( $order->get_meta( '_pps_artwork_processed' ) ) return;

    $has_artwork = false;
    foreach ( $order->get_items() as $item ) {
        if ( $item->get_meta( '_pps_artwork_path' ) ) {
            $has_artwork = true;
            break;
        }
    }
    if ( ! $has_artwork ) return;

    if ( function_exists( 'as_schedule_single_action' ) ) {
        if ( ! as_next_scheduled_action( 'pps_process_artwork_upload', array( $order_id ), 'pps-gdrive' ) ) {
            as_schedule_single_action( time(), 'pps_process_artwork_upload', array( $order_id ), 'pps-gdrive' );
        }
    } else {
        pps_process_artwork_upload( $order_id );
    }
}

// ═══════════════════════════════════════════════════════════════
// PROCESS ARTWORK UPLOAD (background via Action Scheduler)
// ═══════════════════════════════════════════════════════════════

add_action( 'pps_process_artwork_upload', 'pps_process_artwork_upload' );

function pps_process_artwork_upload( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( $order->get_meta( '_pps_artwork_processed' ) ) return;

    if ( ! pps_gdrive_parent_folder() ) {
        error_log( 'PPS Drive: Parent folder ID not configured. Order ' . $order_id );
        return;
    }

    if ( ! pps_gdrive_is_connected() ) {
        error_log( 'PPS Drive: Not connected. Order ' . $order_id );
        return;
    }

    $upload        = wp_upload_dir();
    $had_artwork   = false;
    $all_succeeded = true;

    foreach ( $order->get_items() as $item_id => $item ) {
        $artwork_path = $item->get_meta( '_pps_artwork_path' );
        if ( ! $artwork_path ) continue;

        $full_path = trailingslashit( $upload['basedir'] ) . $artwork_path;
        if ( ! file_exists( $full_path ) ) {
            error_log( 'PPS Drive: File missing for order ' . $order_id . ': ' . $full_path );
            continue;
        }

        $had_artwork = true;
        $token = pathinfo( basename( $artwork_path ), PATHINFO_FILENAME );

        $thumb_name = pps_generate_thumbnail( $full_path, $token );
        if ( $thumb_name ) {
            $item->update_meta_data( '_pps_artwork_thumb', $thumb_name );
            $item->save();
        }

        $folder_id = $order->get_meta( '_pps_gdrive_folder_id' );
        if ( ! $folder_id ) {
            $folder_name = 'Order #' . $order_id . ' — ' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $folder_id   = pps_gdrive_create_folder( $folder_name, pps_gdrive_parent_folder() );
            if ( $folder_id ) {
                $order->update_meta_data( '_pps_gdrive_folder_id', $folder_id );
            }
        }

        if ( ! $folder_id ) {
            error_log( 'PPS Drive: Failed to create folder for order ' . $order_id );
            $all_succeeded = false;
            continue;
        }

        $product        = $item->get_product();
        $prod_name      = $product ? sanitize_file_name( $product->get_name() ) : 'artwork';
        $original_ext   = pathinfo( $artwork_path, PATHINFO_EXTENSION );
        $drive_filename = $prod_name . '.' . $original_ext;

        $file_id = pps_gdrive_upload_file( $full_path, $drive_filename, $folder_id );

        if ( $file_id ) {
            $drive_url = 'https://drive.google.com/file/d/' . $file_id . '/view';
            $item->update_meta_data( '_pps_gdrive_file_id', $file_id );
            $item->update_meta_data( '_pps_gdrive_url', $drive_url );
            $item->update_meta_data( '_pps_artwork_path', '' );
            $item->update_meta_data( '_pps_artwork_location', 'gdrive' );
            $item->save();

            if ( file_exists( $full_path ) ) unlink( $full_path );
            @rmdir( dirname( $full_path ) );
            @rmdir( dirname( dirname( $full_path ) ) );

            error_log( 'PPS Drive: Order ' . $order_id . ' → ' . $file_id );
        } else {
            error_log( 'PPS Drive: Upload failed for order ' . $order_id . '. File kept locally.' );
            $all_succeeded = false;
        }
    }

    if ( $had_artwork && $all_succeeded ) {
        $order->update_meta_data( '_pps_artwork_processed', current_time( 'mysql' ) );
        $order->save();
    } elseif ( $had_artwork && ! $all_succeeded ) {
        $order->save();
        $attempts = intval( $order->get_meta( '_pps_drive_attempts' ) );
        if ( $attempts < 10 && function_exists( 'as_schedule_single_action' ) ) {
            $order->update_meta_data( '_pps_drive_attempts', $attempts + 1 );
            $order->save();
            $delay = 300 * pow( 2, $attempts );
            as_schedule_single_action( time() + $delay, 'pps_process_artwork_upload', array( $order_id ), 'pps-gdrive' );
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// ADMIN ORDER: ARTWORK DISPLAY
// ═══════════════════════════════════════════════════════════════

function pps_render_artwork_link( $item ) {
    $location  = $item->get_meta( '_pps_artwork_location' );
    $drive_url = $item->get_meta( '_pps_gdrive_url' );
    $thumb     = $item->get_meta( '_pps_artwork_thumb' );
    $local     = $item->get_meta( '_pps_artwork_path' );
    $output    = '';

    if ( $thumb ) {
        $thumb_url = trailingslashit( pps_thumb_url() ) . $thumb;
        $output .= '<div style="margin:6px 0"><img src="' . esc_url( $thumb_url ) . '" style="max-width:200px;border:1px solid #ddd;border-radius:4px" /></div>';
    }

    if ( $location === 'gdrive' && $drive_url ) {
        $output .= '<p style="margin:0 0 6px"><strong>Artwork:</strong> <a href="' . esc_url( $drive_url ) . '" target="_blank" style="text-decoration:none">📁 Open in Google Drive</a></p>';
        return $output;
    }

    if ( $local ) {
        $upload    = wp_upload_dir();
        $full_path = trailingslashit( $upload['basedir'] ) . $local;
        $file_url  = trailingslashit( $upload['baseurl'] ) . $local;
        if ( file_exists( $full_path ) ) {
            $fsize = size_format( filesize( $full_path ) );
            $ext   = strtoupper( pathinfo( $local, PATHINFO_EXTENSION ) );
            $output .= '<p style="margin:0 0 6px"><strong>Artwork:</strong> <a href="' . esc_url( $file_url ) . '" target="_blank">📎 Download ' . esc_html( $ext ) . ' (' . esc_html( $fsize ) . ')</a>';
            $output .= ' <span style="color:#dba617;font-size:11px">⚠ Pending Drive upload</span></p>';
            return $output;
        }
    }

    if ( ! $drive_url && ! $local ) return '';
    $output .= '<p style="margin:0 0 6px;color:#b32d2e"><strong>Artwork:</strong> File not found</p>';
    return $output;
}

add_filter( 'woocommerce_hidden_order_itemmeta', function( $hidden ) {
    return array_merge( $hidden, array(
        '_pps_artwork_thumb', '_pps_artwork_location',
        '_pps_gdrive_file_id', '_pps_gdrive_url',
        '_pps_gdrive_folder_id', '_pps_drive_attempts',
    ) );
});
