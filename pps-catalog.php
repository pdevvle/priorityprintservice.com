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
 * Two different questions get confused with each other:
 *
 *   Parity        Does the number we publish match the number on the page?
 *                 Merchant Center crawls the landing page and disapproves the
 *                 item when they differ. Satisfied by construction: we publish
 *                 $product->get_price(), which IS what the page renders.
 *
 *   Meaningfulness Does that number correspond to the configuration the
 *                 calculator actually opens on? Only true when somebody set
 *                 _pps_defaults_price, which is written from a quote link and
 *                 therefore reflects a real quote for the real defaults.
 *
 * A product can pass the first and fail the second: a legacy _regular_price
 * left over from before the calculator owned the product renders on the page
 * and matches the feed, while quoting a figure no customer configuring the
 * defaults will ever see. That is not a Merchant Center violation, but it is a
 * bad listing, so `price_ok` requires both and the two values are reported
 * separately when they disagree.
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

    // What the page shows. Publishing anything else breaks parity.
    $page_price = $product->get_price();
    $page_price = ( $page_price === '' || $page_price === null ) ? null : (float) $page_price;

    // What somebody deliberately quoted for this product's own defaults.
    $quoted_raw   = get_post_meta( $id, '_pps_defaults_price', true );
    $quoted_price = ( $quoted_raw === '' || $quoted_raw === null ) ? null : (float) $quoted_raw;

    $price_ok = ( $page_price !== null && $page_price > 0 && $quoted_price !== null && $quoted_price > 0 );

    // Drift is worth surfacing even when both are present: it means the
    // defaults were re-quoted and the product price was not updated, or the
    // price was edited by hand afterwards.
    $price_drift = ( $price_ok && abs( $page_price - $quoted_price ) >= 0.01 );

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
        'price'       => $page_price,      // publish this one — parity
        'quoted'      => $quoted_price,    // what the defaults were quoted at
        'price_ok'    => $price_ok,
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
                $skipped[] = array(
                    'id' => $id, 'file' => $filename, 'title' => $row['title'],
                    'reason' => $row['price'] === null || $row['price'] <= 0
                        ? 'no price on the product'
                        : 'no "Price at these defaults" set — page price would be published without a quote behind it',
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
