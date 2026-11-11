<?php
/**
 * PPS OpenAI Feed — the product feed for Businesses in ChatGPT (Business Hub).
 *
 * Serves /pps-openai-feed.jsonl and /pps-openai-feed.csv: the same products as
 * the Google Merchant feed (/pps-product-feed.xml), in OpenAI's product-feed
 * shape, plus the preset landing pages. Read-only; it writes nothing but its
 * own hour-long cache.
 *
 * ── The one rule: never a second price calculation ──
 *
 * A catalog product's price is pps_product_price_facts() through
 * pps_catalog_report(), exactly what the Google feed and the page's Product
 * schema publish — list price in `price`, a live sale in `sale_price`, the
 * same two numbers g:price / g:sale_price carry. tools-openai-feed-test.php
 * renders BOTH feeds from one fake catalog and fails on any difference in the
 * item set or in a single price.
 *
 * A preset's price is the price its own page's Product schema carries: the
 * preset's `offer_price` override, else its `price_from` (the order the
 * preset head emitter resolves them in, pps-calculators.php). A preset with
 * no page price is left out and named in ?debug=1 — publishing its product's
 * price instead would advertise one job's price on another job's page.
 *
 * ── Why a configurable product is one row ──
 *
 * Every row is a landing page with a "from" price: the price of the job the
 * page opens with. The calculator on that page prices any other choice live.
 * Descriptions say "from" and never promise a price or a date.
 *
 * Checkout inside ChatGPT is left off (`is_eligible_checkout` false): a print
 * job needs artwork, a proof and a delivery date, which live on the site.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require), after
 * pps-catalog.php and pps-product-feed.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PPS_OPENAI_FEED_SLUG', 'pps-openai-feed' );          // .jsonl and .csv
define( 'PPS_OPENAI_FEED_TRANSIENT', 'pps_openai_feed_rows' );
define( 'PPS_OPENAI_FEED_OPTION', 'pps_openai_feed' );          // ['presets' => bool]

/* ─────────────────────────────────────────────────────────────
 * Routing — same shape as the Google feed.
 * ───────────────────────────────────────────────────────────── */

add_action( 'init', function () {
    add_rewrite_rule(
        '^' . preg_quote( PPS_OPENAI_FEED_SLUG, '/' ) . '\.(jsonl|csv)$',
        'index.php?pps_openai_feed=$matches[1]',
        'top'
    );
    // The rule is new: flush once so the URL answers without a trip to
    // Settings → Permalinks. Versioned, so it never flushes again.
    if ( get_option( 'pps_openai_feed_rules' ) !== '1' ) {
        flush_rewrite_rules( false );
        update_option( 'pps_openai_feed_rules', '1', false );
    }
}, 20 );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'pps_openai_feed';
    return $vars;
} );

add_action( 'template_redirect', function () {
    $fmt = get_query_var( 'pps_openai_feed' );
    if ( $fmt !== 'jsonl' && $fmt !== 'csv' ) return;

    if ( isset( $_GET['debug'] ) && current_user_can( 'manage_woocommerce' ) ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        echo pps_openai_feed_debug();
        exit;
    }

    $rows = pps_openai_feed_cached_rows();
    header( 'Cache-Control: public, max-age=3600' );
    header( 'X-Robots-Tag: noindex' );
    if ( $fmt === 'csv' ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="' . PPS_OPENAI_FEED_SLUG . '.csv"' );
        echo pps_openai_feed_csv( $rows );
    } else {
        header( 'Content-Type: application/jsonl; charset=utf-8' );
        echo pps_openai_feed_jsonl( $rows );
    }
    exit;
}, 5 );

function pps_openai_feed_cached_rows() {
    $rows = get_transient( PPS_OPENAI_FEED_TRANSIENT );
    if ( ! is_array( $rows ) ) {
        $built = pps_openai_feed_build();
        $rows  = $built['items'];
        set_transient( PPS_OPENAI_FEED_TRANSIENT, $rows, HOUR_IN_SECONDS );
    }
    return $rows;
}

function pps_openai_feed_flush_cache() {
    delete_transient( PPS_OPENAI_FEED_TRANSIENT );
}
add_action( 'save_post_product', 'pps_openai_feed_flush_cache' );
add_action( 'deleted_post', 'pps_openai_feed_flush_cache' );
add_action( 'update_option_' . ( defined( 'PPS_CALC_OPTION' ) ? PPS_CALC_OPTION : 'pps_calculators' ),
            'pps_openai_feed_flush_cache' );
add_action( 'update_option_pps_presets', 'pps_openai_feed_flush_cache' );
add_action( 'update_option_' . PPS_OPENAI_FEED_OPTION, 'pps_openai_feed_flush_cache' );

/* ─────────────────────────────────────────────────────────────
 * Settings
 * ───────────────────────────────────────────────────────────── */

function pps_openai_feed_settings() {
    $g = function_exists( 'pps_product_feed_settings' ) ? pps_product_feed_settings() : array();
    $o = get_option( PPS_OPENAI_FEED_OPTION, array() );
    if ( is_string( $o ) ) { $d = json_decode( $o, true ); $o = is_array( $d ) ? $d : array(); }
    if ( ! is_array( $o ) ) $o = array();

    $brand = (string) ( $g['brand'] ?? get_bloginfo( 'name' ) );
    return apply_filters( 'pps_openai_feed_settings', array(
        'brand'          => $brand,
        'seller_name'    => $brand,
        'seller_url'     => home_url( '/' ),
        'currency'       => (string) ( $g['currency'] ?? 'USD' ),
        // Preset landing pages in the feed. On unless the owner turns it off.
        'presets'        => ! array_key_exists( 'presets', $o ) || ! empty( $o['presets'] ),
        // Free ground shipping US-wide — the site's own wording (calculator
        // noscript). The spec reads an omitted shipping_price as "unknown",
        // not free, so it is said: 0.00. (The structured `shipping` field
        // "requires feed-specific setup" with OpenAI; one charge, one field.)
        'shipping_price' => '0.00',
        'return_policy'  => home_url( '/return-reprint-revision-and-cancellation-policy/' ),
        // The policy page says "All sales final"; reprints and refunds are at
        // staff discretion (late, damaged, short, wrong paper, lost).
        'accepts_returns'=> false,
        'privacy_policy' => home_url( '/privacy-policy/' ),
        'terms'          => home_url( '/terms/' ),
        // Root of product_category ("broad to specific, separated by >");
        // the product's WooCommerce categories follow it.
        'category'       => 'Printing Services',
    ) );
}

/* ─────────────────────────────────────────────────────────────
 * Rows
 * ───────────────────────────────────────────────────────────── */

/**
 * Plain one-line text: tags, shortcodes and runs of whitespace gone. The end
 * of a list item, paragraph, heading or line break becomes a sentence break
 * first — short descriptions here are bullet lists, which would otherwise run
 * together ("Minimum Order of 25 Pieces Text and Cardstock …").
 */
function pps_openai_feed_text( $s ) {
    $s = preg_replace( '#</(li|p|h[1-6]|div|td)\s*>|<br\s*/?>#i', '. ', strip_shortcodes( (string) $s ) );
    $s = wp_strip_all_tags( $s, true );
    $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $s = trim( preg_replace( '/\s+/u', ' ', $s ) );
    // ". ." from nested blocks, and a break after text that already ended a sentence.
    $s = preg_replace( '/([.!?:;])(\s*\.)+/u', '$1', $s );
    $s = preg_replace( '/(\s*\.){2,}/u', '.', $s );
    return trim( ltrim( $s, '. ' ) );
}

function pps_openai_feed_clip( $s, $max ) {
    if ( function_exists( 'pps_feed_clip' ) ) return pps_feed_clip( $s, $max );
    $s = trim( (string) $s );
    return mb_strlen( $s ) <= $max ? $s : rtrim( mb_substr( $s, 0, $max ) );
}

function pps_openai_feed_money( $v, $cur ) {
    return number_format( (float) $v, 2, '.', '' ) . ' ' . $cur;
}

/**
 * A product's description: its short description, else its body, else one
 * built from its defaults — the Google feed's order — closed with what a
 * reader of ChatGPT needs and a feed row cannot hold: the price is "from".
 */
function pps_openai_feed_description( $title, $text, array $defaults, array $s ) {
    $text = pps_openai_feed_text( $text );
    if ( $text === '' ) {
        $spec = function_exists( 'pps_catalog_spec_line' ) ? pps_catalog_spec_line( $defaults ) : '';
        $text = trim( $title . ( $spec !== '' ? ' — ' . $spec : '' ) . '.' );
    }
    if ( ! preg_match( '/[.!?]$/u', $text ) ) $text .= '.';
    $tail = ' Printed to order by ' . $s['seller_name'] . '. The price shown is the starting price for the job '
          . 'this page opens with; the online calculator prices any size, paper, quantity and finishing live.';
    return pps_openai_feed_clip( pps_openai_feed_clip( $text, 4500 ) . $tail, 5000 );
}

/** Does this product carry a contract price list? (Same meta the saddle calculator reads.) */
function pps_openai_feed_is_contract( $id ) {
    $c = get_post_meta( (int) $id, '_pps_contract', true );
    if ( is_string( $c ) && $c !== '' ) { $d = json_decode( $c, true ); $c = is_array( $d ) ? $d : array( 'raw' => $c ); }
    return is_array( $c ) && ! empty( $c );
}

/** "Printing Services > Booklets": the root, then the first WooCommerce category. */
function pps_openai_feed_category( array $s, array $cats ) {
    $path = array( $s['category'] );
    foreach ( $cats as $c ) {
        $c = trim( str_replace( '>', '-', pps_openai_feed_text( $c ) ) );
        if ( $c !== '' && strcasecmp( $c, 'Uncategorized' ) !== 0 && strcasecmp( $c, $s['category'] ) !== 0 ) { $path[] = $c; break; }
    }
    return implode( ' > ', $path );
}

/** The fields every row shares. */
function pps_openai_feed_common( array $s ) {
    return array(
        'brand'                => $s['brand'],
        'seller_name'          => $s['seller_name'],
        'seller_url'           => $s['seller_url'],
        'availability'         => 'in_stock',
        'condition'            => 'new',
        'shipping_price'       => pps_openai_feed_money( $s['shipping_price'], $s['currency'] ),
        'return_policy'        => $s['return_policy'],
        'accepts_returns'      => $s['accepts_returns'],
        'seller_privacy_policy'=> $s['privacy_policy'],
        'seller_tos'           => $s['terms'],
        'is_eligible_search'   => true,
        'is_eligible_checkout' => false,
    );
}

/**
 * One catalog row (from pps_catalog_report()) as an OpenAI item. Prices are
 * the Google feed's, field for field: `price` = g:price (list), `sale_price`
 * = g:sale_price when a sale is live and below the list price.
 */
function pps_openai_feed_product_item( array $row, array $s ) {
    if ( $row['price'] === null || $row['price'] <= 0 || $row['image'] === '' ) return null;
    $list = $row['regular'] !== null ? $row['regular'] : $row['price'];

    $item = array(
        'item_id'     => 'pps-' . (int) $row['id'],
        'title'       => pps_openai_feed_clip( pps_openai_feed_text( $row['title'] ), 150 ),
        'description' => pps_openai_feed_description( $row['title'],
                            $row['short'] !== '' ? $row['short'] : $row['description'], $row['defaults'], $s ),
        'url'         => $row['url'],
        'image_url'   => $row['image'],
        'price'       => pps_openai_feed_money( $list, $s['currency'] ),
    ) + pps_openai_feed_common( $s );

    if ( $row['sale'] !== null && $row['sale'] < $list ) {
        $item['sale_price'] = pps_openai_feed_money( $row['sale'], $s['currency'] );
    }
    if ( $row['gallery'] ) {
        $item['additional_image_urls'] = implode( ',', array_slice( $row['gallery'], 0, 10 ) );
    }
    $item['product_category'] = pps_openai_feed_category( $s, $row['categories'] );
    // The spec: an mpn is assigned, never invented. Only a real SKU goes out
    // (the Google feed's PPS-<id> fallback is a Google identifier rule).
    if ( $row['sku'] !== '' ) $item['mpn'] = $row['sku'];
    return $item;
}

/**
 * The price a preset's own page publishes in its Product schema, or null.
 * Same resolution as the preset head emitter: override `offer_price`, else
 * `price_from`. Blank is "no price", as there.
 */
function pps_openai_feed_preset_price( array $preset ) {
    $ovr = isset( $preset['overrides'] ) && is_array( $preset['overrides'] ) ? $preset['overrides'] : array();
    $p = ( isset( $ovr['offer_price'] ) && $ovr['offer_price'] !== '' ) ? $ovr['offer_price']
       : ( ( isset( $preset['price_from'] ) && $preset['price_from'] !== '' ) ? $preset['price_from'] : null );
    if ( $p === null || ! is_numeric( $p ) ) return null;
    $p = (float) $p;
    return ( $p > 0 && $p < 1000000 ) ? round( $p, 2 ) : null;
}

/**
 * One preset as an OpenAI item, or ['skip' => reason].
 *
 * Image: the preset's own (its og_image override, then `image`), else the
 * featured image of the product it sells through — never the site logo, which
 * is what the page's og:image falls back to and which is not a product photo.
 */
function pps_openai_feed_preset_item( array $preset, array $s ) {
    $slug = (string) ( $preset['slug'] ?? '' );
    if ( $slug === '' || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) return array( 'skip' => 'bad slug' );
    $file = function_exists( 'pps_get_filename_for_calc_type' ) ? pps_get_filename_for_calc_type( (string) ( $preset['calc'] ?? '' ) ) : '';
    if ( $file === '' ) return array( 'skip' => 'unknown calculator "' . ( $preset['calc'] ?? '' ) . '"' );

    $price = pps_openai_feed_preset_price( $preset );
    if ( $price === null ) return array( 'skip' => 'no price on its page (set "Price from" on the preset)' );

    $ovr   = isset( $preset['overrides'] ) && is_array( $preset['overrides'] ) ? $preset['overrides'] : array();
    $pid   = function_exists( 'pps_preset_product_id' ) ? pps_preset_product_id( $preset, $file ) : 0;
    $image = (string) ( ( isset( $ovr['og_image'] ) && $ovr['og_image'] !== '' ) ? $ovr['og_image'] : ( $preset['image'] ?? '' ) );
    if ( $image === '' && $pid ) $image = (string) get_the_post_thumbnail_url( $pid, 'full' );
    if ( $image === '' ) return array( 'skip' => 'no image (none on the preset or its product)' );

    $title = (string) ( ( isset( $ovr['schema_name'] ) && $ovr['schema_name'] !== '' ) ? $ovr['schema_name'] : ( $preset['title'] ?? '' ) );
    if ( trim( $title ) === '' ) return array( 'skip' => 'no title' );
    $defaults = isset( $preset['defaults'] ) && is_array( $preset['defaults'] ) ? $preset['defaults'] : array();

    $item = array(
        'item_id'     => 'pps-preset-' . $slug,
        'title'       => pps_openai_feed_clip( pps_openai_feed_text( $title ), 150 ),
        'description' => pps_openai_feed_description( $title, (string) ( $preset['description'] ?? '' ), $defaults, $s ),
        'url'         => pps_get_preset_url( $slug ),
        'image_url'   => $image,
        'price'       => pps_openai_feed_money( $price, $s['currency'] ),
    ) + pps_openai_feed_common( $s );
    // No group_id: the spec keeps it for variants (with a variant_dict) of one
    // product as the site presents it, and a preset is its own page.
    $cats = array();
    if ( $pid ) {
        $terms = get_the_terms( $pid, 'product_cat' );
        if ( is_array( $terms ) ) foreach ( $terms as $t ) $cats[] = $t->name;
    }
    $item['product_category'] = pps_openai_feed_category( $s, $cats );
    return $item;
}

/**
 * Everything: items, and an account of what was left out and why.
 *
 * Catalog rows go through pps_catalog_report() with the Google feed's three
 * requirements, so the two feeds hold the same products.
 *
 * @return array{items:array,skipped:array,warnings:array}
 */
function pps_openai_feed_build() {
    $s = pps_openai_feed_settings();
    $items = array(); $skipped = array(); $warnings = array();

    $report = pps_catalog_report( array(
        'require_price' => true, 'require_image' => true, 'require_virtual' => true,
    ) );
    foreach ( $report['rows'] as $row ) {
        // A contract product (a `_pps_contract` price list, e.g. Armadillo) is sold
        // to one customer at agreed prices; its public price is a placeholder.
        // It stays out of a public catalog — the only difference from the Google
        // feed's item set, and the test holds it to exactly that.
        if ( pps_openai_feed_is_contract( $row['id'] ) ) {
            $skipped[] = array( 'id' => 'pps-' . $row['id'], 'title' => $row['title'], 'reason' => 'contract product (one customer\'s price list)' );
            continue;
        }
        $it = pps_openai_feed_product_item( $row, $s );
        if ( $it ) $items[] = $it;
    }
    foreach ( $report['skipped'] as $sk ) {
        $skipped[] = array( 'id' => 'pps-' . $sk['id'], 'title' => $sk['title'] ?? '', 'reason' => $sk['reason'] );
    }
    foreach ( $report['warnings'] as $w ) {
        $warnings[] = array( 'id' => 'pps-' . $w['id'], 'title' => $w['title'] ?? '', 'note' => $w['note'] );
    }

    if ( $s['presets'] && function_exists( 'pps_get_presets' ) ) {
        foreach ( pps_get_presets() as $key => $preset ) {
            if ( ! is_array( $preset ) ) continue;
            if ( empty( $preset['slug'] ) ) $preset['slug'] = (string) $key;
            $it = pps_openai_feed_preset_item( $preset, $s );
            if ( isset( $it['skip'] ) ) {
                $skipped[] = array( 'id' => 'pps-preset-' . $preset['slug'], 'title' => $preset['title'] ?? '', 'reason' => $it['skip'] );
            } else {
                $items[] = $it;
            }
        }
    }

    // Each problem the linter finds is a warning, never a silent drop; an item
    // missing a REQUIRED field is dropped, because OpenAI rejects it anyway.
    $kept = array();
    foreach ( $items as $it ) {
        $p = pps_openai_feed_lint( $it );
        if ( $p ) {
            $skipped[] = array( 'id' => $it['item_id'], 'title' => $it['title'], 'reason' => implode( '; ', $p ) );
            continue;
        }
        $kept[] = $it;
    }

    return array( 'items' => $kept, 'skipped' => $skipped, 'warnings' => $warnings );
}

/* ─────────────────────────────────────────────────────────────
 * The spec, as a linter. tools-openai-feed-test.php runs it on every row,
 * and on a feed fetched from the live site.
 * ───────────────────────────────────────────────────────────── */

function pps_openai_feed_required_fields() {
    return array( 'item_id', 'title', 'description', 'url', 'brand', 'seller_name', 'image_url', 'price', 'availability' );
}

/** @return string[] problems; empty = the row meets the spec as we read it. */
function pps_openai_feed_lint( array $it ) {
    $p = array();
    foreach ( pps_openai_feed_required_fields() as $f ) {
        if ( ! isset( $it[ $f ] ) || ! is_string( $it[ $f ] ) || trim( $it[ $f ] ) === '' ) $p[] = "missing {$f}";
    }
    if ( $p ) return $p;

    if ( strlen( $it['item_id'] ) > 100 || ! preg_match( '/^[A-Za-z0-9._-]+$/', $it['item_id'] ) ) $p[] = 'item_id not a plain string of up to 100 characters';
    if ( mb_strlen( $it['title'] ) > 150 ) $p[] = 'title over 150 characters';
    if ( mb_strlen( $it['description'] ) > 5000 ) $p[] = 'description over 5000 characters';
    if ( $it['description'] !== strip_tags( $it['description'] ) ) $p[] = 'description carries HTML';
    foreach ( array( 'url', 'image_url' ) as $f ) {
        if ( stripos( $it[ $f ], 'https://' ) !== 0 ) $p[] = "{$f} is not https";
    }
    foreach ( array( 'price', 'sale_price' ) as $f ) {
        if ( isset( $it[ $f ] ) && ! preg_match( '/^\d+\.\d{2} [A-Z]{3}$/', $it[ $f ] ) ) $p[] = "{$f} is not \"12.34 USD\"";
    }
    if ( isset( $it['sale_price'] ) && (float) $it['sale_price'] >= (float) $it['price'] ) $p[] = 'sale_price not below price';
    if ( ! in_array( $it['availability'], array( 'in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown' ), true ) ) $p[] = 'availability not an allowed value';
    if ( ( $it['is_eligible_checkout'] ?? false ) !== false ) $p[] = 'checkout must stay off';
    if ( isset( $it['shipping'] ) && isset( $it['shipping_price'] ) ) $p[] = 'shipping and shipping_price both set (one charge, one field)';
    if ( isset( $it['shipping_price'] ) && ! preg_match( '/^\d+\.\d{2} [A-Z]{3}$/', $it['shipping_price'] ) ) $p[] = 'shipping_price is not "0.00 USD"';
    if ( isset( $it['group_id'] ) && $it['group_id'] === $it['item_id'] ) $p[] = 'group_id equals item_id';
    foreach ( $it as $k => $v ) {
        if ( is_string( $v ) && in_array( strtolower( trim( $v ) ), array( 'null', 'n/a' ), true ) ) $p[] = "{$k} is a placeholder";
    }
    return $p;
}

/* ─────────────────────────────────────────────────────────────
 * Output
 * ───────────────────────────────────────────────────────────── */

function pps_openai_feed_jsonl( array $rows ) {
    $out = '';
    foreach ( $rows as $r ) $out .= json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    return $out;
}

function pps_openai_feed_csv( array $rows ) {
    $cols = array();
    foreach ( $rows as $r ) foreach ( array_keys( $r ) as $k ) $cols[ $k ] = true;
    $cols = array_keys( $cols );
    $fh = fopen( 'php://temp', 'w+' );
    fputcsv( $fh, $cols, ',', '"', '' );
    foreach ( $rows as $r ) {
        $line = array();
        foreach ( $cols as $c ) {
            $v = $r[ $c ] ?? '';
            $line[] = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v;
        }
        fputcsv( $fh, $line, ',', '"', '' );
    }
    rewind( $fh );
    $csv = stream_get_contents( $fh );
    fclose( $fh );
    return $csv;
}

/** Admin-only diagnostic: what is in, what is out and why. */
function pps_openai_feed_debug() {
    $b = pps_openai_feed_build();
    $s = pps_openai_feed_settings();
    $o = "PPS OpenAI product feed — diagnostic\n" . str_repeat( '=', 60 ) . "\n\n";
    $o .= 'Feed URLs: ' . home_url( '/' . PPS_OPENAI_FEED_SLUG . '.jsonl' ) . "\n           "
        . home_url( '/' . PPS_OPENAI_FEED_SLUG . '.csv' ) . "\n";
    $o .= 'Presets:   ' . ( $s['presets'] ? 'included' : 'left out (option pps_openai_feed.presets)' ) . "\n\n";
    $o .= 'IN THE FEED (' . count( $b['items'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    foreach ( $b['items'] as $it ) {
        $o .= sprintf( "  %-34s %-40s %s%s\n", $it['item_id'], pps_openai_feed_clip( $it['title'], 38 ), $it['price'],
                       isset( $it['sale_price'] ) ? ' (sale ' . $it['sale_price'] . ')' : '' );
    }
    $o .= "\nLEFT OUT (" . count( $b['skipped'] ) . ")\n" . str_repeat( '-', 60 ) . "\n";
    foreach ( $b['skipped'] as $sk ) $o .= sprintf( "  %-34s %-30s %s\n", $sk['id'], pps_openai_feed_clip( $sk['title'], 28 ), $sk['reason'] );
    if ( $b['warnings'] ) {
        $o .= "\nWORTH A LOOK — these ARE in the feed\n" . str_repeat( '-', 60 ) . "\n";
        foreach ( $b['warnings'] as $w ) $o .= sprintf( "  %-14s %s\n", $w['id'], $w['note'] );
    }
    return $o;
}

/* Merchant crawlers respect robots.txt; make sure nothing blocks the feed. */
add_filter( 'robots_txt', function ( $output ) {
    if ( strpos( $output, PPS_OPENAI_FEED_SLUG ) === false ) {
        $output .= "\n# ChatGPT (OpenAI) product feed\nAllow: /" . PPS_OPENAI_FEED_SLUG . ".jsonl\nAllow: /" . PPS_OPENAI_FEED_SLUG . ".csv\n";
    }
    return $output;
}, 21, 1 );
