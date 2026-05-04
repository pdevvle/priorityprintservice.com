<?php
/**
 * Plugin Name: PPS HTML Deploy
 * Description: File-system-based deploy capability for PPS calculator HTML files. Co-loaded as both an activatable plugin (via active_plugins) AND a sub-module required from pps-calculators.php. Used by the priority-print MCP.
 * Version: 1.0.0
 * Author: Priority Print Service
 *
 * Provides a permanent, file-system-based deploy capability for calculator
 * HTML files, intended for use by the priority-print MCP (which can write
 * inside wp-content/plugins/ but not directly into wp-content/uploads/).
 *
 * Mechanism: drop new calculator HTML files into:
 *     wp-content/plugins/pps-calculators/_pending_html/calc-<slug>.html
 * On the next WP request, plugins_loaded fires this module, which copies
 * each staged file into the upload subdirectory used by the calculators
 * (wp_upload_dir()['basedir'] . '/pps-calculators/'), updates the
 * pps_calculators_registry option, archives the staged source, and logs
 * the operation to wp_options['pps_html_deploy_log'].
 *
 * Security:
 *   - No HTTP endpoint. Runs only on internal plugins_loaded hook.
 *   - Filenames must match /^calc-[a-z0-9-]+\.html$/i (no path traversal).
 *   - File size capped at 5 MiB.
 *   - Source/destination directories are fixed; no caller-supplied paths.
 *
 * Maintainer note: this is the path the priority-print MCP uses for calc
 * HTML deploys. The customer-facing admin UI upload form in
 * pps-calculators.php still exists and is unaffected.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Guard against double-load. This file is loaded BOTH via require_once
// from pps-calculators.php (sub-module path) AND via WP's active_plugins
// auto-loader (since this file declares its own Plugin Name header so it
// can be activated standalone before pps-calculators.php pulls the
// require_once line from git). The function-exists guard ensures the
// hook function is declared exactly once.
if ( function_exists( 'pps_html_deploy_run' ) ) return;

// pps-calculators.php is required for the constants (PPS_UPLOAD_SUBDIR,
// PPS_CALC_OPTION) and registry helpers used below. Load order: WP iterates
// active_plugins alphabetically by path, so pps-calculators/pps-calculators.php
// loads BEFORE pps-calculators/pps-html-deploy.php. By the time this file's
// plugins_loaded hook fires (priority 5), the host plugin's defines and
// functions are available. We also runtime-check defined() before processing.

// ═══════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════

if ( ! defined( 'PPS_HTML_DEPLOY_PENDING_DIR' ) ) {
    define( 'PPS_HTML_DEPLOY_PENDING_DIR', PPS_CALC_DIR . '_pending_html' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_ARCHIVE_DIR' ) ) {
    define( 'PPS_HTML_DEPLOY_ARCHIVE_DIR', PPS_HTML_DEPLOY_PENDING_DIR . '/_archive' );
}
if ( ! defined( 'PPS_HTML_DEPLOY_LOG_OPTION' ) ) {
    define( 'PPS_HTML_DEPLOY_LOG_OPTION', 'pps_html_deploy_log' );
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

// ═══════════════════════════════════════════════════════════════
// HOOK
// ═══════════════════════════════════════════════════════════════

add_action( 'plugins_loaded', 'pps_html_deploy_run', 5 );

function pps_html_deploy_run() {
    // Fast path: nothing to do unless we have something to process.
    if ( ! defined( 'PPS_CALC_DIR' ) || ! defined( 'PPS_UPLOAD_SUBDIR' ) || ! defined( 'PPS_CALC_OPTION' ) ) return;

    $pending_files       = is_dir( PPS_HTML_DEPLOY_PENDING_DIR ) ? glob( PPS_HTML_DEPLOY_PENDING_DIR . '/*.html' ) : array();
    $pending_attachments = get_option( PPS_HTML_DEPLOY_ATTACH_OPTION, array() );
    if ( ! is_array( $pending_attachments ) ) $pending_attachments = array();

    if ( empty( $pending_files ) && empty( $pending_attachments ) ) return;

    // Transient lock prevents concurrent runs (e.g. under heavy traffic).
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

    $reg     = function_exists( 'pps_get_registry' ) ? pps_get_registry() : get_option( PPS_CALC_OPTION, array() );
    $entries = array();

    // ── Path A: file-system staging (pps-calculators/_pending_html/*.html) ──
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
            $entry['error'] = 'invalid filename (must match calc-<slug>.html)';
            $entries[]      = $entry;
            // Move out of pending so we don't loop on the same bad file.
            @rename( $src, $archive_run_dir . '/REJECTED-' . $filename );
            continue;
        }

        $size = @filesize( $src );
        if ( $size === false || $size === 0 ) {
            $entry['error'] = 'source file unreadable or empty';
            $entries[]      = $entry;
            @rename( $src, $archive_run_dir . '/UNREADABLE-' . $filename );
            continue;
        }
        if ( $size > PPS_HTML_DEPLOY_MAX_BYTES ) {
            $entry['error'] = 'source exceeds size cap (' . PPS_HTML_DEPLOY_MAX_BYTES . ' bytes)';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            @rename( $src, $archive_run_dir . '/TOOLARGE-' . $filename );
            continue;
        }

        $dest = trailingslashit( $dest_dir ) . $filename;
        if ( ! @copy( $src, $dest ) ) {
            $entry['error'] = 'copy() failed (check permissions on ' . $dest_dir . ')';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            continue; // leave pending so a future request can retry
        }

        // Verify byte count matches; bail if partial copy.
        $written = @filesize( $dest );
        if ( $written !== $size ) {
            @unlink( $dest );
            $entry['error'] = 'partial copy (wrote ' . intval( $written ) . ' of ' . $size . ' bytes)';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            continue;
        }

        // Update registry to match the existing admin-form upload behavior.
        if ( ! isset( $reg[ $filename ] ) ) {
            $reg[ $filename ] = array(
                'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
                'products' => '',
                'uploaded' => current_time( 'mysql' ),
            );
        } else {
            $reg[ $filename ]['uploaded'] = current_time( 'mysql' );
        }

        // Archive the source on success.
        @rename( $src, $archive_run_dir . '/' . $filename );

        $entry['bytes'] = $size;
        $entry['ok']    = true;
        $entries[]      = $entry;
    }

    // ── Path B: WP-attachment staging (pps_html_deploy_pending_attachments option) ──
    // Used by the priority-print MCP via wp_upload_request + curl, which
    // streams large HTML files to the WP Media Library without putting them
    // through the MCP transport. The attachment ID(s) are written to
    // pps_html_deploy_pending_attachments; we copy each into the calc upload
    // dir, then delete the attachment to avoid littering the Media Library.
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
            $entries[]      = $entry;
            // Drop from pending; nothing we can do.
            continue;
        }
        if ( ! preg_match( '/^calc-[a-z0-9-]+\.html$/i', $filename ) ) {
            $entry['error'] = 'invalid filename (must match calc-<slug>.html)';
            $entries[]      = $entry;
            wp_delete_attachment( $attach_id, true );
            continue;
        }

        $size = @filesize( $src );
        if ( $size === false || $size === 0 ) {
            $entry['error'] = 'attachment file unreadable or empty';
            $entries[]      = $entry;
            wp_delete_attachment( $attach_id, true );
            continue;
        }
        if ( $size > PPS_HTML_DEPLOY_MAX_BYTES ) {
            $entry['error'] = 'source exceeds size cap (' . PPS_HTML_DEPLOY_MAX_BYTES . ' bytes)';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            wp_delete_attachment( $attach_id, true );
            continue;
        }

        $dest = trailingslashit( $dest_dir ) . $filename;
        if ( ! @copy( $src, $dest ) ) {
            $entry['error'] = 'copy() failed (check permissions on ' . $dest_dir . ')';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            // Leave pending so a future request can retry.
            $remaining_attachments[] = $attach_id;
            continue;
        }

        $written = @filesize( $dest );
        if ( $written !== $size ) {
            @unlink( $dest );
            $entry['error'] = 'partial copy (wrote ' . intval( $written ) . ' of ' . $size . ' bytes)';
            $entry['bytes'] = $size;
            $entries[]      = $entry;
            $remaining_attachments[] = $attach_id;
            continue;
        }

        // Update registry.
        if ( ! isset( $reg[ $filename ] ) ) {
            $reg[ $filename ] = array(
                'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
                'products' => '',
                'uploaded' => current_time( 'mysql' ),
            );
        } else {
            $reg[ $filename ]['uploaded'] = current_time( 'mysql' );
        }

        // Cleanup: remove the attachment from Media Library on success.
        wp_delete_attachment( $attach_id, true );

        $entry['bytes'] = $size;
        $entry['ok']    = true;
        $entries[]      = $entry;
    }

    // Persist the remaining (failed-retry) attachments back to the option.
    update_option( PPS_HTML_DEPLOY_ATTACH_OPTION, $remaining_attachments, false );

    // Persist registry once after all files processed.
    if ( function_exists( 'pps_save_registry' ) ) {
        pps_save_registry( $reg );
    } else {
        update_option( PPS_CALC_OPTION, $reg, false );
    }

    // Append to log (FIFO-cap to keep wp_options light).
    if ( ! empty( $entries ) ) {
        $log = get_option( PPS_HTML_DEPLOY_LOG_OPTION, array() );
        if ( ! is_array( $log ) ) $log = array();
        $log = array_merge( $log, $entries );
        if ( count( $log ) > PPS_HTML_DEPLOY_LOG_CAP ) {
            $log = array_slice( $log, -PPS_HTML_DEPLOY_LOG_CAP );
        }
        update_option( PPS_HTML_DEPLOY_LOG_OPTION, $log, false );
    }

    delete_transient( PPS_HTML_DEPLOY_LOCK );
}
