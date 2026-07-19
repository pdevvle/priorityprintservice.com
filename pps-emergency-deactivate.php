<?php
/**
 * Plugin Name: PPS Emergency Deactivate (mu-plugin)
 * Description: Drop into wp-content/mu-plugins/. Visit any page with
 *              ?pps_emergency=<secret> to deactivate pps-calculators
 *              and pps-html-deploy without needing wp-admin or SSH.
 *
 * Usage:
 *   1. Upload this file to wp-content/mu-plugins/pps-emergency-deactivate.php
 *   2. Set PPS_EMERGENCY_SECRET in wp-config.php:
 *        define( 'PPS_EMERGENCY_SECRET', 'your-secret-here' );
 *      Or skip that and use the hardcoded fallback below.
 *   3. When the site is down, visit:
 *        https://yoursite.com/?pps_emergency=your-secret-here
 *   4. The plugin deactivates pps-calculators, clears object cache,
 *      and prints a confirmation page. Site should come back.
 *
 * This runs as a mu-plugin so it loads BEFORE regular plugins —
 * it works even when a regular plugin is fatally crashing the site.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'muplugins_loaded', function() {
    if ( ! isset( $_GET['pps_emergency'] ) ) return;

    $secret = defined( 'PPS_EMERGENCY_SECRET' ) ? PPS_EMERGENCY_SECRET : 'pps-kill-2026';
    if ( ! hash_equals( $secret, (string) $_GET['pps_emergency'] ) ) return;

    $plugins_to_kill = array(
        'pps-calculators/pps-calculators.php',
        'pps-calculators/pps-html-deploy.php',
        'pps-calculators/pps-db-diag.php',
    );

    $active = get_option( 'active_plugins', array() );
    if ( ! is_array( $active ) ) $active = array();

    $removed = array();
    foreach ( $plugins_to_kill as $p ) {
        $key = array_search( $p, $active, true );
        if ( $key !== false ) {
            unset( $active[ $key ] );
            $removed[] = $p;
        }
    }

    if ( ! empty( $removed ) ) {
        $active = array_values( $active );
        update_option( 'active_plugins', $active );

        // Flush object cache so the change takes effect immediately
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
    }

    // Delete any leftover diagnostic files
    $diag_dir = WP_PLUGIN_DIR . '/pps-calculators/';
    foreach ( glob( $diag_dir . '_pps_diag*.php' ) as $f ) {
        @unlink( $f );
    }

    header( 'Content-Type: text/plain; charset=utf-8' );
    echo "PPS Emergency Deactivate\n";
    echo "========================\n\n";
    if ( ! empty( $removed ) ) {
        echo "Deactivated:\n";
        foreach ( $removed as $p ) echo "  - $p\n";
    } else {
        echo "No PPS plugins were active.\n";
    }
    echo "\nDiagnostic files cleaned.\n";
    echo "Object cache flushed.\n";
    echo "\nSite should be back. Reload without ?pps_emergency to verify.\n";
    exit;
});
