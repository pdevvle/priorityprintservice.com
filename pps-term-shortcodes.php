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

// ── Enable Rank Math's WooCommerce category base removal ──
add_action( 'init', function() {
    $cur = get_option( 'pps_rm_catbase_set' );
    if ( $cur === 'v6' ) return;

    $raw = get_option( 'rank-math-options-general', '' );
    $is_json = is_string( $raw );
    $opts = $is_json ? json_decode( $raw, true ) : $raw;
    if ( is_array( $opts ) && ( ! isset( $opts['wc_remove_category_base'] ) || $opts['wc_remove_category_base'] !== 'on' ) ) {
        $opts['wc_remove_category_base'] = 'on';
        update_option( 'rank-math-options-general', $is_json ? wp_json_encode( $opts ) : $opts );
    }

    flush_rewrite_rules();

    $test_slugs = array( 'postcards', 'brochures', 'booklets', 'business-cards', 'flyers', 'rack-cards', 'door-hangers' );
    $terms = array();
    foreach ( $test_slugs as $s ) {
        $t = get_term_by( 'slug', $s, 'product_cat' );
        $terms[ $s ] = $t ? $t->term_id : false;
    }
    $rules = get_option( 'rewrite_rules', array() );
    $pfree = array();
    foreach ( $rules as $pat => $target ) {
        if ( strpos( $target, 'product_cat=' ) !== false && strpos( $pat, 'product-category' ) === false ) {
            $pfree[ $pat ] = $target;
        }
    }
    update_option( 'pps_catbase_selftest', array(
        'time'               => date( 'Y-m-d H:i:s' ),
        'phase'              => $cur === 'v5' ? 'v5->v6 (second flush)' : 'fresh',
        'terms'              => $terms,
        'prefix_free_rules'  => $pfree,
        'total_rules'        => count( $rules ),
    ), false );

    update_option( 'pps_rm_catbase_set', 'v6', true );
}, 999 );

// ── Route clean category slugs to product_cat (fixes %postname% rule conflict) ──
add_filter( 'request', function( $qv ) {
    if ( ! empty( $qv['product_cat'] ) ) return $qv;

    $slug = '';
    if ( ! empty( $qv['name'] ) )     $slug = $qv['name'];
    if ( ! $slug && ! empty( $qv['pagename'] ) ) $slug = $qv['pagename'];
    if ( ! $slug ) return $qv;

    $term = get_term_by( 'slug', $slug, 'product_cat' );

    if ( empty( $qv['rest_route'] ) ) {
        update_option( 'pps_catroute_log', array(
            'hook'  => 'request',
            'time'  => date( 'H:i:s' ),
            'uri'   => $_SERVER['REQUEST_URI'] ?? '',
            'slug'  => $slug,
            'src'   => ! empty( $qv['name'] ) ? 'name' : 'pagename',
            'found' => $term ? $term->term_id : false,
            'keys'  => array_keys( $qv ),
        ), false );
    }

    if ( $term && ! is_wp_error( $term ) ) {
        unset( $qv['name'], $qv['pagename'], $qv['page'], $qv['post_type'] );
        $qv['product_cat'] = $term->slug;
    }
    return $qv;
}, 1 );

// ── Safety net: force category query if request filter was overridden ──
add_action( 'pre_get_posts', function( $query ) {
    if ( ! $query->is_main_query() || is_admin() ) return;
    if ( $query->get( 'product_cat' ) ) return;

    $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
    if ( ! $path || strpos( $path, '/' ) !== false ) return;

    $term = get_term_by( 'slug', $path, 'product_cat' );
    if ( ! $term || is_wp_error( $term ) ) return;

    update_option( 'pps_catroute_log', array(
        'hook'  => 'pre_get_posts (request filter missed)',
        'time'  => date( 'H:i:s' ),
        'uri'   => $_SERVER['REQUEST_URI'] ?? '',
        'path'  => $path,
        'term'  => $term->term_id,
        'had'   => array(
            'pagename'  => $query->get( 'pagename' ),
            'name'      => $query->get( 'name' ),
            'post_type' => $query->get( 'post_type' ),
        ),
    ), false );

    $query->set( 'product_cat', $term->slug );
    $query->set( 'pagename', '' );
    $query->set( 'name', '' );
    $query->set( 'page', '' );
    $query->set( 'attachment', '' );
    $query->set( 'post_type', '' );
    $query->is_page       = false;
    $query->is_single     = false;
    $query->is_singular   = false;
    $query->is_attachment = false;
    $query->is_tax        = true;
    $query->is_archive    = true;
}, 1 );

// ── Prevent redirect_canonical loop on clean category URLs ──
add_filter( 'redirect_canonical', function( $redirect_url, $requested_url ) {
    if ( is_product_category() ) return false;
    $path = trim( parse_url( $requested_url, PHP_URL_PATH ), '/' );
    if ( $path && ! strpos( $path, '/' ) ) {
        $term = get_term_by( 'slug', $path, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) return false;
    }
    return $redirect_url;
}, 1, 2 );

// ── 301 redirect old landing pages → category pages ──
// ── Also: last-resort fix if WP resolved attachment/404 for a category slug ──

add_action( 'template_redirect', function() {
    $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

    $redirects = array(
        'brochure-printing-services'     => 'brochures',
        'custom-booklet-printing'        => 'booklets',
        'business-card-printing-services' => 'business-cards',
        'business-form-printing-services' => 'business-forms',
        'online-flyer-printing-service'  => 'flyers',
        'postcard-printing-services'     => 'postcards',
        'rack-card-printing'             => 'rack-cards',
        'door-hanger-printing'           => 'door-hangers',
        'sign-and-banner-printing'       => 'signs-and-banners',
        'custom-notepad-printing'        => 'notepads',
        'stationery-printing'            => 'stationery',
        'custom-presentation-folders'    => 'folders',
        'custom-menu-printing'           => 'menus',
        'printing-and-mailing-services'  => 'mailers',
        'eddm-full-service'              => 'eddm',
        'square-brochure-printing'       => 'square-brochures',
        'coupon-pads-coupon-booklets'     => 'coupon-booklets',
    );
    if ( isset( $redirects[ $path ] ) ) {
        wp_redirect( home_url( '/' . $redirects[ $path ] . '/' ), 301 );
        exit;
    }

    if ( ( is_attachment() || is_404() || is_page() || is_single() ) && $path && strpos( $path, '/' ) === false ) {
        $term = get_term_by( 'slug', $path, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            update_option( 'pps_catroute_log', array(
                'hook' => 'template_redirect (last resort)',
                'time' => date( 'H:i:s' ),
                'uri'  => $_SERVER['REQUEST_URI'] ?? '',
                'was'  => is_attachment() ? 'attachment' : ( is_404() ? '404' : ( is_page() ? 'page' : 'single' ) ),
                'term' => $term->term_id,
            ), false );
            global $wp_query;
            $wp_query = new WP_Query( array( 'product_cat' => $term->slug ) );
            $wp_query->is_tax     = true;
            $wp_query->is_archive = true;
            $wp_query->queried_object    = $term;
            $wp_query->queried_object_id = $term->term_id;
        }
    }
} , -999 );

add_filter( 'term_description', 'do_shortcode', 11 );

add_filter( 'woocommerce_product_add_to_cart_text', function() {
    return 'Customize Order';
}, 10, 2 );

add_action( 'wp_head', function() {
    if ( ! is_product_category() && ! is_product_taxonomy() ) return;
    ?>
<style id="pps-cat-shortcode-css">
/* ── Page-level resets ── */
body.tax-product_cat{overflow-x:hidden}
.term-description{max-width:100%;padding:0}

/* ── Hero banner ── */
.pps-cat-hero{position:relative;padding:80px max(40px,calc((100vw - var(--pps-max-w,1200px))/2 + 40px)) 72px;color:#fff;background-size:cover;background-position:center;background-color:#0f172a;width:100vw;margin-left:50%;transform:translateX(-50%);box-sizing:border-box}
.pps-cat-hero::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(15,23,42,.91) 0%,rgba(30,41,59,.88) 60%,rgba(51,65,85,.86) 100%);z-index:0}
.pps-cat-hero>*{position:relative;z-index:1}
.pps-cat-hero-title{font-size:36px;font-weight:800;letter-spacing:-.3px;margin:0 0 14px;line-height:1.2}
.pps-cat-hero-sub{font-size:17px;color:#cbd5e1;line-height:1.6;margin:0;max-width:640px}

/* ── USP bar ── */
.pps-cat-usps{display:grid;grid-template-columns:repeat(4,1fr);background:#f1f5f9;border-bottom:1px solid #e2e8f0;width:100vw;margin-left:50%;transform:translateX(-50%);box-sizing:border-box}
.pps-cat-usp{padding:14px 16px;font-size:13px;font-weight:600;color:#334155;display:flex;align-items:center;gap:8px;justify-content:center;text-align:center;border-right:1px solid #e2e8f0}
.pps-cat-usp:last-child{border-right:0}
.pps-cat-usp-icon{width:22px;height:22px;border-radius:50%;background:#007eff;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}

/* ── Content body ── */
.woocommerce-products-header{max-width:var(--pps-max-w,1200px);margin:0 auto;padding:24px 40px 16px;box-sizing:border-box}
.woocommerce-products-header__title{margin:0;padding:8px 0}
.pps-cat-body{max-width:var(--pps-max-w,1200px);margin:0 auto;padding:32px 40px 24px;box-sizing:border-box}
ul.products{max-width:var(--pps-max-w,1200px)!important;margin-left:auto!important;margin-right:auto!important;padding-left:40px!important;padding-right:40px!important;box-sizing:border-box}
.term-description h2{font-size:20px;font-weight:700;color:#0f172a;margin:32px 0 12px;padding:0 0 0 14px;border-left:3px solid #007eff;border-bottom:none;line-height:1.3}
.term-description h2:first-of-type{margin-top:8px}
.term-description>p,.pps-cat-body>p{font-size:15px;line-height:1.75;color:#475569;margin-bottom:16px}
.term-description ul{list-style:none;padding:0;margin:14px 0}
.term-description ul li{padding:6px 0 6px 22px;position:relative;font-size:15px;color:#475569;line-height:1.6}
.term-description ul li::before{content:"\25B8";position:absolute;left:0;color:#007eff;font-weight:700}

/* ── Paper cards ── */
.pps-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:16px 0 24px}
.pps-cat-card{position:relative;border:1px solid #e2e8f0;border-left:3px solid #007eff;border-radius:8px;padding:14px 16px;background:#fff;transition:box-shadow .2s,transform .15s}
.pps-cat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}
.pps-cat-card--link{text-decoration:none;color:inherit;cursor:pointer}
.pps-cat-card--link:hover .pps-cat-card-name{color:#007eff}
.pps-cat-card-arrow{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:20px;color:#cbd5e1;font-weight:300;transition:color .15s,transform .15s}
.pps-cat-card--link:hover .pps-cat-card-arrow{color:#007eff;transform:translateY(-50%) translateX(3px)}
.pps-cat-card-name{font-weight:600;font-size:14px;color:#0f172a;margin-bottom:6px}
.pps-cat-card-meta{display:flex;flex-wrap:wrap;gap:5px}
.pps-cat-badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:2px 8px;border-radius:4px}
.pps-cat-badge--stock{background:#dcfce7;color:#166534}
.pps-cat-badge--factory{background:#fef9c3;color:#854d0e}
.pps-cat-badge--coat{background:#ede9fe;color:#5b21b6}
.pps-cat-badge--cs{background:#dbeafe;color:#1e40af}

/* ── Turnaround callout ── */
.pps-cat-callout{background:linear-gradient(135deg,#e8f4ff 0%,#eff6ff 100%);border-radius:10px;padding:24px 28px;margin:24px 0;border:1px solid #93c5fd;position:relative;overflow:hidden}
.pps-cat-callout::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:#007eff}
.pps-cat-callout-title{margin:0 0 8px;font-size:17px;font-weight:700;color:#1e293b}
.pps-cat-callout-body{margin:0;color:#475569;font-size:15px;line-height:1.65}
.pps-cat-callout .pps-cat-num{font-size:32px;font-weight:800;color:#007eff;vertical-align:baseline}

/* ── Coating chips ── */
.pps-cat-chips{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 24px}
.pps-cat-chip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:22px;padding:8px 18px;font-size:13px;color:#334155;transition:all .15s}
.pps-cat-chip:hover{background:#e8f4ff;border-color:#93c5fd}
.pps-cat-chip b{color:#007eff;margin-right:4px}

/* ── Add-on grid ── */
.pps-cat-addon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:14px 0 24px}
.pps-cat-addon{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;font-size:13px;font-weight:500;color:#334155;transition:all .15s}
.pps-cat-addon:hover{background:#e8f4ff;border-color:#93c5fd}
.pps-cat-addon-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.pps-cat-addon-dot--vivid{background:#ec4899}
.pps-cat-addon-dot--coating{background:#8b5cf6}
.pps-cat-addon-dot--bundling{background:#3b82f6}
.pps-cat-addon-dot--rc{background:#10b981}
.pps-cat-addon-dot--two_staple{background:#f59e0b}
.pps-cat-addon-dot--perforation{background:#ef4444}
.pps-cat-addon-dot--outfold{background:#6366f1}
.pps-cat-card-name{display:flex;align-items:center;gap:6px}
.pps-cat-tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#e2e8f0;color:#64748b;font-size:10px;font-weight:700;cursor:pointer;flex-shrink:0;margin-left:auto;transition:all .15s;line-height:1;user-select:none}
.pps-cat-tip:hover{background:#007eff;color:#fff}
.pps-cat-chip .pps-cat-tip{margin-left:6px}
.pps-cat-tip-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:99999;align-items:center;justify-content:center}
.pps-cat-tip-overlay.is-open{display:flex}
.pps-cat-tip-modal{background:#fff;border-radius:12px;max-width:420px;width:calc(100vw - 32px);max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:ppsTipIn .2s ease}
@keyframes ppsTipIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:none}}
.pps-cat-tip-close{position:absolute;top:10px;right:14px;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;z-index:1;background:none;border:0;padding:4px}
.pps-cat-tip-close:hover{color:#0f172a}
.pps-cat-tip-modal-inner{position:relative;padding:24px}
.pps-cat-tip-modal h3{margin:0 0 14px;font-size:17px;font-weight:700;color:#0f172a;padding-right:24px}
.pps-cat-tip-modal p{margin:0 0 10px;font-size:14px;line-height:1.65;color:#475569}
.pps-cat-tip-modal p:last-child{margin-bottom:0}
.pps-cat-tip-modal img{max-width:100%;border-radius:6px;border:1px solid #e2e8f0;margin:6px 0}
.pps-cat-tip-modal video{max-width:100%;border-radius:6px;margin:6px 0}
.pps-cat-tip-modal .pps-cat-tip-yt{position:relative;width:100%;padding-top:56.25%;margin:6px 0}
.pps-cat-tip-modal .pps-cat-tip-yt iframe{position:absolute;inset:0;width:100%;height:100%;border:0;border-radius:6px}
@media(max-width:600px){.pps-cat-tip-modal{border-radius:12px 12px 0 0;position:fixed;bottom:0;left:0;right:0;max-width:100%;width:100%;max-height:80vh}}

/* ── Product grid ── */
ul.products{padding:0 4px!important}
ul.products li.product{background:#fff;border-radius:10px;padding:18px;border:1px solid #e2e8f0;transition:box-shadow .2s,transform .15s}
ul.products li.product:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px)}
ul.products li.product .woocommerce-loop-product__title{font-size:15px!important;font-weight:600;color:#0f172a}
ul.products li.product .price{font-size:14px;font-weight:700;color:#007eff}
ul.products li.product .button{background:#007eff;color:#fff;border-radius:6px;font-weight:600;font-size:13px;letter-spacing:.3px;padding:10px 20px;transition:background .15s;border:0;text-transform:none}
ul.products li.product .button:hover{background:#0070e6}
ul.products li.product img{border-radius:8px}

/* ── Responsive ── */
@media(max-width:768px){
.pps-cat-hero{padding:56px 24px 48px}
.pps-cat-hero-title{font-size:28px}
.pps-cat-usps{grid-template-columns:1fr 1fr}
.pps-cat-usp{border-bottom:1px solid #e2e8f0}
.pps-cat-usp:nth-child(2){border-right:0}
.woocommerce-products-header{padding:20px 24px 0}
.pps-cat-body{padding:24px 24px 16px}
ul.products{padding-left:24px!important;padding-right:24px!important}
}
@media(max-width:480px){
.pps-cat-hero{padding:44px 20px 36px}
.pps-cat-hero-title{font-size:24px}
.pps-cat-hero-sub{font-size:14px}
.pps-cat-usps{grid-template-columns:1fr}
.pps-cat-usp{border-right:0;justify-content:flex-start}
.woocommerce-products-header{padding:16px 16px 0}
.pps-cat-body{padding:20px 16px 12px}
ul.products{padding-left:16px!important;padding-right:16px!important}
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
.pps-wiz-prompt .pps-wiz-prev{color:#007eff;font-weight:700}
.pps-wiz-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:6px}
.pps-wiz-opt{all:unset;position:relative;cursor:pointer;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;background:#fff;transition:border-color .15s,box-shadow .15s}
.pps-wiz-opt:hover{border-color:#60a5fa;box-shadow:0 2px 8px rgba(0,126,255,.1)}
.pps-wiz-opt.is-selected{border-color:#007eff;box-shadow:0 0 0 2px rgba(0,126,255,.2);background:#eff6ff}
.pps-wiz-opt-name{font-weight:600;font-size:12.5px;color:#0f172a;margin-bottom:2px}
.pps-wiz-opt.is-selected .pps-wiz-opt-name{color:#007eff}
.pps-wiz-opt-desc{font-size:11px;color:#64748b;line-height:1.4;margin-bottom:3px}
.pps-wiz-opt-badges{display:flex;flex-wrap:wrap;gap:3px}
.pps-wiz-done{background:linear-gradient(135deg,#e8f4ff 0%,#eff6ff 100%);border:1px solid #93c5fd;border-radius:8px;padding:18px 24px;text-align:center}
.pps-wiz-summary{font-size:14px;color:#334155;margin-bottom:12px;line-height:1.5}
.pps-wiz-summary strong{color:#1e293b}
.pps-wiz-cta{display:inline-block;background:#007eff;color:#fff;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none;transition:background .15s}
.pps-wiz-cta:hover{background:#0070e6;color:#fff}
.pps-wiz-reset{display:inline-block;margin-top:8px;font-size:12px;color:#64748b;cursor:pointer;text-decoration:underline;border:0;background:0}
.pps-wiz-reset:hover{color:#007eff}
@media(max-width:480px){.pps-wiz-grid{grid-template-columns:1fr 1fr}}
.pps-wiz-opt.is-toggled{border-color:#007eff;background:#eff6ff}
.pps-wiz-opt.is-toggled .pps-wiz-opt-name{color:#007eff}
.pps-wiz-clear{font-size:11px;color:#94a3b8;cursor:pointer;margin-left:8px;font-weight:400;text-decoration:underline}
.pps-wiz-clear:hover{color:#dc2626}
.pps-wiz-actions{margin-top:14px;background:linear-gradient(135deg,#e8f4ff 0%,#eff6ff 100%);border:1px solid #93c5fd;border-radius:8px;padding:16px 20px;display:none;text-align:center}
.pps-wiz-actions.is-visible{display:block;animation:ppsFadeIn .35s ease}
.pps-wiz-actions-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:8px}
.pps-wiz-act-pricing{display:inline-block;background:#007eff;color:#fff;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px;text-decoration:none;transition:background .15s}
.pps-wiz-act-pricing:hover{background:#0070e6;color:#fff}
.pps-wiz-act-quote{background:none;border:1px solid #93c5fd;color:#007eff;font-weight:600;font-size:13px;padding:10px 24px;border-radius:6px;cursor:pointer;transition:all .15s}
.pps-wiz-act-quote:hover{background:#e8f4ff}
.pps-wiz-email-form{margin-top:12px;display:none;text-align:left;max-width:400px;margin-left:auto;margin-right:auto}
.pps-wiz-phone-hint{font-size:11px;color:#94a3b8;margin:-4px 0 8px 2px;line-height:1.4}
.pps-wiz-cb{display:flex;align-items:center;gap:6px;font-size:13px;color:#334155;cursor:pointer;margin-bottom:8px;font-weight:500}
.pps-wiz-cb input[type="checkbox"]{width:16px;height:16px;accent-color:#007eff;cursor:pointer}
.pps-wiz-input{display:block;width:100%;padding:10px 12px;margin-bottom:8px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box}
.pps-wiz-input:focus{outline:none;border-color:#60a5fa;box-shadow:0 0 0 2px rgba(0,126,255,.15)}
textarea.pps-wiz-input{min-height:70px;resize:vertical}
.pps-wiz-send{background:#007eff;color:#fff;font-weight:600;font-size:13px;padding:10px 24px;border-radius:6px;border:0;cursor:pointer;transition:background .15s;margin-top:4px}
.pps-wiz-send:hover{background:#0070e6}
.pps-wiz-email-status{margin-top:8px;font-size:13px;font-weight:500}
.pps-wiz-email-status.is-ok{color:#166534}
.pps-wiz-email-status.is-err{color:#dc2626}
.pps-wiz-upload{margin-bottom:8px}
.pps-wiz-upload-zone{display:block;border:2px dashed #e2e8f0;border-radius:8px;padding:16px;text-align:center;cursor:pointer;transition:all .15s}
.pps-wiz-upload-zone:hover,.pps-wiz-upload-zone.is-dragover{border-color:#60a5fa;background:#eff6ff}
.pps-wiz-upload-zone input[type="file"]{display:none}
.pps-wiz-upload-icon{font-size:13px;font-weight:600;color:#334155;margin-bottom:2px}
.pps-wiz-upload-hint{font-size:11px;color:#94a3b8}
.pps-wiz-file-list{margin-top:6px}
.pps-wiz-file{display:flex;align-items:center;gap:6px;padding:5px 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;color:#334155;margin-bottom:3px}
.pps-wiz-file-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pps-wiz-file-size{color:#94a3b8;font-size:11px;flex-shrink:0}
.pps-wiz-file-rm{cursor:pointer;color:#dc2626;font-size:14px;line-height:1;flex-shrink:0;padding:0 2px;background:none;border:0}
.pps-wiz-file-rm:hover{color:#b91c1c}
.pps-wiz-file-total{font-size:11px;color:#64748b;margin-top:2px}
.pps-wiz-file-total.is-warn{color:#dc2626;font-weight:600}
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

// ── Tooltip modal (category pages) ──

add_action( 'wp_footer', function() {
    if ( ! is_product_category() && ! is_product_taxonomy() ) return;
    $tips = get_option( 'pps_tooltips', array() );
    if ( empty( $tips ) || ! is_array( $tips ) ) return;

    $tip_json = wp_json_encode( $tips, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE );
    ?>
    <div class="pps-cat-tip-overlay" id="pps-tip-overlay">
        <div class="pps-cat-tip-modal">
            <div class="pps-cat-tip-modal-inner">
                <button class="pps-cat-tip-close" id="pps-tip-close">&times;</button>
                <div id="pps-tip-content"></div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var tips=<?php echo $tip_json; ?>;
        var overlay=document.getElementById('pps-tip-overlay');
        var content=document.getElementById('pps-tip-content');
        function close(){overlay.classList.remove('is-open');content.innerHTML=''}
        document.getElementById('pps-tip-close').addEventListener('click',close);
        overlay.addEventListener('click',function(e){if(e.target===overlay)close()});
        document.addEventListener('keydown',function(e){if(e.key==='Escape')close()});
        document.addEventListener('click',function(e){
            var trigger=e.target.closest('.pps-cat-tip');
            if(!trigger)return;
            e.preventDefault();
            e.stopPropagation();
            var key=trigger.getAttribute('data-tip');
            var tip=tips[key];
            if(!tip)return;
            var html='<h3>'+esc(tip.title||key)+'</h3>';
            (tip.content||[]).forEach(function(b){
                if(b.type==='text')html+='<p>'+esc(b.value||'')+'</p>';
                else if(b.type==='image')html+='<img src="'+esc(b.src||'')+'" alt="'+esc(b.alt||'')+'" loading="lazy" onerror="this.style.display=\'none\'">';
                else if(b.type==='video')html+='<video controls preload="metadata"'+(b.poster?' poster="'+esc(b.poster)+'"':'')+'><source src="'+esc(b.src||'')+'" type="video/mp4"></video>';
                else if(b.type==='youtube')html+='<div class="pps-cat-tip-yt"><iframe src="'+esc(b.src||'')+'" allowfullscreen loading="lazy" title="'+esc(b.alt||'Video')+'"></iframe></div>';
            });
            content.innerHTML=html;
            overlay.classList.add('is-open');
        });
        function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}
    })();
    </script>
    <?php
}, 20 );

// ── [pps_cat_papers type="text|cover|all" factory="yes|no"] ──

add_shortcode( 'pps_cat_papers', function( $atts ) {
    if ( ! function_exists( 'pps_get_config' ) ) return '';
    $a   = shortcode_atts( array( 'type' => 'all', 'factory' => 'yes', 'link' => '' ), $atts );
    $cfg = pps_get_config();

    $papers = array();
    if ( $a['type'] === 'text' || $a['type'] === 'all' ) {
        foreach ( ( isset( $cfg['papers_nc'] ) ? $cfg['papers_nc'] : array() ) as $p ) {
            $p['_tip'] = 'paper_text_' . sanitize_key( str_replace( ' ', '_', $p['label'] ) );
            $p['_tip_fallback'] = 'paper_text_weight';
            $papers[] = $p;
        }
    }
    if ( $a['type'] === 'cover' || $a['type'] === 'all' ) {
        foreach ( ( isset( $cfg['papers_cs'] ) ? $cfg['papers_cs'] : array() ) as $p ) {
            $p['_tip'] = 'paper_cs_' . sanitize_key( str_replace( ' ', '_', $p['label'] ) );
            $p['_tip_fallback'] = 'paper_cardstock';
            $papers[] = $p;
        }
    }
    if ( $a['factory'] === 'no' ) {
        $papers = array_filter( $papers, function( $p ) { return empty( $p['factory'] ); } );
    }
    if ( empty( $papers ) ) return '';

    $tips      = get_option( 'pps_tooltips', array() );
    $base_link = trim( $a['link'] );

    $out = '<div class="pps-cat-grid">';
    foreach ( $papers as $p ) {
        $label   = esc_html( $p['label'] );
        $tip_key = $p['_tip'] ?? '';
        $tip_fb  = $p['_tip_fallback'] ?? '';
        $has_tip = $tip_key && isset( $tips[ $tip_key ] ) && ! empty( $tips[ $tip_key ]['title'] );
        if ( ! $has_tip && $tip_fb ) { $tip_key = $tip_fb; $has_tip = isset( $tips[ $tip_key ] ) && ! empty( $tips[ $tip_key ]['title'] ); }
        $stock = empty( $p['factory'] )
            ? '<span class="pps-cat-badge pps-cat-badge--stock">In Stock</span>'
            : '<span class="pps-cat-badge pps-cat-badge--factory">Factory Order</span>';
        $coat  = ! empty( $p['coatable'] )
            ? '<span class="pps-cat-badge pps-cat-badge--coat">UV Coatable</span>'
            : '';
        $tip_btn = $has_tip ? '<span class="pps-cat-tip" data-tip="' . esc_attr( $tip_key ) . '">?</span>' : '';
        $inner = '<div class="pps-cat-card-name">' . $label . $tip_btn . '</div>'
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

    $tips    = get_option( 'pps_tooltips', array() );
    $has_tip = isset( $tips['coating'] ) && ! empty( $tips['coating']['title'] );

    $out = '<div class="pps-cat-chips">';
    foreach ( $coatings as $c ) {
        $out .= '<span class="pps-cat-chip"><b>&#10022;</b> ' . esc_html( $c['label'] )
              . ( $has_tip ? ' <span class="pps-cat-tip" data-tip="coating">?</span>' : '' )
              . '</span>';
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

    $addon_tip_map = array(
        'vivid' => 'vivid', 'coating' => 'coating', 'bundling' => 'bundling',
        'rc' => 'round_cornering', 'two_staple' => 'saddle_stitch',
        'perforation' => 'perforation', 'outfold' => 'outfold',
    );
    $tips = get_option( 'pps_tooltips', array() );

    $out = '<div class="pps-cat-addon-grid">';
    foreach ( $items as $item ) {
        $tip_key = isset( $addon_tip_map[ $item['slug'] ] ) ? $addon_tip_map[ $item['slug'] ] : '';
        $has_tip = $tip_key && isset( $tips[ $tip_key ] ) && ! empty( $tips[ $tip_key ]['title'] );
        $out .= '<div class="pps-cat-addon">'
              . '<span class="pps-cat-addon-dot pps-cat-addon-dot--' . esc_attr( $item['slug'] ) . '"></span>'
              . esc_html( $item['label'] )
              . ( $has_tip ? '<span class="pps-cat-tip" data-tip="' . esc_attr( $tip_key ) . '">?</span>' : '' )
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

    $calc = $a['calc'];
    $is_booklet = in_array( $calc, array( 'saddle', 'perfect-bound', 'coupon' ), true );

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

    if ( $is_booklet ) {
        $sizes = array();
        if ( isset( $cfg['size_presets'] ) ) {
            foreach ( $cfg['size_presets'] as $group ) {
                foreach ( $group['items'] as $item ) {
                    $sizes[] = array( 'val' => $item['label'], 'label' => $item['label'], 'desc' => '' );
                }
            }
        }
        $page_counts = isset( $cfg['page_counts'] ) ? $cfg['page_counts'] : array( 8, 12, 16, 20, 24, 28, 32 );
        $folds = array();
    } else {
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
        $page_counts = array();
    }

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

    $out = '<div class="pps-wiz" id="' . esc_attr( $id ) . '" data-link="' . esc_attr( $link ) . '" data-calc="' . esc_attr( $calc ) . '">';

    // Step 1: Size
    $size_prompt = $is_booklet ? 'What size do you need?' : 'What size brochure do you need?';
    $out .= '<div class="pps-wiz-step is-active" data-step="size">';
    $out .= '<div class="pps-wiz-prompt">' . esc_html( $size_prompt ) . ' <span class="pps-wiz-clear">&times; clear</span></div>';
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

    if ( $is_booklet ) {
        // Step 2: Page Count
        $out .= '<div class="pps-wiz-step" data-step="pages">';
        $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="size"></span> — how many pages? <span class="pps-wiz-clear">&times; clear</span></div>';
        $out .= '<div class="pps-wiz-grid">';
        foreach ( $page_counts as $pc ) {
            $pc = intval( $pc );
            $out .= '<button type="button" class="pps-wiz-opt" data-val="' . $pc . '" data-label="' . $pc . ' pages">'
                  . '<div class="pps-wiz-opt-name">' . $pc . ' Pages</div>'
                  . '</button>';
        }
        $out .= '</div></div>';
    } else {
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
    }

    // Step 3: Paper type (cardstock vs text weight)
    $pt_prev = $is_booklet ? 'pages' : 'fold';
    $pt_prompt = $is_booklet ? 'What type of paper for the inside pages?' : 'great. What type of paper?';
    $out .= '<div class="pps-wiz-step" data-step="papertype">';
    $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="' . $pt_prev . '"></span> — ' . esc_html( $pt_prompt ) . ' <span class="pps-wiz-clear">&times; clear</span></div>';
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

    // Step 5 (booklets only): Cover stock
    if ( $is_booklet ) {
        $out .= '<div class="pps-wiz-step" data-step="cover">';
        $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="paper"></span> — and the cover stock? <span class="pps-wiz-clear">&times; clear</span></div>';
        $out .= '<div class="pps-wiz-grid">';
        $out .= '<button type="button" class="pps-wiz-opt" data-val="same" data-label="Same as Inside">'
              . '<div class="pps-wiz-opt-name">Same as Inside Pages</div>'
              . '<div class="pps-wiz-opt-desc">Use the same paper stock for the cover</div>'
              . '</button>';
        foreach ( $cs as $p ) {
            $stock = empty( $p['factory'] )
                ? '<span class="pps-cat-badge pps-cat-badge--stock">In Stock</span>'
                : '<span class="pps-cat-badge pps-cat-badge--factory">Factory Order</span>';
            $out .= '<button type="button" class="pps-wiz-opt" data-val="' . esc_attr( $p['val'] ) . '" data-label="' . esc_attr( $p['label'] ) . '">'
                  . '<div class="pps-wiz-opt-name">' . esc_html( $p['label'] ) . '</div>'
                  . '<div class="pps-wiz-opt-badges">' . $stock . '</div>'
                  . '</button>';
        }
        $out .= '</div></div>';
    }

    // Coating (optional)
    $coat_prev = $is_booklet ? 'cover' : 'paper';
    if ( ! empty( $coatings ) ) {
        $out .= '<div class="pps-wiz-step" data-step="coating">';
        $out .= '<div class="pps-wiz-prompt"><span class="pps-wiz-prev" data-show="' . $coat_prev . '"></span> — would you like a coating? <span class="pps-wiz-clear">&times; clear</span></div>';
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
          . '<div class="pps-wiz-upload">'
          . '<label class="pps-wiz-upload-zone">'
          . '<input type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.tiff,.tif,.ai,.psd,.eps" data-field="files">'
          . '<div class="pps-wiz-upload-icon">Attach Files (optional)</div>'
          . '<div class="pps-wiz-upload-hint">PDF, PNG, JPG, TIFF, AI, PSD, EPS</div>'
          . '</label>'
          . '<div class="pps-wiz-file-list"></div>'
          . '</div>'
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
          . 'var wizFiles=[];'
          . 'var fileInput=w.querySelector(\'[data-field="files"]\');'
          . 'var fileZone=w.querySelector(".pps-wiz-upload-zone");'
          . 'var fileList=w.querySelector(".pps-wiz-file-list");'
          . 'function fmtSz(b){return(b/1048576).toFixed(1)+" MB"}'
          . 'function renderFiles(){'
          .   'fileList.innerHTML="";var total=0;'
          .   'wizFiles.forEach(function(f,i){'
          .     'total+=f.size;var d=document.createElement("div");d.className="pps-wiz-file";'
          .     'var nm=document.createElement("span");nm.className="pps-wiz-file-name";nm.textContent=f.name;'
          .     'var sz=document.createElement("span");sz.className="pps-wiz-file-size";sz.textContent=fmtSz(f.size);'
          .     'var rm=document.createElement("button");rm.type="button";rm.className="pps-wiz-file-rm";rm.dataset.fi=i;rm.innerHTML="&times;";'
          .     'd.appendChild(nm);d.appendChild(sz);d.appendChild(rm);fileList.appendChild(d);'
          .   '});'
          .   'if(wizFiles.length){'
          .     'var t=document.createElement("div");'
          .     't.className="pps-wiz-file-total"+(total>20971520?" is-warn":"");'
          .     't.textContent="Total: "+fmtSz(total)+(total>20971520?" — large files will be uploaded to a secure link":"");'
          .     'fileList.appendChild(t);'
          .   '}'
          . '}'
          . 'if(fileInput){fileInput.addEventListener("change",function(){Array.from(this.files).forEach(function(f){wizFiles.push(f)});this.value="";renderFiles()})}'
          . 'if(fileZone){'
          .   'fileZone.addEventListener("dragover",function(e){e.preventDefault();this.classList.add("is-dragover")});'
          .   'fileZone.addEventListener("dragleave",function(){this.classList.remove("is-dragover")});'
          .   'fileZone.addEventListener("drop",function(e){e.preventDefault();this.classList.remove("is-dragover");Array.from(e.dataTransfer.files).forEach(function(f){wizFiles.push(f)});renderFiles()});'
          . '}'
          . 'function show(s){var e=w.querySelector(\'[data-step="\'+s+\'"]\');if(e){e.classList.add("is-active");e.scrollIntoView({behavior:"smooth",block:"nearest"})}}'
          . 'function hide(s){var e=w.querySelector(\'[data-step="\'+s+\'"]\');if(e)e.classList.remove("is-active")}'
          . 'function filterPapers(ptype){'
          .   'w.querySelectorAll(\'[data-step="paper"] .pps-wiz-opt\').forEach(function(o){'
          .     'o.style.display=o.dataset.ptype===ptype?"":"none";'
          .     'o.classList.remove("is-selected");'
          .   '});'
          . '}'
          . 'var calc=w.dataset.calc;'
          . 'function buildUrl(){'
          .   'var params=[];'
          .   'if(calc==="brochure"){'
          .     'if(state.paper&&state.paper.val)params.push("paper="+encodeURIComponent(state.paper.val));'
          .     'if(state.fold)params.push("fold="+encodeURIComponent(state.fold.val));'
          .     'if(state.size&&state.size.val)params.push("size="+encodeURIComponent(state.size.val));'
          .     'if(state.coating&&state.coating.val)params.push("coating="+encodeURIComponent(state.coating.val));'
          .     'if(state.addons&&state.addons.val){state.addons.val.split(",").forEach(function(a){if(a)params.push(a+"=1")})}'
          .   '}else{'
          .     'if(state.size&&state.size.val)params.push("size="+encodeURIComponent(state.size.val));'
          .     'if(state.pages)params.push("pages="+encodeURIComponent(state.pages.val));'
          .     'if(state.paper&&state.paper.val)params.push("paper="+encodeURIComponent(state.paper.val));'
          .     'if(state.cover){if(state.cover.val==="same")params.push("covermode=same");else params.push("coverpaper="+encodeURIComponent(state.cover.val))}'
          .     'if(state.coating&&state.coating.val)params.push("coating="+encodeURIComponent(state.coating.val));'
          .     'if(state.addons&&state.addons.val){state.addons.val.split(",").forEach(function(a){if(a==="vivid")params.push("vivid=true");else if(a==="two_staple")params.push("staple=true");else if(a==="bundling")params.push("bundling=750");else if(a==="rc")params.push("corner=108");else if(a==="perforation")params.push("perf=1");else if(a==="outfold")params.push("outfold=1");else params.push(a+"=1")})}'
          .   '}'
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
          .   'if(state.pages)parts.push(state.pages.label);'
          .   'if(state.fold)parts.push(state.fold.label);'
          .   'if(state.paper)parts.push(state.paper.label);'
          .   'if(state.cover&&state.cover.val)parts.push("Cover: "+state.cover.label);'
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
          .   'var frm=e.target.closest(".pps-wiz-file-rm");'
          .   'if(frm){wizFiles.splice(parseInt(frm.dataset.fi),1);renderFiles();return}'
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
          .     'wizFiles=[];renderFiles();'
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
          .     'wizFiles.forEach(function(f){fd.append("files[]",f)});'
          .     'var btn=e.target.closest(".pps-wiz-send");btn.disabled=true;btn.textContent="Sending...";'
          .     'fetch(bar.dataset.ajax,{method:"POST",body:fd})'
          .       '.then(function(r){return r.json()})'
          .       '.then(function(r){'
          .         'if(r.success){statusEl.className="pps-wiz-email-status is-ok";statusEl.textContent=r.data;btn.textContent="Sent!";wizFiles=[];renderFiles()}'
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

    $headers     = array( 'Reply-To: ' . $name . ' <' . $email . '>' );
    $attachments = array();
    $cleanup     = array();
    $upload_urls = array();

    $allowed_ext = array( 'pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'ai', 'psd', 'eps' );
    $max_per_file = 20 * 1024 * 1024; // 20 MB
    $max_email    = 20 * 1024 * 1024;

    if ( ! empty( $_FILES['files'] ) && is_array( $_FILES['files']['name'] ) ) {
        $count = count( $_FILES['files']['name'] );
        $total = 0;
        $valid = array();

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $_FILES['files']['error'][ $i ] !== UPLOAD_ERR_OK ) continue;
            $orig = sanitize_file_name( $_FILES['files']['name'][ $i ] );
            $ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $allowed_ext, true ) ) continue;
            if ( $_FILES['files']['size'][ $i ] > $max_per_file ) continue;
            $total += $_FILES['files']['size'][ $i ];
            $valid[] = $i;
        }

        if ( $total <= $max_email ) {
            $tmp_dir = get_temp_dir() . 'pps-quote-' . wp_generate_password( 8, false );
            wp_mkdir_p( $tmp_dir );
            foreach ( $valid as $i ) {
                $orig = sanitize_file_name( $_FILES['files']['name'][ $i ] );
                $dest = $tmp_dir . '/' . $orig;
                if ( move_uploaded_file( $_FILES['files']['tmp_name'][ $i ], $dest ) ) {
                    $attachments[] = $dest;
                }
            }
            $cleanup[] = $tmp_dir;
        } else {
            $token   = wp_generate_password( 16, false );
            $upl_dir = wp_upload_dir();
            $quote_dir = $upl_dir['basedir'] . '/pps-quotes/' . $token;
            wp_mkdir_p( $quote_dir );
            $htaccess = $quote_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Options -Indexes\n" );
            }
            foreach ( $valid as $i ) {
                $orig = sanitize_file_name( $_FILES['files']['name'][ $i ] );
                $dest = $quote_dir . '/' . $orig;
                if ( move_uploaded_file( $_FILES['files']['tmp_name'][ $i ], $dest ) ) {
                    $upload_urls[] = $upl_dir['baseurl'] . '/pps-quotes/' . $token . '/' . $orig;
                }
            }
            if ( $upload_urls ) {
                $body .= "\n\nUploaded Files (too large for email attachment):\n" . implode( "\n", $upload_urls );
            }
        }
    }

    $sent = wp_mail( $admin, $subj, $body, $headers, $attachments );

    foreach ( $cleanup as $dir ) {
        $files_in = glob( $dir . '/*' );
        if ( $files_in ) array_map( 'unlink', $files_in );
        @rmdir( $dir );
    }

    if ( $sent ) wp_send_json_success( 'Your quote request has been sent! We\'ll be in touch shortly.' );
    else         wp_send_json_error( 'Could not send email. Please try again or call us directly.' );
}

// ── Purge quote uploads older than 60 days ──

add_action( 'wp_ajax_pps_purge_quotes', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized.', 403 );
    }

    $upl_dir   = wp_upload_dir();
    $base      = $upl_dir['basedir'] . '/pps-quotes';
    $cutoff    = time() - ( 60 * DAY_IN_SECONDS );
    $deleted   = 0;
    $freed     = 0;

    if ( ! is_dir( $base ) ) {
        wp_send_json_success( 'No pps-quotes directory found. Nothing to purge.' );
    }

    foreach ( glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
        if ( filemtime( $dir ) > $cutoff ) continue;
        $size = 0;
        $files = glob( $dir . '/*' );
        if ( $files ) {
            foreach ( $files as $f ) { $size += filesize( $f ); unlink( $f ); }
        }
        @rmdir( $dir );
        $deleted++;
        $freed += $size;
    }

    $mb = round( $freed / 1048576, 1 );
    wp_send_json_success( "Purged {$deleted} upload folder(s) older than 60 days. Freed {$mb} MB." );
} );
