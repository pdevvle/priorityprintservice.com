<?php
/**
 * Plugin Name: PPS HTML Deploy
 * Description: File-system-based deploy capability for PPS calculator HTML files. Co-loaded as both an activatable plugin AND a sub-module required from pps-calculators.php. Used by the priority-print MCP.
 * Version: 1.1.0
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
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
