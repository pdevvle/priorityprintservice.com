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
.ast-separate-container .ast-article-post,.ast-separate-container .ast-article-single,.ast-separate-container .ast-archive-description{background:#fff}
.ast-separate-container .site-content>.ast-container{background:transparent}
.ast-archive-description{padding:28px 32px 8px}
.term-description{max-width:880px}
.term-description h2{font-size:21px;font-weight:700;color:#1a202c;margin:32px 0 10px;padding-bottom:8px;border-bottom:2px solid #667eea}
.term-description h2:first-of-type{margin-top:20px}
.term-description>p{font-size:15px;line-height:1.75;color:#4a5568;margin-bottom:14px}
.term-description>p:first-child{font-size:16px;color:#2d3748;line-height:1.7}
.term-description ul{list-style:none;padding:0;margin:14px 0}
.term-description ul li{padding:6px 0 6px 22px;position:relative;font-size:15px;color:#4a5568;line-height:1.6}
.term-description ul li::before{content:"\25B8";position:absolute;left:0;color:#667eea;font-weight:700}
ul.products{padding:0 20px}
ul.products li.product{background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0;transition:box-shadow .2s,transform .15s}
ul.products li.product:hover{box-shadow:0 4px 14px rgba(0,0,0,.07);transform:translateY(-1px)}
ul.products li.product .button{background:#667eea;color:#fff;border-radius:6px;font-weight:600;font-size:13px;letter-spacing:.3px;padding:8px 18px;transition:background .15s}
ul.products li.product .button:hover{background:#5a67d8}
.pps-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;margin:16px 0 20px}
.pps-cat-card{border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;background:#fff;transition:box-shadow .2s,transform .15s}
.pps-cat-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.07);transform:translateY(-1px)}
.pps-cat-card-name{font-weight:600;font-size:14px;color:#1a202c;margin-bottom:6px}
.pps-cat-card-meta{display:flex;flex-wrap:wrap;gap:5px}
.pps-cat-badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:4px}
.pps-cat-badge--stock{background:#c6f6d5;color:#22543d}
.pps-cat-badge--factory{background:#fefcbf;color:#744210}
.pps-cat-badge--coat{background:#e9d8fd;color:#553c9a}
.pps-cat-callout{background:linear-gradient(135deg,#ebf4ff 0%,#f0e6ff 100%);border-radius:12px;padding:22px 26px;margin:22px 0;border-left:4px solid #667eea}
.pps-cat-callout-title{margin:0 0 6px;font-size:17px;font-weight:700;color:#2d3748}
.pps-cat-callout-body{margin:0;color:#4a5568;font-size:15px;line-height:1.65}
.pps-cat-callout .pps-cat-num{font-size:30px;font-weight:800;color:#667eea;vertical-align:baseline}
.pps-cat-chips{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 20px}
.pps-cat-chip{background:#f7fafc;border:1px solid #e2e8f0;border-radius:22px;padding:7px 16px;font-size:13px;color:#2d3748;transition:background .15s,border-color .15s}
.pps-cat-chip:hover{background:#edf2f7;border-color:#cbd5e0}
.pps-cat-chip b{color:#667eea;margin-right:4px}
.pps-cat-addon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:14px 0 20px}
.pps-cat-addon{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f7fafc;border-radius:10px;border:1px solid #e2e8f0;font-size:13px;color:#2d3748;transition:background .15s}
.pps-cat-addon:hover{background:#edf2f7}
.pps-cat-addon-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.pps-cat-addon-dot--vivid{background:#ec4899}
.pps-cat-addon-dot--coating{background:#8b5cf6}
.pps-cat-addon-dot--bundling{background:#3b82f6}
.pps-cat-addon-dot--rc{background:#10b981}
.pps-cat-addon-dot--two_staple{background:#f59e0b}
.pps-cat-addon-dot--perforation{background:#ef4444}
.pps-cat-addon-dot--outfold{background:#6366f1}
@media(max-width:600px){
.pps-cat-grid{grid-template-columns:1fr}
.pps-cat-addon-grid{grid-template-columns:1fr 1fr}
.pps-cat-callout{padding:16px 18px}
.pps-cat-callout .pps-cat-num{font-size:26px}
}
@media(max-width:400px){
.pps-cat-addon-grid{grid-template-columns:1fr}
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
