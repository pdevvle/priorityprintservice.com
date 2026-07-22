<?php
/**
 * PPS Homepage Logo-Band Probe (staging diagnostic — inert without trigger)
 *
 * Fetches the rendered homepage server-side and captures the real HTML + the
 * Spectra-generated CSS for the "Preferred by businesses…" client-logo band,
 * so a CSS fix can be written against the ACTUAL markup rather than guessed.
 *
 * Run: set option 'pps_home_probe_trigger' to any non-empty value, make one
 * request, then read option 'pps_home_probe_result'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_loaded', function () {
    if ( '' === (string) get_option( 'pps_home_probe_trigger', '' ) ) return;
    delete_option( 'pps_home_probe_trigger' );
    if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 60 );
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();

    $r = wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) );
    if ( is_wp_error( $r ) ) {
        update_option( 'pps_home_probe_result', array( 'error' => $r->get_error_message() ), false );
        return;
    }
    $html = (string) wp_remote_retrieve_body( $r );
    $out  = array( 'http' => (int) wp_remote_retrieve_response_code( $r ), 'len' => strlen( $html ) );

    // 1. The logo-band HTML: anchor on the first uagb block id (body-only marker).
    $anchor = strpos( $html, 'uagb-block-d6f51c67' );
    if ( false !== $anchor ) {
        $out['band_html'] = substr( $html, max( 0, $anchor - 900 ), 4500 );
    } else {
        $out['band_html'] = '(uagb-block-d6f51c67 not found)';
    }

    // 1b. Whitespace analysis of a source logo image (is padding baked into the file?).
    $img_url = 'https://woocommerce-70867-4915293.cloudwaysapps.com/wp-content/uploads/2023/02/Priority-Print-Service-Online-Printing-Services-4.jpg';
    $ir = wp_remote_get( $img_url, array( 'timeout' => 15, 'sslverify' => false ) );
    if ( ! is_wp_error( $ir ) && function_exists( 'imagecreatefromstring' ) ) {
        $im = @imagecreatefromstring( wp_remote_retrieve_body( $ir ) );
        if ( $im ) {
            $w = imagesx( $im ); $h = imagesy( $im );
            $minX = $w; $minY = $h; $maxX = 0; $maxY = 0; $step = 2;
            for ( $y = 0; $y < $h; $y += $step ) {
                for ( $x = 0; $x < $w; $x += $step ) {
                    $rgb = imagecolorat( $im, $x, $y );
                    $rr = ( $rgb >> 16 ) & 0xFF; $gg = ( $rgb >> 8 ) & 0xFF; $bb = $rgb & 0xFF;
                    if ( $rr < 240 || $gg < 240 || $bb < 240 ) { // non-white = content
                        if ( $x < $minX ) $minX = $x; if ( $x > $maxX ) $maxX = $x;
                        if ( $y < $minY ) $minY = $y; if ( $y > $maxY ) $maxY = $y;
                    }
                }
            }
            imagedestroy( $im );
            $out['logo_img'] = array(
                'canvas'      => $w . 'x' . $h,
                'content_box' => ( $maxX >= $minX ) ? ( ( $maxX - $minX ) . 'x' . ( $maxY - $minY ) . ' at ' . $minX . ',' . $minY ) : 'none',
                'pad_pct'     => ( $maxX >= $minX ) ? array(
                    'left'   => round( 100 * $minX / $w ),
                    'right'  => round( 100 * ( $w - $maxX ) / $w ),
                    'top'    => round( 100 * $minY / $h ),
                    'bottom' => round( 100 * ( $h - $maxY ) / $h ),
                    'content_w' => round( 100 * ( $maxX - $minX ) / $w ),
                    'content_h' => round( 100 * ( $maxY - $minY ) / $h ),
                ) : 'n/a',
            );
        } else {
            $out['logo_img'] = 'imagecreatefromstring failed';
        }
    } else {
        $out['logo_img'] = is_wp_error( $ir ) ? $ir->get_error_message() : 'no GD';
    }

    // 2. Spectra per-block CSS + any object-fit / aspect-ratio / height rules that
    //    touch the uagb image blocks. Pull every <style> block, keep rules that
    //    mention a uagb block id or the logo band.
    $ids = array( 'd6f51c67', '52bd251c', '32caae33', '5d67d274', '1218c51c', '2906d89a', '677eba0e', '53d2ad32' );
    $css_hits = array();
    if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $html, $sm ) ) {
        foreach ( $sm[1] as $style ) {
            foreach ( $ids as $id ) {
                if ( preg_match_all( '/[^{}]*uagb-block-' . $id . '[^{}]*\{[^}]*\}/', $style, $rm ) ) {
                    foreach ( $rm[0] as $rule ) $css_hits[] = trim( $rule );
                }
            }
            // pps-benefits / uagb-image rules with layout-affecting props
            if ( preg_match_all( '/[^{}]*(?:pps-benefits|wp-block-uagb-image)[^{}]*\{[^}]*(?:object-fit|aspect-ratio|height|width|padding|margin|flex)[^}]*\}/', $style, $rm2 ) ) {
                foreach ( $rm2[0] as $rule ) $css_hits[] = trim( $rule );
            }
        }
    }
    $out['css_hits'] = array_slice( array_values( array_unique( $css_hits ) ), 0, 60 );
    $out['fix_present'] = ( false !== strpos( $html, 'HOMEPAGE CLIENT-LOGO STRIP' ) );

    update_option( 'pps_home_probe_result', $out, false );
}, 99 );
