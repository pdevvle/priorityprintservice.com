<?php
/**
 * PPS Calculators — HTML Deploy Sub-Module
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

// ═══════════════════════════════════════════════════════════════
// HOOK
// ═══════════════════════════════════════════════════════════════

add_action( 'plugins_loaded', 'pps_html_deploy_run', 5 );

function pps_html_deploy_run() {
    // Fast path: nothing to do unless the pending dir exists and has files.
    if ( ! is_dir( PPS_HTML_DEPLOY_PENDING_DIR ) ) return;

    $pending = glob( PPS_HTML_DEPLOY_PENDING_DIR . '/*.html' );
    if ( empty( $pending ) ) return;

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

    foreach ( $pending as $src ) {
        $filename = basename( $src );
        $entry    = array(
            'time'     => current_time( 'mysql' ),
            'filename' => $filename,
            'bytes'    => 0,
            'ok'       => false,
            'error'    => '',
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
