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
            'backend_maximummarkup'             => 15.2,
            'backend_minimummarkup'             => 3.5,
            'easydiscount_max'                  => 0,
            // Booklet-specific markup (separate from brochure backend_* values)
            'booklet_maximummarkup'             => 8,
            'booklet_minimummarkup'             => 1.5,
            'booklet_size_discount'             => 0.15,
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
            'bw_discount_rate'                  => 0.3,
            'easy_discount_rate'                => 0.05,
            'common_discount_max'               => 0,
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
        ),

        'papers_nc' => array(
            array( 'label' => '70lb Uncoated Opaque Text',  'val' => 0.001, 'price' => 0.06,   'factory' => false, 'coatable' => false ),
            array( 'label' => '80lb Matte Text',            'val' => 0.002, 'price' => 0.069,  'factory' => false, 'coatable' => false ),
            array( 'label' => '100lb Gloss Text',           'val' => 0.003, 'price' => 0.085,  'factory' => false, 'coatable' => true ),
            array( 'label' => '50lb Offset Smooth Opaque',  'val' => 2.001, 'price' => 0.039,  'factory' => true,  'coatable' => false ),
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
    );
}

// ═══════════════════════════════════════════════════════════════
// GET / SAVE CONFIG
// ═══════════════════════════════════════════════════════════════

function pps_get_config() {
    $defaults = pps_default_config();
    $saved    = get_option( PPS_CONFIG_OPTION, array() );
    if ( ! is_array( $saved ) ) $saved = array();

    $result = array_replace( $defaults, $saved );
    $result['pcf'] = array_merge(
        $defaults['pcf'],
        isset( $saved['pcf'] ) && is_array( $saved['pcf'] ) ? $saved['pcf'] : array()
    );

    return $result;
}

function pps_save_config( $data ) {
    update_option( PPS_CONFIG_OPTION, $data, false );
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
// UPS ZONE CHART CSV PARSER
// ═══════════════════════════════════════════════════════════════

/**
 * Parse a UPS zone chart CSV into a 3-digit ZIP prefix → transit days map.
 *
 * UPS CSV format:
 *   Row 1: "ZONE CHART"
 *   Row 2: service names
 *   Row 3: origin info
 *   Row 4: "ZONES"
 *   Row 5: header — "Dest. ZIP", "Ground", "3 Day Select", ...
 *   Row 6+: data  — "004-005", "5", "305", ...  or  "420", "6", ...
 *
 * Zone → transit days: 2=1d, 3=2d, 4=3d, 5=4d, 6=5d, 7=6d, 8=7d
 */
function pps_parse_ups_zone_csv( $raw ) {
    $zone_to_days = array( 2 => 1, 3 => 2, 4 => 3, 5 => 4, 6 => 5, 7 => 6, 8 => 7 );
    $map = array();

    // Normalize line endings
    $raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );
    $lines = explode( "\n", $raw );

    // Find the header row (contains "Dest" or "Ground")
    $header_idx = -1;
    $ground_col = 1; // default: second column
    foreach ( $lines as $idx => $line ) {
        if ( stripos( $line, 'Dest' ) !== false || stripos( $line, 'Ground' ) !== false ) {
            $cols = str_getcsv( $line );
            foreach ( $cols as $ci => $ch ) {
                if ( stripos( trim( $ch ), 'Ground' ) !== false ) {
                    $ground_col = $ci;
                    break;
                }
            }
            $header_idx = $idx;
            break;
        }
    }

    // Parse data rows
    $start = ( $header_idx >= 0 ) ? $header_idx + 1 : 0;
    foreach ( array_slice( $lines, $start ) as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;

        $cols = str_getcsv( $line );
        if ( count( $cols ) < 2 ) continue;

        $dest = trim( $cols[0], ' "' );
        $zone_raw = trim( $cols[ $ground_col ] ?? $cols[1] ?? '', ' "' );

        // Skip non-numeric zones (dashes, empty)
        if ( ! is_numeric( $zone_raw ) ) continue;
        $zone = intval( $zone_raw );
        $days = isset( $zone_to_days[ $zone ] ) ? $zone_to_days[ $zone ] : max( 1, $zone - 1 );

        // Parse dest — could be "004-005" range or "420" single
        if ( strpos( $dest, '-' ) !== false ) {
            $parts = explode( '-', $dest );
            $lo = intval( trim( $parts[0] ) );
            $hi = intval( trim( $parts[1] ) );
            for ( $i = $lo; $i <= $hi; $i++ ) {
                $map[ str_pad( $i, 3, '0', STR_PAD_LEFT ) ] = $days;
            }
        } else {
            $pfx = str_pad( intval( $dest ), 3, '0', STR_PAD_LEFT );
            $map[ $pfx ] = $days;
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

    return $map;
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
            'cover_scoring_vals', 'inv_nc', 'inv_cs',
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

        pps_save_config( $cfg );

        // ── Zone Map (separate wp_option) ──
        $zone_updated = false;

        // CSV file upload
        if ( ! empty( $_FILES['ups_zone_csv']['tmp_name'] ) && $_FILES['ups_zone_csv']['error'] === UPLOAD_ERR_OK ) {
            $csv_data = file_get_contents( $_FILES['ups_zone_csv']['tmp_name'] );
            $zone_result = pps_parse_ups_zone_csv( $csv_data );
            if ( ! empty( $zone_result ) ) {
                update_option( 'pps_ups_zone_map', $zone_result, false );
                update_option( 'pps_ups_zone_map_updated', current_time( 'mysql' ), false );
                $zone_updated = true;
            } else {
                $json_errors[] = 'Zone CSV: Could not parse — ensure it is a UPS zone chart saved as CSV';
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
            'cutter_sheetsperhour'               => array( 'Cutter', '/hr' ),
            'horizonspf20_sheetsperhour'         => array( 'Stitcher', '/hr' ),
            'uvcoaterimpressionsperhour'         => array( 'UV Coater', '/hr' ),
            'roundcornerperhour'                 => array( 'Round Corner', '/hr' ),
            'bundlesperhour'                     => array( 'Bundler', '/hr' ),
        ),
        'Brochure Markup' => array(
            'backend_maximummarkup'  => array( 'Max Markup', '×' ),
            'backend_minimummarkup'  => array( 'Min Markup', '×' ),
        ),
        'Booklet Markup' => array(
            'booklet_maximummarkup'  => array( 'Max Markup', '×' ),
            'booklet_minimummarkup'  => array( 'Min Markup', '×' ),
            'booklet_size_discount'  => array( '8.5×11 Size Disc.', '×' ),
        ),
        'Discounts' => array(
            'easydiscount_max'       => array( 'Easy Size Cap', '$' ),
            'easy_discount_rate'     => array( 'Easy Size Rate', '×' ),
            'common_discount_max'    => array( 'Common Size Cap', '$' ),
            'bw_discount_rate'       => array( 'B&W Discount', '×' ),
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
            $type = in_array( $key, array( 'shop_timezone', 'shippo_api_token', 'shippo_origin_zip' ) ) ? 'text' : 'number';
            $step = ( is_float( $val + 0 ) || strpos( (string) $val, '.' ) !== false ) ? '0.001' : '1';
            echo '<tr>';
            echo '<td style="font-weight:600;white-space:nowrap;width:1%;padding-right:10px;font-size:12px">' . esc_html( $meta[0] ) . '</td>';
            echo '<td><input type="' . $type . '" name="pcf[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" step="' . $step . '"></td>';
            if ( $meta[1] ) echo '<td class="ss-unit">' . esc_html( $meta[1] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

// ═══════════════════════════════════════════════════════════════
// TAB: PAPERS
// ═══════════════════════════════════════════════════════════════

function pps_config_tab_papers( $cfg ) {
    $cols = array(
        array( 'field' => 'label',    'header' => 'Paper Name',  'type' => 'text',     'width' => '40%' ),
        array( 'field' => 'val',      'header' => 'Val',         'type' => 'number',   'width' => '70px' ),
        array( 'field' => 'price',    'header' => '$/Sheet',     'type' => 'number',   'width' => '80px' ),
        array( 'field' => 'factory',  'header' => 'Factory',     'type' => 'checkbox', 'width' => '48px' ),
        array( 'field' => 'coatable', 'header' => 'Coat.',       'type' => 'checkbox', 'width' => '44px' ),
    );

    pps_render_spreadsheet( 'papers_nc', 'Text Weight Papers', $cfg['papers_nc'], $cols, 'val: 0.00x=in-stock, 2.00x=factory' );
    pps_render_spreadsheet( 'papers_cs', 'Cardstock Papers', $cfg['papers_cs'], $cols, 'val: 0.0x=in-stock, 1.0x=special, 2.2x=factory' );

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

    pps_render_spreadsheet( 'coatings', 'Coatings', $cfg['coatings'], $cols, 'val=imp/hr, 0=none' );
    pps_render_spreadsheet( 'bundling', 'Bundling', $cfg['bundling'], $cols, 'price=bundle size' );
    pps_render_spreadsheet( 'corners', 'Round Cornering', $cfg['corners'], $cols );
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
            $bg = $colors[ $d ] ?? '#f0f0f1';
            echo '<span style="display:inline-block;background:' . $bg . ';padding:1px 6px;border-radius:3px;margin-right:4px;font-size:11px">' . $d . 'd: ' . $cnt . '</span>';
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

    // Upload CSV
    echo '<div style="margin-bottom:8px">';
    echo '<label style="font-size:12px;font-weight:600;color:#333">Upload UPS Zone Chart (CSV or XLS saved as CSV):</label><br>';
    echo '<input type="file" name="ups_zone_csv" accept=".csv,.txt" style="margin-top:4px">';
    echo '<div style="font-size:11px;color:#888;margin-top:2px">Go to <a href="https://www.ups.com/us/en/support/shipping-support/shipping-costs-rates/daily-rates" target="_blank">UPS Daily Rates</a> → Download Zone Chart for your origin ZIP → Open in Excel → Save As CSV → Upload here.</div>';
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
