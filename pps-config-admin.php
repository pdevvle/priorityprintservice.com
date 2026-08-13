<?php
/**
 * PPS Calculator Central Config Admin
 *
 * Stores all calculator-configurable data (papers, pricing constants,
 * transit days, shop settings, sizes, finishing options) in wp_options
 * and injects them into window.PPS_CONFIG.calc on product pages.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'PPS_CONFIG_OPTION' ) ) {
    define( 'PPS_CONFIG_OPTION', 'pps_calc_config' );
}
if ( ! defined( 'PPS_ADDONS_VISIBILITY_OPTION' ) ) {
    define( 'PPS_ADDONS_VISIBILITY_OPTION', 'pps_addons_visibility' );
}

// ═══════════════════════════════════════════════════════════════
// ADD-ON VISIBILITY — per-calc-type on/off for finishing add-ons
// ═══════════════════════════════════════════════════════════════
//
// Storage: wp_options['pps_addons_visibility'] = associative array keyed by
// add-on slug, then by calc type. Only calc types that actually support the
// add-on appear under each key. Defaults: every cell `true`.
//
// "Off" semantics: the add-on row is hidden from the calculator form, and
// if a pre-filled state (reorder/preset/edit) carries a non-default value
// for an off add-on, calculate() returns an error so no price displays
// until the user clears the option.

function pps_addon_visibility_matrix_defaults() {
    return array(
        // key          => array of calc-types it applies to
        'vivid'         => array( 'saddle', 'perfect-bound', 'brochure', 'coupon' ),
        'coating'       => array( 'saddle', 'perfect-bound', 'brochure', 'coupon', 'letterhead' ),
        'bundling'      => array( 'saddle', 'perfect-bound', 'brochure', 'coupon' ),
        'rc'            => array( 'saddle', 'perfect-bound', 'brochure', 'coupon' ),
        'two_staple'    => array( 'saddle' ),
        'perforation'   => array( 'perfect-bound', 'brochure', 'coupon' ),
        'outfold'       => array( 'perfect-bound', 'coupon' ),
    );
}

function pps_addon_labels() {
    return array(
        'vivid'       => 'Vivid Print',
        'coating'     => 'UV Coating',
        'bundling'    => 'Bundling',
        'rc'          => 'Round Cornering',
        'two_staple'  => 'Two-Staple',
        'perforation' => 'Perforation',
        'outfold'     => 'Outfold',
    );
}

function pps_get_addons_visibility() {
    $matrix = pps_addon_visibility_matrix_defaults();
    $saved  = get_option( PPS_ADDONS_VISIBILITY_OPTION, array() );
    if ( ! is_array( $saved ) ) $saved = array();

    $out = array();
    foreach ( $matrix as $addon => $calcs ) {
        $out[ $addon ] = array();
        foreach ( $calcs as $calc ) {
            $stored = isset( $saved[ $addon ][ $calc ] ) ? $saved[ $addon ][ $calc ] : true;
            $out[ $addon ][ $calc ] = ! empty( $stored );
        }
    }
    return $out;
}

/**
 * Resolve the visibility flags for a single calc type. Returns
 * { addon_slug => bool } only for add-ons that apply to the calc.
 * Calculator JS reads this directly via window.PPS_CONFIG.addons.
 */
function pps_get_addons_visibility_for_calc( $calc_type ) {
    $matrix = pps_addon_visibility_matrix_defaults();
    $vis    = pps_get_addons_visibility();
    $out    = array();
    foreach ( $matrix as $addon => $calcs ) {
        if ( in_array( $calc_type, $calcs, true ) ) {
            $out[ $addon ] = ! empty( $vis[ $addon ][ $calc_type ] );
        }
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════
// DEFAULT CONFIG (mirrors calculator hardcodes — single source of truth)
// ═══════════════════════════════════════════════════════════════

function pps_default_config() {
    return array(
        'pcf' => array(
            'printing_fullcolor_cost'           => 0.05,
            'printing_black_cost'               => 0.01,
            'labor_press_hr'                    => 35,
            'labor_bindery_hr'                  => 35,
            'labor_cutting_hr'                  => 45,
            'labor_horizonspf20_hr'             => 50,
            'labor_horizonspf20_setup'          => 35,
            'press_printsperhour'               => 600,
            'bindery_morgana_impressionperhour' => 750,
            'cutter_sheetsperhour'              => 15000,
            'horizonspf20_sheetsperhour'        => 4000,
            'cutterbasefee'                     => 7.5,
            'sheetsturnaround'                  => 2500,
            'backend_maximummarkup'             => 13,
            'backend_minimummarkup'             => 1.5,
            'backend_markup_coef_tS'            => 1.75,    // single-axis decay vs ln(pressSheets); floor cross @ ~2500 sheets
            'easydiscount_max'                  => 0,
            // Booklet-specific markup — single-axis (pages already drive tS, 2026-05-14)
            'booklet_maximummarkup'             => 3.6,
            'booklet_minimummarkup'             => 1.45,
            'booklet_markup_coef_tS'            => 0.295,
            'booklet_size_discount'             => 0,
            'booklet_surcharge'                 => 0.40,
            'booklet_8up_markup_bonus'          => 0.15,    // multiplier added to mk when imp >= 8 (small saddle sizes); affects materials/print only, not labor
            'booklet_cover_maximummarkup'       => 6.0,
            'booklet_cover_minimummarkup'       => 1.8,
            'booklet_cover_markup_coef'         => 0.38,
            // Perfect-bound markup — saddle-mirrored, slightly lower coefS to offset PB-only fixed adders
            'perfectbound_maximummarkup'        => 3.6,
            'perfectbound_minimummarkup'        => 1.45,
            'perfectbound_markup_coef_tS'       => 0.275,
            'perfectbound_size_discount'        => 0,
            'perfectbound_surcharge'            => 0.40,
            'perfectbound_cover_maximummarkup'  => 6.0,
            'perfectbound_cover_minimummarkup'  => 1.8,
            'perfectbound_cover_markup_coef'    => 0.38,
            'perfectbound_binder_throat_in'     => 13,      // binder clamp width; 2 spines gang into one bindQty pass only when 2×bindEdge fits this (2026-08-12)
            // Coupon book markup — saddle/PB-mirrored single-axis (2026-05-28)
            'couponbook_maximummarkup'          => 3.6,
            'couponbook_minimummarkup'          => 1.45,
            'couponbook_markup_coef_tS'         => 0.275,
            'couponbook_size_discount'          => 0,
            'couponbook_surcharge'              => 0.40,
            'magneticstripapplication_perhour'   => 120,
            'uvcoaterimpressionsperhour'        => 250,
            'roundcornerperhour'                => 75,
            'bundlesperhour'                    => 50,
            'addon_roundcornermaxdays'          => 30,
            'art_pagesperhour'                  => 8,
            'art_newdesignmodifier'             => 0.5,
            'sheetsforlowcosthardcopyproof'     => 1500,
            'minimum_turnaround_days'           => 3,
            'two_staple_threshold'              => 5.25,
            'non_inventory_fee'                 => 35,
            'backend_base_rate'                 => 10,    // flat per-order fee for brochures
            // Outfold (PB fold-out page tipped into the spine) — Tier C aggressive defaults
            'outfold_per_book_handling'         => 0.40,  // per-book hand-insertion labor ($/book/fold)
            'outfold_setup'                     => 35,    // flat setup per fold per run ($)
            'outfold_specialty_markup'          => 1.30,  // specialty multiplier on the outfold subtotal
            // Cart-price tampering defense (server-side floor on add-to-cart).
            // Submitted pps_price must be >= max(absolute_min, regular_price * min_pct).
            'pps_min_price_pct'                 => 0.5,
            'pps_absolute_min_price'            => 5,
            'bw_discount_rate'                  => 0.3,
            'easy_discount_rate'                => 0.05,
            'common_discount_max'               => 1500,
            'bundling_base_fee'                 => 7,
            'proof_hardcopy_cost'               => 35,
            'proof_digital_cost'                => 10,
            'sets_surcharge'                    => 20,
            'bleed_minimum'                     => 15,
            'default_transit_days'              => 4,
            'shop_timezone'                     => 'America/Phoenix',
            'shop_cutoff_hour'                  => 14,
            'free_shipping_buffer'              => 1.5,
            // Shippo integration (leave token empty to use static transit map)
            'shippo_api_token'                  => '',
            'shippo_origin_zip'                 => '85027',
            // Site-wide sale discount. 0 = sale off; >0 = subtotal multiplied by (1 - pct).
            // Excludes shipping, rush surcharge, and turnaround add-ons. Per-preset rows
            // can override these via the Presets admin (Sale fields).
            'sale_discount_pct'                 => 0,
            'sale_label'                        => 'Sale',
            // "Have a question?" form recipient. Empty falls back to admin_email.
            'question_recipient_email'          => '',
        ),

        'papers_nc' => array(
            array( 'label' => '70lb Uncoated Opaque Text',  'val' => 0.001, 'price' => 0.06,   'factory' => false, 'coatable' => false ),
            array( 'label' => '80lb Matte Text',            'val' => 0.002, 'price' => 0.069,  'factory' => false, 'coatable' => false ),
            array( 'label' => '100lb Gloss Text',           'val' => 0.003, 'price' => 0.085,  'factory' => false, 'coatable' => true ),
            array( 'label' => '60lb Offset Smooth Opaque',  'val' => 2.002, 'price' => 0.042,  'factory' => true,  'coatable' => false ),
            array( 'label' => '80lb Offset Smooth Opaque',  'val' => 2.003, 'price' => 0.056,  'factory' => true,  'coatable' => false ),
            array( 'label' => '80lb Gloss Factory Coated',  'val' => 2.004, 'price' => 0.0525, 'factory' => true,  'coatable' => true ),
            array( 'label' => '100lb Matte Factory Coated', 'val' => 2.005, 'price' => 0.070,  'factory' => true,  'coatable' => true ),
        ),

        'papers_cs' => array(
            array( 'label' => '80lb Opaque Uncoated',       'val' => 0.01,  'price' => 0.0919,   'factory' => false, 'coatable' => false ),
            array( 'label' => '80lb Matte Cardstock',       'val' => 0.02,  'price' => 0.1119,   'factory' => false, 'coatable' => true ),
            array( 'label' => '100lb Gloss Cardstock',      'val' => 0.03,  'price' => 0.1319,   'factory' => false, 'coatable' => true ),
            array( 'label' => '14pt Gloss C1S',             'val' => 1.01,  'price' => 0.1619,   'factory' => false, 'coatable' => true ),
            array( 'label' => '16pt Coated C2S',            'val' => 1.02,  'price' => 0.1919,   'factory' => false, 'coatable' => true ),
            array( 'label' => '80lb Gloss Factory Coated',  'val' => 2.21,  'price' => 0.13192,  'factory' => true,  'coatable' => true ),
            array( 'label' => '100lb Matte Factory Coated', 'val' => 2.22,  'price' => 0.145619, 'factory' => true,  'coatable' => true ),
            array( 'label' => '12pt C2S Factory Coated',    'val' => 2.23,  'price' => 0.155719, 'factory' => true,  'coatable' => true ),
            array( 'label' => '14pt C2S Factory Coated',    'val' => 2.24,  'price' => 0.169019, 'factory' => true,  'coatable' => true ),
            array( 'label' => '18pt C1S Factory Gloss',     'val' => 2.25,  'price' => 0.186719, 'factory' => true,  'coatable' => true ),
        ),

        'cover_same' => array( 'label' => 'Same as Inside Pages', 'val' => 0.001, 'price' => 0.01, 'factory' => false, 'coatable' => false ),

        'cover_scoring_vals' => array( 0.01, 0.02, 0.03, 1.01, 1.02, 2.21, 2.22, 2.23, 2.24, 2.25 ),
        'inv_nc'    => array( 0.001, 0.002, 0.003 ),
        'inv_cs'    => array( 0.01, 0.02, 0.03, 1.01, 1.02 ),
        // Papers stocked at the larger 13×27.5 press sheet (saddle stitch). Oversized
        // jobs (imp<1 on 13×19) must run here; non-stock papers incur non_inventory_fee.
        'large_sheet_vals' => array( 0.003, 0.03 ), // 100lb Gloss Text, 100lb Gloss Cardstock

        'coatings' => array(
            array( 'label' => 'No Additional Coating', 'val' => 0,   'price' => 0 ),
            array( 'label' => 'UV Gloss',              'val' => 750, 'price' => 0.03 ),
            array( 'label' => 'UV Matte',              'val' => 510, 'price' => 0.04 ),
        ),
        'bundling' => array(
            array( 'label' => 'No Bundling',     'val' => 0,    'price' => 500 ),
            array( 'label' => 'Bundle in 25s',   'val' => 750,  'price' => 25 ),
            array( 'label' => 'Bundle in 50s',   'val' => 1500, 'price' => 50 ),
            array( 'label' => 'Bundle in 100s',  'val' => 3000, 'price' => 100 ),
        ),
        'corners' => array(
            array( 'label' => 'No Round Cornering',                              'val' => 0,   'price' => 0 ),
            array( 'label' => "\xC2\xBC\" Round \xE2\x80\x94 Outside 2",        'val' => 216, 'price' => 0.2 ),
            array( 'label' => "\xE2\x85\x9C\" Round \xE2\x80\x94 Outside 2",    'val' => 215, 'price' => 0.15 ),
            array( 'label' => "\xC2\xBC\" Round \xE2\x80\x94 All 4",            'val' => 108, 'price' => 0.1 ),
            array( 'label' => "\xE2\x85\x9C\" Round \xE2\x80\x94 All 4",        'val' => 107, 'price' => 0.075 ),
        ),
        'art_opts' => array(
            array( 'label' => 'Upload Art with Order',      'val' => 0.01, 'price' => 0 ),
            array( 'label' => 'Email Art After Order',      'val' => 0.02, 'price' => 0 ),
            array( 'label' => 'Artwork already discussed',  'val' => 0.03, 'price' => 0 ),
            array( 'label' => 'I have a design in Canva',   'val' => 0.04, 'price' => 0 ),
            array( 'label' => 'Artwork needs edits',        'val' => 2.01, 'price' => 75 ),
            array( 'label' => 'Design from scratch',        'val' => 4.01, 'price' => 75 ),
        ),
        'bleed_opts' => array(
            array( 'label' => 'My artwork has proper bleeds', 'val' => 0, 'price' => 0 ),
            array( 'label' => "I don't have bleeds",          'val' => 1, 'price' => 0.5 ),
        ),

        'size_presets' => array(
            array( 'group' => 'Standard & Small', 'items' => array(
                array( 'label' => '3.5×5.5 (Opens to 7×5.5)',    'imp' => 8, 'bindEdge' => 5.5 ),
                array( 'label' => '4×6 (Opens to 8×6)',           'imp' => 8, 'bindEdge' => 6 ),
                array( 'label' => '4.25×5.5 (Opens to 8.5×5.5)', 'imp' => 8, 'bindEdge' => 5.5 ),
                array( 'label' => '5×7 (Opens to 7×10)',          'imp' => 4, 'bindEdge' => 7 ),
                array( 'label' => '5.5×8.5 (Opens to 8.5×11)',   'imp' => 4, 'bindEdge' => 8.5 ),
                array( 'label' => '6×9 (Opens to 12×9)',          'imp' => 4, 'bindEdge' => 9 ),
                array( 'label' => '8.5×11 (Opens to 11×17)',      'imp' => 2, 'bindEdge' => 11 ),
                array( 'label' => '9×12 (Opens to 18×12)',        'imp' => 2, 'bindEdge' => 12 ),
            )),
            array( 'group' => 'Square & Landscape', 'items' => array(
                array( 'label' => 'Square 4×4 (Opens to 4×8)',      'imp' => 8, 'bindEdge' => 4 ),
                array( 'label' => 'Square 5.5×5.5 (Opens to 5.5×11)', 'imp' => 6, 'bindEdge' => 5.5 ),
                array( 'label' => 'Square 6×6 (Opens to 6×12)',     'imp' => 6, 'bindEdge' => 6 ),
                array( 'label' => 'Square 8×8 (Opens to 8×16)',     'imp' => 2, 'bindEdge' => 8 ),
                array( 'label' => 'Square 9×9 (Opens to 9×18)',     'imp' => 2, 'bindEdge' => 9 ),
                array( 'label' => 'Square 12×12 (Opens to 12×24)',  'imp' => 1, 'bindEdge' => 12 ),
                array( 'label' => '6×4 Landscape (Opens to 12×4)',  'imp' => 8, 'bindEdge' => 4 ),
                array( 'label' => '5.5×4.25 Landscape',             'imp' => 8, 'bindEdge' => 4.25 ),
                array( 'label' => '8.5×5.5 Landscape',              'imp' => 4, 'bindEdge' => 5.5 ),
                array( 'label' => '9×6 Landscape (Opens to 6×18)',  'imp' => 4, 'bindEdge' => 6 ),
                array( 'label' => '11×8.5 Landscape',               'imp' => 1, 'bindEdge' => 8.5 ),
                array( 'label' => '12×9 Landscape (Opens to 24×9)', 'imp' => 1, 'bindEdge' => 9 ),
            )),
        ),

        'transit_days' => array(
            'AZ' => 1,
            'CA' => 2, 'CO' => 2, 'NM' => 2, 'NV' => 2, 'UT' => 2,
            'ID' => 3, 'OR' => 3, 'TX' => 3, 'WA' => 3,
            'AL' => 4, 'AR' => 4, 'FL' => 4, 'IA' => 4, 'IL' => 4, 'IN' => 4,
            'KS' => 4, 'KY' => 4, 'LA' => 4, 'MI' => 4, 'MN' => 4, 'MO' => 4,
            'MS' => 4, 'MT' => 4, 'ND' => 4, 'NE' => 4, 'OK' => 4, 'PA' => 4,
            'SD' => 4, 'TN' => 4, 'WI' => 4, 'WV' => 4, 'WY' => 4,
            'MA' => 5, 'NC' => 5, 'NJ' => 5, 'NY' => 5, 'OH' => 5, 'SC' => 5, 'VA' => 5,
            'CT' => 6, 'DC' => 6, 'DE' => 6, 'MD' => 6, 'ME' => 6, 'NH' => 6, 'VT' => 6,
            'AK' => 7, 'HI' => 7, 'PR' => 7, 'RI' => 7,
            'VI' => 9, 'MP' => 7,
        ),

        'closures' => array( '01-01', '07-04', '12-24', '12-25', '11-28', '11-29' ),

        'page_counts' => array( 8, 12, 16, 20, 24, 28, 32, 36, 40, 44, 48, 52, 56, 60, 64 ),

        // SEO / business info read by the LocalBusiness schema emitter.
        // Address fields are public anyway (LocalBusiness schema), so
        // broadcasting them via PPS_CONFIG to the browser is acceptable.
        // GBP fields are only emitted into aggregateRating when both rating
        // and review_count are > 0.
        'seo' => array(
            'phone'             => '',
            'email'             => '',
            'street'            => '',
            'city'              => 'Phoenix',
            'state'             => 'AZ',
            'zip'               => '85027',
            'lat'               => '',
            'lng'               => '',
            'gbp_url'           => '',
            'gbp_rating_value' => 0,
            'gbp_review_count'  => 0,
        ),
    );
}

// ═══════════════════════════════════════════════════════════════
// GET / SAVE CONFIG
// ═══════════════════════════════════════════════════════════════

/**
 * Canonical per-stock lead-time days + customer-facing copy, keyed by val
 * within the nc/cs pools. Single source of truth: docs/PAPER_CATALOG.md.
 * Every surface derives from this via pps_get_config() enrichment — the
 * calculators (rows injected as PPS_CONFIG.calc win over their embedded
 * PAPER_DESC fallback maps), the category wizard paper steps, and the
 * [pps_cat_papers] cards. When copy changes, update this, the calculators'
 * embedded maps, and docs/PAPER_CATALOG.md in the same commit.
 */
function pps_paper_meta_defaults() {
    return array(
        'nc' => array(
            '0.001' => array( 'days' => 0, 'spec' => '5pt · 104gsm — Best for writing', 'desc' => 'Classic uncoated paper with a natural feel that\'s easy to write on. Crisp text and a soft, non-reflective look — letterhead, inserts, forms, and reading-heavy pages.' ),
            '0.002' => array( 'days' => 0, 'spec' => '5pt · 118gsm — Distinct feel, slightly writable', 'desc' => 'Smooth coated sheet with a soft, glare-free finish. Richer color than uncoated without the shine — the all-purpose choice for brochures and flyers.' ),
            '0.003' => array( 'days' => 0, 'spec' => '6pt · 148gsm — Best image quality', 'desc' => 'Shiny coated sheet that makes photos and color pop. The standard for marketing brochures, catalogs, and mailers.' ),
            '2.002' => array( 'days' => 2, 'spec' => '4pt · 89gsm — Best for writing', 'desc' => 'Light uncoated sheet with good opacity for its weight. Economical for manuals, workbooks, and text-heavy booklets.' ),
            '2.003' => array( 'days' => 2, 'spec' => '5pt · 118gsm — Best for writing', 'desc' => 'Sturdy uncoated sheet with excellent opacity. The uncoated feel with less show-through — workbooks, journals, and premium text pages.' ),
            '2.004' => array( 'days' => 2, 'spec' => '5pt · 118gsm — Best image quality', 'desc' => 'Lightweight gloss sheet with vivid color reproduction. A thinner, economical alternative to 100lb gloss for catalogs and mailers.' ),
            '2.005' => array( 'days' => 2, 'spec' => '6pt · 148gsm — Distinct feel, slightly writable', 'desc' => 'Heavy matte sheet with a refined, low-glare surface. Upscale brochures, art books, and photography that shouldn\'t shine.' ),
            '2.006' => array( 'days' => 4, 'spec' => '7pt · 118gsm — Textured, best for writing', 'desc' => 'Premium stock with a woven linen texture you can feel. Distinctive for invitations, stationery, and fine-dining menus.' ),
        ),
        'cs' => array(
            '0.01' => array( 'days' => 0, 'spec' => '10pt · 216gsm — Best for writing', 'desc' => 'Our lightest cardstock, uncoated and easy to write on. Greeting cards, reply cards, and covers that fold cleanly.' ),
            '0.02' => array( 'days' => 0, 'spec' => '9pt · 216gsm — Distinct feel, slightly writable', 'desc' => 'Light cardstock with a smooth matte coating. A soft, modern look for covers and cards.' ),
            '0.03' => array( 'days' => 0, 'spec' => '10pt · 270gsm — Best image quality', 'desc' => 'Mid-weight cardstock with a glossy face that makes color punchy. Covers, postcards, and hang tags.' ),
            '1.01' => array( 'days' => 1, 'spec' => '300gsm — Best image quality', 'desc' => 'Thick card, gloss-coated on one side with an uncoated back that\'s easy to write on. The postcard standard (C1S = coated one side).' ),
            '1.02' => array( 'days' => 1, 'spec' => '350gsm — Best image quality', 'desc' => 'Our heaviest everyday card, gloss-coated both sides (C2S). Substantial, premium feel for business cards, postcards, and covers.' ),
            '2.21' => array( 'days' => 2, 'spec' => '9pt · 216gsm — Best image quality', 'desc' => 'Light, flexible cardstock with a gloss coat on both sides. Economical covers and cards with vivid color.' ),
            '2.22' => array( 'days' => 2, 'spec' => '10pt · 270gsm — Distinct feel, slightly writable', 'desc' => 'Mid-weight matte card with an elegant, glare-free surface. Book covers and upscale cards.' ),
            '2.23' => array( 'days' => 2, 'spec' => '260gsm — Best image quality', 'desc' => 'Flexible coated card, thinner than 14pt. Tickets, tags, and lightweight postcards.' ),
            '2.24' => array( 'days' => 2, 'spec' => '300gsm — Best image quality', 'desc' => 'Thick card gloss-coated both sides for all-over shine. Postcards and covers that want gloss front and back.' ),
            '2.25' => array( 'days' => 2, 'spec' => '400gsm — Best image quality', 'desc' => 'Our most rigid card — gloss front, uncoated writable back. Heavy-duty hang tags, counter cards, and premium postcards.' ),
        ),
    );
}

/**
 * The one inventory rule, shared by every surface. The calculators mirror it
 * in JS (paperInv) but prefer this server-computed 'inv' flag when present.
 */
function pps_paper_is_inventoried( $row ) {
    return empty( $row['factory'] ) && empty( $row['days'] );
}

/**
 * Fill desc/days from canonical defaults when a row lacks them (admin rows
 * predate both fields), then stamp the computed 'inv' flag. Admin-entered
 * desc/days always win over the canonical defaults.
 */
function pps_paper_enrich( $rows, $pool ) {
    if ( ! is_array( $rows ) ) return $rows;
    $meta = pps_paper_meta_defaults();
    $m    = isset( $meta[ $pool ] ) ? $meta[ $pool ] : array();
    foreach ( $rows as &$p ) {
        if ( ! is_array( $p ) || ! isset( $p['val'] ) ) continue;
        $k = rtrim( rtrim( sprintf( '%.3F', (float) $p['val'] ), '0' ), '.' );
        if ( isset( $m[ $k ] ) ) {
            if ( ! isset( $p['days'] ) || $p['days'] === '' ) $p['days'] = $m[ $k ]['days'];
            if ( empty( $p['desc'] ) ) $p['desc'] = $m[ $k ]['desc'];
            if ( empty( $p['spec'] ) && isset( $m[ $k ]['spec'] ) ) $p['spec'] = $m[ $k ]['spec'];
        }
        if ( ! isset( $p['days'] ) || $p['days'] === '' ) $p['days'] = 0;
        $p['days'] = intval( $p['days'] );
        $p['inv']  = pps_paper_is_inventoried( $p );
    }
    unset( $p );
    return $rows;
}

function pps_get_config() {
    $defaults = pps_default_config();
    $saved    = get_option( PPS_CONFIG_OPTION, array() );
    // The MCP wp_update_option endpoint can store an array payload as a JSON
    // string; decode rather than discard, or every admin-set price and paper row
    // silently reverts to defaults. Same tolerance pps_get_registry() and
    // pps_get_tooltips() apply — the tooltips option was hit by exactly this.
    if ( is_string( $saved ) ) {
        $decoded = json_decode( $saved, true );
        $saved   = is_array( $decoded ) ? $decoded : array();
    }
    if ( ! is_array( $saved ) ) $saved = array();

    $result = array_replace( $defaults, $saved );
    $result['pcf'] = array_merge(
        $defaults['pcf'],
        isset( $saved['pcf'] ) && is_array( $saved['pcf'] ) ? $saved['pcf'] : array()
    );

    if ( isset( $result['papers_nc'] ) ) $result['papers_nc'] = pps_paper_enrich( $result['papers_nc'], 'nc' );
    if ( isset( $result['papers_cs'] ) ) $result['papers_cs'] = pps_paper_enrich( $result['papers_cs'], 'cs' );

    return $result;
}

function pps_save_config( $data ) {
    update_option( PPS_CONFIG_OPTION, $data, false );
    // Pricing tables are baked into page-cached product HTML; a price edit
    // must invalidate the cache or visitors keep seeing pre-edit rates.
    if ( function_exists( 'pps_purge_page_cache' ) ) pps_purge_page_cache();
}

// ═══════════════════════════════════════════════════════════════
// ADMIN PAGE REGISTRATION
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_submenu_page(
        'pps-calculators',
        'Central Config',
        '⚙ Central Config',
        'manage_options',
        'pps-config',
        'pps_config_render_page'
    );
}, 20 );

// ═══════════════════════════════════════════════════════════════
// UPS ZONE CHART CSV / XLSX PARSER
// ═══════════════════════════════════════════════════════════════

/**
 * Convert an XLSX file (Office 2007+, ZIP container) into CSV text.
 * Returns false if the file cannot be opened or has no sheet1.
 * Reads the first worksheet only; that's the layout UPS publishes.
 */
function pps_xlsx_to_csv( $path ) {
    if ( ! class_exists( 'ZipArchive' ) ) return false;
    $zip = new ZipArchive();
    if ( $zip->open( $path ) !== true ) return false;

    $shared = array();
    $ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
    if ( $ss_xml ) {
        $sx = @simplexml_load_string( $ss_xml );
        if ( $sx ) {
            foreach ( $sx->si as $si ) {
                $text = '';
                if ( isset( $si->t ) ) {
                    $text = (string) $si->t;
                } elseif ( isset( $si->r ) ) {
                    foreach ( $si->r as $r ) $text .= (string) $r->t;
                }
                $shared[] = $text;
            }
        }
    }

    $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
    $zip->close();
    if ( ! $sheet_xml ) return false;

    $sx = @simplexml_load_string( $sheet_xml );
    if ( ! $sx || ! isset( $sx->sheetData ) ) return false;

    $out = '';
    foreach ( $sx->sheetData->row as $row ) {
        $cells = array();
        $max = -1;
        foreach ( $row->c as $c ) {
            $ref = (string) $c['r'];
            if ( ! preg_match( '/^([A-Z]+)/', $ref, $m ) ) continue;
            $col_idx = 0;
            foreach ( str_split( $m[1] ) as $ch ) $col_idx = $col_idx * 26 + ( ord( $ch ) - 64 );
            $col_idx--;
            $val = isset( $c->v ) ? (string) $c->v : '';
            $type = (string) $c['t'];
            if ( $type === 's' ) {
                $val = $shared[ intval( $val ) ] ?? '';
            } elseif ( $type === 'inlineStr' && isset( $c->is->t ) ) {
                $val = (string) $c->is->t;
            }
            $cells[ $col_idx ] = $val;
            if ( $col_idx > $max ) $max = $col_idx;
        }
        $line = array();
        for ( $i = 0; $i <= $max; $i++ ) {
            $v = $cells[ $i ] ?? '';
            $line[] = ( strpbrk( $v, ",\"\n\r" ) !== false )
                ? '"' . str_replace( '"', '""', $v ) . '"'
                : $v;
        }
        $out .= implode( ',', $line ) . "\n";
    }
    return $out;
}

/**
 * Parse a UPS zone chart CSV into a 3-digit ZIP prefix → transit days map.
 *
 * UPS CSV format:
 *   Row 1: "ZONE CHART"
 *   Row 2: service names (e.g. "UPS Ground/UPS 3 Day Select/...")
 *   Row 3: origin info
 *   Row 4: instructions
 *   Row 5: "ZONES"
 *   Row 6: header — "Dest. ZIP", "Ground", "3 Day Select", ...
 *   Row 7+: data  — "004-005", "5", "305", ...  or  "420", "6", ...
 *
 * Zone → transit days: 2=1d, 3=2d, 4=3d, 5=4d, 6=5d, 7=6d, 8=7d.
 * HI/AK Ground zones (44-46) are air-only routings; mapped to 7d.
 *
 * Returns array( 'map' => [pfx => days], 'skipped' => int ).
 */
function pps_parse_ups_zone_csv( $raw ) {
    $zone_to_days = array( 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 8 => 7 );
    $map = array();
    $skipped = 0;

    // Normalize line endings
    $raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
    $lines = explode( "\n", $raw );

    // Find the header row — must contain BOTH "Dest" AND "Ground". The
    // service-description row in UPS files contains "Ground" alone (in a
    // long slash-separated string); requiring both tokens skips it.
    $header_idx = -1;
    $ground_col = 1; // default: second column
    foreach ( $lines as $idx => $line ) {
        if ( stripos( $line, 'Dest' ) !== false && stripos( $line, 'Ground' ) !== false ) {
            $cols = str_getcsv( $line );
            foreach ( $cols as $ci => $ch ) {
                if ( strcasecmp( trim( $ch, " \t\"" ), 'Ground' ) === 0 ) {
                    $ground_col = $ci;
                    break;
                }
            }
            $header_idx = $idx;
            break;
        }
    }

    $set_pfx = function( $zip, $days ) use ( &$map ) {
        if ( $zip < 0 ) return;
        if ( $zip > 999 ) $zip = intdiv( $zip, 100 ); // 5-digit ZIP → 3-digit prefix
        if ( $zip < 0 || $zip > 999 ) return;
        $map[ str_pad( $zip, 3, '0', STR_PAD_LEFT ) ] = $days;
    };

    // Parse data rows
    $start = ( $header_idx >= 0 ) ? $header_idx + 1 : 0;
    foreach ( array_slice( $lines, $start ) as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;

        $cols = str_getcsv( $line );
        if ( count( $cols ) < 2 ) continue;

        $dest = trim( $cols[0], ' "' );
        $zone_raw = trim( $cols[ $ground_col ] ?? $cols[1] ?? '', ' "' );

        // Skip non-numeric zones (dashes, empty, header text)
        if ( ! is_numeric( $zone_raw ) ) continue;
        $zone = intval( $zone_raw );

        if ( isset( $zone_to_days[ $zone ] ) ) {
            $days = $zone_to_days[ $zone ];
        } elseif ( $zone >= 44 && $zone <= 46 ) {
            // HI/AK Ground codes: no surface route, treat as 7-day fallback
            $days = 7;
        } elseif ( $zone > 999 ) {
            // Likely a ZIP-enumeration row (HI/AK sub-table lists 5-digit ZIPs
            // grouped under a Zone 44/46 header). Gap-fill handles those 3-digit
            // prefixes correctly, so skip silently rather than alarming the user.
            continue;
        } else {
            $skipped++;
            continue;
        }

        // Parse dest — could be "004-005" range or "420" single
        if ( strpos( $dest, '-' ) !== false ) {
            $parts = explode( '-', $dest );
            $lo = intval( trim( $parts[0] ) );
            $hi = intval( trim( $parts[1] ) );
            if ( $hi < $lo ) continue;
            // Cap range to a sane upper bound to avoid runaway loops on malformed input
            if ( $hi - $lo > 100000 ) continue;
            for ( $i = $lo; $i <= $hi; $i++ ) $set_pfx( $i, $days );
        } else {
            $set_pfx( intval( $dest ), $days );
        }
    }

    // Fill gaps with 7 days (unknown/military)
    if ( count( $map ) > 50 ) {
        for ( $i = 0; $i < 1000; $i++ ) {
            $p = str_pad( $i, 3, '0', STR_PAD_LEFT );
            if ( ! isset( $map[ $p ] ) ) $map[ $p ] = 7;
        }
        ksort( $map );
    }

    return array( 'map' => $map, 'skipped' => $skipped );
}

// ═══════════════════════════════════════════════════════════════
// ADMIN PAGE RENDER
// ═══════════════════════════════════════════════════════════════

function pps_config_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $saved_msg = '';

    // Handle save
    if ( isset( $_POST['pps_config_save'] ) ) {
        check_admin_referer( 'pps_config_save' );
        $cfg = pps_get_config();

        // ── PCF scalars ──
        if ( isset( $_POST['pcf'] ) && is_array( $_POST['pcf'] ) ) {
            foreach ( $_POST['pcf'] as $key => $val ) {
                if ( ! array_key_exists( $key, $cfg['pcf'] ) ) continue;
                if ( $key === 'shop_timezone' ) {
                    $cfg['pcf'][ $key ] = sanitize_text_field( $val );
                } elseif ( in_array( $key, array( 'shippo_api_token', 'shippo_origin_zip' ) ) ) {
                    $cfg['pcf'][ $key ] = sanitize_text_field( $val );
                } else {
                    $cfg['pcf'][ $key ] = floatval( $val );
                }
            }
        }

        // ── JSON sections ──
        $json_keys = array(
            'papers_nc', 'papers_cs', 'cover_same',
            'cover_scoring_vals', 'inv_nc', 'inv_cs', 'large_sheet_vals',
            'coatings', 'bundling', 'corners',
            'art_opts', 'bleed_opts',
            'size_presets', 'transit_days', 'closures', 'page_counts',
        );
        $json_errors = array();
        foreach ( $json_keys as $jk ) {
            if ( ! empty( $_POST[ 'json_' . $jk ] ) ) {
                $decoded = json_decode( wp_unslash( $_POST[ 'json_' . $jk ] ), true );
                if ( json_last_error() === JSON_ERROR_NONE && $decoded !== null ) {
                    $cfg[ $jk ] = $decoded;
                } else {
                    $json_errors[] = $jk . ': ' . json_last_error_msg();
                }
            }
        }

        // ── SEO section (GBP fields) ──
        // Merge into existing seo so other seo fields (phone/email/address
        // populated outside this UI) are not wiped. Only update keys we own.
        if ( isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ) {
            $seo_in   = wp_unslash( $_POST['seo'] );
            $seo_curr = isset( $cfg['seo'] ) && is_array( $cfg['seo'] ) ? $cfg['seo'] : array();

            // GBP URL — must be http/https; reject other schemes silently
            if ( isset( $seo_in['gbp_url'] ) ) {
                $url = esc_url_raw( trim( (string) $seo_in['gbp_url'] ), array( 'http', 'https' ) );
                $seo_curr['gbp_url'] = $url;
            }
            // GBP rating value — float clamped to [0.0, 5.0]
            if ( isset( $seo_in['gbp_rating_value'] ) ) {
                $rv = floatval( $seo_in['gbp_rating_value'] );
                if ( $rv < 0 ) $rv = 0;
                if ( $rv > 5 ) $rv = 5;
                $seo_curr['gbp_rating_value'] = $rv;
            }
            // GBP review count — int clamped to [0, 10_000_000]
            if ( isset( $seo_in['gbp_review_count'] ) ) {
                $rc = intval( $seo_in['gbp_review_count'] );
                if ( $rc < 0 ) $rc = 0;
                if ( $rc > 10000000 ) $rc = 10000000;
                $seo_curr['gbp_review_count'] = $rc;
            }
            $cfg['seo'] = $seo_curr;
        }

        pps_save_config( $cfg );

        // ── Add-on visibility matrix (separate option) ──
        // POST shape: addons_visibility[<addon>][<calc>] = '1' when checked,
        // omitted when unchecked. Build the full matrix from defaults so any
        // missing checkboxes are treated as "off" rather than "default true".
        $matrix = pps_addon_visibility_matrix_defaults();
        $vis_save = array();
        $vis_post = ( isset( $_POST['addons_visibility'] ) && is_array( $_POST['addons_visibility'] ) )
            ? wp_unslash( $_POST['addons_visibility'] ) : array();
        foreach ( $matrix as $addon => $calcs ) {
            foreach ( $calcs as $calc ) {
                $vis_save[ $addon ][ $calc ] = ! empty( $vis_post[ $addon ][ $calc ] );
            }
        }
        update_option( PPS_ADDONS_VISIBILITY_OPTION, $vis_save, false );

        // ── FAQs (separate option from main config) ──
        if ( isset( $_POST['pps_faqs_json'] ) ) {
            $faq_raw = wp_unslash( $_POST['pps_faqs_json'] );
            // Hard size cap to prevent DoS via giant POST
            if ( strlen( $faq_raw ) > 524288 ) {
                $json_errors[] = 'FAQs: payload too large (max 512KB).';
            } else {
                $decoded = json_decode( $faq_raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                    $json_errors[] = 'FAQs: ' . json_last_error_msg();
                } else {
                    $allowed_calcs = array( 'saddle', 'perfect-bound', 'brochure', 'coupon', 'letterhead' );
                    $clean = array();
                    foreach ( $decoded as $calc => $faqs ) {
                        if ( ! in_array( $calc, $allowed_calcs, true ) ) continue;
                        if ( ! is_array( $faqs ) ) continue;
                        $clean[ $calc ] = array();
                        $count = 0;
                        foreach ( $faqs as $entry ) {
                            if ( ++$count > 50 ) break; // 50 FAQs per calc max
                            if ( ! is_array( $entry ) ) continue;
                            $q = isset( $entry['q'] ) ? sanitize_text_field( (string) $entry['q'] ) : '';
                            $a = isset( $entry['a'] ) ? wp_kses_post( (string) $entry['a'] ) : '';
                            // Per-entry size caps
                            if ( strlen( $q ) > 512 )  $q = substr( $q, 0, 512 );
                            if ( strlen( $a ) > 4096 ) $a = substr( $a, 0, 4096 );
                            if ( $q === '' || $a === '' ) continue;
                            $clean[ $calc ][] = array( 'q' => $q, 'a' => $a );
                        }
                    }
                    update_option( 'pps_faqs', $clean, false );
                }
            }
        }

        // ── Tooltips (separate wp_option) ──
        if ( isset( $_POST['pps_tooltips_json'] ) ) {
            $tip_raw = wp_unslash( $_POST['pps_tooltips_json'] );
            if ( strlen( $tip_raw ) > 524288 ) {
                $json_errors[] = 'Tooltips: payload too large (max 512KB).';
            } else {
                $decoded = json_decode( $tip_raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
                    $json_errors[] = 'Tooltips: ' . json_last_error_msg();
                } else {
                    $clean = array();
                    $count = 0;
                    foreach ( $decoded as $key => $tip ) {
                        if ( ++$count > 100 ) break;
                        $k = sanitize_key( $key );
                        if ( $k === '' ) continue;
                        $clean[ $k ] = array(
                            'title'   => sanitize_text_field( $tip['title'] ?? '' ),
                            'content' => array(),
                        );
                        if ( ! empty( $tip['content'] ) && is_array( $tip['content'] ) ) {
                            $bc = 0;
                            foreach ( $tip['content'] as $block ) {
                                if ( ++$bc > 20 ) break;
                                $type = sanitize_key( $block['type'] ?? 'text' );
                                $b = array( 'type' => $type );
                                if ( $type === 'text' ) {
                                    $b['value'] = sanitize_textarea_field( $block['value'] ?? '' );
                                } elseif ( $type === 'image' ) {
                                    $b['src'] = esc_url_raw( $block['src'] ?? '' );
                                    $b['alt'] = sanitize_text_field( $block['alt'] ?? '' );
                                } elseif ( $type === 'video' ) {
                                    $b['src'] = esc_url_raw( $block['src'] ?? '' );
                                    $b['poster'] = esc_url_raw( $block['poster'] ?? '' );
                                } elseif ( $type === 'youtube' ) {
                                    $b['src'] = esc_url_raw( $block['src'] ?? '' );
                                    $b['alt'] = sanitize_text_field( $block['alt'] ?? '' );
                                }
                                $clean[ $k ]['content'][] = $b;
                            }
                        }
                    }
                    update_option( 'pps_tooltips', $clean, false );
                }
            }
        }

        // ── Zone Map (separate wp_option) ──
        $zone_updated = false;

        // Zone chart upload — accepts CSV, XLSX, or .xls-named-but-XLSX (UPS default)
        if ( ! empty( $_FILES['ups_zone_csv']['tmp_name'] ) && $_FILES['ups_zone_csv']['error'] === UPLOAD_ERR_OK ) {
            $tmp = $_FILES['ups_zone_csv']['tmp_name'];
            $csv_data = file_get_contents( $tmp );
            $head4 = substr( (string) $csv_data, 0, 4 );

            if ( $head4 === "PK\x03\x04" ) {
                // XLSX (Office 2007+, ZIP container) — convert in-process
                $converted = pps_xlsx_to_csv( $tmp );
                if ( $converted === false ) {
                    $json_errors[] = 'Zone upload: XLSX detected but could not be parsed';
                    $csv_data = '';
                } else {
                    $csv_data = $converted;
                }
            } elseif ( $head4 === "\xD0\xCF\x11\xE0" ) {
                // Legacy BIFF/OLE2 .xls — not supported (would need a heavy parser)
                $json_errors[] = 'Zone upload: legacy .xls (BIFF) is not supported — open in Excel and save as .xlsx or .csv';
                $csv_data = '';
            }

            if ( $csv_data !== '' ) {
                $zone_result = pps_parse_ups_zone_csv( $csv_data );
                $zone_map = isset( $zone_result['map'] ) ? $zone_result['map'] : array();
                $zone_skipped = isset( $zone_result['skipped'] ) ? intval( $zone_result['skipped'] ) : 0;

                if ( count( $zone_map ) > 50 ) {
                    update_option( 'pps_ups_zone_map', $zone_map, false );
                    update_option( 'pps_ups_zone_map_updated', current_time( 'mysql' ), false );
                    $zone_updated = true;
                    if ( $zone_skipped > 0 ) {
                        $json_errors[] = sprintf( 'Zone upload: %d row(s) skipped (zone out of range or non-numeric)', $zone_skipped );
                    }
                } else {
                    $json_errors[] = 'Zone upload: could not parse — confirm this is a UPS zone chart (CSV or XLSX)';
                }
            }
        }

        // JSON paste
        if ( ! $zone_updated && ! empty( $_POST['zone_map_json'] ) ) {
            $zmap = json_decode( wp_unslash( $_POST['zone_map_json'] ), true );
            if ( is_array( $zmap ) && count( $zmap ) > 50 ) {
                // Sanitize: keys must be 3-digit strings, values must be ints 1-14
                $clean = array();
                foreach ( $zmap as $pfx => $days ) {
                    $pfx = str_pad( substr( preg_replace( '/[^0-9]/', '', $pfx ), 0, 3 ), 3, '0', STR_PAD_LEFT );
                    $d   = intval( $days );
                    if ( $d >= 1 && $d <= 14 ) $clean[ $pfx ] = $d;
                }
                if ( count( $clean ) > 50 ) {
                    ksort( $clean );
                    update_option( 'pps_ups_zone_map', $clean, false );
                    update_option( 'pps_ups_zone_map_updated', current_time( 'mysql' ), false );
                    $zone_updated = true;
                }
            } else {
                $json_errors[] = 'Zone JSON: Invalid format or too few entries';
            }
        }

        if ( ! empty( $json_errors ) ) {
            $saved_msg = '<div class="notice notice-warning is-dismissible"><p><strong>Saved with errors.</strong> These fields had invalid data and were NOT updated:<br><code>' . implode( '</code>, <code>', array_map( 'esc_html', $json_errors ) ) . '</code></p></div>';
        } else {
            $saved_msg = '<div class="notice notice-success is-dismissible"><p><strong>Configuration saved.</strong> Changes are live immediately.</p></div>';
        }

    } elseif ( isset( $_POST['pps_config_reset'] ) ) {
        check_admin_referer( 'pps_config_save' );
        delete_option( PPS_CONFIG_OPTION );
        $saved_msg = '<div class="notice notice-warning is-dismissible"><p>Configuration reset to factory defaults.</p></div>';
    }

    $cfg = pps_get_config();
    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'production';

    $tabs = array(
        'production' => 'Production',
        'papers'     => 'Papers',
        'finishing'  => 'Finishing',
        'artwork'    => 'Artwork',
        'sizes'      => 'Sizes',
        'shipping'   => 'Shipping',
        'seo'        => 'SEO',
        'tooltips'   => 'Tooltips',
    );

    ?>
    <div class="wrap pps-cfg-wrap">
        <?php echo $saved_msg; ?>
        <div class="pps-cfg-header">
            <h1>⚙ PPS Central Config</h1>
            <a href="<?php echo admin_url( 'admin.php?page=pps-calculators' ); ?>" class="button button-small">← Calculators</a>
        </div>

        <nav class="nav-tab-wrapper" style="margin-bottom:0">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="<?php echo admin_url( 'admin.php?page=pps-config&tab=' . $slug ); ?>"
                   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <form method="post" id="pps-config-form" enctype="multipart/form-data" style="margin-top:0">
            <?php wp_nonce_field( 'pps_config_save' ); ?>

            <?php
            switch ( $tab ) {
                case 'production': pps_config_tab_production( $cfg ); break;
                case 'papers':     pps_config_tab_papers( $cfg ); break;
                case 'finishing':  pps_config_tab_finishing( $cfg ); break;
                case 'artwork':    pps_config_tab_artwork( $cfg ); break;
                case 'sizes':      pps_config_tab_sizes( $cfg ); break;
                case 'shipping':   pps_config_tab_shipping( $cfg ); break;
                case 'seo':        pps_config_tab_seo( $cfg ); break;
                case 'tooltips':   pps_config_tab_tooltips( $cfg ); break;
            }
            ?>

            <div class="pps-cfg-actions">
                <button type="submit" name="pps_config_save" class="button button-primary">💾 Save</button>
                <button type="submit" name="pps_config_reset" class="button button-link-delete"
                        onclick="return confirm('Reset ALL config to factory defaults?')">Reset to Defaults</button>
            </div>
        </form>
    </div>

    <style>
        .pps-cfg-wrap { max-width: 1100px; }
        .pps-cfg-header { display:flex; align-items:center; gap:12px; margin:16px 0 12px; }
        .pps-cfg-header h1 { margin:0; font-size:20px; }
        .pps-cfg-actions { padding:12px 0; display:flex; gap:10px; align-items:center; }

        /* Compact spreadsheet tables */
        .pps-ss { border-collapse:collapse; width:100%; font-size:12px; margin-bottom:4px; background:#fff; }
        .pps-ss th { background:#f0f0f1; padding:4px 8px; border:1px solid #ccc; font-weight:600; text-align:left; white-space:nowrap; font-size:11px; text-transform:uppercase; color:#555; letter-spacing:0.3px; }
        .pps-ss td { padding:2px 4px; border:1px solid #ddd; vertical-align:middle; }
        .pps-ss input[type=number], .pps-ss input[type=text] { width:100%; padding:2px 5px; border:1px solid #ccc; border-radius:2px; font-size:12px; background:#fff; box-sizing:border-box; }
        .pps-ss input[type=number] { max-width:100px; text-align:right; }
        .pps-ss input[type=text] { min-width:120px; }
        .pps-ss input[type=checkbox] { margin:0; }
        .pps-ss tr:hover td { background:#f7fbff; }
        .pps-ss .ss-unit { color:#999; font-size:10px; padding-left:2px; white-space:nowrap; }
        .pps-ss .ss-row-del { cursor:pointer; color:#b32d2e; font-size:14px; text-align:center; padding:0 4px; user-select:none; }
        .pps-ss .ss-row-del:hover { background:#fce4e4; }

        /* Section headers */
        .pps-ss-section { font-size:13px; font-weight:600; margin:14px 0 4px; color:#1d2327; border-bottom:2px solid #007eff; padding-bottom:3px; display:flex; align-items:center; gap:8px; }
        .pps-ss-section:first-child { margin-top:8px; }
        .pps-ss-hint { font-size:11px; color:#888; font-weight:400; }

        /* Add row button */
        .pps-ss-add { font-size:11px; color:#007eff; cursor:pointer; display:inline-block; margin:2px 0 10px; padding:2px 8px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-ss-add:hover { background:#e8f4ff; }

        /* Compact grid for transit */
        .pps-transit-grid { display:flex; flex-wrap:wrap; gap:2px; }
        .pps-transit-cell { display:flex; align-items:center; gap:2px; font-size:12px; }
        .pps-transit-cell label { font-weight:600; width:24px; color:#555; }
        .pps-transit-cell input { width:32px; padding:2px; border:1px solid #ccc; border-radius:2px; font-size:12px; text-align:center; }

        /* Inline chips for simple arrays */
        .pps-chips-wrap { display:flex; flex-wrap:wrap; gap:3px; align-items:center; margin-bottom:8px; }
        .pps-chip { display:inline-flex; align-items:center; gap:3px; background:#f0f0f1; border:1px solid #ccc; border-radius:3px; padding:2px 6px; font-size:12px; font-family:monospace; }
        .pps-chip input { width:55px; border:none; background:transparent; font-size:12px; font-family:monospace; text-align:center; padding:0; }
        .pps-chip .chip-del { cursor:pointer; color:#b32d2e; font-weight:bold; font-size:14px; line-height:1; user-select:none; }
        .pps-chip .chip-del:hover { color:#ff0000; }

        /* Hidden JSON fields */
        .pps-json-hidden { display:none; }

        /* SEO tab — GBP fields */
        .pps-seo-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px 24px; margin-bottom:14px; }
        .pps-seo-field { display:flex; flex-direction:column; gap:3px; font-size:12px; }
        .pps-seo-field label { font-weight:600; color:#1d2327; }
        .pps-seo-field input { padding:5px 8px; border:1px solid #ccc; border-radius:3px; font-size:13px; box-sizing:border-box; }
        .pps-seo-field .hint { color:#888; font-size:11px; }

        /* SEO tab — FAQs */
        .pps-faq-calc { background:#fafafa; border:1px solid #ddd; border-radius:4px; padding:10px 12px; margin-bottom:14px; }
        .pps-faq-calc-title { font-size:13px; font-weight:600; color:#1d2327; margin-bottom:6px; }
        .pps-faq-rows { display:flex; flex-direction:column; gap:6px; }
        .pps-faq-row { display:grid; grid-template-columns:1fr 16px; gap:6px; align-items:start; padding:6px; background:#fff; border:1px solid #e0e0e0; border-radius:3px; }
        .pps-faq-row input, .pps-faq-row textarea { width:100%; padding:4px 6px; border:1px solid #ccc; border-radius:2px; font-size:12px; box-sizing:border-box; font-family:inherit; }
        .pps-faq-row textarea { resize:vertical; min-height:48px; margin-top:4px; }
        .pps-faq-row .pps-faq-q-a { display:flex; flex-direction:column; gap:4px; }
        .pps-faq-del { cursor:pointer; color:#b32d2e; font-size:16px; text-align:center; line-height:24px; user-select:none; }
        .pps-faq-del:hover { background:#fce4e4; border-radius:2px; }
        .pps-faq-add { font-size:11px; color:#007eff; cursor:pointer; display:inline-block; margin-top:8px; padding:3px 10px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-faq-add:hover { background:#e8f4ff; }

        /* Tooltips tab */
        .pps-tip-card { background:#fafafa; border:1px solid #ddd; border-radius:4px; padding:10px 12px; margin-bottom:10px; }
        .pps-tip-header { display:flex; align-items:center; gap:8px; cursor:pointer; user-select:none; }
        .pps-tip-arrow { font-size:10px; color:#999; transition:transform .15s; width:12px; }
        .pps-tip-card.is-open .pps-tip-arrow { transform:rotate(90deg); }
        .pps-tip-key { font-family:monospace; font-size:12px; color:#007eff; font-weight:600; min-width:140px; }
        .pps-tip-title-preview { font-size:12px; color:#555; flex:1; }
        .pps-tip-del { cursor:pointer; color:#b32d2e; font-size:16px; line-height:1; user-select:none; margin-left:auto; padding:0 4px; }
        .pps-tip-del:hover { background:#fce4e4; border-radius:2px; }
        .pps-tip-body { display:none; margin-top:10px; padding-top:10px; border-top:1px solid #e0e0e0; }
        .pps-tip-card.is-open .pps-tip-body { display:block; }
        .pps-tip-fields { display:grid; grid-template-columns:180px 1fr; gap:6px 12px; margin-bottom:10px; align-items:center; }
        .pps-tip-fields label { font-size:11px; font-weight:600; color:#555; text-transform:uppercase; }
        .pps-tip-fields input { padding:4px 8px; border:1px solid #ccc; border-radius:3px; font-size:12px; box-sizing:border-box; }
        .pps-tip-block { display:grid; grid-template-columns:90px 1fr 20px; gap:6px; align-items:start; padding:6px; background:#fff; border:1px solid #e0e0e0; border-radius:3px; margin-bottom:4px; }
        .pps-tip-block select { padding:3px 4px; border:1px solid #ccc; border-radius:2px; font-size:11px; }
        .pps-tip-block textarea { width:100%; padding:4px 6px; border:1px solid #ccc; border-radius:2px; font-size:12px; box-sizing:border-box; font-family:inherit; resize:vertical; min-height:36px; }
        .pps-tip-block input[type="text"], .pps-tip-block input[type="url"] { width:100%; padding:4px 6px; border:1px solid #ccc; border-radius:2px; font-size:12px; box-sizing:border-box; }
        .pps-tip-block-fields { display:flex; flex-direction:column; gap:4px; }
        .pps-tip-block-del { cursor:pointer; color:#b32d2e; font-size:14px; text-align:center; line-height:24px; user-select:none; }
        .pps-tip-block-del:hover { background:#fce4e4; border-radius:2px; }
        .pps-tip-block-add { font-size:11px; color:#007eff; cursor:pointer; display:inline-block; margin-top:4px; padding:2px 8px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-tip-block-add:hover { background:#e8f4ff; }
        .pps-tip-add { font-size:11px; color:#007eff; cursor:pointer; display:inline-block; margin-top:6px; padding:3px 10px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-tip-add:hover { background:#e8f4ff; }
        .pps-tip-count { font-size:11px; color:#888; margin-left:8px; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ── Sync spreadsheet tables to hidden JSON fields on submit ──
        document.getElementById('pps-config-form').addEventListener('submit', function() {
            // Array-of-objects tables
            document.querySelectorAll('[data-pps-table]').forEach(function(table) {
                var key = table.dataset.ppsTable;
                var hidden = document.querySelector('textarea[name="json_' + key + '"]');
                if (!hidden) return;
                var arr = [];
                table.querySelectorAll('tbody tr').forEach(function(tr) {
                    var obj = {};
                    tr.querySelectorAll('[data-field]').forEach(function(el) {
                        var f = el.dataset.field;
                        if (el.type === 'checkbox') obj[f] = el.checked;
                        else if (el.type === 'number') obj[f] = parseFloat(el.value) || 0;
                        else obj[f] = el.value;
                    });
                    if (obj.label !== undefined && obj.label !== '') arr.push(obj);
                });
                hidden.value = JSON.stringify(arr);
            });

            // Size presets (grouped)
            var sizeHidden = document.querySelector('textarea[name="json_size_presets"]');
            if (sizeHidden) {
                var groups = [];
                document.querySelectorAll('[data-pps-size-group]').forEach(function(table) {
                    var groupName = table.dataset.ppsSizeGroup;
                    var items = [];
                    table.querySelectorAll('tbody tr').forEach(function(tr) {
                        var label = tr.querySelector('[data-field="label"]');
                        var imp = tr.querySelector('[data-field="imp"]');
                        var bind = tr.querySelector('[data-field="bindEdge"]');
                        if (label && label.value) {
                            items.push({ label: label.value, imp: parseInt(imp.value) || 1, bindEdge: parseFloat(bind.value) || 0 });
                        }
                    });
                    if (items.length) groups.push({ group: groupName, items: items });
                });
                sizeHidden.value = JSON.stringify(groups);
            }

            // Transit days grid
            var transitHidden = document.querySelector('textarea[name="json_transit_days"]');
            if (transitHidden) {
                var obj = {};
                document.querySelectorAll('.pps-transit-cell input').forEach(function(inp) {
                    var st = inp.dataset.state;
                    var d = parseInt(inp.value);
                    if (st && !isNaN(d)) obj[st] = d;
                });
                transitHidden.value = JSON.stringify(obj);
            }

            // Simple chip arrays
            document.querySelectorAll('[data-pps-chips]').forEach(function(wrap) {
                var key = wrap.dataset.ppsChips;
                var hidden = document.querySelector('textarea[name="json_' + key + '"]');
                if (!hidden) return;
                var vals = [];
                wrap.querySelectorAll('input').forEach(function(inp) {
                    var v = inp.value.trim();
                    if (v === '') return;
                    var n = parseFloat(v);
                    vals.push(isNaN(n) ? v : n);
                });
                hidden.value = JSON.stringify(vals);
            });

            // Cover same (single object)
            var coverWrap = document.querySelector('[data-pps-single="cover_same"]');
            var coverHidden = document.querySelector('textarea[name="json_cover_same"]');
            if (coverWrap && coverHidden) {
                var obj = {};
                coverWrap.querySelectorAll('[data-field]').forEach(function(el) {
                    var f = el.dataset.field;
                    if (el.type === 'checkbox') obj[f] = el.checked;
                    else if (el.type === 'number') obj[f] = parseFloat(el.value) || 0;
                    else obj[f] = el.value;
                });
                coverHidden.value = JSON.stringify(obj);
            }

            // FAQs (per calc type → array of {q, a})
            var faqHidden = document.querySelector('textarea[name="pps_faqs_json"]');
            if (faqHidden) {
                var faqOut = {};
                document.querySelectorAll('[data-pps-faq-calc]').forEach(function(group) {
                    var calc = group.dataset.ppsFaqCalc;
                    var entries = [];
                    group.querySelectorAll('[data-pps-faq-row]').forEach(function(row) {
                        var qEl = row.querySelector('[data-faq-field="q"]');
                        var aEl = row.querySelector('[data-faq-field="a"]');
                        var q = qEl ? qEl.value.trim() : '';
                        var a = aEl ? aEl.value.trim() : '';
                        if (q !== '' && a !== '') entries.push({ q: q, a: a });
                    });
                    faqOut[calc] = entries;
                });
                faqHidden.value = JSON.stringify(faqOut);
            }
        });

        // ── Add row buttons ──
        document.querySelectorAll('.pps-ss-add[data-target]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var table = document.getElementById(this.dataset.target);
                if (!table) return;
                var tbody = table.querySelector('tbody');
                var firstRow = tbody.querySelector('tr');
                if (!firstRow) return;
                var newRow = firstRow.cloneNode(true);
                newRow.querySelectorAll('input').forEach(function(inp) {
                    if (inp.type === 'checkbox') inp.checked = false;
                    else inp.value = '';
                });
                tbody.appendChild(newRow);
                newRow.querySelector('input').focus();
            });
        });

        // ── Delete row ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.ss-row-del');
            if (!del) return;
            var tr = del.closest('tr');
            var tbody = tr.closest('tbody');
            if (tbody.querySelectorAll('tr').length > 1) tr.remove();
        });

        // ── Add chip ──
        document.querySelectorAll('.pps-chip-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrap = this.closest('.pps-chips-outer').querySelector('[data-pps-chips]');
                var chip = document.createElement('span');
                chip.className = 'pps-chip';
                chip.innerHTML = '<input type="text" value=""> <span class="chip-del">\u00d7</span>';
                wrap.appendChild(chip);
                chip.querySelector('input').focus();
            });
        });

        // ── Delete chip ──
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('chip-del')) e.target.closest('.pps-chip').remove();
        });

        // ── FAQ: add row ──
        document.querySelectorAll('.pps-faq-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var group = this.closest('[data-pps-faq-calc]');
                if (!group) return;
                var rowsWrap = group.querySelector('.pps-faq-rows');
                if (!rowsWrap) return;
                var template = rowsWrap.querySelector('[data-pps-faq-row]');
                var newRow;
                if (template) {
                    newRow = template.cloneNode(true);
                    newRow.querySelectorAll('input, textarea').forEach(function(el) { el.value = ''; });
                } else {
                    newRow = document.createElement('div');
                    newRow.setAttribute('data-pps-faq-row', '1');
                    newRow.className = 'pps-faq-row';
                    newRow.innerHTML =
                        '<input type="text" data-faq-field="q" placeholder="Question" maxlength="512">' +
                        '<textarea data-faq-field="a" placeholder="Answer" rows="3" maxlength="4096"></textarea>' +
                        '<span class="pps-faq-del" title="Delete FAQ">×</span>';
                }
                rowsWrap.appendChild(newRow);
                var firstInput = newRow.querySelector('input,textarea');
                if (firstInput) firstInput.focus();
            });
        });

        // ── FAQ: delete row ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.pps-faq-del');
            if (!del) return;
            var row = del.closest('[data-pps-faq-row]');
            var rowsWrap = row && row.parentNode;
            if (row && rowsWrap) {
                if (rowsWrap.querySelectorAll('[data-pps-faq-row]').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input, textarea').forEach(function(el) { el.value = ''; });
                }
            }
        });

        // ── Tooltips: toggle card expand/collapse ──
        document.addEventListener('click', function(e) {
            var header = e.target.closest('.pps-tip-header');
            if (!header) return;
            if (e.target.closest('.pps-tip-del')) return;
            header.closest('.pps-tip-card').classList.toggle('is-open');
        });

        // ── Tooltips: delete card ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.pps-tip-del');
            if (!del) return;
            var card = del.closest('.pps-tip-card');
            if (card && confirm('Delete this tooltip?')) card.remove();
        });

        // ── Tooltips: delete content block ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.pps-tip-block-del');
            if (!del) return;
            del.closest('.pps-tip-block').remove();
        });

        // ── Tooltips: add content block ──
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.pps-tip-block-add');
            if (!btn) return;
            var blocks = btn.closest('.pps-tip-body').querySelector('.pps-tip-blocks');
            var block = document.createElement('div');
            block.className = 'pps-tip-block';
            block.innerHTML =
                '<select data-tip-block-type>' +
                    '<option value="text">Text</option>' +
                    '<option value="image">Image</option>' +
                    '<option value="video">Video</option>' +
                    '<option value="youtube">YouTube</option>' +
                '</select>' +
                '<div class="pps-tip-block-fields">' +
                    '<textarea data-tip-val="" placeholder="Text content" rows="2"></textarea>' +
                '</div>' +
                '<span class="pps-tip-block-del" title="Remove block">&times;</span>';
            blocks.appendChild(block);
        });

        // ── Tooltips: type change swaps fields ──
        document.addEventListener('change', function(e) {
            if (!e.target.matches('[data-tip-block-type]')) return;
            var type = e.target.value;
            var fieldsDiv = e.target.closest('.pps-tip-block').querySelector('.pps-tip-block-fields');
            if (type === 'text') {
                fieldsDiv.innerHTML = '<textarea data-tip-val="" placeholder="Text content" rows="2"></textarea>';
            } else if (type === 'image') {
                fieldsDiv.innerHTML =
                    '<input type="url" data-tip-val="src" placeholder="Image URL">' +
                    '<input type="text" data-tip-val="alt" placeholder="Alt text">';
            } else if (type === 'video') {
                fieldsDiv.innerHTML =
                    '<input type="url" data-tip-val="src" placeholder="Video URL (.mp4)">' +
                    '<input type="url" data-tip-val="poster" placeholder="Poster image URL (optional)">';
            } else if (type === 'youtube') {
                fieldsDiv.innerHTML =
                    '<input type="url" data-tip-val="src" placeholder="YouTube embed URL">' +
                    '<input type="text" data-tip-val="alt" placeholder="Video title">';
            }
        });

        // ── Tooltips: add new tooltip card ──
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.pps-tip-add');
            if (!btn) return;
            var wrap = document.getElementById('pps-tip-cards');
            if (!wrap) return;
            var card = document.createElement('div');
            card.className = 'pps-tip-card is-open';
            card.innerHTML =
                '<div class="pps-tip-header">' +
                    '<span class="pps-tip-arrow">&#9654;</span>' +
                    '<span class="pps-tip-key">new_tooltip</span>' +
                    '<span class="pps-tip-title-preview"></span>' +
                    '<span class="pps-tip-del" title="Delete tooltip">&times;</span>' +
                '</div>' +
                '<div class="pps-tip-body">' +
                    '<div class="pps-tip-fields">' +
                        '<label>Key (slug)</label>' +
                        '<input type="text" data-tip-key="" value="new_tooltip" placeholder="e.g. vivid">' +
                        '<label>Title</label>' +
                        '<input type="text" data-tip-title="" value="" placeholder="Tooltip heading">' +
                    '</div>' +
                    '<div class="pps-tip-blocks"></div>' +
                    '<button type="button" class="pps-tip-block-add">+ Add Content Block</button>' +
                '</div>';
            wrap.appendChild(card);
            card.querySelector('[data-tip-key]').focus();
        });

        // ── Tooltips: sync key/title to header preview ──
        document.addEventListener('input', function(e) {
            if (e.target.matches('[data-tip-key]')) {
                var card = e.target.closest('.pps-tip-card');
                if (card) card.querySelector('.pps-tip-key').textContent = e.target.value || 'untitled';
            }
            if (e.target.matches('[data-tip-title]')) {
                var card = e.target.closest('.pps-tip-card');
                if (card) card.querySelector('.pps-tip-title-preview').textContent = e.target.value;
            }
        });

        // ── Tooltips: serialize to JSON on submit ──
        var form = document.getElementById('pps-config-form');
        if (form) {
            form.addEventListener('submit', function() {
                var tipHidden = document.querySelector('textarea[name="pps_tooltips_json"]');
                if (!tipHidden) return;
                var out = {};
                document.querySelectorAll('.pps-tip-card').forEach(function(card) {
                    var keyEl = card.querySelector('[data-tip-key]');
                    var titleEl = card.querySelector('[data-tip-title]');
                    var key = keyEl ? keyEl.value.trim().replace(/[^a-z0-9_]/g, '') : '';
                    if (!key) return;
                    var tip = { title: titleEl ? titleEl.value.trim() : '', content: [] };
                    card.querySelectorAll('.pps-tip-block').forEach(function(block) {
                        var typeEl = block.querySelector('[data-tip-block-type]');
                        var type = typeEl ? typeEl.value : 'text';
                        var b = { type: type };
                        if (type === 'text') {
                            var ta = block.querySelector('[data-tip-val=""]');
                            b.value = ta ? ta.value.trim() : '';
                        } else if (type === 'image') {
                            b.src = (block.querySelector('[data-tip-val="src"]') || {}).value || '';
                            b.alt = (block.querySelector('[data-tip-val="alt"]') || {}).value || '';
                        } else if (type === 'video') {
                            b.src = (block.querySelector('[data-tip-val="src"]') || {}).value || '';
                            b.poster = (block.querySelector('[data-tip-val="poster"]') || {}).value || '';
                        } else if (type === 'youtube') {
                            b.src = (block.querySelector('[data-tip-val="src"]') || {}).value || '';
                            b.alt = (block.querySelector('[data-tip-val="alt"]') || {}).value || '';
                        }
                        if (type === 'text' ? b.value : b.src) tip.content.push(b);
                    });
                    out[key] = tip;
                });
                tipHidden.value = JSON.stringify(out);
            }, true);
        }
    });
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════
// HELPER: Render spreadsheet table from array-of-objects
// ═══════════════════════════════════════════════════════════════

function pps_render_spreadsheet( $key, $label, $data, $columns, $hint = '' ) {
    $tid = 'pps-ss-' . $key;
    echo '<div class="pps-ss-section">' . esc_html( $label );
    if ( $hint ) echo ' <span class="pps-ss-hint">' . esc_html( $hint ) . '</span>';
    echo '</div>';

    echo '<textarea name="json_' . esc_attr( $key ) . '" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    echo '<table class="pps-ss" id="' . esc_attr( $tid ) . '" data-pps-table="' . esc_attr( $key ) . '">';
    echo '<thead><tr>';
    foreach ( $columns as $col ) {
        $w = isset( $col['width'] ) ? ' style="width:' . $col['width'] . '"' : '';
        echo '<th' . $w . '>' . esc_html( $col['header'] ) . '</th>';
    }
    echo '<th style="width:20px"></th></tr></thead><tbody>';

    foreach ( $data as $row ) {
        echo '<tr>';
        foreach ( $columns as $col ) {
            $f = $col['field'];
            $v = isset( $row[ $f ] ) ? $row[ $f ] : '';
            echo '<td>';
            if ( $col['type'] === 'text' ) {
                echo '<input type="text" data-field="' . esc_attr( $f ) . '" value="' . esc_attr( $v ) . '">';
            } elseif ( $col['type'] === 'number' ) {
                $step = isset( $col['step'] ) ? $col['step'] : '0.001';
                echo '<input type="number" data-field="' . esc_attr( $f ) . '" value="' . esc_attr( $v ) . '" step="' . $step . '">';
            } elseif ( $col['type'] === 'checkbox' ) {
                echo '<input type="checkbox" data-field="' . esc_attr( $f ) . '"' . ( $v ? ' checked' : '' ) . '>';
            }
            echo '</td>';
        }
        echo '<td class="ss-row-del" title="Delete">×</td></tr>';
    }

    echo '</tbody></table>';
    echo '<button type="button" class="pps-ss-add" data-target="' . esc_attr( $tid ) . '">+ Add Row</button>';
}

// ═══════════════════════════════════════════════════════════════
// HELPER: Render editable chip list for simple arrays
// ═══════════════════════════════════════════════════════════════

function pps_render_chips( $key, $label, $data, $hint = '' ) {
    echo '<div class="pps-ss-section">' . esc_html( $label );
    if ( $hint ) echo ' <span class="pps-ss-hint">' . esc_html( $hint ) . '</span>';
    echo '</div>';

    echo '<textarea name="json_' . esc_attr( $key ) . '" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    echo '<div class="pps-chips-outer">';
    echo '<div class="pps-chips-wrap" data-pps-chips="' . esc_attr( $key ) . '">';
    foreach ( $data as $val ) {
        echo '<span class="pps-chip"><input type="text" value="' . esc_attr( $val ) . '"> <span class="chip-del">×</span></span>';
    }
    echo '</div>';
    echo '<button type="button" class="pps-ss-add pps-chip-add">+ Add</button>';
    echo '</div>';
}

// ═══════════════════════════════════════════════════════════════
// TAB: PRODUCTION
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_production( $cfg ) {
    $pcf = $cfg['pcf'];

    $groups = array(
        'Printing Costs' => array(
            'printing_fullcolor_cost'  => array( 'Full Color /Sheet', '$' ),
            'printing_black_cost'      => array( 'Black Only /Sheet', '$' ),
        ),
        'Labor Rates' => array(
            'labor_press_hr'            => array( 'Press Operator', '$/hr' ),
            'labor_bindery_hr'          => array( 'Bindery', '$/hr' ),
            'labor_cutting_hr'          => array( 'Cutter', '$/hr' ),
            'labor_horizonspf20_hr'     => array( 'Stitcher (SPF-20)', '$/hr' ),
            'labor_horizonspf20_setup'  => array( 'Stitcher Setup', '$ flat' ),
        ),
        'Machine Speeds' => array(
            'press_printsperhour'               => array( 'Press', '/hr' ),
            'bindery_morgana_impressionperhour'  => array( 'Morgana', '/hr' ),
            'perfectbound_binder_throat_in'      => array( 'Perfect Binder Throat', 'in' ),
            'cutter_sheetsperhour'               => array( 'Cutter', '/hr' ),
            'horizonspf20_sheetsperhour'         => array( 'Stitcher', '/hr' ),
            'uvcoaterimpressionsperhour'         => array( 'UV Coater', '/hr' ),
            'roundcornerperhour'                 => array( 'Round Corner', '/hr' ),
            'bundlesperhour'                     => array( 'Bundler', '/hr' ),
        ),
        'Brochure Markup' => array(
            'backend_maximummarkup'  => array( 'Max Markup', '×' ),
            'backend_minimummarkup'  => array( 'Min Markup', '×' ),
            'backend_markup_coef_tS' => array( 'Sheet Decay Coef', '×ln(tS)' ),
        ),
        'Booklet Markup' => array(
            'booklet_maximummarkup'      => array( 'Max Markup', '×' ),
            'booklet_minimummarkup'      => array( 'Min Markup', '×' ),
            'booklet_markup_coef_tS'     => array( 'Sheet Decay Coef', '×ln(tS)' ),
            'booklet_size_discount'      => array( '8.5×11 Size Disc.', '×' ),
            'booklet_8up_markup_bonus'   => array( '8-up Markup Bonus', '×' ),
            'booklet_surcharge'          => array( 'Pricing Surcharge', '×' ),
        ),
        'Booklet Cover Markup' => array(
            'booklet_cover_maximummarkup' => array( 'Cover Max Markup', '×' ),
            'booklet_cover_minimummarkup' => array( 'Cover Min Markup', '×' ),
            'booklet_cover_markup_coef'   => array( 'Cover Decay Coef', '×ln(sheets)' ),
        ),
        'Perfect Bound Markup' => array(
            'perfectbound_maximummarkup'   => array( 'Max Markup', '×' ),
            'perfectbound_minimummarkup'   => array( 'Min Markup', '×' ),
            'perfectbound_markup_coef_tS'  => array( 'Sheet Decay Coef', '×ln(tS)' ),
            'perfectbound_size_discount'   => array( '8.5×11 Size Disc.', '×' ),
            'perfectbound_surcharge'       => array( 'Pricing Surcharge', '×' ),
        ),
        'PB Cover Markup' => array(
            'perfectbound_cover_maximummarkup' => array( 'Cover Max Markup', '×' ),
            'perfectbound_cover_minimummarkup' => array( 'Cover Min Markup', '×' ),
            'perfectbound_cover_markup_coef'   => array( 'Cover Decay Coef', '×ln(sheets)' ),
        ),
        'Coupon Book Markup' => array(
            'couponbook_maximummarkup'    => array( 'Max Markup', '×' ),
            'couponbook_minimummarkup'    => array( 'Min Markup', '×' ),
            'couponbook_markup_coef_tS'   => array( 'Sheet Decay Coef', '×ln(tS)' ),
            'couponbook_size_discount'    => array( '8.5×11 Size Disc.', '×' ),
            'couponbook_surcharge'        => array( 'Pricing Surcharge', '×' ),
            'magneticstripapplication_perhour' => array( 'Magnetic Backer', '/hr' ),
        ),
        'Coupon Cover Markup' => array(
            'booklet_cover_maximummarkup' => array( 'Cover Max Markup', '×' ),
            'booklet_cover_minimummarkup' => array( 'Cover Min Markup', '×' ),
            'booklet_cover_markup_coef'   => array( 'Cover Decay Coef', '×ln(sheets)' ),
        ),
        'Discounts' => array(
            'easydiscount_max'       => array( 'Easy Size Cap', '$' ),
            'easy_discount_rate'     => array( 'Easy Size Rate', '×' ),
            'common_discount_max'    => array( 'Competitive Disc. (>0=on)', '' ),
            'bw_discount_rate'       => array( 'B&W Discount', '×' ),
        ),
        'Site-Wide Sale' => array(
            'sale_discount_pct'      => array( 'Sale % (0 = off)', '×' ),
            'sale_label'             => array( 'Sale Label', '' ),
        ),
        'Question Form' => array(
            'question_recipient_email' => array( 'Recipient Email (blank = admin)', '' ),
        ),
        'Fees' => array(
            'non_inventory_fee'      => array( 'Non-Inventory', '$' ),
            'bundling_base_fee'      => array( 'Bundling Base', '$' ),
            'proof_hardcopy_cost'    => array( 'Hardcopy Proof', '$' ),
            'proof_digital_cost'     => array( 'Digital Proof', '$' ),
            'sets_surcharge'         => array( 'Per-Set Surcharge', '$' ),
            'bleed_minimum'          => array( 'Bleed Minimum', '$' ),
            'two_staple_threshold'   => array( '2-Staple Threshold', 'in' ),
        ),
        'Turnaround' => array(
            'cutterbasefee'                  => array( 'Cutter Base Fee', '$' ),
            'sheetsturnaround'               => array( 'Sheets/Day', 'sheets' ),
            'minimum_turnaround_days'        => array( 'Min Turnaround', 'days' ),
            'addon_roundcornermaxdays'        => array( 'Round Corner Max', 'days' ),
            'sheetsforlowcosthardcopyproof'  => array( 'Low-Cost Proof Threshold', 'sheets' ),
        ),
        'Artwork' => array(
            'art_pagesperhour'       => array( 'Art Pages/Hour', 'pages' ),
            'art_newdesignmodifier'  => array( 'New Design Modifier', '×' ),
        ),
        'Shop Schedule' => array(
            'shop_timezone'        => array( 'Timezone', '' ),
            'shop_cutoff_hour'     => array( 'Cutoff Hour (24h)', 'hr' ),
            'free_shipping_buffer' => array( 'Free Ship Buffer', '×' ),
            'default_transit_days' => array( 'Default Transit', 'days' ),
        ),
        'Shippo Integration' => array(
            'shippo_api_token'     => array( 'API Token', '' ),
            'shippo_origin_zip'    => array( 'Origin ZIP', '' ),
        ),
    );

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:0 24px;">';
    foreach ( $groups as $gLabel => $fields ) {
        echo '<div>';
        echo '<div class="pps-ss-section">' . esc_html( $gLabel ) . '</div>';
        echo '<table class="pps-ss"><tbody>';
        foreach ( $fields as $key => $meta ) {
            $val  = $pcf[ $key ] ?? '';
            $type = in_array( $key, array( 'shop_timezone', 'shippo_api_token', 'shippo_origin_zip', 'sale_label', 'question_recipient_email' ) ) ? 'text' : 'number';
            $step = ( is_numeric( $val ) && ( is_float( $val + 0 ) || strpos( (string) $val, '.' ) !== false ) ) ? '0.001' : '1';
            echo '<tr>';
            echo '<td style="font-weight:600;white-space:nowrap;width:1%;padding-right:10px;font-size:12px">' . esc_html( $meta[0] ) . '</td>';
            echo '<td><input type="' . $type . '" name="pcf[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" step="' . $step . '"></td>';
            if ( $meta[1] ) echo '<td class="ss-unit">' . esc_html( $meta[1] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    // ── Add-on availability (Vivid Print, Two-Staple, Perforation, Outfold) ──
    // The other three add-ons (UV Coating, Bundling, Round Cornering) live on
    // the Finishing tab inline with their spreadsheets. These four don't have
    // their own spreadsheet sections, so they live here.
    pps_render_addon_availability_block( array( 'vivid', 'two_staple', 'perforation', 'outfold' ) );
}

/**
 * Render a compact block of per-add-on availability rows. Used at the bottom
 * of the Production tab for add-ons that have no spreadsheet of their own.
 */
function pps_render_addon_availability_block( $addon_slugs ) {
    echo '<div class="pps-ss-section" style="margin-top:32px">Add-on Availability</div>';
    echo '<p style="margin:-4px 0 8px;color:#666;font-size:12px">Uncheck a box to make the add-on unavailable on that calculator. Customers no longer see the option; pre-filled orders carrying the option will return an error until cleared.</p>';
    foreach ( $addon_slugs as $slug ) {
        pps_render_addon_availability_row( $slug );
    }
}

// ═══════════════════════════════════════════════════════════════
// TAB: PAPERS
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_papers( $cfg ) {
    $cols = array(
        array( 'field' => 'label',    'header' => 'Paper Name',  'type' => 'text',     'width' => '40%' ),
        array( 'field' => 'val',      'header' => 'Val',         'type' => 'number',   'width' => '70px' ),
        array( 'field' => 'price',    'header' => '$/Sheet',     'type' => 'number',   'width' => '80px' ),
        array( 'field' => 'days',     'header' => '+Days',       'type' => 'number',   'width' => '54px' ),
        array( 'field' => 'factory',  'header' => 'Factory',     'type' => 'checkbox', 'width' => '48px' ),
        array( 'field' => 'coatable', 'header' => 'Coat.',       'type' => 'checkbox', 'width' => '44px' ),
        array( 'field' => 'desc',     'header' => 'Customer description (shown in calculators, wizard & category cards)', 'type' => 'text' ),
    );

    pps_render_spreadsheet( 'papers_nc', 'Text Weight Papers', $cfg['papers_nc'], $cols, 'val: 0.00x=in-stock, 2.00x=factory. Blue inventory dot = no factory flag AND 0 days. Blank desc/days fall back to the canonical catalog (docs/PAPER_CATALOG.md).' );
    pps_render_spreadsheet( 'papers_cs', 'Cardstock Papers', $cfg['papers_cs'], $cols, 'val: 0.0x=in-stock, 1.0x=special, 2.2x=factory. Blue inventory dot = no factory flag AND 0 days. Blank desc/days fall back to the canonical catalog.' );

    // Cover same
    echo '<div class="pps-ss-section">Cover "Same as Inside" Default</div>';
    echo '<textarea name="json_cover_same" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $cfg['cover_same'], JSON_UNESCAPED_UNICODE ) ) . '</textarea>';
    $cs = $cfg['cover_same'];
    echo '<div data-pps-single="cover_same" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;font-size:12px;flex-wrap:wrap">';
    echo '<label>Label <input type="text" data-field="label" value="' . esc_attr( $cs['label'] ) . '" style="width:180px;padding:2px 5px;font-size:12px"></label>';
    echo '<label>Val <input type="number" data-field="val" value="' . esc_attr( $cs['val'] ) . '" step="0.001" style="width:60px;padding:2px 5px;font-size:12px"></label>';
    echo '<label>Price <input type="number" data-field="price" value="' . esc_attr( $cs['price'] ) . '" step="0.001" style="width:60px;padding:2px 5px;font-size:12px"></label>';
    echo '<label><input type="checkbox" data-field="factory"' . ( $cs['factory'] ? ' checked' : '' ) . '> Factory</label>';
    echo '<label><input type="checkbox" data-field="coatable"' . ( $cs['coatable'] ? ' checked' : '' ) . '> Coatable</label>';
    echo '</div>';

    pps_render_chips( 'cover_scoring_vals', 'Cover Scoring Vals', $cfg['cover_scoring_vals'], 'thick stocks that need scoring' );
    pps_render_chips( 'inv_nc', 'In-Stock Text Vals', $cfg['inv_nc'], 'no surcharge' );
    pps_render_chips( 'inv_cs', 'In-Stock Cardstock Vals', $cfg['inv_cs'] );
}

// ═══════════════════════════════════════════════════════════════
// TAB: FINISHING
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_finishing( $cfg ) {
    $cols = array(
        array( 'field' => 'label', 'header' => 'Option', 'type' => 'text',   'width' => '50%' ),
        array( 'field' => 'val',   'header' => 'Val',    'type' => 'number', 'width' => '70px' ),
        array( 'field' => 'price', 'header' => 'Price',  'type' => 'number', 'width' => '80px' ),
    );

    pps_render_addon_availability_row( 'coating' );
    pps_render_spreadsheet( 'coatings', 'Coatings', $cfg['coatings'], $cols, 'val=imp/hr, 0=none' );

    pps_render_addon_availability_row( 'bundling' );
    pps_render_spreadsheet( 'bundling', 'Bundling', $cfg['bundling'], $cols, 'price=bundle size' );

    pps_render_addon_availability_row( 'rc' );
    pps_render_spreadsheet( 'corners', 'Round Cornering', $cfg['corners'], $cols );
}

/**
 * Render an "Available on:" checkbox row for a single add-on slug.
 * Slug must exist in pps_addon_visibility_matrix_defaults(). Renders only
 * the calc-type checkboxes that apply to the add-on.
 */
function pps_render_addon_availability_row( $addon_slug ) {
    $matrix = pps_addon_visibility_matrix_defaults();
    if ( ! isset( $matrix[ $addon_slug ] ) ) return;
    $calcs  = $matrix[ $addon_slug ];
    $labels = array(
        'saddle'        => 'Saddle Stitch',
        'perfect-bound' => 'Perfect Bound',
        'brochure'      => 'Brochure',
        'coupon'        => 'Coupon Book',
        'letterhead'    => 'Letterhead',
    );
    $vis    = pps_get_addons_visibility();
    $addon_labels = pps_addon_labels();
    echo '<div style="margin:14px 0 -6px;padding:8px 12px;background:#f6f7f9;border:1px solid #e2e6ec;border-radius:4px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px">';
    echo '<span style="font-weight:700;color:#444">' . esc_html( $addon_labels[ $addon_slug ] ?? $addon_slug ) . ' — available on:</span>';
    foreach ( $calcs as $calc ) {
        $on  = ! empty( $vis[ $addon_slug ][ $calc ] );
        $cb  = '<input type="checkbox" name="addons_visibility[' . esc_attr( $addon_slug ) . '][' . esc_attr( $calc ) . ']" value="1"' . ( $on ? ' checked' : '' ) . '>';
        echo '<label style="display:inline-flex;align-items:center;gap:4px;color:#333;cursor:pointer">' . $cb . esc_html( $labels[ $calc ] ?? $calc ) . '</label>';
    }
    echo '</div>';
}

// ═══════════════════════════════════════════════════════════════
// TAB: ARTWORK
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_artwork( $cfg ) {
    $cols = array(
        array( 'field' => 'label', 'header' => 'Option', 'type' => 'text',   'width' => '50%' ),
        array( 'field' => 'val',   'header' => 'Val',    'type' => 'number', 'width' => '70px' ),
        array( 'field' => 'price', 'header' => '$/hr',   'type' => 'number', 'width' => '80px' ),
    );

    pps_render_spreadsheet( 'art_opts', 'Artwork Options', $cfg['art_opts'], $cols, '0.0x=free, 2.0x=edits, 4.0x=new' );
    pps_render_spreadsheet( 'bleed_opts', 'Bleed Options', $cfg['bleed_opts'], $cols );
}

// ═══════════════════════════════════════════════════════════════
// TAB: SIZES
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_sizes( $cfg ) {
    echo '<textarea name="json_size_presets" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $cfg['size_presets'], JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    foreach ( $cfg['size_presets'] as $gi => $group ) {
        $gname = $group['group'];
        $tid = 'pps-ss-sizes-' . $gi;
        echo '<div class="pps-ss-section">' . esc_html( $gname ) . '</div>';
        echo '<table class="pps-ss" id="' . esc_attr( $tid ) . '" data-pps-size-group="' . esc_attr( $gname ) . '">';
        echo '<thead><tr><th style="width:50%">Size</th><th style="width:60px">Imp</th><th style="width:70px">Bind Edge</th><th style="width:20px"></th></tr></thead><tbody>';
        foreach ( $group['items'] as $item ) {
            echo '<tr>';
            echo '<td><input type="text" data-field="label" value="' . esc_attr( $item['label'] ) . '"></td>';
            echo '<td><input type="number" data-field="imp" value="' . esc_attr( $item['imp'] ) . '" step="1"></td>';
            echo '<td><input type="number" data-field="bindEdge" value="' . esc_attr( $item['bindEdge'] ) . '" step="0.25"></td>';
            echo '<td class="ss-row-del" title="Delete">×</td></tr>';
        }
        echo '</tbody></table>';
        echo '<button type="button" class="pps-ss-add" data-target="' . esc_attr( $tid ) . '">+ Add Size</button>';
    }

    pps_render_chips( 'page_counts', 'Page Counts', $cfg['page_counts'], 'multiples of 4' );
}

// ═══════════════════════════════════════════════════════════════
// TAB: SHIPPING
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_shipping( $cfg ) {
    $transit = $cfg['transit_days'];

    // ── Zone Map (3-digit ZIP prefix → transit days) ──
    $zone_map = get_option( 'pps_ups_zone_map', array() );
    $has_map  = ! empty( $zone_map ) && is_array( $zone_map );

    echo '<div class="pps-ss-section">UPS Ground Zone Map (ZIP Prefix → Transit Days)</div>';

    if ( $has_map ) {
        $dist = array();
        foreach ( $zone_map as $pfx => $d ) {
            $dist[ $d ] = ( $dist[ $d ] ?? 0 ) + 1;
        }
        ksort( $dist );
        $colors = array( 1 => '#e0f7e0', 2 => '#eaf7ea', 3 => '#e8f4ff', 4 => '#eef0ff', 5 => '#f5f0ff', 6 => '#fff3e0', 7 => '#ffe8e0', 9 => '#fce4e4' );
        echo '<div style="margin-bottom:10px;padding:8px 12px;background:#f7fbff;border:1px solid #d0e3f1;border-radius:4px;font-size:12px">';
        echo '<strong style="color:#0073aa">✓ Zone map loaded</strong> — ' . count( $zone_map ) . ' prefixes &nbsp;·&nbsp; ';
        foreach ( $dist as $d => $cnt ) {
            $d_int = is_numeric( $d ) ? intval( $d ) : -1;
            $is_valid = $d_int >= 1 && $d_int <= 14;
            $bg = $is_valid ? ( $colors[ $d_int ] ?? '#f0f0f1' ) : '#fce4e4';
            $label = $is_valid ? ( $d_int . 'd' ) : ( '?(' . esc_html( (string) $d ) . ')' );
            echo '<span style="display:inline-block;background:' . $bg . ';padding:1px 6px;border-radius:3px;margin-right:4px;font-size:11px">' . $label . ': ' . intval( $cnt ) . '</span>';
        }
        $updated = get_option( 'pps_ups_zone_map_updated', '' );
        if ( $updated ) echo '<br><span style="color:#888;font-size:11px">Last updated: ' . esc_html( $updated ) . '</span>';
        echo '</div>';
    } else {
        echo '<div style="margin-bottom:10px;padding:8px 12px;background:#fff3e0;border:1px solid #f0c36d;border-radius:4px;font-size:12px">';
        echo '<strong>⚠ No zone map loaded</strong> — using state-level transit fallback. Upload a UPS zone chart or CSV for zip-level accuracy.';
        echo '</div>';
    }

    echo '<div style="margin-bottom:14px;padding:10px 14px;background:#fafafa;border:1px solid #ddd;border-radius:4px">';
    echo '<div style="font-size:11px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Update Zone Map</div>';

    // Upload zone chart — accepts the raw file UPS provides (XLS/XLSX) or CSV
    echo '<div style="margin-bottom:8px">';
    echo '<label style="font-size:12px;font-weight:600;color:#333">Upload UPS Zone Chart (XLS, XLSX, or CSV):</label><br>';
    echo '<input type="file" name="ups_zone_csv" accept=".csv,.txt,.xls,.xlsx" style="margin-top:4px">';
    echo '<div style="font-size:11px;color:#888;margin-top:2px">Go to <a href="https://www.ups.com/us/en/support/shipping-support/shipping-costs-rates/daily-rates" target="_blank">UPS Daily Rates</a> → Download Zone Chart for your origin ZIP → Upload the file here as-is. No conversion needed.</div>';
    echo '</div>';

    // Or paste JSON
    echo '<div>';
    echo '<label style="font-size:12px;font-weight:600;color:#333">Or paste zone map JSON:</label><br>';
    echo '<textarea name="zone_map_json" rows="3" style="width:100%;font-size:11px;font-family:monospace;margin-top:4px" placeholder=\'{"010":5,"020":5,"100":5,...}\'></textarea>';
    echo '</div>';
    echo '</div>';

    // State fallback
    echo '<div class="pps-ss-section">State Transit Fallback (used when zone map is missing a prefix)</div>';
    echo '<textarea name="json_transit_days" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $transit, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    // Group by day count
    $by_day = array();
    foreach ( $transit as $st => $d ) $by_day[ $d ][] = $st;
    ksort( $by_day );

    $colors = array( 1 => '#e0f7e0', 2 => '#eaf7ea', 3 => '#e8f4ff', 4 => '#eef0ff', 5 => '#f5f0ff', 6 => '#fff3e0', 7 => '#ffe8e0', 9 => '#fce4e4' );

    echo '<div style="margin-bottom:12px">';
    foreach ( $by_day as $days => $states ) {
        sort( $states );
        $bg = isset( $colors[ $days ] ) ? $colors[ $days ] : '#f0f0f1';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;padding:3px 8px;background:' . $bg . ';border-radius:3px">';
        echo '<strong style="font-size:12px;min-width:42px;color:#555">' . $days . ' day' . ( $days > 1 ? 's' : '' ) . '</strong>';
        echo '<div class="pps-transit-grid">';
        foreach ( $states as $st ) {
            echo '<div class="pps-transit-cell"><label>' . esc_html( $st ) . '</label>';
            echo '<input type="number" data-state="' . esc_attr( $st ) . '" value="' . esc_attr( $days ) . '" min="1" max="14"></div>';
        }
        echo '</div></div>';
    }
    echo '</div>';

    pps_render_chips( 'closures', 'Shop Closures', $cfg['closures'], 'MM-DD annual or YYYY-MM-DD one-off' );
}

// ═══════════════════════════════════════════════════════════════
// TAB: SEO  (GBP rating + per-calc-type FAQs)
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_seo( $cfg ) {
    $seo = isset( $cfg['seo'] ) && is_array( $cfg['seo'] ) ? $cfg['seo'] : array();

    // ── Google Business Profile (aggregateRating on LocalBusiness) ──
    echo '<div class="pps-ss-section">Google Business Profile (aggregateRating on LocalBusiness) <span class="pps-ss-hint">stars next to your brand in SERPs</span></div>';
    echo '<div class="pps-seo-grid">';

    echo '<div class="pps-seo-field">';
    echo '<label for="pps-gbp-url">Google Business Profile URL</label>';
    echo '<input id="pps-gbp-url" type="url" name="seo[gbp_url]" value="' . esc_attr( $seo['gbp_url'] ?? '' ) . '" placeholder="https://maps.app.goo.gl/...">';
    echo '<span class="hint">Optional. Linked from aggregateRating.</span>';
    echo '</div>';

    echo '<div class="pps-seo-field">';
    echo '<label for="pps-gbp-rating">Average rating (0.0&ndash;5.0)</label>';
    echo '<input id="pps-gbp-rating" type="number" name="seo[gbp_rating_value]" value="' . esc_attr( $seo['gbp_rating_value'] ?? 0 ) . '" min="0" max="5" step="0.1">';
    echo '<span class="hint">From your live GBP. Update quarterly.</span>';
    echo '</div>';

    echo '<div class="pps-seo-field">';
    echo '<label for="pps-gbp-count">Review count</label>';
    echo '<input id="pps-gbp-count" type="number" name="seo[gbp_review_count]" value="' . esc_attr( $seo['gbp_review_count'] ?? 0 ) . '" min="0" step="1">';
    echo '<span class="hint">Total review count on your GBP.</span>';
    echo '</div>';

    echo '</div>';
    echo '<p style="font-size:11px;color:#888;margin:0 0 18px">aggregateRating is only emitted when both rating &gt; 0 and review count &gt; 0. Schema.org policy: ratings must be from real users — these mirror your live Google Business Profile, do not fabricate.</p>';

    // ── FAQs per calc type ──
    echo '<div class="pps-ss-section">FAQ Schema (per calculator type) <span class="pps-ss-hint">emitted as FAQPage JSON-LD on calculator product pages</span></div>';

    // Hidden JSON field — JS serializes form into this on submit
    $current_faqs = get_option( 'pps_faqs', array() );
    if ( ! is_array( $current_faqs ) ) $current_faqs = array();
    echo '<textarea name="pps_faqs_json" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $current_faqs, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    $calcs = array(
        'saddle'        => 'Saddle Stitch Booklets',
        'perfect-bound' => 'Perfect Bound Booklets',
        'brochure'      => 'Brochures',
        'coupon'        => 'Coupon Books',
        'letterhead'    => 'Letterhead',
    );

    $defaults = function_exists( 'pps_default_faqs' ) ? pps_default_faqs() : array();

    foreach ( $calcs as $calc_key => $calc_label ) {
        // Use saved if present, else fall back to default content (so admin sees something to edit)
        $entries = array();
        if ( isset( $current_faqs[ $calc_key ] ) && is_array( $current_faqs[ $calc_key ] ) ) {
            $entries = $current_faqs[ $calc_key ];
        } elseif ( isset( $defaults[ $calc_key ] ) && is_array( $defaults[ $calc_key ] ) ) {
            $entries = $defaults[ $calc_key ];
        }
        if ( empty( $entries ) ) {
            // Provide one blank row so the JS template-clone has something to copy
            $entries = array( array( 'q' => '', 'a' => '' ) );
        }

        echo '<div class="pps-faq-calc" data-pps-faq-calc="' . esc_attr( $calc_key ) . '">';
        echo '<div class="pps-faq-calc-title">' . esc_html( $calc_label ) . '</div>';
        echo '<div class="pps-faq-rows">';
        foreach ( $entries as $entry ) {
            $q = isset( $entry['q'] ) ? (string) $entry['q'] : '';
            $a = isset( $entry['a'] ) ? (string) $entry['a'] : '';
            echo '<div class="pps-faq-row" data-pps-faq-row="1">';
            echo '<div class="pps-faq-q-a">';
            echo '<input type="text" data-faq-field="q" value="' . esc_attr( $q ) . '" placeholder="Question" maxlength="512">';
            echo '<textarea data-faq-field="a" rows="3" placeholder="Answer" maxlength="4096">' . esc_textarea( $a ) . '</textarea>';
            echo '</div>';
            echo '<span class="pps-faq-del" title="Delete FAQ">&times;</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '<button type="button" class="pps-faq-add">+ Add FAQ</button>';
        echo '</div>';
    }

    echo '<p style="font-size:11px;color:#888;margin:0">Empty entries are not emitted. Per-calc cap: 50 FAQs. Q max 512 chars, A max 4096 chars. HTML in answers is stripped at emit time (schema.org Answer.text is plain text).</p>';
}

// ═══════════════════════════════════════════════════════════════
// TAB: TOOLTIPS
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_tooltips( $cfg ) {
    $tips = get_option( 'pps_tooltips', array() );
    if ( ! is_array( $tips ) ) $tips = array();
    if ( empty( $tips ) && function_exists( 'pps_default_tooltips' ) ) {
        $tips = pps_default_tooltips();
    }

    echo '<textarea name="pps_tooltips_json" class="pps-json-hidden">' . esc_textarea( wp_json_encode( $tips, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    echo '<div class="pps-ss-section">RichTip Tooltips <span class="pps-ss-hint">shown on calculator pages &amp; category pages via tipKey</span></div>';
    echo '<p style="font-size:11px;color:#888;margin:0 0 10px">Each tooltip has a key (used in code via <code>tipKey="key"</code>), a title, and one or more content blocks. Blocks can be text, image, video, or YouTube embed. Click a row to expand.</p>';

    echo '<div id="pps-tip-cards">';
    foreach ( $tips as $key => $tip ) {
        $title   = $tip['title'] ?? '';
        $blocks  = ( isset( $tip['content'] ) && is_array( $tip['content'] ) ) ? $tip['content'] : array();

        echo '<div class="pps-tip-card">';
        echo   '<div class="pps-tip-header">';
        echo     '<span class="pps-tip-arrow">&#9654;</span>';
        echo     '<span class="pps-tip-key">' . esc_html( $key ) . '</span>';
        echo     '<span class="pps-tip-title-preview">' . esc_html( $title ) . '</span>';
        echo     '<span class="pps-tip-count">' . count( $blocks ) . ' block' . ( count( $blocks ) !== 1 ? 's' : '' ) . '</span>';
        echo     '<span class="pps-tip-del" title="Delete tooltip">&times;</span>';
        echo   '</div>';
        echo   '<div class="pps-tip-body">';
        echo     '<div class="pps-tip-fields">';
        echo       '<label>Key (slug)</label>';
        echo       '<input type="text" data-tip-key="" value="' . esc_attr( $key ) . '" placeholder="e.g. vivid">';
        echo       '<label>Title</label>';
        echo       '<input type="text" data-tip-title="" value="' . esc_attr( $title ) . '" placeholder="Tooltip heading">';
        echo     '</div>';
        echo     '<div class="pps-tip-blocks">';
        foreach ( $blocks as $block ) {
            $type = $block['type'] ?? 'text';
            echo '<div class="pps-tip-block">';
            echo   '<select data-tip-block-type>';
            foreach ( array( 'text', 'image', 'video', 'youtube' ) as $opt ) {
                $sel = $opt === $type ? ' selected' : '';
                echo '<option value="' . $opt . '"' . $sel . '>' . ucfirst( $opt ) . '</option>';
            }
            echo   '</select>';
            echo   '<div class="pps-tip-block-fields">';
            if ( $type === 'text' ) {
                echo '<textarea data-tip-val="" placeholder="Text content" rows="2">' . esc_textarea( $block['value'] ?? '' ) . '</textarea>';
            } elseif ( $type === 'image' ) {
                echo '<input type="url" data-tip-val="src" placeholder="Image URL" value="' . esc_attr( $block['src'] ?? '' ) . '">';
                echo '<input type="text" data-tip-val="alt" placeholder="Alt text" value="' . esc_attr( $block['alt'] ?? '' ) . '">';
            } elseif ( $type === 'video' ) {
                echo '<input type="url" data-tip-val="src" placeholder="Video URL (.mp4)" value="' . esc_attr( $block['src'] ?? '' ) . '">';
                echo '<input type="url" data-tip-val="poster" placeholder="Poster image URL" value="' . esc_attr( $block['poster'] ?? '' ) . '">';
            } elseif ( $type === 'youtube' ) {
                echo '<input type="url" data-tip-val="src" placeholder="YouTube embed URL" value="' . esc_attr( $block['src'] ?? '' ) . '">';
                echo '<input type="text" data-tip-val="alt" placeholder="Video title" value="' . esc_attr( $block['alt'] ?? '' ) . '">';
            }
            echo   '</div>';
            echo   '<span class="pps-tip-block-del" title="Remove block">&times;</span>';
            echo '</div>';
        }
        echo     '</div>';
        echo     '<button type="button" class="pps-tip-block-add">+ Add Content Block</button>';
        echo   '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<button type="button" class="pps-tip-add">+ Add Tooltip</button>';
    echo '<p style="font-size:11px;color:#888;margin:8px 0 0">Max 100 tooltips, 20 blocks per tooltip. Keys must be lowercase alphanumeric with underscores. Changes here update both calculator pages and category pages.</p>';
}
