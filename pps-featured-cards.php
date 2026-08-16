<?php
/**
 * PPS Featured Cards — single-source product-family facts + homepage card grid.
 *
 * The homepage "Featured Products" grid was hand-written HTML, and its claims
 * drifted from what the calculators actually sell (a "8-200 pages" pill against
 * a page select that tops out at 64; a "Numbered" pill for a feature the coupon
 * calculator does not have). This file makes the marketing surfaces read from
 * one canonical facts table instead.
 *
 * Shortcodes:
 *   [pps_featured_cards]                       — the full homepage card grid
 *   [pps_calc_fact calc="saddle" key="page_max"] — one fact inline, for prose
 *
 * Numbers are no longer hand-maintained. pps_calc_live_facts() reads them out
 * of the deployed calculators themselves — the same constants the calculator
 * uses to clamp its own inputs — so a pill cannot drift from what the product
 * actually sells. Hand-maintenance is what put "40–300 pages" on the perfect
 * bound card while the calculator accepted 4–350.
 *
 * The scalars in pps_calc_facts() below are now only a fallback, used when a
 * calculator has not been deployed yet or its constants cannot be parsed.
 * Nothing breaks if parsing fails; the card just quotes the last known values.
 *
 * The contract, per family — a calculator constant of the form
 * `_CFG.<key> || <literal>`, which is both what the calculator reads at runtime
 * (so an admin override in pps_calc_config wins) and what this file parses:
 *   saddle / perfect  page_counts, page_limits  → page_min, page_max
 *   brochure          fold_types, size table    → fold_types, max_edge_in
 * Change a limit in the calculator and the card follows on the next deploy.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Canonical customer-facing facts per calculator family.
 * Scalar values can be referenced as {tokens} inside desc/pills.
 */
function pps_calc_facts() {
    $facts = array(
        'saddle' => array(
            'title'    => 'Saddle Stitch Booklets',
            'url'      => '/product-category/booklets/',
            'image'    => '/wp-content/uploads/2018/06/open-saddlestitch-booklet-mockup.png',
            'alt'      => 'An open saddle stitch booklet showing stapled binding along the spine',
            'page_min' => 8,
            'page_max' => 64,
            'desc'     => 'Stapled on the spine. The go-to for programs, catalogs, zines, and manuals up to {page_max} pages.',
            'pills'    => array( '{page_min}–{page_max} pages', 'Staple bound', 'Multiple sizes' ),
        ),
        'perfect' => array(
            'title'    => 'Perfect Bound Booklets',
            'url'      => '/product-category/booklets/perfect-bound/',
            'image'    => '/wp-content/uploads/2019/05/perfect-bound-booklet-min.png',
            'alt'      => 'Perfect bound booklet with flat glued spine',
            'page_min' => 40,
            'page_max' => 300,
            'desc'     => 'Glued spine with a flat, professional edge. Ideal for thick catalogs, reports, journals, and books.',
            'pills'    => array( '{page_min}–{page_max} pages', 'Glued spine', 'Square back' ),
        ),
        'brochure' => array(
            'title'       => 'Brochures & Flyers',
            'url'         => '/product-category/brochures/',
            'image'       => '/wp-content/uploads/2020/05/mini-trifold-brochure.png',
            'alt'         => 'Custom trifold brochure printing',
            'fold_types'  => 9,
            'max_edge_in' => 25.5,
            'desc'        => 'Single-sheet printing with {fold_types} fold options. From flat flyers to gate folds — any size up to {max_edge_in} inches.',
            'pills'       => array( '{fold_types} fold types', 'Flat available', 'Custom sizes' ),
        ),
        'coupon' => array(
            'title' => 'Coupon Books',
            'url'   => '/product-category/booklets/coupon-booklets/',
            'image' => '/wp-content/uploads/2023/08/custom-coupon-book-min.png',
            'alt'   => 'Custom printed coupon book with perforated pages',
            'desc'  => 'Perforated tear-out pages, collated in your page order. Pad-bound or wraparound cover styles.',
            'pills' => array( 'Perforated', 'Collated', 'Pad or book' ),
        ),
    );
    foreach ( pps_calc_live_facts() as $family => $live ) {
        if ( isset( $facts[ $family ] ) ) {
            $facts[ $family ] = array_merge( $facts[ $family ], $live );
        }
    }

    return apply_filters( 'pps_calc_facts', $facts );
}

/** Deployed calculator file per family, or '' when it has never been deployed. */
function pps_calc_family_file( $family ) {
    $map = array(
        'saddle'   => 'calc-preview-test.html',
        'perfect'  => 'calc-perfect-bound.html',
        'brochure' => 'calc-brochure.html',
        'coupon'   => 'calc-coupon-book.html',
    );
    if ( ! isset( $map[ $family ] ) || ! function_exists( 'pps_upload_dir' ) ) return '';
    $path = trailingslashit( pps_upload_dir() ) . $map[ $family ];
    return is_readable( $path ) ? $path : '';
}

/**
 * Pull each family's customer-facing limits out of the deployed calculator.
 *
 * Cached per file identity (mtime + size), so a calculator deploy invalidates
 * it by construction and a normal page view never re-parses 500KB.
 */
function pps_calc_live_facts() {
    $out = array();

    foreach ( array( 'saddle', 'perfect', 'brochure' ) as $family ) {
        $path = pps_calc_family_file( $family );
        if ( ! $path ) continue;

        $stat = @stat( $path );
        $key  = 'pps_facts_' . $family . '_' . ( $stat ? $stat['mtime'] . '_' . $stat['size'] : '0' );

        $cached = get_transient( $key );
        if ( is_array( $cached ) ) { $out[ $family ] = $cached; continue; }

        $src = @file_get_contents( $path );
        if ( false === $src || '' === $src ) continue;

        $facts = pps_calc_parse_facts( $family, $src );
        unset( $src );
        if ( empty( $facts ) ) continue;

        set_transient( $key, $facts, MONTH_IN_SECONDS );
        $out[ $family ] = $facts;
    }

    // An admin override in pps_calc_config is what the customer actually gets,
    // so it outranks the file's own default.
    if ( function_exists( 'pps_get_public_config' ) ) {
        $cfg = pps_get_public_config();
        $ov  = ( isset( $cfg['calc'] ) && is_array( $cfg['calc'] ) ) ? $cfg['calc'] : array();

        if ( ! empty( $ov['page_counts'] ) && is_array( $ov['page_counts'] ) ) {
            $n = array_values( array_filter( array_map( 'intval', $ov['page_counts'] ) ) );
            if ( $n ) {
                foreach ( array( 'saddle', 'perfect' ) as $f ) {
                    $out[ $f ]['page_min'] = min( $n );
                    $out[ $f ]['page_max'] = max( $n );
                }
            }
        }
        if ( isset( $ov['page_limits']['min'], $ov['page_limits']['max'] ) ) {
            $out['perfect']['page_min'] = (int) $ov['page_limits']['min'];
            $out['perfect']['page_max'] = (int) $ov['page_limits']['max'];
        }
        if ( ! empty( $ov['fold_types'] ) && is_array( $ov['fold_types'] ) ) {
            $folds = 0;
            foreach ( $ov['fold_types'] as $ft ) {
                if ( isset( $ft['val'] ) && 'flat' === $ft['val'] ) continue;
                $folds++;
            }
            if ( $folds ) $out['brochure']['fold_types'] = $folds;
        }
    }

    return $out;
}

/**
 * Parse one calculator's constants. Every branch is optional: a miss leaves the
 * fallback scalar in place rather than emitting a wrong or empty pill.
 */
function pps_calc_parse_facts( $family, $src ) {
    $facts = array();

    if ( 'saddle' === $family || 'perfect' === $family ) {
        // `_CFG.page_limits || { min: 4, max: 350 }` — an explicit clamp.
        if ( preg_match( '/page_limits\s*\|\|\s*\{\s*min\s*:\s*(\d+)\s*,\s*max\s*:\s*(\d+)/', $src, $m ) ) {
            $facts['page_min'] = (int) $m[1];
            $facts['page_max'] = (int) $m[2];
        // `_CFG.page_counts || [8,12,...,64]` — an explicit list of choices.
        } elseif ( preg_match( '/page_counts\s*\|\|\s*\[([0-9,\s]+)\]/', $src, $m ) ) {
            $n = array_values( array_filter( array_map( 'intval', explode( ',', $m[1] ) ) ) );
            if ( $n ) {
                $facts['page_min'] = min( $n );
                $facts['page_max'] = max( $n );
            }
        }
    }

    if ( 'brochure' === $family ) {
        // Count real folds. "flat" is the absence of a fold and must not inflate
        // the claim; every other entry counts, including trifold_xbifold, which
        // is only offered at some sizes but is still a fold we sell.
        if ( preg_match( '/fold_types\s*\|\|\s*\[(.*?)\n\s*\];/s', $src, $m )
          || preg_match( '/fold_types\s*\|\|\s*\[(.*?)\];/s', $src, $m ) ) {
            if ( preg_match_all( '/val\s*:\s*"([a-z0-9_]+)"/i', $m[1], $v ) ) {
                $folds = array_diff( $v[1], array( 'flat' ) );
                if ( $folds ) $facts['fold_types'] = count( $folds );
            }
        }
        // Largest orderable edge across the size table.
        if ( preg_match_all( '/long\s*:\s*([0-9]+(?:\.[0-9]+)?)/', $src, $m ) ) {
            $edges = array_map( 'floatval', $m[1] );
            if ( $edges ) $facts['max_edge_in'] = (float) max( $edges );
        }
    }

    return $facts;
}

/** Replace {key} tokens with the family's scalar facts. */
function pps_calc_fact_interpolate( $text, $family ) {
    return preg_replace_callback( '/\{([a-z_]+)\}/', function( $m ) use ( $family ) {
        $v = $family[ $m[1] ] ?? null;
        return is_scalar( $v ) ? (string) $v : $m[0];
    }, $text );
}

add_shortcode( 'pps_calc_fact', function( $atts ) {
    $atts  = shortcode_atts( array( 'calc' => '', 'key' => '' ), $atts );
    $facts = pps_calc_facts();
    $fam   = $facts[ $atts['calc'] ] ?? null;
    if ( ! $fam || ! isset( $fam[ $atts['key'] ] ) || ! is_scalar( $fam[ $atts['key'] ] ) ) return '';
    return esc_html( (string) $fam[ $atts['key'] ] );
} );

add_shortcode( 'pps_featured_cards', function() {
    $facts = pps_calc_facts();
    if ( empty( $facts ) ) return '';

    // Markup and classes mirror the hand-written grid this replaces, so the
    // swap is visually invisible. Styles ride along; the section is
    // self-contained wherever the shortcode lands.
    $css = '.ppw{max-width:1200px;margin:0 auto;padding:60px 24px 72px}'
         . '.ppw-hdr{display:flex;align-items:baseline;gap:16px;margin-bottom:36px;padding-bottom:18px;border-bottom:1px solid rgba(10,10,10,.08)}'
         . '.ppw-title{font-size:clamp(20px,2.5vw,26px);font-weight:600;letter-spacing:-.015em;margin:0;color:#0a0a0a}'
         . '.ppw-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:24px}'
         . '@media(max-width:720px){.ppw-grid{grid-template-columns:1fr}}'
         . '.ppw-card{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #e6e4df;border-radius:8px;overflow:hidden;transition:transform .28s cubic-bezier(.2,.8,.2,1),box-shadow .28s cubic-bezier(.2,.8,.2,1);cursor:pointer;text-decoration:none;color:inherit}'
         . '.ppw-card:hover{transform:translateY(-4px);box-shadow:0 16px 48px -12px rgba(10,10,10,.15);border-color:#c8c6c2}'
         . '.ppw-img{position:relative;aspect-ratio:16/10;overflow:hidden;background:#e6e4df}'
         . '.ppw-img img{width:100%;height:100%;object-fit:cover;transition:transform .52s cubic-bezier(.2,.8,.2,1)}'
         . '.ppw-card:hover .ppw-img img{transform:scale(1.04)}'
         . '.ppw-body{padding:24px;display:flex;flex-direction:column;gap:8px;flex:1}'
         . '.ppw-name{margin:0;font-size:20px;font-weight:700;letter-spacing:-.015em;line-height:1.2}'
         . '.ppw-desc{margin:0;font-size:14px;line-height:1.5;color:#5a5a5a}'
         . '.ppw-tags{margin:6px 0 0;display:flex;flex-wrap:wrap;gap:8px}'
         . '.ppw-tag{display:inline-block;padding:3px 10px;border-radius:999px;background:#f6f4ef;border:1px solid #e6e4df;font-size:12px;font-weight:500;color:#3a3a3a}'
         . '.ppw-cta{margin-top:auto;padding-top:16px;display:flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:#0a0a0a}'
         . '.ppw-cta svg{width:14px;height:14px;transition:transform .16s cubic-bezier(.2,.8,.2,1)}'
         . '.ppw-card:hover .ppw-cta svg{transform:translateX(4px)}';

    $arrow = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4"/></svg>';

    $out = '<style>' . $css . '</style>';
    $out .= '<section class="ppw" id="products"><div class="ppw-hdr"><h2 class="ppw-title">Featured Products</h2></div><div class="ppw-grid">';

    foreach ( $facts as $fam ) {
        if ( empty( $fam['title'] ) || empty( $fam['url'] ) ) continue;
        $out .= '<a class="ppw-card" href="' . esc_url( $fam['url'] ) . '">';
        if ( ! empty( $fam['image'] ) ) {
            $out .= '<div class="ppw-img"><img src="' . esc_url( $fam['image'] ) . '" alt="' . esc_attr( $fam['alt'] ?? '' ) . '" loading="lazy"></div>';
        }
        $out .= '<div class="ppw-body"><h3 class="ppw-name">' . esc_html( $fam['title'] ) . '</h3>';
        if ( ! empty( $fam['desc'] ) ) {
            $out .= '<p class="ppw-desc">' . esc_html( pps_calc_fact_interpolate( $fam['desc'], $fam ) ) . '</p>';
        }
        if ( ! empty( $fam['pills'] ) && is_array( $fam['pills'] ) ) {
            $out .= '<div class="ppw-tags">';
            foreach ( $fam['pills'] as $pill ) {
                $out .= '<span class="ppw-tag">' . esc_html( pps_calc_fact_interpolate( $pill, $fam ) ) . '</span>';
            }
            $out .= '</div>';
        }
        $out .= '<span class="ppw-cta">Get started ' . $arrow . '</span></div></a>';
    }

    $out .= '</div></section>';
    return $out;
} );
