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
 * Test Shippo API connection — validates the configured token.
 * Makes a lightweight address-creation call to verify authentication.
 */
add_action( 'wp_ajax_pps_test_shippo', function() {
    check_ajax_referer( 'pps_config_save', '_wpnonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $cfg   = pps_get_config();
    $token = trim( $cfg['pcf']['shippo_api_token'] ?? '' );
    if ( empty( $token ) ) {
        wp_send_json_error( 'No Shippo API token configured. Enter a token and save first.' );
    }

    // Lightweight validation: create a test address (non-validate mode, minimal data)
    $resp = wp_remote_post( 'https://api.goshippo.com/addresses/', array(
        'headers' => array(
            'Authorization' => 'ShippoToken ' . $token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( array(
            'name'    => 'PPS Connection Test',
            'street1' => '1234 Test St',
            'city'    => 'Phoenix',
            'state'   => 'AZ',
            'zip'     => '85027',
            'country' => 'US',
        ) ),
        'timeout' => 10,
    ) );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'Network error: ' . $resp->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $resp );
    $body = json_decode( wp_remote_retrieve_body( $resp ), true );

    if ( $code === 401 ) {
        wp_send_json_error( 'Authentication failed — check your API token.' );
    }
    if ( $code >= 400 ) {
        wp_send_json_error( 'Shippo returned HTTP ' . $code . ': ' . ( $body['detail'] ?? 'Unknown error' ) );
    }

    wp_send_json_success( array(
        'message'    => 'Connected to Shippo successfully.',
        'object_id'  => $body['object_id'] ?? null,
        'origin_zip' => $cfg['pcf']['shippo_origin_zip'] ?? '85027',
    ) );
} );

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
// SEO: SUPPRESS THIRD-PARTY SCHEMAS ON CALCULATOR PAGES
// ═══════════════════════════════════════════════════════════════

/**
 * Remove WooCommerce and SEO plugin structured data on PPS calculator product pages.
 * Our plugin injects its own schemas tailored to the calculator.
 */
add_action( 'wp_head', function() {
    if ( ! is_product() ) return;
    global $product;
    if ( ! $product ) return;
    if ( ! pps_get_calculator_for_product( $product->get_id() ) ) return;

    // WooCommerce native structured data
    if ( WC() && isset( WC()->structured_data ) ) {
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
 * Inject structured data schemas on calculator product pages.
 * Runs via wp_head so schemas appear before page content.
 */
add_action( 'wp_head', function() {
    if ( ! is_product() ) return;
    global $product;
    if ( ! $product ) return;
    $product_id = $product->get_id();
    if ( ! pps_get_calculator_for_product( $product_id ) ) return;

    $site_url    = home_url( '/' );
    $product_url = get_permalink( $product_id );
    $product_img = wp_get_attachment_url( $product->get_image_id() ) ?: '';
    $product_name = $product->get_name();
    $config      = function_exists( 'pps_get_config' ) ? pps_get_config() : array();

    // ── Product + AggregateOffer ──
    $product_schema = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        'name'        => $product_name,
        'description' => 'Custom printed saddle-stitched booklets with full color or greyscale printing, multiple paper options, and finishing services including UV coating, round cornering, and bundling.',
        'brand'       => array( '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) ),
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
        'additionalProperty' => array(
            array( '@type' => 'PropertyValue', 'name' => 'Binding', 'value' => 'Saddle Stitch (Stapled)' ),
            array( '@type' => 'PropertyValue', 'name' => 'Page Count', 'value' => '8 to 64 pages' ),
            array( '@type' => 'PropertyValue', 'name' => 'Minimum Quantity', 'value' => '1' ),
            array( '@type' => 'PropertyValue', 'name' => 'Turnaround', 'value' => ( $config['minimum_turnaround_days'] ?? 3 ) . '+ business days' ),
        ),
    );
    echo '<script type="application/ld+json" id="pps-schema-product">' . wp_json_encode( $product_schema, JSON_UNESCAPED_SLASHES ) . "</script>\n";

    // ── LocalBusiness ──
    $seo = $config['seo'] ?? array();
    $business_schema = array(
        '@context'       => 'https://schema.org',
        '@type'          => 'LocalBusiness',
        'additionalType' => 'https://schema.org/ProfessionalService',
        'name'           => get_bloginfo( 'name' ),
        'description'    => 'Full-service commercial print shop specializing in saddle-stitch booklets, brochures, and custom printing with fast turnaround.',
        'url'            => $site_url,
        'telephone'      => $seo['phone'] ?? '',
        'email'          => $seo['email'] ?? get_option( 'admin_email' ),
        'address'        => array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => $seo['street'] ?? '',
            'addressLocality' => $seo['city'] ?? 'Phoenix',
            'addressRegion'   => $seo['state'] ?? 'AZ',
            'postalCode'      => $seo['zip'] ?? '85027',
            'addressCountry'  => 'US',
        ),
        'priceRange'  => '$$',
        'areaServed'  => array( '@type' => 'Country', 'name' => 'United States' ),
        'knowsAbout'  => array( 'Saddle Stitch Booklets', 'Digital Printing', 'Offset Printing', 'UV Coating', 'Booklet Binding' ),
    );
    if ( ! empty( $seo['lat'] ) && ! empty( $seo['lng'] ) ) {
        $business_schema['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => $seo['lat'], 'longitude' => $seo['lng'] );
    }
    echo '<script type="application/ld+json" id="pps-schema-business">' . wp_json_encode( $business_schema, JSON_UNESCAPED_SLASHES ) . "</script>\n";

    // ── FAQPage ──
    $faq_schema = array(
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array(
            array( '@type' => 'Question', 'name' => 'What is saddle stitch booklet binding?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Saddle stitch binding uses staples along the spine fold to hold pages together. It\'s the most cost-effective binding method for booklets up to 64 pages.' ) ),
            array( '@type' => 'Question', 'name' => 'What is the minimum page count for a saddle stitch booklet?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'The minimum page count is 8 pages (including the cover). Page counts must be in multiples of 4 since each sheet creates 4 pages when folded.' ) ),
            array( '@type' => 'Question', 'name' => 'What paper options are available?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'We offer text weight papers (70lb Uncoated, 80lb Matte, 100lb Gloss) and cardstock options (80lb through 18pt) for both inside pages and covers. Cardstock insides are available for booklets of 24 pages or less.' ) ),
            array( '@type' => 'Question', 'name' => 'What is the turnaround time for booklet printing?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Minimum turnaround is ' . intval( $config['minimum_turnaround_days'] ?? 3 ) . ' business days. Turnaround varies based on quantity, paper selection, and finishing options. Rush options are available.' ) ),
            array( '@type' => 'Question', 'name' => 'What sizes of booklets can you print?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'We print standard sizes from 3.5x5.5 up to 12x9, including square formats (4x4 through 12x12) and landscape orientations. Custom sizes are also available.' ) ),
            array( '@type' => 'Question', 'name' => 'Do I need to add bleeds to my artwork?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'For edge-to-edge printing, artwork should include 0.125 inch (1/8") bleed on all sides. If your artwork doesn\'t have bleeds, we offer a bleed setup service.' ) ),
            array( '@type' => 'Question', 'name' => 'What finishing options are available?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Available options include UV Gloss or UV Matte coating on covers, round cornering, bundling in groups of 25/50/100, two-staple binding, and enhanced vivid quality printing.' ) ),
        ),
    );
    echo '<script type="application/ld+json" id="pps-schema-faq">' . wp_json_encode( $faq_schema, JSON_UNESCAPED_SLASHES ) . "</script>\n";

    // ── WebApplication ──
    $webapp_schema = array(
        '@context'            => 'https://schema.org',
        '@type'               => 'WebApplication',
        'name'                => $product_name . ' Price Calculator',
        'description'         => 'Instant online pricing calculator for custom saddle-stitched booklets. Configure size, paper, quantity, and finishing options for a real-time quote.',
        'url'                 => $product_url,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem'     => 'Any',
        'browserRequirements' => 'Requires JavaScript',
        'offers'              => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => get_woocommerce_currency(), 'description' => 'Free to use' ),
        'creator'             => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
    );
    echo '<script type="application/ld+json" id="pps-schema-webapp">' . wp_json_encode( $webapp_schema, JSON_UNESCAPED_SLASHES ) . "</script>\n";
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

    exit;
} );
