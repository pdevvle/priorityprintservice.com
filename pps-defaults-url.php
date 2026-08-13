<?php
/**
 * PPS Defaults-from-URL — turn a shared quote link into a defaults blob.
 *
 * The calculators' "Copy link" builds a flat query string of the current
 * configuration (see the share-link builder in each calc, and the matching
 * read-back block commented "overridden by URL params (share link)"). This
 * file is the server-side counterpart: paste that link into a product (or a
 * preset) and get the same configuration back as a `_pps_defaults` blob.
 *
 * Why this instead of hand-writing JSON: the operator configures the job
 * visually in the calculator — where the paper values, the ×-sign in size
 * labels and the exact enum spellings are guaranteed correct — copies the
 * link, and pastes. No guessing field names, no `x` vs `×`, no wondering
 * whether a paper is `0.003` or `"100lb Gloss Text"`.
 *
 * SAFETY: the param map below IS the whitelist. Anything not in it is
 * ignored and reported back to the operator, so a pasted URL can never
 * inject arbitrary keys into product meta.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Share-link param → defaults key, with the cast the calculator itself applies.
 *
 * Extracted from the calculators' own read-back blocks so the two stay in
 * step. Covers the booklet family (saddle / perfect-bound / coupon), which
 * are the three calculators that currently emit a share link. The five flat
 * calculators have no "Copy link" yet — when they get one, add their params
 * here and this reader serves them with no other change.
 *
 * @return array param => [ defaults_key, type ]
 */
function pps_defaults_param_map() {
    return apply_filters( 'pps_defaults_param_map', array(
        // Identity / size
        'size'       => array( 'sizeLabel',       'str'   ),
        'long'       => array( 'customLong',      'float' ),
        'short'      => array( 'customShort',     'float' ),
        'qty'        => array( 'qty',             'int'   ),
        'pages'      => array( 'pages',           'int'   ),
        'bind'       => array( 'bindDir',         'str'   ),
        // Colour
        'color'      => array( 'insideColor',     'str'   ),
        'covercolor' => array( 'coverColor',      'str'   ),
        'frontcolor' => array( 'frontColor',      'str'   ),
        'backcolor'  => array( 'backColor',       'str'   ),
        // Paper. paperval/coverval stay strings — the calculator compares
        // them loosely against the config rows, so casting would break the match.
        'paper'      => array( 'insidePaperType', 'str'   ),
        'paperval'   => array( 'insidePaper',     'str'   ),
        'covermode'  => array( 'coverMode',       'str'   ),
        'coverpaper' => array( 'coverPaperType',  'str'   ),
        'coverval'   => array( 'coverPaper',      'str'   ),
        // Finishing
        'staple'     => array( 'twoStaple',       'bool'  ),
        'vivid'      => array( 'vividPrint',      'bool'  ),
        'coating'    => array( 'coating',         'float' ),
        'bundling'   => array( 'bundling',        'float' ),
        'corner'     => array( 'roundCorner',     'float' ),
        'perf'       => array( 'perforation',     'float' ),
        // Artwork & proofing
        'artwork'    => array( 'artwork',         'float' ),
        'artedit'    => array( 'artEditPages',    'int'   ),
        'bleed'      => array( 'bleed',           'float' ),
        'proof'      => array( 'proof',           'float' ),
        'canva'      => array( 'canvaLink',       'str'   ),
        // Destination
        'state'      => array( 'shipState',       'str'   ),

        // ── Flat family (brochure / postcard / letterhead / greeting card /
        // sticker). Their share builders emit these; note `size` and `long`/
        // `short` are shared with the booklets above, and `sizemode` decides
        // which the calculator honours.
        'sizemode'   => array( 'sizeMode',        'str'   ),
        'fold'       => array( 'foldType',        'str'   ),
        'folddir'    => array( 'foldDir',         'str'   ),
        'sides'      => array( 'sides',           'str'   ),
        'coatsides'  => array( 'coatSides',       'str'   ),
        'perfdir'    => array( 'perfDir',         'str'   ),
        'perfpos'    => array( 'perfPositions',   'str'   ),
    ) );
}

/**
 * Params the calculators emit that are deliberately NOT defaults — so they
 * are skipped silently rather than reported as unknown.
 */
function pps_defaults_ignored_params() {
    return array( 'debug', 'pps_reorder', 'pps_edit_key', 'utm_source', 'utm_medium',
                  'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' );
}

/**
 * Parse a shared quote link into a defaults blob.
 *
 * Accepts a full URL, a bare query string, or a leading-`?` fragment — an
 * operator pasting from the address bar should not have to think about it.
 *
 * @param string $url
 * @return array {
 *   @type array       $defaults  Mapped, cast, sanitised defaults (may be empty).
 *   @type float|null  $price     Quoted total, if the link carried one (`q` or `price`).
 *   @type array       $unknown   Param names present but not in the map.
 *   @type string      $error     Non-empty when the input could not be parsed at all.
 * }
 */
function pps_defaults_from_url( $url ) {
    $out = array( 'defaults' => array(), 'price' => null, 'unknown' => array(), 'error' => '' );

    $url = trim( (string) $url );
    if ( $url === '' ) return $out;

    // Full URL, bare query string, or "?a=b" — normalise to a query string.
    $qs = '';
    if ( strpos( $url, '?' ) !== false ) {
        $qs = substr( $url, strpos( $url, '?' ) + 1 );
    } elseif ( strpos( $url, '=' ) !== false && strpos( $url, ' ' ) === false ) {
        $qs = $url; // looks like a bare query string
    }
    // Drop any fragment.
    if ( ( $hash = strpos( $qs, '#' ) ) !== false ) $qs = substr( $qs, 0, $hash );

    if ( $qs === '' ) {
        $out['error'] = 'No query string found in that link — copy the full URL from the calculator\'s "Copy link" button.';
        return $out;
    }

    $params = array();
    parse_str( $qs, $params );
    if ( empty( $params ) || ! is_array( $params ) ) {
        $out['error'] = 'Could not read any settings from that link.';
        return $out;
    }

    $map     = pps_defaults_param_map();
    $ignored = pps_defaults_ignored_params();

    foreach ( $params as $key => $raw ) {
        $key = (string) $key;
        if ( in_array( $key, $ignored, true ) ) continue;

        // Quoted total, if the link carries one. Display/schema only — the
        // charged price is always recomputed at add-to-cart.
        if ( $key === 'q' || $key === 'price' ) {
            $p = is_scalar( $raw ) ? floatval( $raw ) : 0;
            if ( $p > 0 && $p < 1000000 ) $out['price'] = round( $p, 2 );
            continue;
        }

        if ( ! isset( $map[ $key ] ) ) {
            $out['unknown'][] = $key;
            continue;
        }
        if ( ! is_scalar( $raw ) ) continue;

        list( $field, $type ) = $map[ $key ];
        $raw = (string) $raw;

        switch ( $type ) {
            case 'int':
                if ( $raw === '' || ! is_numeric( $raw ) ) continue 2;
                $val = intval( $raw );
                break;
            case 'float':
                if ( $raw === '' || ! is_numeric( $raw ) ) continue 2;
                $val = floatval( $raw );
                break;
            case 'bool':
                $val = ( $raw === 'true' || $raw === '1' );
                break;
            default:
                // Empty strings are meaningful absence, not a value to store.
                if ( $raw === '' ) continue 2;
                $val = $raw;
        }
        $out['defaults'][ $field ] = $val;
    }

    // Same hardening the admin form uses: charset-restricted keys with case
    // preserved, 200-key cap, recursive.
    if ( $out['defaults'] && function_exists( 'pps_sanitize_defaults_blob' ) ) {
        $out['defaults'] = pps_sanitize_defaults_blob( $out['defaults'] );
    }

    $out['unknown'] = array_values( array_unique( $out['unknown'] ) );
    return $out;
}

/**
 * Human-readable one-liner describing what a parse produced. Used for the
 * admin notice after a paste, so the operator sees what landed rather than
 * having to diff the form.
 */
function pps_defaults_url_summary( array $parsed ) {
    if ( ! empty( $parsed['error'] ) ) return $parsed['error'];
    $n = count( $parsed['defaults'] );
    if ( ! $n ) return 'No recognised settings in that link.';
    $bits = array( sprintf( 'Applied %d setting%s from the quote link', $n, $n === 1 ? '' : 's' ) );
    if ( $parsed['price'] !== null ) {
        $bits[] = sprintf( 'quoted total $%s', number_format( $parsed['price'], 2 ) );
    }
    if ( ! empty( $parsed['unknown'] ) ) {
        $bits[] = 'ignored unknown: ' . implode( ', ', array_slice( $parsed['unknown'], 0, 8 ) );
    }
    return implode( ' · ', $bits );
}
