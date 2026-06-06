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
// SHOP CLOSURES & TRANSIT (from config admin or fallback)
// ═══════════════════════════════════════════════════════════════

function pps_get_closures() {
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        return isset( $cfg['closures'] ) ? $cfg['closures'] : array();
    }
    return array( '01-01', '07-04', '12-24', '12-25', '11-28', '11-29' );
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
    return get_option( PPS_CALC_OPTION, array() );
}

function pps_save_registry( $reg ) {
    update_option( PPS_CALC_OPTION, $reg, false );
}

/**
 * Find calculator filename assigned to a product ID.
 */
function pps_get_calculator_for_product( $product_id ) {
    $reg = pps_get_registry();
    foreach ( $reg as $filename => $meta ) {
        $ids = array_map( 'intval', array_filter( preg_split( '/[\s,]+/', $meta['products'] ?? '' ) ) );
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
        $action = sanitize_text_field( $_POST['pps_action'] );

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
            $fn = sanitize_file_name( $_POST['pps_filename'] ?? '' );
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
            $fn       = sanitize_file_name( $_POST['pps_filename'] ?? '' );
            $new_name = sanitize_text_field( $_POST['pps_new_name'] ?? '' );
            if ( isset( $reg[ $fn ] ) && $new_name ) {
                $reg[ $fn ]['name'] = $new_name;
                pps_save_registry( $reg );
                echo '<div class="notice notice-success is-dismissible"><p>Renamed to <strong>' . esc_html( $new_name ) . '</strong></p></div>';
            }
        }

        // Delete
        if ( $action === 'delete' ) {
            $fn = sanitize_file_name( $_POST['pps_filename'] ?? '' );
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
                $product_ids  = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $products_str ) ) );
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
    );

    // <style>...</style> block
    if ( preg_match( '/<style>(.*?)<\/style>/s', $html, $m ) ) {
        $parts['styles'] = $m[1];
    }

    // <script type="text/babel">...</script> app code
    if ( preg_match( '/<script type="text\/babel">(.*)<\/script>/s', $html, $m ) ) {
        $parts['app_code'] = trim( $m[1] );
    }

    return $parts;
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
        wp_enqueue_script( 'pps-pdfjs', 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', array(), '3.11.174', true );
        wp_add_inline_script( 'pps-pdfjs', "pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';" );
        // Babel must load after React so transpiled code can find it
        wp_enqueue_script( 'pps-babel', 'https://unpkg.com/@babel/standalone@7.26.9/babel.min.js', array( 'pps-react', 'pps-react-dom', 'pps-pdfjs' ), '7.26.9', true );
    });

    // Embed calculator inline
    add_action( 'woocommerce_after_single_product_summary', function() use ( $filepath, $product_id ) {
        $html  = file_get_contents( $filepath );
        $parts = pps_parse_calculator_html( $html );

        // Build config object for the calculator JS
        $config = array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'cartUrl'     => wc_get_cart_url(),
            'cartNonce'   => wp_create_nonce( 'pps_add_to_cart' ),
            'uploadNonce' => wp_create_nonce( 'pps_upload_artwork' ),
            'productId'   => $product_id,
        );

        // Inject central config so calculator reads from admin settings
        if ( function_exists( 'pps_get_config' ) ) {
            $config['calc'] = pps_get_config();
        }

        // Inject tooltip content for RichTip components
        $tips = get_option( 'pps_tooltips', array() );
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

        // Edit mode: store edit key so add-to-cart replaces the old item
        if ( ! empty( $_GET['pps_edit_key'] ) ) {
            $edit_key = sanitize_text_field( $_GET['pps_edit_key'] );
            // Verify the cart item actually exists
            if ( WC()->cart && isset( WC()->cart->get_cart()[ $edit_key ] ) ) {
                WC()->session->set( 'pps_edit_key_' . $product_id, $edit_key );
                $config['editMode'] = true;

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
        echo '<script>window.PPS_CONFIG=' . wp_json_encode( $config ) . ';</script>';

        // ── Edit mode banner + button text override ──
        if ( ! empty( $config['editMode'] ) ) {
            echo '<div id="pps-edit-banner" style="background:#007eff;color:#fff;padding:10px 16px;border-radius:4px;margin:12px 0;font-size:14px;display:flex;align-items:center;gap:8px">
                <span style="font-size:18px">✏️</span>
                <span><strong>Edit Mode</strong> — Update your specs below, then click the button to save changes.</span>
                <a href="' . esc_url( wc_get_cart_url() ) . '" style="margin-left:auto;color:#fff;opacity:0.8;font-size:12px;text-decoration:underline">Cancel</a>
            </div>';
            echo '<script>
            (function(){
                var observer = new MutationObserver(function(){
                    var btns = document.querySelectorAll("#pps-calculator-wrap button");
                    btns.forEach(function(b){
                        if (b.textContent.match(/add to cart/i) && !b.dataset.ppsEdited) {
                            b.textContent = b.textContent.replace(/add to cart/i, "Update Cart");
                            b.dataset.ppsEdited = "1";
                        }
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
        if ( $parts['app_code'] ) {
            echo '<script type="text/babel">' . $parts['app_code'] . '</script>';
        }
    }, 5 );
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

    $allowed = array( 'pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'eps', 'ai' );
    if ( ! in_array( $ext, $allowed, true ) ) {
        wp_send_json_error( 'File type not allowed: .' . $ext );
    }

    if ( $file['size'] > 200 * 1024 * 1024 ) {
        wp_send_json_error( 'File too large (max 200MB).' );
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
// the staff (admin_email by default; override via pps_question_recipient
// option) and send a confirmation back to the customer. Honeypot + rate
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
    $reorder = get_post_meta( $post->ID, '_pps_q_reorder_url', true );
    if ( $reorder ) {
        echo '<p style="margin-top:10px"><a href="' . esc_url( $reorder ) . '" target="_blank" class="button button-primary" style="width:100%;text-align:center">Open this quote in calculator →</a></p>';
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
    // Recipient resolution: PCF (admin-editable) → legacy option → WP admin_email.
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

function pps_ajax_add_to_cart() {
    check_ajax_referer( 'pps_add_to_cart', 'nonce' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $price      = floatval( $_POST['pps_price'] ?? 0 );
    $rush       = floatval( $_POST['pps_rush'] ?? 0 );
    $summary    = sanitize_textarea_field( $_POST['pps_summary'] ?? '' );
    $metadata   = wp_unslash( $_POST['pps_metadata'] ?? '{}' );
    $biz_days   = intval( $_POST['pps_biz_days'] ?? 5 );

    if ( ! $product_id || $price <= 0 ) {
        wp_send_json_error( 'Invalid product or price.' );
    }

    json_decode( $metadata );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'Invalid metadata JSON.' );
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
    $artwork_path = sanitize_text_field( $_POST['pps_artwork_path'] ?? '' );
    if ( $artwork_path ) {
        // Security: prevent path traversal — must start with pps-artwork/ and contain no ..
        if ( strpos( $artwork_path, '..' ) !== false || strpos( $artwork_path, 'pps-artwork/' ) !== 0 ) {
            wp_send_json_error( 'Invalid artwork path.' );
        }
        $cart_item_data['pps_artwork_path'] = $artwork_path;
    }

    if ( ! WC()->cart ) {
        wp_send_json_error( 'Cart not available.' );
    }

    $cart_item_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

    if ( $cart_item_key ) {
        // Edit mode: now safe to remove old item since new one succeeded
        if ( $edit_key ) {
            WC()->cart->remove_cart_item( $edit_key );
            WC()->session->set( 'pps_edit_key_' . $product_id, null );
        }
        wp_send_json_success( array( 'cart_item_key' => $cart_item_key ) );
    } else {
        wp_send_json_error( 'Could not add to cart.' );
    }
}

// ═══════════════════════════════════════════════════════════════
// CART: SESSION PERSISTENCE
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
    $keys = array( 'pps_price', 'pps_rush', 'pps_summary', 'pps_metadata', 'pps_biz_days', 'pps_hash', 'pps_artwork_path' );
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

    $lines = array_filter( explode( "\n", $cart_item['pps_summary'] ) );
    foreach ( $lines as $line ) {
        $parts = explode( ':', $line, 2 );
        if ( count( $parts ) === 2 ) {
            $data[] = array( 'key' => trim( $parts[0] ), 'value' => trim( $parts[1] ) );
        } elseif ( trim( $line ) ) {
            $data[] = array( 'key' => 'Configuration', 'value' => trim( $line ) );
        }
    }

    if ( isset( $cart_item['pps_biz_days'] ) ) {
        $tz = 'America/Phoenix';
        if ( function_exists( 'pps_get_config' ) ) {
            $cfg = pps_get_config();
            $tz  = $cfg['pcf']['shop_timezone'] ?? $tz;
        }
        $delivery = pps_add_business_days(
            new DateTime( 'now', new DateTimeZone( $tz ) ),
            intval( $cart_item['pps_biz_days'] )
        );
        $data[] = array(
            'key'     => 'Estimated Delivery',
            'value'   => $delivery->format( 'l, M j, Y' ),
            'display' => '<strong style="color:#46882c">' . $delivery->format( 'l, M j, Y' ) . '</strong>',
        );
    }

    return $data;
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
// CART: EDIT SPECS LINK
// ═══════════════════════════════════════════════════════════════

add_filter( 'woocommerce_cart_item_name', function( $name, $cart_item, $cart_item_key ) {
    if ( ! isset( $cart_item['pps_metadata'] ) ) return $name;

    $product = wc_get_product( $cart_item['product_id'] );
    if ( ! $product ) return $name;

    $full = json_decode( $cart_item['pps_metadata'], true );
    if ( ! $full ) return $name;

    // Build reorder config (same fields as My Account reorder)
    $reorder_fields = array(
        'sizeLabel', 'customLong', 'customShort', 'bindDir',
        'sets', 'insideColor', 'coverColor',
        'insidePaper', 'insidePaperType',
        'coverMode', 'coverPaper', 'coverPaperType',
        'twoStaple', 'vividPrint',
        'coating', 'bundling', 'roundCorner',
        'artwork', 'artEditPages', 'bleed', 'proof',
        'shipState',
    );
    $reorder_config = array();
    foreach ( $reorder_fields as $key ) {
        if ( isset( $full[ $key ] ) ) $reorder_config[ $key ] = $full[ $key ];
    }

    // Include quantity if present
    if ( isset( $full['qty'] ) ) $reorder_config['qty'] = $full['qty'];

    // Include artwork file info for edit mode
    if ( ! empty( $cart_item['pps_artwork_path'] ) ) {
        $reorder_config['artworkPath'] = $cart_item['pps_artwork_path'];
        $reorder_config['artworkFilename'] = basename( $cart_item['pps_artwork_path'] );
    }

    $encoded = rtrim( strtr( base64_encode( json_encode( $reorder_config ) ), '+/', '-_' ), '=' );
    $url = add_query_arg( array(
        'pps_reorder'  => $encoded,
        'pps_edit_key' => $cart_item_key,
    ), $product->get_permalink() );

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

function pps_add_business_days( DateTime $start, int $days ): DateTime {
    $closures = pps_get_closures();
    $d = clone $start;
    $added = 0;

    while ( $added < $days ) {
        $d->modify( '+1 day' );
        $dow = (int) $d->format( 'N' );
        if ( $dow >= 6 ) continue;

        $ymd  = $d->format( 'Y-m-d' );
        $mmdd = $d->format( 'm-d' );
        if ( in_array( $ymd, $closures, true ) || in_array( $mmdd, $closures, true ) ) continue;

        $added++;
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

    // Snapshot delivery date at checkout moment
    $tz = 'America/Phoenix';
    if ( function_exists( 'pps_get_config' ) ) {
        $cfg = pps_get_config();
        $tz  = $cfg['pcf']['shop_timezone'] ?? $tz;
    }
    $biz_days = intval( $values['pps_biz_days'] ?? 5 );
    $delivery = pps_add_business_days( new DateTime( 'now', new DateTimeZone( $tz ) ), $biz_days );

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
        $size     = $full['sizeLabel'] ?? 'Unknown';
        $iPaper   = is_array( $full['insidePaper'] ?? null ) ? ( $full['insidePaper']['label'] ?? '' ) : '';
        $iColor   = ( $full['insideColor'] ?? '' ) === 'bw' ? 'BW' : 'Color';
        $proof    = ( $full['proof'] ?? 0 ) >= 3 ? 'Hardcopy' : ( ( $full['proof'] ?? 0 ) > 0 ? 'DigitalProof' : 'SelfApproved' );
        $rush     = ( $full['rushCost'] ?? 0 ) > 0 ? 'RUSH' : 'Standard';
        $days     = intval( $full['days'] ?? $biz_days );
        $sets_ct  = count( $sets );

        $spec = implode( ' | ', array(
            $size,
            $totalQty . 'qty',
            $totalPg . 'pg',
            $sets_ct . ( $sets_ct === 1 ? 'set' : 'sets' ),
            $iPaper,
            $iColor,
            $proof,
            $rush,
            $days . 'days',
        ) );
        $item->add_meta_data( 'PPS-Spec', $spec, true );

        // Production start date — distinct label for Missive rule parsing
        $prodStart = $full['productionStartDate'] ?? '';
        if ( $prodStart ) {
            $item->add_meta_data( 'PPS-Production-Start', $prodStart, true );
        }
    }
}, 10, 4 );

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
    return $hidden;
});

// ═══════════════════════════════════════════════════════════════
// PER-PRODUCT DEFAULTS (WooCommerce Product Editor)
// ═══════════════════════════════════════════════════════════════

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
        $fields = array(
            'sizeLabel'       => array( 'label' => 'Size', 'placeholder' => '5.5×8.5 (Opens to 8.5×11)' ),
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
        ?>
    </div>
    <?php
} );

add_action( 'woocommerce_process_product_meta', function( $post_id ) {
    if ( ! current_user_can( 'edit_products' ) ) return;
    if ( ! isset( $_POST['pps_defaults'] ) ) return;
    $raw = array_map( 'sanitize_text_field', $_POST['pps_defaults'] );
    // Strip empty values so they fall through to global defaults
    $clean = array_filter( $raw, function( $v ) { return $v !== ''; } );
    if ( ! empty( $clean ) ) {
        update_post_meta( $post_id, '_pps_defaults', $clean );
    } else {
        delete_post_meta( $post_id, '_pps_defaults' );
    }
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

        $reorder_fields = array(
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

    // POST /wp-json/pps/v1/shipping/transit-estimate — Shippo transit time by zip
    // Lightweight call: just origin zip + destination zip → UPS ground transit days
    register_rest_route( 'pps/v1', '/shipping/transit-estimate', array(
        'methods'             => 'POST',
        'permission_callback' => function() { return is_user_logged_in(); },
        'callback'            => function( $request ) {
            $cfg   = pps_get_config();
            $token = $cfg['pcf']['shippo_api_token'] ?? '';
            if ( empty( $token ) ) {
                return new WP_Error( 'no_shippo', 'Shippo API token not configured', array( 'status' => 501 ) );
            }
            $data        = $request->get_json_params();
            $origin_zip  = $cfg['pcf']['shippo_origin_zip'] ?? '85027';
            $dest_zip    = sanitize_text_field( $data['zip'] ?? '' );
            $dest_state  = strtoupper( sanitize_text_field( $data['state'] ?? '' ) );
            $dest_country = sanitize_text_field( $data['country'] ?? 'US' );

            if ( strlen( $dest_zip ) < 5 ) {
                return new WP_Error( 'bad_zip', 'ZIP code must be at least 5 digits', array( 'status' => 400 ) );
            }

            // Create a shipment with minimal parcel to get rate estimates with transit days
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
                'parcels' => array( array(
                    'length'        => '12',
                    'width'         => '10',
                    'height'        => '2',
                    'distance_unit' => 'in',
                    'weight'        => '2',
                    'mass_unit'     => 'lb',
                ) ),
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

            // Find UPS Ground (or cheapest ground service)
            $ground = null;
            foreach ( $rates as $rate ) {
                $svc = strtolower( $rate['servicelevel']['token'] ?? '' );
                if ( strpos( $svc, 'ground' ) !== false || strpos( $svc, 'ups_ground' ) !== false ) {
                    $ground = $rate;
                    break;
                }
            }

            // Fallback: cheapest rate with estimated_days
            if ( ! $ground ) {
                usort( $rates, function( $a, $b ) {
                    return ( $a['estimated_days'] ?? 99 ) - ( $b['estimated_days'] ?? 99 );
                } );
                $ground = $rates[0] ?? null;
            }

            return rest_ensure_response( array(
                'transit_days'  => $ground['estimated_days'] ?? null,
                'carrier'       => $ground['provider'] ?? null,
                'service'       => $ground['servicelevel']['name'] ?? null,
                'amount'        => $ground['amount'] ?? null,
                'currency'      => $ground['currency'] ?? 'USD',
                'domestic'      => strtoupper( $dest_country ) === 'US',
            ) );
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
        'vivid' => array(
            'title' => 'Enhanced Vivid Printing',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Our vivid print mode runs your job through an enhanced print profile that produces richer blacks, more saturated colors, and sharper detail. Great for photography-heavy pieces and marketing materials.' ),
                array( 'type' => 'text', 'value' => 'Adds approximately 1 day per 2,500 sheets to turnaround. Cost is roughly equivalent to a second press pass.' ),
            ),
        ),
        'coating' => array(
            'title' => 'UV Cover Coating',
            'content' => array(
                array( 'type' => 'text', 'value' => 'A liquid UV coating applied after printing. Enhances durability, color vibrancy, and provides a professional finish.' ),
                array( 'type' => 'text', 'value' => 'UV Gloss: High-shine reflective finish that makes colors pop. UV Matte: Soft, non-reflective finish with a velvety feel.' ),
                array( 'type' => 'text', 'value' => 'Requires a glossy or coated paper. Cannot be applied to uncoated stocks.' ),
            ),
        ),
        'bundling' => array(
            'title' => 'Bundling',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Pieces are counted and bundled in your chosen quantity using kraft paper or shrink wrap. Keeps your order organized and protected during shipping and storage.' ),
                array( 'type' => 'text', 'value' => 'Available for orders of 100+ pieces. Bundle sizes: 25, 50, or 100 per bundle.' ),
            ),
        ),
        'round_cornering' => array(
            'title' => 'Round Cornering',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Rounds the corners of your finished piece for a softer, modern look. Available in two radius sizes and two corner configurations.' ),
                array( 'type' => 'text', 'value' => 'Outside 2: rounds the two corners on the open (non-spine) edge. All 4: rounds every corner including the spine side.' ),
                array( 'type' => 'text', 'value' => '1/4" radius is subtle and professional. 3/8" radius is more pronounced and playful.' ),
            ),
        ),
        'perforation' => array(
            'title' => 'Perforation',
            'content' => array(
                array( 'type' => 'text', 'value' => 'A line of small holes punched through the paper, allowing a section to be torn off cleanly. Common for reply cards, coupons, and tear-off tabs.' ),
                array( 'type' => 'text', 'value' => 'Available for select sizes. Perforation lines run parallel to the fold or binding edge.' ),
            ),
        ),
        'outfold' => array(
            'title' => 'Fold-Out Page (Outfold)',
            'content' => array(
                array( 'type' => 'text', 'value' => 'An extra panel that folds out from the booklet, like a brochure insert inside the book. Printed full color on both sides.' ),
                array( 'type' => 'text', 'value' => 'Adds both printing cost (full color) and folding labor. Available as single or double fold-out.' ),
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
        'paper_cardstock' => array(
            'title' => 'Cardstock',
            'content' => array(
                array( 'type' => 'text', 'value' => 'Heavier, rigid paper typically used for covers. Measured in points (pt) or pounds (lb).' ),
                array( 'type' => 'text', 'value' => '80lb Cardstock: Light card, good for self-mailers. 14pt C1S: One glossy side, one uncoated — premium cover stock. 16pt C2S: Glossy both sides, thick and sturdy.' ),
            ),
        ),
    );
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
// /booklets/{slug}/ that renders the appropriate calculator HTML with
// the preset's defaults pre-filled into PPS_CONFIG.defaults.
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
define( 'PPS_PRESET_URL_PREFIX', 'booklets' );

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
    );
    return isset( $map[ $calc_type ] ) ? $map[ $calc_type ] : '';
}

/**
 * Read the full preset registry. Always returns an array (possibly empty).
 */
function pps_get_presets() {
    $raw = get_option( PPS_PRESETS_OPTION, array() );
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
    return isset( $presets[ $slug ] ) && is_array( $presets[ $slug ] ) ? $presets[ $slug ] : null;
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

    $allowed_calcs = array( 'saddle', 'perfect-bound', 'brochure', 'coupon' );
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
        'overrides'         => $overrides,
        'schema_overrides'  => $schema_overrides,
        'schema_extras'     => $schema_extras,
        'faqs'              => $faqs,
        'modified_at'       => time(), // sitemap <lastmod>; updated on every save
    );

    $presets = pps_get_presets();
    $presets[ $slug ] = $clean;
    update_option( PPS_PRESETS_OPTION, $presets, false );

    // Rewrite rules don't depend on slug list (the regex catches all slugs),
    // but flush anyway so newly-added presets are discoverable on first hit
    // without a manual "Settings → Permalinks" save.
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
 *  - Keys → sanitize_key
 *
 * Cap: 200 keys total at any depth to prevent admin-side DoS.
 */
function pps_sanitize_defaults_blob( $data, &$key_count = null ) {
    if ( $key_count === null ) $key_count = 0;
    if ( ! is_array( $data ) ) return array();
    $out = array();
    foreach ( $data as $k => $v ) {
        if ( $key_count++ > 200 ) break;
        $clean_key = is_int( $k ) ? $k : sanitize_key( (string) $k );
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
    $post->guid           = home_url( '/' . PPS_PRESET_URL_PREFIX . '/' . $slug . '/' );
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
    wp_enqueue_script( 'pps-pdfjs',     'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', array(), '3.11.174', true );
    wp_add_inline_script( 'pps-pdfjs', "pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';" );
    wp_enqueue_script( 'pps-babel',     'https://unpkg.com/@babel/standalone@7.26.9/babel.min.js', array( 'pps-react', 'pps-react-dom', 'pps-pdfjs' ), '7.26.9', true );
} );

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
        // No productId — preset render does not target a single WC product.
        // The cart layer should fall back to a calc-type → product map; that
        // mapping is wired in a follow-up PR alongside per-line preset slug
        // capture in order meta. For now the preset-URL calculator renders
        // and computes prices but add-to-cart goes through the existing
        // calculator's own product binding (when present).
        'presetSlug'  => $preset['slug'],
    );

    if ( function_exists( 'pps_get_config' ) ) {
        $config['calc'] = pps_get_config();
    }

    $tips = get_option( 'pps_tooltips', array() );
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

    echo '<script>window.PPS_CONFIG=' . wp_json_encode( $config ) . ';</script>';

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

    if ( $parts['app_code'] ) {
        echo '<script type="text/babel">' . $parts['app_code'] . '</script>';
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
 * Routing: register rewrite rule + query var for /booklets/{slug}/.
 */
add_action( 'init', function() {
    add_rewrite_rule(
        '^' . PPS_PRESET_URL_PREFIX . '/([a-z0-9\-]+)/?$',
        'index.php?pps_preset=$matches[1]',
        'top'
    );
} );
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'pps_preset';
    return $vars;
} );

/**
 * Flush rewrite rules on plugin activation so the rewrite rule is live
 * immediately. (Other rewrite changes — e.g. preset add/edit/delete —
 * also call flush_rewrite_rules() in pps_save_preset/pps_delete_preset.)
 */
register_activation_hook( __FILE__, function() {
    add_rewrite_rule(
        '^' . PPS_PRESET_URL_PREFIX . '/([a-z0-9\-]+)/?$',
        'index.php?pps_preset=$matches[1]',
        'top'
    );
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
 * JSON encoding flags used for every JSON-LD <script> we emit.
 *
 * JSON_UNESCAPED_SLASHES keeps https:// readable and matches the existing
 * output verbatim. We do NOT add JSON_HEX_TAG here even though it would
 * harden against '</script>' breakout, because all current call sites
 * sanitize input upstream:
 *   - FAQ Q field    → sanitize_text_field (strips tags)
 *   - FAQ A field    → wp_strip_all_tags before emit
 *   - GBP URL field  → esc_url_raw with http/https allowlist
 *   - GBP numerics   → float/int casts
 *
 * Future PRs that add user-pasted raw schema input (Tier 2/3 overrides)
 * should reintroduce JSON_HEX_TAG (or post-process '</' → '<\/') as part
 * of that work, with a deliberate diff.
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
    return home_url( '/' . PPS_PRESET_URL_PREFIX . '/' . $slug . '/' );
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
            'lowPrice'        => '50',
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

    // ── Open Graph ──
    echo "<meta property=\"og:type\" content=\"product\">\n";
    echo '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
    echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n";
    echo '<meta property="og:url" content="' . esc_url( $url ) . "\">\n";
    echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . "\">\n";
    if ( $image !== '' ) {
        echo '<meta property="og:image" content="' . esc_url( $image ) . "\">\n";
    }

    // ── Twitter Card ──
    $twitter_card = $image !== '' ? 'summary_large_image' : 'summary';
    echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . "\">\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . "\">\n";
    echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . "\">\n";
    if ( $image !== '' ) {
        echo '<meta name="twitter:image" content="' . esc_url( $image ) . "\">\n";
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
            array( 'name' => 'Booklets',   'url' => home_url( '/' . PPS_PRESET_URL_PREFIX . '/' ) ),
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
    echo "> Full-service commercial print shop in Phoenix, AZ specializing in custom saddle-stitch booklet printing with online instant pricing.\n\n";
    echo "## Services\n";
    echo "- Custom saddle-stitch (stapled) booklet printing\n";
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

// ═══════════════════════════════════════════════════════════════
// ONE-SHOT SELF-INSTALL: seed registry + copy calc-*.html to uploads
//
// Runs once on first request after deploy. Self-disables via the
// pps_calculators_seeded option. Safe to leave in place — won't re-run.
// ═══════════════════════════════════════════════════════════════

add_action( 'init', function() {
    if ( get_option( 'pps_calculators_seeded' ) === 'yes' ) return;

    $calcs = array(
        'calc-preview-test.html'   => 'Saddle Stitch Booklets',
        'calc-perfect-bound.html'  => 'Perfect Bound Booklets',
        'calc-brochure.html'       => 'Brochures',
        'calc-coupon-book.html'    => 'Coupon Books',
    );

    $plugin_dir = PPS_CALC_DIR;
    $upload_dir = pps_upload_dir();

    $registry = get_option( 'pps_calculators_registry', array() );
    if ( ! is_array( $registry ) ) $registry = array();

    $now_mysql = current_time( 'mysql' );
    $any_copied = false;

    foreach ( $calcs as $filename => $display_name ) {
        $src = $plugin_dir . $filename;
        $dst = trailingslashit( $upload_dir ) . $filename;

        if ( ! file_exists( $src ) ) continue;
        if ( ! @copy( $src, $dst ) ) continue;
        $any_copied = true;

        $existing_products = '';
        if ( isset( $registry[ $filename ]['products'] ) && is_string( $registry[ $filename ]['products'] ) ) {
            $existing_products = $registry[ $filename ]['products'];
        }

        $registry[ $filename ] = array(
            'name'     => $display_name,
            'products' => $existing_products,
            'uploaded' => $now_mysql,
        );
    }

    if ( $any_copied ) {
        update_option( 'pps_calculators_registry', $registry, false );
        update_option( 'pps_calculators_seeded', 'yes', false );
    }
}, 5 );
