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
 * The numbers here were verified against the calculators on 2026-08-11:
 * saddle page select 8–64 (docs/PRICING_MATRIX.md sweep), perfect bound page
 * field clamps at 300, brochure's largest size is 11×25.5, coupon has
 * collation but no numbering. When a calculator's limits change, update the
 * matching number HERE in the same commit — this table is the one the
 * customer-facing cards quote.
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
    return apply_filters( 'pps_calc_facts', $facts );
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
