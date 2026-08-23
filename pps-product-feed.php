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
 * Google does not fill in the calculator to find a price. It reads the landing
 * page's structured data. So the check Merchant Center actually runs is
 * *feed vs. our own Product schema* — and the schema's price comes from
 * pps_product_defaults_low_price(), not from WooCommerce's price element.
 *
 * Both sides therefore go through pps_product_price_facts(), which is the
 * single answer to "what does this product cost". Because the schema and the
 * feed read the same resolver, they cannot contradict each other — which is
 * the whole of what Google checks.
 *
 * That done, the feed is deliberately ungated: a product goes to Shopping with
 * whatever price it carries, high or low (owner's call, 2026-08-15). Only two
 * things keep a product out, and both are things Google rejects outright —
 * no price, and no image. Everything else is reported as a note.
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
        // Flat shipping estimate for the feed. Blank means "configured at the
        // Merchant Center account level instead", which is the better place for
        // it — PPS quotes real shipping at checkout, so anything here is only a
        // figure for Google's listing.
        'shipping_price'   => (string) ( $seo['feed_shipping_price'] ?? '' ),
        'shipping_country' => (string) ( $seo['feed_shipping_country'] ?? 'US' ),
    ) );
}

/**
 * Server-side check of one item against the things Google rejects on.
 *
 * Merchant Center's own message is famously unhelpful ("The product details in
 * your file don't meet Google requirements") and the per-item reason lives
 * several clicks deep. This catches the mechanical faults here, where the fix
 * is, rather than after a fetch-and-review round trip that costs a day.
 *
 * It cannot see everything — whether Google can actually download an image,
 * and whether shipping and tax are configured on the account, are only knowable
 * from Google's side.
 *
 * @return string[] Human-readable problems; empty means nothing obvious wrong.
 */
function pps_product_feed_lint( array $row, array $s ) {
    $p = array();
    $home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

    if ( trim( $row['title'] ) === '' ) $p[] = 'no title';

    $desc = $row['short'] !== '' ? $row['short'] : $row['description'];
    if ( strlen( trim( $desc ) ) < 20 ) {
        $p[] = 'description is very short — Google treats near-empty descriptions as poor quality';
    }

    foreach ( array( 'link' => $row['url'], 'image_link' => $row['image'] ) as $label => $u ) {
        if ( $u === '' ) { $p[] = "no {$label}"; continue; }
        $parts = wp_parse_url( $u );
        if ( empty( $parts['scheme'] ) || $parts['scheme'] !== 'https' ) {
            $p[] = "{$label} is not https — Google requires it";
        }
        if ( $label === 'link' && ! empty( $parts['host'] ) && $parts['host'] !== $home ) {
            $p[] = "link points at {$parts['host']}, not the site's own domain — "
                 . 'it must be the domain claimed in Merchant Center';
        }
    }

    if ( $row['price'] === null || $row['price'] <= 0 ) $p[] = 'no price';

    // The specific contradiction that got every item rejected in the first
    // Merchant Center run: identifier_exists=no cannot sit beside a brand.
    if ( $s['brand'] === '' ) {
        $p[] = 'no brand set — with no GTIN either, Google has no way to identify the product';
    }

    return $p;
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

    // g:price is the LIST price and g:sale_price the discounted one — never
    // the effective price in g:price alone. Google compares the landing page's
    // structured data against the sale price when one is present, and against
    // g:price when one is not, so collapsing the two would mismatch every
    // product that happens to be on sale.
    $list = $row['regular'] !== null ? $row['regular'] : $row['price'];
    $out .= '    <g:price>' . number_format( $list, 2, '.', '' ) . ' ' . pps_feed_x( $s['currency'] ) . "</g:price>\n";
    if ( $row['sale'] !== null && $row['sale'] < $list ) {
        $out .= '    <g:sale_price>' . number_format( $row['sale'], 2, '.', '' ) . ' ' . pps_feed_x( $s['currency'] ) . "</g:sale_price>\n";
    }

    $out .= "    <g:condition>new</g:condition>\n";
    $out .= '    <g:brand>' . pps_feed_x( $s['brand'] ) . "</g:brand>\n";

    // ── Product identifiers ──
    //
    // This previously sent identifier_exists=no alongside a brand, which is
    // self-contradictory: that value means the product has no GTIN, no MPN AND
    // no brand. Google reads the pair as a malformed identifier and disapproves.
    //
    // Custom print has no GTIN — nobody assigns barcodes to a booklet you
    // designed this morning — but it does have a brand and it can have a
    // manufacturer part number, which is exactly what a SKU is. brand + mpn is
    // a complete identifier in Google's terms, so that is what goes out.
    $mpn = $row['sku'] !== '' ? $row['sku'] : 'PPS-' . $row['id'];
    $out .= '    <g:mpn>' . pps_feed_x( $mpn ) . "</g:mpn>\n";

    // Shipping. Optional here: if a shipping service is configured at the
    // Merchant Center account level, that wins and this can stay unset. With
    // neither, every item fails on "Missing value: shipping".
    if ( $s['shipping_price'] !== '' ) {
        $out .= "    <g:shipping>\n";
        $out .= '      <g:country>' . pps_feed_x( $s['shipping_country'] ) . "</g:country>\n";
        $out .= '      <g:price>' . pps_feed_x( $s['shipping_price'] ) . ' ' . pps_feed_x( $s['currency'] ) . "</g:price>\n";
        $out .= "    </g:shipping>\n";
    }

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
    // require_virtual reports rather than excludes — a product missing the flag
    // is a WooCommerce shipping problem, not a Shopping one.
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
          . count( $report['skipped'] ) . ' without a price or an image. '
          . 'Add ?debug=1 as an admin for the detail. -->' . "\n";

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

    // The measured floor, shown for contrast only. It is deliberately NOT what
    // g:price carries: Google requires the advertised price to be the price of
    // the item as the landing page presents it, and a customer who clicks a
    // floor price and arrives at the defaults price has been misled — which is
    // a disapproval and a policy problem, not just a bad number.
    if ( function_exists( 'pps_min_prices' ) ) {
        $mp = pps_min_prices();
        if ( empty( $mp['calculators'] ) ) {
            echo "Measured price floors: none imported. Run tools-min-price.mjs --config <live>.\n\n";
        } elseif ( pps_min_prices_are_stale() ) {
            echo "Measured price floors: STALE — pricing config has changed since the sweep of "
               . ( $mp['imported_at'] ?? 'unknown' ) . ". Re-run tools-min-price.mjs.\n\n";
        } else {
            echo 'Measured price floors (imported ' . ( $mp['imported_at'] ?? '?' ) . "):\n";
            foreach ( $mp['calculators'] as $k => $row ) {
                printf( "  %-24s from %8s\n", $k, '$' . number_format( (float) ( $row['min_total'] ?? 0 ), 2 ) );
            }
            echo "  (marketing 'from' figures only — g:price stays pinned to each page's own price)\n\n";
        }
    }

    echo 'IN THE FEED (' . count( $report['rows'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    $lint_hits = 0;
    foreach ( $report['rows'] as $row ) {
        $note = ( $row['sale'] !== null ) ? '  (on sale from ' . number_format( $row['regular'], 2 ) . ')' : '';
        printf( "  #%-7d %-46s %10s%s\n", $row['id'], pps_feed_clip( $row['title'], 44 ),
                number_format( $row['price'], 2 ), $note );
        foreach ( pps_product_feed_lint( $row, $s ) as $problem ) {
            $lint_hits++;
            echo "           ! " . $problem . "\n";
        }
    }
    if ( ! $report['rows'] ) echo "  (none)\n";

    echo "\nLEFT OUT (" . count( $report['skipped'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    echo "Only two things keep a product out: no price, or no image. Google will\n";
    echo "not list a product missing either.\n\n";
    foreach ( $report['skipped'] as $sk ) {
        printf( "  #%-7d %-40s %s\n", $sk['id'],
                pps_feed_clip( $sk['title'] ?? '(unresolved)', 38 ), $sk['reason'] );
    }
    if ( ! $report['skipped'] ) echo "  (none)\n";

    if ( ! empty( $report['warnings'] ) ) {
        echo "\nWORTH A LOOK (" . count( $report['warnings'] ) . ") — these ARE in the feed\n"
           . str_repeat( '-', 60 ) . "\n";
        foreach ( $report['warnings'] as $w ) {
            printf( "  #%-7d %-30s %s\n", $w['id'], pps_feed_clip( $w['title'] ?? '', 28 ), $w['note'] );
        }
    }

    if ( $report['collisions'] ) {
        echo "\nASSIGNED TO TWO CALCULATORS — configuration error\n" . str_repeat( '-', 60 ) . "\n";
        foreach ( $report['collisions'] as $c ) {
            printf( "  #%-7d %s\n", $c['id'], implode( ' + ', $c['files'] ) );
        }
    }

    if ( $lint_hits ) {
        echo "\n" . $lint_hits . " item-level problem(s) marked ! above. Those are the ones Google\n";
        echo "rejects on, and they are fixable here rather than in Merchant Center.\n";
    }

    echo "\nTHINGS THIS PAGE CANNOT SEE\n" . str_repeat( '-', 60 ) . "\n";
    echo "  - whether Google can download the images (hotlink protection, Cloudflare)\n";
    echo "  - whether SHIPPING is configured in Merchant Center. With neither an\n";
    echo "    account-level shipping service nor a feed value, every item fails on\n";
    echo "    \"Missing value: shipping\". Set one in Merchant Center, or fill in\n";
    echo "    seo.feed_shipping_price to put a flat figure in the feed.\n";
    echo "  - whether TAX is configured (US accounts need it at account level)\n";
    echo "  - whether the domain is verified and claimed\n";
    echo "  Feed shipping value: "
       . ( $s['shipping_price'] !== '' ? '$' . $s['shipping_price'] . ' ' . $s['shipping_country']
                                       : '(not set — relying on Merchant Center account settings)' ) . "\n";

    echo "\nA missing price is fixed on the product's PPS Defaults tab by pasting a\n";
    echo "quote link, which sets the defaults and the price together — or just by\n";
    echo "typing a price into WooCommerce's own field.\n";
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
