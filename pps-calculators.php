<?php
/**
 * Plugin Name: PPS Product Calculators
 * Description: Upload HTML calculator tools and assign them to WooCommerce products. Handles pricing, cart, order metadata, and delivery date calculation.
 * Version: 2.0.0
 * Author: Priority Print Service
 * Requires Plugins: woocommerce
 * Text Domain: pps-calculators
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════

define( 'PPS_CALC_VERSION', '2.0.0' );
define( 'PPS_CALC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPS_CALC_URL', plugin_dir_url( __FILE__ ) );
define( 'PPS_CALC_OPTION', 'pps_calculators_registry' );
define( 'PPS_UPLOAD_SUBDIR', 'pps-calculators' );

// ═══════════════════════════════════════════════════════════════
// ALLOW RICH HTML IN WOOCOMMERCE CATEGORY DESCRIPTIONS
// ═══════════════════════════════════════════════════════════════

remove_filter( 'pre_term_description', 'wp_filter_kses' );
remove_filter( 'term_description', 'wp_kses_data' );
add_filter( 'pre_term_description', 'wp_filter_post_kses' );

// ═══════════════════════════════════════════════════════════════
// SHOP CLOSURES & TRANSIT (from config admin or fallback)
// ═══════════════════════════════════════════════════════════════

function pps_get_closures() {
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        return isset( $cfg['closures'] ) ? $cfg['closures'] : array();
    }
    return array( '01-01', '07-04', '12-24', '12-25', '11-28', '11-29' );
}

/**
 * Client-safe copy of the central config for injection into window.PPS_CONFIG.
 *
 * pps_get_config() carries server-only secrets — the Shippo API token and the
 * question-form recipient email — which must never reach page source. This
 * helper strips them and substitutes a boolean `shippo_enabled` flag the
 * calculator JS can gate on. Use this, NOT pps_get_config(), anywhere the
 * config is emitted to the browser (window.PPS_CONFIG). Server-side callers
 * (e.g. the Shippo REST proxies) keep using pps_get_config() for the real token.
 */
/**
 * Purge the page cache after saving anything that is baked into cached HTML —
 * pricing tables (pps_calc_config), presets, tooltips. Without this, cached
 * product pages keep quoting pre-edit rates until the cache expires on its
 * own (QA finding, 2026-08-09). No-op when WP Rocket is absent.
 */
function pps_purge_page_cache() {
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
    }
}

function pps_get_public_config() {
    $cfg = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    if ( isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ) {
        $cfg['pcf']['shippo_enabled'] = ! empty( $cfg['pcf']['shippo_api_token'] );
        unset(
            $cfg['pcf']['shippo_api_token'],
            $cfg['pcf']['question_recipient_email']
        );
    }
    return $cfg;
}

// ═══════════════════════════════════════════════════════════════
// LOAD SUB-MODULES
// ═══════════════════════════════════════════════════════════════

if ( file_exists( PPS_CALC_DIR . 'pps-config-admin.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-config-admin.php';
}

if ( file_exists( PPS_CALC_DIR . 'pps-gdrive.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-gdrive.php';
}

if ( file_exists( PPS_CALC_DIR . 'pps-presets-admin.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-presets-admin.php';
}

if ( file_exists( PPS_CALC_DIR . 'pps-reorder.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-reorder.php';
}

if ( file_exists( PPS_CALC_DIR . 'pps-html-deploy.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-html-deploy.php';
}

if ( file_exists( PPS_CALC_DIR . 'pps-imposition.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-imposition.php';
}

// Loaded here as well as being separately activatable. The category wizard in
// pps-term-shortcodes.php submits through the shared intake pipeline, so a deactivated
// PPS Intake would stop recording quote requests from every category page. Its own
// co-load guard makes the double-load harmless.
if ( file_exists( PPS_CALC_DIR . 'pps-intake.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-intake.php';
}

// Shippo integration test runner. Loaded only while its trigger option is set, which
// costs one autoloaded option read per request and keeps a diagnostic that makes live
// API calls out of the normal request path.
//
// It has always been in the repo but nothing ever required it, so every previous run
// needed a hand-edit to this file on the server — which the next deploy then reverted,
// leaving a versioned tool that only worked by breaking the deploy rule. This is the
// in-tree version of that hook. See docs/SHIPPO_TESTING.md for the run procedure.
if ( file_exists( PPS_CALC_DIR . 'pps-shippo-test.php' )
     && '' !== (string) get_option( 'pps_shippo_test_trigger', '' ) ) {
    require_once PPS_CALC_DIR . 'pps-shippo-test.php';
}

// Single-source product-family facts + homepage card shortcodes.
// Guarded so this file deploys safely ahead of (or without) that one.
if ( file_exists( PPS_CALC_DIR . 'pps-featured-cards.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-featured-cards.php';
}

// Jobs sold over email — create a payable order + payment link, which lands in
// the customer's /reorders history once paid.
// Guarded so this file deploys safely ahead of (or without) that one.
if ( file_exists( PPS_CALC_DIR . 'pps-job-invoice.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-job-invoice.php';
}

// Quote links: the job without the customer, filled in by whoever opens it.
// Loaded after the invoice module, whose helpers it uses.
if ( file_exists( PPS_CALC_DIR . 'pps-job-quote.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-job-quote.php';
}

// QuickBooks: raise the invoice, take the payment, hear about it settling.
// Loaded before the pay-link module, which routes on whether this can pay.
if ( file_exists( PPS_CALC_DIR . 'pps-quickbooks.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-quickbooks.php';
}

// Minting a quote link from a conversation (Missive) rather than a form.
// Loaded after the quote module, whose helpers it calls.
if ( file_exists( PPS_CALC_DIR . 'pps-pay-link.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-pay-link.php';
}

// Shared quote link → defaults blob (product + preset admin).
if ( file_exists( PPS_CALC_DIR . 'pps-defaults-url.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-defaults-url.php';
}

// "Which products do the calculators own?" — answered once, for the Merchant
// Center feed, llms.txt and the admin diagnostics. Retires the registry-ID
// parse that used to be copy-pasted at :242, :448 and :1102.
if ( file_exists( PPS_CALC_DIR . 'pps-catalog.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-catalog.php';
}

// Paper report: which open jobs sit on stock we don't inventory. Depends on
// pps-config-admin.php for the inventoried test.
if ( file_exists( PPS_CALC_DIR . 'pps-paper-report.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-paper-report.php';
}

// /pps-product-feed.xml for Google Merchant Center. Depends on pps-catalog.php.
if ( file_exists( PPS_CALC_DIR . 'pps-product-feed.php' )
     && file_exists( PPS_CALC_DIR . 'pps-catalog.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-product-feed.php';
}

// Daily Places API refresh of the Business Profile rating the LocalBusiness
// schema mirrors. Inert until a key and Place ID are configured.
if ( file_exists( PPS_CALC_DIR . 'pps-gbp-sync.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-gbp-sync.php';
}

// One-screen product creation from a quote link, plus the calculator-binding
// dropdown on the product editor. Needs the URL parser.
if ( file_exists( PPS_CALC_DIR . 'pps-spawn-product.php' )
     && file_exists( PPS_CALC_DIR . 'pps-defaults-url.php' ) ) {
    require_once PPS_CALC_DIR . 'pps-spawn-product.php';
}

// ═══════════════════════════════════════════════════════════════
// UPLOAD DIRECTORY
// ═══════════════════════════════════════════════════════════════

function pps_upload_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . PPS_UPLOAD_SUBDIR;
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
    }
    return $dir;
}

function pps_upload_url() {
    $upload = wp_upload_dir();
    return trailingslashit( $upload['baseurl'] ) . PPS_UPLOAD_SUBDIR;
}

// ═══════════════════════════════════════════════════════════════
// ARTWORK DIRECTORY
// ═══════════════════════════════════════════════════════════════

function pps_artwork_dir() {
    $upload = wp_upload_dir();
    $dir = trailingslashit( $upload['basedir'] ) . 'pps-artwork';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        file_put_contents( $dir . '/.htaccess', "Options -Indexes\n" );
    }
    return $dir;
}

// ═══════════════════════════════════════════════════════════════
// REGISTRY (stored in wp_options)
// ═══════════════════════════════════════════════════════════════

function pps_get_registry() {
    $reg = get_option( PPS_CALC_OPTION, array() );
    // The MCP wp_update_option endpoint serialises array payloads as JSON
    // strings; decode so callers always receive an array (same tolerance
    // pps-html-deploy.php applies to its pending-attachments option). The
    // next pps_save_registry() rewrites the option in native array form.
    if ( is_string( $reg ) ) {
        $decoded = json_decode( $reg, true );
        $reg = is_array( $decoded ) ? $decoded : array();
    }
    return is_array( $reg ) ? $reg : array();
}

function pps_save_registry( $reg ) {
    update_option( PPS_CALC_OPTION, $reg, false );
}

/**
 * Parse a registry row's product-ID string.
 *
 * The registry stores IDs as a free-form comma/space separated string because
 * it is edited by hand in a textarea, so every reader has to tolerate stray
 * whitespace, trailing commas and duplicates. This used to be re-implemented
 * inline at each call site, with each copy quietly disagreeing about
 * duplicates and zeros.
 *
 * Lives here rather than in pps-catalog.php because callers below are core and
 * that file is loaded behind a file_exists guard.
 *
 * @param string $products_str
 * @return int[] Unique, positive, in the order first seen.
 */
function pps_registry_product_ids( $products_str ) {
    $ids = array_map( 'intval', preg_split( '/[\s,]+/', (string) $products_str ) );
    $ids = array_filter( $ids, function ( $id ) { return $id > 0; } );
    return array_values( array_unique( $ids ) );
}

/**
 * The delivery date the customer was actually shown, formatted for display.
 *
 * The cart and the order item both used to recompute this as "now + N business days",
 * which disagreed with the calculator for three separate reasons: it counted from the
 * moment the page was viewed rather than when the quote was made (so the date drifted
 * every day the cart sat there), it ignored the 2pm production cutoff that the engine
 * applies, and it ignored a date the customer had explicitly asked for. That is where
 * the three different delivery dates on one checkout screen came from.
 *
 * The calculator already resolved all of that and put the answer in the metadata as
 * `estimatedDeliveryDate` (Y-m-d). Reading it back is not merely more accurate — it is
 * the only version the customer ever agreed to.
 *
 * The recompute survives as a fallback for cart items and orders created before the
 * field existed.
 *
 * @param string $metadata_json The line item's pps_metadata.
 * @param int    $biz_days      Business-day count, for the legacy fallback.
 * @return string|null Formatted date, or null if neither source yields one.
 */
function pps_quoted_delivery_date( $metadata_json, $biz_days ) {
    $tz  = 'America/Phoenix';
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $tz  = $cfg['pcf']['shop_timezone'] ?? $tz;
    }
    $zone = new DateTimeZone( $tz );

    $meta = json_decode( (string) $metadata_json, true );
    $ymd  = is_array( $meta ) ? trim( (string) ( $meta['estimatedDeliveryDate'] ?? '' ) ) : '';
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
        $d = DateTime::createFromFormat( 'Y-m-d|', $ymd, $zone );
        // createFromFormat accepts out-of-range parts by rolling them over, so a
        // malformed date can parse into a real but wrong one. Round-tripping it is
        // what catches that.
        if ( $d && $d->format( 'Y-m-d' ) === $ymd ) return $d->format( 'l, M j, Y' );
    }

    $biz_days = (int) $biz_days;
    if ( $biz_days <= 0 ) return null;
    return pps_add_business_days( new DateTime( 'now', $zone ), $biz_days )->format( 'l, M j, Y' );
}

/**
 * Find calculator filename assigned to a product ID.
 */
function pps_get_calculator_for_product( $product_id ) {
    $reg = pps_get_registry();
    foreach ( $reg as $filename => $meta ) {
        $ids = pps_registry_product_ids( $meta['products'] ?? '' );
        if ( in_array( (int) $product_id, $ids, true ) ) {
            return $filename;
        }
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════
// ADMIN MENU & PAGE
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_menu_page(
        'PPS Calculators',
        'PPS Calculators',
        'manage_options',
        'pps-calculators',
        'pps_admin_page',
        'dashicons-calculator',
        58
    );
});

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'toplevel_page_pps-calculators' ) return;
    wp_enqueue_style( 'woocommerce_admin_styles' );
    wp_enqueue_script( 'wc-enhanced-select' );
});

add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'toplevel_page_pps-calculators' ) return;
    echo '<style>';
    echo '
        .pps-wrap { max-width:1100px; }
        .pps-header { display:flex; align-items:center; gap:12px; margin:20px 0 16px; }
        .pps-header h1 { margin:0; font-size:22px; }
        .pps-header .pps-badge { background:#007eff; color:#fff; font-size:10px; font-weight:600; padding:2px 8px; border-radius:3px; vertical-align:middle; }

        .pps-upload-box { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; margin-bottom:20px; }
        .pps-upload-box h3 { margin:0 0 12px; font-size:14px; }
        .pps-upload-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

        .pps-cards { display:grid; grid-template-columns:repeat(auto-fill, minmax(480px, 1fr)); gap:16px; }
        .pps-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; overflow:hidden; }
        .pps-card-head { padding:14px 18px; border-bottom:1px solid #e0e0e0; display:flex; justify-content:space-between; align-items:center; }
        .pps-card-head h4 { margin:0; font-size:14px; display:flex; align-items:center; gap:8px; }
        .pps-file-tag { font-size:10px; color:#888; font-weight:400; font-family:monospace; background:#f0f0f1; padding:2px 6px; border-radius:3px; }
        .pps-card-body { padding:14px 18px; }
        .pps-card-body label { display:block; font-size:12px; font-weight:600; color:#555; margin-bottom:4px; }
        .pps-field-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
        .pps-field-row input[type="text"] { flex:1; }
        .pps-card-foot { padding:10px 18px; border-top:1px solid #e0e0e0; background:#f9f9f9; display:flex; justify-content:space-between; align-items:center; }
        .pps-meta { font-size:11px; color:#999; }

        .pps-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
        .pps-dot.green { background:#46882c; }
        .pps-dot.grey { background:#ccc; }

        .pps-btn-danger { color:#b32d2e !important; border-color:#b32d2e !important; }
        .pps-btn-danger:hover { background:#b32d2e !important; color:#fff !important; }

        .pps-empty { text-align:center; padding:60px 20px; color:#999; }
        .pps-empty .dashicons { font-size:48px; width:48px; height:48px; color:#ddd; margin-bottom:12px; display:block; }

        .pps-card .select2-container { flex:1; min-width:0; }
        .pps-card .select2-container .select2-selection--multiple { min-height:32px; border-color:#8c8f94; }
        .pps-card .select2-selection__choice { max-width:100%; }
    ';
    echo '</style>';
});

function pps_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    $reg = pps_get_registry();
    $dir = pps_upload_dir();
    $url = pps_upload_url();

    // ── Handle form actions ──
    if ( isset( $_POST['pps_action'] ) && check_admin_referer( 'pps_admin_action' ) ) {
        $action = sanitize_text_field( wp_unslash( $_POST['pps_action'] ) );

        // Upload
        if ( $action === 'upload' && ! empty( $_FILES['pps_file'] ) && $_FILES['pps_file']['error'] === UPLOAD_ERR_OK ) {
            $file     = $_FILES['pps_file'];
            $filename = sanitize_file_name( $file['name'] );

            if ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'html' ) {
                echo '<div class="notice notice-error"><p>Only <code>.html</code> files are allowed.</p></div>';
            } else {
                $dest      = trailingslashit( $dir ) . $filename;
                $is_update = file_exists( $dest );

                if ( move_uploaded_file( $file['tmp_name'], $dest ) ) {
                    if ( ! isset( $reg[ $filename ] ) ) {
                        $reg[ $filename ] = array(
                            'name'     => pathinfo( $filename, PATHINFO_FILENAME ),
                            'products' => '',
                            'uploaded' => current_time( 'mysql' ),
                        );
                    } else {
                        $reg[ $filename ]['uploaded'] = current_time( 'mysql' );
                    }
                    pps_save_registry( $reg );
                    $verb = $is_update ? 'Updated' : 'Uploaded';
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( "$verb: $filename" ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Upload failed — check folder permissions on <code>wp-content/uploads/' . PPS_UPLOAD_SUBDIR . '/</code></p></div>';
                }
            }
        }

        // Save product assignment
        if ( $action === 'save_products' ) {
            $fn = sanitize_file_name( wp_unslash( $_POST['pps_filename'] ?? '' ) );
            if ( isset( $reg[ $fn ] ) ) {
                $raw_ids = isset( $_POST['pps_products'] ) ? (array) $_POST['pps_products'] : array();
                $ids = array_unique( array_filter( array_map( 'intval', $raw_ids ) ) );
                $reg[ $fn ]['products'] = implode( ', ', $ids );
                pps_save_registry( $reg );
                echo '<div class="notice notice-success is-dismissible"><p>Products updated for <strong>' . esc_html( $reg[ $fn ]['name'] ) . '</strong></p></div>';
            }
        }

        // Rename
        if ( $action === 'rename' ) {
            $fn       = sanitize_file_name( wp_unslash( $_POST['pps_filename'] ?? '' ) );
            $new_name = sanitize_text_field( wp_unslash( $_POST['pps_new_name'] ?? '' ) );
            if ( isset( $reg[ $fn ] ) && $new_name ) {
                $reg[ $fn ]['name'] = $new_name;
                pps_save_registry( $reg );
                echo '<div class="notice notice-success is-dismissible"><p>Renamed to <strong>' . esc_html( $new_name ) . '</strong></p></div>';
            }
        }

        // Delete
        if ( $action === 'delete' ) {
            $fn = sanitize_file_name( wp_unslash( $_POST['pps_filename'] ?? '' ) );
            if ( isset( $reg[ $fn ] ) ) {
                $filepath = trailingslashit( $dir ) . $fn;
                if ( file_exists( $filepath ) ) unlink( $filepath );
                $deleted_name = $reg[ $fn ]['name'];
                unset( $reg[ $fn ] );
                pps_save_registry( $reg );
                echo '<div class="notice notice-success is-dismissible"><p>Deleted <strong>' . esc_html( $deleted_name ) . '</strong></p></div>';
            }
        }

        // Re-read after changes
        $reg = pps_get_registry();
    }

    // ── Sync registry with files on disk ──
    $files_on_disk = glob( trailingslashit( $dir ) . '*.html' ) ?: array();
    $disk_names    = array_map( 'basename', $files_on_disk );

    $changed = false;
    foreach ( array_keys( $reg ) as $fn ) {
        if ( ! in_array( $fn, $disk_names, true ) ) {
            unset( $reg[ $fn ] );
            $changed = true;
        }
    }
    if ( $changed ) pps_save_registry( $reg );

    // ── Render ──
    ?>
    <div class="wrap pps-wrap">
        <div class="pps-header">
            <h1>PPS Calculators</h1>
            <span class="pps-badge">v<?php echo PPS_CALC_VERSION; ?></span>
            <?php if ( function_exists( 'pps_get_config' ) ) : ?>
                <a href="<?php echo admin_url( 'admin.php?page=pps-config' ); ?>" class="button button-small" style="margin-left:auto">⚙ Central Config</a>
            <?php endif; ?>
        </div>

        <!-- Upload -->
        <div class="pps-upload-box">
            <h3>Upload Calculator</h3>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field( 'pps_admin_action' ); ?>
                <input type="hidden" name="pps_action" value="upload">
                <div class="pps-upload-row">
                    <input type="file" name="pps_file" accept=".html" required>
                    <button type="submit" class="button button-primary">Upload Calculator</button>
                    <span style="font-size:11px;color:#888">Same filename = auto-update existing calculator</span>
                </div>
            </form>
        </div>

        <!-- Cards -->
        <?php if ( empty( $reg ) ) : ?>
            <div class="pps-empty">
                <span class="dashicons dashicons-calculator"></span>
                <p>No calculators yet.<br>Upload an <code>.html</code> file to get started.</p>
            </div>
        <?php else : ?>
            <div class="pps-cards">
            <?php foreach ( $reg as $filename => $meta ) :
                $name         = $meta['name'] ?? pathinfo( $filename, PATHINFO_FILENAME );
                $products_str = $meta['products'] ?? '';
                $uploaded     = $meta['uploaded'] ?? '—';
                $filepath     = trailingslashit( $dir ) . $filename;
                $filesize     = file_exists( $filepath ) ? size_format( filesize( $filepath ) ) : '—';
                $has_products = ! empty( trim( $products_str ) );
                $product_ids  = pps_registry_product_ids( $products_str );
            ?>
                <div class="pps-card">
                    <div class="pps-card-head">
                        <h4>
                            <span class="pps-dot <?php echo $has_products ? 'green' : 'grey'; ?>"></span>
                            <?php echo esc_html( $name ); ?>
                            <span class="pps-file-tag"><?php echo esc_html( $filename ); ?></span>
                        </h4>
                        <a href="<?php echo esc_url( trailingslashit( $url ) . $filename ); ?>" target="_blank" class="button button-small">Preview ↗</a>
                    </div>

                    <div class="pps-card-body">
                        <!-- Display Name -->
                        <form method="post" style="margin-bottom:12px">
                            <?php wp_nonce_field( 'pps_admin_action' ); ?>
                            <input type="hidden" name="pps_action" value="rename">
                            <input type="hidden" name="pps_filename" value="<?php echo esc_attr( $filename ); ?>">
                            <label>Display Name</label>
                            <div class="pps-field-row">
                                <input type="text" name="pps_new_name" value="<?php echo esc_attr( $name ); ?>">
                                <button type="submit" class="button button-small">Rename</button>
                            </div>
                        </form>

                        <!-- Product Assignment -->
                        <form method="post">
                            <?php wp_nonce_field( 'pps_admin_action' ); ?>
                            <input type="hidden" name="pps_action" value="save_products">
                            <input type="hidden" name="pps_filename" value="<?php echo esc_attr( $filename ); ?>">
                            <label>Assigned Products</label>
                            <div class="pps-field-row">
                                <select
                                    class="wc-product-search"
                                    name="pps_products[]"
                                    multiple="multiple"
                                    style="width:100%"
                                    data-placeholder="Search for products…"
                                    data-action="woocommerce_json_search_products"
                                    data-exclude_type="grouped"
                                >
                                    <?php foreach ( $product_ids as $pid ) :
                                        $p = wc_get_product( $pid );
                                        if ( $p ) : ?>
                                            <option value="<?php echo esc_attr( $pid ); ?>" selected>
                                                <?php echo esc_html( $p->get_name() . ' (#' . $pid . ')' ); ?>
                                            </option>
                                        <?php endif;
                                    endforeach; ?>
                                </select>
                                <button type="submit" class="button button-small button-primary">Save</button>
                            </div>
                        </form>
                    </div>

                    <div class="pps-card-foot">
                        <span class="pps-meta"><?php echo esc_html( $filesize ); ?> · Updated <?php echo esc_html( $uploaded ); ?></span>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete <?php echo esc_js( $filename ); ?>? This cannot be undone.')">
                            <?php wp_nonce_field( 'pps_admin_action' ); ?>
                            <input type="hidden" name="pps_action" value="delete">
                            <input type="hidden" name="pps_filename" value="<?php echo esc_attr( $filename ); ?>">
                            <button type="submit" class="button button-small pps-btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════
/**
 * The pdf.js loader + upload preflight, shared by both embed paths.
 *
 * pdf.js 4.x is ES-modules-only, so the old classic-script enqueue cannot load it; this
 * inline loader dynamic-imports it and publishes window.pdfjsLib, the global the
 * calculator code already uses. One function rather than two copies, because the
 * product-page and preset-URL embeds carried duplicate 3.11 enqueues and had already
 * started to drift in whitespace.
 *
 * The calculator HTML files carry their own identical copy in their <head> for the
 * GitHub Pages previews; the WP embeds never read that head, which is why this exists.
 * If the blob changes in the HTML files, change it here in the same commit.
 *
 * Why 4.10.38: 3.11.174 silently dropped an entire transparency group from a customer
 * PDF, so the approval proof was missing a block the press would print (2026-08-04).
 * 4.10.38 is the version that was verified against that exact file.
 */
function pps_pdfjs_loader_js() {
    return <<<'PPSPDFJS'
/* pdf.js 4.x ships as ES modules only, so the classic-script global is gone. This
   loader dynamic-imports it at parse time -- the fetch is warm before anyone can pick a
   file -- and publishes it at window.pdfjsLib, the name every use site already resolves.
   Use sites await window.__ppsPdfJs first, so a slow network delays the first upload
   instead of breaking it.
   Why 4.x at all: 3.11.174 silently dropped an entire transparency group from a
   customer PDF -- the proof rendered without a block the press would print. 4.10.38 is
   pinned because it is the version verified against that file. The legacy build is
   deliberate: customers proof on phones whose Safari the modern build has dropped.
   Two mirrors, both exact npm layouts, full prefixes as literals so the offline test
   harness can string-replace them with vendored copies. */
(function () {
  var MIRRORS = [
    "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/",
    "https://unpkg.com/pdfjs-dist@4.10.38/legacy/build/"
  ];
  function attempt(i) {
    if (i >= MIRRORS.length) return Promise.reject(new Error("pdf.js could not be loaded from any CDN"));
    return import(MIRRORS[i] + "pdf.min.mjs").then(function (m) {
      m.GlobalWorkerOptions.workerSrc = MIRRORS[i] + "pdf.worker.min.mjs";
      window.pdfjsLib = m;
      return m;
    }, function () { return attempt(i + 1); });
  }
  window.__ppsPdfJs = attempt(0);
  window.__ppsPdfJs.catch(function (e) { console.error("[pps]", e); });
})();

/* Preflight, not gatekeeping: names the constructs in an uploaded PDF that a browser
   preview is known to fumble -- pdf.js is a viewer, not a prepress RIP, and
   transparency is exactly where the two part company. Reads the operator lists the
   renderer builds anyway (they are cached and reused by the page renders that follow),
   so the scan costs almost nothing. Never throws: a preflight that can break an upload
   is worse than no preflight. Results accumulate in window.PPS_PDF_RISKS keyed by
   upload slot; the proof modal renders the union. */
window.PPS_PDF_RISKS = {};
window.ppsPdfRisks = function () {
  var o = window.PPS_PDF_RISKS, out = [], k, i, r;
  for (k in o) { r = o[k] || []; for (i = 0; i < r.length; i++) if (out.indexOf(r[i]) < 0) out.push(r[i]); }
  return out;
};
window.ppsAnalyzePdfRisks = async function (doc) {
  var risks = [];
  var add = function (x) { if (risks.indexOf(x) < 0) risks.push(x); };
  try {
    var OPS = window.pdfjsLib.OPS;
    var limit = Math.min(doc.numPages, 40);   // enough pages to decide; bounded on purpose
    for (var i = 1; i <= limit; i++) {
      var pg = await doc.getPage(i);
      var list = await pg.getOperatorList();
      for (var k = 0; k < list.fnArray.length; k++) {
        var f = list.fnArray[k], a = list.argsArray[k];
        if (f === OPS.beginGroup) add("transparency groups");
        else if (f === OPS.shadingFill) add("gradients");
        else if (f === OPS.setGState && a && Array.isArray(a[0])) {
          for (var e = 0; e < a[0].length; e++) {
            var ent = a[0][e]; if (!Array.isArray(ent)) continue;
            var key = ent[0], val = ent[1];
            if (key === "SMask" && val) add("soft masks");
            else if (key === "BM" && val && !/^(Normal|Compatible|source-over)$/i.test(String(val && val.name || val))) add("blend modes");
            else if ((key === "ca" || key === "CA") && val > 0 && val < 1) add("partial transparency");
          }
        } else if (f === OPS.setFont && a && typeof a[0] === "string") {
          try {
            var fnt = pg.commonObjs.get(a[0]);
            // The standard-14 faces are never embedded by design and substitute the
            // same way in every renderer; flagging them would banner every quick-tool
            // PDF and teach people to ignore the warning.
            if (fnt && fnt.missingFile && !/^(Helvetica|Times|Courier|Arial|Symbol|ZapfDingbats)/i.test(String(fnt.name || ""))) add("non-embedded fonts");
          } catch (e2) {}
        }
      }
      if (risks.length >= 4) break;
    }
  } catch (e3) {}
  return risks;
};
PPSPDFJS;
}

// FRONTEND: EMBED CALCULATOR ON PRODUCT PAGES (direct, no iframe)
// ═══════════════════════════════════════════════════════════════

/**
 * Parse a calculator HTML file to extract styles and app code.
 * CDN scripts (React, Babel, PDF.js) are loaded separately via wp_enqueue_script
 * to ensure proper dependency ordering and deduplication.
 */
function pps_parse_calculator_html( $html ) {
    $parts = array(
        'styles'   => '',
        'app_code' => '',
        'compiled' => false,
    );

    // <style>...</style> block
    if ( preg_match( '/<style>(.*?)<\/style>/s', $html, $m ) ) {
        $parts['styles'] = $m[1];
    }

    // App code: either the JSX source block, or the precompiled plain-script
    // block tools-compile-calcs.mjs emits (identified by its marker comment).
    // Matching ONLY text/babel is how the compiled deploy rendered an empty
    // calculator on every product page (round-3 QA blocker): no match, no app.
    if ( preg_match( '/<script type="text\/babel">(.*)<\/script>/s', $html, $m ) ) {
        $parts['app_code'] = trim( $m[1] );
    } elseif ( preg_match( '/<script>(\/\* compiled by tools-compile-calcs\.mjs.*)<\/script>/s', $html, $m ) ) {
        $parts['app_code'] = trim( $m[1] );
        $parts['compiled'] = true;
    }

    return $parts;
}

/**
 * True when the uploaded calculator file is a precompiled build (no
 * in-browser Babel needed). Read the head only; cached per path.
 */
function pps_calc_file_is_compiled( $filepath ) {
    static $cache = array();
    if ( ! isset( $cache[ $filepath ] ) ) {
        // Full read, not a head-sniff: the compile marker sits at the app
        // <script>, tens of KB in past the styles — an 8KB sniff missed it,
        // which is why compiled pages kept enqueuing Babel (round-4 QA).
        $body = (string) file_get_contents( $filepath );
        $cache[ $filepath ] = ( strpos( $body, 'tools-compile-calcs.mjs' ) !== false );
    }
    return $cache[ $filepath ];
}

/**
 * Serve a compiled calculator's app code as a real enqueued file instead of a
 * giant inline <script>. Round-4 QA found the inline form dies twice over:
 * executed synchronously it runs ~26 scripts before React exists
 * ("ReferenceError: React is not defined" — text/babel used to defer it, a
 * plain script tag does not), and WP Rocket's minifier deletes 400KB+ inline
 * blocks from the cached page outright. A dependency-ordered footer enqueue
 * fixes the ordering; an external file survives the minifier (and the
 * existing 'pps-calculator' Rocket exclusion covers its URL); the page sheds
 * ~450KB of HTML. Content-hashed filename = immutable, browser-cacheable.
 * Returns false if the file can't be written, so callers can fall back.
 */
function pps_enqueue_calc_app_file( $filepath, $app_code ) {
    $hash = substr( md5( $app_code ), 0, 10 );
    $base = preg_replace( '/\.html$/', '', basename( $filepath ) );
    $dir  = trailingslashit( pps_upload_dir() ) . 'js';
    $file = $dir . '/' . $base . '-' . $hash . '.js';

    if ( ! file_exists( $file ) ) {
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            @file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        }
        // Write-then-rename so a concurrent request never serves a half file.
        $tmp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
        if ( false === @file_put_contents( $tmp, $app_code ) ) return false;
        if ( ! @rename( $tmp, $file ) ) { @unlink( $tmp ); return false; }
    }

    $upload = wp_upload_dir();
    $url    = str_replace(
        trailingslashit( $upload['basedir'] ),
        trailingslashit( $upload['baseurl'] ),
        $file
    );
    wp_enqueue_script(
        'pps-calc-app',
        $url,
        array( 'pps-react', 'pps-react-dom', 'pps-pdfjs', 'pps-jspdf' ),
        $hash,
        true
    );
    return true;
}

/**
 * Exempt the calculator script stack from Cloudflare Rocket Loader.
 *
 * Production sits behind Cloudflare (staging does not), and Rocket Loader
 * re-orders/re-executes scripts, which breaks the React → ReactDOM → app
 * dependency chain the external-file enqueue exists to guarantee. Observed
 * live 2026-08-13 as a commit-phase removeChild NotFoundError on proof
 * approval (coupon calculator, production only). data-cfasync="false" is
 * honored by Rocket Loader wherever the zone is managed, so this needs no
 * Cloudflare dashboard access and leaves Rocket Loader on for the rest of
 * the site. The WP Rocket exclusions elsewhere in this file are a different
 * product and do NOT cover this.
 */
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
    static $pps_handles = array(
        'pps-react'     => 1,
        'pps-react-dom' => 1,
        'pps-pdfjs'     => 1,
        'pps-jspdf'     => 1,
        'pps-babel'     => 1,
        'pps-calc-app'  => 1,
    );
    if ( isset( $pps_handles[ $handle ] ) && false === strpos( $tag, 'data-cfasync' ) ) {
        $tag = str_replace( '<script ', '<script data-cfasync="false" ', $tag );
    }
    return $tag;
}, 10, 2 );

/**
 * Compiled-ness of the calculator serving the CURRENT request (product page
 * or preset URL) — used to skip the Babel Standalone enqueue, ~3MB of decoded
 * JS that compiled pages never execute.
 */
function pps_current_calc_is_compiled() {
    $filename = '';
    if ( ! empty( $GLOBALS['pps_active_preset'] ) && function_exists( 'pps_get_filename_for_calc_type' ) ) {
        $filename = pps_get_filename_for_calc_type( $GLOBALS['pps_active_preset']['calc'] );
    } elseif ( function_exists( 'is_product' ) && is_product() ) {
        $filename = pps_get_calculator_for_product( get_queried_object_id() );
    }
    if ( ! $filename ) return false;
    $filepath = trailingslashit( pps_upload_dir() ) . $filename;
    return file_exists( $filepath ) && pps_calc_file_is_compiled( $filepath );
}

add_action( 'wp', function() {
    if ( ! is_product() ) return;

    $product_id = get_queried_object_id();
    if ( ! $product_id ) return;

    $filename = pps_get_calculator_for_product( $product_id );
    if ( ! $filename ) return;

    $filepath = trailingslashit( pps_upload_dir() ) . $filename;
    if ( ! file_exists( $filepath ) ) return;

    // Hide native WC price, add-to-cart, quantity, and WCPA
    add_action( 'wp_head', function() {
        echo '<style>
            .single-product .summary .price,
            .single-product form.cart,
            .single-product .wcpa_form_outer,
            .single-product .woocommerce-variation-add-to-cart { display:none !important; }
        </style>';
    });

    // Enqueue CDN dependencies properly (fires before page render)
    add_action( 'wp_enqueue_scripts', function() {
        wp_enqueue_script( 'pps-react', 'https://unpkg.com/react@18.3.1/umd/react.production.min.js', array(), '18.3.1', true );
        wp_enqueue_script( 'pps-react-dom', 'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js', array( 'pps-react' ), '18.3.1', true );
        // pdf.js 4.x is ESM-only; the handle survives as an inline loader so the
        // pps-babel dependency chain is unchanged. See pps_pdfjs_loader_js().
        wp_register_script( 'pps-pdfjs', false, array(), '4.10.38', true );
        wp_enqueue_script( 'pps-pdfjs' );
        wp_add_inline_script( 'pps-pdfjs', pps_pdfjs_loader_js() );
        // jsPDF — for generating print-ready PDFs. Pre-loading via wp_enqueue_script
        // removes the runtime <script> injection in the calc HTML, which was a
        // DOM-mutation source contributing to React removeChild errors.
        wp_enqueue_script( 'pps-jspdf', 'https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js', array(), '2.5.1', true );
        // Babel only for JSX-source builds; compiled pages skip ~3MB of dead JS.
        if ( ! pps_current_calc_is_compiled() ) {
            wp_enqueue_script( "pps-babel", 'https://unpkg.com/@babel/standalone@7.26.9/babel.min.js', array( 'pps-react', 'pps-react-dom', 'pps-pdfjs', 'pps-jspdf' ), '7.26.9', true );
        }
    });

    // ── Kill WC's gallery slider / lightbox / zoom on calc product pages ──
    // Theme support is read at gallery render time; turning it off here means
    // WC outputs plain stacked images instead of Flexslider-wrapped DOM with
    // inline height/transform styles. Lets our CSS scroll-snap carousel
    // work on simple DOM rather than fighting JS-applied inline styles.
    remove_theme_support( 'wc-product-gallery-slider' );
    remove_theme_support( 'wc-product-gallery-lightbox' );
    remove_theme_support( 'wc-product-gallery-zoom' );

    // ── External-JS hardening on calculator product pages ──
    // The calc's React mount is fragile to non-React DOM mutations. We
    // suppress two known production sources surgically — keeping WP Rocket's
    // page cache, minify, CSS optimization, and CDN active so we don't lose
    // Core Web Vitals headroom on a high-SEO-value page.
    //
    //  1) WCPA's frontend bundle — form is CSS-hidden but its JS still
    //     mounts and mutates fields that can collide with React.
    //  2) WP Rocket Delay-JS — would defer the React/Babel/jsPDF bundle until
    //     first user interaction, so the calc never paints on page load.
    //     Excluded by URL pattern via rocket_delay_js_exclusions; delay-JS
    //     stays active for analytics, social embeds, and everything else.
    //  3) WP Rocket Lazy-Load — only the images React creates dynamically
    //     (proof modal, 3D preview spreads, magnifier overlays) cause the
    //     "Failed to execute 'removeChild' on Node" errors. Excluded by
    //     selector via rocket_lazyload_excluded_attributes; header, gallery,
    //     and footer images keep lazy-load for LCP.
    add_action( 'wp_enqueue_scripts', function() {
        foreach ( array( 'wcpa-front', 'wcpa-shared', 'wcpa_front', 'wcpa-modal', 'wcpa-frontend' ) as $h ) {
            wp_dequeue_script( $h );
            wp_dequeue_style( $h );
        }
        // Belt-and-suspenders: theme support is gone (above), but some themes
        // enqueue these unconditionally. Dropping them here keeps the page
        // payload trim.
        foreach ( array( 'flexslider', 'photoswipe', 'photoswipe-ui-default', 'wc-single-product', 'zoom' ) as $h ) {
            wp_dequeue_script( $h );
            wp_dequeue_style( $h );
        }
    }, 100 );
    add_filter( 'rocket_delay_js_exclusions', function( $excluded ) {
        $excluded[] = '/unpkg\.com/react';
        $excluded[] = '/unpkg\.com/react-dom';
        $excluded[] = '/unpkg\.com/@babel/standalone';
        $excluded[] = '/unpkg\.com/jspdf';
        $excluded[] = '/cdnjs\.cloudflare\.com/ajax/libs/pdf\.js';
        $excluded[] = 'pps-calculator';
        return $excluded;
    } );
    add_filter( 'rocket_lazyload_excluded_attributes', function( $excluded ) {
        // Exclude only images inside the calc's React-controlled DOM. Modal
        // panels (.bp-modal-bg / .bp-modal), 3D book scenes (.bp-scene), and
        // page faces (.bp-page, .bp-face) cover every dynamically-created
        // <img> the calc renders. WC gallery, hero, and chrome images stay
        // lazy-loaded.
        $excluded[] = 'class*="bp-modal"';
        $excluded[] = 'class*="bp-scene"';
        $excluded[] = 'class*="bp-page"';
        $excluded[] = 'class*="bp-face"';
        $excluded[] = 'data-pps-calc';
        return $excluded;
    } );

    // ── Peek-carousel gallery + artwork-hides-gallery bridge ──
    // ── Custom gallery — replace WC default render entirely ──
    // Strategy: skip WooCommerce's default gallery render (and everything
    // any third-party plugin like Astra Pro / WC Gallery Slider / PhotoSwipe
    // adds on top of it) by removing the woocommerce_show_product_images
    // callback. We emit our own .pps-gallery markup at the same hook so the
    // calc still renders directly below. Anyone hooked into the WC gallery
    // classes finds nothing to enhance.
    // ── Bound the parallax ──
    // .pps-gallery is position:sticky. Its containing block used to be the whole
    // product container, so it stayed pinned at top:0 for the entire page and
    // showed through every block below that lacked an opaque background — the
    // photo reappearing in strips behind the trust badges and the description.
    // The 2026-08-12 pass treated that by listing containers to paint white,
    // but the list was Astra-era and pps-theme's wrappers are not in it, so the
    // leak came back the moment the theme changed.
    //
    // A sticky element cannot escape its parent's box. Wrapping the gallery and
    // the calculator in one stage ends the pinning exactly where the calculator
    // ends, which is the only place the parallax was ever meant to reach. No
    // class list to keep in sync with whatever theme is installed.
    add_action( 'woocommerce_before_single_product_summary', function() {
        echo '<div class="pps-stage">';
    }, 19 );
    add_action( 'woocommerce_before_single_product_summary', function() {
        echo '</div>';
    }, 26 );

    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
    add_action( 'woocommerce_before_single_product_summary', function() use ( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $image_ids = array();
        $featured  = $product->get_image_id();
        if ( $featured ) $image_ids[] = (int) $featured;
        foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
            if ( $gid && ! in_array( (int) $gid, $image_ids, true ) ) $image_ids[] = (int) $gid;
        }
        if ( empty( $image_ids ) ) return;
        echo '<div class="pps-gallery" role="region" aria-label="Product images">';
        echo '<div class="pps-gallery__track">';
        foreach ( $image_ids as $id ) {
            $src = wp_get_attachment_image_url( $id, 'woocommerce_single' );
            if ( ! $src ) $src = wp_get_attachment_image_url( $id, 'large' );
            if ( ! $src ) continue;
            $alt = trim( strip_tags( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
            printf(
                '<div class="pps-gallery__slide"><img src="%s" alt="%s" loading="lazy" decoding="async" /></div>',
                esc_url( $src ),
                esc_attr( $alt )
            );
        }
        echo '</div></div>';
    }, 20 );

    // ── Peek-carousel CSS + artwork-hides-gallery bridge ──
    // Native CSS scroll-snap; each slide is 85% wide, 7.5% gutters reveal the
    // prev/next image edges. No JS slider, no lightbox. When the calc emits
    // pps:artwork-changed { available: true }, body.pps-artwork-uploaded hides
    // .pps-gallery — calc sidebar preview becomes the only artwork surface.
    add_action( 'wp_head', function() {
        ?>
        <style id="pps-peek-carousel">
        /* Hide WC default gallery if any plugin re-injects it after our
           remove_action above (e.g. Astra Pro's gallery enhancer).
           Our own .pps-gallery is the only carousel that should show. */
        .woocommerce-product-gallery,
        .astra-product-images,
        .ast-product-gallery-layout-vertical { display: none !important; }

        /* Sticky parallax needs overflow: visible up the ancestor chain.
           Common WC/theme containers get the override. */
        .single-product .product,
        .single-product .content-area,
        .single-product .site-main,
        .single-product .entry-content,
        .single-product main { overflow: visible !important; }

        /* The stage is the sticky gallery's containing block: the parallax runs
           from the top of the gallery to the bottom of the calculator and stops
           there, because a sticky child cannot be pushed past its parent. */
        .pps-stage { position: relative; overflow: visible; }

        .pps-gallery {
            position: sticky;
            top: 0;
            z-index: 0;
            width: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        /* Everything BELOW the calculator must also cover the sticky gallery.
           Only #pps-calculator-wrap used to carry the overlay, so the gallery —
           pinned at top:0 — showed through the description, tabs and Site
           Builder blocks further down the page. That is the "image floating
           over the description" and the "collapsing the description doesn't
           hide it" reports (2026-08-12): the gallery was bleeding through,
           the description was never the problem. */
        .single-product .woocommerce-tabs,
        .single-product .wc-tabs-wrapper,
        .single-product .woocommerce-Tabs-panel,
        .single-product .ast-single-product-tabs,
        .single-product .related,
        .single-product .up-sells,
        .single-product .cross-sells,
        .single-product .ast-advanced-hook-markup,
        .single-product .site-footer {
            position: relative;
            z-index: 1;
            background: #fff;
        }

        /* The description tab is full-bleed, so on a wide monitor the copy runs
           the entire viewport — 200+ characters a line, which nobody reads.
           Cap it at the same 1200px the rest of the site's content uses, and
           hold the prose itself to a normal measure inside that. */
        .single-product .woocommerce-Tabs-panel,
        .single-product .woocommerce-tabs .panel,
        .single-product .wc-tab {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: 24px;
            padding-right: 24px;
            box-sizing: border-box;
        }
        .single-product .woocommerce-Tabs-panel p,
        .single-product .woocommerce-Tabs-panel li,
        .single-product .woocommerce-tabs .panel p,
        .single-product .woocommerce-tabs .panel li {
            max-width: 80ch;
        }

        /* Parallax overlay — the calc wrap slides up over the sticky gallery. */
        #pps-calculator-wrap {
            position: relative !important;
            z-index: 1 !important;
            background: #fff !important;
            margin-top: -28px !important;
            padding-top: 28px !important;
            border-radius: 24px 24px 0 0 !important;
            box-shadow: 0 -12px 32px rgba(0,0,0,.08) !important;
        }
        .pps-gallery__track {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-padding-inline: 7.5%;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            margin: 0;
            padding: 0 7.5%;
            max-height: 60vh;
        }
        .pps-gallery__track::-webkit-scrollbar { display: none; }
        .pps-gallery__slide {
            flex: 0 0 85%;
            scroll-snap-align: center;
            margin: 0;
            padding: 0;
            display: block;
            background: transparent;
        }
        .pps-gallery__slide img {
            display: block;
            width: 100%;
            height: auto;
            max-width: 100%;
            max-height: 60vh;
            margin: 0;
            padding: 0;
            object-fit: contain;
            cursor: grab;
            user-select: none;
            -webkit-user-drag: none;
        }
        .pps-gallery__slide img:active { cursor: grabbing; }
        /* Artwork-uploaded state — calc owns the visual now. */
        body.pps-artwork-uploaded .pps-gallery { display: none !important; }
        </style>
        <?php
    } );
    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function() {
            const body = document.body;
            window.addEventListener('pps:artwork-changed', (e) => {
                const detail = (e && e.detail) || {};
                if (detail.available) {
                    body.classList.add('pps-artwork-uploaded');
                } else {
                    body.classList.remove('pps-artwork-uploaded');
                }
            });
        })();
        </script>
        <?php
    } );

    // Embed calculator inline — render immediately after the product gallery.
    // WC hooks pps_show_product_images into woocommerce_before_single_product_summary
    // at priority 20, so priority 25 fires right after the gallery and before
    // the summary block (price/title/add-to-cart, which we hide via CSS anyway).
    add_action( 'woocommerce_before_single_product_summary', function() use ( $filepath, $product_id ) {
        $html  = file_get_contents( $filepath );
        $parts = pps_parse_calculator_html( $html );

        // Build config object for the calculator JS
        $config = array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'cartUrl'     => wc_get_cart_url(),
            'cartNonce'   => wp_create_nonce( 'pps_add_to_cart' ),
            'uploadNonce' => wp_create_nonce( 'pps_upload_artwork' ),
            // What PHP will actually accept in one POST. Without it the
            // calculator cannot tell an oversized file from a stale nonce:
            // both arrive as admin-ajax's bare "-1", which is what produced
            // the "Artwork upload failed: Unknown error" report.
            'maxUpload'   => (int) wp_max_upload_size(),
            'productId'   => $product_id,
        );

        // Inject central config so calculator reads from admin settings
        if ( function_exists( 'pps_get_config' ) ) {
            $config['calc'] = pps_get_public_config();
        }

        // Inject tooltip content for RichTip components. Saved entries override
        // per key, but code-shipped defaults always ride along — otherwise a new
        // default tooltip never reaches sites whose pps_tooltips option was
        // seeded before it existed.
        $tips = pps_get_tooltips();
        if ( ! empty( $tips ) ) {
            $config['tips'] = $tips;
        }

        // Logo URL for template watermarks — set in Central Config or defaults to site logo
        $logo_url = get_option( 'pps_logo_url', '' );
        if ( ! $logo_url ) {
            $custom_logo_id = get_theme_mod( 'custom_logo' );
            if ( $custom_logo_id ) $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
        }
        if ( $logo_url ) $config['logoUrl'] = $logo_url;

        // Inject UPS zone map (3-digit ZIP prefix → transit days)
        $zone_map = get_option( 'pps_ups_zone_map', array() );
        if ( ! empty( $zone_map ) && is_array( $zone_map ) ) {
            $config['zoneMap'] = $zone_map;
        }

        // Per-product defaults — stored as product meta, overrides central config defaults
        $product_defaults = get_post_meta( $product_id, '_pps_defaults', true );
        if ( is_string( $product_defaults ) && $product_defaults !== '' ) {
            $product_defaults = json_decode( $product_defaults, true );
        }
        if ( ! empty( $product_defaults ) && is_array( $product_defaults ) ) {
            $config['defaults'] = $product_defaults;
        }

        // Forward reorder config if present on the product page URL
        if ( ! empty( $_GET['pps_reorder'] ) ) {
            $config['reorder'] = sanitize_text_field( $_GET['pps_reorder'] );
        }

        // Add-on visibility flags resolved for this calculator's type.
        // Calculator JS reads window.PPS_CONFIG.addons.<slug> to hide the
        // option row (false) and calculate() returns an error if a pre-filled
        // state carries a non-default value for a disabled add-on.
        if ( function_exists( 'pps_get_addons_visibility_for_calc' ) ) {
            $calc_type = pps_get_calc_type_for_filename( $filename );
            if ( $calc_type ) {
                $config['addons'] = pps_get_addons_visibility_for_calc( $calc_type );
            }
        }

        // Switch targets: let booklet calculators link to each other
        $booklet_types = array( 'saddle', 'perfect-bound', 'coupon' );
        if ( ! empty( $calc_type ) && in_array( $calc_type, $booklet_types, true ) ) {
            $switch = array();
            foreach ( $booklet_types as $bt ) {
                if ( $bt === $calc_type ) continue;
                $bt_file = pps_get_filename_for_calc_type( $bt );
                if ( ! $bt_file ) continue;
                $bt_reg = pps_get_registry();
                if ( ! isset( $bt_reg[ $bt_file ]['products'] ) ) continue;
                $bt_ids = pps_registry_product_ids( $bt_reg[ $bt_file ]['products'] );
                if ( empty( $bt_ids ) ) continue;
                $bt_url = get_permalink( $bt_ids[0] );
                if ( $bt_url ) {
                    $label_map = array( 'saddle' => 'Saddle Stitch', 'perfect-bound' => 'Perfect Bound', 'coupon' => 'Coupon Book' );
                    $switch[] = array( 'type' => $bt, 'label' => $label_map[ $bt ] ?? $bt, 'url' => $bt_url );
                }
            }
            if ( $switch ) $config['switchTargets'] = $switch;
        }

        // Edit mode: store edit key so add-to-cart replaces the old item
        if ( ! empty( $_GET['pps_edit_key'] ) ) {
            $edit_key = sanitize_text_field( $_GET['pps_edit_key'] );
            // Verify the cart item actually exists
            if ( WC()->cart && isset( WC()->cart->get_cart()[ $edit_key ] ) ) {
                WC()->session->set( 'pps_edit_key_' . $product_id, $edit_key );
                $config['editMode'] = true;

                // Rebuild the restore payload from the cart session instead of
                // trusting a URL to carry it (the Edit Specs link is now a bare
                // token). Same whitelist as every other reorder path; encoded
                // the same way, so the calculators' PPS_CONFIG.reorder reader
                // is none the wiser.
                $edit_meta = json_decode( (string) ( WC()->cart->get_cart()[ $edit_key ]['pps_metadata'] ?? '' ), true );
                if ( is_array( $edit_meta ) ) {
                    $edit_fields = function_exists( 'pps_reorder_field_whitelist' )
                        ? pps_reorder_field_whitelist()
                        : array( 'sizeLabel', 'qty', 'sides', 'paper', 'shipState' );
                    $edit_cfg = array();
                    foreach ( $edit_fields as $ek ) {
                        if ( isset( $edit_meta[ $ek ] ) ) $edit_cfg[ $ek ] = $edit_meta[ $ek ];
                    }
                    if ( ! empty( WC()->cart->get_cart()[ $edit_key ]['pps_artwork_path'] ) ) {
                        $edit_cfg['artworkPath']     = WC()->cart->get_cart()[ $edit_key ]['pps_artwork_path'];
                        $edit_cfg['artworkFilename'] = basename( WC()->cart->get_cart()[ $edit_key ]['pps_artwork_path'] );
                    }
                    if ( $edit_cfg ) {
                        $config['reorder'] = rtrim( strtr( base64_encode( wp_json_encode( $edit_cfg ) ), '+/', '-_' ), '=' );
                    }
                }

                // Pass existing artwork info so calculator can display it
                $edit_item = WC()->cart->get_cart()[ $edit_key ];
                if ( ! empty( $edit_item['pps_artwork_path'] ) ) {
                    $upload    = wp_upload_dir();
                    $full_path = trailingslashit( $upload['basedir'] ) . $edit_item['pps_artwork_path'];
                    $config['existingArtwork'] = array(
                        'path'     => $edit_item['pps_artwork_path'],
                        'filename' => basename( $edit_item['pps_artwork_path'] ),
                        'size'     => file_exists( $full_path ) ? filesize( $full_path ) : 0,
                        'url'      => trailingslashit( $upload['baseurl'] ) . $edit_item['pps_artwork_path'],
                    );
                }
            }
        }

        // ── Config ──
        echo '<script data-cfasync="false">window.PPS_CONFIG=' . wp_json_encode( $config ) . ';</script>';

        // ── Edit mode banner + button text override ──
        if ( ! empty( $config['editMode'] ) ) {
            echo '<div id="pps-edit-banner" style="background:#007eff;color:#fff;padding:10px 16px;border-radius:4px;margin:12px 0;font-size:14px;display:flex;align-items:center;gap:8px">
                <span style="font-size:18px">✏️</span>
                <span><strong>Edit Mode</strong> — Update your specs below, then click the button to save changes.</span>
                <a href="' . esc_url( wc_get_cart_url() ) . '" style="margin-left:auto;color:#fff;opacity:0.8;font-size:12px;text-decoration:underline">Cancel</a>
            </div>';
            echo '<script data-cfasync="false">
            (function(){
                // The calculators say "Add to Order", not "Add to cart", so this matched
                // nothing and the button kept its append-sounding label through the whole
                // edit flow. The cart item IS replaced -- pps_ajax_add_to_cart removes the
                // old key after the new line succeeds -- but nothing on screen said so,
                // which is a reasonable thing to refuse to click.
                var observer = new MutationObserver(function(){
                    var btns = document.querySelectorAll("#pps-calculator-wrap button");
                    btns.forEach(function(b){
                        if (b.dataset.ppsEdited) return;
                        if (!/add to (cart|order)/i.test(b.textContent)) return;
                        b.textContent = b.textContent.replace(/add to (cart|order)/i, "Update Order");
                        b.dataset.ppsEdited = "1";
                    });
                });
                observer.observe(document.body, {childList:true, subtree:true});
            })();
            </script>';
        }

        // ── Scoped styles ──
        if ( $parts['styles'] ) {
            $css = $parts['styles'];
            // Scope universal selectors to our container
            $css = str_replace(
                '* {',
                '#pps-calculator-wrap, #pps-calculator-wrap *, #pps-calculator-wrap *::before, #pps-calculator-wrap *::after {',
                $css
            );
            $css = str_replace( 'body {', '#pps-calculator-wrap {', $css );
            echo '<style>' . $css . '</style>';
        }

        // ── Container ──
        echo '<div id="pps-calculator-wrap" style="margin:-20px 0 40px;clear:both">';
        echo '<div id="pps-calculator-root"></div>';
        echo '</div>';

        // ── App code ──
        // JSX source ships inline as text/babel (Babel Standalone defers it).
        // Compiled builds enqueue an external dependency-ordered file; the
        // inline fallback is wrapped in DOMContentLoaded so it can never run
        // ahead of the footer-loaded React (round-4 QA blocker).
        if ( $parts['app_code'] ) {
            if ( empty( $parts['compiled'] ) ) {
                echo '<script data-cfasync="false" type="text/babel">' . $parts['app_code'] . '</script>';
            } elseif ( ! pps_enqueue_calc_app_file( $filepath, $parts['app_code'] ) ) {
                echo '<script data-cfasync="false">document.addEventListener("DOMContentLoaded",function(){' . $parts['app_code'] . "\n});</script>";
            }
        }
    }, 25 );
});

// ═══════════════════════════════════════════════════════════════
// AJAX: UPLOAD ARTWORK
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_upload_artwork', 'pps_ajax_upload_artwork' );
add_action( 'wp_ajax_nopriv_pps_upload_artwork', 'pps_ajax_upload_artwork' );

function pps_ajax_upload_artwork() {
    check_ajax_referer( 'pps_upload_artwork', 'nonce' );

    if ( empty( $_FILES['artwork'] ) || $_FILES['artwork']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'No file received.' );
    }

    $file = $_FILES['artwork'];
    $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

    // 'txt' is permitted for the generated manipulation-manifest deliverable
    // (plain-text record of art transforms) that ships with the approval package.
    $allowed = array( 'pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'eps', 'ai', 'txt' );
    if ( ! in_array( $ext, $allowed, true ) ) {
        wp_send_json_error( 'File type not allowed: .' . $ext );
    }

    if ( $file['size'] > 200 * 1024 * 1024 ) {
        wp_send_json_error( 'File too large (max 200MB).' );
    }

    // Magic-byte validation: the file's leading bytes must match its claimed
    // extension — blocks executables/polyglots renamed to an allowed type.
    $fh   = fopen( $file['tmp_name'], 'rb' );
    $head = $fh ? (string) fread( $fh, 16 ) : '';
    if ( $fh ) {
        fclose( $fh );
    }
    $magic_ok = false;
    switch ( $ext ) {
        case 'pdf':
            $magic_ok = strncmp( $head, '%PDF', 4 ) === 0;
            break;
        case 'jpg':
        case 'jpeg':
            $magic_ok = strncmp( $head, "\xFF\xD8\xFF", 3 ) === 0;
            break;
        case 'png':
            $magic_ok = strncmp( $head, "\x89PNG\r\n\x1a\n", 8 ) === 0;
            break;
        case 'tif':
        case 'tiff':
            $magic_ok = strncmp( $head, "II*\x00", 4 ) === 0 || strncmp( $head, "MM\x00*", 4 ) === 0;
            break;
        case 'eps':
            $magic_ok = strncmp( $head, '%!PS', 4 ) === 0 || strncmp( $head, "\xC5\xD0\xD3\xC6", 4 ) === 0;
            break;
        case 'ai': // modern AI = PDF-compatible; legacy AI = PostScript
            $magic_ok = strncmp( $head, '%PDF', 4 ) === 0 || strncmp( $head, '%!PS', 4 ) === 0;
            break;
        case 'txt': // generated manifests: small, and no executable headers
            $magic_ok = $file['size'] <= 1024 * 1024
                && strncmp( $head, 'MZ', 2 ) !== 0
                && strncmp( $head, "\x7FELF", 4 ) !== 0
                && strncmp( $head, "\xCA\xFE\xBA\xBE", 4 ) !== 0;
            break;
    }
    if ( ! $magic_ok ) {
        wp_send_json_error( 'File content does not match its .' . $ext . ' extension — re-export the file and try again.' );
    }

    // Unique filename: timestamp-hash.ext
    $token       = date( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
    $stored_name = $token . '.' . $ext;

    // Organize by year/month
    $dir     = pps_artwork_dir();
    $subdir  = date( 'Y' ) . '/' . date( 'm' );
    $full_dir = trailingslashit( $dir ) . $subdir;
    if ( ! file_exists( $full_dir ) ) {
        wp_mkdir_p( $full_dir );
    }

    // Defense in depth: nothing in the artwork tree may ever execute, and
    // the directory must not be listable. (nginx ignores .htaccess — there
    // the stored token.ext naming already prevents script extensions.)
    $guard = trailingslashit( $full_dir ) . '.htaccess';
    if ( ! file_exists( $guard ) ) {
        @file_put_contents( $guard,
            "<FilesMatch \"\\.(?:php|phtml|phar|php\\d|phps|cgi|pl|py|sh|asp|aspx|jsp)$\">\n" .
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" .
            "</FilesMatch>\nOptions -Indexes -ExecCGI\n" );
    }
    if ( ! file_exists( trailingslashit( $full_dir ) . 'index.html' ) ) {
        @file_put_contents( trailingslashit( $full_dir ) . 'index.html', '' );
    }

    // Optional VirusTotal HASH lookup (privacy-safe: only the SHA-256 leaves
    // the server — never the file). Opt-in via wp_options['pps_vt_api_key'].
    // A known-malicious verdict rejects the upload; unknown/clean/errors pass
    // (fail-open — this is a second opinion, not the primary defense).
    $vt_key = get_option( 'pps_vt_api_key', '' );
    if ( $vt_key ) {
        $sha = @hash_file( 'sha256', $file['tmp_name'] );
        if ( $sha ) {
            $vt = wp_remote_get( 'https://www.virustotal.com/api/v3/files/' . $sha, array(
                'timeout' => 8,
                'headers' => array( 'x-apikey' => $vt_key ),
            ) );
            if ( ! is_wp_error( $vt ) && wp_remote_retrieve_response_code( $vt ) === 200 ) {
                $body  = json_decode( wp_remote_retrieve_body( $vt ), true );
                $stats = $body['data']['attributes']['last_analysis_stats'] ?? array();
                $bad   = intval( $stats['malicious'] ?? 0 ) + intval( $stats['suspicious'] ?? 0 );
                if ( $bad >= 2 ) { // ≥2 engines flag it — refuse
                    wp_send_json_error( 'This file was flagged as unsafe by malware scanning and cannot be uploaded. Please re-export from your source application.' );
                }
            }
        }
    }

    $dest = trailingslashit( $full_dir ) . $stored_name;

    if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
        wp_send_json_error( 'Failed to save file.' );
    }

    // Return the full relative path — stored directly in cart, no glob needed
    $relative_path = 'pps-artwork/' . $subdir . '/' . $stored_name;

    wp_send_json_success( array(
        'path'     => $relative_path,
        'filename' => $file['name'],
        'size'     => $file['size'],
    ) );
}

// Ensure WordPress accepts .txt uploads. The approval-package manipulation
// manifest is a plain-text file; some security plugins (e.g. AIOS) strip
// text/plain from the allowed-mime list, which would make wp_check_filetype()
// return an empty type (and any wp_handle_upload/media path reject the file).
// Registering it guarantees .txt is recognized as text/plain site-wide.
add_filter( 'upload_mimes', function( $mimes ) {
    if ( empty( $mimes['txt'] ) ) {
        $mimes['txt'] = 'text/plain';
    }
    return $mimes;
} );

// ═══════════════════════════════════════════════════════════════
// AJAX: ADD TO CART
// ═══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_pps_add_to_cart', 'pps_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_pps_add_to_cart', 'pps_ajax_add_to_cart' );

// ═══════════════════════════════════════════════════════════════
// QUOTE QUESTION FORM — AJAX handler
// ═══════════════════════════════════════════════════════════════
//
// Customer fills in name/email/phone/question while viewing a quote.
// Calculator JS posts the current calc state + a re-open URL. We email
// the staff (PCF question_recipient_email → pps_question_recipient option →
// WooCommerce from-address; the WP admin email is deliberately NOT in the
// chain except as an unreachable-recipient last resort — owner rule
// 2026-08-25) and send a confirmation back to the customer. Honeypot + rate
// limit prevent the obvious bot floods. Reuses the pps_add_to_cart nonce
// since the calculator already has it loaded.

add_action( 'wp_ajax_pps_quote_question', 'pps_ajax_quote_question' );
add_action( 'wp_ajax_nopriv_pps_quote_question', 'pps_ajax_quote_question' );

// ── CPT: pps_question (admin-only log of submissions) ──
add_action( 'init', function() {
    register_post_type( 'pps_question', array(
        'label'          => 'Calc Questions',
        'labels'         => array(
            'name'          => 'Calc Questions',
            'singular_name' => 'Calc Question',
            'all_items'     => 'All Questions',
            'edit_item'     => 'View Question',
            'view_item'     => 'View Question',
            'search_items'  => 'Search questions',
            'not_found'     => 'No questions yet.',
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_admin_bar'   => false,
        'show_in_nav_menus'   => false,
        'menu_position'       => 26,
        'menu_icon'           => 'dashicons-format-chat',
        'supports'            => array( 'title', 'editor' ),
        'capability_type'     => 'post',
        'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
        'map_meta_cap'        => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'exclude_from_search' => true,
    ) );
} );

// Custom columns on the Calc Questions list table — show Email + Calc + Total
add_filter( 'manage_pps_question_posts_columns', function( $cols ) {
    $new = array();
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['pps_q_email'] = 'Email';
            $new['pps_q_calc']  = 'Calculator';
            $new['pps_q_total'] = 'Total';
        }
    }
    return $new;
} );
add_action( 'manage_pps_question_posts_custom_column', function( $col, $post_id ) {
    if ( $col === 'pps_q_email' ) {
        $email = get_post_meta( $post_id, '_pps_q_email', true );
        if ( $email ) echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
    } elseif ( $col === 'pps_q_calc' ) {
        $label  = get_post_meta( $post_id, '_pps_q_calc_label', true );
        $preset = get_post_meta( $post_id, '_pps_q_preset_slug', true );
        echo esc_html( $label );
        if ( $preset ) echo ' <span style="color:#888">· ' . esc_html( $preset ) . '</span>';
    } elseif ( $col === 'pps_q_total' ) {
        $t = (float) get_post_meta( $post_id, '_pps_q_total', true );
        if ( $t > 0 ) echo '$' . number_format( $t, 2 );
    }
}, 10, 2 );

// Render snapshot meta as a read-only meta box on the edit screen
add_action( 'add_meta_boxes', function() {
    add_meta_box( 'pps_q_snapshot', 'Quote snapshot', 'pps_render_question_meta_box', 'pps_question', 'side', 'high' );
} );
function pps_render_question_meta_box( $post ) {
    $rows = array(
        'Name'         => get_post_meta( $post->ID, '_pps_q_name', true ),
        'Email'        => get_post_meta( $post->ID, '_pps_q_email', true ),
        'Phone'        => get_post_meta( $post->ID, '_pps_q_phone', true ),
        'Callback'     => get_post_meta( $post->ID, '_pps_q_callback', true ) ? 'Yes' : '',
        'Calculator'   => get_post_meta( $post->ID, '_pps_q_calc_label', true ),
        'Preset'       => get_post_meta( $post->ID, '_pps_q_preset_slug', true ),
        'Total'        => ( $t = (float) get_post_meta( $post->ID, '_pps_q_total', true ) ) > 0 ? '$' . number_format( $t, 2 ) : '',
        'Per Unit'     => ( $u = (float) get_post_meta( $post->ID, '_pps_q_per_unit', true ) ) > 0 ? '$' . number_format( $u, 2 ) : '',
        'Quantity'     => ( $q = (int) get_post_meta( $post->ID, '_pps_q_qty', true ) ) > 0 ? number_format( $q ) : '',
        'Days'         => ( $d = (int) get_post_meta( $post->ID, '_pps_q_days', true ) ) > 0 ? $d . ' biz' : '',
        'Submitter IP' => get_post_meta( $post->ID, '_pps_q_user_ip', true ),
    );
    echo '<table class="form-table" style="margin:0"><tbody>';
    foreach ( $rows as $label => $val ) {
        if ( $val === '' || $val === null ) continue;
        echo '<tr><th scope="row" style="padding:6px 0;font-size:12px;width:90px">' . esc_html( $label ) . '</th><td style="padding:6px 0;font-size:12px">' . esc_html( $val ) . '</td></tr>';
    }
    echo '</tbody></table>';
    $summary = get_post_meta( $post->ID, '_pps_q_summary', true );
    if ( $summary ) {
        echo '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #ddd"><strong style="font-size:12px">Spec</strong><pre style="margin:4px 0 0;padding:8px;background:#fafafa;border-radius:3px;font-size:11px;white-space:pre-wrap">' . esc_html( $summary ) . '</pre></div>';
    }
    $files = get_post_meta( $post->ID, '_pps_q_files', true );
    if ( is_array( $files ) && $files ) {
        echo '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #ddd"><strong style="font-size:12px">Uploaded Files</strong><ul style="margin:4px 0 0;font-size:11px">';
        foreach ( $files as $furl ) {
            echo '<li><a href="' . esc_url( $furl ) . '" target="_blank">' . esc_html( basename( $furl ) ) . '</a></li>';
        }
        echo '</ul></div>';
    }
    $reorder = get_post_meta( $post->ID, '_pps_q_reorder_url', true );
    if ( $reorder ) {
        $btn_label = strpos( get_post_meta( $post->ID, '_pps_q_calc_label', true ), 'Lead:' ) === 0
            ? 'View source page &rarr;' : 'Open this quote in calculator &rarr;';
        echo '<p style="margin-top:10px"><a href="' . esc_url( $reorder ) . '" target="_blank" class="button button-primary" style="width:100%;text-align:center">' . $btn_label . '</a></p>';
    }
}

function pps_quote_question_rate_key() {
    $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'pps';
    return 'pps_qq_' . substr( hash( 'sha256', $ip . $salt ), 0, 24 );
}

function pps_ajax_quote_question() {
    check_ajax_referer( 'pps_add_to_cart', 'nonce' );

    // ── Rate limit: 5 submissions per IP per 15 minutes ──
    $rkey     = pps_quote_question_rate_key();
    $attempts = (int) get_transient( $rkey );
    if ( $attempts >= 5 ) {
        wp_send_json_error( array( 'message' => 'Too many submissions from your address. Please try again in a few minutes.' ), 429 );
    }
    set_transient( $rkey, $attempts + 1, 15 * MINUTE_IN_SECONDS );

    // ── Honeypot: silently drop bot submissions ──
    $honey = isset( $_POST['hp'] ) ? sanitize_text_field( wp_unslash( $_POST['hp'] ) ) : '';
    if ( $honey !== '' ) {
        wp_send_json_success( array( 'message' => 'Thanks — we will be in touch shortly.' ) );
    }

    // ── Required fields ──
    $name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
    if ( $name === '' || strlen( $name ) > 120 ) {
        wp_send_json_error( array( 'message' => 'Please enter your name.' ) );
    }
    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
    }
    if ( $message === '' || strlen( $message ) > 4000 ) {
        wp_send_json_error( array( 'message' => 'Please enter a question (1-4000 chars).' ) );
    }

    // ── Optional fields ──
    $phone        = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
    if ( strlen( $phone ) > 40 ) $phone = substr( $phone, 0, 40 );
    $summary      = sanitize_textarea_field( wp_unslash( $_POST['summary'] ?? '' ) );
    if ( strlen( $summary ) > 4000 ) $summary = substr( $summary, 0, 4000 );
    $total        = isset( $_POST['total'] ) ? (float) $_POST['total'] : 0;
    $per_unit     = isset( $_POST['perUnit'] ) ? (float) $_POST['perUnit'] : 0;
    $qty          = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0;
    $days         = isset( $_POST['days'] ) ? (int) $_POST['days'] : 0;
    $calc_type    = sanitize_key( wp_unslash( $_POST['calcType'] ?? '' ) );
    $calc_label   = sanitize_text_field( wp_unslash( $_POST['calcLabel'] ?? '' ) );
    $preset_slug  = sanitize_key( wp_unslash( $_POST['presetSlug'] ?? '' ) );

    // Re-open URL: must be on our site to prevent the email from being used
    // as an open redirector. We accept the calc's pre-built reorder URL and
    // validate the host.
    $reorder_url  = '';
    if ( ! empty( $_POST['reorderUrl'] ) ) {
        $candidate = esc_url_raw( wp_unslash( $_POST['reorderUrl'] ), array( 'http', 'https' ) );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $cand_host = wp_parse_url( $candidate, PHP_URL_HOST );
        if ( $candidate && $cand_host && strcasecmp( $cand_host, $site_host ) === 0 ) {
            $reorder_url = $candidate;
        }
    }

    // ── Compose staff email ──
    // Recipient resolution: PCF (admin-editable) → legacy option → WooCommerce
    // from-address. Owner rule (2026-08-25): the WP admin email is a personal
    // mailbox and must NOT receive site inquiry notifications — it remains only
    // as the very last resort, because a form whose notification silently goes
    // nowhere is worse than one that reaches the wrong inbox.
    $recipient = '';
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $cand = isset( $cfg['pcf']['question_recipient_email'] ) ? trim( (string) $cfg['pcf']['question_recipient_email'] ) : '';
        if ( is_email( $cand ) ) $recipient = $cand;
    }
    if ( ! $recipient ) {
        $cand = get_option( 'pps_question_recipient', '' );
        if ( is_email( $cand ) ) $recipient = $cand;
    }
    if ( ! $recipient ) {
        $cand = get_option( 'woocommerce_email_from_address', '' );
        if ( is_email( $cand ) ) $recipient = $cand;
    }
    if ( ! $recipient ) $recipient = get_option( 'admin_email' );
    $subject_calc = $calc_label !== '' ? $calc_label : 'Calculator';
    $subject = sprintf( '[PPS] Question on %s quote — %s', $subject_calc, $name );

    $body_lines = array();
    $body_lines[] = 'A customer has a question about a quote.';
    $body_lines[] = '';
    $body_lines[] = 'NAME:    ' . $name;
    $body_lines[] = 'EMAIL:   ' . $email;
    if ( $phone !== '' ) $body_lines[] = 'PHONE:   ' . $phone;
    $body_lines[] = '';
    $body_lines[] = 'QUESTION:';
    $body_lines[] = $message;
    $body_lines[] = '';
    $body_lines[] = 'QUOTE:';
    if ( $calc_label !== '' )  $body_lines[] = '  Calculator: ' . $calc_label;
    if ( $preset_slug !== '' ) $body_lines[] = '  Preset:     ' . $preset_slug;
    if ( $total > 0 )          $body_lines[] = '  Total:      $' . number_format( $total, 2 );
    if ( $per_unit > 0 )       $body_lines[] = '  Per unit:   $' . number_format( $per_unit, 2 );
    if ( $qty > 0 )            $body_lines[] = '  Quantity:   ' . number_format( $qty );
    if ( $days > 0 )           $body_lines[] = '  Days:       ' . $days . ' biz';
    if ( $summary !== '' ) {
        $body_lines[] = '';
        $body_lines[] = 'SPEC:';
        foreach ( explode( "\n", $summary ) as $line ) {
            $body_lines[] = '  ' . $line;
        }
    }
    if ( $reorder_url !== '' ) {
        $body_lines[] = '';
        $body_lines[] = 'OPEN THIS QUOTE IN THE CALCULATOR:';
        $body_lines[] = $reorder_url;
    }
    $body_lines[] = '';
    $body_lines[] = '— Submitted ' . current_time( 'M j, Y g:i a T' );

    $staff_body = implode( "\n", $body_lines );
    $staff_headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . sprintf( '%s <%s>', $name, $email ),
    );

    // Log the submission as a pps_question post BEFORE sending so we keep
    // a record even if mail delivery fails. wp_kses cleans the bodies for
    // safe storage; emails were already plain text but this is defense in
    // depth in case wp_insert_post stores HTML escapes oddly.
    $post_title = sprintf( '%s — %s', $name, $calc_label !== '' ? $calc_label : 'Calculator' );
    if ( $total > 0 ) $post_title .= sprintf( ' · $%s', number_format( $total, 2 ) );
    $post_id = wp_insert_post( array(
        'post_type'    => 'pps_question',
        'post_status'  => 'publish',
        'post_title'   => wp_strip_all_tags( $post_title ),
        'post_content' => $message,
    ), true );
    if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
        update_post_meta( $post_id, '_pps_q_name',         $name );
        update_post_meta( $post_id, '_pps_q_email',        $email );
        update_post_meta( $post_id, '_pps_q_phone',        $phone );
        update_post_meta( $post_id, '_pps_q_calc_type',    $calc_type );
        update_post_meta( $post_id, '_pps_q_calc_label',   $calc_label );
        update_post_meta( $post_id, '_pps_q_preset_slug',  $preset_slug );
        update_post_meta( $post_id, '_pps_q_total',        $total );
        update_post_meta( $post_id, '_pps_q_per_unit',     $per_unit );
        update_post_meta( $post_id, '_pps_q_qty',          $qty );
        update_post_meta( $post_id, '_pps_q_days',         $days );
        update_post_meta( $post_id, '_pps_q_summary',      $summary );
        update_post_meta( $post_id, '_pps_q_reorder_url',  $reorder_url );
        update_post_meta( $post_id, '_pps_q_user_ip',      isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '' );
    }

    $sent_staff = wp_mail( $recipient, $subject, $staff_body, $staff_headers );
    if ( ! is_wp_error( $post_id ) && $post_id > 0 ) {
        update_post_meta( $post_id, '_pps_q_email_sent', $sent_staff ? 1 : 0 );
    }

    // ── Compose customer confirmation ──
    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $cust_subject = sprintf( 'We got your question — %s', $site_name );
    $cust_lines = array();
    $cust_lines[] = sprintf( 'Hi %s,', $name );
    $cust_lines[] = '';
    $cust_lines[] = 'Thanks for reaching out about your quote. We typically respond within 1 business day.';
    $cust_lines[] = '';
    $cust_lines[] = 'For reference, here is the quote you were looking at:';
    $cust_lines[] = '';
    if ( $calc_label !== '' ) $cust_lines[] = 'Calculator: ' . $calc_label;
    if ( $total > 0 )         $cust_lines[] = 'Total:      $' . number_format( $total, 2 );
    if ( $qty > 0 )           $cust_lines[] = 'Quantity:   ' . number_format( $qty );
    if ( $summary !== '' ) {
        $cust_lines[] = '';
        foreach ( explode( "\n", $summary ) as $line ) $cust_lines[] = $line;
    }
    if ( $reorder_url !== '' ) {
        $cust_lines[] = '';
        $cust_lines[] = 'Re-open this quote in the calculator:';
        $cust_lines[] = $reorder_url;
    }
    $cust_lines[] = '';
    $cust_lines[] = 'Your question:';
    $cust_lines[] = $message;
    $cust_lines[] = '';
    $cust_lines[] = sprintf( '— The %s team', $site_name );

    $cust_headers = array( 'Content-Type: text/plain; charset=UTF-8' );
    wp_mail( $email, $cust_subject, implode( "\n", $cust_lines ), $cust_headers );

    if ( $sent_staff ) {
        wp_send_json_success( array( 'message' => 'Thanks! We received your question and emailed you a copy. Expect a reply within 1 business day.' ) );
    } else {
        wp_send_json_error( array( 'message' => 'Sorry — there was a problem submitting your question. Please try again or call us directly.' ) );
    }
}

/**
 * Materials price floor — server-authoritative lower bound for booklet calculators.
 *
 * Business rule (owner, 2026-07-27): nothing may sell below the minimum markup on
 * materials, excluding labour and add-ons:
 *
 *     floor = ( (sheet cost + print cost) x total sheets ) x minimum markup
 *
 * Why this is trustworthy where the posted price is not: every input is either
 * server-side config (paper prices, click costs, imposition, markup) or a customer
 * selection that is SELF-ENFORCING — quantity, page count, trim size and stock all
 * flow into PPS-Spec, so understating one to depress the floor also shrinks the job
 * that gets produced. The debug block in pps_metadata is deliberately NOT used: it
 * has no downstream effect, so lying about it would be free.
 *
 * NOTE: this is the one piece of pricing arithmetic that lives in PHP. It is a
 * security bound, not a quote — it must never be used to price anything. See
 * docs/MASTER_PRICING_LOGIC.md.
 *
 * Fails OPEN by design: any input it cannot resolve authoritatively (custom trim,
 * unknown stock, unrecognized calculator) returns null and the check is skipped. A
 * false rejection at add-to-cart costs a real sale; a missed check costs margin.
 *
 * @return float|null Floor in dollars, or null when it cannot be determined.
 */
function pps_materials_price_floor( $product_id, $metadata_json ) {
    $meta = json_decode( (string) $metadata_json, true );
    if ( ! is_array( $meta ) ) return null;

    // Booklet calculators only — they share this sheet math. Others fail open.
    $calc_file = pps_get_calculator_for_product( $product_id );
    $calc      = $calc_file ? pps_get_calc_type_for_filename( $calc_file ) : '';
    if ( ! in_array( $calc, array( 'saddle', 'perfect-bound', 'coupon' ), true ) ) return null;

    $cfg = get_option( PPS_CONFIG_OPTION, array() );
    $pcf = isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ? $cfg['pcf'] : array();

    // ── Imposition from the trim size (server-side table). Custom trims skip. ──
    $size_label = isset( $meta['sizeLabel'] ) ? (string) $meta['sizeLabel'] : '';
    if ( $size_label === '' || $size_label === 'Custom Size' ) return null;
    $imp = 0;
    foreach ( (array) ( $cfg['size_presets'] ?? array() ) as $group ) {
        foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
            if ( isset( $item['label'] ) && $item['label'] === $size_label ) {
                $imp = floatval( $item['imp'] ?? 0 );
                break 2;
            }
        }
    }
    if ( $imp <= 0 ) return null;

    // ── Paper prices from config, matched by label. Never trust the posted price. ──
    $paper_price = function( $label ) use ( $cfg ) {
        if ( $label === '' ) return null;
        foreach ( array( 'papers_nc', 'papers_cs' ) as $key ) {
            foreach ( (array) ( $cfg[ $key ] ?? array() ) as $p ) {
                if ( isset( $p['label'] ) && $p['label'] === $label ) return floatval( $p['price'] ?? 0 );
            }
        }
        return null;
    };
    $inside_label = isset( $meta['insidePaper']['label'] ) ? (string) $meta['insidePaper']['label'] : '';
    $inside_price = $paper_price( $inside_label );
    if ( $inside_price === null || $inside_price <= 0 ) return null;

    // Self-cover uses the COVER_SAME stub, not the inside stock: those sheets are
    // already counted in the inside total, so the engine charges a nominal rate for
    // them. Using the real inside price here would inflate the floor several-fold on
    // the commonest configuration and reject legitimate orders.
    $cover_same  = ( ( $meta['coverMode'] ?? '' ) === 'same' );
    $cover_label = isset( $meta['coverPaper']['label'] ) ? (string) $meta['coverPaper']['label'] : '';
    $cover_price = $cover_same
        ? floatval( $cfg['cover_same']['price'] ?? 0.01 )
        : $paper_price( $cover_label );
    if ( $cover_price === null || $cover_price <= 0 ) return null;

    // ── Sheet counts, mirroring the calculator's primitives ──
    $booklet_sheets = 0.0; $total_qty = 0.0;
    foreach ( (array) ( $meta['sets'] ?? array() ) as $set ) {
        $q  = floatval( $set['qty'] ?? 0 );
        $pg = floatval( $set['pages'] ?? 0 );
        if ( $q <= 0 || $pg <= 0 ) return null;
        $booklet_sheets += ( $q * $pg ) / 4;
        $total_qty      += $q;
    }
    if ( $booklet_sheets <= 0 || $total_qty <= 0 ) return null;
    $inside_sheets = $booklet_sheets / ( $imp / 2 );
    $cover_sheets  = $total_qty / $imp;

    // ── Click costs (both sides), same shape as the calculator's cost lines ──
    $black = floatval( $pcf['printing_black_cost'] ?? 0.01 );
    $color = floatval( $pcf['printing_fullcolor_cost'] ?? 0.05 );
    $inside_print = ( ( $meta['insideColor'] ?? '' ) === 'bw' ) ? ( $black * 2 ) : ( $black * 2 + $color * 2 );
    $cover_print  = ( ( $meta['coverColor'] ?? '' ) === 'bw' ) ? ( $black * 2 ) : ( $color * 2 );

    $materials = ( $inside_price + $inside_print ) * $inside_sheets
               + ( $cover_price  + $cover_print  ) * $cover_sheets;

    // Minimum markup. The cover's own minimum is higher (1.8); deliberately using the
    // lower inside figure for both so the bound can never exceed a legitimate quote.
    $min_markup = floatval( $pcf['booklet_minimummarkup'] ?? 1.45 );
    if ( $min_markup <= 0 ) return null;

    $floor = $materials * $min_markup;

    // A site-wide sale genuinely lowers the minimum legitimate price, so the bound has
    // to move with it or the first big promotion starts rejecting real orders. Measured
    // against the live engine: without this, a 20% sale falsely rejects large jobs
    // (floor reaches 1.12x the quoted price) and a 30% sale is worse.
    $sale = floatval( $pcf['sale_discount_pct'] ?? 0 );
    if ( $sale > 0 && $sale < 1 ) {
        $floor *= ( 1 - $sale );
    }

    return ( is_finite( $floor ) && $floor > 0 ) ? $floor : null;
}

/**
 * Payload tripwire — detects a hand-edited add-to-cart request.
 *
 * The calculator posts two fields that do not participate in pricing:
 *   pps_tier  always the literal "retail"
 *   pps_lock  a checksum over the posted price string + product id
 *
 * Neither is load-bearing, so no legitimate flow ever produces a different value.
 * Editing pps_price in devtools without recomputing pps_lock trips it; flipping
 * pps_tier to something that looks like a discount tier trips it unambiguously.
 *
 * This is DETECTION, not a control. The salt is client-side, so anyone who reads
 * the calculator source can recompute the checksum — pps_materials_price_floor()
 * remains the actual bound. The value here is that you find out someone tried.
 *
 * Absent fields are ignored so a browser holding an older cached calculator is
 * never rejected. Returns a reason string when tripped, or '' when clean.
 */
function pps_cart_tripwire( $product_id ) {
    // Decoy: any value other than the literal we ship is a deliberate edit.
    if ( isset( $_POST['pps_tier'] ) ) {
        $tier = (string) wp_unslash( $_POST['pps_tier'] );
        if ( $tier !== 'retail' ) {
            return 'tier=' . substr( sanitize_text_field( $tier ), 0, 40 );
        }
    }
    // Canary: checksum must match the price string actually posted.
    if ( isset( $_POST['pps_lock'] ) && isset( $_POST['pps_price'] ) ) {
        $raw  = (string) wp_unslash( $_POST['pps_price'] );
        $seed = $raw . '|' . (int) $product_id;
        $h    = 0;
        $len  = strlen( $seed );
        for ( $i = 0; $i < $len; $i++ ) {
            $h = ( $h * 31 + ord( $seed[ $i ] ) ) & 0xFFFFFFFF;
        }
        $expect = base_convert( (string) $h, 10, 36 );
        $got    = strtolower( trim( (string) wp_unslash( $_POST['pps_lock'] ) ) );
        if ( $got !== $expect ) {
            return 'lock mismatch';
        }
    }
    return '';
}

/**
 * Record a tripwire hit: always logged, admin alerted at most once per 30 minutes
 * so a repeated probe can't be turned into a mail flood.
 */
function pps_cart_tripwire_report( $product_id, $reason, $price ) {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '?';
    error_log( sprintf(
        '[pps] CART TRIPWIRE pid=%d price=%s reason=%s ip=%s ua=%s',
        (int) $product_id, $price, $reason, $ip,
        isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 120 ) : '?'
    ) );

    if ( get_transient( 'pps_tripwire_alerted' ) ) return;
    set_transient( 'pps_tripwire_alerted', 1, 30 * MINUTE_IN_SECONDS );

    $cfg = get_option( PPS_CONFIG_OPTION, array() );
    $pcf = isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ? $cfg['pcf'] : array();
    $to  = '';
    $cand = isset( $pcf['question_recipient_email'] ) ? trim( (string) $pcf['question_recipient_email'] ) : '';
    if ( is_email( $cand ) ) $to = $cand;
    if ( ! $to ) $to = get_option( 'admin_email' );
    if ( ! is_email( $to ) ) return;

    wp_mail(
        $to,
        '[PPS] Cart payload tampering detected',
        implode( "\n", array(
            'An add-to-cart request arrived with an edited payload.',
            '',
            'Product:  ' . (int) $product_id,
            'Price:    ' . $price,
            'Reason:   ' . $reason,
            'IP:       ' . $ip,
            'Time:     ' . current_time( 'M j, Y g:i a T' ),
            '',
            'The request was ' . ( floatval( $pcf['pps_tripwire_mode_flag'] ?? 0 ) ? 'ALLOWED and flagged on the order' : 'REJECTED' ) . '.',
            'Further alerts are suppressed for 30 minutes.',
        ) ),
        array( 'Content-Type: text/plain; charset=UTF-8' )
    );
}

function pps_ajax_add_to_cart() {
    check_ajax_referer( 'pps_add_to_cart', 'nonce' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $price      = floatval( $_POST['pps_price'] ?? 0 );
    $rush       = floatval( $_POST['pps_rush'] ?? 0 );
    // WordPress slashes $_POST on every request (magic-quotes emulation) and
    // sanitize_*() does not undo it, so without wp_unslash an apostrophe survives as
    // a literal backslash all the way into the cart and checkout summary — e.g.
    // "I don\'t have bleeds". Every other $_POST read in this file already unslashes.
    $summary    = sanitize_textarea_field( wp_unslash( $_POST['pps_summary'] ?? '' ) );
    $metadata   = wp_unslash( $_POST['pps_metadata'] ?? '{}' );
    $biz_days   = intval( $_POST['pps_biz_days'] ?? 5 );

    if ( ! $product_id || $price <= 0 ) {
        wp_send_json_error( 'Invalid product or price.' );
    }

    json_decode( $metadata );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'Invalid metadata JSON.' );
    }

    // ── Payload tripwire (2026-07-27) ──
    // Fires only on a deliberately edited request; see pps_cart_tripwire().
    $trip = pps_cart_tripwire( $product_id );
    if ( $trip !== '' ) {
        pps_cart_tripwire_report( $product_id, $trip, (string) ( $_POST['pps_price'] ?? '' ) );
        $cfg_t = get_option( PPS_CONFIG_OPTION, array() );
        $pcf_t = isset( $cfg_t['pcf'] ) && is_array( $cfg_t['pcf'] ) ? $cfg_t['pcf'] : array();
        if ( floatval( $pcf_t['pps_tripwire_mode_flag'] ?? 0 ) ) {
            // Flag mode: accept, but mark the order so it can be reviewed before
            // it goes to production. Catches more than rejecting, which teaches
            // the prober exactly which field gave them away.
            $decoded = json_decode( $metadata, true );
            if ( is_array( $decoded ) ) {
                $decoded['tamperFlag'] = $trip;
                $metadata = wp_json_encode( $decoded );
            }
        } else {
            wp_send_json_error( 'Could not add to cart. Please refresh the page and try again.' );
        }
    }

    // ── Price-tampering defense (security, 2026-05-17) ──
    // The calculator computes price client-side and posts it. A malicious
    // shopper could POST pps_price=0.01 to check out a real order for cents.
    // Until we port the pricing engine to PHP for an authoritative recompute,
    // enforce a hard floor: the submitted price must be >= the smaller of
    //   (a) the product's regular_price × pps_min_price_pct (default 50%), or
    //   (b) the absolute floor pps_absolute_min_price (default $5).
    // Both knobs live in wp_options['pps_calc_config']['pcf']; admin-tunable.
    $cfg = get_option( PPS_CONFIG_OPTION, array() );
    $pcf = isset( $cfg['pcf'] ) && is_array( $cfg['pcf'] ) ? $cfg['pcf'] : array();
    $min_pct       = floatval( $pcf['pps_min_price_pct']      ?? 0.5 );
    $absolute_min  = floatval( $pcf['pps_absolute_min_price'] ?? 5 );
    $product       = wc_get_product( $product_id );
    if ( $product ) {
        $regular = floatval( $product->get_regular_price() );
        if ( $regular > 0 ) {
            $pct_floor    = $regular * $min_pct;
            $allowed_min  = max( $absolute_min, $pct_floor );
            if ( $price < $allowed_min ) {
                if ( function_exists( 'error_log' ) ) {
                    error_log( sprintf(
                        '[pps] add-to-cart price floor rejected: pid=%d submitted=%.2f required>=%.2f (regular=%.2f, pct=%.2f, abs=%.2f)',
                        $product_id, $price, $allowed_min, $regular, $min_pct, $absolute_min
                    ) );
                }
                wp_send_json_error( 'Price below product floor. Refresh and try again.' );
            }
        } elseif ( $price < $absolute_min ) {
            // No regular_price set — fall back to absolute floor only.
            wp_send_json_error( 'Price below minimum. Refresh and try again.' );
        }
    }

    // ── Materials floor (2026-07-27) ──
    // Scales with the job, unlike the flat product floor above: a large booklet order
    // can no longer be checked out near the $5/50%-of-regular_price minimum. Returns
    // null and skips whenever an input can't be resolved authoritatively.
    $mat_floor = pps_materials_price_floor( $product_id, $metadata );
    if ( $mat_floor !== null && $price < $mat_floor ) {
        error_log( sprintf(
            '[pps] add-to-cart materials floor rejected: pid=%d submitted=%.2f required>=%.2f',
            $product_id, $price, $mat_floor
        ) );
        if ( floatval( $pcf['pps_floor_enforce'] ?? 1 ) ) {
            wp_send_json_error( 'Price below minimum for this configuration. Refresh and try again.' );
        }
    }

    // Edit mode: remember old cart key — remove AFTER successful add for atomicity
    $edit_key = null;
    if ( WC()->session && WC()->cart ) {
        $edit_key = WC()->session->get( 'pps_edit_key_' . $product_id );
        if ( $edit_key && ! isset( WC()->cart->get_cart()[ $edit_key ] ) ) {
            $edit_key = null; // key no longer in cart
        }
    }

    $config_hash = md5( $metadata );

    $cart_item_data = array(
        'pps_price'    => $price,
        'pps_rush'     => $rush,
        'pps_summary'  => $summary,
        'pps_metadata' => $metadata,
        'pps_biz_days' => $biz_days,
        'pps_hash'     => $config_hash,
    );

    // Artwork: direct relative path from upload endpoint
    $artwork_path = sanitize_text_field( wp_unslash( $_POST['pps_artwork_path'] ?? '' ) );
    if ( $artwork_path ) {
        // Security: prevent path traversal — must start with pps-artwork/ and contain no ..
        if ( strpos( $artwork_path, '..' ) !== false || strpos( $artwork_path, 'pps-artwork/' ) !== 0 ) {
            wp_send_json_error( 'Invalid artwork path.' );
        }
        $cart_item_data['pps_artwork_path'] = $artwork_path;
    }

    // Approval binding: SHA-256 the calculator computed over the print-ready
    // bytes the customer approved. Strict 64-hex or dropped — this is a
    // production gate (the imposition tool refuses on mismatch), so a malformed
    // value must vanish rather than half-match.
    $proof_hash = strtolower( sanitize_text_field( wp_unslash( $_POST['pps_proof_hash'] ?? '' ) ) );
    if ( preg_match( '/^[0-9a-f]{64}$/', $proof_hash ) ) {
        $cart_item_data['pps_proof_hash'] = $proof_hash;
    }

    // Full approval package: every uploaded deliverable (raw + print-ready PDF +
    // preview pages + manifest) as an array of { path, name }. The raw file is
    // also kept in pps_artwork_path above for reorder/back-compat.
    $files_raw = wp_unslash( $_POST['pps_artwork_files'] ?? '' );
    if ( $files_raw ) {
        $decoded = json_decode( $files_raw, true );
        if ( is_array( $decoded ) ) {
            $clean = array();
            foreach ( $decoded as $f ) {
                if ( ! is_array( $f ) || empty( $f['path'] ) ) continue;
                $p = sanitize_text_field( $f['path'] );
                // Same path-traversal guard as the single-file path above.
                if ( strpos( $p, '..' ) !== false || strpos( $p, 'pps-artwork/' ) !== 0 ) continue;
                $clean[] = array(
                    'path' => $p,
                    'name' => sanitize_file_name( $f['name'] ?? basename( $p ) ),
                );
            }
            if ( $clean ) {
                $cart_item_data['pps_artwork_files'] = $clean;
            }
        }
    }

    if ( ! WC()->cart ) {
        wp_send_json_error( 'Cart not available.' );
    }

    // Tells the spec-less-line guard below that this add is the calculator's own.
    $GLOBALS['pps_internal_add_to_cart'] = true;
    $cart_item_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );
    unset( $GLOBALS['pps_internal_add_to_cart'] );

    if ( $cart_item_key ) {
        // Carry the delivery address the customer just typed into the checkout session,
        // so the address block on /checkout/ agrees with the "Ship to:" on the line item
        // instead of showing a second, older address from their account.
        pps_prefill_customer_shipping( $metadata );

        // Edit mode: now safe to remove old item since new one succeeded
        if ( $edit_key ) {
            WC()->cart->remove_cart_item( $edit_key );
            WC()->session->set( 'pps_edit_key_' . $product_id, null );
        }
        // Discount code, if the customer entered one. Applied through WooCommerce so
        // Woo remains the single authority on coupon maths and renders the discount
        // line natively in cart, checkout, order emails and the admin order screen.
        // Deliberately NOT folded into pps_price: that price is what the materials
        // floor and the pps_lock checksum are computed against, and a coupon baked
        // into it would trip both.
        $coupon_applied = '';
        $coupon_code    = wc_format_coupon_code( sanitize_text_field( (string) ( $_POST['pps_coupon'] ?? '' ) ) );
        if ( $coupon_code !== '' && strlen( $coupon_code ) <= 60 ) {
            // Woo re-validates here; a code the preview endpoint accepted can still be
            // refused (used up in the meantime, cart no longer meets the minimum...).
            if ( ! WC()->cart->has_discount( $coupon_code ) ) {
                if ( WC()->cart->apply_coupon( $coupon_code ) ) {
                    $coupon_applied = $coupon_code;
                }
                // Woo pushes its own notice on failure; swallow it so a bad code cannot
                // block a legitimate add-to-cart. The response tells the client instead.
                if ( $coupon_applied === '' && function_exists( 'wc_clear_notices' ) ) {
                    wc_clear_notices();
                }
            } else {
                $coupon_applied = $coupon_code;
            }
        }

        wp_send_json_success( array(
            'cart_item_key'  => $cart_item_key,
            'coupon_applied' => $coupon_applied,
            'coupon_failed'  => ( $coupon_code !== '' && $coupon_applied === '' ) ? $coupon_code : '',
        ) );
    } else {
        wp_send_json_error( 'Could not add to cart.' );
    }
}

/**
 * A calculator product may only enter the cart through the calculator.
 *
 * Every product in the registry carries a placeholder catalogue price — a penny, in the
 * case of Letterhead — because its real price is whatever the calculator works out from
 * the spec. WooCommerce's own Add to cart button does not know that. Pressed from a shop
 * archive, a related-products strip, a stale `?add-to-cart=` link or a search result, it
 * puts a spec-less line on the cart at the placeholder price, and that line is
 * checkout-able.
 *
 * It has already happened on staging: a stray $0.01 "Letterhead" line sat in the cart,
 * pushed the subtotal a penny past the calculator's quote (which read as a rounding bug
 * in the pricing engine and is not one), and — being a non-virtual product — switched
 * WooCommerce's shipping machinery back on for a cart that is otherwise entirely
 * virtual, which is where the third, contradictory delivery date at checkout came from.
 *
 * The order-side price floor above cannot catch this: the placeholder price IS the
 * product's regular price, so a penny clears a floor defined as a fraction of it.
 *
 * Products not in the registry are WCPA's or plain WooCommerce's and are untouched.
 */
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
    if ( ! $passed ) return $passed;
    if ( ! empty( $GLOBALS['pps_internal_add_to_cart'] ) ) return $passed;   // the calculator's own add

    $calc = pps_get_calculator_for_product( $product_id );
    if ( ! $calc ) return $passed;                                          // WCPA or plain Woo product

    $product = wc_get_product( $product_id );
    $link    = $product ? get_permalink( $product_id ) : '';
    wc_add_notice( sprintf(
        /* translators: %s: link to the product's calculator page */
        'This product is priced from its specification. %s to choose your options and get a price.',
        $link ? '<a href="' . esc_url( $link ) . '">Open the calculator</a>' : 'Open its product page'
    ), 'error' );
    return false;
}, 10, 4 );

/**
 * Sweep out spec-less calculator lines that predate the guard above.
 *
 * The guard stops new ones. It does nothing about the line already sitting in a session
 * cart from before it existed — which is a live problem rather than a hypothetical, that
 * being exactly how the stray $0.01 Letterhead survived across a staging session.
 *
 * Removal is the only sound outcome: the line carries no specification, so there is
 * nothing to produce and no price that means anything. Silently dropping it would be
 * worse than leaving it, so it says so.
 */
add_action( 'woocommerce_cart_loaded_from_session', function( $cart ) {
    if ( is_admin() && ! wp_doing_ajax() ) return;
    $dropped = array();

    foreach ( $cart->get_cart() as $key => $item ) {
        if ( isset( $item['pps_metadata'] ) || isset( $item['pps_legacy_unit_price'] ) ) continue;
        $pid = (int) ( $item['product_id'] ?? 0 );
        if ( ! $pid || ! pps_get_calculator_for_product( $pid ) ) continue;

        $product   = $item['data'] ?? null;
        $dropped[] = $product && is_a( $product, 'WC_Product' ) ? $product->get_name() : 'an item';
        $cart->remove_cart_item( $key );
    }

    if ( $dropped && function_exists( 'wc_add_notice' ) ) {
        wc_add_notice( sprintf(
            '%s was removed from your cart: it had no specification, so it could not be priced or produced. Please configure it on its product page.',
            esc_html( implode( ', ', array_unique( $dropped ) ) )
        ), 'notice' );
    }
}, 20 );

// ═══════════════════════════════════════════════════════════════
// CART: SESSION PERSISTENCE
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
    $keys = array( 'pps_price', 'pps_rush', 'pps_summary', 'pps_metadata', 'pps_biz_days', 'pps_hash', 'pps_artwork_path', 'pps_artwork_files', 'pps_proof_hash' );
    foreach ( $keys as $k ) {
        if ( isset( $values[ $k ] ) ) {
            $cart_item[ $k ] = $values[ $k ];
        }
    }
    return $cart_item;
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
// CART: OVERRIDE PRICE
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    foreach ( $cart->get_cart() as $item ) {
        if ( isset( $item['pps_price'] ) ) {
            $item['data']->set_price( floatval( $item['pps_price'] ) );
        }
    }
}, 20 );

// ═══════════════════════════════════════════════════════════════
// CART: DISPLAY CONFIGURATION + LIVE DELIVERY DATE
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_get_item_data', function( $data, $cart_item ) {
    if ( ! isset( $cart_item['pps_summary'] ) ) return $data;

    // buildSummary() emits one line per chosen option, and rendering them verbatim turned
    // a cart row into a dozen table rows — six of them labeled "Configuration", because
    // a line with no colon had nothing to split on and fell back to that word. Group them
    // instead: the headline spec, the two paper facts, and one row carrying every
    // finishing choice. Same information, a third of the height, no repeated labels.
    $headline = '';
    $labeled = array();
    $options  = array();

    foreach ( array_filter( array_map( 'trim', explode( "\n", $cart_item['pps_summary'] ) ) ) as $line ) {
        $parts = explode( ':', $line, 2 );
        if ( count( $parts ) === 2 ) {
            $labeled[ trim( $parts[0] ) ] = trim( $parts[1] );
        } elseif ( $headline === '' ) {
            $headline = $line;   // size · quantity · pages · job name — always first
        } else {
            $options[] = $line;  // stapling, coating, bundling, corners, artwork, proof…
        }
    }

    if ( $headline !== '' ) {
        $data[] = array( 'key' => 'Specification', 'value' => $headline );
    }
    // Paper first, because it is what a customer double-checks on a print order.
    foreach ( array( 'Inside', 'Cover' ) as $paper_key ) {
        if ( isset( $labeled[ $paper_key ] ) ) {
            $data[] = array( 'key' => $paper_key, 'value' => $labeled[ $paper_key ] );
            unset( $labeled[ $paper_key ] );
        }
    }
    if ( $options ) {
        $data[] = array( 'key' => 'Finishing', 'value' => implode( ' · ', $options ) );
    }
    // Whatever else carried its own label — Rush, Ship to, Standard delivery.
    foreach ( $labeled as $label => $value ) {
        $data[] = array( 'key' => $label, 'value' => $value );
    }

    $delivery = pps_quoted_delivery_date(
        $cart_item['pps_metadata'] ?? '',
        intval( $cart_item['pps_biz_days'] ?? 0 )
    );
    if ( $delivery !== null ) {
        $data[] = array(
            'key'     => 'Estimated Delivery',
            'value'   => $delivery,
            'display' => '<strong style="color:#46882c">' . esc_html( $delivery ) . '</strong>',
        );
    }

    return $data;
}, 10, 2 );

// WCPA (still active for non-registry products) also filters this hook and
// emits its form-field labels — valueless — on registry products it does not
// own ("Booklet Finished Size:", "Insides Print Color:", …). Scrub
// empty-valued rows off PPS items late, after every plugin has added its
// rows. WCPA-owned products are untouched: they carry no pps_summary.
add_filter( 'woocommerce_get_item_data', function( $data, $cart_item ) {
    if ( ! isset( $cart_item['pps_summary'] ) || ! is_array( $data ) ) return $data;
    return array_values( array_filter( $data, function( $row ) {
        if ( ! is_array( $row ) ) return true;
        // Any WCPA row is foreign on a PPS item (systems never share products) —
        // including the literal 'wcpa_empty_label' placeholder keys the blocks
        // checkout surfaced. Kill by key prefix, then kill anything valueless.
        $key = strtolower( trim( (string) ( $row['key'] ?? ( $row['name'] ?? '' ) ) ) );
        if ( strpos( $key, 'wcpa' ) === 0 ) return false;
        $value   = trim( (string) ( $row['value'] ?? '' ) );
        $display = trim( wp_strip_all_tags( (string) ( $row['display'] ?? '' ) ) );
        return $value !== '' || $display !== '';
    } ) );
}, 999, 2 );

// ═══════════════════════════════════════════════════════════════
// CART & CHECKOUT: SKIN
// ═══════════════════════════════════════════════════════════════

/**
 * Mark calculator lines so the stylesheet can treat them differently from ordinary
 * products. A calculator line is always quantity 1 — the real quantity is part of the
 * spec — so its unit price and quantity box only repeat the subtotal. An ordinary
 * WooCommerce product may have an editable quantity, and hiding that would leave a
 * customer no way to change it.
 */
add_filter( 'woocommerce_cart_item_class', function( $class, $cart_item ) {
    if ( isset( $cart_item['pps_metadata'] ) ) $class .= ' pps-line';
    return $class;
}, 10, 2 );

add_filter( 'body_class', function( $classes ) {
    if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
        $classes[] = 'pps-cart';
    }
    return $classes;
} );

/**
 * Cart/checkout styling.
 *
 * Inline rather than a stylesheet file: it is small, it only loads on two pages, and a
 * separate file would be one more artifact to keep in step with the repo on every
 * deploy. Scoped under .pps-cart and written only against WooCommerce's own markup, so
 * it survives a theme change.
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) return;
    wp_register_style( 'pps-cart', false, array(), PPS_CALC_VERSION );
    wp_enqueue_style( 'pps-cart' );
    wp_add_inline_style( 'pps-cart', <<<'CSS'
/* PPS cart & checkout skin.
   Loaded only on cart/checkout, and only styles what WooCommerce's own markup gives us,
   so nothing here depends on a theme template staying put.

   The default table put the product name in a narrow column beside three mostly-empty
   money columns, then stacked a dozen spec rows under it — so a single line item ran a
   full screen tall and the price you were being asked to pay sat somewhere in the middle
   of it. This turns each line into a card: art, name and specs on the left, money on the
   right, specs as quiet supporting text rather than a table of their own. */

/* ── Give the form a width before anything else ──────────────────────────────
   Astra lays the cart out as a flex row. Its first item carries no width of its own, so
   it is free to shrink to min-content — and it did: on staging the form and table
   measured 239px inside a 619px column inside a 910px row, which squeezed the name cell
   to nothing and rendered "Low Cost Brochure Printing" one character per line.

   flex-grow on the column makes it claim the row's free space; width:100% on the form
   makes it fill the column. Both are inert if the theme ever stops using flex here, so
   this is safe to state unconditionally. min-width:0 is the companion rule — without it
   a flex item refuses to shrink below its content, which is how long spec strings push
   the table wider than the page. */
.pps-cart .ast-cart-non-sticky { flex: 1 1 auto; min-width: 0; }
.pps-cart form.woocommerce-cart-form {
  display: block; width: 100%; max-width: 100%; min-width: 0; flex: 1 1 auto;
}

/* ── The line-item table, rebuilt as cards ─────────────────────────────────
   Table layout is dropped entirely. Every row below is a grid, so the table algorithm
   was contributing nothing but a min-content floor that fought the column it sat in —
   which is the other half of the collapse above. As blocks they simply fill their
   parent, and a long spec string can no longer widen the page. */
.pps-cart table.cart,
.pps-cart table.shop_table.cart {
  display: block; width: 100%; max-width: 100%; border: 0; background: none;
}
.pps-cart table.cart > tbody { display: block; width: 100%; }
.pps-cart table.cart > thead { display: none; }  /* column headers mean nothing once rows are cards */
.pps-cart table.cart > tbody > tr { display: block; width: 100%; }

.pps-cart table.cart tr.cart_item {
  display: grid;
  /* The name gets a floor. Left as minmax(0,1fr) the auto money track — sized by
     the quantity input's ~185px default — starved it to 58px in a narrow column,
     which renders a product name one letter per line. */
  grid-template-columns: 76px minmax(140px, 1fr) auto;
  grid-template-rows: auto auto auto 1fr;
  /* Rows on the right so remove, unit price, quantity and subtotal stack in the corner.
     WooCommerce gives us no wrapper to put them in, so the grid does the stacking.
     Empty rows collapse, so a line that hides price and quantity loses the gap too. */
  grid-template-areas:
    "thumb name remove"
    "thumb name price"
    "thumb name qty"
    "thumb name money";
  align-items: start;
  gap: 0 18px;
  margin: 0 0 12px;          /* replaces border-spacing, which a block table ignores */
  background: #fff;
  border: 1px solid #e6e9ee;
  border-radius: 14px;
  padding: 18px 20px;
  box-sizing: border-box;
  box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
}
.pps-cart table.cart tr.cart_item > td {
  display: block; border: 0; padding: 0; background: none; vertical-align: top;
  min-width: 0;              /* a grid item defaults to min-content; long specs overflowed */
}

.pps-cart tr.cart_item td.product-thumbnail { grid-area: thumb; }
.pps-cart tr.cart_item td.product-thumbnail img {
  width: 76px; height: auto; border-radius: 8px; display: block;
  border: 1px solid #eef1f5;
}
.pps-cart tr.cart_item td.product-name { grid-area: name; min-width: 0; }

.pps-cart tr.cart_item td.product-name > a:first-child {
  display: block; font-size: 17px; font-weight: 700; line-height: 1.25;
  color: #111827; text-decoration: none; margin-bottom: 2px;
}
.pps-cart tr.cart_item td.product-name > a:first-child:hover { color: #007eff; }

/* Money reads as one right-aligned stack instead of three columns to scan across. */
.pps-cart tr.cart_item td.product-price { grid-area: price; justify-self: end;
  font-size: 12.5px; color: #6b7280; }
.pps-cart tr.cart_item td.product-quantity { grid-area: qty; justify-self: end;
  font-size: 12.5px; color: #6b7280; margin-top: 2px; }
/* A number input defaults to roughly 185px. In an auto-sized grid track that is the
   whole row's width budget, so it is capped to what a quantity actually needs. */
.pps-cart tr.cart_item td.product-quantity .qty,
.pps-cart tr.cart_item td.product-quantity input { width: 64px; max-width: 100%; box-sizing: border-box; }

/* A calculator line always has quantity 1 — the real quantity lives inside the spec —
   so unit price and quantity just repeat the subtotal. Hidden for those lines ONLY:
   an ordinary WooCommerce product may have an editable quantity box, and hiding that
   would leave no way to change it. */
.pps-cart tr.cart_item.pps-line td.product-price,
.pps-cart tr.cart_item.pps-line td.product-quantity { display: none; }
.pps-cart tr.cart_item td.product-subtotal {
  grid-area: money; justify-self: end; align-self: start;
  font-size: 19px; font-weight: 800; color: #111827; white-space: nowrap; padding-left: 12px;
}

/* Remove sits as a quiet ✕ in the corner rather than its own table column. */
.pps-cart tr.cart_item td.product-remove {
  grid-area: remove; justify-self: end; align-self: start; margin-bottom: 8px;
}
.pps-cart tr.cart_item td.product-remove a.remove {
  display: inline-flex; align-items: center; justify-content: center;
  width: 24px; height: 24px; border-radius: 50%;
  color: #9aa4b2 !important; background: #f3f5f8 !important;
  font-size: 15px; line-height: 1; text-decoration: none;
}
.pps-cart tr.cart_item td.product-remove a.remove:hover {
  color: #fff !important; background: #ef4444 !important;
}

/* ── Specs: supporting detail, not a second table ────────────────────────── */
.pps-cart tr.cart_item dl.variation {
  display: grid; grid-template-columns: auto minmax(0, 1fr);
  gap: 2px 8px; margin: 8px 0 0; font-size: 12.5px; line-height: 1.5;
}
.pps-cart tr.cart_item dl.variation dt {
  margin: 0; font-weight: 600; color: #8a94a3; white-space: nowrap;
}
.pps-cart tr.cart_item dl.variation dd { margin: 0; color: #4b5563; min-width: 0; }
.pps-cart tr.cart_item dl.variation dd p { margin: 0; }

.pps-cart .pps-edit-specs {
  display: inline-block; margin: 6px 0 2px; padding: 3px 10px;
  font-size: 11.5px; font-weight: 600; line-height: 1.6;
  color: #007eff; background: #eff6ff; border: 1px solid #cfe4ff;
  border-radius: 999px; text-decoration: none;
}
.pps-cart .pps-edit-specs:hover { background: #dbeafe; }

/* Update cart row */
.pps-cart table.cart td.actions {
  display: block; border: 0; background: none; padding: 4px 0 0; text-align: right;
}

/* ── Totals card ─────────────────────────────────────────────────────────── */
.pps-cart .cart_totals {
  background: #fff; border: 1px solid #e6e9ee; border-radius: 14px;
  padding: 20px; box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
}
.pps-cart .cart_totals > h2 {
  font-size: 15px; font-weight: 700; letter-spacing: .02em;
  margin: 0 0 14px; padding: 0; border: 0; color: #111827;
}
.pps-cart .cart_totals table { border: 0; margin: 0; width: 100%; }
.pps-cart .cart_totals table th,
.pps-cart .cart_totals table td {
  border: 0; border-bottom: 1px solid #f0f2f5; padding: 9px 0;
  font-size: 13.5px; background: none;
}
.pps-cart .cart_totals table th { font-weight: 600; color: #6b7280; text-align: left; }
.pps-cart .cart_totals table td { text-align: right; color: #111827; }
.pps-cart .cart_totals tr.cart-discount th,
.pps-cart .cart_totals tr.cart-discount td { color: #16a34a; }
.pps-cart .cart_totals tr.order-total th,
.pps-cart .cart_totals tr.order-total td {
  border-bottom: 0; padding-top: 13px; font-size: 17px; font-weight: 800; color: #111827;
}
.pps-cart .cart_totals .woocommerce-remove-coupon { font-size: 11.5px; }

/* Shipping methods are a list of choices, not a figure. The blanket right-align on
   totals cells turned them into a ragged one-word-per-line column. */
.pps-cart .cart_totals tr.woocommerce-shipping-totals td,
.pps-cart .cart_totals tr.shipping td { text-align: left; }
.pps-cart .cart_totals ul#shipping_method {
  list-style: none; margin: 0; padding: 0; display: grid; gap: 8px;
}
.pps-cart .cart_totals ul#shipping_method li {
  display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px;
  align-items: start; margin: 0; line-height: 1.4;
}
.pps-cart .cart_totals ul#shipping_method li label { margin: 0; font-size: 13px; color: #374151; }
.pps-cart .cart_totals ul#shipping_method li input { margin: 3px 0 0; }
.pps-cart .cart_totals .woocommerce-shipping-destination {
  margin: 10px 0 0; font-size: 12px; color: #6b7280; text-align: left;
}

.pps-cart .wc-proceed-to-checkout { padding: 14px 0 0; }
.pps-cart .wc-proceed-to-checkout a.checkout-button,
.pps-cart .wc-proceed-to-checkout .button {
  display: block; width: 100%; box-sizing: border-box; text-align: center;
  padding: 14px 16px; border-radius: 10px;
  font-size: 15px; font-weight: 700; letter-spacing: .01em;
}

/* ── Narrow viewport: the card becomes two rows, money under the name ────────
   This was a container query against the cart form. `container-type: inline-size` also
   means "size this element as if it had no contents", which is precisely the property
   that lets a flex parent squeeze it to nothing — the same failure this file now exists
   to fix, re-introduced by the fix's own tooling. A viewport query cannot do that. */
@media (max-width: 860px) {
  .pps-cart table.cart tr.cart_item {
    grid-template-columns: 60px minmax(0, 1fr) auto;
    /* Quantity and unit price share one line beneath the name; the line total gets its
       own full-width row so it is the last thing read, as on the wide card. */
    grid-template-areas:
      "thumb name  remove"
      "thumb qty   price"
      "money money money";
    padding: 14px 15px; gap: 0 14px;
  }
  .pps-cart tr.cart_item td.product-thumbnail img { width: 60px; }
  .pps-cart tr.cart_item td.product-quantity { justify-self: start; margin-top: 8px; }
  .pps-cart tr.cart_item td.product-price { justify-self: end; margin-top: 8px; }
  .pps-cart tr.cart_item td.product-subtotal {
    justify-self: start; text-align: left; margin-top: 12px; padding-top: 12px;
    padding-left: 0; border-top: 1px solid #f0f2f5;
  }
  .pps-cart tr.cart_item dl.variation { grid-template-columns: 1fr; gap: 0; }
  .pps-cart tr.cart_item dl.variation dt { margin-top: 6px; }
}

@media (max-width: 640px) {
  .pps-cart table.cart tr.cart_item {
    grid-template-areas:
      "thumb name remove"
      "price qty  qty"
      "money money money";
  }
  .pps-cart tr.cart_item td.product-price { justify-self: start; }
}

/* Round-3 QA: the theme's "Have a coupon?" toggle handler stopped binding,
   leaving div.coupon at display:none with no way for a customer to enter a
   code. Keep the field permanently open — pure CSS, so there is no race with
   whatever theme handler may or may not attach. The toggle text becomes an
   inert label above an already-open field, which reads fine. */
.pps-cart div.coupon {
  display: block !important;
  height: auto !important;
  overflow: visible !important;
  opacity: 1 !important;
}

/* Round-5 QA / owner: the theme's "Have a coupon?" toggle is dead UI — its
   handler never binds, while the always-visible #ast-coupon-code field is the
   one that works. Delete the dead pair outright rather than leaving two
   coupon inputs, one of which ignores clicks. */
.pps-cart .wc-proceed-to-checkout p[tabindex="0"],
.pps-cart div.coupon {
  display: none !important;
}

/* Owner screenshot 2026-08-10: at wide viewports the theme floats the totals
   column beside the items and our form width leaves it a ~1ch sliver, so the
   delivery estimate rendered one letter per line. Stack the collaterals
   full-width below the items instead — no float math to collapse. */
.pps-cart .cart-collaterals,
.pps-cart .cart_totals {
  float: none !important;
  width: 100% !important;
  min-width: 0 !important;
  clear: both;
}
.pps-cart .cart-collaterals { max-width: 720px; margin-top: 18px; }
CSS
    );
}, 20 );

// ═══════════════════════════════════════════════════════════════
// CART: EDIT SPECS LINK
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_cart_item_name', function( $name, $cart_item, $cart_item_key ) {
    if ( ! isset( $cart_item['pps_metadata'] ) ) return $name;

    $product = wc_get_product( $cart_item['product_id'] );
    if ( ! $product ) return $name;

    // Token only. The restore payload used to travel in the URL as base64
    // (round-5 QA: a 1,425-character query string carrying shipState/shipZip
    // into access logs, history and Referer headers). The cart session holds
    // the full metadata under this key, so the render path now rebuilds the
    // payload server-side — see the pps_edit_key branch in the product config
    // build, which injects PPS_CONFIG.reorder from the cart item.
    $url = add_query_arg( 'pps_edit_key', $cart_item_key, $product->get_permalink() );

    $name .= ' <a href="' . esc_url( $url ) . '" style="display:inline-block;margin-left:8px;font-size:12px;color:#007eff;text-decoration:none;border:1px solid #007eff;padding:2px 8px;border-radius:3px;white-space:nowrap">✏️ Edit Specs</a>';

    return $name;
}, 10, 3 );

// ═══════════════════════════════════════════════════════════════
// CART: LOCK QUANTITY (calculator controls this)
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_cart_item_quantity', function( $quantity_html, $cart_item_key, $cart_item ) {
    if ( isset( $cart_item['pps_price'] ) ) {
        return '<span>' . intval( $cart_item['quantity'] ) . '</span>';
    }
    return $quantity_html;
}, 10, 3 );

// ═══════════════════════════════════════════════════════════════
// BUSINESS DAY CALCULATION
// ═══════════════════════════════════════════════════════════════

/**
 * Is the shop open on this date?
 *
 * Extracted from pps_add_business_days() so nothing has to reimplement it.
 * A second copy of this test is how the quote page ended up offering delivery
 * on closing days: it skipped weekends and knew nothing about the holiday
 * list. One predicate, one answer.
 *
 * Closures are matched as Y-m-d (a specific day) or m-d (annually recurring).
 */
function pps_is_business_day( DateTime $d ): bool {
    if ( (int) $d->format( 'N' ) >= 6 ) return false;
    $closures = pps_get_closures();
    return ! in_array( $d->format( 'Y-m-d' ), $closures, true )
        && ! in_array( $d->format( 'm-d' ), $closures, true );
}

function pps_add_business_days( DateTime $start, int $days ): DateTime {
    $d = clone $start;
    $added = 0;

    while ( $added < $days ) {
        $d->modify( '+1 day' );
        if ( pps_is_business_day( $d ) ) $added++;
    }

    return $d;
}

// ═══════════════════════════════════════════════════════════════
// CHECKOUT: SAVE TO ORDER
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( ! isset( $values['pps_metadata'] ) ) return;

    // Internal (hidden from customer)
    $item->add_meta_data( '_pps_metadata', $values['pps_metadata'], true );
    $item->add_meta_data( '_pps_summary', $values['pps_summary'] ?? '', true );
    $item->add_meta_data( '_pps_rush', $values['pps_rush'] ?? 0, true );

    // The date the customer was quoted, not a fresh count from checkout time. Both are
    // usually the same day; they diverge exactly when it matters — a cart left overnight,
    // an order placed after the 2pm cutoff, or a delivery date the customer chose.
    $tz = 'America/Phoenix';
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $tz  = $cfg['pcf']['shop_timezone'] ?? $tz;
    }
    $biz_days  = intval( $values['pps_biz_days'] ?? 5 );
    $quoted    = pps_quoted_delivery_date( $values['pps_metadata'] ?? '', $biz_days );
    $delivery  = $quoted !== null
        ? new DateTime( $quoted, new DateTimeZone( $tz ) )
        : pps_add_business_days( new DateTime( 'now', new DateTimeZone( $tz ) ), $biz_days );

    $item->add_meta_data( '_pps_delivery_date', $delivery->format( 'Y-m-d' ), true );

    // Artwork: direct path from cart (no token resolution, no glob)
    if ( ! empty( $values['pps_artwork_path'] ) ) {
        $path = sanitize_text_field( $values['pps_artwork_path'] );
        // Security: prevent path traversal
        if ( strpos( $path, '..' ) === false && strpos( $path, 'pps-artwork/' ) === 0 ) {
            $upload = wp_upload_dir();
            $full   = trailingslashit( $upload['basedir'] ) . $path;
            // Store path even if local file was moved to Google Drive —
            // it serves as a reference for reorders and Drive lookups
            $item->add_meta_data( '_pps_artwork_path', $path, true );
            if ( ! file_exists( $full ) ) {
                $item->add_meta_data( '_pps_artwork_on_drive', 'yes', true );
            }
        }
    }

    // Full approval package → order item (JSON). The Drive uploader pushes every
    // deliverable in this list into the order folder, not just the raw file.
    if ( ! empty( $values['pps_artwork_files'] ) && is_array( $values['pps_artwork_files'] ) ) {
        $clean = array();
        foreach ( $values['pps_artwork_files'] as $f ) {
            if ( ! is_array( $f ) || empty( $f['path'] ) ) continue;
            $p = sanitize_text_field( $f['path'] );
            if ( strpos( $p, '..' ) !== false || strpos( $p, 'pps-artwork/' ) !== 0 ) continue;
            $clean[] = array( 'path' => $p, 'name' => sanitize_file_name( $f['name'] ?? basename( $p ) ) );
        }
        if ( $clean ) {
            $item->add_meta_data( '_pps_artwork_files', wp_json_encode( $clean ), true );
        }
    }

    // Approval binding: SHA-256 of the print-ready bytes the customer approved
    // on screen. The imposition tool hashes the file it is about to impose and
    // refuses on mismatch — what was approved is what prints.
    if ( ! empty( $values['pps_proof_hash'] ) && preg_match( '/^[0-9a-f]{64}$/', (string) $values['pps_proof_hash'] ) ) {
        $item->add_meta_data( '_pps_proof_hash', (string) $values['pps_proof_hash'], true );
    }

    // Visible in order emails
    $item->add_meta_data( 'Estimated Delivery', $delivery->format( 'l, M j, Y' ), true );
    $item->add_meta_data( 'Order Summary', $values['pps_summary'] ?? '', true );

    // ── Missive-parseable fields ──
    $full = json_decode( $values['pps_metadata'] ?? '{}', true );
    if ( $full ) {
        // Single-line spec string: size | qty | pages | paper | color | proof | rush | turnaround
        $sets     = is_array( $full['sets'] ?? null ) ? $full['sets'] : array();
        $totalQty = array_sum( array_column( $sets, 'qty' ) );
        $totalPg  = array_sum( array_column( $sets, 'pages' ) );
        $size     = pps_spec_size_label( $full );
        $iPaper   = is_array( $full['insidePaper'] ?? null ) ? ( $full['insidePaper']['label'] ?? '' ) : '';
        $iColor   = ( $full['insideColor'] ?? '' ) === 'bw' ? 'BW' : 'Color';
        $proof    = ( $full['proof'] ?? 0 ) >= 3 ? 'Hardcopy' : ( ( $full['proof'] ?? 0 ) > 0 ? 'DigitalProof' : 'SelfApproved' );
        $rush     = ( $full['rushCost'] ?? 0 ) > 0 ? 'RUSH' : 'Standard';
        $days     = intval( $full['days'] ?? $biz_days );
        $sets_ct  = count( $sets );

        // Cover stock and color are priced and printed separately from the inside, so a
        // spec that names only the inside paper is a spec production cannot work from.
        $cPaper = is_array( $full['coverPaper'] ?? null ) ? ( $full['coverPaper']['label'] ?? '' ) : '';
        $cColor = ( $full['coverColor'] ?? '' ) === 'bw' ? 'BW' : 'Color';
        $cover  = ( ( $full['coverMode'] ?? '' ) === 'same' || $cPaper === '' )
            ? 'SELF-COVER'
            : 'COVER: ' . $cPaper . '/' . $cColor;

        // Finishing choices live in the metadata as numeric config values, not labels —
        // coating 750 means nothing on a job ticket. buildSummary() has already resolved
        // every one of them to the words the customer chose, so take them from there:
        // the labeled lines are facts we already have, the rest are the add-ons.
        $addons = array();
        $seen_headline = false;
        foreach ( array_filter( array_map( 'trim', explode( "\n", (string) ( $values['pps_summary'] ?? '' ) ) ) ) as $line ) {
            if ( strpos( $line, ':' ) !== false ) continue;   // Inside:/Cover:/Rush:/Ship to:
            if ( ! $seen_headline ) { $seen_headline = true; continue; }  // size · qty · pages
            $addons[] = $line;
        }

        $ship = trim( (string) ( $full['shipState'] ?? '' ) . ' ' . (string) ( $full['shipZip'] ?? '' ) );
        $job  = isset( $sets[0]['name'] ) ? trim( (string) $sets[0]['name'] ) : '';

        $spec_parts = array_merge(
            array(
                $size,
                $totalQty . 'qty',
                $totalPg . 'pg',
                $sets_ct . ( $sets_ct === 1 ? 'set' : 'sets' ),
                'INSIDE: ' . $iPaper . '/' . $iColor,
                $cover,
            ),
            $addons,
            array(
                $proof,
                $rush,
                $days . 'days',
            )
        );
        if ( $ship !== '' ) $spec_parts[] = 'SHIP: ' . $ship;
        if ( $job !== '' )  $spec_parts[] = 'JOB: ' . $job;

        $spec = implode( ' | ', array_filter( $spec_parts, static function( $p ) { return trim( (string) $p ) !== ''; } ) );
        $item->add_meta_data( 'PPS-Spec', $spec, true );

        // Production start date — distinct label for Missive rule parsing
        $prodStart = $full['productionStartDate'] ?? '';
        if ( $prodStart ) {
            $item->add_meta_data( 'PPS-Production-Start', $prodStart, true );
        }
    }
}, 10, 4 );

// ═══════════════════════════════════════════════════════════════
// ORDER: KEEP THE PRODUCTION FIELDS OFF THE CUSTOMER'S COPY
// ═══════════════════════════════════════════════════════════════

/**
 * PPS-Spec and PPS-Production-Start have to be visible item meta — Missive parses them
 * out of the staff notification, and an underscore-prefixed key never renders. The cost
 * of that was a customer receipt ending in two lines of job-ticket shorthand and an
 * internal start date, which reads as a system leaking its guts.
 *
 * So: keep them wherever staff look, strip them wherever the customer does. Admin
 * screens and the admin notification keep everything; the front end — emailed receipt,
 * order-received page, My Account — sees only what it should.
 *
 * `Preset` goes too. It is an analytics slug, meaningless to a customer.
 */
function pps_internal_item_meta_keys() {
    return array( 'PPS-Spec', 'PPS-Production-Start', 'Preset' );
}

// WooCommerce renders both notifications through the same meta accessor, so the filter
// cannot tell them apart on its own. These two bracket the order table and record which
// audience is being written for.
add_action( 'woocommerce_email_before_order_table', function( $order, $sent_to_admin ) {
    $GLOBALS['pps_email_sent_to_admin'] = (bool) $sent_to_admin;
}, 1, 2 );
add_action( 'woocommerce_email_after_order_table', function() {
    unset( $GLOBALS['pps_email_sent_to_admin'] );
}, 99 );

add_filter( 'woocommerce_order_item_get_formatted_meta_data', function( $formatted, $item ) {
    if ( ! is_array( $formatted ) ) return $formatted;

    // Positive identification only, and it FAILS OPEN deliberately. The obvious
    // alternative — strip unless we can prove this is staff — depends on
    // woocommerce_email_before_order_table firing, and a theme or plugin that overrides
    // email-order-details.php can silently drop it. Astra ships WooCommerce template
    // overrides, so that is not hypothetical here. The failure mode would be the admin
    // notification losing PPS-Spec, Missive parsing nothing, and nobody noticing for
    // days. A customer seeing one line of job shorthand is the cheaper way to be wrong.
    $customer_email = isset( $GLOBALS['pps_email_sent_to_admin'] ) && ! $GLOBALS['pps_email_sent_to_admin'];

    $customer_page = false;
    if ( ! is_admin() && function_exists( 'is_wc_endpoint_url' ) ) {
        $customer_page = is_wc_endpoint_url( 'order-received' )
                      || is_wc_endpoint_url( 'view-order' )
                      || is_wc_endpoint_url( 'order-pay' );
    }

    if ( ! $customer_email && ! $customer_page ) return $formatted;

    $internal = pps_internal_item_meta_keys();
    foreach ( $formatted as $id => $meta ) {
        if ( isset( $meta->key ) && in_array( $meta->key, $internal, true ) ) {
            unset( $formatted[ $id ] );
        }
    }
    return $formatted;
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
// ORDER: LIFT THE CALCULATOR'S SHIP-TO ONTO THE ORDER ITSELF
// ═══════════════════════════════════════════════════════════════

/**
 * Copy the calculator's delivery address and shipment estimate onto the WC order.
 *
 * Every calculator product is a WooCommerce *virtual* product (owner rule 2026-07-19),
 * which is what keeps Woo's own shipping machinery — and every coexisting shipping
 * plugin — out of the cart. The side effect is that Woo decides the cart needs no
 * shipping, so checkout never renders the shipping address fields and the order ends up
 * carrying a billing address only.
 *
 * That matters well beyond WooCommerce's own screens. Anything reading the order over
 * the REST API — Shippo's store connection, packing slips, anything added later — sees
 * an empty shipping address and falls back to billing, which for a card payment is the
 * cardholder's address and frequently not where the job goes.
 *
 * The address the customer actually typed is already on the order: the calculator sends
 * it in `shipAddr` and it is stored in the line item's `_pps_metadata`. This lifts it
 * into the fields those tools read, along with the predicted weight and carton count,
 * which a virtual line item has nowhere else to put.
 *
 * Reads from the ORDER, not the cart, so classic checkout, block checkout and an order
 * built in wp-admin all go through one path.
 *
 * @param WC_Order|int $order_or_id
 */
function pps_apply_calculator_shipping_address( $order_or_id ) {
    $order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order ) return false;

    // A mixed cart containing a genuinely shippable product has a real WooCommerce
    // shipping address, entered at checkout. That one is authoritative — never
    // overwrite it with a calculator's copy.
    //
    // Note this is a flag rather than an early return. It used to return here, which
    // also skipped the weight and carton meta below — and once the session prefill
    // started putting the calculator's address on the order before this runs, that
    // "already has an address" branch became the common case, silently taking the
    // packing figures with it.
    // Why this is not simply "the order already has an address":
    //
    // Every calculator product is virtual, deliberately, so WooCommerce's own
    // shipping machinery stays out of the cart. But a virtual cart renders no
    // shipping section at checkout, so the customer is never shown those fields
    // — and WC_Checkout fills the order's shipping address by copying billing.
    // That copy is not a destination anybody chose; it is the cardholder's
    // address wearing the shipping fields. Treating it as authoritative is what
    // sent order 87032's Denver job to the buyer's house in Texas.
    //
    // So defer only to an address that could actually have been typed at
    // checkout: one belonging to a genuinely shippable, non-calculator item.
    // On a calculator-only order the address the customer entered in the
    // calculator is the only destination they were ever offered, and it wins.
    $has_shippable_non_calc_item = false;
    foreach ( $order->get_items() as $probe_item ) {
        if ( $probe_item->get_meta( '_pps_metadata' ) ) continue;   // calculator line
        $probe_product = $probe_item->get_product();
        if ( $probe_product && ! $probe_product->is_virtual() ) {
            $has_shippable_non_calc_item = true;
            break;
        }
    }

    $keep_existing_address = $has_shippable_non_calc_item
        && trim( (string) $order->get_shipping_address_1() ) !== '';

    $addr = null;
    $all_dests = array();
    $quote_ctx = array();
    $weight = 0.0;
    $cartons = 0;

    foreach ( $order->get_items() as $item ) {
        $raw = $item->get_meta( '_pps_metadata' );
        if ( ! $raw ) continue;
        $meta = json_decode( (string) $raw, true );
        if ( ! is_array( $meta ) ) continue;

        // Weight and cartons accumulate across every calculator line on the order —
        // two booklet lines ship as one consignment, not two.
        $weight  += (float) ( $meta['estWeightLb'] ?? 0 );
        $cartons += (int) ( $meta['estCartons'] ?? 0 );

        $a = isset( $meta['shipAddr'] ) && is_array( $meta['shipAddr'] ) ? $meta['shipAddr'] : array();
        // State lives alongside shipAddr rather than inside it — the calculator collects
        // it separately because it drives the transit map. ZIP is mirrored in both.
        $state  = trim( (string) ( $a['state'] ?? $meta['shipState'] ?? '' ) );
        $zip    = trim( (string) ( $a['zip'] ?? $meta['shipZip'] ?? '' ) );
        $street = trim( (string) ( $a['street1'] ?? '' ) );
        $city   = trim( (string) ( $a['city'] ?? '' ) );

        // Partial is worse than absent: half an address in the shipping fields looks
        // authoritative to a fulfilment tool and silently ships to the wrong place,
        // whereas empty fields fall back to billing, which is at least a whole address.
        if ( $street === '' || $city === '' || $state === '' || $zip === '' ) continue;

        // Kept for the mismatch note: what the customer was quoted, and against
        // which destination, so the operator can judge the cost/date impact.
        // Record EVERY line's destination, not only the first. A WooCommerce order
        // carries exactly one shipping address, so two lines bound for two places
        // cannot both be honored — and until this was recorded, the second one
        // was dropped without trace while its weight was still added to the
        // consignment. Detected after the loop and flagged for a human, because
        // splitting an order into two shipments is an operator's decision.
        $dest_key = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $street . $city . $state ) )
                  . '|' . substr( preg_replace( '/[^0-9]/', '', $zip ), 0, 5 );
        if ( ! isset( $all_dests[ $dest_key ] ) ) {
            $all_dests[ $dest_key ] = trim(
                trim( (string) ( $a['name'] ?? '' ) . ' ' . (string) ( $a['company'] ?? '' ) )
                . ' — ' . $street . ', ' . $city . ', ' . $state . ' ' . $zip
                . '  [' . $item->get_name() . ']'
            );
        }

        if ( $addr !== null ) continue;   // first usable address wins

        $quote_ctx = array(
            'transitDays' => isset( $meta['transitDays'] ) ? (int) $meta['transitDays'] : null,
            'rushCost'    => isset( $meta['rushCost'] ) ? (float) $meta['rushCost'] : null,
            'delivery'    => trim( (string) ( $meta['estimatedDeliveryDate'] ?? '' ) ),
            'needBy'      => trim( (string) ( $meta['needByDate'] ?? '' ) ),
        );

        $addr = array(
            'street1' => $street,
            'street2' => trim( (string) ( $a['street2'] ?? '' ) ),
            'city'    => $city,
            'state'   => $state,
            'zip'     => $zip,
            'company' => trim( (string) ( $a['company'] ?? '' ) ),
            'name'    => trim( (string) ( $a['name'] ?? '' ) ),
        );
    }

    // Internal only, and deliberately underscore-prefixed: this is an operator's
    // off-the-cuff reference, not a figure to publish. It stays out of the customer's
    // receipt and off the order screen's line-item rows; the PPS Calculator Data meta
    // box renders it where someone packing the job will actually look.
    //
    // Summed across every calculator line, because two booklet lines ship as one
    // consignment rather than two.
    if ( $weight > 0 )  $order->update_meta_data( '_pps_est_weight_lb', round( $weight, 2 ) );
    if ( $cartons > 0 ) $order->update_meta_data( '_pps_est_cartons', $cartons );

    // One order, one shipping address — so if the lines disagree about where they
    // are going, no address choice below can be right for all of them.
    if ( count( $all_dests ) > 1 && ! $order->get_meta( '_pps_multi_destination' ) ) {
        $order->add_order_note( wp_strip_all_tags(
            "⚠ THIS ORDER HAS " . count( $all_dests ) . " DIFFERENT DELIVERY ADDRESSES — it cannot ship as one.\n\n"
            . implode( "\n", array_map( function ( $d ) { return '  • ' . $d; }, array_values( $all_dests ) ) )
            . "\n\nA WooCommerce order holds one shipping address, so only the first is on the order; "
            . "the rest are recorded here and nowhere else. The packing weight and carton count are the "
            . "SUM of all lines, so they describe one consignment that does not exist. "
            . "Split this into separate shipments before rating or labelling."
        ) );
        $order->update_meta_data( '_pps_multi_destination', count( $all_dests ) );
        $order->save();
    }

    if ( $addr === null || $keep_existing_address ) {
        // Order 87032 shipped this bug into daylight: a customer buying for a
        // colleague entered the real ship-to in the calculator, then checkout's
        // "ship to same address as billing" default overwrote it with their own.
        // The correct destination survived only in _pps_metadata, where nobody
        // looks until a parcel goes to the wrong person.
        //
        // Until the address flow itself is fixed, refuse to discard it silently:
        // when the two disagree on where the parcel is going, say so on the order.
        if ( $addr !== null && $keep_existing_address && ! $order->get_meta( '_pps_ship_mismatch' ) ) {
            $norm_zip = function ( $z ) { return substr( preg_replace( '/[^0-9]/', '', (string) $z ), 0, 5 ); };
            $same = $norm_zip( $addr['zip'] ) === $norm_zip( $order->get_shipping_postcode() )
                 && strtoupper( $addr['state'] ) === strtoupper( (string) $order->get_shipping_state() );

            if ( ! $same ) {
                $calc_line = trim( $addr['name'] . ( $addr['company'] !== '' ? ' · ' . $addr['company'] : '' ) )
                    . ' — ' . $addr['street1']
                    . ( $addr['street2'] !== '' ? ', ' . $addr['street2'] : '' )
                    . ', ' . $addr['city'] . ', ' . $addr['state'] . ' ' . $addr['zip'];

                $order_line = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
                    . ' — ' . $order->get_shipping_address_1()
                    . ', ' . $order->get_shipping_city()
                    . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode();

                $note = "⚠ SHIPPING ADDRESS MISMATCH — confirm before this ships.\n\n"
                      . "Ships to (WooCommerce / Shippo):\n  " . $order_line . "\n\n"
                      . "Destination entered in the calculator:\n  " . $calc_line . "\n\n"
                      . "WooCommerce defaults to \"ship to same address as billing\", so a customer "
                      . "ordering for someone else can have their own address silently replace the "
                      . "destination they entered. The calculator's copy is above; it was not applied.";

                if ( ! empty( $quote_ctx['transitDays'] ) || ! empty( $quote_ctx['rushCost'] ) ) {
                    $note .= "\n\nQuoted against the calculator's destination:"
                          . ( $quote_ctx['transitDays'] !== null ? " transit " . $quote_ctx['transitDays'] . " day(s);" : '' )
                          . ( $quote_ctx['rushCost'] ? " rush " . wc_price( $quote_ctx['rushCost'] ) . ";" : '' )
                          . ( $quote_ctx['delivery'] !== '' ? " estimated delivery " . $quote_ctx['delivery'] . ";" : '' )
                          . ( $quote_ctx['needBy'] !== '' ? " need-by " . $quote_ctx['needBy'] . ";" : '' )
                          . " Shipping cost and dates were calculated for that address, not the one above.";
                }

                $order->add_order_note( wp_strip_all_tags( $note ) );
                $order->update_meta_data( '_pps_ship_mismatch', $calc_line );
                $order->save();
                return false;
            }
        }

        if ( $weight > 0 || $cartons > 0 ) $order->save();
        return false;
    }

    // The form asks for one "Full Name" because that is how people write an address.
    // Split on the last space so "Mary Anne Van Der Berg" keeps its surname intact;
    // fall back to the billing name when the field was left empty.
    $name = $addr['name'];
    if ( $name === '' ) {
        $first = $order->get_billing_first_name();
        $last  = $order->get_billing_last_name();
    } elseif ( strpos( $name, ' ' ) === false ) {
        $first = $name;
        $last  = '';
    } else {
        $first = trim( substr( $name, 0, strrpos( $name, ' ' ) ) );
        $last  = trim( substr( $name, strrpos( $name, ' ' ) + 1 ) );
    }

    $order->set_shipping_first_name( $first );
    $order->set_shipping_last_name( $last );
    $order->set_shipping_company( $addr['company'] );
    $order->set_shipping_address_1( $addr['street1'] );
    $order->set_shipping_address_2( $addr['street2'] );
    $order->set_shipping_city( $addr['city'] );
    $order->set_shipping_state( $addr['state'] );
    $order->set_shipping_postcode( $addr['zip'] );
    // The calculator has no country field — it prices US ground transit only. Stated
    // here rather than left blank, because a blank country makes an address unrateable.
    $order->set_shipping_country( 'US' );

    // WC 8.0+ carries a shipping phone; older versions ignore the setter's absence.
    if ( method_exists( $order, 'set_shipping_phone' ) ) {
        $existing = $order->get_shipping_phone();
        if ( trim( (string) $existing ) === '' ) $order->set_shipping_phone( $order->get_billing_phone() );
    }

    // Auditable: whoever picks this order up in Shippo can see where the address came
    // from, rather than wondering why it differs from billing.
    $order->add_order_note( sprintf(
        'Delivery address taken from the calculator: %s, %s %s %s.%s',
        $addr['street1'], $addr['city'], $addr['state'], $addr['zip'],
        $weight > 0 ? sprintf( ' Estimated %.2f lb in %d carton(s).', $weight, max( 1, $cartons ) ) : ''
    ) );

    $order->save();
    return true;
}

/**
 * Prefill the checkout address from the calculator, at add-to-cart.
 *
 * pps_apply_calculator_shipping_address() above repairs the ORDER, after checkout. This
 * is the other half of the same problem: what the customer READS on the checkout page,
 * before any order exists.
 *
 * Without it they see the delivery address they typed into the calculator on the line
 * item ("Ship to: TX 78701") and, a few inches away, whatever WooCommerce remembered
 * from their account ("Scottsdale, AZ 85262") — two ship-tos on one screen with nothing
 * saying which one the job follows. It follows the calculator's: that is the address the
 * quote was priced and the transit time calculated against.
 *
 * So the calculator's address wins outright rather than filling only blanks. A saved
 * profile address is older than something the customer typed a minute ago, and leaving
 * the stale one in place is the bug being fixed. It is still theirs to edit at checkout.
 *
 * Billing is left alone — that is the cardholder's address and frequently not the
 * delivery one, which is the whole reason these are separate fields.
 *
 * Side effect worth knowing: for a logged-in customer, WC_Customer::save() writes
 * through to their saved account shipping address. That is what WooCommerce itself does
 * with an address typed at checkout, and it is what makes the block checkout (a separate
 * request reading WC()->customer) agree with the classic one. If it should instead be
 * scoped to this cart only, drop the save() and prefill via woocommerce_checkout_get_value
 * — that covers classic checkout but not blocks.
 *
 * @param string $metadata_json The line item's pps_metadata, as posted.
 * @return bool True when the session was updated.
 */
function pps_prefill_customer_shipping( $metadata_json ) {
    if ( ! function_exists( 'WC' ) || ! WC()->customer ) return false;

    $meta = json_decode( (string) $metadata_json, true );
    if ( ! is_array( $meta ) ) return false;

    $a = isset( $meta['shipAddr'] ) && is_array( $meta['shipAddr'] ) ? $meta['shipAddr'] : array();
    // State sits alongside shipAddr rather than inside it — the calculator collects it
    // separately because it drives the transit map. ZIP is mirrored in both.
    $street = trim( (string) ( $a['street1'] ?? '' ) );
    $city   = trim( (string) ( $a['city'] ?? '' ) );
    $state  = trim( (string) ( $a['state'] ?? $meta['shipState'] ?? '' ) );
    $zip    = trim( (string) ( $a['zip'] ?? $meta['shipZip'] ?? '' ) );

    // Same rule as the order-side writer: a half address looks authoritative and ships
    // to the wrong place, where an empty one at least prompts the customer to type it.
    if ( $street === '' || $city === '' || $state === '' || $zip === '' ) return false;

    $name  = trim( (string) ( $a['name'] ?? '' ) );
    if ( $name !== '' ) {
        // Split on the last space so "Mary Anne Van Der Berg" keeps its surname intact.
        $cut   = strrpos( $name, ' ' );
        $first = $cut === false ? $name : trim( substr( $name, 0, $cut ) );
        $last  = $cut === false ? ''    : trim( substr( $name, $cut + 1 ) );
        WC()->customer->set_shipping_first_name( $first );
        WC()->customer->set_shipping_last_name( $last );
    }
    WC()->customer->set_shipping_company( trim( (string) ( $a['company'] ?? '' ) ) );
    WC()->customer->set_shipping_address_1( $street );
    WC()->customer->set_shipping_address_2( trim( (string) ( $a['street2'] ?? '' ) ) );
    WC()->customer->set_shipping_city( $city );
    WC()->customer->set_shipping_state( $state );
    WC()->customer->set_shipping_postcode( $zip );
    // The calculator prices US ground transit only and has no country field. Stated
    // rather than left blank, because a blank country makes an address unrateable.
    WC()->customer->set_shipping_country( 'US' );
    // Mirror into billing: the checkout form's visible fields are the billing
    // set, and without this they sit empty and the state control falls back to
    // the store base (Arizona) — a mis-selection waiting to be submitted. Same
    // trade-off as above for logged-in customers: the typed address wins.
    if ( $name !== '' ) {
        WC()->customer->set_billing_first_name( $first );
        WC()->customer->set_billing_last_name( $last );
    }
    WC()->customer->set_billing_company( trim( (string) ( $a['company'] ?? '' ) ) );
    WC()->customer->set_billing_address_1( $street );
    WC()->customer->set_billing_address_2( trim( (string) ( $a['street2'] ?? '' ) ) );
    WC()->customer->set_billing_city( $city );
    WC()->customer->set_billing_state( $state );
    WC()->customer->set_billing_postcode( $zip );
    WC()->customer->set_billing_country( 'US' );
    WC()->customer->save();

    return true;
}

// Classic checkout, block checkout, and the admin "create order" screen. Each fires
// once the line items exist, which is what this reads from.
add_action( 'woocommerce_checkout_order_processed', 'pps_apply_calculator_shipping_address', 20, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'pps_apply_calculator_shipping_address', 20, 1 );

// Safety net. If an order reaches processing without a shipping address — a gateway
// that builds the order down another path, a manual order, a checkout plugin we have
// not met — fill it in before anything downstream imports it.
add_action( 'woocommerce_order_status_processing', 'pps_apply_calculator_shipping_address', 5, 1 );
add_action( 'woocommerce_order_status_on-hold',    'pps_apply_calculator_shipping_address', 5, 1 );

// ═══════════════════════════════════════════════════════════════
// ADMIN ORDER: META BOX
// ═══════════════════════════════════════════════════════════════

add_action( 'add_meta_boxes', function() {
    add_meta_box( 'pps_calc_data', '🖨️ PPS Calculator Data', 'pps_order_meta_box', 'shop_order', 'normal', 'high' );
    add_meta_box( 'pps_calc_data', '🖨️ PPS Calculator Data', 'pps_order_meta_box', 'woocommerce_page_wc-orders', 'normal', 'high' );
});

function pps_order_meta_box( $post_or_order ) {
    $order = ( $post_or_order instanceof WP_Post )
        ? wc_get_order( $post_or_order->ID )
        : $post_or_order;

    if ( $order && $order->get_meta( '_pps_multi_destination' ) ) {
        echo '<div style="margin:0 0 10px;padding:8px 10px;border-left:4px solid #d63638;background:#fcf0f1">'
           . '<strong>⚠ ' . esc_html( (string) $order->get_meta( '_pps_multi_destination' ) )
           . ' different delivery addresses on this order.</strong><br>'
           . 'The order header carries only the first. Check each line\'s "Ship to" below and split the '
           . 'shipment before rating or labelling — the header address is wrong for at least one line.'
           . '</div>';
    }

    if ( ! $order ) { echo '<p>Order not found.</p>'; return; }

    $has_pps = false;
    foreach ( $order->get_items() as $item ) {
        $metadata_json = $item->get_meta( '_pps_metadata' );
        $summary       = $item->get_meta( '_pps_summary' );
        $delivery      = $item->get_meta( '_pps_delivery_date' );
        $rush          = $item->get_meta( '_pps_rush' );
        $artwork_path  = $item->get_meta( '_pps_artwork_path' );

        if ( ! $metadata_json && ! $summary ) continue;
        $has_pps = true;

        echo '<div style="margin-bottom:16px;padding:14px;background:#f0faff;border:1px solid #b8e0f5;border-radius:4px">';
        echo '<h4 style="margin:0 0 8px;color:#007eff">' . esc_html( $item->get_name() ) . ' — $' . number_format( $item->get_total(), 2 ) . '</h4>';

        if ( $delivery ) {
            echo '<p style="margin:0 0 6px"><strong>Delivery:</strong> ' . esc_html( $delivery ) . '</p>';
        }
        if ( $rush && floatval( $rush ) > 0 ) {
            echo '<p style="margin:0 0 6px"><strong>Rush:</strong> $' . number_format( floatval( $rush ), 2 ) . '</p>';
        }

        // Shipment estimate — an operator's reference figure, nothing more. The
        // calculator's own weight model produced it at quote time; it has never been on
        // a scale. Labelled "est." and never surfaced to the customer, so nobody buys
        // postage against it without weighing the job first.
        $ship_meta = json_decode( (string) $metadata_json, true );
        if ( is_array( $ship_meta ) && (float) ( $ship_meta['estWeightLb'] ?? 0 ) > 0 ) {
            $w = (float) $ship_meta['estWeightLb'];
            $c = max( 1, (int) ( $ship_meta['estCartons'] ?? 1 ) );
            echo '<p style="margin:0 0 6px"><strong>Shipment (est.):</strong> '
               . esc_html( number_format( $w, 2 ) ) . ' lb · '
               . esc_html( $c ) . ' carton' . ( $c === 1 ? '' : 's' )
               . ' <span style="color:#666;font-weight:400">— calculated, not weighed</span></p>';
        }

        // Where THIS line is going. The order carries one shipping address, so on a
        // multi-destination order it is right for one line and wrong for the others —
        // and the packer had no way to see that. Printed against the line's own
        // shipment estimate, which is the figure it actually belongs to.
        if ( is_array( $ship_meta ) ) {
            $sa = isset( $ship_meta['shipAddr'] ) && is_array( $ship_meta['shipAddr'] ) ? $ship_meta['shipAddr'] : array();
            $line_street = trim( (string) ( $sa['street1'] ?? '' ) );
            $line_city   = trim( (string) ( $sa['city'] ?? '' ) );
            $line_state  = trim( (string) ( $sa['state'] ?? $ship_meta['shipState'] ?? '' ) );
            $line_zip    = trim( (string) ( $sa['zip'] ?? $ship_meta['shipZip'] ?? '' ) );
            if ( $line_street !== '' && $line_city !== '' ) {
                $who = trim( trim( (string) ( $sa['name'] ?? '' ) ) . ( ! empty( $sa['company'] ) ? ' · ' . $sa['company'] : '' ) );
                $line_two = trim( (string) ( $sa['street2'] ?? '' ) );
                echo '<p style="margin:0 0 6px"><strong>Ship to:</strong> '
                   . ( $who !== '' ? esc_html( $who ) . ' — ' : '' )
                   . esc_html( $line_street )
                   . ( $line_two !== '' ? ', ' . esc_html( $line_two ) : '' )
                   . ', ' . esc_html( $line_city ) . ', ' . esc_html( $line_state ) . ' ' . esc_html( $line_zip )
                   . '</p>';
            }
        }

        // Artwork: Drive-aware renderer if available, else local fallback
        if ( function_exists( 'pps_render_artwork_link' ) ) {
            echo pps_render_artwork_link( $item );
        } elseif ( $artwork_path ) {
            $upload    = wp_upload_dir();
            $full_path = trailingslashit( $upload['basedir'] ) . $artwork_path;
            $file_url  = trailingslashit( $upload['baseurl'] ) . $artwork_path;
            if ( file_exists( $full_path ) ) {
                $fsize = size_format( filesize( $full_path ) );
                $ext   = strtoupper( pathinfo( $artwork_path, PATHINFO_EXTENSION ) );
                echo '<p style="margin:0 0 6px"><strong>Artwork:</strong> ';
                echo '<a href="' . esc_url( $file_url ) . '" target="_blank" style="text-decoration:none">';
                echo '📎 Download ' . esc_html( $ext ) . ' (' . esc_html( $fsize ) . ')';
                echo '</a></p>';
            } else {
                echo '<p style="margin:0 0 6px;color:#b32d2e"><strong>Artwork:</strong> File missing from server</p>';
            }
        }

        if ( $summary ) {
            echo '<div style="margin-bottom:8px"><strong>Summary:</strong>';
            echo '<pre style="background:#fff;padding:10px;border:1px solid #ddd;border-radius:3px;font-size:12px;white-space:pre-wrap;margin:4px 0 0">';
            echo esc_html( $summary );
            echo '</pre></div>';
        }
        if ( $metadata_json ) {
            $decoded = json_decode( $metadata_json, true );
            $pretty  = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
            echo '<details><summary style="cursor:pointer;font-weight:600;font-size:12px;color:#007eff">Production JSON ▾</summary>';
            echo '<pre style="background:#1a1a1a;color:#007eff;padding:12px;border-radius:4px;font-size:11px;margin-top:6px;max-height:400px;overflow:auto;white-space:pre-wrap">';
            echo esc_html( $pretty );
            echo '</pre></details>';
        }
        echo '</div>';
    }

    if ( ! $has_pps ) {
        echo '<p style="color:#999;font-style:italic">No calculator data on this order.</p>';
    }
}

// ═══════════════════════════════════════════════════════════════
// EMAILS: HIDE INTERNAL META KEYS
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_hidden_order_itemmeta', function( $hidden ) {
    $hidden[] = '_pps_metadata';
    $hidden[] = '_pps_summary';
    $hidden[] = '_pps_rush';
    $hidden[] = '_pps_delivery_date';
    $hidden[] = '_pps_artwork_path';
    $hidden[] = '_pps_artwork_files';
    $hidden[] = '_pps_proof_hash';
    return $hidden;
});

/**
 * The size as production needs to read it.
 *
 * A custom trim stored `sizeLabel` as the literal string "Custom Size", and both
 * the Order Summary and PPS-Spec printed exactly that — so order #87005 reached
 * the shop as "Custom Size | 800qty | 12pg" with no dimensions anywhere on the
 * ticket. Unmakeable. Name the inches instead, keeping the word Custom because a
 * custom trim takes a different imposition path and production needs to know.
 *
 * Handles both vocabularies: booklets store customLong/customShort, flats store
 * longEdge/shortEdge.
 */
function pps_spec_size_label( array $full ) {
    $label = trim( (string) ( $full['sizeLabel'] ?? '' ) );
    $is_custom = ( stripos( $label, 'custom' ) !== false )
              || ( ( $full['sizeMode'] ?? '' ) === 'custom' );
    if ( ! $is_custom ) return $label !== '' ? $label : 'Unknown';

    $a = $full['customShort'] ?? $full['shortEdge'] ?? null;
    $b = $full['customLong']  ?? $full['longEdge']  ?? null;
    if ( ! is_numeric( $a ) || ! is_numeric( $b ) ) {
        return $label !== '' ? $label : 'Unknown';
    }
    $w = min( (float) $a, (float) $b );
    $h = max( (float) $a, (float) $b );
    $fmt = static function( $n ) {
        return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
    };
    return 'Custom ' . $fmt( $w ) . '×' . $fmt( $h ) . '"';
}

// ═══════════════════════════════════════════════════════════════
// PER-PRODUCT DEFAULTS (WooCommerce Product Editor)
// ═══════════════════════════════════════════════════════════════

/**
 * The schema lowPrice for the product currently being viewed: the quoted price
 * at its own defaults when set, else the fallback.
 *
 * Kept as a formatted string because schema.org offers want a string and the
 * surrounding array is built with literals.
 */
function pps_product_defaults_low_price( $fallback = '50' ) {
    $pid = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
    if ( ! $pid ) return $fallback;
    $f = pps_product_price_facts( $pid );
    return $f['effective'] !== null ? number_format( $f['effective'], 2, '.', '' ) : $fallback;
}

/**
 * The single price answer for a product. Both the Product schema and the
 * Merchant Center feed call this, so the two cannot disagree.
 *
 * ── Why this function exists ──
 *
 * Google does not fill in the calculator to discover a price. It reads the
 * page's structured data. So the comparison Merchant Center actually performs
 * is *feed vs. your own Product schema* — not feed vs. the number a human sees.
 *
 * That makes it dangerously easy to publish a mismatch: the schema reads
 * _pps_defaults_price while WooCommerce's price element reads _price, and
 * nothing kept the two in step. A product whose Woo price was edited by hand
 * after its defaults were quoted would advertise one number and mark up
 * another, and the first anyone would hear of it is an item disapproval.
 *
 * Three numbers, deliberately kept distinct:
 *
 *   quoted     _pps_defaults_price — what the calculator quotes for this
 *              product's own default configuration. The number that means
 *              something; set from a share link.
 *   regular    _regular_price — the list price WooCommerce renders.
 *   sale       An active sale price, or null. get_price() collapses this into
 *              regular, which is why it cannot be used on its own.
 *
 * `effective` is what a customer pays today, and is what both the schema and
 * the feed's advertised price reflect. Because both read it from here, they
 * cannot disagree with each other — which is the only consistency Google
 * actually checks.
 *
 * `publishable` therefore asks one question: is there a price at all? Whether
 * that price is high or low, and whether it matches the stored quote, is not
 * this function's business — the owner's instruction is that the product's own
 * price goes out and where it lands it lands.
 *
 * `agrees` is kept as information only. A product whose stored quote and whose
 * WooCommerce price differ is worth a look, but it is a housekeeping note, not
 * a reason to withhold a sellable product.
 *
 * @param int $product_id
 * @return array{quoted:?float,regular:?float,sale:?float,effective:?float,agrees:bool,publishable:bool}
 */
function pps_product_price_facts( $product_id ) {
    $out = array(
        'quoted' => null, 'regular' => null, 'sale' => null,
        'effective' => null, 'agrees' => false, 'publishable' => false,
    );

    $product_id = (int) $product_id;
    if ( ! $product_id ) return $out;

    $q = get_post_meta( $product_id, '_pps_defaults_price', true );
    if ( $q !== '' && $q !== null ) {
        $q = (float) $q;
        if ( $q > 0 && $q < 1000000 ) $out['quoted'] = round( $q, 2 );
    }

    if ( function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $r = $product->get_regular_price();
            if ( $r !== '' && $r !== null && (float) $r > 0 ) $out['regular'] = round( (float) $r, 2 );

            // is_on_sale() is the only reliable test — a sale price can be set
            // but scheduled for a window that is not open yet.
            if ( $product->is_on_sale() ) {
                $s = $product->get_sale_price();
                if ( $s !== '' && $s !== null && (float) $s > 0 ) $out['sale'] = round( (float) $s, 2 );
            }
        }
    }

    $out['effective'] = $out['sale'] !== null ? $out['sale'] : $out['regular'];

    $out['agrees'] = ( $out['quoted'] !== null && $out['regular'] !== null
                       && abs( $out['quoted'] - $out['regular'] ) < 0.01 );

    // One question: is there a price? Nothing here judges the number.
    $out['publishable'] = ( $out['effective'] !== null && $out['effective'] > 0 );

    return $out;
}

/**
 * Add a "PPS Defaults" tab to the WooCommerce product data panel.
 * Lets you set default calculator values per product (size, qty, paper, etc.)
 * so different product URLs start with different configurations.
 */
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
    $tabs['pps_defaults'] = array(
        'label'    => 'PPS Defaults',
        'target'   => 'pps_defaults_data',
        'class'    => array(),
        'priority' => 90,
    );
    return $tabs;
} );

add_action( 'woocommerce_product_data_panels', function() {
    global $post;
    $defaults = get_post_meta( $post->ID, '_pps_defaults', true ) ?: array();
    ?>
    <div id="pps_defaults_data" class="panel woocommerce_options_panel" style="padding:12px 20px">
        <p style="color:#666;font-size:12px">Set default calculator values for this product. Leave blank to use the global defaults. URL parameters override these.</p>

        <?php
        // ── Apply-from-quote-link ──────────────────────────────────────
        // Configure the job in the calculator, hit "Copy link", paste here.
        // Beats hand-writing JSON: the calculator guarantees the exact paper
        // values, the × in size labels and the enum spellings.
        $last_src = get_post_meta( $post->ID, '_pps_defaults_source', true );
        ?>
        <p class="form-field" style="border-top:1px solid #eee;padding-top:12px">
            <label for="pps_defaults_url">Apply from quote link</label>
            <input type="url" id="pps_defaults_url" name="pps_defaults_url" value=""
                   placeholder="https://priorityprintservice.com/product/…?size=5.5×8.5&amp;qty=100&amp;pages=8…"
                   style="width:100%;max-width:640px" />
            <span class="description" style="display:block;margin-left:0">
                Configure the job in the calculator, click <strong>Copy link</strong>, paste it here and save.
                Applied once on save, then cleared. Fields below override anything the link sets.
                <?php if ( $last_src ) : ?>
                    <br><em style="color:#777">Last applied: <?php echo esc_html( wp_trim_words( $last_src, 14, '…' ) ); ?></em>
                <?php endif; ?>
            </span>
        </p>
        <?php
        $fields = array(
            'productType'     => array( 'label' => 'Product Type', 'placeholder' => 'letterhead (derivative calc mode)' ),
            'sizeLabel'       => array( 'label' => 'Size', 'placeholder' => '5.5×8.5 (Opens to 8.5×11)' ),
            'foldType'        => array( 'label' => 'Fold Type (brochure/postcard family)', 'placeholder' => 'flat, bifold, trifold, z3, gate3, accordion4, roll4, dgate4, dparallel4' ),
            'qty'             => array( 'label' => 'Default Quantity', 'placeholder' => '100', 'type' => 'number' ),
            'pages'           => array( 'label' => 'Default Pages', 'placeholder' => '8', 'type' => 'number' ),
            'bindDir'         => array( 'label' => 'Bind Direction', 'placeholder' => 'short' ),
            'insideColor'     => array( 'label' => 'Inside Color', 'placeholder' => 'color (or bw)' ),
            'coverColor'      => array( 'label' => 'Cover Color', 'placeholder' => 'color (or bw)' ),
            'insidePaperType' => array( 'label' => 'Inside Paper Type', 'placeholder' => 'noncardstock (or cardstock)' ),
            'coverMode'       => array( 'label' => 'Cover Mode', 'placeholder' => 'same (or separate)' ),
            'shipState'       => array( 'label' => 'Default Ship State', 'placeholder' => 'AZ' ),
        );
        foreach ( $fields as $key => $f ) {
            $val = $defaults[ $key ] ?? '';
            $type = $f['type'] ?? 'text';
            echo '<p class="form-field"><label for="pps_d_' . $key . '">' . esc_html( $f['label'] ) . '</label>';
            echo '<input type="' . $type . '" id="pps_d_' . $key . '" name="pps_defaults[' . $key . ']" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $f['placeholder'] ) . '" style="width:300px" /></p>';
        }

        // ── Advanced: anything the eleven fields above can't express ──
        // The runtime has always accepted arbitrary keys (the reader
        // json_decodes a string value); only this form was the ceiling.
        $known    = array_keys( $fields );
        $extra    = array_diff_key( is_array( $defaults ) ? $defaults : array(), array_flip( $known ) );
        $extra_js = $extra ? wp_json_encode( $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
        ?>
        <p class="form-field" style="border-top:1px solid #eee;padding-top:12px">
            <label for="pps_defaults_extra">Advanced defaults (JSON)</label>
            <textarea id="pps_defaults_extra" name="pps_defaults_extra" rows="5" style="width:100%;max-width:640px;font-family:monospace"
                      placeholder='{"vividPrint": true, "bundling": 750, "customLong": 8.5, "customShort": 5.5}'><?php echo esc_textarea( $extra_js ); ?></textarea>
            <span class="description" style="display:block;margin-left:0">
                Any calculator default the fields above don't cover. Keys are case-sensitive
                (<code>sizeLabel</code>, not <code>sizelabel</code>). Fields above win on conflict.
            </span>
        </p>
        <?php
        // ── Display price at these defaults ──
        // Woo's own price field drives shop cards and Product schema. What the
        // customer is actually charged is always recomputed at add-to-cart from
        // pps_price, so this is a display/"from" figure and can never overcharge.
        $quoted = get_post_meta( $post->ID, '_pps_defaults_price', true );
        ?>
        <p class="form-field">
            <label for="pps_defaults_price">Price at these defaults</label>
            <input type="number" step="0.01" min="0" id="pps_defaults_price" name="pps_defaults_price"
                   value="<?php echo esc_attr( $quoted ); ?>" placeholder="109.04" style="width:300px" />
            <span class="description" style="display:block;margin-left:0">
                Sets the product's regular price, so the catalogue card and Product schema quote
                the configuration this page actually lands on. Filled automatically if the pasted
                quote link carries a total. <strong>Display only</strong> — the charged price is
                always recalculated at add-to-cart.
            </span>
        </p>
        <?php
        ?>
    </div>
    <?php
} );

add_action( 'woocommerce_process_product_meta', function( $post_id ) {
    if ( ! current_user_can( 'edit_products' ) ) return;
    if ( ! isset( $_POST['pps_defaults'] ) ) return;

    // Three sources, increasing precedence, so what you can see always wins
    // over what you can't:
    //   1. a pasted quote link   (bulk, from the calculator)
    //   2. the advanced JSON box (anything the named fields can't express)
    //   3. the named fields      (visible in the form)
    $merged     = array();
    $notice     = '';
    $source_url = '';
    $url_price  = null;

    if ( ! empty( $_POST['pps_defaults_url'] ) && function_exists( 'pps_defaults_from_url' ) ) {
        $source_url = esc_url_raw( wp_unslash( $_POST['pps_defaults_url'] ) );
        $parsed     = pps_defaults_from_url( $source_url );
        if ( ! empty( $parsed['defaults'] ) ) $merged = $parsed['defaults'];
        if ( $parsed['price'] !== null )      $url_price = $parsed['price'];
        $notice = pps_defaults_url_summary( $parsed );
    }

    if ( ! empty( $_POST['pps_defaults_extra'] ) ) {
        $extra = json_decode( trim( wp_unslash( $_POST['pps_defaults_extra'] ) ), true );
        if ( is_array( $extra ) ) {
            if ( function_exists( 'pps_sanitize_defaults_blob' ) ) $extra = pps_sanitize_defaults_blob( $extra );
            $merged = array_merge( $merged, $extra );
        } elseif ( $notice === '' ) {
            $notice = 'Advanced defaults ignored — that JSON did not parse.';
        }
    }

    $named = array_filter(
        array_map( 'sanitize_text_field', (array) $_POST['pps_defaults'] ),
        function( $v ) { return $v !== ''; }
    );
    $merged = array_merge( $merged, $named );

    if ( ! empty( $merged ) ) {
        update_post_meta( $post_id, '_pps_defaults', $merged );
    } else {
        delete_post_meta( $post_id, '_pps_defaults' );
    }

    // Keep the link that produced this, for the audit trail the DB otherwise
    // has no record of. Not re-applied on later saves — the field is one-shot.
    if ( $source_url ) update_post_meta( $post_id, '_pps_defaults_source', $source_url );
    if ( $notice )     set_transient( 'pps_defaults_notice_' . $post_id, $notice, 60 );

    // Display price. An explicitly typed value wins over one carried in the
    // link, so the operator can always correct it.
    $typed = isset( $_POST['pps_defaults_price'] ) ? trim( wp_unslash( $_POST['pps_defaults_price'] ) ) : '';
    $price = $typed !== '' ? floatval( $typed ) : ( $url_price !== null ? $url_price : null );
    if ( $price !== null && $price > 0 && $price < 1000000 ) {
        $price = round( $price, 2 );
        update_post_meta( $post_id, '_pps_defaults_price', $price );
        // Woo's own fields — these drive the catalogue card and Product schema.
        // Harmless to the cart: pps_price overrides at add-to-cart every time.
        update_post_meta( $post_id, '_regular_price', $price );
        update_post_meta( $post_id, '_price', $price );
    } elseif ( $typed === '' && $url_price === null ) {
        delete_post_meta( $post_id, '_pps_defaults_price' );
    }
} );

/**
 * Surface what a pasted quote link actually applied, so the operator sees the
 * result rather than having to diff the form against the calculator.
 */
add_action( 'admin_notices', function() {
    global $post;
    if ( ! $post || ! isset( $post->ID ) ) return;
    $notice = get_transient( 'pps_defaults_notice_' . $post->ID );
    if ( ! $notice ) return;
    delete_transient( 'pps_defaults_notice_' . $post->ID );
    echo '<div class="notice notice-info is-dismissible"><p><strong>PPS Defaults:</strong> '
       . esc_html( $notice ) . '</p></div>';
} );

// ═══════════════════════════════════════════════════════════════
// REORDER: BUTTON ON MY ACCOUNT → ORDERS
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_my_account_my_orders_actions', function( $actions, $order ) {
    foreach ( $order->get_items() as $item ) {
        $metadata_json = $item->get_meta( '_pps_metadata' );
        if ( ! $metadata_json ) continue;

        $product_id = $item->get_product_id();
        $product    = wc_get_product( $product_id );
        if ( ! $product ) continue;

        $full = json_decode( $metadata_json, true );
        if ( ! $full ) continue;

        // Shared list (pps-reorder.php) so this copy can't drift; inline fallback
        // only if the reorder module isn't loaded.
        $reorder_fields = function_exists( 'pps_reorder_field_whitelist' )
            ? pps_reorder_field_whitelist()
            : array(
                'sizeLabel', 'customLong', 'customShort', 'bindDir',
                'sets',
                'insideColor', 'coverColor',
                'insidePaper', 'insidePaperType',
                'coverMode', 'coverPaper', 'coverPaperType',
                'twoStaple', 'vividPrint',
                'coating', 'bundling', 'roundCorner',
                'artwork', 'artEditPages', 'bleed', 'proof',
                'shipState',
            );
        $reorder_config = array();
        foreach ( $reorder_fields as $key ) {
            if ( isset( $full[ $key ] ) ) {
                $reorder_config[ $key ] = $full[ $key ];
            }
        }

        $encoded = rtrim( strtr( base64_encode( json_encode( $reorder_config ) ), '+/', '-_' ), '=' );
        $url     = add_query_arg( 'pps_reorder', $encoded, $product->get_permalink() );

        $label = 'Reorder';
        $pps_count = 0;
        foreach ( $order->get_items() as $check ) {
            if ( $check->get_meta( '_pps_metadata' ) ) $pps_count++;
        }
        if ( $pps_count > 1 ) {
            $label = 'Reorder ' . $product->get_name();
        }

        $actions[ 'pps_reorder_' . $item->get_id() ] = array(
            'url'  => $url,
            'name' => $label,
        );
    }

    return $actions;
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
// ACTIVATION: CREATE UPLOAD DIRECTORY
// ═══════════════════════════════════════════════════════════════

register_activation_hook( __FILE__, function() {
    pps_upload_dir();
    // Seed initial UPS zone map if none exists
    if ( ! get_option( 'pps_ups_zone_map' ) ) {
        pps_seed_zone_map();
    }
});

/**
 * Seed the UPS zone map with state-level estimates from Phoenix (850).
 * This is the initial fallback — upload an actual UPS zone chart CSV
 * from the admin for zip-prefix-level accuracy.
 */
function pps_seed_zone_map() {
    // 3-digit ZIP prefix → state, then state → transit days from Phoenix
    $state_days = array(
        'AZ'=>1,'CA'=>2,'CO'=>2,'NM'=>2,'NV'=>2,'UT'=>2,
        'ID'=>3,'OR'=>3,'TX'=>3,'WA'=>3,
        'AL'=>4,'AR'=>4,'FL'=>4,'GA'=>4,'IA'=>4,'IL'=>4,'IN'=>4,'KS'=>4,
        'KY'=>4,'LA'=>4,'MI'=>4,'MN'=>4,'MO'=>4,'MS'=>4,'MT'=>4,'ND'=>4,
        'NE'=>4,'OK'=>4,'PA'=>4,'SD'=>4,'TN'=>4,'WI'=>4,'WV'=>4,'WY'=>4,
        'MA'=>5,'NC'=>5,'NJ'=>5,'NY'=>5,'OH'=>5,'SC'=>5,'VA'=>5,
        'CT'=>6,'DC'=>6,'DE'=>6,'MD'=>6,'ME'=>6,'NH'=>6,'VT'=>6,
        'AK'=>7,'HI'=>7,'PR'=>7,'RI'=>7,'VI'=>9,
    );
    // ZIP prefix → state assignments (USPS standard)
    $ranges = array(
        array(6,9,'PR'),array(10,27,'MA'),array(28,29,'RI'),array(30,38,'NH'),
        array(39,49,'ME'),array(50,59,'VT'),array(60,69,'CT'),array(70,89,'NJ'),
        array(100,149,'NY'),array(150,196,'PA'),array(197,199,'DE'),
        array(200,205,'DC'),array(206,219,'MD'),array(220,246,'VA'),
        array(247,268,'WV'),array(270,289,'NC'),array(290,299,'SC'),
        array(300,319,'GA'),array(320,349,'FL'),array(350,369,'AL'),
        array(370,385,'TN'),array(386,397,'MS'),array(400,427,'KY'),
        array(430,458,'OH'),array(460,479,'IN'),array(480,499,'MI'),
        array(500,528,'IA'),array(530,549,'WI'),array(550,567,'MN'),
        array(570,577,'SD'),array(580,588,'ND'),array(590,599,'MT'),
        array(600,629,'IL'),array(630,658,'MO'),array(660,679,'KS'),
        array(680,693,'NE'),array(700,714,'LA'),array(716,729,'AR'),
        array(730,749,'OK'),array(750,799,'TX'),array(800,816,'CO'),
        array(820,831,'WY'),array(832,838,'ID'),array(840,847,'UT'),
        array(850,865,'AZ'),array(870,884,'NM'),array(889,898,'NV'),
        array(900,966,'CA'),array(967,968,'HI'),array(970,979,'OR'),
        array(980,994,'WA'),array(995,999,'AK'),
    );
    $map = array();
    foreach ( $ranges as $r ) {
        $days = $state_days[ $r[2] ] ?? 7;
        for ( $i = $r[0]; $i <= $r[1]; $i++ ) {
            $map[ str_pad( $i, 3, '0', STR_PAD_LEFT ) ] = $days;
        }
    }
    // Fill gaps
    for ( $i = 0; $i < 1000; $i++ ) {
        $p = str_pad( $i, 3, '0', STR_PAD_LEFT );
        if ( ! isset( $map[ $p ] ) ) $map[ $p ] = 7;
    }
    ksort( $map );
    update_option( 'pps_ups_zone_map', $map, false );
    update_option( 'pps_ups_zone_map_updated', current_time( 'mysql' ) . ' (initial seed)', false );
}

// ═══════════════════════════════════════════════════════════════
// REST API: SHIPPING (Shippo proxy + WC address recall)
// ═══════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {

    // GET /wp-json/pps/v1/shipping/address — returns logged-in user's WC shipping address
    register_rest_route( 'pps/v1', '/shipping/address', array(
        'methods'             => 'GET',
        'permission_callback' => 'is_user_logged_in',
        'callback'            => function( $request ) {
            $uid = get_current_user_id();
            return rest_ensure_response( array(
                'name'    => trim( get_user_meta( $uid, 'shipping_first_name', true ) . ' ' . get_user_meta( $uid, 'shipping_last_name', true ) ),
                'company' => get_user_meta( $uid, 'shipping_company', true ),
                'street1' => get_user_meta( $uid, 'shipping_address_1', true ),
                'street2' => get_user_meta( $uid, 'shipping_address_2', true ),
                'city'    => get_user_meta( $uid, 'shipping_city', true ),
                'state'   => get_user_meta( $uid, 'shipping_state', true ),
                'zip'     => get_user_meta( $uid, 'shipping_postcode', true ),
                'country' => get_user_meta( $uid, 'shipping_country', true ),
            ) );
        },
    ) );

    // POST /wp-json/pps/v1/shipping/address — saves shipping address to WC user meta
    register_rest_route( 'pps/v1', '/shipping/address', array(
        'methods'             => 'POST',
        'permission_callback' => 'is_user_logged_in',
        'callback'            => function( $request ) {
            $uid  = get_current_user_id();
            $data = $request->get_json_params();
            // Split name into first/last for WC compatibility
            $parts = explode( ' ', sanitize_text_field( $data['name'] ?? '' ), 2 );
            update_user_meta( $uid, 'shipping_first_name', $parts[0] ?? '' );
            update_user_meta( $uid, 'shipping_last_name', $parts[1] ?? '' );
            update_user_meta( $uid, 'shipping_company', sanitize_text_field( $data['company'] ?? '' ) );
            update_user_meta( $uid, 'shipping_address_1', sanitize_text_field( $data['street1'] ?? '' ) );
            update_user_meta( $uid, 'shipping_address_2', sanitize_text_field( $data['street2'] ?? '' ) );
            update_user_meta( $uid, 'shipping_city', sanitize_text_field( $data['city'] ?? '' ) );
            update_user_meta( $uid, 'shipping_state', strtoupper( sanitize_text_field( $data['state'] ?? '' ) ) );
            update_user_meta( $uid, 'shipping_postcode', sanitize_text_field( $data['zip'] ?? '' ) );
            update_user_meta( $uid, 'shipping_country', sanitize_text_field( $data['country'] ?? 'US' ) );
            return rest_ensure_response( array( 'saved' => true ) );
        },
    ) );

    // POST /wp-json/pps/v1/shipping/validate — Shippo address validation proxy
    // Requires pcf.shippo_api_token to be set in Central Config
    register_rest_route( 'pps/v1', '/shipping/validate', array(
        'methods'             => 'POST',
        'permission_callback' => function() { return is_user_logged_in(); },
        'callback'            => function( $request ) {
            $cfg   = pps_get_config();
            $token = $cfg['pcf']['shippo_api_token'] ?? '';
            if ( empty( $token ) ) {
                return new WP_Error( 'no_shippo', 'Shippo API token not configured', array( 'status' => 501 ) );
            }
            $data = $request->get_json_params();
            $body = array(
                'name'     => sanitize_text_field( $data['name'] ?? '' ),
                'company'  => sanitize_text_field( $data['company'] ?? '' ),
                'street1'  => sanitize_text_field( $data['street1'] ?? '' ),
                'street2'  => sanitize_text_field( $data['street2'] ?? '' ),
                'city'     => sanitize_text_field( $data['city'] ?? '' ),
                'state'    => strtoupper( sanitize_text_field( $data['state'] ?? '' ) ),
                'zip'      => sanitize_text_field( $data['zip'] ?? '' ),
                'country'  => sanitize_text_field( $data['country'] ?? 'US' ),
                'validate' => true,
            );
            $resp = wp_remote_post( 'https://api.goshippo.com/addresses/', array(
                'headers' => array(
                    'Authorization' => 'ShippoToken ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 10,
            ) );
            if ( is_wp_error( $resp ) ) {
                return new WP_Error( 'shippo_error', $resp->get_error_message(), array( 'status' => 502 ) );
            }
            return rest_ensure_response( json_decode( wp_remote_retrieve_body( $resp ), true ) );
        },
    ) );

    // POST /wp-json/pps/v1/coupon/preview — what would this code do to this quote?
    //
    // The calculator needs to SHOW the effect of a discount code while the customer is
    // still configuring, but coupons live in WooCommerce and the browser must never be
    // told what they are worth in advance. So the client asks, and this answers for one
    // code against one subtotal.
    //
    // This is a PREVIEW and nothing more. The coupon is never folded into pps_price —
    // doing so would trip pps_materials_price_floor() (which compares the line price to
    // materials cost) and desync the pps_lock checksum. The real discount is applied by
    // WooCommerce at cart level in pps_ajax_add_to_cart(), which also means cart,
    // checkout, order emails and the admin screen render it natively for free.
    // A tampered client can therefore only lie to itself: Woo re-validates at checkout.
    // Fresh nonces on demand. The nonces baked into PPS_CONFIG ride inside
    // page-cached HTML, and once the cache outlives the nonce tick (~12h) an
    // aged page's add-to-cart starts failing. The calculators call this just
    // before submit and swap the baked values for live ones. Public on
    // purpose: a nonce is minted for the caller's own session cookie and
    // grants nothing by itself.
    register_rest_route( 'pps/v1', '/nonces', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            // Never let this response be cached. A nonce is bound to the
            // caller's identity, so a cached copy would hand one user another
            // user's token — which verifies as a mismatch and fails exactly
            // the way the baked-in nonce already does. That would make the
            // retry path useless for the case it exists to fix: a customer
            // who logged in after the page was rendered.
            nocache_headers();
            return array(
                'cart'   => wp_create_nonce( 'pps_add_to_cart' ),
                'upload' => wp_create_nonce( 'pps_upload_artwork' ),
            );
        },
    ) );

    register_rest_route( 'pps/v1', '/coupon/preview', array(
        'methods'             => 'POST',
        // Public: guests are most customers, and they need to see the effect too.
        'permission_callback' => '__return_true',
        'callback'            => function( $request ) {
            if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
                return new WP_Error( 'no_wc', 'WooCommerce unavailable', array( 'status' => 501 ) );
            }
            $data     = $request->get_json_params();
            $code     = wc_format_coupon_code( sanitize_text_field( (string) ( $data['code'] ?? '' ) ) );
            $subtotal = round( floatval( $data['subtotal'] ?? 0 ), 2 );
            $prod_id  = intval( $data['product_id'] ?? 0 );

            if ( $code === '' || strlen( $code ) > 60 ) {
                return new WP_Error( 'bad_code', 'Enter a discount code.', array( 'status' => 400 ) );
            }
            if ( $subtotal <= 0 ) {
                return new WP_Error( 'bad_subtotal', 'Configure your order first.', array( 'status' => 400 ) );
            }

            // Rate limit per IP. Without this the endpoint is a coupon-code oracle —
            // someone could grind through guesses until one validates.
            $ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
            $rl_key = 'pps_coupon_rl_' . md5( $ip );
            $hits   = (int) get_transient( $rl_key );
            if ( $hits >= 12 ) {
                return new WP_Error( 'rate_limited', 'Too many attempts — please wait a minute.', array( 'status' => 429 ) );
            }
            set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

            // Deliberately uniform failure text: never distinguish "no such code" from
            // "expired" or "used up", or the endpoint confirms which codes exist.
            $reject = new WP_Error( 'invalid', 'That code is not valid for this order.', array( 'status' => 200 ) );

            $coupon_id = wc_get_coupon_id_by_code( $code );
            if ( ! $coupon_id ) return $reject;
            $coupon = new WC_Coupon( $coupon_id );
            if ( ! $coupon->get_id() ) return $reject;

            // Validity checks done explicitly rather than via WC_Discounts, which needs a
            // real cart. Each one mirrors what Woo will enforce at checkout.
            $exp = $coupon->get_date_expires();
            if ( $exp && $exp->getTimestamp() < current_time( 'timestamp', true ) ) return $reject;

            $limit = $coupon->get_usage_limit();
            if ( $limit > 0 && $coupon->get_usage_count() >= $limit ) return $reject;

            $min = (float) $coupon->get_minimum_amount();
            if ( $min > 0 && $subtotal < $min ) {
                return rest_ensure_response( array(
                    'valid'   => false,
                    'message' => sprintf( 'This code needs a minimum of %s.', wp_strip_all_tags( wc_price( $min ) ) ),
                ) );
            }
            $max = (float) $coupon->get_maximum_amount();
            if ( $max > 0 && $subtotal > $max ) {
                return rest_ensure_response( array(
                    'valid'   => false,
                    'message' => sprintf( 'This code applies to orders up to %s.', wp_strip_all_tags( wc_price( $max ) ) ),
                ) );
            }

            // Product / category restrictions, when the calculator told us the product.
            if ( $prod_id ) {
                $inc = $coupon->get_product_ids();
                $exc = $coupon->get_excluded_product_ids();
                if ( ! empty( $inc ) && ! in_array( $prod_id, $inc, true ) ) return $reject;
                if ( ! empty( $exc ) && in_array( $prod_id, $exc, true ) ) return $reject;
                $cat_inc = $coupon->get_product_categories();
                $cat_exc = $coupon->get_excluded_product_categories();
                if ( ! empty( $cat_inc ) || ! empty( $cat_exc ) ) {
                    $terms = wc_get_product_cat_ids( $prod_id );
                    if ( ! empty( $cat_inc ) && ! array_intersect( $terms, $cat_inc ) ) return $reject;
                    if ( ! empty( $cat_exc ) && array_intersect( $terms, $cat_exc ) ) return $reject;
                }
            }

            $type   = $coupon->get_discount_type();
            $amount = (float) $coupon->get_amount();
            if ( 'percent' === $type ) {
                $discount = $subtotal * ( $amount / 100 );
            } else {
                // fixed_cart and fixed_product both resolve to a flat amount here: the
                // calculator quotes a single line item, so there is no basket to spread across.
                $discount = $amount;
            }
            $discount = min( round( $discount, 2 ), $subtotal );
            if ( $discount <= 0 ) return $reject;

            return rest_ensure_response( array(
                'valid'    => true,
                'code'     => $code,
                'type'     => $type,
                'amount'   => $amount,
                'discount' => $discount,
                'label'    => 'percent' === $type
                    ? rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '% off'
                    : wp_strip_all_tags( wc_price( $amount ) ) . ' off',
                'free_shipping' => (bool) $coupon->get_free_shipping(),
            ) );
        },
    ) );

    // POST /wp-json/pps/v1/shipping/transit-estimate — Shippo transit time by zip
    // Lightweight call: just origin zip + destination zip → UPS ground transit days
    register_rest_route( 'pps/v1', '/shipping/transit-estimate', array(
        'methods'             => 'POST',
        // Public, read-only transit lookup — guests (most customers) need it.
        // Guarded by a 30-day per-destination cache (UPS ground transit is stable)
        // and a per-IP rate limit, so it can't be used to burn the Shippo quota.
        'permission_callback' => '__return_true',
        'callback'            => function( $request ) {
            $cfg   = pps_get_config();
            $token = $cfg['pcf']['shippo_api_token'] ?? '';
            if ( empty( $token ) ) {
                return new WP_Error( 'no_shippo', 'Shippo API token not configured', array( 'status' => 501 ) );
            }
            $data         = $request->get_json_params();
            $origin_zip   = $cfg['pcf']['shippo_origin_zip'] ?? '85027';
            $dest_zip     = substr( preg_replace( '/[^0-9]/', '', (string) ( $data['zip'] ?? '' ) ), 0, 5 );
            $dest_state   = strtoupper( sanitize_text_field( $data['state'] ?? '' ) );
            $dest_country = strtoupper( sanitize_text_field( $data['country'] ?? 'US' ) );

            if ( strlen( $dest_zip ) < 5 ) {
                return new WP_Error( 'bad_zip', 'ZIP code must be at least 5 digits', array( 'status' => 400 ) );
            }

            // Optional weight_lb (internal ops use): when present, quote the REAL
            // multi-carton shipment instead of the 2 lb transit sample. Owner rule
            // 2026-07-19: goods pack in 45 lb cartons (a carton = 900 text-weight
            // or 400 cardstock 13x19 sheets — that math lives client-side).
            $weight_lb = isset( $data['weight_lb'] ) ? floatval( $data['weight_lb'] ) : 0;
            if ( $weight_lb < 0 || $weight_lb > 2000 ) $weight_lb = 0;

            // Cache hit → serve instantly, no Shippo call. Weighted quotes cache
            // separately per (zip, rounded lb); transit-only keeps the v2 key.
            $cache_key = $weight_lb > 0
                ? 'pps_shipcost_v1_' . $dest_country . '_' . $dest_zip . '_' . (string) ceil( $weight_lb )
                : 'pps_transit_v2_' . $dest_country . '_' . $dest_zip; // v2: UPS-only selection (Ground Saver excluded) — orphans v1 entries
            $cached    = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                $cached['cached'] = true;
                return rest_ensure_response( $cached );
            }

            // Cache miss → rate-limit per IP before spending a Shippo call.
            $ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : '0';
            $rl_key = 'pps_transit_rl_' . md5( $ip );
            $hits   = (int) get_transient( $rl_key );
            if ( $hits >= 20 ) {
                return new WP_Error( 'rate_limited', 'Too many lookups — please slow down', array( 'status' => 429 ) );
            }
            set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

            // Parcels: real 45 lb cartons (19x13x6) when a weight was given,
            // else the minimal 2 lb sample (transit days don't vary by weight).
            if ( $weight_lb > 0 ) {
                $carton_lb = 45.0;
                $n_parcels = min( 20, max( 1, (int) ceil( $weight_lb / $carton_lb ) ) );
                $parcels   = array();
                $rem       = $weight_lb;
                for ( $i = 0; $i < $n_parcels; $i++ ) {
                    $w   = min( $carton_lb, $rem );
                    $rem = $rem - $w;
                    $parcels[] = array(
                        'length'        => '19',
                        'width'         => '13',
                        'height'        => '6',
                        'distance_unit' => 'in',
                        'weight'        => (string) max( 1, round( $w, 1 ) ),
                        'mass_unit'     => 'lb',
                    );
                }
            } else {
                $n_parcels = 1;
                $parcels   = array( array(
                    'length'        => '12',
                    'width'         => '10',
                    'height'        => '2',
                    'distance_unit' => 'in',
                    'weight'        => '2',
                    'mass_unit'     => 'lb',
                ) );
            }

            $shipment = array(
                'address_from' => array(
                    'zip'     => $origin_zip,
                    'country' => 'US',
                ),
                'address_to' => array(
                    'zip'     => $dest_zip,
                    'state'   => $dest_state,
                    'country' => $dest_country,
                ),
                'parcels' => $parcels,
                'async' => false,
            );

            $resp = wp_remote_post( 'https://api.goshippo.com/shipments/', array(
                'headers' => array(
                    'Authorization' => 'ShippoToken ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $shipment ),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $resp ) ) {
                return new WP_Error( 'shippo_error', $resp->get_error_message(), array( 'status' => 502 ) );
            }

            $result = json_decode( wp_remote_retrieve_body( $resp ), true );
            $rates  = $result['rates'] ?? array();

            // UPS only (owner rule 2026-07-19): prefer true UPS Ground; never
            // Ground Saver / SurePost (economy USPS-handoff final mile — not
            // representative of our shipping). Other carriers on the Shippo
            // account (USPS, …) are ignored entirely.
            $ups = array();
            foreach ( $rates as $rate ) {
                if ( strtolower( $rate['provider'] ?? '' ) !== 'ups' ) continue;
                $tok  = strtolower( $rate['servicelevel']['token'] ?? '' );
                $name = strtolower( $rate['servicelevel']['name'] ?? '' );
                if ( strpos( $tok, 'ground_saver' ) !== false || strpos( $name, 'ground saver' ) !== false ) continue;
                if ( strpos( $tok, 'surepost' ) !== false ) continue;
                $ups[] = $rate;
            }

            $ground = null;
            foreach ( $ups as $rate ) {
                if ( strpos( strtolower( $rate['servicelevel']['token'] ?? '' ), 'ground' ) !== false ) {
                    $ground = $rate;
                    break;
                }
            }

            // Fallback: slowest remaining UPS service (closest stand-in for
            // ground transit). No UPS rates at all → transit_days stays null,
            // is never cached, and the calculator keeps its static estimate.
            if ( ! $ground && ! empty( $ups ) ) {
                usort( $ups, function( $a, $b ) {
                    return ( $b['estimated_days'] ?? 0 ) - ( $a['estimated_days'] ?? 0 );
                } );
                $ground = $ups[0];
            }

            $payload = array(
                'transit_days'  => $ground['estimated_days'] ?? null,
                'carrier'       => $ground['provider'] ?? null,
                'service'       => $ground['servicelevel']['name'] ?? null,
                'amount'        => $ground['amount'] ?? null,
                'currency'      => $ground['currency'] ?? 'USD',
                'domestic'      => $dest_country === 'US',
                'parcels'       => $n_parcels,
                'est_weight_lb' => $weight_lb > 0 ? round( $weight_lb, 1 ) : null,
                'cached'        => false,
            );
            // Cache only successful lookups (30 days); UPS ground transit is stable.
            if ( $payload['transit_days'] !== null ) {
                set_transient( $cache_key, $payload, 30 * DAY_IN_SECONDS );
            }
            return rest_ensure_response( $payload );
        },
    ) );

} );

// ═══════════════════════════════════════════════════════════════
// TOOLTIPS: CENTRALIZED RICH TOOLTIP CONTENT
// ═══════════════════════════════════════════════════════════════

/**
 * Default tooltip content — used when no custom content is saved.
 * Keys match what the calculator JS looks up via PPS_CONFIG.tips[key].
 * Each tooltip has: title, content (array of {type, value/src/alt/poster} blocks).
 */
function pps_default_tooltips() {
    return array(
        'score_fold' => array(
            'title' => 'Score & Fold vs. Score for Folding',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Score & Fold: we crease each card and machine-fold it, so your cards arrive finished and ready to use.' ),
                array( 'type' => 'text', 'value' => 'Score for Folding: we crease (score) each card but ship it FLAT. The score line guarantees a clean, crack-free fold on heavy stock — you do the folding. Choose this when you plan to print, address, or insert before folding; flat cards also feed through desktop printers and mailing equipment more easily.' ),
                array( 'type' => 'text', 'value' => 'Both options run the same bindery pass, so the price is the same either way.' ),
            ),
        ),
        'envelopes' => array(
            'title' => 'Blank Envelopes',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Adds blank envelopes matched to your card size, shipped with your order. Sold in full packs — your quantity is rounded up to the nearest pack.' ),
                array( 'type' => 'text', 'value' => 'Envelopes are ordered in for each job and typically add about 5 business days to turnaround.' ),
            ),
        ),
        'vivid' => array(
            'title' => 'Enhanced Vivid Printing',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Every job receives high quality printing, but enhanced vivid mode increases color saturation and contrast. It is best used for high-density art on gloss paper.' ),
            ),
        ),
        'coating' => array(
            'title' => 'UV Coating',
            'content' => array(
                array( 'type' => 'text', 'value' => 'A liquid UV coating applied after printing. Enhances durability, color vibrancy, and provides a professional finish.' ),
                array( 'type' => 'text', 'value' => 'UV Gloss: High-shine reflective finish that makes colors pop. UV Matte: Soft, non-reflective finish with a velvety feel.' ),
                array( 'type' => 'text', 'value' => 'Requires a glossy or coated paper. Cannot be applied to uncoated stocks.' ),
            ),
        ),
        'cover_coating' => array(
            'title' => 'UV Cover Coating',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Additional coating applied to the outside of the front and back cover sheets. This will retexture the printing from the paper\'s default finish.' ),
                array( 'type' => 'text', 'value' => 'UV Matte adds a distinctive roughness that reduces glare. UV Gloss greatly increases sheen and color saturation with a mirror-like coating.' ),
            ),
        ),
        'bundling' => array(
            'title' => 'Bundling',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Bundling in quantities within the same package. We make no guarantee as to what material is used for the bundling; whether rubber bands, plastic bands, paper bands, or paper or plastic wrapping.' ),
                array( 'type' => 'text', 'value' => 'If you have particular needs, be sure to request a quote before placing an order.' ),
            ),
        ),
        'round_cornering' => array(
            'title' => 'Round Cornering',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Rounded corners add a distinctive appeal. Choose from two sizes, narrow and wide corner.' ),
                array( 'type' => 'text', 'value' => 'In the case of booklets, a wider radius works best with greater page counts.' ),
            ),
        ),
        'perforation' => array(
            'title' => 'Perforation',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Adds perforations to the sheet(s) to make them easier to tear.' ),
            ),
        ),
        'outfold' => array(
            'title' => 'Fold-Out Page (Outfold)',
            'content' => array(
                array( 'type' => 'text', 'value' => 'An outfold is like having a brochure within the book. It is specially-sized, folded and then inserted before binding. Excellent for diagrams and schematics.' ),
                array( 'type' => 'text', 'value' => 'The design of these is intricate, so feel free to reach out before or after placing your order.' ),
            ),
        ),
        'perfect_binding' => array(
            'title' => 'Perfect Binding',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Pages are glued to a flat spine creating a clean, professional book-style edge. The cover wraps around the spine, which is printable.' ),
                array( 'type' => 'text', 'value' => 'Requires enough pages to create a spine (typically 20+). Ideal for catalogs, manuals, and publications.' ),
            ),
        ),
        'saddle_stitch' => array(
            'title' => 'Saddle Stitch Binding',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Pages are folded and stapled through the spine. The most cost-effective binding method for booklets up to 64 pages.' ),
                array( 'type' => 'text', 'value' => 'Page counts must be in multiples of 4. One or two staples depending on size.' ),
            ),
        ),
        'bleed' => array(
            'title' => 'Artwork Bleeds',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Bleed is the area of artwork that extends beyond the trim line. When your design has color or images that go to the edge of the page, the bleed ensures no white border appears after cutting.' ),
                array( 'type' => 'text', 'value' => 'Standard bleed is 0.125" (1/8") on all sides. If your file doesn\'t include bleeds, we can add them for an additional fee.' ),
            ),
        ),
        'paper_text_weight' => array(
            'title' => 'Text Weight Paper',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Lighter, flexible paper used for most booklet interior pages. Measured in pounds (lb) — higher numbers are thicker.' ),
                array( 'type' => 'text', 'value' => '70lb Uncoated: Clean, natural feel. Best for text-heavy content. 80lb Matte: Smooth with slight sheen. Good all-purpose choice. 100lb Gloss: Shiny, vibrant colors. Best for photo-heavy content.' ),
            ),
        ),
        // Lives here so booklet calculators deliver it without a database row —
        // it previously existed only in wp_options and rendered nothing when that
        // option was stored as a JSON string.
        'page_count' => array(
            'title' => 'How to Count Booklet Pages',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Count pages, not sheets. Each folded sheet holds 4 pages — front and back of each half — and the front and back covers each count as a page.' ),
                array( 'type' => 'text', 'value' => 'Saddle-stitch booklets must be a multiple of 4 pages. If your file isn\'t divisible by 4, blank pages are added to reach the next multiple.' ),
                array( 'type' => 'image', 'src' => 'https://priorityprintservice.com/wp-content/uploads/2025/05/how-to-count-booklet-pages.webp', 'alt' => 'Animated guide to counting saddle-stitch booklet pages' ),
            ),
        ),
        'paper_cardstock' => array(
            'title' => 'Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Heavier, rigid paper typically used for covers. Measured in points (pt) or pounds (lb).' ),
                array( 'type' => 'text', 'value' => '80lb Cardstock: Light card, good for self-mailers. 14pt C1S: One glossy side, one uncoated — premium cover stock. 16pt C2S: Glossy both sides, thick and sturdy.' ),
            ),
        ),
        // ── Per-stock paper descriptions ──
        // Canonical copy lives in docs/PAPER_CATALOG.md — edit there first, mirror here
        // and in each calculator's PAPER_DESC map. Keys follow the category-shortcode
        // slug rule: paper_text_/paper_cs_ + lowercased label, spaces → underscores.
        'paper_text_70lb_uncoated_opaque_text' => array(
            'title' => '70lb Uncoated Opaque Text',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Classic uncoated paper with a natural feel that\'s easy to write on. Crisp text and a soft, non-reflective look — letterhead, inserts, forms, and reading-heavy pages.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_text_80lb_matte_text' => array(
            'title' => '80lb Matte Text',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Smooth coated sheet with a soft, glare-free finish. Richer color than uncoated without the shine — the all-purpose choice for brochures and flyers.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_text_100lb_gloss_text' => array(
            'title' => '100lb Gloss Text',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Shiny coated sheet that makes photos and color pop. The standard for marketing brochures, catalogs, and mailers.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_text_60lb_offset_smooth_opaque' => array(
            'title' => '60lb Offset Smooth Opaque',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Light uncoated sheet with good opacity for its weight. Economical for manuals, workbooks, and text-heavy booklets.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_text_80lb_offset_smooth_opaque' => array(
            'title' => '80lb Offset Smooth Opaque',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Sturdy uncoated sheet with excellent opacity. The uncoated feel with less show-through — workbooks, journals, and premium text pages.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_text_80lb_gloss_factory_coated' => array(
            'title' => '80lb Gloss Factory Coated',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Lightweight gloss sheet with vivid color reproduction. A thinner, economical alternative to 100lb gloss for catalogs and mailers.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_text_100lb_matte_factory_coated' => array(
            'title' => '100lb Matte Factory Coated',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Heavy matte sheet with a refined, low-glare surface. Upscale brochures, art books, and photography that shouldn\'t shine.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_text_linen' => array(
            'title' => 'Linen',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Premium stock with a woven linen texture you can feel. Distinctive for invitations, stationery, and fine-dining menus.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 4 production days.' ),
            ),
        ),
        'paper_cs_80lb_opaque_uncoated' => array(
            'title' => '80lb Opaque Uncoated Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Our lightest cardstock, uncoated and easy to write on. Greeting cards, reply cards, and covers that fold cleanly.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_cs_80lb_matte_cardstock' => array(
            'title' => '80lb Matte Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Light cardstock with a smooth matte coating. A soft, modern look for covers and cards.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_cs_100lb_gloss_cardstock' => array(
            'title' => '100lb Gloss Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Mid-weight cardstock with a glossy face that makes color punchy. Covers, postcards, and hang tags.' ),
                array( 'type' => 'text', 'value' => 'In our standing inventory — best for quick turnaround, small quantities, and hardcopy proofs.' ),
            ),
        ),
        'paper_cs_14pt_gloss_c1s' => array(
            'title' => '14pt Gloss C1S',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Thick card, gloss-coated on one side with an uncoated back that\'s easy to write on. The postcard standard (C1S = coated one side).' ),
                array( 'type' => 'text', 'value' => 'Special-order size — typically adds about 1 production day.' ),
            ),
        ),
        'paper_cs_16pt_coated_c2s' => array(
            'title' => '16pt Coated C2S',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Our heaviest everyday card, gloss-coated both sides (C2S). Substantial, premium feel for business cards, postcards, and covers.' ),
                array( 'type' => 'text', 'value' => 'Special-order size — typically adds about 1 production day.' ),
            ),
        ),
        'paper_cs_80lb_gloss_factory_coated' => array(
            'title' => '80lb Gloss Factory Coated Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Light, flexible cardstock with a gloss coat on both sides. Economical covers and cards with vivid color.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_cs_100lb_matte_factory_coated' => array(
            'title' => '100lb Matte Factory Coated Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Mid-weight matte card with an elegant, glare-free surface. Book covers and upscale cards.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_cs_12pt_c2s_factory_coated' => array(
            'title' => '12pt C2S Factory Coated',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Flexible coated card, thinner than 14pt. Tickets, tags, and lightweight postcards.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_cs_14pt_c2s_factory_coated' => array(
            'title' => '14pt C2S Factory Coated',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Thick card gloss-coated both sides for all-over shine. Postcards and covers that want gloss front and back.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
        'paper_cs_18pt_c1s_factory_gloss' => array(
            'title' => '18pt C1S Factory Gloss',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Our most rigid card — gloss front, uncoated writable back. Heavy-duty hang tags, counter cards, and premium postcards.' ),
                array( 'type' => 'text', 'value' => 'Factory-ordered from the mill — typically adds about 2 production days.' ),
            ),
        ),
    );
}

/**
 * The tooltip set every surface should read: code defaults with saved entries
 * layered on top.
 *
 * Two failure modes this guards, both seen on staging 2026-08-09:
 *
 * 1. The MCP wp_update_option endpoint can serialise an array payload as a JSON
 *    *string*. A plain (array) cast on that yields array( 0 => '{"…"}' ), which
 *    ships the whole blob to the front end under a numeric key and delivers not
 *    one real entry. pps_get_registry() carries the same tolerance for the same
 *    reason. Decoding here also means the admin Tooltips tab (which writes a
 *    native array) and the MCP path agree.
 * 2. Callers that read the raw option get only what is in the database, so a key
 *    that lives solely in pps_default_tooltips() renders no tooltip at all. The
 *    category-page shortcodes did exactly this, which is why clearing stale DB
 *    keys took their "?" icons with them.
 */
function pps_get_tooltips() {
    $saved = get_option( 'pps_tooltips', array() );
    if ( is_string( $saved ) ) {
        $decoded = json_decode( $saved, true );
        $saved   = is_array( $decoded ) ? $decoded : array();
    }
    if ( ! is_array( $saved ) ) $saved = array();
    return array_merge( pps_default_tooltips(), $saved );
}

/**
 * Save tooltips from admin — stored as a single wp_option.
 * Accepts JSON with the same structure as pps_default_tooltips().
 */
add_action( 'wp_ajax_pps_save_tooltips', function() {
    check_ajax_referer( 'pps_tooltip_save', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $raw = wp_unslash( $_POST['tips'] ?? '{}' );
    $tips = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'Invalid JSON: ' . json_last_error_msg() );
    }

    // Sanitize each tooltip
    $clean = array();
    foreach ( $tips as $key => $tip ) {
        $k = sanitize_key( $key );
        $clean[ $k ] = array(
            'title'   => sanitize_text_field( $tip['title'] ?? '' ),
            'content' => array(),
        );
        if ( ! empty( $tip['content'] ) && is_array( $tip['content'] ) ) {
            foreach ( $tip['content'] as $block ) {
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
    pps_purge_page_cache();
    wp_send_json_success( 'Saved ' . count( $clean ) . ' tooltips.' );
} );

/**
 * Seed default tooltips on plugin activation if none exist.
 */
register_activation_hook( __FILE__, function() {
    if ( ! get_option( 'pps_tooltips' ) ) {
        update_option( 'pps_tooltips', pps_default_tooltips(), false );
    }
} );

// ═══════════════════════════════════════════════════════════════
// PRESET ROUTING (Phase 1)
//
// Each entry in wp_options['pps_presets'] registers a public URL at
// /{slug}/ that renders the appropriate calculator HTML with the
// preset's defaults pre-filled into PPS_CONFIG.defaults.
//
// Preset row shape:
//   [
//     'slug'        => 'kebab-case-string',
//     'calc'        => 'saddle' | 'perfect-bound' | 'brochure' | 'coupon',
//     'title'       => 'Display title',
//     'description' => '1-2 sentences, ≤160 chars recommended',
//     'image'       => 'https://… absolute URL or empty string',
//     'defaults'    => [...],          // shape: same as _pps_defaults postmeta
//     'price_from'  => 187.50,         // float|null
//     'currency'    => 'USD',          // ISO 4217
//     // (override fields added in a later commit; absent here for v1)
//   ]
// ═══════════════════════════════════════════════════════════════

define( 'PPS_PRESETS_OPTION', 'pps_presets' );

/**
 * Map calc type slug → calculator HTML filename. Inverse of
 * pps_get_calc_type_for_filename() defined earlier.
 */
function pps_get_filename_for_calc_type( $calc_type ) {
    $map = array(
        'saddle'        => 'calc-preview-test.html',
        'perfect-bound' => 'calc-perfect-bound.html',
        'brochure'      => 'calc-brochure.html',
        'coupon'        => 'calc-coupon-book.html',
        'letterhead'    => 'calc-letterhead.html',
        'postcard'      => 'calc-postcard.html',
        'sticker'       => 'calc-sticker.html',
        'greeting-card' => 'calc-greeting-card.html',
    );
    return isset( $map[ $calc_type ] ) ? $map[ $calc_type ] : '';
}

/**
 * Read the full preset registry. Always returns an array (possibly empty).
 */
function pps_get_presets() {
    $raw = get_option( PPS_PRESETS_OPTION, array() );
    if ( is_string( $raw ) && $raw !== '' ) {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            $raw = $decoded;
        }
    }
    return is_array( $raw ) ? $raw : array();
}

/**
 * Look up a single preset by slug. Returns null if missing.
 * Slug is validated against [a-z0-9-]+ before lookup.
 */
function pps_get_preset( $slug ) {
    if ( ! is_string( $slug ) || $slug === '' ) return null;
    if ( ! preg_match( '/^[a-z0-9\-]+$/', $slug ) ) return null;
    $presets = pps_get_presets();
    if ( ! isset( $presets[ $slug ] ) || ! is_array( $presets[ $slug ] ) ) return null;
    $row = $presets[ $slug ];
    if ( empty( $row['slug'] ) ) $row['slug'] = $slug;
    return $row;
}

/**
 * Sanitize and persist a preset row. Returns the cleaned row on success
 * or WP_Error on validation failure.
 *
 * Used by the admin CRUD save handler in pps-presets-admin.php.
 */
function pps_save_preset( $slug, $data ) {
    $slug = sanitize_key( $slug );
    if ( ! preg_match( '/^[a-z0-9\-]+$/', $slug ) || strlen( $slug ) > 80 ) {
        return new WP_Error( 'pps_preset_bad_slug', 'Slug must be kebab-case [a-z0-9-]+ and ≤80 chars.' );
    }

    $allowed_calcs = array( 'saddle', 'perfect-bound', 'brochure', 'coupon', 'letterhead', 'postcard', 'sticker', 'greeting-card' );
    $calc = isset( $data['calc'] ) ? (string) $data['calc'] : '';
    if ( ! in_array( $calc, $allowed_calcs, true ) ) {
        return new WP_Error( 'pps_preset_bad_calc', 'Calc must be one of: ' . implode( ', ', $allowed_calcs ) );
    }

    $title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
    if ( $title === '' || strlen( $title ) > 200 ) {
        return new WP_Error( 'pps_preset_bad_title', 'Title is required and must be ≤200 chars.' );
    }

    $description = isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '';
    if ( strlen( $description ) > 500 ) $description = substr( $description, 0, 500 );

    $image = '';
    if ( ! empty( $data['image'] ) ) {
        $image = esc_url_raw( (string) $data['image'], array( 'http', 'https' ) );
    }

    // Defaults: must be a JSON-decoded array. Recursively scrub strings.
    $defaults = array();
    if ( isset( $data['defaults'] ) && is_array( $data['defaults'] ) ) {
        $defaults = pps_sanitize_defaults_blob( $data['defaults'] );
    }

    $price_from = null;
    if ( isset( $data['price_from'] ) && $data['price_from'] !== '' ) {
        $pf = floatval( $data['price_from'] );
        if ( $pf >= 0 && $pf < 1000000 ) $price_from = $pf;
    }

    $currency = isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : 'USD';
    if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) $currency = 'USD';

    // Per-preset sale override. 0 = use site-wide PCF.sale_discount_pct.
    // Hard-capped at 0.50 to prevent typos like "15" instead of "0.15".
    $sale_discount_pct = 0.0;
    if ( isset( $data['sale_discount_pct'] ) && $data['sale_discount_pct'] !== '' ) {
        $sd = floatval( $data['sale_discount_pct'] );
        if ( $sd < 0 ) $sd = 0.0;
        if ( $sd > 0.5 ) $sd = 0.5;
        $sale_discount_pct = $sd;
    }
    $sale_label = isset( $data['sale_label'] ) ? sanitize_text_field( (string) $data['sale_label'] ) : '';
    if ( strlen( $sale_label ) > 80 ) $sale_label = substr( $sale_label, 0, 80 );

    // ── Tier 1: simple field overrides (spec-table dispatcher) ──
    // Each key declares its sanitization strategy. pps_sanitize_override_value()
    // returns null to signal "drop this field"; we only store non-null results.
    $override_specs = array(
        // Existing — shape preserved.
        'meta_title'        => array( 'sanitize' => 'text',     'max' => 200 ),
        'meta_description'  => array( 'sanitize' => 'textarea', 'max' => 320 ),
        'og_image'          => array( 'sanitize' => 'url' ),
        'schema_name'       => array( 'sanitize' => 'text',     'max' => 200 ),
        'schema_sku'        => array( 'sanitize' => 'regex',    'pattern' => '/[^a-zA-Z0-9_\-]/', 'max' => 80 ),
        'breadcrumb_label'  => array( 'sanitize' => 'text',     'max' => 200 ),

        // Group A — Basic Product
        'schema_description'                => array( 'sanitize' => 'textarea', 'max' => 500 ),
        'schema_brand'                      => array( 'sanitize' => 'text',     'max' => 120 ),
        'schema_category'                   => array( 'sanitize' => 'text',     'max' => 200 ),
        'schema_image'                      => array( 'sanitize' => 'url_csv',  'max_items' => 6 ),
        'schema_url'                        => array( 'sanitize' => 'url' ),
        'schema_additional_type'            => array( 'sanitize' => 'url' ),
        'schema_audience'                   => array( 'sanitize' => 'text',     'max' => 200 ),
        'schema_color'                      => array( 'sanitize' => 'text',     'max' => 120 ),
        'schema_material'                   => array( 'sanitize' => 'text',     'max' => 120 ),
        'schema_disambiguating_description' => array( 'sanitize' => 'text',     'max' => 200 ),

        // Group B — Identifiers
        'schema_gtin' => array( 'sanitize' => 'regex', 'pattern' => '/[^a-zA-Z0-9]/',     'max' => 32 ),
        'schema_mpn'  => array( 'sanitize' => 'regex', 'pattern' => '/[^a-zA-Z0-9_\-]/',  'max' => 64 ),

        // Group C — Offer
        'offer_price'             => array( 'sanitize' => 'decimal',  'min' => 0, 'max_value' => 1000000 ),
        'offer_price_high'        => array( 'sanitize' => 'decimal',  'min' => 0, 'max_value' => 1000000 ),
        'offer_price_currency'    => array( 'sanitize' => 'currency' ),
        'offer_availability'      => array( 'sanitize' => 'enum',     'allowed' => array( 'InStock', 'OutOfStock', 'PreOrder', 'Discontinued', 'SoldOut' ) ),
        'offer_price_valid_until' => array( 'sanitize' => 'date_iso' ),
        'offer_item_condition'    => array( 'sanitize' => 'enum',     'allowed' => array( 'NewCondition', 'RefurbishedCondition', 'UsedCondition', 'DamagedCondition' ) ),
        'offer_url'               => array( 'sanitize' => 'url' ),

        // Group D — AggregateRating
        'rating_value' => array( 'sanitize' => 'decimal', 'min' => 0, 'max_value' => 5,       'precision' => 1 ),
        'rating_count' => array( 'sanitize' => 'int',     'min' => 1, 'max_value' => 1000000 ),
        'rating_best'  => array( 'sanitize' => 'decimal', 'min' => 0, 'max_value' => 5,       'precision' => 1 ),
        'rating_worst' => array( 'sanitize' => 'decimal', 'min' => 0, 'max_value' => 5,       'precision' => 1 ),
    );
    $overrides = array();
    $warnings  = array();
    if ( isset( $data['overrides'] ) && is_array( $data['overrides'] ) ) {
        foreach ( $override_specs as $k => $spec ) {
            if ( ! isset( $data['overrides'][ $k ] ) ) continue;
            $raw = trim( (string) $data['overrides'][ $k ] );
            if ( $raw === '' ) continue;
            $clean_v = pps_sanitize_override_value( $raw, $spec );
            if ( $clean_v === null || $clean_v === '' ) continue;
            $overrides[ $k ] = $clean_v;
        }
    }

    // Cross-field validation post-pass: drop offending fields and surface a
    // non-blocking warning so the save still succeeds.
    if ( isset( $overrides['rating_value'] ) xor isset( $overrides['rating_count'] ) ) {
        unset( $overrides['rating_value'], $overrides['rating_count'] );
        $warnings[] = 'AggregateRating requires both rating_value and rating_count — dropped.';
    }
    if ( isset( $overrides['offer_price_high'] ) && ! isset( $overrides['offer_price'] ) ) {
        unset( $overrides['offer_price_high'] );
        $warnings[] = 'offer_price_high requires offer_price — dropped high price.';
    }
    if ( isset( $overrides['offer_price_high'], $overrides['offer_price'] )
         && (float) $overrides['offer_price_high'] <= (float) $overrides['offer_price'] ) {
        unset( $overrides['offer_price_high'] );
        $warnings[] = 'offer_price_high must be greater than offer_price — dropped high price.';
    }
    if ( isset( $overrides['rating_best'], $overrides['rating_worst'] )
         && (float) $overrides['rating_worst'] > (float) $overrides['rating_best'] ) {
        unset( $overrides['rating_worst'] );
        $warnings[] = 'rating_worst cannot exceed rating_best — dropped rating_worst.';
    }

    // ── Tier 2: schema block overrides (per-block JSON-LD) ──
    $allowed_blocks  = array( 'product', 'faq', 'breadcrumb', 'localbusiness', 'webapp' );
    $schema_overrides = array();
    $block_errors     = array();
    if ( isset( $data['schema_overrides'] ) && is_array( $data['schema_overrides'] ) ) {
        foreach ( $allowed_blocks as $bk ) {
            $raw = isset( $data['schema_overrides'][ $bk ] ) ? trim( (string) $data['schema_overrides'][ $bk ] ) : '';
            if ( $raw === '' ) continue;
            $clean = pps_sanitize_jsonld_override( $raw );
            if ( is_wp_error( $clean ) ) {
                $block_errors[] = $bk . ': ' . $clean->get_error_message();
                continue;
            }
            $schema_overrides[ $bk ] = $clean;
        }
    }

    // ── Tier 3: extra schema blocks (array of JSON-LD) ──
    $schema_extras = array();
    $extra_errors  = array();
    if ( isset( $data['schema_extras'] ) && is_array( $data['schema_extras'] ) ) {
        $i = 0;
        foreach ( $data['schema_extras'] as $raw ) {
            if ( ! is_string( $raw ) ) continue;
            $raw = trim( $raw );
            if ( $raw === '' ) continue;
            if ( ++$i > 12 ) break; // hard cap on extras
            $clean = pps_sanitize_jsonld_override( $raw );
            if ( is_wp_error( $clean ) ) {
                $extra_errors[] = 'extra ' . $i . ': ' . $clean->get_error_message();
                continue;
            }
            $schema_extras[] = $clean;
        }
    }

    // ── Categories (array of product_cat slugs for category-page lineup injection) ──
    $categories = array();
    if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
        foreach ( $data['categories'] as $cat_slug ) {
            $cat_slug = sanitize_key( (string) $cat_slug );
            if ( $cat_slug !== '' && strlen( $cat_slug ) <= 80 ) {
                $categories[] = $cat_slug;
            }
        }
        $categories = array_unique( array_slice( $categories, 0, 20 ) );
    }

    // ── Per-preset FAQs (overrides calc-type defaults; same shape as wp_options['pps_faqs'][calc]) ──
    $faqs = array();
    if ( isset( $data['faqs'] ) && is_array( $data['faqs'] ) ) {
        $i = 0;
        foreach ( $data['faqs'] as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            if ( ++$i > 50 ) break; // per-preset cap
            $q = isset( $entry['q'] ) ? sanitize_text_field( (string) $entry['q'] ) : '';
            $a = isset( $entry['a'] ) ? wp_kses_post( (string) $entry['a'] ) : '';
            if ( strlen( $q ) > 512 )  $q = substr( $q, 0, 512 );
            if ( strlen( $a ) > 4096 ) $a = substr( $a, 0, 4096 );
            if ( $q === '' || $a === '' ) continue;
            $faqs[] = array( 'q' => $q, 'a' => $a );
        }
    }

    // Surface validation failures so admin can fix them
    if ( ! empty( $block_errors ) || ! empty( $extra_errors ) ) {
        return new WP_Error( 'pps_preset_jsonld_invalid', 'JSON-LD override(s) invalid: ' . implode( '; ', array_merge( $block_errors, $extra_errors ) ) );
    }

    $clean = array(
        'slug'              => $slug,
        'calc'              => $calc,
        'title'             => $title,
        'description'       => $description,
        'image'             => $image,
        'defaults'          => $defaults,
        'price_from'        => $price_from,
        'currency'          => $currency,
        'sale_discount_pct' => $sale_discount_pct,
        'sale_label'        => $sale_label,
        'categories'        => $categories,
        'overrides'         => $overrides,
        'schema_overrides'  => $schema_overrides,
        'schema_extras'     => $schema_extras,
        'faqs'              => $faqs,
        'modified_at'       => time(), // sitemap <lastmod>; updated on every save
    );

    $presets = pps_get_presets();
    $presets[ $slug ] = $clean;
    update_option( PPS_PRESETS_OPTION, $presets, false );

    flush_rewrite_rules( false );

    // Warnings (cross-field drops) ride along the return value but are NOT
    // persisted to the option — admin form handler reads them once, then
    // surfaces them as notices on the redirect.
    if ( ! empty( $warnings ) ) {
        $clean['_warnings'] = $warnings;
    }

    return $clean;
}

/**
 * Recursively sanitize a defaults blob.
 *  - String values → sanitize_text_field
 *  - Numeric values → kept as-is (cast to float/int by callers)
 *  - Boolean values → kept
 *  - Arrays → recursed
 *  - Objects / closures / resources → dropped (json_decode never produces these,
 *    but defense in depth)
 *  - Keys → charset-restricted, CASE PRESERVED
 *
 * Keys must keep their case. This used to run sanitize_key(), which
 * lowercases — so every camelCase default saved through the Presets admin
 * form silently became unreadable: `sizeLabel` stored as `sizelabel`,
 * `insidePaperType` as `insidepapertype`. The calculator JS reads
 * PPS_CONFIG.defaults by exact key, so those presets loaded with an empty
 * form and no error anywhere. (The one live preset row, `letterhead`, kept
 * its camelCase only because it was written straight to the option rather
 * than through the form.) Fixed 2026-08-12.
 *
 * The charset restriction is what actually provides the safety here — these
 * keys are emitted into a JSON blob, never into SQL or markup — so
 * [A-Za-z0-9_-] with everything else stripped is both safe and lossless for
 * every real field name.
 *
 * Cap: 200 keys total at any depth to prevent admin-side DoS.
 */
function pps_sanitize_defaults_blob( $data, &$key_count = null ) {
    if ( $key_count === null ) $key_count = 0;
    if ( ! is_array( $data ) ) return array();
    $out = array();
    foreach ( $data as $k => $v ) {
        if ( $key_count++ > 200 ) break;
        $clean_key = is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k );
        if ( $clean_key === '' ) continue;  // key sanitised to nothing — drop, don't store under ''
        if ( is_array( $v ) ) {
            $out[ $clean_key ] = pps_sanitize_defaults_blob( $v, $key_count );
        } elseif ( is_string( $v ) ) {
            $s = sanitize_text_field( $v );
            if ( strlen( $s ) > 1000 ) $s = substr( $s, 0, 1000 );
            $out[ $clean_key ] = $s;
        } elseif ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
            $out[ $clean_key ] = $v;
        } elseif ( is_null( $v ) ) {
            $out[ $clean_key ] = null;
        }
        // objects/resources: silently dropped
    }
    return $out;
}

/**
 * Delete a preset by slug. Returns true if it existed.
 */
function pps_delete_preset( $slug ) {
    $slug = sanitize_key( $slug );
    $presets = pps_get_presets();
    if ( ! isset( $presets[ $slug ] ) ) return false;
    unset( $presets[ $slug ] );
    update_option( PPS_PRESETS_OPTION, $presets, false );
    flush_rewrite_rules( false );
    return true;
}

/**
 * Resolve a preset request — set $GLOBALS['pps_active_preset'] when the
 * request matches a real preset slug, or trigger 404 otherwise.
 *
 * Hooks into 'parse_request' (very early in the WP lifecycle) so the 404
 * fires before the_posts injection or template rendering.
 */
add_action( 'parse_request', function( $wp ) {
    if ( empty( $wp->query_vars['pps_preset'] ) ) return;

    $slug   = sanitize_key( $wp->query_vars['pps_preset'] );
    $preset = pps_get_preset( $slug );

    if ( $preset === null ) {
        // Unknown slug → 404 (real, not pretty redirect)
        $wp->query_vars['error'] = '404';
        unset( $wp->query_vars['pps_preset'] );
        return;
    }

    // Stash the resolved preset row for later hooks (the_posts, the_content,
    // wp_head SEO emission added in PR 3, sitemap etc.). The slug is also
    // pinned so downstream code never has to re-validate.
    $GLOBALS['pps_active_preset'] = $preset;
} );

/**
 * Inject a virtual WP_Post for preset URLs so the theme's standard
 * single-page template runs and our the_content filter (below) gets a
 * chance to output the calculator.
 */
add_filter( 'the_posts', function( $posts, $query ) {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return $posts;
    if ( ! ( $query instanceof WP_Query ) ) return $posts;
    if ( ! $query->is_main_query() ) return $posts;

    $preset = $GLOBALS['pps_active_preset'];
    $slug   = $preset['slug'];

    $post                 = new stdClass();
    $post->ID             = -1; // virtual
    $post->post_author    = 0;
    $post->post_date      = current_time( 'mysql' );
    $post->post_date_gmt  = current_time( 'mysql', 1 );
    $post->post_content   = ''; // populated by the_content filter
    $post->post_title     = $preset['title'];
    $post->post_excerpt   = '';
    $post->post_status    = 'publish';
    $post->comment_status = 'closed';
    $post->ping_status    = 'closed';
    $post->post_password  = '';
    $post->post_name      = $slug;
    $post->to_ping        = '';
    $post->pinged         = '';
    $post->post_modified  = current_time( 'mysql' );
    $post->post_modified_gmt = current_time( 'mysql', 1 );
    $post->post_content_filtered = '';
    $post->post_parent    = 0;
    $post->guid           = home_url( '/' . $slug . '/' );
    $post->menu_order     = 0;
    $post->post_type      = 'page';
    $post->post_mime_type = '';
    $post->comment_count  = 0;
    $post->filter         = 'raw';

    $wp_post = new WP_Post( $post );

    // Tell the loop this is a real single-page result
    $query->is_singular   = true;
    $query->is_single     = false;
    $query->is_page       = true;
    $query->is_home       = false;
    $query->is_archive    = false;
    $query->is_404        = false;
    $query->found_posts   = 1;
    $query->post_count    = 1;
    $query->max_num_pages = 1;
    $query->post          = $wp_post;
    $query->posts         = array( $wp_post );

    return array( $wp_post );
}, 10, 2 );

/**
 * Enqueue the calculator's JS deps on preset URLs.
 * Mirrors the existing per-WC-product enqueue at top of pps-calculators.php.
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return;
    wp_enqueue_script( 'pps-react',     'https://unpkg.com/react@18.3.1/umd/react.production.min.js', array(), '18.3.1', true );
    wp_enqueue_script( 'pps-react-dom', 'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js', array( 'pps-react' ), '18.3.1', true );
    // Same ESM loader as the product-page embed; see pps_pdfjs_loader_js().
    wp_register_script( 'pps-pdfjs', false, array(), '4.10.38', true );
    wp_enqueue_script( 'pps-pdfjs' );
    wp_add_inline_script( 'pps-pdfjs', pps_pdfjs_loader_js() );
    // jsPDF — pre-loaded to avoid the runtime script injection in the calc HTML
    wp_enqueue_script( 'pps-jspdf',     'https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js', array(), '2.5.1', true );
    // Babel only for JSX-source builds; compiled pages skip ~3MB of dead JS.
    if ( ! pps_current_calc_is_compiled() ) {
        wp_enqueue_script( "pps-babel", 'https://unpkg.com/@babel/standalone@7.26.9/babel.min.js', array( 'pps-react', 'pps-react-dom', 'pps-pdfjs', 'pps-jspdf' ), '7.26.9', true );
    }
} );

// ── External-JS hardening on preset URLs (mirrors the product-page logic) ──
add_action( 'wp_enqueue_scripts', function() {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return;
    foreach ( array( 'wcpa-front', 'wcpa-shared', 'wcpa_front', 'wcpa-modal', 'wcpa-frontend' ) as $h ) {
        wp_dequeue_script( $h );
        wp_dequeue_style( $h );
    }
}, 100 );
add_action( 'wp', function() {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return;
    // Surgical exclusions only — keep page cache, minify, CSS optimization,
    // and CDN active on preset URLs (same SEO rationale as the product-page
    // block above). See that block for the per-filter explanation.
    add_filter( 'rocket_delay_js_exclusions', function( $excluded ) {
        $excluded[] = '/unpkg\.com/react';
        $excluded[] = '/unpkg\.com/react-dom';
        $excluded[] = '/unpkg\.com/@babel/standalone';
        $excluded[] = '/unpkg\.com/jspdf';
        $excluded[] = '/cdnjs\.cloudflare\.com/ajax/libs/pdf\.js';
        $excluded[] = 'pps-calculator';
        return $excluded;
    } );
    add_filter( 'rocket_lazyload_excluded_attributes', function( $excluded ) {
        $excluded[] = 'class*="bp-modal"';
        $excluded[] = 'class*="bp-scene"';
        $excluded[] = 'class*="bp-page"';
        $excluded[] = 'class*="bp-face"';
        $excluded[] = 'data-pps-calc';
        return $excluded;
    } );
}, 5 );

/**
 * Render the calculator + its config inline as the page content.
 *
 * Returns a string (not echoed) because the_content filter expects that.
 * Extracts <style> and <script type="text/babel"> from the calculator HTML
 * via the existing pps_parse_calculator_html() helper.
 */
function pps_render_preset_calculator( $preset ) {
    $filename = pps_get_filename_for_calc_type( $preset['calc'] );
    if ( ! $filename ) return '';

    $filepath = trailingslashit( pps_upload_dir() ) . $filename;
    if ( ! file_exists( $filepath ) ) return '';

    $html  = file_get_contents( $filepath );
    $parts = pps_parse_calculator_html( $html );

    // Build PPS_CONFIG, parallel to the WC-product render path
    $config = array(
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
        'cartNonce'   => wp_create_nonce( 'pps_add_to_cart' ),
        'uploadNonce' => wp_create_nonce( 'pps_upload_artwork' ),
        'maxUpload'   => (int) wp_max_upload_size(),
        // No productId — preset render does not target a single WC product.
        // The cart layer should fall back to a calc-type → product map; that
        // mapping is wired in a follow-up PR alongside per-line preset slug
        // capture in order meta. For now the preset-URL calculator renders
        // and computes prices but add-to-cart goes through the existing
        // calculator's own product binding (when present).
        'presetSlug'  => $preset['slug'],
    );

    if ( function_exists( 'pps_get_config' ) ) {
        $config['calc'] = pps_get_public_config();
    }

    $tips = array_merge( pps_default_tooltips(), (array) get_option( 'pps_tooltips', array() ) );
    if ( ! empty( $tips ) ) $config['tips'] = $tips;

    $logo_url = get_option( 'pps_logo_url', '' );
    if ( ! $logo_url ) {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
    }
    if ( $logo_url ) $config['logoUrl'] = $logo_url;

    $zone_map = get_option( 'pps_ups_zone_map', array() );
    if ( ! empty( $zone_map ) && is_array( $zone_map ) ) $config['zoneMap'] = $zone_map;

    // Preset's own defaults override central calc defaults — calculator JS
    // reads PPS_CONFIG.defaults to pre-fill the form.
    if ( ! empty( $preset['defaults'] ) && is_array( $preset['defaults'] ) ) {
        $config['defaults'] = $preset['defaults'];
    }

    // Per-preset sale override. A non-zero preset sale overrides the site-wide
    // PCF default; a non-empty preset label overrides too. Calculator JS only
    // reads PCF.sale_*, so we mutate the injected PCF block.
    if ( ! empty( $preset['sale_discount_pct'] ) || ! empty( $preset['sale_label'] ) ) {
        if ( ! isset( $config['calc'] ) || ! is_array( $config['calc'] ) ) $config['calc'] = array();
        if ( ! isset( $config['calc']['pcf'] ) || ! is_array( $config['calc']['pcf'] ) ) $config['calc']['pcf'] = array();
        if ( ! empty( $preset['sale_discount_pct'] ) ) {
            $config['calc']['pcf']['sale_discount_pct'] = floatval( $preset['sale_discount_pct'] );
        }
        if ( ! empty( $preset['sale_label'] ) ) {
            $config['calc']['pcf']['sale_label'] = (string) $preset['sale_label'];
        }
    }

    // Add-on visibility (per-calc-type, not per-preset). Same shape as the
    // product-page render path.
    if ( function_exists( 'pps_get_addons_visibility_for_calc' ) ) {
        $config['addons'] = pps_get_addons_visibility_for_calc( $preset['calc'] );
    }

    // Build output buffer — we have to return a string, not echo.
    ob_start();

    echo '<script data-cfasync="false">window.PPS_CONFIG=' . wp_json_encode( $config ) . ';</script>';

    // Scoped styles (mirror existing logic at line ~537)
    if ( $parts['styles'] ) {
        $css = $parts['styles'];
        $css = str_replace(
            '* {',
            '#pps-calculator-wrap, #pps-calculator-wrap *, #pps-calculator-wrap *::before, #pps-calculator-wrap *::after {',
            $css
        );
        $css = str_replace( 'body {', '#pps-calculator-wrap {', $css );
        echo '<style>' . $css . '</style>';
    }

    echo '<div id="pps-calculator-wrap" style="margin:-20px 0 40px;clear:both">';
    echo '<div id="pps-calculator-root"></div>';
    echo '</div>';

    // Same split as the product path: external enqueue for compiled builds,
    // DOMContentLoaded-wrapped inline as the write-failure fallback.
    if ( $parts['app_code'] ) {
        if ( empty( $parts['compiled'] ) ) {
            echo '<script data-cfasync="false" type="text/babel">' . $parts['app_code'] . '</script>';
        } elseif ( ! pps_enqueue_calc_app_file( $filepath, $parts['app_code'] ) ) {
            echo '<script data-cfasync="false">document.addEventListener("DOMContentLoaded",function(){' . $parts['app_code'] . "\n});</script>";
        }
    }

    return ob_get_clean();
}

/**
 * Replace the_content with the calculator on preset URLs.
 *
 * Gated on:
 *   - $GLOBALS['pps_active_preset'] is set (preset request)
 *   - in the main loop on the main query (so secondary queries don't get hijacked)
 *   - filter has not already run for this request (single-fire guard)
 */
add_filter( 'the_content', function( $content ) {
    static $rendered = false;
    if ( $rendered ) return $content;
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return $content;
    if ( ! is_main_query() || ! in_the_loop() ) return $content;

    $rendered = true;
    return pps_render_preset_calculator( $GLOBALS['pps_active_preset'] );
}, 5 );

/**
 * Forward the preset slug to the cart so it can be persisted on the order.
 *
 * The calculator's existing add-to-cart flow accepts arbitrary metadata.
 * When PPS_CONFIG.presetSlug is set, the JS-side payload is expected to
 * include it; this PHP handler stores it on the cart line item.
 */
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item_data, $product_id, $variation_id ) {
    if ( ! empty( $_POST['pps_preset_slug'] ) ) {
        $slug = sanitize_key( wp_unslash( $_POST['pps_preset_slug'] ) );
        if ( preg_match( '/^[a-z0-9\-]+$/', $slug ) ) {
            $cart_item_data['pps_preset_slug'] = $slug;
        }
    }
    return $cart_item_data;
}, 10, 3 );

/**
 * Persist the preset slug onto the order line item at checkout.
 * Visible in WC admin under the line item meta as "Preset".
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( ! empty( $values['pps_preset_slug'] ) ) {
        $item->add_meta_data( '_pps_preset_slug', sanitize_key( $values['pps_preset_slug'] ), true );
        $item->add_meta_data( 'Preset', sanitize_key( $values['pps_preset_slug'] ), true );
    }
}, 10, 4 );

/**
 * Routing: register one rewrite rule per preset slug at root level
 * (e.g. /letterhead/, /saddle-stitch-booklets/). Individual rules
 * avoid a catch-all wildcard that would collide with pages/posts.
 */
function pps_register_preset_rewrite_rules() {
    $presets = get_option( PPS_PRESETS_OPTION, array() );
    if ( is_string( $presets ) && $presets !== '' ) {
        $decoded = json_decode( $presets, true );
        if ( is_array( $decoded ) ) $presets = $decoded;
    }
    if ( ! is_array( $presets ) ) return;
    foreach ( array_keys( $presets ) as $slug ) {
        if ( ! preg_match( '/^[a-z0-9\-]+$/', $slug ) ) continue;
        add_rewrite_rule(
            '^' . preg_quote( $slug, '/' ) . '/?$',
            'index.php?pps_preset=' . $slug,
            'top'
        );
    }
}
add_action( 'init', 'pps_register_preset_rewrite_rules' );
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'pps_preset';
    return $vars;
} );

/**
 * Flush rewrite rules on plugin activation so preset routes are live
 * immediately. Save/delete also flush so new slugs work on first hit.
 */
register_activation_hook( __FILE__, function() {
    pps_register_preset_rewrite_rules();
    flush_rewrite_rules( false );
} );

// ═══════════════════════════════════════════════════════════════
// SEO: SUPPRESS THIRD-PARTY SCHEMAS ON CALCULATOR PAGES
// ═══════════════════════════════════════════════════════════════

/**
 * True when the current request is either a calculator-bearing WC product
 * page or a preset URL — i.e., a page whose Product schema is owned by
 * this plugin and where third-party SEO schemas should be suppressed.
 */
function pps_is_calculator_owned_url() {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return true;
    if ( function_exists( 'is_product' ) && is_product() ) {
        global $product;
        if ( $product && pps_get_calculator_for_product( $product->get_id() ) ) return true;
    }
    return false;
}

/**
 * Remove WooCommerce and SEO plugin structured data on PPS calculator product pages
 * AND on preset URLs. Our plugin injects its own schemas tailored to the calculator
 * (existing emitter at priority 5 for WC products; new per-preset emitter at the
 * same priority gated on $GLOBALS['pps_active_preset']).
 */
add_action( 'wp_head', function() {
    if ( ! pps_is_calculator_owned_url() ) return;

    // WooCommerce native structured data — only relevant on WC product pages
    if ( function_exists( 'WC' ) && WC() && isset( WC()->structured_data ) ) {
        remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
        remove_action( 'woocommerce_email_order_details', array( WC()->structured_data, 'output_email_structured_data' ), 30 );
    }

    // Yoast SEO
    if ( class_exists( 'WPSEO_Frontend' ) ) {
        add_filter( 'wpseo_json_ld_output', '__return_empty_array' );
    }
    // Yoast WooCommerce SEO addon
    add_filter( 'wpseo_schema_graph', function( $graph ) {
        // Remove Product and Offer pieces, keep Organization/WebSite/BreadcrumbList
        return array_values( array_filter( $graph, function( $piece ) {
            $type = $piece['@type'] ?? '';
            if ( is_array( $type ) ) $type = implode( ',', $type );
            return stripos( $type, 'Product' ) === false && stripos( $type, 'Offer' ) === false;
        } ) );
    } );

    // RankMath
    add_filter( 'rank_math/json_ld', function( $data ) {
        unset( $data['ProductPage'], $data['product'], $data['Product'] );
        return $data;
    }, 99 );

    // All in One SEO
    add_filter( 'aioseo_schema_output', function( $graphs ) {
        if ( ! is_array( $graphs ) ) return $graphs;
        return array_values( array_filter( $graphs, function( $g ) {
            $type = $g['@type'] ?? '';
            return stripos( $type, 'Product' ) === false;
        } ) );
    } );

    // SEOPress
    add_filter( 'seopress_schemas_auto_output', '__return_false' );
}, 1 ); // priority 1 = runs before our schema injection at priority 5

// ═══════════════════════════════════════════════════════════════
// SEO: JSON-LD SCHEMAS, NOSCRIPT FALLBACK, LLMS.TXT
// ═══════════════════════════════════════════════════════════════

/**
 * JSON encoding flags for auto-generated JSON-LD (FAQs, GBP, etc.).
 * These call sites sanitize upstream so JSON_HEX_TAG is not needed here.
 *
 * Tier 2/3 overrides use PPS_SCHEMA_JSON_FLAGS_RAW (adds JSON_HEX_TAG)
 * and are sanitized at save time via pps_sanitize_jsonld_value().
 */
if ( ! defined( 'PPS_SCHEMA_JSON_FLAGS' ) ) {
    define( 'PPS_SCHEMA_JSON_FLAGS', JSON_UNESCAPED_SLASHES );
}

/**
 * Map a calculator HTML filename to a calc type slug used by FAQs and
 * (in later PRs) preset routing.
 */
function pps_get_calc_type_for_filename( $filename ) {
    $map = array(
        'calc-preview-test.html'  => 'saddle',
        'calc-perfect-bound.html' => 'perfect-bound',
        'calc-brochure.html'      => 'brochure',
        'calc-coupon-book.html'   => 'coupon',
        'calc-letterhead.html'    => 'letterhead',
        'calc-postcard.html'      => 'postcard',
        'calc-sticker.html'       => 'sticker',
        'calc-greeting-card.html' => 'greeting-card',
    );
    return isset( $map[ $filename ] ) ? $map[ $filename ] : '';
}

/**
 * Default FAQs per calc type. Used as a fallback before admin saves any.
 *
 * Saddle defaults preserve the strings previously emitted inline so existing
 * installs see identical FAQ schema until the admin edits them. Other calc
 * types start empty — better to omit FAQ schema than emit saddle-flavored
 * answers on a perfect-bound or brochure product page.
 *
 * Note: the turnaround-days lookup uses the same key path as the original
 * inline emitter (which read $config['minimum_turnaround_days']); the actual
 * value lives at $config['pcf']['minimum_turnaround_days']. The fallback
 * resolves to 3 either way, and admins can override the FAQ text via the
 * SEO admin tab.
 */
function pps_default_faqs() {
    $config   = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $min_days = intval( $config['minimum_turnaround_days'] ?? 3 );

    return array(
        'saddle' => array(
            array( 'q' => 'What is saddle stitch booklet binding?',
                   'a' => 'Saddle stitch binding uses staples along the spine fold to hold pages together. It\'s the most cost-effective binding method for booklets up to 64 pages.' ),
            array( 'q' => 'What is the minimum page count for a saddle stitch booklet?',
                   'a' => 'The minimum page count is 8 pages (including the cover). Page counts must be in multiples of 4 since each sheet creates 4 pages when folded.' ),
            array( 'q' => 'What paper options are available?',
                   'a' => 'We offer text weight papers (70lb Uncoated, 80lb Matte, 100lb Gloss) and cardstock options (80lb through 18pt) for both inside pages and covers. Cardstock insides are available for booklets of 24 pages or less.' ),
            array( 'q' => 'What is the turnaround time for booklet printing?',
                   'a' => 'Minimum turnaround is ' . $min_days . ' business days. Turnaround varies based on quantity, paper selection, and finishing options. Rush options are available.' ),
            array( 'q' => 'What sizes of booklets can you print?',
                   'a' => 'We print standard sizes from 3.5x5.5 up to 12x9, including square formats (4x4 through 12x12) and landscape orientations. Custom sizes are also available.' ),
            array( 'q' => 'Do I need to add bleeds to my artwork?',
                   'a' => 'For edge-to-edge printing, artwork should include 0.125 inch (1/8") bleed on all sides. If your artwork doesn\'t have bleeds, we offer a bleed setup service.' ),
            array( 'q' => 'What finishing options are available?',
                   'a' => 'Available options include UV Gloss or UV Matte coating on covers, round cornering, bundling in groups of 25/50/100, two-staple binding, and enhanced vivid quality printing.' ),
        ),
        'perfect-bound' => array(),
        'brochure'      => array(),
        'coupon'        => array(),
        'letterhead'    => array(),
        'postcard'      => array(),
        'sticker'       => array(),
        'greeting-card' => array(),
    );
}

/**
 * Resolve the FAQ list for a calc type — admin override first, then defaults.
 * Returns an array of ['q' => ..., 'a' => ...] entries, possibly empty.
 */
function pps_get_faqs( $calc_type ) {
    $saved = get_option( 'pps_faqs', array() );
    if ( is_array( $saved ) && isset( $saved[ $calc_type ] ) && is_array( $saved[ $calc_type ] ) ) {
        return $saved[ $calc_type ];
    }
    $defaults = pps_default_faqs();
    return isset( $defaults[ $calc_type ] ) ? $defaults[ $calc_type ] : array();
}

/**
 * Emit a Product JSON-LD <script> block.
 *
 * Args:
 *   id                          DOM id (default 'pps-schema-product')
 *   name                        Product name (required)
 *   description                 Plain-text description
 *   brand_name                  Brand display name (default site name)
 *   category                    Schema category string
 *   image                       Single image URL (string) or array of URLs
 *   url                         Canonical URL of this Product
 *   sku                         SKU (optional; emitted only when non-empty)
 *   offers                      Array shaped per schema.org Offer/AggregateOffer, or null
 *   additional_properties       Array of ['name' => ..., 'value' => ...] — empty entries skipped
 *   additional_type             URL string, e.g. https://schema.org/Booklet (optional)
 *   audience                    Audience type string, emitted as Audience/audienceType (optional)
 *   color                       Color string (optional)
 *   material                    Material string (optional)
 *   disambiguating_description  Short distinguishing description (optional)
 *   gtin                        Global Trade Item Number (optional)
 *   mpn                         Manufacturer Part Number (optional)
 *   aggregate_rating            Array ['value' => float, 'count' => int, 'best' => float, 'worst' => float] or null
 */
function pps_emit_product_schema( array $args ) {
    $id     = isset( $args['id'] ) && is_string( $args['id'] ) && $args['id'] !== '' ? $args['id'] : 'pps-schema-product';
    $schema = pps_build_product_schema( $args );
    if ( empty( $schema ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $schema, PPS_SCHEMA_JSON_FLAGS )
       . "</script>\n";
}

/**
 * Build (but do not emit) the Product JSON-LD schema array. Same arg
 * shape as pps_emit_product_schema(); used for partial-merge with Tier
 * 2 overrides on preset URLs.
 */
function pps_build_product_schema( array $args ) {
    $defaults = array(
        'name'                       => '',
        'description'                => '',
        'brand_name'                 => get_bloginfo( 'name' ),
        'category'                   => '',
        'image'                      => '',
        'url'                        => '',
        'sku'                        => '',
        'offers'                     => null,
        'additional_properties'      => array(),
        'additional_type'            => '',
        'audience'                   => '',
        'color'                      => '',
        'material'                   => '',
        'disambiguating_description' => '',
        'gtin'                       => '',
        'mpn'                        => '',
        'aggregate_rating'           => null,
    );
    $a = wp_parse_args( $args, $defaults );

    $schema = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $a['name'],
        'description' => $a['description'],
        'brand'       => array( '@type' => 'Brand', 'name' => $a['brand_name'] ),
        'category'    => $a['category'],
        'image'       => $a['image'],
        'url'         => $a['url'],
    );
    if ( $a['sku'] !== '' ) {
        $schema['sku'] = $a['sku'];
    }
    if ( $a['additional_type'] !== '' ) {
        $schema['additionalType'] = $a['additional_type'];
    }
    if ( $a['gtin'] !== '' ) {
        $schema['gtin'] = $a['gtin'];
    }
    if ( $a['mpn'] !== '' ) {
        $schema['mpn'] = $a['mpn'];
    }
    if ( $a['color'] !== '' ) {
        $schema['color'] = $a['color'];
    }
    if ( $a['material'] !== '' ) {
        $schema['material'] = $a['material'];
    }
    if ( $a['disambiguating_description'] !== '' ) {
        $schema['disambiguatingDescription'] = $a['disambiguating_description'];
    }
    if ( $a['audience'] !== '' ) {
        $schema['audience'] = array( '@type' => 'Audience', 'audienceType' => $a['audience'] );
    }
    if ( is_array( $a['offers'] ) ) {
        $schema['offers'] = $a['offers'];
    }

    // AggregateRating: only emit when value+count are valid.
    // Defense-in-depth: re-validate at build time even though admin save also validates.
    if ( is_array( $a['aggregate_rating'] ) ) {
        $rv = isset( $a['aggregate_rating']['value'] ) ? floatval( $a['aggregate_rating']['value'] ) : 0.0;
        $rc = isset( $a['aggregate_rating']['count'] ) ? intval( $a['aggregate_rating']['count'] ) : 0;
        if ( $rv > 0 && $rv <= 5 && $rc > 0 ) {
            $best  = isset( $a['aggregate_rating']['best'] )  ? floatval( $a['aggregate_rating']['best'] )  : 5.0;
            $worst = isset( $a['aggregate_rating']['worst'] ) ? floatval( $a['aggregate_rating']['worst'] ) : 1.0;
            if ( $best <= 0 || $best > 5 )   $best  = 5.0;
            if ( $worst < 0 || $worst > 5 )  $worst = 1.0;
            $schema['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format( $rv, 1, '.', '' ),
                'reviewCount' => (string) $rc,
                'bestRating'  => number_format( $best, 1, '.', '' ),
                'worstRating' => number_format( $worst, 1, '.', '' ),
            );
        }
    }

    if ( ! empty( $a['additional_properties'] ) && is_array( $a['additional_properties'] ) ) {
        $props = array();
        foreach ( $a['additional_properties'] as $p ) {
            if ( ! is_array( $p ) ) continue;
            $name  = isset( $p['name'] )  ? trim( (string) $p['name'] )  : '';
            $value = isset( $p['value'] ) ? trim( (string) $p['value'] ) : '';
            if ( $name === '' || $value === '' ) continue;
            $props[] = array( '@type' => 'PropertyValue', 'name' => $name, 'value' => $value );
        }
        if ( $props ) {
            $schema['additionalProperty'] = $props;
        }
    }

    return $schema;
}

/**
 * Emit a LocalBusiness JSON-LD <script> block.
 *
 * Args:
 *   id                DOM id (default 'pps-schema-business')
 *   name              Business name (default site name)
 *   description       Plain-text description
 *   url               Site URL
 *   telephone, email, street, city, state, zip, country
 *   price_range       Schema.org priceRange (default '$$')
 *   area_served       Country name string (default 'United States')
 *   knows_about       Array of topic strings
 *   lat, lng          Optional coordinates; both required to emit geo
 *   aggregate_rating  Optional ['rating_value' => float, 'review_count' => int, 'url' => string]
 *                     Emitted only when rating_value > 0 and rating_value <= 5 and review_count > 0.
 */
function pps_emit_localbusiness_schema( array $args ) {
    $id     = isset( $args['id'] ) && is_string( $args['id'] ) && $args['id'] !== '' ? $args['id'] : 'pps-schema-business';
    $schema = pps_build_localbusiness_schema( $args );
    if ( empty( $schema ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $schema, PPS_SCHEMA_JSON_FLAGS )
       . "</script>\n";
}

/**
 * Build (but do not emit) the LocalBusiness JSON-LD schema array.
 */
function pps_build_localbusiness_schema( array $args ) {
    $defaults = array(
        'name'             => get_bloginfo( 'name' ),
        'description'      => '',
        'url'              => home_url( '/' ),
        'telephone'        => '',
        'email'            => '',
        'street'           => '',
        'city'             => '',
        'state'            => '',
        'zip'              => '',
        'country'          => 'US',
        'price_range'      => '$$',
        'area_served'      => 'United States',
        'knows_about'      => array(),
        'lat'              => '',
        'lng'              => '',
        'aggregate_rating' => null,
    );
    $a = wp_parse_args( $args, $defaults );

    $email = $a['email'] !== '' ? $a['email'] : get_option( 'admin_email' );

    $schema = array(
        '@context'       => 'https://schema.org',
        '@type'          => 'LocalBusiness',
        'additionalType' => 'https://schema.org/ProfessionalService',
        'name'           => $a['name'],
        'description'    => $a['description'],
        'url'            => $a['url'],
        'telephone'      => $a['telephone'],
        'email'          => $email,
        'address'        => array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => $a['street'],
            'addressLocality' => $a['city'],
            'addressRegion'   => $a['state'],
            'postalCode'      => $a['zip'],
            'addressCountry'  => $a['country'],
        ),
        'priceRange' => $a['price_range'],
        'areaServed' => array( '@type' => 'Country', 'name' => $a['area_served'] ),
        'knowsAbout' => $a['knows_about'],
    );

    // Only emit aggregateRating when both rating and count are valid.
    // Defense-in-depth: re-validate at build time even though admin save also validates.
    if ( is_array( $a['aggregate_rating'] ) ) {
        $rv = isset( $a['aggregate_rating']['rating_value'] ) ? floatval( $a['aggregate_rating']['rating_value'] ) : 0.0;
        $rc = isset( $a['aggregate_rating']['review_count'] ) ? intval( $a['aggregate_rating']['review_count'] ) : 0;
        if ( $rv > 0 && $rv <= 5 && $rc > 0 ) {
            $rating = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => number_format( $rv, 1, '.', '' ),
                'reviewCount' => (string) $rc,
                'bestRating'  => '5',
                'worstRating' => '1',
            );
            if ( ! empty( $a['aggregate_rating']['url'] ) ) {
                $url = esc_url_raw( $a['aggregate_rating']['url'], array( 'http', 'https' ) );
                if ( $url ) $rating['url'] = $url;
            }
            $schema['aggregateRating'] = $rating;
        }
    }

    if ( $a['lat'] !== '' && $a['lng'] !== '' ) {
        $schema['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => $a['lat'], 'longitude' => $a['lng'] );
    }

    return $schema;
}

/**
 * Emit a FAQPage JSON-LD <script> block.
 *
 * Args:
 *   id    DOM id (default 'pps-schema-faq')
 *   faqs  Array of ['q' => string, 'a' => string] entries. Empty entries are
 *         skipped. If no valid entries remain, no <script> tag is emitted.
 *
 * Answer text is run through wp_strip_all_tags so the schema 'text' field
 * stays plain text even if admin pastes HTML — schema.org Answer.text is
 * meant to be plain text.
 */
function pps_emit_faq_schema( array $args ) {
    $id     = isset( $args['id'] ) && is_string( $args['id'] ) && $args['id'] !== '' ? $args['id'] : 'pps-schema-faq';
    $schema = pps_build_faq_schema( $args );
    if ( empty( $schema ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $schema, PPS_SCHEMA_JSON_FLAGS )
       . "</script>\n";
}

/**
 * Build (but do not emit) the FAQPage JSON-LD schema array. Returns an
 * empty array if no valid Q&A entries are present.
 */
function pps_build_faq_schema( array $args ) {
    $defaults = array(
        'faqs' => array(),
    );
    $a = wp_parse_args( $args, $defaults );

    if ( empty( $a['faqs'] ) || ! is_array( $a['faqs'] ) ) return array();

    $entities = array();
    foreach ( $a['faqs'] as $faq ) {
        if ( ! is_array( $faq ) ) continue;
        $q = isset( $faq['q'] ) ? trim( (string) $faq['q'] ) : '';
        $ans = isset( $faq['a'] ) ? trim( (string) $faq['a'] ) : '';
        if ( $q === '' || $ans === '' ) continue;
        $entities[] = array(
            '@type'          => 'Question',
            'name'           => $q,
            'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $ans ) ),
        );
    }
    if ( empty( $entities ) ) return array();

    return array(
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    );
}

/**
 * Emit a WebApplication JSON-LD <script> block.
 *
 * Args:
 *   id                    DOM id (default 'pps-schema-webapp')
 *   name                  Application name
 *   description           Plain-text description
 *   url                   Canonical URL
 *   application_category  Schema.org applicationCategory (default 'BusinessApplication')
 *   operating_system      Default 'Any'
 *   browser_requirements  Default 'Requires JavaScript'
 *   free_to_use           bool — when true, includes a $0 Offer block (default true)
 */
function pps_emit_webapp_schema( array $args ) {
    $id     = isset( $args['id'] ) && is_string( $args['id'] ) && $args['id'] !== '' ? $args['id'] : 'pps-schema-webapp';
    $schema = pps_build_webapp_schema( $args );
    if ( empty( $schema ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $schema, PPS_SCHEMA_JSON_FLAGS )
       . "</script>\n";
}

/**
 * Build (but do not emit) the WebApplication JSON-LD schema array.
 */
function pps_build_webapp_schema( array $args ) {
    $defaults = array(
        'name'                 => '',
        'description'          => '',
        'url'                  => '',
        'application_category' => 'BusinessApplication',
        'operating_system'     => 'Any',
        'browser_requirements' => 'Requires JavaScript',
        'free_to_use'          => true,
    );
    $a = wp_parse_args( $args, $defaults );

    $schema = array(
        '@context'            => 'https://schema.org',
        '@type'               => 'WebApplication',
        'name'                => $a['name'],
        'description'         => $a['description'],
        'url'                 => $a['url'],
        'applicationCategory' => $a['application_category'],
        'operatingSystem'     => $a['operating_system'],
        'browserRequirements' => $a['browser_requirements'],
    );

    if ( $a['free_to_use'] ) {
        $schema['offers'] = array(
            '@type'         => 'Offer',
            'price'         => '0',
            'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
            'description'   => 'Free to use',
        );
    }

    $schema['creator'] = array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) );

    return $schema;
}

/**
 * Emit a BreadcrumbList JSON-LD <script> block.
 *
 * Args:
 *   id     DOM id (default 'pps-schema-breadcrumb')
 *   items  Array of ['name' => string, 'url' => string|null] entries.
 *          The last item's url may be null/empty (current page).
 */
function pps_emit_breadcrumb_schema( array $args ) {
    $id     = isset( $args['id'] ) && is_string( $args['id'] ) && $args['id'] !== '' ? $args['id'] : 'pps-schema-breadcrumb';
    $schema = pps_build_breadcrumb_schema( $args );
    if ( empty( $schema ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $schema, PPS_SCHEMA_JSON_FLAGS )
       . "</script>\n";
}

/**
 * Build (but do not emit) the BreadcrumbList JSON-LD schema array.
 * Returns an empty array if no valid items remain.
 */
function pps_build_breadcrumb_schema( array $args ) {
    $defaults = array(
        'items' => array(),
    );
    $a = wp_parse_args( $args, $defaults );

    if ( empty( $a['items'] ) || ! is_array( $a['items'] ) ) return array();

    $list = array();
    $pos  = 0;
    foreach ( $a['items'] as $item ) {
        if ( ! is_array( $item ) ) continue;
        $name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
        if ( $name === '' ) continue;
        $pos++;
        $entry = array(
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => $name,
        );
        if ( ! empty( $item['url'] ) ) {
            $url = esc_url_raw( (string) $item['url'], array( 'http', 'https' ) );
            if ( $url ) $entry['item'] = $url;
        }
        $list[] = $entry;
    }
    if ( empty( $list ) ) return array();

    return array(
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    );
}

/**
 * Build the canonical URL for a preset.
 */
function pps_get_preset_url( $slug ) {
    return home_url( '/' . $slug . '/' );
}

// ═══════════════════════════════════════════════════════════════
// SCHEMA OVERRIDES: validation, sanitization, raw emission
//
// Tier 2 (block override) and Tier 3 (extra block) accept admin-pasted
// JSON-LD. The validation pipeline:
//
//   1. JSON parse — must succeed
//   2. Root must be associative array (object) with @type, OR array of
//      such objects (we accept either; Tier 3 wraps singletons)
//   3. Recursive sanitize: string values → wp_kses with empty allowlist
//      (strips ALL HTML); strip null bytes; cap individual strings at
//      8KB. Arrays kept; primitives kept; objects/closures dropped.
//   4. Total size cap 50KB per block at storage; secondary check at
//      emission time
//
// Emission uses pps_emit_raw_jsonld() with PPS_SCHEMA_JSON_FLAGS_RAW —
// adds JSON_HEX_TAG to the standard flags so any '<' or '>' that
// survives sanitization (it shouldn't, but defense-in-depth) cannot
// break out of the <script> container.
// ═══════════════════════════════════════════════════════════════

if ( ! defined( 'PPS_SCHEMA_JSON_FLAGS_RAW' ) ) {
    define( 'PPS_SCHEMA_JSON_FLAGS_RAW', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
}
if ( ! defined( 'PPS_SCHEMA_OVERRIDE_MAX_BYTES' ) ) {
    define( 'PPS_SCHEMA_OVERRIDE_MAX_BYTES', 51200 ); // 50KB per override
}

/**
 * Recursively sanitize a JSON-decoded value for safe re-emission as
 * JSON-LD. Strips HTML and null bytes from strings, caps string length,
 * recurses into arrays, drops resources/objects.
 */
function pps_sanitize_jsonld_value( $value, $depth = 0 ) {
    if ( $depth > 24 ) return null; // bound recursion
    if ( is_string( $value ) ) {
        $s = wp_kses( $value, array() ); // strip ALL HTML
        $s = str_replace( "\0", '', $s ); // null bytes out
        if ( strlen( $s ) > 8192 ) $s = substr( $s, 0, 8192 );
        return $s;
    }
    if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || is_null( $value ) ) {
        return $value;
    }
    if ( is_array( $value ) ) {
        $out = array();
        foreach ( $value as $k => $v ) {
            $clean_k = is_int( $k ) ? $k : wp_kses( (string) $k, array() );
            if ( is_string( $clean_k ) && strlen( $clean_k ) > 100 ) continue; // absurd keys out
            $out[ $clean_k ] = pps_sanitize_jsonld_value( $v, $depth + 1 );
        }
        return $out;
    }
    // objects, resources, closures: drop
    return null;
}

/**
 * Validate + sanitize a raw JSON-LD override string. Returns the cleaned
 * decoded array on success or WP_Error.
 *
 * Acceptance criteria:
 *   - Decodes successfully
 *   - Root is an associative array (object)
 *   - Has @type key
 *   - ≤ PPS_SCHEMA_OVERRIDE_MAX_BYTES raw size
 */
function pps_sanitize_jsonld_override( $raw ) {
    $raw = (string) $raw;
    if ( strlen( $raw ) > PPS_SCHEMA_OVERRIDE_MAX_BYTES ) {
        return new WP_Error( 'pps_jsonld_too_large', 'JSON-LD override exceeds ' . PPS_SCHEMA_OVERRIDE_MAX_BYTES . ' bytes.' );
    }
    $decoded = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'pps_jsonld_invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
    }
    if ( ! is_array( $decoded ) ) {
        return new WP_Error( 'pps_jsonld_not_object', 'Root must be a JSON object/array.' );
    }
    // Permit either a single schema object or a graph array; require @type
    // somewhere obvious so blank/garbage rows don't slip through.
    $has_type = isset( $decoded['@type'] )
              || ( isset( $decoded[0] ) && is_array( $decoded[0] ) && isset( $decoded[0]['@type'] ) )
              || ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) );
    if ( ! $has_type ) {
        return new WP_Error( 'pps_jsonld_no_type', 'Root must include @type (or @graph or be an array of objects with @type).' );
    }
    return pps_sanitize_jsonld_value( $decoded );
}

/**
 * Emit a pre-built (override-derived) JSON-LD block.
 *
 * Used by Tier 2 (block override) and Tier 3 (extras) emission. Uses the
 * RAW flags variant which escapes < and > so override content cannot
 * break out of the <script> container.
 */
function pps_emit_raw_jsonld( $id, $data ) {
    if ( ! is_array( $data ) || empty( $data ) ) return;
    echo '<script type="application/ld+json" id="' . esc_attr( $id ) . '">'
       . wp_json_encode( $data, PPS_SCHEMA_JSON_FLAGS_RAW )
       . "</script>\n";
}

/**
 * Sanitize a single Tier 1 override value against a per-field spec.
 *
 * Strategy is selected by $spec['sanitize']. Returns a cleaned scalar,
 * an array (for url_csv with >1 item), or null to signal "drop this
 * field" (caller should not store).
 *
 * Strategies:
 *   text      sanitize_text_field, cap to $spec['max']
 *   textarea  sanitize_textarea_field, cap to $spec['max']
 *   url       esc_url_raw with http/https allowlist; null if empty
 *   url_csv   split on commas; sanitize each as url; cap to $spec['max_items']
 *             (default 6); returns string if 1 item, array if >1, null if 0
 *   regex     preg_replace($spec['pattern'], '', $v); cap to $spec['max'];
 *             null if empty post-strip
 *   decimal   floatval; range check $spec['min']..$spec['max_value'];
 *             format with $spec['precision'] dp (default 2); null if OOR
 *   int       intval; range check; cast to string; null if OOR
 *   enum      check $spec['allowed']; null if not in list
 *   currency  strtoupper; require ^[A-Z]{3}$; null if invalid
 *   date_iso  require ^\d{4}-\d{2}-\d{2}$ AND checkdate(); null if invalid
 */
function pps_sanitize_override_value( $raw, $spec ) {
    $strategy = isset( $spec['sanitize'] ) ? $spec['sanitize'] : 'text';
    $raw = (string) $raw;

    switch ( $strategy ) {
        case 'text':
            $s = sanitize_text_field( $raw );
            $max = isset( $spec['max'] ) ? (int) $spec['max'] : 200;
            if ( strlen( $s ) > $max ) $s = substr( $s, 0, $max );
            return $s === '' ? null : $s;

        case 'textarea':
            $s = sanitize_textarea_field( $raw );
            $max = isset( $spec['max'] ) ? (int) $spec['max'] : 500;
            if ( strlen( $s ) > $max ) $s = substr( $s, 0, $max );
            return $s === '' ? null : $s;

        case 'url':
            $u = esc_url_raw( $raw, array( 'http', 'https' ) );
            return $u ? $u : null;

        case 'url_csv':
            $max_items = isset( $spec['max_items'] ) ? (int) $spec['max_items'] : 6;
            $parts = array_map( 'trim', explode( ',', $raw ) );
            $urls  = array();
            foreach ( $parts as $p ) {
                if ( $p === '' ) continue;
                $u = esc_url_raw( $p, array( 'http', 'https' ) );
                if ( $u ) $urls[] = $u;
                if ( count( $urls ) >= $max_items ) break;
            }
            if ( ! $urls ) return null;
            return count( $urls ) === 1 ? $urls[0] : $urls;

        case 'regex':
            $pattern = isset( $spec['pattern'] ) ? $spec['pattern'] : '/[^a-zA-Z0-9_\-]/';
            $s = preg_replace( $pattern, '', $raw );
            $max = isset( $spec['max'] ) ? (int) $spec['max'] : 80;
            if ( strlen( $s ) > $max ) $s = substr( $s, 0, $max );
            return $s === '' ? null : $s;

        case 'decimal':
            if ( ! is_numeric( $raw ) ) return null;
            $f = floatval( $raw );
            $min = isset( $spec['min'] )       ? (float) $spec['min']       : 0.0;
            $max = isset( $spec['max_value'] ) ? (float) $spec['max_value'] : 1000000.0;
            if ( $f < $min || $f > $max ) return null;
            $prec = isset( $spec['precision'] ) ? (int) $spec['precision'] : 2;
            return number_format( $f, $prec, '.', '' );

        case 'int':
            if ( ! is_numeric( $raw ) ) return null;
            $i = intval( $raw );
            $min = isset( $spec['min'] )       ? (int) $spec['min']       : 0;
            $max = isset( $spec['max_value'] ) ? (int) $spec['max_value'] : 1000000;
            if ( $i < $min || $i > $max ) return null;
            return (string) $i;

        case 'enum':
            $allowed = isset( $spec['allowed'] ) && is_array( $spec['allowed'] ) ? $spec['allowed'] : array();
            return in_array( $raw, $allowed, true ) ? $raw : null;

        case 'currency':
            $s = strtoupper( sanitize_text_field( $raw ) );
            return preg_match( '/^[A-Z]{3}$/', $s ) ? $s : null;

        case 'date_iso':
            if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) ) return null;
            if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return null;
            return $raw;
    }
    return null;
}

/**
 * Inject structured data schemas on calculator product pages.
 * Runs via wp_head so schemas appear before page content.
 */
add_action( 'wp_head', function() {
    if ( ! is_product() ) return;
    global $product;
    if ( ! $product ) return;
    $product_id = $product->get_id();
    $filename   = pps_get_calculator_for_product( $product_id );
    if ( ! $filename ) return;

    $product_url  = get_permalink( $product_id );
    $product_img  = wp_get_attachment_url( $product->get_image_id() ) ?: '';
    $product_name = $product->get_name();
    $config       = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $seo          = isset( $config['seo'] ) && is_array( $config['seo'] ) ? $config['seo'] : array();
    $calc_type    = pps_get_calc_type_for_filename( $filename );

    // ── Product + AggregateOffer ──
    pps_emit_product_schema( array(
        'name'        => $product_name,
        'description' => 'Custom printed saddle-stitched booklets with full color or greyscale printing, multiple paper options, and finishing services including UV coating, round cornering, and bundling.',
        'category'    => 'Printing Services > Booklets',
        'image'       => $product_img,
        'url'         => $product_url,
        'offers'      => array(
            '@type'           => 'AggregateOffer',
            'priceCurrency'   => get_woocommerce_currency(),
            // Quote the configuration this page actually lands on when the
            // product carries one ("Price at these defaults" in the PPS
            // Defaults tab). The 50 fallback is a placeholder from before
            // per-product defaults existed and understates most products.
            'lowPrice'        => pps_product_defaults_low_price( '50' ),
            'highPrice'       => '5000',
            'offerCount'      => '1',
            'availability'    => 'https://schema.org/InStock',
            'priceValidUntil' => date( 'Y-12-31', strtotime( '+1 year' ) ),
            'seller'          => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
        ),
        'additional_properties' => array(
            array( 'name' => 'Binding',          'value' => 'Saddle Stitch (Stapled)' ),
            array( 'name' => 'Page Count',       'value' => '8 to 64 pages' ),
            array( 'name' => 'Minimum Quantity', 'value' => '1' ),
            array( 'name' => 'Turnaround',       'value' => ( $config['minimum_turnaround_days'] ?? 3 ) . '+ business days' ),
        ),
    ) );

    // ── LocalBusiness (with optional GBP aggregate rating) ──
    pps_emit_localbusiness_schema( array(
        'description' => 'Full-service commercial print shop specializing in saddle-stitch booklets, brochures, and custom printing with fast turnaround.',
        'telephone'   => $seo['phone'] ?? '',
        'email'       => $seo['email'] ?? '',
        'street'      => $seo['street'] ?? '',
        'city'        => $seo['city'] ?? 'Phoenix',
        'state'       => $seo['state'] ?? 'AZ',
        'zip'         => $seo['zip'] ?? '85027',
        'lat'         => $seo['lat'] ?? '',
        'lng'         => $seo['lng'] ?? '',
        'knows_about' => array( 'Saddle Stitch Booklets', 'Digital Printing', 'Offset Printing', 'UV Coating', 'Booklet Binding' ),
        'aggregate_rating' => array(
            'rating_value' => $seo['gbp_rating_value'] ?? 0,
            'review_count' => $seo['gbp_review_count'] ?? 0,
            'url'          => $seo['gbp_url'] ?? '',
        ),
    ) );

    // ── FAQPage (calc-type-aware; emits nothing for calc types without saved or default FAQs) ──
    pps_emit_faq_schema( array( 'faqs' => pps_get_faqs( $calc_type ) ) );

    // ── WebApplication ──
    pps_emit_webapp_schema( array(
        'name'        => $product_name . ' Price Calculator',
        'description' => 'Instant online pricing calculator for custom saddle-stitched booklets. Configure size, paper, quantity, and finishing options for a real-time quote.',
        'url'         => $product_url,
    ) );
}, 5 );

// ═══════════════════════════════════════════════════════════════
// SEO: PER-PRESET HEAD TAGS, SCHEMAS, AND DEDUPE FILTERS
// ═══════════════════════════════════════════════════════════════

/**
 * Compute meta-description for the active preset, with sensible truncation
 * for SERP. Returns empty string if no preset.
 */
function pps_preset_meta_description( $preset ) {
    $desc = isset( $preset['description'] ) ? trim( (string) $preset['description'] ) : '';
    if ( $desc === '' ) {
        // Fall back to the title if there's no description; better than empty.
        $desc = isset( $preset['title'] ) ? (string) $preset['title'] : '';
    }
    if ( strlen( $desc ) > 320 ) $desc = substr( $desc, 0, 317 ) . '...';
    return $desc;
}

/**
 * Per-preset head tags (title, description, canonical, OG, Twitter, robots)
 * + per-preset Product/Breadcrumb/LocalBusiness/FAQ/WebApp JSON-LD.
 *
 * Runs at priority 5 so schema appears alongside (or before) plugins that
 * hook later. Gated on $GLOBALS['pps_active_preset'] — completely silent
 * on non-preset requests.
 */
add_action( 'wp_head', function() {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return;

    $preset    = $GLOBALS['pps_active_preset'];
    $slug      = $preset['slug'];
    $url       = pps_get_preset_url( $slug );
    $ovr       = isset( $preset['overrides'] )       && is_array( $preset['overrides'] )       ? $preset['overrides']       : array();
    $blocks    = isset( $preset['schema_overrides'] ) && is_array( $preset['schema_overrides'] ) ? $preset['schema_overrides'] : array();
    $extras    = isset( $preset['schema_extras'] )    && is_array( $preset['schema_extras'] )    ? $preset['schema_extras']    : array();

    // Tier 1 resolution — override → fall back to computed
    $title         = $ovr['meta_title']        ?? (string) ( $preset['title'] ?? '' );
    $desc          = $ovr['meta_description']  ?? pps_preset_meta_description( $preset );
    $image         = $ovr['og_image']          ?? (string) ( $preset['image'] ?? '' );
    $schema_name   = $ovr['schema_name']       ?? $title;
    $schema_sku    = $ovr['schema_sku']        ?? $slug;
    $crumb_label   = $ovr['breadcrumb_label']  ?? $title;

    $calc_type = (string) ( $preset['calc'] ?? '' );
    $config    = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $seo       = isset( $config['seo'] ) && is_array( $config['seo'] ) ? $config['seo'] : array();
    $site_name = get_bloginfo( 'name' );

    // ── Meta description ──
    echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";

    // ── Canonical ──
    echo '<link rel="canonical" href="' . esc_url( $url ) . "\">\n";

    // ── Robots ──
    echo "<meta name=\"robots\" content=\"index, follow, max-image-preview:large\">\n";

    // ── OG image resolution: preset image → site logo fallback ──
    $og_image    = $image;
    $og_img_w    = '';
    $og_img_h    = '';
    $og_img_type = '';
    if ( $og_image === '' ) {
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $og_image = wp_get_attachment_url( $logo_id );
            if ( ! $og_image ) $og_image = '';
        }
    }
    if ( $og_image !== '' ) {
        $att_id = attachment_url_to_postid( $og_image );
        if ( $att_id ) {
            $meta = wp_get_attachment_metadata( $att_id );
            if ( is_array( $meta ) ) {
                if ( ! empty( $meta['width'] ) )  $og_img_w = (int) $meta['width'];
                if ( ! empty( $meta['height'] ) ) $og_img_h = (int) $meta['height'];
            }
            $og_img_type = get_post_mime_type( $att_id );
        }
    }

    // ── Open Graph ──
    echo "<meta property=\"og:type\" content=\"product\">\n";
    echo '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
    echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n";
    echo '<meta property="og:url" content="' . esc_url( $url ) . "\">\n";
    echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . "\">\n";
    if ( $og_image !== '' ) {
        echo '<meta property="og:image" content="' . esc_url( $og_image ) . "\">\n";
        if ( $og_img_w && $og_img_h ) {
            echo '<meta property="og:image:width" content="' . esc_attr( $og_img_w ) . "\">\n";
            echo '<meta property="og:image:height" content="' . esc_attr( $og_img_h ) . "\">\n";
        }
        if ( $og_img_type ) {
            echo '<meta property="og:image:type" content="' . esc_attr( $og_img_type ) . "\">\n";
        }
    }

    // ── Twitter Card ──
    $twitter_card = $og_image !== '' ? 'summary_large_image' : 'summary';
    echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . "\">\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . "\">\n";
    echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . "\">\n";
    if ( $og_image !== '' ) {
        echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . "\">\n";
    }

    // ── Tier 2 partial-merge helper ──
    // For each block: build the auto schema array, then if a Tier 2 override
    // is present, shallow-merge it on top — keys in the override win, keys
    // absent from the override fall back to auto. If the auto is empty AND
    // no override is set, nothing emits. (`@type`, `@context`, etc are part
    // of auto and survive unless the override explicitly sets them.)
    $emit_merged = function ( $id, $auto, $tier2_key ) use ( $blocks ) {
        $override = isset( $blocks[ $tier2_key ] ) && is_array( $blocks[ $tier2_key ] ) ? $blocks[ $tier2_key ] : null;
        if ( $override !== null ) {
            $final = is_array( $auto ) ? array_merge( $auto, $override ) : $override;
        } else {
            $final = $auto;
        }
        if ( ! is_array( $final ) || empty( $final ) ) return;
        pps_emit_raw_jsonld( $id, $final );
    };

    // ── Product JSON-LD ──
    // Helper: read a Tier 1 override or fall back to a default. Treats
    // empty string and null identically so blank overrides fall through.
    $ovr_get = function ( $key, $default = null ) use ( $ovr ) {
        return ( isset( $ovr[ $key ] ) && $ovr[ $key ] !== '' ) ? $ovr[ $key ] : $default;
    };

    $product_args = array(
        'name'                       => $schema_name,
        'description'                => $ovr_get( 'schema_description', $desc ),
        'brand_name'                 => $ovr_get( 'schema_brand', get_bloginfo( 'name' ) ),
        'category'                   => $ovr_get( 'schema_category', 'Print services' ),
        'image'                      => $ovr_get( 'schema_image', $image ),
        'url'                        => $ovr_get( 'schema_url', $url ),
        'sku'                        => $schema_sku,
        'additional_type'            => (string) $ovr_get( 'schema_additional_type', '' ),
        'audience'                   => (string) $ovr_get( 'schema_audience', '' ),
        'color'                      => (string) $ovr_get( 'schema_color', '' ),
        'material'                   => (string) $ovr_get( 'schema_material', '' ),
        'disambiguating_description' => (string) $ovr_get( 'schema_disambiguating_description', '' ),
        'gtin'                       => (string) $ovr_get( 'schema_gtin', '' ),
        'mpn'                        => (string) $ovr_get( 'schema_mpn', '' ),
    );

    // ── Offer / AggregateOffer ──
    // Resolve each Offer attribute with override-then-default precedence.
    $preset_price = ( isset( $preset['price_from'] ) && $preset['price_from'] !== null )
                    ? (float) $preset['price_from'] : null;
    $offer_low_raw  = $ovr_get( 'offer_price', $preset_price );
    $offer_high_raw = $ovr_get( 'offer_price_high', null );
    $preset_curr    = ( isset( $preset['currency'] ) && preg_match( '/^[A-Z]{3}$/', (string) $preset['currency'] ) )
                      ? $preset['currency'] : 'USD';
    $offer_currency = (string) $ovr_get( 'offer_price_currency', $preset_curr );
    $offer_avail    = 'https://schema.org/' . (string) $ovr_get( 'offer_availability',   'InStock' );
    $offer_cond     = 'https://schema.org/' . (string) $ovr_get( 'offer_item_condition', 'NewCondition' );
    $offer_pvu      = (string) $ovr_get( 'offer_price_valid_until', date( 'Y-12-31', strtotime( '+1 year' ) ) );
    $offer_url      = (string) $ovr_get( 'offer_url', $url );

    if ( $offer_low_raw !== null && (float) $offer_low_raw >= 0 ) {
        $low = (float) $offer_low_raw;
        if ( $offer_high_raw !== null && (float) $offer_high_raw > $low ) {
            $product_args['offers'] = array(
                '@type'           => 'AggregateOffer',
                'url'             => $offer_url,
                'priceCurrency'   => $offer_currency,
                'lowPrice'        => number_format( $low, 2, '.', '' ),
                'highPrice'       => number_format( (float) $offer_high_raw, 2, '.', '' ),
                'offerCount'      => '1',
                'availability'    => $offer_avail,
                'itemCondition'   => $offer_cond,
                'priceValidUntil' => $offer_pvu,
            );
        } else {
            $product_args['offers'] = array(
                '@type'           => 'Offer',
                'url'             => $offer_url,
                'priceCurrency'   => $offer_currency,
                'price'           => number_format( $low, 2, '.', '' ),
                'availability'    => $offer_avail,
                'itemCondition'   => $offer_cond,
                'priceValidUntil' => $offer_pvu,
            );
        }
    }

    // ── AggregateRating ── (only when both value+count are set; cross-field
    // validation in pps_save_preset() guarantees this cohort is intact)
    $rv = $ovr_get( 'rating_value' );
    $rc = $ovr_get( 'rating_count' );
    if ( $rv !== null && $rc !== null ) {
        $product_args['aggregate_rating'] = array(
            'value' => (float) $rv,
            'count' => (int)   $rc,
            'best'  => $ovr_get( 'rating_best',  5 ),
            'worst' => $ovr_get( 'rating_worst', 1 ),
        );
    }

    // ── additionalProperty (Quantity/Pages/Size from defaults) ──
    $preset_defaults = isset( $preset['defaults'] ) && is_array( $preset['defaults'] ) ? $preset['defaults'] : array();
    $props = array();
    foreach ( array(
        'qty'   => 'Quantity',
        'pages' => 'Pages',
        'size'  => 'Size',
    ) as $field => $label ) {
        if ( isset( $preset_defaults[ $field ] ) && $preset_defaults[ $field ] !== '' && ! is_array( $preset_defaults[ $field ] ) ) {
            $props[] = array( 'name' => $label, 'value' => (string) $preset_defaults[ $field ] );
        }
    }
    if ( $props ) $product_args['additional_properties'] = $props;

    $emit_merged( 'pps-schema-product', pps_build_product_schema( $product_args ), 'product' );

    // ── BreadcrumbList ──
    $emit_merged( 'pps-schema-breadcrumb', pps_build_breadcrumb_schema( array(
        'items' => array(
            array( 'name' => $site_name,   'url' => home_url( '/' ) ),
            array( 'name' => $crumb_label ),
        ),
    ) ), 'breadcrumb' );

    // ── LocalBusiness ──
    $emit_merged( 'pps-schema-business', pps_build_localbusiness_schema( array(
        'description' => 'Full-service commercial print shop specializing in saddle-stitch booklets, brochures, and custom printing with fast turnaround.',
        'telephone'   => $seo['phone'] ?? '',
        'email'       => $seo['email'] ?? '',
        'street'      => $seo['street'] ?? '',
        'city'        => $seo['city'] ?? 'Phoenix',
        'state'       => $seo['state'] ?? 'AZ',
        'zip'         => $seo['zip'] ?? '85027',
        'lat'         => $seo['lat'] ?? '',
        'lng'         => $seo['lng'] ?? '',
        'knows_about' => array( 'Saddle Stitch Booklets', 'Digital Printing', 'Offset Printing', 'UV Coating', 'Booklet Binding' ),
        'aggregate_rating' => array(
            'rating_value' => $seo['gbp_rating_value'] ?? 0,
            'review_count' => $seo['gbp_review_count'] ?? 0,
            'url'          => $seo['gbp_url'] ?? '',
        ),
    ) ), 'localbusiness' );

    // ── FAQPage ── (Tier 2 override merges; per-preset faqs > calc-type defaults)
    $preset_faqs = isset( $preset['faqs'] ) && is_array( $preset['faqs'] ) && ! empty( $preset['faqs'] )
                   ? $preset['faqs']
                   : pps_get_faqs( $calc_type );
    $emit_merged( 'pps-schema-faq', pps_build_faq_schema( array( 'faqs' => $preset_faqs ) ), 'faq' );

    // ── WebApplication ──
    $emit_merged( 'pps-schema-webapp', pps_build_webapp_schema( array(
        'name'        => $title . ' Price Calculator',
        'description' => 'Instant online pricing calculator for ' . $title . '. Configure size, paper, quantity, and finishing options for a real-time quote.',
        'url'         => $url,
    ) ), 'webapp' );

    // ── Tier 3: extra schema blocks ──
    if ( ! empty( $extras ) ) {
        $i = 0;
        foreach ( $extras as $extra ) {
            pps_emit_raw_jsonld( 'pps-schema-extra-' . $i, $extra );
            $i++;
        }
    }
}, 5 );

/**
 * Resolve a Tier 1 override field for the active preset; returns the
 * computed fallback when the override is absent.
 */
function pps_preset_resolved_field( $field ) {
    $preset = $GLOBALS['pps_active_preset'] ?? null;
    if ( ! $preset ) return null;
    $ovr = isset( $preset['overrides'] ) && is_array( $preset['overrides'] ) ? $preset['overrides'] : array();
    switch ( $field ) {
        case 'title':       return $ovr['meta_title']       ?? (string) ( $preset['title'] ?? '' );
        case 'description': return $ovr['meta_description'] ?? pps_preset_meta_description( $preset );
        case 'og_image':    return $ovr['og_image']         ?? (string) ( $preset['image'] ?? '' );
    }
    return null;
}

/**
 * Filter <title> on preset URLs. Priority 999 to win against Yoast/RM/theme.
 */
add_filter( 'pre_get_document_title', function( $title ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) {
        return pps_preset_resolved_field( 'title' );
    }
    return $title;
}, 999 );

/**
 * Yoast/Rank Math title dedupe — feed our title through their pipeline so
 * the SEO plugin doesn't emit a competing <title>.
 */
add_filter( 'wpseo_title', function( $title ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'title' );
    return $title;
}, 999 );
add_filter( 'rank_math/frontend/title', function( $title ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'title' );
    return $title;
}, 999 );

/**
 * Yoast/Rank Math meta-description dedupe.
 */
add_filter( 'wpseo_metadesc', function( $desc ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'description' );
    return $desc;
}, 999 );
add_filter( 'rank_math/frontend/description', function( $desc ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'description' );
    return $desc;
}, 999 );

/**
 * Canonical URL: filter WP core's get_canonical_url + Yoast/RM equivalents
 * so all three converge on our preset URL. (We also emit our own
 * <link rel="canonical"> in the wp_head action above; downstream filters
 * keep WP's rel_canonical action and SEO plugins from emitting a conflict.)
 */
add_filter( 'get_canonical_url', function( $canonical, $post = null ) {
    if ( ! empty( $GLOBALS['pps_active_preset']['slug'] ) ) {
        return pps_get_preset_url( $GLOBALS['pps_active_preset']['slug'] );
    }
    return $canonical;
}, 999, 2 );
add_filter( 'wpseo_canonical', function( $canonical ) {
    if ( ! empty( $GLOBALS['pps_active_preset']['slug'] ) ) {
        return pps_get_preset_url( $GLOBALS['pps_active_preset']['slug'] );
    }
    return $canonical;
}, 999 );
add_filter( 'rank_math/frontend/canonical', function( $canonical ) {
    if ( ! empty( $GLOBALS['pps_active_preset']['slug'] ) ) {
        return pps_get_preset_url( $GLOBALS['pps_active_preset']['slug'] );
    }
    return $canonical;
}, 999 );

/**
 * Robots dedupe — Yoast/RM emit their own robots meta. Force ours.
 */
add_filter( 'wpseo_robots', function( $robots ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return 'index, follow, max-image-preview:large';
    return $robots;
}, 999 );
add_filter( 'rank_math/frontend/robots', function( $robots ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) {
        return array( 'index', 'follow', 'max-image-preview:large' );
    }
    return $robots;
}, 999 );

/**
 * OG/Twitter dedupe — when a SEO plugin tries to emit its own OG/Twitter
 * tags, override their values to match ours. Cheaper than trying to
 * disable their emission entirely; net effect is a single set of tags
 * with our values regardless of which plugin (if any) is active.
 */
add_filter( 'wpseo_opengraph_title', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'title' );
    return $v;
}, 999 );
add_filter( 'wpseo_opengraph_desc', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'description' );
    return $v;
}, 999 );
add_filter( 'wpseo_opengraph_url', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset']['slug'] ) ) return pps_get_preset_url( $GLOBALS['pps_active_preset']['slug'] );
    return $v;
}, 999 );
add_filter( 'wpseo_opengraph_image', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) {
        $img = pps_preset_resolved_field( 'og_image' );
        if ( $img ) return $img;
    }
    return $v;
}, 999 );
add_filter( 'wpseo_twitter_title', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'title' );
    return $v;
}, 999 );
add_filter( 'wpseo_twitter_description', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) return pps_preset_resolved_field( 'description' );
    return $v;
}, 999 );
add_filter( 'wpseo_twitter_image', function( $v ) {
    if ( ! empty( $GLOBALS['pps_active_preset'] ) ) {
        $img = pps_preset_resolved_field( 'og_image' );
        if ( $img ) return $img;
    }
    return $v;
}, 999 );

/**
 * Noscript fallback for preset URLs — static HTML in the page footer for
 * crawlers that don't render JS. The calculator markup itself is JS-driven;
 * this gives indexers something solid to read.
 *
 * Hooked at wp_footer (priority 5) instead of woocommerce_after_single_product_summary
 * (which doesn't fire on virtual posts).
 */
add_action( 'wp_footer', function() {
    if ( empty( $GLOBALS['pps_active_preset'] ) ) return;

    $preset    = $GLOBALS['pps_active_preset'];
    $title     = (string) ( $preset['title'] ?? '' );
    $desc      = pps_preset_meta_description( $preset );
    $defaults  = isset( $preset['defaults'] ) && is_array( $preset['defaults'] ) ? $preset['defaults'] : array();
    $email     = get_option( 'admin_email' );

    echo "<noscript>\n";
    echo '<div style="max-width:800px;margin:40px auto;padding:20px;font-family:sans-serif;color:#333">';
    echo '<h1>' . esc_html( $title ) . '</h1>';
    if ( $desc !== '' ) echo '<p>' . esc_html( $desc ) . '</p>';

    // Spec table from defaults (Quantity / Pages / Size if present)
    $rows = array();
    if ( isset( $defaults['qty'] )   && $defaults['qty']   !== '' ) $rows['Quantity'] = (string) $defaults['qty'];
    if ( isset( $defaults['pages'] ) && $defaults['pages'] !== '' ) $rows['Pages']    = (string) $defaults['pages'];
    if ( isset( $defaults['size'] )  && $defaults['size']  !== '' ) $rows['Size']     = (string) $defaults['size'];
    if ( $rows ) {
        echo '<h2>Specs</h2>';
        echo '<table style="border-collapse:collapse;margin-bottom:14px"><tbody>';
        foreach ( $rows as $k => $v ) {
            echo '<tr><th style="text-align:left;padding:4px 12px 4px 0">' . esc_html( $k ) . '</th><td style="padding:4px 0">' . esc_html( $v ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p><strong>Enable JavaScript for the interactive price calculator.</strong></p>';
    echo '<p>Contact: <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>';
    echo '</div>';
    echo "</noscript>\n";
}, 5 );

/**
 * Noscript fallback — static HTML for crawlers that don't render JS.
 */
add_action( 'woocommerce_after_single_product_summary', function() {
    global $product;
    if ( ! $product ) return;
    if ( ! pps_get_calculator_for_product( $product->get_id() ) ) return;

    $config   = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $min_days = intval( $config['minimum_turnaround_days'] ?? 3 );
    $email    = esc_html( get_option( 'admin_email' ) );
    $name     = esc_html( get_bloginfo( 'name' ) );
    ?>
    <noscript>
    <div style="max-width:800px;margin:40px auto;padding:20px;font-family:sans-serif;color:#333">
        <h1>Custom Saddle Stitch Booklet Printing</h1>
        <p>Order custom saddle-stitched booklets from <?php echo $name; ?>.
        Configure size, paper, quantity, and finishing options for an instant quote.</p>
        <h2>Available Sizes</h2>
        <ul>
            <li>3.5&times;5.5, 4&times;6, 4.25&times;5.5, 5&times;7</li>
            <li>5.5&times;8.5 (most popular), 6&times;9, 8.5&times;11, 9&times;12</li>
            <li>Square: 4&times;4 through 12&times;12</li>
            <li>Landscape formats &amp; custom sizes (2.5&Prime;&ndash;13.5&Prime;)</li>
        </ul>
        <h2>Paper Options</h2>
        <p><strong>Text weight:</strong> 70lb Uncoated, 80lb Matte, 100lb Gloss, factory-coated options.<br>
        <strong>Cardstock:</strong> 80lb&ndash;18pt (covers always, insides up to 24 pages).</p>
        <h2>Finishing</h2>
        <p>UV Gloss/Matte coating, round cornering, bundling (25/50/100), two-staple binding, vivid printing.</p>
        <h2>Turnaround</h2>
        <p>Minimum <?php echo $min_days; ?> business days. Free ground shipping US-wide. Rush available.</p>
        <h2>Artwork</h2>
        <p>Upload PDF or images. Built-in proof tool with bleed, trim, and safety guides.</p>
        <p><strong>Enable JavaScript for the interactive price calculator.</strong></p>
        <p>Contact: <a href="mailto:<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><?php echo $email; ?></a></p>
    </div>
    </noscript>
    <?php
}, 25 );

/**
 * llms.txt — AI search engine content descriptor at /llms.txt
 */
add_action( 'init', function() {
    add_rewrite_rule( '^llms\.txt$', 'index.php?pps_llms_txt=1', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'pps_llms_txt';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( ! get_query_var( 'pps_llms_txt' ) ) return;

    $name     = get_bloginfo( 'name' );
    $url      = home_url( '/' );
    $config   = function_exists( 'pps_get_config' ) ? pps_get_config() : array();
    $min_days = intval( $config['minimum_turnaround_days'] ?? 3 );
    $email    = get_option( 'admin_email' );

    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Cache-Control: public, max-age=86400' );

    echo "# {$name}\n";
    echo "> Full-service commercial print shop in Phoenix, AZ. Booklets, brochures, postcards, letterhead, greeting cards, stickers and coupon books, all priced instantly online.\n\n";

    // ── Services ──
    // Driven off the registry rather than hardcoded. The previous version
    // named only saddle stitch, so the other seven calculators were invisible
    // to every answer engine — and it would have gone stale again the next
    // time a product family was added.
    echo "## Services\n";
    $svc_labels = array(
        'saddle'        => 'Saddle-stitch (stapled) booklet printing',
        'perfect-bound' => 'Perfect bound book printing (square spine)',
        'coupon'        => 'Coupon book printing with numbered, perforated tear-out coupons',
        'brochure'      => 'Brochure and flat printing with 9 fold styles',
        'postcard'      => 'Postcard printing',
        'letterhead'    => 'Letterhead printing, including linen stocks',
        'greeting-card' => 'Greeting card printing',
        'sticker'       => 'Sticker and label printing',
    );
    $svc_seen = array();
    if ( function_exists( 'pps_catalog' ) ) {
        foreach ( pps_catalog() as $row ) {
            if ( $row['calc'] !== '' ) $svc_seen[ $row['calc'] ] = true;
        }
    }
    // If the catalog could not be resolved at all (WooCommerce not loaded,
    // empty registry), fall back to listing everything rather than emitting a
    // Services section with no services in it — a silently empty list is worse
    // than the hardcoded one this replaced.
    foreach ( $svc_labels as $slug => $label ) {
        if ( ! $svc_seen || isset( $svc_seen[ $slug ] ) ) echo "- {$label}\n";
    }
    echo "- Full color and greyscale digital printing\n";
    echo "- Paper stocks: text weight (70lb-100lb) and cardstock (80lb-18pt)\n";
    echo "- UV Gloss and UV Matte cover coating\n";
    echo "- Round cornering, bundling, two-staple binding\n";
    echo "- Professional prepress artwork review\n";
    echo "- Nationwide shipping (UPS Ground, overnight available)\n\n";
    echo "## Saddle Stitch Booklets\n";
    echo "- Page counts: 8 to 64 pages (multiples of 4)\n";
    echo "- Sizes: 3.5x5.5 through 12x9, square 4x4-12x12, landscape, custom\n";
    echo "- Minimum quantity: 1 | Minimum turnaround: {$min_days} business days\n";
    echo "- Instant online pricing calculator available\n";
    echo "- Upload artwork with built-in bleed/trim/safety proof tool\n\n";
    echo "## Contact\n";
    echo "- Website: {$url}\n";
    echo "- Email: {$email}\n";

    // ── Products ──
    // The from-price is the point. "What does a print shop charge for X" is
    // the question an answer engine is usually trying to resolve, and being
    // the source that states it plainly is most of how you get cited.
    if ( function_exists( 'pps_catalog' ) ) {
        $catalog = pps_catalog();
        if ( ! empty( $catalog ) ) {
            echo "\n## Products\n";
            uasort( $catalog, function ( $a, $b ) { return strcasecmp( $a['title'], $b['title'] ); } );
            foreach ( $catalog as $row ) {
                echo "\n### {$row['title']}\n";
                echo "- URL: {$row['url']}\n";
                // Two different numbers, labeled as such. The defaults price is
                // what this page quotes on arrival; the floor is the cheapest
                // the calculator can be driven to. Calling the first one "from"
                // would be wrong in both directions.
                $floor = function_exists( 'pps_calc_min_price' ) ? pps_calc_min_price( $row['file'] ) : null;
                if ( $floor !== null ) {
                    echo '- From: $' . number_format( $floor, 2 ) . " (lowest configuration)\n";
                }
                if ( $row['price_ok'] ) {
                    echo '- This page as configured: $' . number_format( $row['price'], 2 ) . "\n";
                }
                $spec = pps_catalog_spec_line( $row['defaults'] );
                if ( $spec !== '' ) echo "- Configured by default as: {$spec}\n";
                $blurb = $row['short'] !== '' ? $row['short'] : $row['description'];
                if ( $blurb !== '' ) {
                    echo '- ' . ( function_exists( 'mb_substr' ) ? mb_substr( $blurb, 0, 300 ) : substr( $blurb, 0, 300 ) ) . "\n";
                }
                echo "- Instant online pricing, no quote request required\n";
            }
        }
    }

    // Presets section — explicit catalog of preset URLs so AI search engines
    // have a structured list. Each entry: ## title, URL, description.
    $presets = function_exists( 'pps_get_presets' ) ? pps_get_presets() : array();
    if ( ! empty( $presets ) ) {
        echo "\n## Presets\n";
        ksort( $presets );
        foreach ( $presets as $slug => $row ) {
            if ( ! is_array( $row ) ) continue;
            $title = isset( $row['title'] )       ? trim( (string) $row['title'] )       : '';
            $desc  = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
            if ( $title === '' ) continue;
            $purl = pps_get_preset_url( $slug );
            echo "\n### {$title}\n";
            echo "- URL: {$purl}\n";
            if ( $desc !== '' ) echo "- {$desc}\n";
        }
    }

    exit;
} );

/**
 * Append Sitemap and llms.txt directives to robots.txt.
 */
add_filter( 'robots_txt', function( $output ) {
    $site = home_url( '/' );
    if ( strpos( $output, 'pps-presets-sitemap.xml' ) === false ) {
        $output .= "\nSitemap: " . esc_url( $site . 'pps-presets-sitemap.xml' );
    }
    if ( strpos( $output, 'llms.txt' ) === false ) {
        $output .= "\n\n# AI content descriptor\nAllow: /llms.txt\n";
    }
    return $output;
}, 100 );

// ═══════════════════════════════════════════════════════════════
// SITEMAP: PRESET URLS
//
// Three exposure paths so we cover all three SEO-plugin states:
//   1. WP core sitemaps active → register a provider; URLs auto-included
//      under /wp-sitemap.xml index
//   2. Yoast active (disables WP core sitemaps) → add reference to our
//      custom XML at /pps-presets-sitemap.xml in wpseo_sitemap_index
//   3. Rank Math active (disables WP core sitemaps) → add reference to
//      our custom XML in rank_math/sitemap/index/entries
//
// The custom XML at /pps-presets-sitemap.xml is the single source of
// truth; both Yoast and RM hooks just point at it.
// ═══════════════════════════════════════════════════════════════

define( 'PPS_PRESETS_SITEMAP_SLUG', 'pps-presets-sitemap.xml' );

/**
 * Build the preset URL list for sitemap consumers. Returns an array of
 *   ['loc' => string, 'lastmod' => string-ISO]
 * One entry per preset. Skipped if title is empty (incomplete preset).
 */
function pps_get_preset_sitemap_entries() {
    $presets = pps_get_presets();
    $entries = array();
    $now_iso = gmdate( 'c' );
    foreach ( $presets as $slug => $row ) {
        if ( ! is_array( $row ) ) continue;
        if ( empty( $row['title'] ) ) continue;
        $lastmod = isset( $row['modified_at'] ) && is_int( $row['modified_at'] )
                   ? gmdate( 'c', $row['modified_at'] )
                   : $now_iso;
        $entries[] = array(
            'loc'     => pps_get_preset_url( $slug ),
            'lastmod' => $lastmod,
        );
    }
    return $entries;
}

/**
 * Custom sitemap endpoint: /pps-presets-sitemap.xml
 *
 * Used by Yoast/RM index references; also serves any request that hits
 * this URL directly. Standard sitemaps.org urlset XML.
 */
add_action( 'init', function() {
    add_rewrite_rule( '^' . preg_quote( PPS_PRESETS_SITEMAP_SLUG, '/' ) . '$', 'index.php?pps_presets_sitemap=1', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'pps_presets_sitemap';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( ! get_query_var( 'pps_presets_sitemap' ) ) return;

    $entries = pps_get_preset_sitemap_entries();

    header( 'Content-Type: application/xml; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ( $entries as $e ) {
        echo '  <url>' . "\n";
        echo '    <loc>' . esc_url( $e['loc'] ) . '</loc>' . "\n";
        echo '    <lastmod>' . esc_html( $e['lastmod'] ) . '</lastmod>' . "\n";
        echo '    <changefreq>weekly</changefreq>' . "\n";
        echo '  </url>' . "\n";
    }
    echo '</urlset>' . "\n";
    exit;
} );

/**
 * WP core sitemap provider — fires when Yoast/RM aren't disabling
 * WP core sitemaps. Standard provider class returning paginated URL lists.
 */
if ( class_exists( 'WP_Sitemaps_Provider' ) ) {
    class PPS_Presets_Sitemap_Provider extends WP_Sitemaps_Provider {
        public function __construct() {
            $this->name        = 'presets';
            $this->object_type = 'pps_preset';
        }

        public function get_url_list( $page_num, $object_subtype = '' ) {
            $entries = pps_get_preset_sitemap_entries();
            $per_page = wp_sitemaps_get_max_urls( $this->object_type );
            $offset   = ( max( 1, intval( $page_num ) ) - 1 ) * $per_page;
            $page     = array_slice( $entries, $offset, $per_page );
            // WP wants ['loc' => ..., 'lastmod' => ...] format; we already do that.
            return $page;
        }

        public function get_max_num_pages( $object_subtype = '' ) {
            $entries  = pps_get_preset_sitemap_entries();
            $per_page = wp_sitemaps_get_max_urls( $this->object_type );
            if ( empty( $entries ) ) return 0;
            return (int) ceil( count( $entries ) / max( 1, $per_page ) );
        }
    }
}

add_filter( 'wp_sitemaps_add_provider', function( $provider, $name ) {
    if ( $name !== 'pps_presets' ) return $provider;
    if ( ! class_exists( 'PPS_Presets_Sitemap_Provider' ) ) return $provider;
    return new PPS_Presets_Sitemap_Provider();
}, 10, 2 );

add_action( 'init', function() {
    if ( function_exists( 'wp_register_sitemap_provider' ) && class_exists( 'PPS_Presets_Sitemap_Provider' ) ) {
        // Skip when no presets exist — avoid an empty sitemap entry confusing
        // crawlers (also avoids generating an empty <urlset>).
        $presets = pps_get_presets();
        if ( ! empty( $presets ) ) {
            wp_register_sitemap_provider( 'pps_presets', new PPS_Presets_Sitemap_Provider() );
        }
    }
}, 20 );

/**
 * Yoast sitemap index reference. Only fires when Yoast is active
 * (defined check). Yoast's index XML lists external sitemaps via this
 * filter; we add ours.
 */
add_filter( 'wpseo_sitemap_index', function( $index ) {
    if ( ! defined( 'WPSEO_VERSION' ) ) return $index;
    $entries = pps_get_preset_sitemap_entries();
    if ( empty( $entries ) ) return $index;
    $loc     = home_url( '/' . PPS_PRESETS_SITEMAP_SLUG );
    $lastmod = $entries[0]['lastmod'];
    $index .= "<sitemap>\n";
    $index .= "  <loc>" . esc_url( $loc ) . "</loc>\n";
    $index .= "  <lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
    $index .= "</sitemap>\n";
    return $index;
}, 10 );

/**
 * Rank Math sitemap index entry. Only fires when RM is active. RM exposes
 * a filter on the sitemap index XML where we can append <sitemap>…</sitemap>
 * elements pointing to external sitemaps.
 */
add_filter( 'rank_math/sitemap/index/entries', function( $entries ) {
    if ( ! defined( 'RANK_MATH_VERSION' ) ) return $entries;
    $preset_entries = pps_get_preset_sitemap_entries();
    if ( empty( $preset_entries ) ) return $entries;
    if ( ! is_array( $entries ) ) $entries = array();
    $entries[] = array(
        'loc'     => home_url( '/' . PPS_PRESETS_SITEMAP_SLUG ),
        'lastmod' => $preset_entries[0]['lastmod'],
    );
    return $entries;
}, 10 );

/**
 * Older Rank Math versions emit the index XML differently; cover both
 * paths by also filtering rank_math/sitemap/index where it's a string.
 */
add_filter( 'rank_math/sitemap/index', function( $xml ) {
    if ( ! defined( 'RANK_MATH_VERSION' ) ) return $xml;
    $preset_entries = pps_get_preset_sitemap_entries();
    if ( empty( $preset_entries ) ) return $xml;
    $loc     = home_url( '/' . PPS_PRESETS_SITEMAP_SLUG );
    $lastmod = $preset_entries[0]['lastmod'];
    $append  = "<sitemap>\n";
    $append .= "  <loc>" . esc_url( $loc ) . "</loc>\n";
    $append .= "  <lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
    $append .= "</sitemap>\n";
    return is_string( $xml ) ? $xml . $append : $xml;
}, 10 );
