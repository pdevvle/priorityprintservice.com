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

/**
 * APPLY mode: trim each of the 8 client logos to its own content bounding box
 * (with a small uniform margin), overwrite the file, regenerate thumbnails, and
 * keep a one-time backup of each original at <file>.pre-trim-bak. Reversible.
 * Trigger: set 'pps_home_apply_trigger' to any value. Result: 'pps_home_apply_result'.
 */
add_action( 'wp_loaded', function () {
    if ( '' === (string) get_option( 'pps_home_apply_trigger', '' ) ) return;
    delete_option( 'pps_home_apply_trigger' );
    if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 180 );
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $ids = array( 39221, 39222, 39223, 39224, 39225, 39226, 39227, 39228 );
    $rep = array();
    foreach ( $ids as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) { $rep[ $id ] = 'no file'; continue; }
        $bak = $file . '.pre-trim-bak';
        if ( ! file_exists( $bak ) ) @copy( $file, $bak );          // one-time original backup
        $src = file_exists( $bak ) ? $bak : $file;                  // always trim from the pristine original
        $im  = @imagecreatefromstring( file_get_contents( $src ) );
        if ( ! $im ) { $rep[ $id ] = 'decode fail'; continue; }
        $w = imagesx( $im ); $h = imagesy( $im );
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        for ( $y = 0; $y < $h; $y++ ) {
            for ( $x = 0; $x < $w; $x++ ) {
                $c = imagecolorat( $im, $x, $y );
                if ( ( ( $c >> 16 ) & 255 ) < 240 || ( ( $c >> 8 ) & 255 ) < 240 || ( $c & 255 ) < 240 ) {
                    if ( $x < $minX ) $minX = $x; if ( $x > $maxX ) $maxX = $x;
                    if ( $y < $minY ) $minY = $y; if ( $y > $maxY ) $maxY = $y;
                }
            }
        }
        if ( $maxX < $minX ) { $rep[ $id ] = 'blank'; imagedestroy( $im ); continue; }
        $cw = $maxX - $minX + 1; $ch = $maxY - $minY + 1;
        $m  = max( 12, (int) round( 0.08 * min( $cw, $ch ) ) );     // small breathing margin
        $nx = max( 0, $minX - $m ); $ny = max( 0, $minY - $m );
        $nw = min( $w - $nx, $cw + 2 * $m ); $nh = min( $h - $ny, $ch + 2 * $m );
        $dst = imagecreatetruecolor( $nw, $nh );
        imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
        imagecopy( $dst, $im, 0, 0, $nx, $ny, $nw, $nh );
        imagejpeg( $dst, $file, 92 );
        imagedestroy( $im ); imagedestroy( $dst );
        $meta = wp_generate_attachment_metadata( $id, $file );
        $dropped = array();
        if ( $meta && ! empty( $meta['sizes'] ) ) {
            // Trimmed logos are wide/short; WP's hard-cropped square 'thumbnail'
            // (and any other aspect-changing size) would land in srcset and clip
            // the logo. Drop every generated size whose aspect differs from full,
            // so only the correct-aspect full image is ever served.
            $full_a = ( $nh > 0 ) ? $nw / $nh : 1;
            foreach ( $meta['sizes'] as $name => $sz ) {
                $a = ( ! empty( $sz['height'] ) ) ? $sz['width'] / $sz['height'] : 0;
                if ( abs( $a - $full_a ) > 0.08 ) {
                    @unlink( path_join( dirname( $file ), $sz['file'] ) );
                    unset( $meta['sizes'][ $name ] );
                    $dropped[] = $name;
                }
            }
        }
        if ( $meta ) wp_update_attachment_metadata( $id, $meta );
        $rep[ $id ] = "trimmed {$w}x{$h} -> {$nw}x{$nh}; dropped cropped sizes: " . ( $dropped ? implode( ',', $dropped ) : 'none' );
    }
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
    update_option( 'pps_home_apply_result', $rep, false );
}, 100 );

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

    // 1b. Whitespace analysis of ALL 8 source logos (padding is baked into the files).
    $base = 'https://woocommerce-70867-4915293.cloudwaysapps.com/wp-content/uploads/';
    $imgs = array(
        '1' => '2023/09/Priority-Print-Service-Online-Printing-Services-1.jpg',
        '2' => '2023/02/Priority-Print-Service-Online-Printing-Services-2.jpg',
        '3' => '2023/02/Priority-Print-Service-Online-Printing-Services-3.jpg',
        '4' => '2023/02/Priority-Print-Service-Online-Printing-Services-4.jpg',
        '5' => '2023/02/Priority-Print-Service-Online-Printing-Services-5.jpg',
        '6' => '2023/02/Priority-Print-Service-Online-Printing-Services-6.jpg',
        '7' => '2023/02/Priority-Print-Service-Online-Printing-Services-7.jpg',
        '8' => '2023/02/Priority-Print-Service-Online-Printing-Services-8.jpg',
    );
    $rows = array(); $maxCH = 0; $maxCW = 0;
    foreach ( $imgs as $k => $rel ) {
        $ir = wp_remote_get( $base . $rel, array( 'timeout' => 12, 'sslverify' => false ) );
        if ( is_wp_error( $ir ) || ! function_exists( 'imagecreatefromstring' ) ) { $rows[ $k ] = 'fetch/GD fail'; continue; }
        $im = @imagecreatefromstring( wp_remote_retrieve_body( $ir ) );
        if ( ! $im ) { $rows[ $k ] = 'decode fail'; continue; }
        $w = imagesx( $im ); $h = imagesy( $im );
        $minX = $w; $minY = $h; $maxX = 0; $maxY = 0; $step = 3;
        for ( $y = 0; $y < $h; $y += $step ) {
            for ( $x = 0; $x < $w; $x += $step ) {
                $rgb = imagecolorat( $im, $x, $y );
                if ( ( ( $rgb >> 16 ) & 0xFF ) < 240 || ( ( $rgb >> 8 ) & 0xFF ) < 240 || ( $rgb & 0xFF ) < 240 ) {
                    if ( $x < $minX ) $minX = $x; if ( $x > $maxX ) $maxX = $x;
                    if ( $y < $minY ) $minY = $y; if ( $y > $maxY ) $maxY = $y;
                }
            }
        }
        imagedestroy( $im );
        if ( $maxX < $minX ) { $rows[ $k ] = 'blank'; continue; }
        $cw = round( 100 * ( $maxX - $minX ) / $w );
        $ch = round( 100 * ( $maxY - $minY ) / $h );
        $cy = round( 100 * ( ( $minY + $maxY ) / 2 ) / $h ); // content vertical center
        if ( $ch > $maxCH ) $maxCH = $ch;
        if ( $cw > $maxCW ) $maxCW = $cw;
        $rows[ $k ] = array( 'canvas' => $w . 'x' . $h, 'cw' => $cw, 'ch' => $ch, 'cy' => $cy,
            'top' => round( 100 * $minY / $h ), 'bottom' => round( 100 * ( $h - $maxY ) / $h ) );
    }
    $out['logos'] = $rows;
    $out['max_content_h_pct'] = $maxCH;
    $out['max_content_w_pct'] = $maxCW;

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
