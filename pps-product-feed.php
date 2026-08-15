<?php
/**
 * PPS Product Feed — Google Merchant Center, via scheduled fetch.
 *
 * Serves /pps-product-feed.xml. Merchant Center is pointed at that URL and
 * fetches it on a schedule (Products → Feeds → Add → Scheduled fetch).
 *
 * Why a fetched file rather than the Content API: no OAuth, no token refresh,
 * no quota handling, and the whole thing can be debugged by opening the URL.
 * Daily freshness is ample for prices that only change when the operator
 * changes them. The endpoint is the same shape as /pps-presets-sitemap.xml
 * (pps-calculators.php:6390) — rewrite rule, query var, template_redirect,
 * emit, exit.
 *
 * ── The rule that protects the Merchant Center account ──
 *
 * Google crawls the landing page and compares its price to the feed's. A
 * mismatch disapproves the item; a pattern of them warns the account. So the
 * feed publishes $product->get_price() — literally the number the page renders
 * — and refuses to invent one. A product without a meaningful price is left
 * OUT of the feed rather than sent with a guess: an omitted product loses one
 * listing, a mismatched one costs account standing.
 *
 * Every omission is explained at /pps-product-feed.xml?debug=1 (admins only),
 * because a silent exclusion turns "why isn't my product in Shopping?" into a
 * question nobody can answer.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_PRODUCT_FEED_SLUG', 'pps-product-feed.xml' );
define( 'PPS_PRODUCT_FEED_TRANSIENT', 'pps_product_feed_xml' );

/* ─────────────────────────────────────────────────────────────
 * Routing — mirrors PPS_PRESETS_SITEMAP_SLUG exactly.
 * ───────────────────────────────────────────────────────────── */

add_action( 'init', function () {
    add_rewrite_rule(
        '^' . preg_quote( PPS_PRODUCT_FEED_SLUG, '/' ) . '$',
        'index.php?pps_product_feed=1',
        'top'
    );
} );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'pps_product_feed';
    return $vars;
} );

add_action( 'template_redirect', function () {
    if ( ! get_query_var( 'pps_product_feed' ) ) return;

    // Diagnostic view. Admin-only: the skip list names unpublished products
    // and internal reasons, which is not public information.
    if ( isset( $_GET['debug'] ) && current_user_can( 'manage_woocommerce' ) ) {
        pps_product_feed_render_debug();
        exit;
    }

    $xml = get_transient( PPS_PRODUCT_FEED_TRANSIENT );
    if ( $xml === false ) {
        $xml = pps_product_feed_render();
        set_transient( PPS_PRODUCT_FEED_TRANSIENT, $xml, HOUR_IN_SECONDS );
    }

    header( 'Content-Type: application/xml; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );
    header( 'X-Robots-Tag: noindex' );   // the feed is for Merchant Center, not Search
    echo $xml;
    exit;
}, 5 );

/**
 * Drop the cached feed whenever something in it could have changed.
 * Without this an edit waits up to an hour to appear, which reads as a bug.
 */
function pps_product_feed_flush_cache() {
    delete_transient( PPS_PRODUCT_FEED_TRANSIENT );
}
add_action( 'save_post_product', 'pps_product_feed_flush_cache' );
add_action( 'deleted_post', 'pps_product_feed_flush_cache' );
add_action( 'update_option_' . ( defined( 'PPS_CALC_OPTION' ) ? PPS_CALC_OPTION : 'pps_calculators' ),
            'pps_product_feed_flush_cache' );

/* ─────────────────────────────────────────────────────────────
 * Rendering
 * ───────────────────────────────────────────────────────────── */

/**
 * XML-safe text. Not esc_xml() — that only exists from WP 6.5, and this file
 * has to run on whatever the site is on today.
 */
function pps_feed_x( $s ) {
    return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * Truncate on a word boundary, because Google truncates mid-word and it shows.
 */
function pps_feed_clip( $s, $max ) {
    $s = trim( (string) $s );
    if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) <= $max : strlen( $s ) <= $max ) return $s;
    $cut = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
    $sp  = strrpos( $cut, ' ' );
    if ( $sp !== false && $sp > $max * 0.6 ) $cut = substr( $cut, 0, $sp );
    return rtrim( $cut, " \t\n\r\0\x0B,;:-" );
}

/**
 * Feed-wide settings, all overridable without touching this file.
 */
function pps_product_feed_settings() {
    $seo = array();
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $seo = isset( $cfg['seo'] ) && is_array( $cfg['seo'] ) ? $cfg['seo'] : array();
    }
    return apply_filters( 'pps_product_feed_settings', array(
        'brand'    => (string) ( $seo['brand_name'] ?? get_bloginfo( 'name' ) ),
        'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
        // Google's own taxonomy value. Deliberately empty by default — guessing
        // one is worse than omitting it, and Google will infer when it is absent.
        // Set seo.google_product_category once from the current taxonomy list.
        'gpc'      => (string) ( $seo['google_product_category'] ?? '' ),
    ) );
}

/**
 * One <item>. Returns '' if the row somehow lacks what it needs.
 */
function pps_product_feed_item( array $row, array $s ) {
    if ( $row['price'] === null || $row['price'] <= 0 || $row['image'] === '' ) return '';

    // Description: prefer the short description — it is written to be a
    // summary, which is exactly what a feed wants. Fall back to the body.
    $desc = $row['short'] !== '' ? $row['short'] : $row['description'];
    if ( $desc === '' ) {
        // Never ship an empty description; build one from the defaults so the
        // listing still says something true and specific.
        $spec = function_exists( 'pps_catalog_spec_line' ) ? pps_catalog_spec_line( $row['defaults'] ) : '';
        $desc = trim( $row['title'] . ( $spec !== '' ? ' — ' . $spec : '' ) . '. Printed to order by ' . $s['brand'] . '.' );
    }

    $out  = "  <item>\n";
    $out .= '    <g:id>' . pps_feed_x( $row['id'] ) . "</g:id>\n";
    $out .= '    <title>' . pps_feed_x( pps_feed_clip( $row['title'], 150 ) ) . "</title>\n";
    $out .= '    <description>' . pps_feed_x( pps_feed_clip( $desc, 5000 ) ) . "</description>\n";
    $out .= '    <link>' . pps_feed_x( $row['url'] ) . "</link>\n";
    $out .= '    <g:image_link>' . pps_feed_x( $row['image'] ) . "</g:image_link>\n";

    foreach ( array_slice( $row['gallery'], 0, 10 ) as $img ) {
        $out .= '    <g:additional_image_link>' . pps_feed_x( $img ) . "</g:additional_image_link>\n";
    }

    $out .= "    <g:availability>in_stock</g:availability>\n";
    $out .= '    <g:price>' . number_format( $row['price'], 2, '.', '' ) . ' ' . pps_feed_x( $s['currency'] ) . "</g:price>\n";
    $out .= "    <g:condition>new</g:condition>\n";
    $out .= '    <g:brand>' . pps_feed_x( $s['brand'] ) . "</g:brand>\n";

    // Custom-printed goods carry no GTIN and no MPN worth inventing. Saying so
    // explicitly is required; omitting the field reads to Google as an
    // oversight and gets the item disapproved.
    $out .= "    <g:identifier_exists>no</g:identifier_exists>\n";

    if ( $row['categories'] ) {
        $out .= '    <g:product_type>' . pps_feed_x( implode( ' &gt; ', $row['categories'] ) ) . "</g:product_type>\n";
    }
    if ( $s['gpc'] !== '' ) {
        $out .= '    <g:google_product_category>' . pps_feed_x( $s['gpc'] ) . "</g:google_product_category>\n";
    }
    if ( $row['calc'] !== '' ) {
        // Free segmentation for campaigns later, at no cost now.
        $out .= '    <g:custom_label_0>' . pps_feed_x( $row['calc'] ) . "</g:custom_label_0>\n";
    }

    $out .= "  </item>\n";
    return $out;
}

/**
 * The whole document.
 *
 * Note the three requirements passed to pps_catalog_report(): a feed item is
 * useless without a price and an image, and a PPS product that is not virtual
 * is a configuration error worth surfacing rather than publishing.
 */
function pps_product_feed_render() {
    $s = pps_product_feed_settings();
    $report = pps_catalog_report( array(
        'require_price'   => true,
        'require_image'   => true,
        'require_virtual' => true,
    ) );

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    $out .= "<channel>\n";
    $out .= '  <title>' . pps_feed_x( get_bloginfo( 'name' ) ) . "</title>\n";
    $out .= '  <link>' . pps_feed_x( home_url( '/' ) ) . "</link>\n";
    $out .= '  <description>' . pps_feed_x( get_bloginfo( 'description' ) ) . "</description>\n";

    foreach ( $report['rows'] as $row ) {
        $out .= pps_product_feed_item( $row, $s );
    }

    $out .= "</channel>\n</rss>\n";

    // A trailer the operator can see without leaving the browser, and which
    // Merchant Center ignores.
    $out .= '<!-- ' . count( $report['rows'] ) . ' item(s); '
          . count( $report['skipped'] ) . ' skipped. '
          . 'Add ?debug=1 as an admin to see why. -->' . "\n";

    return $out;
}

/**
 * The diagnostic. Plain text on purpose — it is read, not parsed.
 */
function pps_product_feed_render_debug() {
    $report = pps_catalog_report( array(
        'require_price'   => true,
        'require_image'   => true,
        'require_virtual' => true,
    ) );
    $s = pps_product_feed_settings();

    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Robots-Tag: noindex' );

    echo "PPS product feed — diagnostic\n";
    echo str_repeat( '=', 60 ) . "\n\n";
    echo 'Feed URL:  ' . home_url( '/' . PPS_PRODUCT_FEED_SLUG ) . "\n";
    echo 'Brand:     ' . $s['brand'] . "\n";
    echo 'Currency:  ' . $s['currency'] . "\n";
    echo 'Google product category: ' . ( $s['gpc'] !== '' ? $s['gpc'] : '(not set — Google will infer)' ) . "\n\n";

    echo 'IN THE FEED (' . count( $report['rows'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    foreach ( $report['rows'] as $row ) {
        printf( "  #%-7d %-46s %10s\n", $row['id'], pps_feed_clip( $row['title'], 44 ),
                number_format( $row['price'], 2 ) );
        if ( $row['price_drift'] ) {
            printf( "           ⚠ page price %s but defaults were quoted at %s — re-check the product\n",
                    number_format( $row['price'], 2 ), number_format( $row['quoted'], 2 ) );
        }
    }
    if ( ! $report['rows'] ) echo "  (none)\n";

    echo "\nLEFT OUT (" . count( $report['skipped'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    foreach ( $report['skipped'] as $sk ) {
        printf( "  #%-7d %-40s %s\n", $sk['id'],
                pps_feed_clip( $sk['title'] ?? '(unresolved)', 38 ), $sk['reason'] );
    }
    if ( ! $report['skipped'] ) echo "  (none)\n";

    if ( $report['collisions'] ) {
        echo "\nASSIGNED TO TWO CALCULATORS — configuration error\n" . str_repeat( '-', 60 ) . "\n";
        foreach ( $report['collisions'] as $c ) {
            printf( "  #%-7d %s\n", $c['id'], implode( ' + ', $c['files'] ) );
        }
    }

    echo "\nMost omissions are fixed on the product's PPS Defaults tab by\n";
    echo "pasting a quote link, which sets both the defaults and the price.\n";
}

/* ─────────────────────────────────────────────────────────────
 * Discoverability
 * ───────────────────────────────────────────────────────────── */

/**
 * The plugin already appends to robots.txt; make sure nothing blocks the feed.
 * Merchant Center's fetcher respects robots.txt, so a stray Disallow would
 * present as an empty feed with no explanation.
 */
add_filter( 'robots_txt', function ( $output ) {
    if ( strpos( $output, PPS_PRODUCT_FEED_SLUG ) === false ) {
        $output .= "\n# Google Merchant Center product feed\nAllow: /" . PPS_PRODUCT_FEED_SLUG . "\n";
    }
    return $output;
}, 20, 1 );

/**
 * Admin pointer: the feed exists, here is its state, here is where it goes.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || strpos( (string) $screen->id, 'pps' ) === false ) return;

    $report = pps_catalog_report( array(
        'require_price' => true, 'require_image' => true, 'require_virtual' => true,
    ) );
    $n = count( $report['rows'] );
    $k = count( $report['skipped'] );
    if ( ! $n && ! $k ) return;

    $url = home_url( '/' . PPS_PRODUCT_FEED_SLUG );
    echo '<div class="notice notice-info"><p><strong>Merchant Center feed:</strong> '
       . esc_html( sprintf( '%d product%s in the feed, %d left out.', $n, $n === 1 ? '' : 's', $k ) )
       . ' <a href="' . esc_url( $url ) . '" target="_blank">View feed</a> · '
       . '<a href="' . esc_url( add_query_arg( 'debug', '1', $url ) ) . '" target="_blank">Why were products left out?</a>'
       . '</p></div>';
} );
