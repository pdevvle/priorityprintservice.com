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
.ast-separate-container .site-content>.ast-container{background:#f8fafc;overflow-x:hidden}
.ast-archive-description{padding:0!important;overflow:visible;border-radius:0;border:0;margin-bottom:28px}
.term-description{max-width:100%}

/* ── Hero banner ── */
.pps-cat-hero{position:relative;padding:80px 6vw 72px;color:#fff;background-size:cover;background-position:center;background-color:#0f172a;margin-left:calc(-50vw + 50%);margin-right:calc(-50vw + 50%)}
.pps-cat-hero::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(15,23,42,.91) 0%,rgba(30,41,59,.88) 60%,rgba(51,65,85,.86) 100%);z-index:0}
.pps-cat-hero>*{position:relative;z-index:1}
.pps-cat-hero-title{font-size:36px;font-weight:800;letter-spacing:-.3px;margin:0 0 14px;line-height:1.2}
.pps-cat-hero-sub{font-size:17px;color:#cbd5e1;line-height:1.6;margin:0;max-width:640px}

/* ── USP bar ── */
.pps-cat-usps{display:grid;grid-template-columns:repeat(4,1fr);background:#f1f5f9;border-bottom:1px solid #e2e8f0;margin-left:calc(-50vw + 50%);margin-right:calc(-50vw + 50%)}
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
.pps-cat-card{position:relative;border:1px solid #e2e8f0;border-left:3px solid #4f46e5;border-radius:8px;padding:14px 16px;background:#fff;transition:box-shadow .2s,transform .15s}
.pps-cat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}
.pps-cat-card--link{text-decoration:none;color:inherit;cursor:pointer}
.pps-cat-card--link:hover .pps-cat-card-name{color:#4f46e5}
.pps-cat-card-arrow{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:20px;color:#cbd5e1;font-weight:300;transition:color .15s,transform .15s}
.pps-cat-card--link:hover .pps-cat-card-arrow{color:#4f46e5;transform:translateY(-50%) translateX(3px)}
.pps-cat-card-name{font-weight:600;font-size:14px;color:#0f172a;margin-bottom:6px}
.pps-cat-card-meta{display:flex;flex-wrap:wrap;gap:5px}
.pps-cat-badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:4px}
.pps-cat-badge--stock{background:#dcfce7;color:#166534}
.pps-cat-badge--factory{background:#fef9c3;color:#854d0e}
.pps-cat-badge--coat{background:#ede9fe;color:#5b21b6}
.pps-cat-badge--cs{background:#dbeafe;color:#1e40af}

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
.pps-cat-hero{padding:56px 24px 48px}
.pps-cat-hero-title{font-size:28px}
.pps-cat-usps{grid-template-columns:1fr 1fr}
.pps-cat-usp{border-bottom:1px solid #e2e8f0}
.pps-cat-usp:nth-child(2){border-right:0}
.pps-cat-body{padding:24px 24px 16px}
}
@media(max-width:480px){
.pps-cat-hero{padding:44px 20px 36px}
.pps-cat-hero-title{font-size:24px}
.pps-cat-hero-sub{font-size:14px}
.pps-cat-usps{grid-template-columns:1fr}
.pps-cat-usp{border-right:0;justify-content:flex-start}
.pps-cat-body{padding:20px 16px 12px}
.pps-cat-grid{grid-template-columns:1fr}
.pps-cat-addon-grid{grid-template-columns:1fr}
.pps-cat-callout{padding:18px 20px}
.pps-cat-callout .pps-cat-num{font-size:26px}
}

/* ── Wizard ── */
.pps-wiz-step{display:none;margin-bottom:18px}
.pps-wiz-step.is-active{display:block;animation:ppsFadeIn .35s ease}
@keyframes ppsFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.pps-wiz-prompt{font-size:14px;font-weight:600;color:#1e293b;margin-bottom:10px;line-height:1.4}
.pps-wiz-prompt .pps-wiz-prev{color:#4f46e5;font-weight:700}
.pps-wiz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px}
.pps-wiz-opt{all:unset;position:relative;cursor:pointer;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;background:#fff;transition:border-color .15s,box-shadow .15s}
.pps-wiz-opt:hover{border-color:#a5b4fc;box-shadow:0 2px 8px rgba(79,70,229,.1)}
.pps-wiz-opt.is-selected{border-color:#4f46e5;box-shadow:0 0 0 2px rgba(79,70,229,.2);background:#f5f3ff}
.pps-wiz-opt-name{font-weight:600;font-size:12.5px;color:#0f172a;margin-bottom:2px}
.pps-wiz-opt.is-selected .pps-wiz-opt-name{color:#4f46e5}
.pps-wiz-opt-desc{font-size:11px;color:#64748b;line-height:1.4;margin-bottom:3px}
.pps-wiz-opt-badges{display:flex;flex-wrap:wrap;gap:3px}
.pps-wiz-done{background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%);border:1px solid #c7d2fe;border-radius:8px;padding:18px 24px;text-align:center}
.pps-wiz-summary{font-size:14px;color:#334155;margin-bottom:12px;line-height:1.5}
.pps-wiz-summary strong{color:#1e293b}
.pps-wiz-cta{display:inline-block;background:#4f46e5;color:#fff;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none;transition:background .15s}
.pps-wiz-cta:hover{background:#4338ca;color:#fff}
.pps-wiz-reset{display:inline-block;margin-top:8px;font-size:12px;color:#64748b;cursor:pointer;text-decoration:underline;border:0;background:0}
.pps-wiz-reset:hover{color:#4f46e5}
@media(max-width:480px){.pps-wiz-grid{grid-template-columns:1fr 1fr}}
.pps-wiz-opt.is-toggled{border-color:#4f46e5;background:#f5f3ff}
.pps-wiz-opt.is-toggled .pps-wiz-opt-name{color:#4f46e5}
.pps-wiz-clear{font-size:11px;color:#94a3b8;cursor:pointer;margin-left:8px;font-weight:400;text-decoration:underline}
.pps-wiz-clear:hover{color:#dc2626}
.pps-wiz-actions{margin-top:14px;background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%);border:1px solid #c7d2fe;border-radius:8px;padding:16px 20px;display:none;text-align:center}
.pps-wiz-actions.is-visible{display:block;animation:ppsFadeIn .35s ease}
.pps-wiz-actions-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:8px}
.pps-wiz-act-pricing{display:inline-block;background:#4f46e5;color:#fff;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none;transition:background .15s}
.pps-wiz-act-pricing:hover{background:#4338ca;color:#fff}
.pps-wiz-act-quote{background:none;border:1px solid #c7d2fe;color:#4f46e5;font-weight:600;font-size:13px;padding:10px 24px;border-radius:6px;cursor:pointer;transition:all .15s}
.pps-wiz-act-quote:hover{background:#eef2ff}
.pps-wiz-email-form{margin-top:12px;display:none;text-align:left;max-width:400px;margin-left:auto;margin-right:auto}
.pps-wiz-phone-hint{font-size:11px;color:#94a3b8;margin:-4px 0 8px 2px;line-height:1.4}
.pps-wiz-cb{display:flex;align-items:center;gap:6px;font-size:13px;color:#334155;cursor:pointer;margin-bottom:8px;font-weight:500}
.pps-wiz-cb input[type="checkbox"]{width:16px;height:16px;accent-color:#4f46e5;cursor:pointer}
.pps-wiz-input{display:block;width:100%;padding:10px 12px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box}
.pps-wiz-input:focus{outline:none;border-color:#a5b4fc;box-shadow:0 0 0 2px rgba(79,70,229,.15)}
textarea.pps-wiz-input{min-height:70px;resize:vertical}
.pps-wiz-send{background:#4f46e5;color:#fff;font-weight:600;font-size:13px;padding:10px 24px;border-radius:6px;border:0;cursor:pointer;transition:background .15s;margin-top:4px}
.pps-wiz-send:hover{background:#4338ca}
.pps-wiz-email-status{margin-top:8px;font-size:13px;font-weight:500}
.pps-wiz-email-status.is-ok{color:#166534}
.pps-wiz-email-status.is-err{color:#dc2626}
</style>
    <?php
} );

// ── URL-param → reorder bridge (carries wizard selections to the calculator) ──

add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
    ?>
    <script>
    (function(){
        var p=new URLSearchParams(window.location.search);
        if(!p.has("paper")&&!p.has("fold")&&!p.has("size"))return;
        var c=window.PPS_CONFIG;if(!c||c.reorder)return;
        var rc={};
        var pv=p.get("paper");
        if(pv){
            var label;
            if(c.calc){var all=(c.calc.papers_nc||[]).concat(c.calc.papers_cs||[]);var m=all.find(function(pp){return String(pp.val)===pv});if(m)label=m.label}
            if(!label){var map={"0.001":"70lb Uncoated Opaque Text","0.002":"80lb Matte Text","0.003":"100lb Gloss Text","2.002":"60lb Offset Smooth Opaque","2.003":"80lb Offset Smooth Opaque","2.004":"80lb Gloss Factory Coated","2.005":"100lb Matte Factory Coated","0.01":"80lb Opaque Uncoated","0.02":"80lb Matte Cardstock","0.03":"100lb Gloss Cardstock","1.01":"14pt Gloss C1S","1.02":"16pt Coated C2S"};label=map[pv]}
            if(label)rc.paper={label:label}
        }
        var fv=p.get("fold");if(fv)rc.foldType=fv;
        var sv=p.get("size");if(sv){rc.sizeMode="preset";rc.sizeLabel=sv.replace(/x/gi,"×")}
        var cv=p.get("coating");if(cv)rc.coating=cv;
        ["vivid","bundling","rc","two_staple","perforation","outfold"].forEach(function(k){if(p.get(k)==="1")rc[k]=true});
        if(Object.keys(rc).length)c.reorder=btoa(JSON.stringify(rc));
    })();
    </script>
    <?php
}, 1 );

// ── [pps_cat_papers type="text|cover|all" factory="yes|no"] ──

add_shortcode( 'pps_cat_papers', function( $atts ) {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $a   = shortcode_atts( array( 'type' => 'all', 'factory' => 'yes', 'link' => '' ), $atts );
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

    $base_link = trim( $a['link'] );

    $out = '<div class="pps-cat-grid">';
    foreach ( $papers as $p ) {
        $label = esc_html( $p['label'] );
        $stock = empty( $p['factory'] )
            ? '<span class="pps-cat-badge pps-cat-badge--stock">In Stock</span>'
            : '<span class="pps-cat-badge pps-cat-badge--factory">Factory Order</span>';
        $coat  = ! empty( $p['coatable'] )
            ? '<span class="pps-cat-badge pps-cat-badge--coat">UV Coatable</span>'
            : '';
        $inner = '<div class="pps-cat-card-name">' . $label . '</div>'
               . '<div class="pps-cat-card-meta">' . $stock . $coat . '</div>';

        if ( $base_link ) {
            $href = esc_url( add_query_arg( 'paper', $p['val'], $base_link ) );
            $out .= '<a href="' . $href . '" class="pps-cat-card pps-cat-card--link">'
                  . $inner
                  . '<span class="pps-cat-card-arrow">&#8250;</span>'
                  . '</a>';
        } else {
            $out .= '<div class="pps-cat-card">' . $inner . '</div>';
        }
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

// ── [pps_cat_wizard calc="brochure" link="/product/..."] ──

add_shortcode( 'pps_cat_wizard', function( $atts ) {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $a   = shortcode_atts( array( 'calc' => 'brochure', 'link' => '' ), $atts );
    $cfg = pps_get_config();
    $link = trim( $a['link'] );
    if ( ! $link ) return '';

    $id = 'pps-wiz-' . wp_unique_id();

    // ── Step data ──

    $nc = isset( $cfg['papers_nc'] ) ? $cfg['papers_nc'] : array();
    $cs = isset( $cfg['papers_cs'] ) ? $cfg['papers_cs'] : array();
    foreach ( $cs as &$_p ) { $_p['_cs'] = true; }
    unset( $_p );
    $papers = array_merge( $nc, $cs );

    $paper_hints = array(
        '70lb Uncoated Opaque Text'  => 'Lightweight, natural feel — inserts & newsletters',
        '80lb Matte Text'            => 'Smooth matte — versatile for brochures & catalogs',
        '100lb Gloss Text'           => 'Glossy, vibrant — premium marketing materials',
        '60lb Offset Smooth Opaque'  => 'Economy weight — budget-friendly for large runs',
        '80lb Offset Smooth Opaque'  => 'Mid-weight offset — solid quality at great value',
        '80lb Gloss Factory Coated'  => 'Pre-coated gloss — vivid colors out of the box',
        '100lb Matte Factory Coated' => 'Pre-coated matte — rich feel, great ink holdout',
        '80lb Opaque Uncoated'       => 'Sturdy uncoated cardstock — professional feel',
        '80lb Matte Cardstock'       => 'Smooth matte cardstock — elegant & substantial',
        '100lb Gloss Cardstock'      => 'Glossy cardstock — bold colors, sharp images',
        '14pt Gloss C1S'             => 'Thick, coated one side — postcards & covers',
        '16pt Coated C2S'            => 'Premium double-coated — maximum durability',
    );

    $folds = array(
        array( 'val' => 'flat',        'label' => 'Flat — No Folding',       'desc' => 'Single sheet — flyers, handouts, inserts' ),
        array( 'val' => 'bifold',      'label' => 'Bifold (2 Panel)',        'desc' => 'Folded in half — menus, programs, invitations' ),
        array( 'val' => 'trifold',     'label' => 'Trifold (3 Panel)',       'desc' => 'The classic brochure fold — most popular' ),
        array( 'val' => 'z3',          'label' => 'Z-Fold (3 Panel)',        'desc' => 'Zigzag — each panel fully visible when open' ),
        array( 'val' => 'gate3',       'label' => 'Gate Fold (3 Panel)',     'desc' => 'Two flaps fold inward — dramatic reveal' ),
        array( 'val' => 'accordion4',  'label' => 'Accordion (4 Panel)',     'desc' => 'Zigzag with 4 panels — guides & timelines' ),
        array( 'val' => 'roll4',       'label' => 'Roll Fold (4 Panel)',     'desc' => 'Panels roll inward — mailers & menus' ),
        array( 'val' => 'dgate4',      'label' => 'Double Gate (4 Panel)',   'desc' => 'Four panels folding in — maximum impact' ),
        array( 'val' => 'dparallel4',  'label' => 'Double Parallel (4 Panel)', 'desc' => 'Two parallel folds — compact, detailed' ),
    );

    $sizes = array(
        array( 'val' => '5.5x8.5', 'label' => '5.5 × 8.5',  'desc' => 'Half letter — compact' ),
        array( 'val' => '6x9',     'label' => '6 × 9',       'desc' => 'Common mailer size' ),
        array( 'val' => '8.5x11',  'label' => '8.5 × 11',    'desc' => 'Letter — the standard' ),
        array( 'val' => '8.5x14',  'label' => '8.5 × 14',    'desc' => 'Legal — extra room' ),
        array( 'val' => '9x12',    'label' => '9 × 12',      'desc' => 'Fits inside folders' ),
        array( 'val' => '11x17',   'label' => '11 × 17',     'desc' => 'Tabloid — large format' ),
        array( 'val' => '12x18',   'label' => '12 × 18',     'desc' => 'Oversized tabloid' ),
        array( 'val' => '11x25.5', 'label' => '11 × 25.5',   'desc' => 'Extra-long mailer' ),
    );

    $coatings = array();
    if ( isset( $cfg['coatings'] ) ) {
        foreach ( $cfg['coatings'] as $c ) {
            if ( ! empty( $c['val'] ) ) $coatings[] = $c;
        }
    }

    $addons = array();
    if ( function_exists( 'pps_get_addons_visibility' ) && function_exists( 'pps_addon_labels' ) ) {
        $labels = pps_addon_labels();
        $vis    = pps_get_addons_visibility();
        foreach ( $vis as $addon => $calcs ) {
            if ( $addon === 'coating' ) continue;
            if ( isset( $calcs[ $a['calc'] ] ) && $calcs[ $a['calc'] ] && isset( $labels[ $addon ] ) ) {
                $addons[] = array( 'slug' => $addon, 'label' => $labels[ $addon ] );
            }
        }
    }

    // ── Render ──

    $out = '<div class="pps-wiz" id="' . esc_attr( $id ) . '" data-link="' . esc_attr( $link ) . '">';

    // Step 1: Size
    $out .= '<div class="pps-wiz-step is-active" data-step="size">';
    $out .= '<div class="pps-wiz-prompt">What size brochure do you need? <span class="pps-wiz-clear">&times; clear</span></div>';
    $out .= '<div class="pps-wiz-grid">';
    foreach ( $sizes as $s ) {
        $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $s['val'] ) . '" data-label="' . esc_attr( $s['label'] ) . '">'
              . '<div class="pps-wiz-opt-name">' . esc_html( $s['label'] ) . '</div>'
              . '<div class="pps-wiz-opt-desc">' . esc_html( $s['desc'] ) . '</div>'
              . '</button>';
    }
    $out .= '<button type="button" class="pps-wiz-opt" data-val="" data-label="Custom Size">'
          . '<div class="pps-wiz-opt-name">Custom Size</div>'
          . '<div class="pps-wiz-opt-desc">I\'ll enter dimensions in the calculator</div>'
          . '</button>';
    $out .= '</div></div>';

    // Step 2: Fold
    $out .= '<div class="pps-wiz-step" data-step="fold">';
    $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="size"></span> — now, which type of fold? <span class="pps-wiz-clear">&times; clear</span></div>';
    $out .= '<div class="pps-wiz-grid">';
    foreach ( $folds as $f ) {
        $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $f['val'] ) . '" data-label="' . esc_attr( $f['label'] ) . '">'
              . '<div class="pps-wiz-opt-name">' . esc_html( $f['label'] ) . '</div>'
              . '<div class="pps-wiz-opt-desc">' . esc_html( $f['desc'] ) . '</div>'
              . '</button>';
    }
    $out .= '</div></div>';

    // Step 3: Paper type (cardstock vs text weight)
    $out .= '<div class="pps-wiz-step" data-step="papertype">';
    $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="fold"></span> — great. What type of paper? <span class="pps-wiz-clear">&times; clear</span></div>';
    $out .= '<div class="pps-wiz-grid">';
    $out .= '<button type="button" class="pps-wiz-opt" data-val="text" data-label="Standard Text Weight">'
          . '<div class="pps-wiz-opt-name">Standard Text Weight</div>'
          . '<div class="pps-wiz-opt-desc">Lighter, flexible sheets &mdash; folds cleanly, great for brochures, newsletters &amp; inserts</div>'
          . '</button>';
    $out .= '<button type="button" class="pps-wiz-opt" data-val="cs" data-label="Cardstock">'
          . '<div class="pps-wiz-opt-name">Cardstock</div>'
          . '<div class="pps-wiz-opt-desc">Heavier, rigid stock &mdash; stands on its own, ideal for postcards, covers &amp; presentation pieces</div>'
          . '</button>';
    $out .= '</div></div>';

    // Step 4: Paper (filtered by type)
    $out .= '<div class="pps-wiz-step" data-step="paper">';
    $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="papertype"></span> — pick your paper stock: <span class="pps-wiz-clear">&times; clear</span></div>';
    $out .= '<div class="pps-wiz-grid">';
    foreach ( $papers as $p ) {
        $hint  = isset( $paper_hints[ $p['label'] ] ) ? $paper_hints[ $p['label'] ] : '';
        $ptype = ! empty( $p['_cs'] ) ? 'cs' : 'text';
        $stock = empty( $p['factory'] )
            ? '<span class="pps-cat-badge pps-cat-badge--stock">In Stock</span>'
            : '<span class="pps-cat-badge pps-cat-badge--factory">Factory Order</span>';
        $coat  = ! empty( $p['coatable'] )
            ? '<span class="pps-cat-badge pps-cat-badge--coat">UV Coatable</span>'
            : '';
        $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $p['val'] ) . '" data-label="' . esc_attr( $p['label'] ) . '" data-ptype="' . esc_attr( $ptype ) . '">'
              . '<div class="pps-wiz-opt-name">' . esc_html( $p['label'] ) . '</div>'
              . ( $hint ? '<div class="pps-wiz-opt-desc">' . esc_html( $hint ) . '</div>' : '' )
              . '<div class="pps-wiz-opt-badges">' . $stock . $coat . '</div>'
              . '</button>';
    }
    $out .= '</div></div>';

    // Step 5: Coating (optional)
    if ( ! empty( $coatings ) ) {
        $out .= '<div class="pps-wiz-step" data-step="coating">';
        $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="paper"></span> — would you like a coating? <span class="pps-wiz-clear">&times; clear</span></div>';
        $out .= '<div class="pps-wiz-grid">';
        $out .= '<button type="button" class="pps-wiz-opt" data-val="" data-label="No Coating">'
              . '<div class="pps-wiz-opt-name">No Coating</div>'
              . '<div class="pps-wiz-opt-desc">Default paper finish</div>'
              . '</button>';
        foreach ( $coatings as $c ) {
            $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $c['val'] ) . '" data-label="' . esc_attr( $c['label'] ) . '">'
                  . '<div class="pps-wiz-opt-name">' . esc_html( $c['label'] ) . '</div>'
                  . '</button>';
        }
        $out .= '</div></div>';
    }

    // Step 6: Add-ons (optional, multi-select)
    if ( ! empty( $addons ) ) {
        $out .= '<div class="pps-wiz-step" data-step="addons" data-multi="1">';
        $out .= '<div class="pps-wiz-prompt">Any finishing touches? <span style="font-weight:400;color:#64748b">(select all that apply)</span> <span class="pps-wiz-clear">&times; clear</span></div>';
        $out .= '<div class="pps-wiz-grid">';
        foreach ( $addons as $item ) {
            $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $item['slug'] ) . '" data-label="' . esc_attr( $item['label'] ) . '">'
                  . '<div class="pps-wiz-opt-name">' . esc_html( $item['label'] ) . '</div>'
                  . '</button>';
        }
        $out .= '</div></div>';
    }

    // Floating action bar
    $nonce    = wp_create_nonce( 'pps_wizard_email' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    $out .= '<div class="pps-wiz-actions" data-nonce="' . esc_attr( $nonce ) . '" data-ajax="' . esc_url( $ajax_url ) . '">';
    $out .= '<div class="pps-wiz-summary"></div>';
    $out .= '<div class="pps-wiz-actions-btns">';
    $out .= '<a class="pps-wiz-act-pricing" href="' . esc_url( $link ) . '">Proceed to Pricing &rarr;</a>';
    $out .= '<button type="button" class="pps-wiz-act-quote">Request a Quote</button>';
    $out .= '</div>';
    $out .= '<div class="pps-wiz-email-form">'
          . '<input type="text" class="pps-wiz-input" data-field="name" placeholder="Your Name">'
          . '<input type="email" class="pps-wiz-input" data-field="email" placeholder="Your Email">'
          . '<input type="tel" class="pps-wiz-input" data-field="phone" placeholder="Phone Number">'
          . '<div class="pps-wiz-phone-hint">Only used to communicate about this project &mdash; never used for advertising purposes.</div>'
          . '<input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off" data-field="hp">'
          . '<label class="pps-wiz-cb"><input type="checkbox" data-field="callback"> Request Callback</label>'
          . '<textarea class="pps-wiz-input" data-field="message" placeholder="Additional details (optional)"></textarea>'
          . '<button type="button" class="pps-wiz-send">Send Quote Request</button>'
          . '<div class="pps-wiz-email-status"></div>'
          . '</div>';
    $out .= '<button type="button" class="pps-wiz-reset">Start over</button>';
    $out .= '</div>';

    $out .= '</div>';

    // ── Inline JS ──
    $out .= '<script>'
          . '(function(){'
          . 'var w=document.getElementById(' . wp_json_encode( $id ) . ');'
          . 'if(!w)return;'
          . 'var steps=[];w.querySelectorAll(".pps-wiz-step").forEach(function(el){steps.push(el.dataset.step)});'
          . 'var state={},base=w.dataset.link;'
          . 'var bar=w.querySelector(".pps-wiz-actions");'
          . 'function show(s){var e=w.querySelector(\'[data-step="\'+s+\'"]\');if(e){e.classList.add("is-active");e.scrollIntoView({behavior:"smooth",block:"nearest"})}}'
          . 'function hide(s){var e=w.querySelector(\'[data-step="\'+s+\'"]\');if(e)e.classList.remove("is-active")}'
          . 'function filterPapers(ptype){'
          .   'w.querySelectorAll(\'[data-step="paper"] .pps-wiz-opt\').forEach(function(o){'
          .     'o.style.display=o.dataset.ptype===ptype?"":"none";'
          .     'o.classList.remove("is-selected");'
          .   '});'
          . '}'
          . 'function buildUrl(){'
          .   'var params=[];'
          .   'if(state.paper&&state.paper.val)params.push("paper="+encodeURIComponent(state.paper.val));'
          .   'if(state.fold)params.push("fold="+encodeURIComponent(state.fold.val));'
          .   'if(state.size&&state.size.val)params.push("size="+encodeURIComponent(state.size.val));'
          .   'if(state.coating&&state.coating.val)params.push("coating="+encodeURIComponent(state.coating.val));'
          .   'if(state.addons&&state.addons.val){state.addons.val.split(",").forEach(function(a){if(a)params.push(a+"=1")})}'
          .   'return params.length?base+(base.indexOf("?")>-1?"&":"?")+params.join("&"):base;'
          . '}'
          . 'function pick(step,val,label){'
          .   'state[step]={val:val,label:label};'
          .   'var opts=w.querySelectorAll(\'[data-step="\'+step+\'"] .pps-wiz-opt\');'
          .   'opts.forEach(function(o){o.classList.toggle("is-selected",o.dataset.val===val)});'
          .   'var si=steps.indexOf(step);'
          .   'for(var i=si+2;i<steps.length;i++){hide(steps[i]);delete state[steps[i]]}'
          .   'if(step==="papertype")filterPapers(val);'
          .   'if(si<steps.length-1)setTimeout(function(){show(steps[si+1])},150);'
          .   'updatePrev();updateActions();'
          . '}'
          . 'function updatePrev(){'
          .   'w.querySelectorAll(".pps-wiz-prev").forEach(function(el){'
          .     'var k=el.dataset.show;if(state[k])el.textContent=state[k].label;'
          .   '});'
          . '}'
          . 'function updateActions(){'
          .   'var parts=[];'
          .   'if(state.size)parts.push(state.size.label);'
          .   'if(state.fold)parts.push(state.fold.label);'
          .   'if(state.paper)parts.push(state.paper.label);'
          .   'if(state.coating&&state.coating.val)parts.push(state.coating.label);'
          .   'if(state.addons&&state.addons.val)parts.push(state.addons.label);'
          .   'var s=w.querySelector(".pps-wiz-summary");'
          .   'if(s)s.innerHTML=parts.length?parts.map(function(p){return"<strong>"+p+"<\\/strong>"}).join(" \\u00b7 "):"";'
          .   'var url=buildUrl();'
          .   'var a=w.querySelector(".pps-wiz-act-pricing");if(a)a.href=url;'
          .   'bar.classList.toggle("is-visible",parts.length>0);'
          . '}'
          . 'function clearStep(step){'
          .   'var si=steps.indexOf(step);if(si<0)return;'
          .   'delete state[step];'
          .   'var el=w.querySelector(\'[data-step="\'+step+\'"]\');'
          .   'if(el)el.querySelectorAll(".pps-wiz-opt").forEach(function(o){o.classList.remove("is-selected","is-toggled");if(o.dataset.ptype)o.style.display=""});'
          .   'for(var i=si+1;i<steps.length;i++){hide(steps[i]);delete state[steps[i]];'
          .     'var se=w.querySelector(\'[data-step="\'+steps[i]+\'"]\');'
          .     'if(se)se.querySelectorAll(".pps-wiz-opt").forEach(function(o){o.classList.remove("is-selected","is-toggled");if(o.dataset.ptype)o.style.display=""})}'
          .   'var ef=w.querySelector(".pps-wiz-email-form");if(ef)ef.style.display="none";'
          .   'updatePrev();updateActions();'
          . '}'
          . 'w.addEventListener("click",function(e){'
          .   'if(e.target.closest(".pps-wiz-clear")){'
          .     'var st=e.target.closest(".pps-wiz-step");'
          .     'if(st)clearStep(st.dataset.step);'
          .     'return;'
          .   '}'
          .   'var opt=e.target.closest(".pps-wiz-opt");'
          .   'if(opt){'
          .     'var st=opt.closest(".pps-wiz-step");'
          .     'if(st&&st.dataset.multi==="1"){'
          .       'opt.classList.toggle("is-toggled");'
          .       'var sel=st.querySelectorAll(".pps-wiz-opt.is-toggled");'
          .       'var labs=[],vals=[];'
          .       'sel.forEach(function(o){labs.push(o.dataset.label);vals.push(o.dataset.val)});'
          .       'if(vals.length)state[st.dataset.step]={val:vals.join(","),label:labs.join(", ")};'
          .       'else delete state[st.dataset.step];'
          .       'updateActions();'
          .       'return;'
          .     '}'
          .     'if(st)pick(st.dataset.step,opt.dataset.val,opt.dataset.label);'
          .     'return;'
          .   '}'
          .   'if(e.target.closest(".pps-wiz-reset")){'
          .     'state={};'
          .     'w.querySelectorAll(".pps-wiz-opt").forEach(function(o){o.classList.remove("is-selected","is-toggled");o.style.display=""});'
          .     'steps.forEach(function(s,i){if(i>0)hide(s)});'
          .     'bar.classList.remove("is-visible");'
          .     'var ef=w.querySelector(".pps-wiz-email-form");if(ef)ef.style.display="none";'
          .     'w.querySelector(\'[data-step="size"]\').scrollIntoView({behavior:"smooth",block:"nearest"});'
          .     'return;'
          .   '}'
          .   'if(e.target.closest(".pps-wiz-act-quote")){'
          .     'var ef=w.querySelector(".pps-wiz-email-form");'
          .     'if(ef)ef.style.display=ef.style.display==="block"?"none":"block";'
          .     'return;'
          .   '}'
          .   'if(e.target.closest(".pps-wiz-send")){'
          .     'var name=w.querySelector(\'[data-field="name"]\').value;'
          .     'var email=w.querySelector(\'[data-field="email"]\').value;'
          .     'var phone=w.querySelector(\'[data-field="phone"]\').value;'
          .     'var hp=w.querySelector(\'[data-field="hp"]\');'
          .     'var cb=w.querySelector(\'[data-field="callback"]\');'
          .     'var msg=w.querySelector(\'[data-field="message"]\').value;'
          .     'var statusEl=w.querySelector(".pps-wiz-email-status");'
          .     'if(!name||!email||!phone){statusEl.className="pps-wiz-email-status is-err";statusEl.textContent="Please enter your name, email, and phone number.";return}'
          .     'var digits=phone.replace(/\\D/g,"");if(digits.length<7||digits.length>15){statusEl.className="pps-wiz-email-status is-err";statusEl.textContent="Please enter a valid phone number.";return}'
          .     'var fd=new FormData();'
          .     'fd.append("action","pps_wizard_email");'
          .     'fd.append("nonce",bar.dataset.nonce);'
          .     'fd.append("name",name);fd.append("email",email);fd.append("phone",phone);'
          .     'if(hp)fd.append("website",hp.value);'
          .     'if(cb&&cb.checked)fd.append("callback","1");'
          .     'fd.append("specs",w.querySelector(".pps-wiz-summary").textContent);'
          .     'fd.append("url",w.querySelector(".pps-wiz-act-pricing").href);'
          .     'fd.append("message",msg);'
          .     'var btn=e.target.closest(".pps-wiz-send");btn.disabled=true;btn.textContent="Sending...";'
          .     'fetch(bar.dataset.ajax,{method:"POST",body:fd})'
          .       '.then(function(r){return r.json()})'
          .       '.then(function(r){'
          .         'if(r.success){statusEl.className="pps-wiz-email-status is-ok";statusEl.textContent=r.data;btn.textContent="Sent!"}'
          .         'else{statusEl.className="pps-wiz-email-status is-err";statusEl.textContent=r.data||"Could not send. Please try again.";btn.disabled=false;btn.textContent="Send Quote Request"}'
          .       '})'
          .       '.catch(function(){statusEl.className="pps-wiz-email-status is-err";statusEl.textContent="Network error. Please try again.";btn.disabled=false;btn.textContent="Send Quote Request"});'
          .     'return;'
          .   '}'
          . '});'
          . '})();'
          . '</script>';

    return $out;
} );

// ── Wizard quote email handler ──

add_action( 'wp_ajax_pps_wizard_email',        'pps_wizard_email_handler' );
add_action( 'wp_ajax_nopriv_pps_wizard_email', 'pps_wizard_email_handler' );
function pps_wizard_email_handler() {
    check_ajax_referer( 'pps_wizard_email', 'nonce' );
    if ( ! empty( $_POST['website'] ) ) wp_send_json_error( 'Invalid submission.' );

    $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
    $callback = ! empty( $_POST['callback'] );
    $specs    = sanitize_text_field( wp_unslash( $_POST['specs'] ?? '' ) );
    $url      = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
    $msg      = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

    if ( ! $name || ! $email || ! is_email( $email ) || ! $phone ) {
        wp_send_json_error( 'Please provide a valid name, email, and phone number.' );
    }
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
        wp_send_json_error( 'Please provide a valid phone number.' );
    }

    $admin = get_option( 'admin_email' );
    $subj  = $callback ? 'Quote + Callback Request from ' . $name : 'Quote Request from ' . $name;
    $body  = "Name: {$name}\nEmail: {$email}\nPhone: {$phone}";
    if ( $callback ) $body .= "\n*** CALLBACK REQUESTED ***";
    $body .= "\n\nSelected Specs:\n{$specs}\n\nCalculator Link:\n{$url}";
    if ( $msg ) $body .= "\n\nMessage:\n{$msg}";

    $headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );
    $sent    = wp_mail( $admin, $subj, $body, $headers );

    if ( $sent ) wp_send_json_success( 'Your quote request has been sent! We\'ll be in touch shortly.' );
    else         wp_send_json_error( 'Could not send email. Please try again or call us directly.' );
}
