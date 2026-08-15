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
 * The interesting part is not the enumeration; it is `price_ok`. See
 * pps_catalog_row() for why parity and meaningfulness are two different tests.
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
 * When the quote behind the defaults and the WooCommerce price disagree,
 * `price_ok` is false and the product is withheld. Drift is a data error, and
 * picking a side would advertise a number the other half of the site
 * contradicts. `price_drift` stays true on the row so the operator can be told
 * precisely what to fix.
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
 *   @type bool $require_virtual Drop rows not marked virtual (owner rule 2026-07-19).
 * }
 * @return array{rows:array,skipped:array,collisions:array}
 */
function pps_catalog_report( array $args = array() ) {
    $args = array_merge( array(
        'require_price'   => false,
        'require_image'   => false,
        'require_virtual' => false,
    ), $args );

    $rows = array();
    $skipped = array();
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

            if ( $args['require_price'] && ! $row['price_ok'] ) {
                if ( $row['price_drift'] ) {
                    // The dangerous case. Both numbers exist and disagree, so
                    // the schema advertises one and WooCommerce renders the
                    // other. Publishing either is a guaranteed mismatch.
                    $reason = sprintf(
                        'price conflict — quoted at $%s for its defaults but the product price is $%s; '
                        . 'the Product schema publishes one and the page the other. Re-paste the quote link, '
                        . 'or set the product price to match.',
                        number_format( $row['quoted'], 2 ), number_format( $row['regular'], 2 ) );
                } elseif ( $row['quoted'] === null ) {
                    $reason = 'no "Price at these defaults" set — nothing to advertise, '
                            . 'and the Product schema is falling back to its $50 placeholder';
                } else {
                    $reason = 'no price on the product';
                }
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'], 'reason' => $reason,
                );
                continue;
            }
            if ( $args['require_image'] && $row['image'] === '' ) {
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'],
                    'reason' => 'no featured image',
                );
                continue;
            }
            if ( $args['require_virtual'] && ! $row['virtual'] ) {
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'],
                    'reason' => 'not marked virtual — PPS owns shipping, so this must be a virtual product',
                );
                continue;
            }

            $rows[ $id ] = apply_filters( 'pps_catalog_row', $row, $id, $filename );
        }
    }

    return array( 'rows' => $rows, 'skipped' => $skipped, 'collisions' => $collisions );
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
