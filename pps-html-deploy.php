<?php
/**
 * Plugin Name: PPS HTML Deploy
 * Description: File-system-based deploy capability for PPS calculator HTML files. Co-loaded as both an activatable plugin AND a sub-module required from pps-calculators.php. Used by the priority-print MCP.
 * Version: 1.4.0
 * Author: Priority Print Service
 *
 * Drop calc-*.html into wp-content/plugins/pps-calculators/_pending_html/
 * OR upload to Media Library + add attachment IDs to wp_options
 * 'pps_html_deploy_pending_attachments'. Next WP request copies each
 * into wp-content/uploads/pps-calculators/, updates the registry,
 * archives sources, and logs to wp_options['pps_html_deploy_log'].
 *
 * NOTE: PHP hoists top-level function declarations, so a naive
 * `if (function_exists(...)) return;` guard at file top would always
 * fire (hoisted function is already declared by the time the line
 * runs). Instead, we rely on PHP's `include_once`/`require_once` to
 * prevent the file being loaded twice when both the active_plugins
 * auto-loader AND pps-calculators.php's require_once try to load it.
 *
 * v1.2.0: tolerate JSON-string option values. The MCP wp_update_option
 * endpoint serialises array payloads as JSON strings; without this
 * tolerance pps_html_deploy_pending_attachments stays string-shaped
 * and the is_array() guard would silently drop the deploy.
 *
 * v1.3.0: side-load pps-cart-price-floor.php (security patch shipped
 * via MCP ahead of the next Cloudways pull that brings the in-tree
 * fix from pps-calculators.php / production). Once production sync
 * lands, the floor file can be removed or left as a harmless duplicate.
 *
 * v1.4.0: bulk calculator upload. PPS Calculators → Bulk Upload takes
 * any number of .html files in one go and overwrites by filename, which
 * is the same rule the single-file form on the main page already uses —
 * calc-brochure.html replaces calc-brochure.html. A release is eight
 * files, and doing that eight times through a one-at-a-time form is
 * where a calculator gets missed.
 *
 * It lives here rather than in pps-calculators.php on purpose. This file
 * is small and in sync with the repo; pps-calculators.php on production
 * is ~78 KB ahead of the version in git, so editing and redeploying it
 * would destroy live code (see CLAUDE.md, "Server-side patches must come
 * home the same session").
 *
 * Two things it does that the single-file form does not:
 *   - writes to a temp file and rename()s over the target, so a live
 *     product page can never load a half-written calculator;
 *   - copies the outgoing version into _backup/<timestamp>/ first, so a
 *     bad release is a file copy away from being undone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Side-load the cart-price-floor security patch (best-effort) ──
$_pps_floor_path = __DIR__ . '/pps-cart-price-floor.php';
if ( file_exists( $_pps_floor_path ) ) {
    require_once $_pps_floor_path;
}
unset( $_pps_floor_path );

// ── Side-load rich HTML filter for WooCommerce category descriptions ──
$_pps_term_html = __DIR__ . '/pps-term-html.php';
if ( file_exists( $_pps_term_html ) ) {
    require_once $_pps_term_html;
}
unset( $_pps_term_html );

// ── Side-load dynamic shortcodes for category descriptions ──
$_pps_term_sc = __DIR__ . '/pps-term-shortcodes.php';
if ( file_exists( $_pps_term_sc ) ) {
    require_once $_pps_term_sc;
}
unset( $_pps_term_sc );

if ( ! defined( 'PPS_HTML_DEPLOY_PENDING_DIR' ) ) {
    define( 'PPS_HTML_DEPLOY_PENDING_DIR', defined( 'PPS_CALC_DIR' ) ? PPS_CALC_DIR . '_pending_html' : __DIR__ . '/_pending_html' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_ARCHIVE_DIR' ) ) {
    define( 'PPS_HTML_DEPLOY_ARCHIVE_DIR', PPS_HTML_DEPLOY_PENDING_DIR . '/_archive' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_LOG_OPTION' ) ) {
    define( 'PPS_HTML_DEPLOY_LOG_OPTION', 'pps_html_deploy_log_v2' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_LOCK' ) ) {
    define( 'PPS_HTML_DEPLOY_LOCK', 'pps_html_deploy_lock' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_MAX_BYTES' ) ) {
    define( 'PPS_HTML_DEPLOY_MAX_BYTES', 5 * 1024 * 1024 );
}
if ( ! defined( 'PPS_HTML_DEPLOY_LOG_CAP' ) ) {
    define( 'PPS_HTML_DEPLOY_LOG_CAP', 200 );
}
if ( ! defined( 'PPS_HTML_DEPLOY_ATTACH_OPTION' ) ) {
    define( 'PPS_HTML_DEPLOY_ATTACH_OPTION', 'pps_html_deploy_pending_attachments' );
}

add_action( 'plugins_loaded', 'pps_html_deploy_run', 5 );

function pps_html_deploy_log_append( $entry ) {
    $log = get_option( PPS_HTML_DEPLOY_LOG_OPTION, array() );
    if ( ! is_array( $log ) || isset( $log['time'] ) ) $log = array(); // reset legacy non-array shape
    $log[] = $entry;
    if ( count( $log ) > PPS_HTML_DEPLOY_LOG_CAP ) {
        $log = array_slice( $log, -PPS_HTML_DEPLOY_LOG_CAP );
    }
    update_option( PPS_HTML_DEPLOY_LOG_OPTION, $log, false );
}

function pps_html_deploy_run() {
    if ( ! defined( 'PPS_UPLOAD_SUBDIR' ) || ! defined( 'PPS_CALC_OPTION' ) ) {
        pps_html_deploy_log_append( array(
            'time'  => current_time( 'mysql' ),
            'event' => 'bail-no-host',
        ) );
        return;
    }

    $pending_files       = is_dir( PPS_HTML_DEPLOY_PENDING_DIR ) ? glob( PPS_HTML_DEPLOY_PENDING_DIR . '/*.html' ) : array();
    $pending_attachments = get_option( PPS_HTML_DEPLOY_ATTACH_OPTION, array() );
    // MCP wp_update_option stores values as JSON strings — accept those too.
    if ( is_string( $pending_attachments ) ) {
        $decoded = json_decode( $pending_attachments, true );
        if ( is_array( $decoded ) ) $pending_attachments = $decoded;
    }
    if ( ! is_array( $pending_attachments ) ) $pending_attachments = array();

    if ( empty( $pending_files ) && empty( $pending_attachments ) ) return;

    if ( get_transient( PPS_HTML_DEPLOY_LOCK ) ) return;
    set_transient( PPS_HTML_DEPLOY_LOCK, 1, 60 );

    $upload   = wp_upload_dir();
    $dest_dir = trailingslashit( $upload['basedir'] ) . PPS_UPLOAD_SUBDIR;
    if ( ! is_dir( $dest_dir ) ) {
        wp_mkdir_p( $dest_dir );
    }

    if ( ! is_dir( PPS_HTML_DEPLOY_ARCHIVE_DIR ) ) {
        wp_mkdir_p( PPS_HTML_DEPLOY_ARCHIVE_DIR );
    }
    $archive_run_dir = PPS_HTML_DEPLOY_ARCHIVE_DIR . '/' . gmdate( 'Y-m-d-His' );
    wp_mkdir_p( $archive_run_dir );

    $reg = function_exists( 'pps_get_registry' ) ? pps_get_registry() : get_option( PPS_CALC_OPTION, array() );

    // ── Path A: file-system staging ──
    foreach ( $pending_files as $src ) {
        $filename = basename( $src );
        $entry    = array(
            'time'     => current_time( 'mysql' ),
            'filename' => $filename,
            'bytes'    => 0,
            'ok'       => false,
            'error'    => '',
            'via'      => 'fs',
        );

        if ( ! preg_match( '/^calc-[a-z0-9-]+\.html$/i', $filename ) ) {
            $entry['error'] = 'invalid filename';
            pps_html_deploy_log_append( $entry );
            @rename( $src, $archive_run_dir . '/REJECTED-' . $filename );
            continue;
        }

        $size = @filesize( $src );
        if ( $size === false || $size === 0 ) {
            $entry['error'] = 'unreadable or empty';
            pps_html_deploy_log_append( $entry );
            @rename( $src, $archive_run_dir . '/UNREADABLE-' . $filename );
            continue;
        }
        if ( $size > PPS_HTML_DEPLOY_MAX_BYTES ) {
            $entry['error'] = 'too large';
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            @rename( $src, $archive_run_dir . '/TOOLARGE-' . $filename );
            continue;
        }

        $dest = trailingslashit( $dest_dir ) . $filename;
        if ( ! @copy( $src, $dest ) ) {
            $entry['error'] = 'copy failed';
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            continue;
        }

        $written = @filesize( $dest );
        if ( $written !== $size ) {
            @unlink( $dest );
            $entry['error'] = 'partial copy ' . intval( $written ) . '/' . $size;
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            continue;
        }

        if ( ! isset( $reg[ $filename ] ) ) {
            $reg[ $filename ] = array(
                'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
                'products' => '',
                'uploaded' => current_time( 'mysql' ),
            );
        } else {
            $reg[ $filename ]['uploaded'] = current_time( 'mysql' );
        }

        @rename( $src, $archive_run_dir . '/' . $filename );

        $entry['bytes'] = $size;
        $entry['ok']    = true;
        pps_html_deploy_log_append( $entry );
    }

    // ── Path B: WP-attachment staging ──
    $remaining_attachments = array();
    foreach ( $pending_attachments as $attach_id ) {
        $attach_id = intval( $attach_id );
        if ( $attach_id <= 0 ) continue;

        $src      = get_attached_file( $attach_id );
        $filename = $src ? basename( $src ) : '';
        $entry    = array(
            'time'      => current_time( 'mysql' ),
            'filename'  => $filename,
            'bytes'     => 0,
            'ok'        => false,
            'error'     => '',
            'via'       => 'attach',
            'attach_id' => $attach_id,
        );

        if ( ! $src || ! file_exists( $src ) ) {
            $entry['error'] = 'attachment file not found';
            pps_html_deploy_log_append( $entry );
            continue;
        }
        if ( ! preg_match( '/^calc-[a-z0-9-]+\.html$/i', $filename ) ) {
            $entry['error'] = 'invalid filename';
            pps_html_deploy_log_append( $entry );
            wp_delete_attachment( $attach_id, true );
            continue;
        }

        $size = @filesize( $src );
        if ( $size === false || $size === 0 ) {
            $entry['error'] = 'unreadable or empty';
            pps_html_deploy_log_append( $entry );
            wp_delete_attachment( $attach_id, true );
            continue;
        }
        if ( $size > PPS_HTML_DEPLOY_MAX_BYTES ) {
            $entry['error'] = 'too large';
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            wp_delete_attachment( $attach_id, true );
            continue;
        }

        $dest = trailingslashit( $dest_dir ) . $filename;
        if ( ! @copy( $src, $dest ) ) {
            $entry['error'] = 'copy failed';
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            $remaining_attachments[] = $attach_id;
            continue;
        }

        $written = @filesize( $dest );
        if ( $written !== $size ) {
            @unlink( $dest );
            $entry['error'] = 'partial copy ' . intval( $written ) . '/' . $size;
            $entry['bytes'] = $size;
            pps_html_deploy_log_append( $entry );
            $remaining_attachments[] = $attach_id;
            continue;
        }

        if ( ! isset( $reg[ $filename ] ) ) {
            $reg[ $filename ] = array(
                'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
                'products' => '',
                'uploaded' => current_time( 'mysql' ),
            );
        } else {
            $reg[ $filename ]['uploaded'] = current_time( 'mysql' );
        }

        wp_delete_attachment( $attach_id, true );

        $entry['bytes'] = $size;
        $entry['ok']    = true;
        pps_html_deploy_log_append( $entry );
    }

    update_option( PPS_HTML_DEPLOY_ATTACH_OPTION, $remaining_attachments, false );

    if ( function_exists( 'pps_save_registry' ) ) {
        pps_save_registry( $reg );
    } else {
        update_option( PPS_CALC_OPTION, $reg, false );
    }

    delete_transient( PPS_HTML_DEPLOY_LOCK );
}

// ═══════════════════════════════════════════════════════════════
// BULK CALCULATOR UPLOAD  (PPS Calculators → Bulk Upload)
// ═══════════════════════════════════════════════════════════════
//
// Same overwrite rule as the single-file form on the main page: the filename
// IS the identity. calc-brochure.html replaces calc-brochure.html; a name the
// registry has never seen is added as a new calculator. Product assignments
// survive an overwrite — only the 'uploaded' timestamp moves.

if ( ! defined( 'PPS_BULK_UPLOAD_SLUG' ) ) define( 'PPS_BULK_UPLOAD_SLUG', 'pps-bulk-upload' );

/**
 * Where calculators live. pps_upload_dir() is the main plugin's answer and is
 * authoritative; the fallback reproduces the same path and only matters if this
 * file is ever loaded without its host.
 */
function pps_bulk_upload_dir() {
    if ( function_exists( 'pps_upload_dir' ) ) return pps_upload_dir();
    $upload = wp_upload_dir();
    $sub    = defined( 'PPS_UPLOAD_SUBDIR' ) ? PPS_UPLOAD_SUBDIR : 'pps-calculators';
    return trailingslashit( $upload['basedir'] ) . $sub;
}

/**
 * Name for the file we stage the upload under before swapping it into place.
 *
 * It must live in the same directory as the target (rename() is only atomic
 * within a filesystem) and it must NOT end in .html: the main admin page globs
 * *.html to reconcile the registry against disk, so a temp file left there
 * would show up as a phantom calculator.
 */
function pps_bulk_upload_stage_name( $dir ) {
    $rand = function_exists( 'wp_generate_password' ) ? wp_generate_password( 12, false ) : uniqid();
    return trailingslashit( $dir ) . '.pps-upload-' . $rand . '.part';
}

/**
 * Fold one placed file into the registry and hand the registry back.
 *
 * The whole point is the else branch. On an overwrite only 'uploaded' moves —
 * 'products' is the product-ID assignment, and replacing the row wholesale
 * would unhook every calculator from its products on every release. That
 * failure surfaces as a bare product page, not an error, which is exactly the
 * kind that ships unnoticed.
 */
function pps_bulk_upload_register( $reg, $filename ) {
    if ( ! is_array( $reg ) ) $reg = array();
    $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

    if ( ! isset( $reg[ $filename ] ) || ! is_array( $reg[ $filename ] ) ) {
        $reg[ $filename ] = array(
            'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
            'products' => '',
            'uploaded' => $now,
        );
    } else {
        $reg[ $filename ]['uploaded'] = $now;
    }
    return $reg;
}

/** PHP's upload error codes say what went wrong; the operator needs it in English. */
function pps_bulk_upload_error_text( $code ) {
    switch ( (int) $code ) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'larger than the server upload limit (' . size_format( wp_max_upload_size() ) . ')';
        case UPLOAD_ERR_PARTIAL:    return 'only partially uploaded — try again';
        case UPLOAD_ERR_NO_FILE:    return 'no file received';
        case UPLOAD_ERR_NO_TMP_DIR: return 'server has no temp directory';
        case UPLOAD_ERR_CANT_WRITE: return 'server could not write the temp file';
        case UPLOAD_ERR_EXTENSION:  return 'blocked by a PHP extension';
    }
    return 'upload failed (code ' . intval( $code ) . ')';
}

add_action( 'admin_menu', function() {
    add_submenu_page(
        'pps-calculators',
        'Bulk Upload Calculators',
        'Bulk Upload',
        'manage_options',
        PPS_BULK_UPLOAD_SLUG,
        'pps_bulk_upload_page'
    );
}, 20 );

/**
 * Take every uploaded file and place it. Returns one row per file for display.
 *
 * Placement is deliberately not a straight move_uploaded_file() onto the live
 * path. Each file goes to a temp name in the same directory, gets its size
 * checked, and is then rename()d over the target — atomic on the same
 * filesystem, so a customer loading a product page mid-upload gets either the
 * old calculator or the new one, never half of either.
 */
function pps_bulk_upload_handle() {
    $results = array();

    $files = isset( $_FILES['pps_bulk_files'] ) ? $_FILES['pps_bulk_files'] : null;
    if ( ! is_array( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) return $results;

    $dir = pps_bulk_upload_dir();
    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
        return array( array( 'name' => '—', 'ok' => false, 'note' => 'could not create ' . $dir ) );
    }
    if ( ! is_writable( $dir ) ) {
        return array( array( 'name' => '—', 'ok' => false, 'note' => 'directory is not writable: ' . $dir ) );
    }

    $reg = function_exists( 'pps_get_registry' )
        ? pps_get_registry()
        : (array) get_option( defined( 'PPS_CALC_OPTION' ) ? PPS_CALC_OPTION : 'pps_calculators', array() );

    $backup_dir  = trailingslashit( $dir ) . '_backup/' . gmdate( 'Y-m-d-His' );
    $backup_made = false;
    $reg_changed = false;

    $count = count( $files['name'] );
    for ( $i = 0; $i < $count; $i++ ) {
        $raw = isset( $files['name'][ $i ] ) ? (string) $files['name'][ $i ] : '';
        if ( $raw === '' ) continue;

        $filename = sanitize_file_name( $raw );
        $row = array( 'name' => $filename !== '' ? $filename : $raw, 'ok' => false, 'note' => '', 'bytes' => 0, 'verb' => '' );

        $err = isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE;
        if ( $err !== UPLOAD_ERR_OK ) {
            $row['note'] = pps_bulk_upload_error_text( $err );
            $results[]   = $row;
            continue;
        }

        if ( $filename === '' || strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'html' ) {
            $row['note'] = 'not a .html file — skipped';
            $results[]   = $row;
            continue;
        }

        $tmp = isset( $files['tmp_name'][ $i ] ) ? $files['tmp_name'][ $i ] : '';
        // The one check that actually matters: PHP guarantees this path came from
        // a real multipart upload and is not, say, /etc/passwd smuggled in.
        if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
            $row['note'] = 'not a valid upload — skipped';
            $results[]   = $row;
            continue;
        }

        $size = (int) filesize( $tmp );
        $row['bytes'] = $size;
        if ( $size <= 0 ) {
            $row['note'] = 'empty file — skipped';
            $results[]   = $row;
            continue;
        }
        if ( $size > PPS_HTML_DEPLOY_MAX_BYTES ) {
            $row['note'] = 'over the ' . size_format( PPS_HTML_DEPLOY_MAX_BYTES ) . ' cap — skipped';
            $results[]   = $row;
            continue;
        }

        $dest      = trailingslashit( $dir ) . $filename;
        $is_update = file_exists( $dest );

        // Keep the outgoing version. A bulk release is where a bad build reaches
        // several product pages at once, so the way back should be a file copy.
        if ( $is_update ) {
            if ( ! $backup_made ) {
                wp_mkdir_p( $backup_dir );
                // Backups are old calculators, not secrets, but nothing needs to
                // serve them either.
                @file_put_contents( trailingslashit( $backup_dir ) . '.htaccess', "Require all denied\nDeny from all\n" );
                $backup_made = true;
            }
            @copy( $dest, trailingslashit( $backup_dir ) . $filename );
        }

        $stage = pps_bulk_upload_stage_name( $dir );

        if ( ! move_uploaded_file( $tmp, $stage ) ) {
            $row['note'] = 'could not write to the calculators directory';
            $results[]   = $row;
            continue;
        }

        $written = (int) filesize( $stage );
        if ( $written !== $size ) {
            @unlink( $stage );
            $row['note'] = sprintf( 'partial write (%d of %d bytes) — left the previous version in place', $written, $size );
            $results[]   = $row;
            continue;
        }

        if ( ! @rename( $stage, $dest ) ) {
            @unlink( $stage );
            $row['note'] = 'could not replace the existing file';
            $results[]   = $row;
            continue;
        }
        @chmod( $dest, 0644 );

        $reg         = pps_bulk_upload_register( $reg, $filename );
        $reg_changed = true;

        $row['ok']   = true;
        $row['verb'] = $is_update ? 'Replaced' : 'Added';
        $results[]   = $row;

        pps_html_deploy_log_append( array(
            'time'     => current_time( 'mysql' ),
            'filename' => $filename,
            'bytes'    => $size,
            'ok'       => true,
            'error'    => '',
            'via'      => 'bulk-admin',
        ) );
    }

    if ( $reg_changed ) {
        if ( function_exists( 'pps_save_registry' ) ) {
            pps_save_registry( $reg );
        } else {
            update_option( defined( 'PPS_CALC_OPTION' ) ? PPS_CALC_OPTION : 'pps_calculators', $reg, false );
        }
    }

    return $results;
}

function pps_bulk_upload_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $results  = array();
    $overflow = false;

    // A POST with nothing in it means the body blew past post_max_size: PHP throws
    // away $_POST and $_FILES both, so the nonce is gone too and the page would
    // otherwise just silently do nothing. Checked before the nonce for that reason.
    if ( ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) === 'POST'
         && empty( $_POST ) && empty( $_FILES )
         && (int) ( isset( $_SERVER['CONTENT_LENGTH'] ) ? $_SERVER['CONTENT_LENGTH'] : 0 ) > 0 ) {
        $overflow = true;
    } elseif ( isset( $_POST['pps_bulk_action'] ) ) {
        check_admin_referer( 'pps_bulk_upload' );
        $results = pps_bulk_upload_handle();
    }

    $dir         = pps_bulk_upload_dir();
    $post_max    = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
    $per_file    = wp_max_upload_size();
    $max_files   = (int) ini_get( 'max_file_uploads' );
    $ok_count    = count( array_filter( $results, function( $r ) { return ! empty( $r['ok'] ); } ) );
    $fail_count  = count( $results ) - $ok_count;
    ?>
    <div class="wrap" style="max-width:900px">
        <h1>Bulk Upload Calculators</h1>
        <p class="description" style="font-size:13px">
            Pick as many <code>.html</code> calculators as you like. The filename decides what each one
            replaces — <code>calc-brochure.html</code> overwrites <code>calc-brochure.html</code>.
            Product assignments are kept; a name the registry hasn't seen before is added as a new calculator.
        </p>

        <?php if ( $overflow ) : ?>
            <div class="notice notice-error"><p>
                <strong>Nothing was uploaded.</strong> The batch was larger than this server's
                <code>post_max_size</code> of <?php echo esc_html( size_format( $post_max ) ); ?>,
                so PHP discarded the whole request. Upload them in two or three smaller batches.
            </p></div>
        <?php endif; ?>

        <?php if ( $results ) : ?>
            <div class="notice <?php echo $fail_count ? 'notice-warning' : 'notice-success'; ?>">
                <p><strong><?php echo intval( $ok_count ); ?></strong> placed<?php
                    if ( $fail_count ) echo ', <strong>' . intval( $fail_count ) . '</strong> skipped'; ?>.</p>
            </div>
            <table class="widefat striped" style="margin-bottom:20px">
                <thead><tr><th>File</th><th style="width:110px">Result</th><th style="width:90px">Size</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ( $results as $r ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $r['name'] ); ?></code></td>
                        <td><?php echo ! empty( $r['ok'] )
                            ? '<span style="color:#46882c;font-weight:600">' . esc_html( $r['verb'] ) . '</span>'
                            : '<span style="color:#b32d2e;font-weight:600">Skipped</span>'; ?></td>
                        <td><?php echo ! empty( $r['bytes'] ) ? esc_html( size_format( $r['bytes'] ) ) : '—'; ?></td>
                        <td style="color:#666"><?php echo esc_html( isset( $r['note'] ) ? $r['note'] : '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'pps_bulk_upload' ); ?>
            <input type="hidden" name="pps_bulk_action" value="1">
            <div id="pps-drop" style="border:2px dashed #c3c4c7;border-radius:6px;background:#fff;padding:26px;text-align:center">
                <p style="margin:0 0 12px;font-size:13px;color:#555">Drop <code>.html</code> calculators here, or choose them below.</p>
                <input type="file" name="pps_bulk_files[]" id="pps-bulk-files" accept=".html,text/html" multiple required>
                <p id="pps-picked" style="margin:12px 0 0;font-size:12px;color:#666"></p>
            </div>
            <p><button type="submit" class="button button-primary">Upload &amp; Replace</button></p>
        </form>

        <p style="font-size:12px;color:#888;margin-top:18px">
            Writing to <code><?php echo esc_html( $dir ); ?></code>.
            Limits: <?php echo esc_html( size_format( $per_file ) ); ?> per file,
            <?php echo esc_html( size_format( $post_max ) ); ?> per batch<?php
            if ( $max_files > 0 ) echo ', ' . intval( $max_files ) . ' files per batch'; ?>.
            Replaced versions are kept in <code>_backup/</code>.
        </p>
    </div>
    <script>
    (function(){
        var input = document.getElementById('pps-bulk-files'),
            drop  = document.getElementById('pps-drop'),
            note  = document.getElementById('pps-picked');
        if (!input || !drop) return;
        var MAX = <?php echo $max_files > 0 ? intval( $max_files ) : 0; ?>;
        function describe(){
            var n = input.files ? input.files.length : 0;
            if (!n) { note.textContent = ''; return; }
            var names = [], i;
            for (i = 0; i < n; i++) names.push(input.files[i].name);
            note.textContent = n + (n === 1 ? ' file: ' : ' files: ') + names.join(', ');
            // PHP silently drops anything past max_file_uploads, which would look
            // like the upload just quietly missed a calculator.
            if (MAX && n > MAX) {
                note.textContent += ' — too many; this server accepts ' + MAX + ' per batch.';
                note.style.color = '#b32d2e';
            } else {
                note.style.color = '#666';
            }
        }
        input.addEventListener('change', describe);
        ['dragenter','dragover'].forEach(function(e){
            drop.addEventListener(e, function(ev){ ev.preventDefault(); drop.style.borderColor = '#007eff'; });
        });
        ['dragleave','drop'].forEach(function(e){
            drop.addEventListener(e, function(ev){ ev.preventDefault(); drop.style.borderColor = '#c3c4c7'; });
        });
        drop.addEventListener('drop', function(ev){
            if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files.length) {
                input.files = ev.dataTransfer.files;
                describe();
            }
        });
    })();
    </script>
    <?php
}
