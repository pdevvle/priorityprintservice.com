<?php
/**
 * Dynamic shortcodes for WooCommerce category descriptions.
 * Reads live data from pps_calc_config (via pps_get_config()) so
 * category pages stay in sync with the Central Config admin.
 *
 * Shortcodes:
 *   [pps_cat_papers type="text|cover|all" factory="yes|no"]
 *   [pps_cat_turnaround]
 *   [pps_cat_coatings]
 *   [pps_cat_addons calc="brochure|saddle|perfect-bound|coupon"]
 *
 * Side-loaded by pps-html-deploy.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'term_description', 'do_shortcode', 11 );

add_filter( 'woocommerce_product_add_to_cart_text', function() {
    return 'Customize Order';
}, 10, 2 );

add_action( 'wp_head', function() {
    if ( ! is_product_category() && ! is_product_taxonomy() ) return;
    ?>
<style id="pps-cat-shortcode-css">
/* ── Page-level resets ── */
.ast-separate-container .ast-article-post,
.ast-separate-container .ast-article-single,
.ast-separate-container .ast-archive-description{background:#fff;box-shadow:none;border:0}
.ast-separate-container .site-content>.ast-container{background:#f8fafc}
.ast-archive-description{padding:0!important;overflow:hidden;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:28px}
.term-description{max-width:100%}

/* ── Hero banner ── */
.pps-cat-hero{position:relative;padding:44px 40px 38px;color:#fff;background-size:cover;background-position:center;background-color:#0f172a}
.pps-cat-hero::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(15,23,42,.91) 0%,rgba(30,41,59,.88) 60%,rgba(51,65,85,.86) 100%);z-index:0}
.pps-cat-hero>*{position:relative;z-index:1}
.pps-cat-hero-title{font-size:32px;font-weight:800;letter-spacing:-.3px;margin:0 0 10px;line-height:1.2}
.pps-cat-hero-sub{font-size:16px;color:#cbd5e1;line-height:1.6;margin:0;max-width:640px}

/* ── USP bar ── */
.pps-cat-usps{display:grid;grid-template-columns:repeat(4,1fr);background:#f1f5f9;border-bottom:1px solid #e2e8f0}
.pps-cat-usp{padding:14px 16px;font-size:13px;font-weight:600;color:#334155;display:flex;align-items:center;gap:8px;justify-content:center;text-align:center;border-right:1px solid #e2e8f0}
.pps-cat-usp:last-child{border-right:0}
.pps-cat-usp-icon{width:22px;height:22px;border-radius:50%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}

/* ── Content body ── */
.pps-cat-body{padding:32px 40px 24px}
.term-description h2{font-size:20px;font-weight:700;color:#0f172a;margin:32px 0 12px;padding:0 0 0 14px;border-left:3px solid #4f46e5;border-bottom:none;line-height:1.3}
.term-description h2:first-of-type{margin-top:8px}
.term-description>p,.pps-cat-body>p{font-size:15px;line-height:1.75;color:#475569;margin-bottom:16px}
.term-description ul{list-style:none;padding:0;margin:14px 0}
.term-description ul li{padding:6px 0 6px 22px;position:relative;font-size:15px;color:#475569;line-height:1.6}
.term-description ul li::before{content:"\25B8";position:absolute;left:0;color:#4f46e5;font-weight:700}

/* ── Paper cards ── */
.pps-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:16px 0 24px}
.pps-cat-card{border:1px solid #e2e8f0;border-left:3px solid #4f46e5;border-radius:8px;padding:14px 16px;background:#fff;transition:box-shadow .2s,transform .15s}
.pps-cat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}
.pps-cat-card-name{font-weight:600;font-size:14px;color:#0f172a;margin-bottom:6px}
.pps-cat-card-meta{display:flex;flex-wrap:wrap;gap:5px}
.pps-cat-badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:4px}
.pps-cat-badge--stock{background:#dcfce7;color:#166534}
.pps-cat-badge--factory{background:#fef9c3;color:#854d0e}
.pps-cat-badge--coat{background:#ede9fe;color:#5b21b6}

/* ── Turnaround callout ── */
.pps-cat-callout{background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%);border-radius:10px;padding:24px 28px;margin:24px 0;border:1px solid #c7d2fe;position:relative;overflow:hidden}
.pps-cat-callout::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:#4f46e5}
.pps-cat-callout-title{margin:0 0 8px;font-size:17px;font-weight:700;color:#1e293b}
.pps-cat-callout-body{margin:0;color:#475569;font-size:15px;line-height:1.65}
.pps-cat-callout .pps-cat-num{font-size:32px;font-weight:800;color:#4f46e5;vertical-align:baseline}

/* ── Coating chips ── */
.pps-cat-chips{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 24px}
.pps-cat-chip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:22px;padding:8px 18px;font-size:13px;color:#334155;transition:all .15s}
.pps-cat-chip:hover{background:#eef2ff;border-color:#c7d2fe}
.pps-cat-chip b{color:#4f46e5;margin-right:4px}

/* ── Add-on grid ── */
.pps-cat-addon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:14px 0 24px}
.pps-cat-addon{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;font-size:13px;font-weight:500;color:#334155;transition:all .15s}
.pps-cat-addon:hover{background:#eef2ff;border-color:#c7d2fe}
.pps-cat-addon-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.pps-cat-addon-dot--vivid{background:#ec4899}
.pps-cat-addon-dot--coating{background:#8b5cf6}
.pps-cat-addon-dot--bundling{background:#3b82f6}
.pps-cat-addon-dot--rc{background:#10b981}
.pps-cat-addon-dot--two_staple{background:#f59e0b}
.pps-cat-addon-dot--perforation{background:#ef4444}
.pps-cat-addon-dot--outfold{background:#6366f1}

/* ── Product grid ── */
ul.products{padding:0 4px!important}
ul.products li.product{background:#fff;border-radius:10px;padding:18px;border:1px solid #e2e8f0;transition:box-shadow .2s,transform .15s}
ul.products li.product:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px)}
ul.products li.product .woocommerce-loop-product__title{font-size:15px!important;font-weight:600;color:#0f172a}
ul.products li.product .price{font-size:14px;font-weight:700;color:#4f46e5}
ul.products li.product .button{background:#4f46e5;color:#fff;border-radius:6px;font-weight:600;font-size:13px;letter-spacing:.3px;padding:10px 20px;transition:background .15s;border:0;text-transform:none}
ul.products li.product .button:hover{background:#4338ca}
ul.products li.product img{border-radius:8px}

/* ── Responsive ── */
@media(max-width:768px){
.pps-cat-hero{padding:32px 24px 28px}
.pps-cat-hero-title{font-size:26px}
.pps-cat-usps{grid-template-columns:1fr 1fr}
.pps-cat-usp{border-bottom:1px solid #e2e8f0}
.pps-cat-usp:nth-child(2){border-right:0}
.pps-cat-body{padding:24px 24px 16px}
}
@media(max-width:480px){
.pps-cat-hero{padding:28px 20px 24px}
.pps-cat-hero-title{font-size:22px}
.pps-cat-hero-sub{font-size:14px}
.pps-cat-usps{grid-template-columns:1fr}
.pps-cat-usp{border-right:0;justify-content:flex-start}
.pps-cat-body{padding:20px 16px 12px}
.pps-cat-grid{grid-template-columns:1fr}
.pps-cat-addon-grid{grid-template-columns:1fr}
.pps-cat-callout{padding:18px 20px}
.pps-cat-callout .pps-cat-num{font-size:26px}
}
</style>
    <?php
} );

// ── [pps_cat_papers type="text|cover|all" factory="yes|no"] ──

add_shortcode( 'pps_cat_papers', function( $atts ) {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $a   = shortcode_atts( array( 'type' => 'all', 'factory' => 'yes' ), $atts );
    $cfg = pps_get_config();

    $papers = array();
    if ( $a['type'] === 'text' || $a['type'] === 'all' ) {
        $papers = array_merge( $papers, isset( $cfg['papers_nc'] ) ? $cfg['papers_nc'] : array() );
    }
    if ( $a['type'] === 'cover' || $a['type'] === 'all' ) {
        $papers = array_merge( $papers, isset( $cfg['papers_cs'] ) ? $cfg['papers_cs'] : array() );
    }
    if ( $a['factory'] === 'no' ) {
        $papers = array_filter( $papers, function( $p ) { return empty( $p['factory'] ); } );
    }
    if ( empty( $papers ) ) return '';

    $out = '<div class="pps-cat-grid">';
    foreach ( $papers as $p ) {
        $label = esc_html( $p['label'] );
        $stock = empty( $p['factory'] )
            ? '<span class="pps-cat-badge pps-cat-badge--stock">In Stock</span>'
            : '<span class="pps-cat-badge pps-cat-badge--factory">Factory Order</span>';
        $coat  = ! empty( $p['coatable'] )
            ? '<span class="pps-cat-badge pps-cat-badge--coat">UV Coatable</span>'
            : '';
        $out .= '<div class="pps-cat-card">'
              . '<div class="pps-cat-card-name">' . $label . '</div>'
              . '<div class="pps-cat-card-meta">' . $stock . $coat . '</div>'
              . '</div>';
    }
    $out .= '</div>';
    return $out;
} );

// ── [pps_cat_turnaround] ──

add_shortcode( 'pps_cat_turnaround', function() {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $cfg  = pps_get_config();
    $days = isset( $cfg['pcf']['minimum_turnaround_days'] ) ? intval( $cfg['pcf']['minimum_turnaround_days'] ) : 3;

    return '<div class="pps-cat-callout">'
         . '<div class="pps-cat-callout-title">Rush &amp; Standard Turnaround</div>'
         . '<p class="pps-cat-callout-body">'
         . 'Production starts in as few as <span class="pps-cat-num">' . $days . '</span> business days. '
         . 'Our pricing calculator shows real-time delivery dates based on your ZIP code &mdash; '
         . 'you&#8217;ll see exactly when your order arrives before you place it.'
         . '</p></div>';
} );

// ── [pps_cat_coatings] ──

add_shortcode( 'pps_cat_coatings', function() {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $cfg      = pps_get_config();
    $coatings = isset( $cfg['coatings'] ) ? $cfg['coatings'] : array();
    $coatings = array_filter( $coatings, function( $c ) { return ! empty( $c['val'] ); } );
    if ( empty( $coatings ) ) return '';

    $out = '<div class="pps-cat-chips">';
    foreach ( $coatings as $c ) {
        $out .= '<span class="pps-cat-chip"><b>&#10022;</b> ' . esc_html( $c['label'] ) . '</span>';
    }
    $out .= '</div>';
    return $out;
} );

// ── [pps_cat_addons calc="brochure"] ──

add_shortcode( 'pps_cat_addons', function( $atts ) {
    if ( ! function_exists( 'pps_get_addons_visibility' ) ) return '';
    $a    = shortcode_atts( array( 'calc' => '' ), $atts );
    $calc = $a['calc'];
    if ( ! $calc ) return '';

    $labels = function_exists( 'pps_addon_labels' ) ? pps_addon_labels() : array();
    $vis    = pps_get_addons_visibility();
    $items  = array();
    foreach ( $vis as $addon => $calcs ) {
        if ( isset( $calcs[ $calc ] ) && $calcs[ $calc ] && isset( $labels[ $addon ] ) ) {
            $items[] = array( 'slug' => $addon, 'label' => $labels[ $addon ] );
        }
    }
    if ( empty( $items ) ) return '';

    $out = '<div class="pps-cat-addon-grid">';
    foreach ( $items as $item ) {
        $out .= '<div class="pps-cat-addon">'
              . '<span class="pps-cat-addon-dot pps-cat-addon-dot--' . esc_attr( $item['slug'] ) . '"></span>'
              . esc_html( $item['label'] )
              . '</div>';
    }
    $out .= '</div>';
    return $out;
} );
