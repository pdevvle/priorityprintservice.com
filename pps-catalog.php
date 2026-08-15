<?php
/**
 * PPS Catalog — the one place that answers "which products do the calculators own?"
 *
 * Before this file that question had no answer. What existed instead was the
 * registry's ID string being re-parsed inline wherever somebody needed it
 * (pps-calculators.php:242, :448, :1102), each copy with its own idea of
 * whether a product was worth counting. Three consumers now want the same
 * answer — the Merchant Center feed, llms.txt, and the admin diagnostics — so
 * it lives here once.
 *
 * The interesting part is not the enumeration; it is the price. See
 * pps_catalog_row() for what Google actually compares, and why that turns out
 * to need one shared resolver rather than a pile of rules.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * pps_registry_product_ids() deliberately lives in pps-calculators.php, not
 * here: three core call sites use it, and this file is loaded behind a
 * file_exists guard. Declaring it in both would be a redeclaration fatal.
 */

/**
 * Every product ID the registry claims, mapped to its calculator filename.
 *
 * A product assigned to two calculators is a configuration error; first
 * registry row wins and the collision is reported by pps_catalog_report().
 *
 * @return array<int,string> product_id => calculator filename
 */
function pps_catalog_registry_map() {
    $map = array();
    foreach ( pps_get_registry() as $filename => $meta ) {
        if ( ! is_array( $meta ) ) continue;
        foreach ( pps_registry_product_ids( $meta['products'] ?? '' ) as $id ) {
            if ( ! isset( $map[ $id ] ) ) $map[ $id ] = $filename;
        }
    }
    return $map;
}

/**
 * Build one catalog row, or return a skip reason.
 *
 * ── On price, which is the whole reason this function is careful ──
 *
 * The intuition that Merchant Center somehow drives the calculator to find a
 * price is wrong, and getting it wrong produces exactly the bug it is trying
 * to avoid. Google reads the landing page's **structured data**. So the check
 * that actually runs is:
 *
 *      feed g:price   ==   the Product schema's offer price
 *
 * and the schema's price comes from pps_product_defaults_low_price(), which
 * reads _pps_defaults_price — not WooCommerce's price element. Publishing
 * $product->get_price() therefore does NOT give parity; it gives parity with
 * the number a human sees while silently contradicting the number the crawler
 * reads.
 *
 * Hence pps_product_price_facts(): one resolver, called by both the schema and
 * the feed, so they cannot diverge. It also separates the list price from an
 * active sale price, which get_price() collapses.
 *
 * With that in place the consistency problem is solved at the source, and
 * `price_ok` reduces to "is there a price". The product's own price goes out,
 * high or low — owner's call, 2026-08-15. `price_drift` is still computed, but
 * it is a housekeeping note for the operator, not a veto.
 *
 * @param int    $id
 * @param string $filename Registry key that claimed this ID.
 * @return array Row on success, or ['skip' => reason-string].
 */
function pps_catalog_row( $id, $filename ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return array( 'skip' => 'WooCommerce not active' );
    }

    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'product' ) {
        return array( 'skip' => 'no such product (stale registry entry)' );
    }
    if ( $post->post_status !== 'publish' ) {
        return array( 'skip' => 'not published (' . $post->post_status . ')' );
    }

    $product = wc_get_product( $id );
    if ( ! $product ) {
        return array( 'skip' => 'product could not be loaded' );
    }

    // One resolver, shared with the Product schema — see
    // pps_product_price_facts() for why publishing anything else is a
    // disapproval waiting to happen.
    $pf = pps_product_price_facts( $id );

    // Drift means the quote behind the defaults and the price WooCommerce
    // renders disagree. Publishing either one advertises a number the other
    // half of the site contradicts, so the product is withheld instead.
    $price_drift = ( $pf['quoted'] !== null && $pf['regular'] !== null && ! $pf['agrees'] );

    $defaults = get_post_meta( $id, '_pps_defaults', true );
    if ( ! is_array( $defaults ) ) $defaults = array();

    // Shortcodes first: stripping tags first would leave bare [shortcode]
    // text behind in the description.
    $description = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ), true );
    $description = trim( preg_replace( '/\s+/u', ' ', $description ) );

    $short = wp_strip_all_tags( strip_shortcodes( (string) $post->post_excerpt ), true );
    $short = trim( preg_replace( '/\s+/u', ' ', $short ) );

    $image   = (string) get_the_post_thumbnail_url( $id, 'full' );
    $gallery = array();
    foreach ( $product->get_gallery_image_ids() as $att_id ) {
        $src = wp_get_attachment_image_url( $att_id, 'full' );
        if ( $src ) $gallery[] = $src;
    }

    $categories = array();
    $terms = get_the_terms( $id, 'product_cat' );
    if ( is_array( $terms ) ) {
        foreach ( $terms as $t ) $categories[] = $t->name;
    }

    return array(
        'id'          => (int) $id,
        'file'        => $filename,
        'calc'        => function_exists( 'pps_get_calc_type_for_filename' )
                         ? pps_get_calc_type_for_filename( $filename ) : '',
        'product'     => $product,
        'title'       => (string) $post->post_title,
        'url'         => (string) get_permalink( $id ),
        'description' => $description,
        'short'       => $short,
        'price'       => $pf['effective'],  // what a customer pays today
        'regular'     => $pf['regular'],
        'sale'        => $pf['sale'],       // null unless a sale is live
        'quoted'      => $pf['quoted'],     // what the defaults were quoted at
        'price_ok'    => $pf['publishable'],
        'price_drift' => $price_drift,
        'virtual'     => $product->is_virtual(),
        'image'       => $image,
        'gallery'     => $gallery,
        'categories'  => $categories,
        'defaults'    => $defaults,
        'modified'    => (string) $post->post_modified_gmt,
    );
}

/**
 * The catalog, with an account of everything left out.
 *
 * Consumers that publish (the feed) need the skip list as much as the rows —
 * an invisible exclusion turns "why isn't my product in Shopping?" into an
 * unanswerable question.
 *
 * @param array $args {
 *   @type bool $require_price  Drop rows without a usable, meaningful price.
 *   @type bool $require_image  Drop rows without a featured image.
 *   @type bool $require_virtual Report rows not marked virtual as a warning
 *                                (owner rule 2026-07-19). Does not drop them.
 * }
 * @return array{rows:array,skipped:array,warnings:array,collisions:array}
 */
function pps_catalog_report( array $args = array() ) {
    $args = array_merge( array(
        'require_price'   => false,
        'require_image'   => false,
        'require_virtual' => false,
    ), $args );

    $rows = array();
    $skipped = array();
    $warnings = array();
    $collisions = array();
    $seen = array();

    foreach ( pps_get_registry() as $filename => $meta ) {
        if ( ! is_array( $meta ) ) continue;
        foreach ( pps_registry_product_ids( $meta['products'] ?? '' ) as $id ) {
            if ( isset( $seen[ $id ] ) ) {
                $collisions[] = array( 'id' => $id, 'files' => array( $seen[ $id ], $filename ) );
                continue;
            }
            $seen[ $id ] = $filename;

            $row = pps_catalog_row( $id, $filename );
            if ( isset( $row['skip'] ) ) {
                $skipped[] = array( 'id' => $id, 'file' => $filename, 'reason' => $row['skip'] );
                continue;
            }

            // Only two things keep a product out, and both are things Google
            // itself rejects outright: no price, and no image. Everything else
            // is a note, not a veto — a sellable product goes to Shopping with
            // whatever price it carries.
            if ( $args['require_price'] && ! $row['price_ok'] ) {
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'],
                    'reason' => 'no price set on the product',
                );
                continue;
            }
            if ( $args['require_image'] && $row['image'] === '' ) {
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'],
                    'reason' => 'no featured image — Google will not list a product without one',
                );
                continue;
            }

            // Worth an operator's attention, never a reason to withhold.
            if ( $row['price_drift'] ) {
                $warnings[] = array(
                    'id' => $id, 'title' => $row['title'],
                    'note' => sprintf( 'publishing $%s; its saved quote says $%s. Not a problem for Google — '
                                     . 'both the feed and the schema publish the product price — but one of '
                                     . 'the two is out of date.',
                                       number_format( $row['price'], 2 ), number_format( $row['quoted'], 2 ) ),
                );
            }
            if ( $args['require_virtual'] && ! $row['virtual'] ) {
                $warnings[] = array(
                    'id' => $id, 'title' => $row['title'],
                    'note' => 'not marked virtual. Harmless for Shopping, but PPS owns shipping and '
                            . 'WooCommerce will try to charge its own on this one.',
                );
            }

            $rows[ $id ] = apply_filters( 'pps_catalog_row', $row, $id, $filename );
        }
    }

    return array( 'rows' => $rows, 'skipped' => $skipped,
                  'warnings' => $warnings, 'collisions' => $collisions );
}

/**
 * Just the rows. Use pps_catalog_report() when you need to explain omissions.
 *
 * @param array $args Same shape as pps_catalog_report().
 * @return array<int,array>
 */
function pps_catalog( array $args = array() ) {
    $r = pps_catalog_report( $args );
    return $r['rows'];
}

/* ─────────────────────────────────────────────────────────────
 * Lowest achievable price, measured by tools-min-price.mjs
 * ───────────────────────────────────────────────────────────── */

/**
 * Fingerprint of the pricing constants currently in play.
 *
 * Computed on the PHP side at both import and read time, so the comparison is
 * always PHP-to-PHP — never against the tool's own hash, which would differ on
 * key order and escaping alone and report every sweep as stale.
 */
function pps_pricing_fingerprint() {
    if ( ! function_exists( 'pps_get_public_config' ) ) return '';
    return md5( (string) wp_json_encode( pps_get_public_config() ) );
}

/**
 * The stored sweep: what tools-min-price.mjs measured, plus the fingerprint of
 * the pricing config it was measured against.
 */
function pps_min_prices() {
    $v = get_option( 'pps_min_prices', array() );
    if ( is_string( $v ) ) {
        $d = json_decode( $v, true );
        $v = is_array( $d ) ? $d : array();
    }
    return is_array( $v ) ? $v : array();
}

/**
 * Store a sweep, stamping it with the pricing config it describes.
 *
 * @param array $calculators  filename => ['min_total' => float, 'at_qty' => int, ...]
 */
function pps_save_min_prices( array $calculators ) {
    update_option( 'pps_min_prices', array(
        'calculators'  => $calculators,
        'fingerprint'  => pps_pricing_fingerprint(),
        'imported_at'  => current_time( 'mysql' ),
    ), false );
}

/**
 * Has pricing changed since the sweep ran?
 *
 * This is the guard that keeps a "from $X" honest. Central Config edits change
 * what the calculators quote, and a stored minimum has no way of noticing —
 * it would go on advertising a price the engine no longer produces. When this
 * returns true, callers publish nothing rather than publish a stale number.
 */
function pps_min_prices_are_stale() {
    $m = pps_min_prices();
    if ( empty( $m['fingerprint'] ) ) return true;
    $now = pps_pricing_fingerprint();
    if ( $now === '' ) return true;          // cannot verify ⇒ do not trust
    return ! hash_equals( (string) $m['fingerprint'], $now );
}

/**
 * Lowest price a calculator can quote, or null when unknown or stale.
 *
 * Deliberately per-calculator rather than per-product: PPS_CONFIG.defaults
 * pre-fills the form, it does not restrict it, so a customer on any product
 * page can configure down to the same floor.
 *
 * NOT for g:price or the Product schema's offer — see pps_product_price_facts().
 * This is the "from $X" figure for marketing surfaces that carry no
 * price-match obligation: category cards, llms.txt, ad copy.
 *
 * @param string $filename Calculator HTML filename.
 * @return float|null
 */
function pps_calc_min_price( $filename ) {
    if ( pps_min_prices_are_stale() ) return null;
    $m = pps_min_prices();
    $key = preg_replace( '/\.html$/', '', (string) $filename );
    $row = $m['calculators'][ $key ] ?? $m['calculators'][ $filename ] ?? null;
    if ( ! is_array( $row ) ) return null;
    $p = isset( $row['min_total'] ) ? (float) $row['min_total'] : 0;
    return ( $p > 0 && $p < 1000000 ) ? round( $p, 2 ) : null;
}

/**
 * A one-line spec string built from a product's calculator defaults.
 *
 * Used by llms.txt and the feed description. Reads the same `_pps_defaults`
 * vocabulary the calculators use, so it stays correct as defaults change.
 *
 * @param array $defaults
 * @return string e.g. "5.5×8.5, 24 pages, full colour, 100lb Gloss Text"
 */
function pps_catalog_spec_line( array $defaults ) {
    if ( ! $defaults ) return '';
    $bits = array();

    $size = trim( (string) ( $defaults['sizeLabel'] ?? '' ) );
    if ( $size === '' && isset( $defaults['customLong'], $defaults['customShort'] ) ) {
        $size = rtrim( rtrim( (string) $defaults['customShort'], '0' ), '.' ) . '×'
              . rtrim( rtrim( (string) $defaults['customLong'], '0' ), '.' ) . '"';
    }
    if ( $size !== '' ) $bits[] = $size;

    if ( ! empty( $defaults['pages'] ) )  $bits[] = intval( $defaults['pages'] ) . ' pages';
    if ( ! empty( $defaults['qty'] ) )    $bits[] = 'from ' . intval( $defaults['qty'] ) . ' copies';

    $color = (string) ( $defaults['insideColor'] ?? $defaults['frontColor'] ?? '' );
    if ( $color !== '' ) {
        $bits[] = ( stripos( $color, 'grey' ) === 0 || $color === 'bw' ) ? 'greyscale' : 'full color';
    }

    $fold = trim( (string) ( $defaults['foldType'] ?? '' ) );
    if ( $fold !== '' && $fold !== 'flat' ) $bits[] = $fold . ' fold';

    return implode( ', ', $bits );
}
