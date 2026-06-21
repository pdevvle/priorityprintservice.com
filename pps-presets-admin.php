<?php
/**
 * PPS Presets Admin
 *
 * CRUD UI for the pps_presets registry. Each preset publishes a public URL
 * at /booklets/{slug}/ that renders the appropriate calculator with
 * pre-filled defaults.
 *
 * Storage: wp_options['pps_presets'] (associative array keyed by slug).
 * Routing, registry helpers, and render path live in pps-calculators.php.
 *
 * Schema-override fields (Tier 1/2/3) are added by a follow-up commit; this
 * file establishes the basic CRUD plumbing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
// ADMIN MENU
// ═══════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
    add_submenu_page(
        'pps-calculators',
        'Presets',
        '🔗 Presets',
        'manage_options',
        'pps-presets',
        'pps_presets_render_page'
    );
}, 25 );

// ═══════════════════════════════════════════════════════════════
// PAGE DISPATCH
// ═══════════════════════════════════════════════════════════════

function pps_presets_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    // Handle save BEFORE we render so notices reflect the save outcome
    $notice = '';
    if ( isset( $_POST['pps_preset_save'] ) ) {
        check_admin_referer( 'pps_preset_save' );

        $slug    = isset( $_POST['preset_slug'] ) ? sanitize_key( wp_unslash( $_POST['preset_slug'] ) ) : '';
        $orig    = isset( $_POST['preset_orig_slug'] ) ? sanitize_key( wp_unslash( $_POST['preset_orig_slug'] ) ) : '';

        // Decode defaults JSON (textarea field)
        $defaults_arr = array();
        if ( ! empty( $_POST['preset_defaults_json'] ) ) {
            $raw = wp_unslash( $_POST['preset_defaults_json'] );
            if ( strlen( $raw ) > 65536 ) {
                $notice = pps_presets_notice( 'error', 'Defaults JSON exceeds 64KB.' );
            } else {
                $decoded = json_decode( $raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $notice = pps_presets_notice( 'error', 'Defaults JSON: ' . esc_html( json_last_error_msg() ) );
                } elseif ( ! is_array( $decoded ) ) {
                    $notice = pps_presets_notice( 'error', 'Defaults must decode to an object/array.' );
                } else {
                    $defaults_arr = $decoded;
                }
            }
        }

        if ( $notice === '' ) {
            // Tier 1: simple field overrides
            $overrides = array();
            if ( isset( $_POST['preset_overrides'] ) && is_array( $_POST['preset_overrides'] ) ) {
                $overrides = wp_unslash( $_POST['preset_overrides'] );
            }

            // Tier 2: per-block JSON-LD overrides (raw textareas)
            $schema_overrides = array();
            if ( isset( $_POST['preset_schema_overrides'] ) && is_array( $_POST['preset_schema_overrides'] ) ) {
                $schema_overrides = wp_unslash( $_POST['preset_schema_overrides'] );
            }

            // Tier 3: extra schema blocks (array of textareas)
            $schema_extras = array();
            if ( isset( $_POST['preset_schema_extras'] ) && is_array( $_POST['preset_schema_extras'] ) ) {
                foreach ( wp_unslash( $_POST['preset_schema_extras'] ) as $extra_raw ) {
                    if ( is_string( $extra_raw ) && trim( $extra_raw ) !== '' ) {
                        $schema_extras[] = $extra_raw;
                    }
                }
            }

            // Per-preset FAQ override (hidden JSON serialized by JS)
            $faqs_arr = array();
            if ( ! empty( $_POST['preset_faqs_json'] ) ) {
                $faqs_raw = wp_unslash( $_POST['preset_faqs_json'] );
                if ( strlen( $faqs_raw ) <= 524288 ) {
                    $faqs_dec = json_decode( $faqs_raw, true );
                    if ( is_array( $faqs_dec ) ) $faqs_arr = $faqs_dec;
                }
            }

            $categories_arr = array();
            if ( isset( $_POST['preset_categories'] ) && is_array( $_POST['preset_categories'] ) ) {
                $categories_arr = array_map( 'sanitize_key', wp_unslash( $_POST['preset_categories'] ) );
            }

            $data = array(
                'calc'              => isset( $_POST['preset_calc'] )              ? wp_unslash( $_POST['preset_calc'] )              : '',
                'title'             => isset( $_POST['preset_title'] )             ? wp_unslash( $_POST['preset_title'] )             : '',
                'description'       => isset( $_POST['preset_description'] )       ? wp_unslash( $_POST['preset_description'] )       : '',
                'image'             => isset( $_POST['preset_image'] )             ? wp_unslash( $_POST['preset_image'] )             : '',
                'price_from'        => isset( $_POST['preset_price_from'] )        ? wp_unslash( $_POST['preset_price_from'] )        : '',
                'currency'          => isset( $_POST['preset_currency'] )          ? wp_unslash( $_POST['preset_currency'] )          : 'USD',
                'sale_discount_pct' => isset( $_POST['preset_sale_discount_pct'] ) ? wp_unslash( $_POST['preset_sale_discount_pct'] ) : '',
                'sale_label'        => isset( $_POST['preset_sale_label'] )        ? wp_unslash( $_POST['preset_sale_label'] )        : '',
                'defaults'          => $defaults_arr,
                'categories'        => $categories_arr,
                'overrides'         => $overrides,
                'schema_overrides'  => $schema_overrides,
                'schema_extras'     => $schema_extras,
                'faqs'              => $faqs_arr,
            );

            $result = pps_save_preset( $slug, $data );
            if ( is_wp_error( $result ) ) {
                $notice = pps_presets_notice( 'error', esc_html( $result->get_error_message() ) );
            } else {
                // Merge categories into the saved preset. pps_save_preset() will
                // handle this natively once pps-calculators.php is updated; until
                // then this bridge ensures the field persists across saves.
                $presets = pps_get_presets();
                if ( isset( $presets[ $slug ] ) && is_array( $presets[ $slug ] ) ) {
                    $clean_cats = array_values( array_unique( array_filter(
                        array_map( 'sanitize_key', $categories_arr )
                    ) ) );
                    $presets[ $slug ]['categories'] = $clean_cats;
                    update_option( PPS_PRESETS_OPTION, $presets, false );
                }

                // If the slug changed during edit, remove the old entry
                if ( $orig !== '' && $orig !== $slug ) {
                    pps_delete_preset( $orig );
                }
                // Stash cross-field validation warnings (if any) in a one-shot
                // user-scoped transient so they survive the POST → GET redirect.
                $redirect_args = array(
                    'page' => 'pps-presets',
                    'edit' => $slug,
                    'msg'  => 'saved',
                );
                if ( ! empty( $result['_warnings'] ) && is_array( $result['_warnings'] ) ) {
                    $tkey = 'pps_preset_warn_' . get_current_user_id() . '_' . $slug;
                    set_transient( $tkey, $result['_warnings'], 60 );
                    $redirect_args['warn'] = 1;
                }
                wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
                exit;
            }
        }
    }

    if ( isset( $_POST['pps_preset_delete'] ) ) {
        check_admin_referer( 'pps_preset_delete' );
        $slug = isset( $_POST['preset_slug'] ) ? sanitize_key( wp_unslash( $_POST['preset_slug'] ) ) : '';
        if ( $slug !== '' ) {
            pps_delete_preset( $slug );
            wp_safe_redirect( add_query_arg( array(
                'page' => 'pps-presets',
                'msg'  => 'deleted',
            ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    // Read query vars
    $msg  = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
    if ( $msg === 'saved' )   $notice = pps_presets_notice( 'success', 'Preset saved.' );
    if ( $msg === 'deleted' ) $notice = pps_presets_notice( 'success', 'Preset deleted.' );

    $editing_slug = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
    $is_new       = ! empty( $_GET['new'] );

    // Surface cross-field validation warnings stashed by the save handler.
    if ( ! empty( $_GET['warn'] ) && $editing_slug !== '' ) {
        $tkey  = 'pps_preset_warn_' . get_current_user_id() . '_' . $editing_slug;
        $warns = get_transient( $tkey );
        if ( is_array( $warns ) && $warns ) {
            delete_transient( $tkey );
            $items = '';
            foreach ( $warns as $w ) {
                $items .= '<li>' . esc_html( (string) $w ) . '</li>';
            }
            $notice .= '<div class="notice notice-warning is-dismissible">'
                     . '<p><strong>Some override fields were dropped during save:</strong></p>'
                     . '<ul style="margin:0 0 8px 22px;list-style:disc">' . $items . '</ul>'
                     . '</div>';
        }
    }

    echo '<div class="wrap pps-presets-wrap">';
    echo $notice;
    echo '<div class="pps-presets-header">';
    echo '<h1>🔗 PPS Presets</h1>';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=pps-calculators' ) ) . '" class="button button-small">← Calculators</a>';
    if ( ! $is_new && $editing_slug === '' ) {
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=pps-presets&new=1' ) ) . '" class="button button-primary">+ New Preset</a>';
    }
    echo '</div>';

    if ( $is_new ) {
        pps_presets_render_edit_form( null );
    } elseif ( $editing_slug !== '' ) {
        $preset = pps_get_preset( $editing_slug );
        if ( $preset === null ) {
            echo '<div class="notice notice-error"><p>Preset not found: <code>' . esc_html( $editing_slug ) . '</code></p></div>';
            pps_presets_render_list();
        } else {
            pps_presets_render_edit_form( $preset );
        }
    } else {
        pps_presets_render_list();
    }

    echo '</div>';
    pps_presets_render_styles();
}

function pps_presets_notice( $type, $msg ) {
    $cls = $type === 'error' ? 'notice-error' : 'notice-success';
    return '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

// ═══════════════════════════════════════════════════════════════
// LIST VIEW
// ═══════════════════════════════════════════════════════════════

function pps_presets_render_list() {
    $presets = pps_get_presets();
    ksort( $presets );

    if ( empty( $presets ) ) {
        echo '<div class="pps-presets-empty">';
        echo '<p>No presets yet. Each preset publishes a public URL at <code>/' . esc_html( PPS_PRESET_URL_PREFIX ) . '/{slug}/</code> with a pre-filled calculator and its own SEO metadata.</p>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=pps-presets&new=1' ) ) . '" class="button button-primary">+ Create your first preset</a>';
        echo '</div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped pps-presets-table">';
    echo '<thead><tr>';
    echo '<th style="width:22%">Slug / URL</th>';
    echo '<th style="width:12%">Calc</th>';
    echo '<th>Title</th>';
    echo '<th style="width:10%">Price From</th>';
    echo '<th style="width:14%">Actions</th>';
    echo '</tr></thead><tbody>';

    foreach ( $presets as $slug => $row ) {
        $url      = home_url( '/' . PPS_PRESET_URL_PREFIX . '/' . $slug . '/' );
        $edit_url = admin_url( 'admin.php?page=pps-presets&edit=' . $slug );
        $price    = isset( $row['price_from'] ) && $row['price_from'] !== null
                    ? '$' . number_format( (float) $row['price_from'], 2 )
                    : '—';

        echo '<tr>';
        echo '<td><strong>' . esc_html( $slug ) . '</strong><br>';
        echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="font-size:11px;color:#666">' . esc_html( $url ) . '</a></td>';
        echo '<td>' . esc_html( $row['calc'] ?? '' ) . '</td>';
        echo '<td>' . esc_html( $row['title'] ?? '' ) . '<br>';
        echo '<span style="font-size:11px;color:#666">' . esc_html( wp_trim_words( $row['description'] ?? '', 18 ) ) . '</span></td>';
        echo '<td>' . esc_html( $price ) . '</td>';
        echo '<td>';
        echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">Edit</a> ';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete preset \\\'' . esc_js( $slug ) . '\\\'? This removes the URL.\');">';
        wp_nonce_field( 'pps_preset_delete' );
        echo '<input type="hidden" name="preset_slug" value="' . esc_attr( $slug ) . '">';
        echo '<button type="submit" name="pps_preset_delete" class="button button-small button-link-delete">Delete</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// ═══════════════════════════════════════════════════════════════
// EDIT FORM
// ═══════════════════════════════════════════════════════════════

function pps_presets_render_edit_form( $preset ) {
    $is_new   = ( $preset === null );
    $slug     = $is_new ? '' : ( $preset['slug'] ?? '' );
    $orig     = $slug;

    $defaults_json = '';
    if ( ! $is_new && ! empty( $preset['defaults'] ) && is_array( $preset['defaults'] ) ) {
        $defaults_json = wp_json_encode( $preset['defaults'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    $calcs = array(
        'saddle'        => 'Saddle Stitch Booklet',
        'perfect-bound' => 'Perfect Bound Booklet',
        'brochure'      => 'Brochure / Flat',
        'coupon'        => 'Coupon Book',
    );

    $back_url = admin_url( 'admin.php?page=pps-presets' );

    echo '<form method="post" class="pps-preset-form">';
    wp_nonce_field( 'pps_preset_save' );
    echo '<input type="hidden" name="preset_orig_slug" value="' . esc_attr( $orig ) . '">';

    echo '<h2 style="margin-top:18px">' . ( $is_new ? 'New preset' : 'Edit preset' ) . '</h2>';

    echo '<div class="pps-preset-grid">';

    // Slug
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_slug">Slug <span class="req">*</span></label>';
    echo '<input id="preset_slug" type="text" name="preset_slug" value="' . esc_attr( $slug ) . '" pattern="[a-z0-9\-]+" maxlength="80" required>';
    echo '<span class="hint">kebab-case, ≤80 chars. URL: <code>' . esc_html( home_url( '/' . PPS_PRESET_URL_PREFIX . '/' ) ) . '<span id="pps-slug-preview">{slug}</span>/</code></span>';
    echo '</div>';

    // Calc
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_calc">Calculator <span class="req">*</span></label>';
    echo '<select id="preset_calc" name="preset_calc" required>';
    $current_calc = $preset['calc'] ?? '';
    echo '<option value="">— select —</option>';
    foreach ( $calcs as $key => $label ) {
        $sel = ( $key === $current_calc ) ? ' selected' : '';
        echo '<option value="' . esc_attr( $key ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    // Title
    echo '<div class="pps-preset-field pps-preset-wide">';
    echo '<label for="preset_title">Title <span class="req">*</span></label>';
    echo '<input id="preset_title" type="text" name="preset_title" value="' . esc_attr( $preset['title'] ?? '' ) . '" maxlength="200" required>';
    echo '<span class="hint">Page title, OG title, Product schema name. ≤200 chars; SERP truncates around 60.</span>';
    echo '</div>';

    // Description
    echo '<div class="pps-preset-field pps-preset-wide">';
    echo '<label for="preset_description">Description</label>';
    echo '<textarea id="preset_description" name="preset_description" rows="3" maxlength="500">' . esc_textarea( $preset['description'] ?? '' ) . '</textarea>';
    echo '<span class="hint">Meta description, OG description, Product schema description. ≤500 chars; SERP truncates around 160.</span>';
    echo '</div>';

    // Image
    echo '<div class="pps-preset-field pps-preset-wide">';
    echo '<label for="preset_image">Image URL</label>';
    echo '<input id="preset_image" type="url" name="preset_image" value="' . esc_attr( $preset['image'] ?? '' ) . '">';
    echo '<span class="hint">Absolute http/https URL. Used for og:image, twitter:image, Product schema image.</span>';
    echo '</div>';

    // Price from
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_price_from">Price from</label>';
    $price_val = ( isset( $preset['price_from'] ) && $preset['price_from'] !== null ) ? (float) $preset['price_from'] : '';
    echo '<input id="preset_price_from" type="number" name="preset_price_from" value="' . esc_attr( $price_val ) . '" min="0" step="0.01">';
    echo '<span class="hint">Used as Offer.lowPrice. Leave blank to omit Offer entirely.</span>';
    echo '</div>';

    // Currency
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_currency">Currency</label>';
    echo '<input id="preset_currency" type="text" name="preset_currency" value="' . esc_attr( $preset['currency'] ?? 'USD' ) . '" pattern="[A-Z]{3}" maxlength="3">';
    echo '<span class="hint">ISO 4217.</span>';
    echo '</div>';

    // Sale % (per-preset override; 0 = use site-wide)
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_sale_discount_pct">Sale %</label>';
    $sale_pct_val = ( isset( $preset['sale_discount_pct'] ) && $preset['sale_discount_pct'] !== '' ) ? (float) $preset['sale_discount_pct'] : '';
    echo '<input id="preset_sale_discount_pct" type="number" name="preset_sale_discount_pct" value="' . esc_attr( $sale_pct_val ) . '" min="0" max="0.5" step="0.01">';
    echo '<span class="hint">0 (or blank) = use site-wide PCF setting. Decimal e.g. 0.15 = 15% off. Capped at 0.5.</span>';
    echo '</div>';

    // Sale label
    echo '<div class="pps-preset-field">';
    echo '<label for="preset_sale_label">Sale Label</label>';
    echo '<input id="preset_sale_label" type="text" name="preset_sale_label" value="' . esc_attr( $preset['sale_label'] ?? '' ) . '" maxlength="80">';
    echo '<span class="hint">Shown in price breakdown + badge. Blank = use site-wide PCF label.</span>';
    echo '</div>';

    // Categories
    $all_cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
    $preset_cats = isset( $preset['categories'] ) && is_array( $preset['categories'] ) ? $preset['categories'] : array();
    if ( ! is_wp_error( $all_cats ) && ! empty( $all_cats ) ) {
        echo '<div class="pps-preset-field pps-preset-wide">';
        echo '<label>Category pages</label>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:4px">';
        foreach ( $all_cats as $cat ) {
            $checked = in_array( $cat->slug, $preset_cats, true ) ? ' checked' : '';
            echo '<label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer">';
            echo '<input type="checkbox" name="preset_categories[]" value="' . esc_attr( $cat->slug ) . '"' . $checked . '>';
            echo esc_html( $cat->name );
            echo '</label>';
        }
        echo '</div>';
        echo '<span class="hint">Preset card appears in the product lineup on checked category pages.</span>';
        echo '</div>';
    }

    echo '</div>';

    // Defaults JSON
    echo '<div class="pps-preset-field pps-preset-wide" style="margin-top:8px">';
    echo '<label for="preset_defaults_json">Defaults (JSON)</label>';
    echo '<textarea id="preset_defaults_json" name="preset_defaults_json" rows="10" placeholder=\'{"qty": 250, "pages": 16, "size": "5.5x8.5", ...}\'>' . esc_textarea( $defaults_json ) . '</textarea>';
    echo '<span class="hint">Same shape as a calculator\'s _pps_defaults postmeta. Pre-fills the form on the preset URL. JSON only — strings/numbers/booleans/arrays/null. Recursively sanitized on save (string fields capped at 1000 chars; max 200 keys total).</span>';
    echo '</div>';

    // ── Tier 1: SEO field overrides ──
    $current_overrides = isset( $preset['overrides'] ) && is_array( $preset['overrides'] ) ? $preset['overrides'] : array();
    pps_presets_render_override_fields( $current_overrides );

    // ── Tier 2: schema block overrides (per-block JSON-LD) ──
    $current_blocks = isset( $preset['schema_overrides'] ) && is_array( $preset['schema_overrides'] ) ? $preset['schema_overrides'] : array();
    pps_presets_render_schema_block_overrides( $current_blocks );

    // ── Tier 3: extra schema blocks ──
    $current_extras = isset( $preset['schema_extras'] ) && is_array( $preset['schema_extras'] ) ? $preset['schema_extras'] : array();
    pps_presets_render_schema_extras( $current_extras );

    // ── Per-preset FAQ override ──
    $current_faqs = isset( $preset['faqs'] ) && is_array( $preset['faqs'] ) ? $preset['faqs'] : array();
    pps_presets_render_faq_override( $current_faqs, $preset['calc'] ?? '' );

    // Actions
    echo '<div class="pps-preset-actions">';
    echo '<button type="submit" name="pps_preset_save" class="button button-primary">💾 Save preset</button> ';
    echo '<a href="' . esc_url( $back_url ) . '" class="button">Cancel</a>';
    if ( ! $is_new ) {
        echo '<a href="' . esc_url( home_url( '/' . PPS_PRESET_URL_PREFIX . '/' . $slug . '/' ) ) . '" class="button" target="_blank" rel="noopener" style="margin-left:auto">View preset URL ↗</a>';
    }
    echo '</div>';

    echo '</form>';

    // Live slug preview
    echo '<script>
    (function() {
        var slug  = document.getElementById("preset_slug");
        var prev  = document.getElementById("pps-slug-preview");
        if (!slug || !prev) return;
        function render() { prev.textContent = slug.value || "{slug}"; }
        slug.addEventListener("input", render);
        render();
    })();
    </script>';
}

// ═══════════════════════════════════════════════════════════════
// OVERRIDE UI BLOCKS
// ═══════════════════════════════════════════════════════════════

/**
 * Tier 1 — discrete field-level overrides. Each Product schema field
 * (and existing meta/SEO fields) gets its own input. Empty values fall
 * back to the computed value at emit time.
 *
 * UI groups (one accordion, four labeled sub-sections):
 *   - SEO meta + breadcrumb
 *   - Product schema — basic
 *   - Product schema — identifiers
 *   - Offer
 *   - Aggregate rating
 */
function pps_presets_render_override_fields( $current ) {
    if ( ! is_array( $current ) ) $current = array();

    // Field schema: render config per key. Strategy maps to input type.
    //   text        -> <input type="text">
    //   textarea    -> <textarea>
    //   url         -> <input type="url">
    //   url_csv     -> <input type="text"> with CSV placeholder
    //   number      -> <input type="number">  (use min/max/step/precision attrs from $opts)
    //   date        -> <input type="date">
    //   select      -> <select> with options from $opts['options']
    $sec_meta = array(
        'meta_title'        => array( 'text',     'Meta title',        'Overrides <title>, og:title, twitter:title.', array( 'maxlength' => 200 ) ),
        'breadcrumb_label'  => array( 'text',     'Breadcrumb label',  'Overrides the leaf node in BreadcrumbList. Defaults to title.', array( 'maxlength' => 200 ) ),
        'meta_description'  => array( 'textarea', 'Meta description',  'Overrides meta description, og:description, twitter:description. ≤320 chars.', array( 'maxlength' => 320, 'rows' => 2, 'wide' => true ) ),
        'og_image'          => array( 'url',      'OG image URL',      'Overrides og:image, twitter:image. http/https only.', array( 'wide' => true ) ),
    );
    $sec_basic = array(
        'schema_name'                       => array( 'text',     'Product.name',                'Overrides Product.name. Defaults to title.', array( 'maxlength' => 200 ) ),
        'schema_sku'                        => array( 'text',     'Product.sku',                 'Defaults to slug. [a-zA-Z0-9_-] ≤80.', array( 'maxlength' => 80 ) ),
        'schema_brand'                      => array( 'text',     'Product.brand.name',          'Defaults to site name.', array( 'maxlength' => 120 ) ),
        'schema_category'                   => array( 'text',     'Product.category',            'Defaults to "Print services". e.g., "Printing Services > Booklets".', array( 'maxlength' => 200 ) ),
        'schema_description'                => array( 'textarea', 'Product.description',         'Distinct from meta description if you want SERP copy ≠ schema copy. ≤500.', array( 'maxlength' => 500, 'rows' => 3, 'wide' => true ) ),
        'schema_image'                      => array( 'url_csv',  'Product.image (CSV)',         'Comma-separated http/https URLs (max 6). Defaults to OG image when blank.', array( 'wide' => true ) ),
        'schema_url'                        => array( 'url',      'Product.url',                 'Override canonical URL on Product node only. Rare.', array() ),
        'schema_additional_type'            => array( 'url',      'Product.additionalType',      'e.g., https://schema.org/Booklet. Adds a more specific subtype.', array() ),
        'schema_audience'                   => array( 'text',     'Product.audience',            'audienceType, e.g. "Small businesses".', array( 'maxlength' => 200 ) ),
        'schema_disambiguating_description' => array( 'text',     'disambiguatingDescription',   'Short distinguishing line vs. similar products.', array( 'maxlength' => 200 ) ),
        'schema_color'                      => array( 'text',     'Product.color',               'e.g., "Full color CMYK".', array( 'maxlength' => 120 ) ),
        'schema_material'                   => array( 'text',     'Product.material',            'e.g., "100lb gloss text stock".', array( 'maxlength' => 120 ) ),
    );
    $sec_ident = array(
        'schema_gtin' => array( 'text', 'Product.gtin', 'UPC/EAN/ISBN. Alphanumeric only, ≤32. Leave blank if not assigned.', array( 'maxlength' => 32 ) ),
        'schema_mpn'  => array( 'text', 'Product.mpn',  'Manufacturer part number. [a-zA-Z0-9_-] ≤64.', array( 'maxlength' => 64 ) ),
    );
    $avail_opts = array( '' => '— default (InStock) —', 'InStock' => 'InStock', 'OutOfStock' => 'OutOfStock', 'PreOrder' => 'PreOrder', 'Discontinued' => 'Discontinued', 'SoldOut' => 'SoldOut' );
    $cond_opts  = array( '' => '— default (NewCondition) —', 'NewCondition' => 'NewCondition', 'RefurbishedCondition' => 'RefurbishedCondition', 'UsedCondition' => 'UsedCondition', 'DamagedCondition' => 'DamagedCondition' );
    $sec_offer = array(
        'offer_price'             => array( 'number', 'Offer.price',           'Overrides "Price from". If only this is set → Offer.price.',    array( 'min' => 0, 'step' => '0.01' ) ),
        'offer_price_high'        => array( 'number', 'Offer.highPrice',       'If set with offer_price → switches to AggregateOffer with range.', array( 'min' => 0, 'step' => '0.01' ) ),
        'offer_price_currency'    => array( 'text',   'Offer.priceCurrency',   'ISO 4217 (3 letters). Overrides preset currency.', array( 'maxlength' => 3, 'placeholder' => 'USD' ) ),
        'offer_availability'      => array( 'select', 'Offer.availability',    'Default InStock when blank.', array( 'options' => $avail_opts ) ),
        'offer_item_condition'    => array( 'select', 'Offer.itemCondition',   'Default NewCondition when blank.', array( 'options' => $cond_opts ) ),
        'offer_price_valid_until' => array( 'date',   'Offer.priceValidUntil', 'YYYY-MM-DD. Default = Dec 31 next year.', array() ),
        'offer_url'               => array( 'url',    'Offer.url',             'Defaults to canonical preset URL.', array( 'wide' => true ) ),
    );
    $sec_rating = array(
        'rating_value' => array( 'number', 'ratingValue', 'Average rating, 0–5.',                  array( 'min' => 0, 'max' => 5, 'step' => '0.1' ) ),
        'rating_count' => array( 'number', 'reviewCount', 'Total reviews, integer ≥1.',           array( 'min' => 1, 'step' => '1' ) ),
        'rating_best'  => array( 'number', 'bestRating',  'Default 5. Change only for non-5-star.', array( 'min' => 0, 'max' => 5, 'step' => '0.1', 'placeholder' => '5' ) ),
        'rating_worst' => array( 'number', 'worstRating', 'Default 1. Change only for non-5-star.', array( 'min' => 0, 'max' => 5, 'step' => '0.1', 'placeholder' => '1' ) ),
    );

    $set_count = count( array_filter( $current, function ( $v ) { return $v !== '' && $v !== null; } ) );

    echo '<details class="pps-preset-collapse">';
    echo '<summary>SEO field overrides <span class="pps-preset-collapse-count">' . (int) $set_count . ' set</span></summary>';
    echo '<div class="pps-preset-collapse-body">';
    echo '<p class="pps-preset-collapse-hint">Leave any field blank to use the computed value. Tier 2 (full block override) takes precedence over Tier 1 fields.</p>';

    pps_presets_render_override_subsection( 'SEO meta + breadcrumb',           $sec_meta,   $current, 'pps-preset-grid' );
    pps_presets_render_override_subsection( 'Product schema — basic',          $sec_basic,  $current, 'pps-preset-grid' );
    pps_presets_render_override_subsection( 'Product schema — identifiers',    $sec_ident,  $current, 'pps-preset-grid' );
    pps_presets_render_override_subsection( 'Offer',                           $sec_offer,  $current, 'pps-preset-grid pps-preset-grid--3col' );
    pps_presets_render_override_subsection( 'Aggregate rating',                $sec_rating, $current, 'pps-preset-grid pps-preset-grid--4col',
        'All four fields required for aggregateRating to emit. Use only for verifiable on-page reviews.' );

    echo '</div>';
    echo '</details>';
}

/**
 * Render a single sub-section: <h4> header + grid of fields.
 */
function pps_presets_render_override_subsection( $title, $fields, $current, $grid_class, $hint = '' ) {
    echo '<h4 class="pps-preset-subsection">' . esc_html( $title ) . '</h4>';
    if ( $hint !== '' ) {
        echo '<p class="pps-preset-collapse-hint" style="margin-top:-2px">' . esc_html( $hint ) . '</p>';
    }
    echo '<div class="' . esc_attr( $grid_class ) . '">';
    foreach ( $fields as $key => $field ) {
        list( $strategy, $label, $hint, $opts ) = $field;
        $val  = isset( $current[ $key ] ) ? $current[ $key ] : '';
        // url_csv: stored as string OR array; flatten for the input.
        if ( is_array( $val ) ) $val = implode( ', ', $val );
        $wide = ! empty( $opts['wide'] );
        echo '<div class="pps-preset-field' . ( $wide ? ' pps-preset-wide' : '' ) . '">';
        echo '<label>' . esc_html( $label ) . '</label>';
        $name_attr = 'preset_overrides[' . esc_attr( $key ) . ']';

        switch ( $strategy ) {
            case 'textarea':
                $rows = isset( $opts['rows'] ) ? (int) $opts['rows'] : 2;
                $maxl = isset( $opts['maxlength'] ) ? ' maxlength="' . (int) $opts['maxlength'] . '"' : '';
                echo '<textarea name="' . $name_attr . '" rows="' . (int) $rows . '"' . $maxl . '>' . esc_textarea( (string) $val ) . '</textarea>';
                break;
            case 'select':
                $options = isset( $opts['options'] ) ? $opts['options'] : array();
                echo '<select name="' . $name_attr . '">';
                foreach ( $options as $ov => $ol ) {
                    echo '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $val, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
                }
                echo '</select>';
                break;
            case 'number':
                $attrs = '';
                if ( isset( $opts['min'] ) )  $attrs .= ' min="' . esc_attr( $opts['min'] ) . '"';
                if ( isset( $opts['max'] ) )  $attrs .= ' max="' . esc_attr( $opts['max'] ) . '"';
                if ( isset( $opts['step'] ) ) $attrs .= ' step="' . esc_attr( $opts['step'] ) . '"';
                if ( isset( $opts['placeholder'] ) ) $attrs .= ' placeholder="' . esc_attr( $opts['placeholder'] ) . '"';
                echo '<input type="number" name="' . $name_attr . '" value="' . esc_attr( (string) $val ) . '"' . $attrs . '>';
                break;
            case 'date':
                echo '<input type="date" name="' . $name_attr . '" value="' . esc_attr( (string) $val ) . '">';
                break;
            case 'url':
                $maxl = isset( $opts['maxlength'] ) ? ' maxlength="' . (int) $opts['maxlength'] . '"' : '';
                echo '<input type="url" name="' . $name_attr . '" value="' . esc_attr( (string) $val ) . '"' . $maxl . '>';
                break;
            case 'url_csv':
                echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr( (string) $val ) . '" placeholder="https://a.com/1.jpg, https://b.com/2.jpg">';
                break;
            case 'text':
            default:
                $maxl = isset( $opts['maxlength'] ) ? ' maxlength="' . (int) $opts['maxlength'] . '"' : '';
                $ph   = isset( $opts['placeholder'] ) ? ' placeholder="' . esc_attr( $opts['placeholder'] ) . '"' : '';
                echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr( (string) $val ) . '"' . $maxl . $ph . '>';
                break;
        }
        echo '<span class="hint">' . esc_html( $hint ) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Tier 2 — per-block JSON-LD textarea. Top-level keys present in the
 * pasted object override the auto-generated block; absent keys fall
 * back to the auto-generated value (shallow merge — no need to
 * re-paste the whole schema).
 */
function pps_presets_render_schema_block_overrides( $current ) {
    $blocks = array(
        'product'        => 'Product',
        'breadcrumb'     => 'BreadcrumbList',
        'localbusiness'  => 'LocalBusiness',
        'faq'            => 'FAQPage',
        'webapp'         => 'WebApplication',
    );

    $set_count = 0;
    foreach ( $blocks as $k => $_ ) if ( ! empty( $current[ $k ] ) ) $set_count++;

    echo '<details class="pps-preset-collapse">';
    echo '<summary>Schema block overrides <span class="pps-preset-collapse-count">' . $set_count . ' set</span></summary>';
    echo '<div class="pps-preset-collapse-body">';
    echo '<p class="pps-preset-collapse-hint">Paste a partial or full JSON-LD object. <strong>Top-level keys you supply replace the auto-generated value; missing keys fall through to the auto-generated default</strong> — so you can paste just <code>{"aggregateRating": {…}}</code> without losing name/description/offers/etc. Nested objects are not deep-merged: if you supply <code>offers</code>, you must supply the full Offer object. Validated on save (root must be an object with @type; ≤50KB; HTML stripped from string values; renders with extra escaping for &lt; and &gt; to prevent script-tag breakout).</p>';

    foreach ( $blocks as $key => $label ) {
        $val = isset( $current[ $key ] ) && is_array( $current[ $key ] )
               ? wp_json_encode( $current[ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
               : '';
        echo '<div class="pps-preset-field pps-preset-wide" style="margin-bottom:10px">';
        echo '<label>' . esc_html( $label ) . '</label>';
        echo '<textarea name="preset_schema_overrides[' . esc_attr( $key ) . ']" rows="6" placeholder="Leave empty to use 100% auto-generated &quot;' . esc_attr( $label ) . '&quot; — or paste a partial object to override specific top-level keys.">' . esc_textarea( $val ) . '</textarea>';
        echo '</div>';
    }

    echo '</div>';
    echo '</details>';
}

/**
 * Tier 3 — extras: array of arbitrary JSON-LD blocks. Repeater of
 * textareas. Each non-empty entry emits an additional <script> with id
 * pps-schema-extra-N.
 */
function pps_presets_render_schema_extras( $current ) {
    $count = is_array( $current ) ? count( array_filter( $current, 'is_array' ) ) : 0;

    echo '<details class="pps-preset-collapse">';
    echo '<summary>Extra schema blocks <span class="pps-preset-collapse-count">' . $count . ' set</span></summary>';
    echo '<div class="pps-preset-collapse-body" data-pps-extras-wrap>';
    echo '<p class="pps-preset-collapse-hint">Each block emits a separate &lt;script type="application/ld+json"&gt; tag. Useful for HowTo, VideoObject, Service, Event, etc. Cap: 12 extras per preset; 50KB each.</p>';

    if ( ! empty( $current ) && is_array( $current ) ) {
        foreach ( $current as $i => $extra ) {
            if ( ! is_array( $extra ) ) continue;
            $val = wp_json_encode( $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            echo '<div class="pps-preset-field pps-preset-wide pps-extra-row" style="margin-bottom:8px">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:3px">';
            echo '<label style="margin:0">Extra ' . ( $i + 1 ) . '</label>';
            echo '<span class="pps-extra-del" title="Remove">&times;</span>';
            echo '</div>';
            echo '<textarea name="preset_schema_extras[]" rows="6">' . esc_textarea( $val ) . '</textarea>';
            echo '</div>';
        }
    }

    // Always include one blank row so admin can add without JS
    echo '<div class="pps-preset-field pps-preset-wide pps-extra-row" style="margin-bottom:8px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:3px">';
    echo '<label style="margin:0">+ New extra</label>';
    echo '<span class="pps-extra-del" title="Remove">&times;</span>';
    echo '</div>';
    echo '<textarea name="preset_schema_extras[]" rows="4" placeholder=\'{"@context":"https://schema.org","@type":"HowTo","name":"…"}\'></textarea>';
    echo '</div>';

    echo '<button type="button" class="pps-extra-add">+ Add another extra</button>';
    echo '</div>';
    echo '</details>';
}

/**
 * Per-preset FAQ override — same Q+A repeater as the SEO tab. If empty,
 * calc-type defaults from wp_options['pps_faqs'] are used.
 */
function pps_presets_render_faq_override( $current, $calc_type ) {
    $count = is_array( $current ) ? count( $current ) : 0;

    echo '<details class="pps-preset-collapse">';
    echo '<summary>Custom FAQs <span class="pps-preset-collapse-count">' . $count . ' set</span></summary>';
    echo '<div class="pps-preset-collapse-body" data-pps-faqs-wrap>';
    echo '<p class="pps-preset-collapse-hint">If any FAQ here is set, this preset uses these instead of the calculator\'s default FAQs (managed in Central Config &rarr; SEO). Per-preset cap 50; Q ≤512ch; A ≤4096ch.</p>';

    // Hidden JSON serialization target (filled by JS on submit)
    echo '<textarea name="preset_faqs_json" class="pps-preset-faqs-hidden" style="display:none">' . esc_textarea( wp_json_encode( $current, JSON_UNESCAPED_UNICODE ) ) . '</textarea>';

    echo '<div class="pps-preset-faq-rows">';
    $rows_to_render = ! empty( $current ) ? $current : array( array( 'q' => '', 'a' => '' ) );
    foreach ( $rows_to_render as $entry ) {
        $q = isset( $entry['q'] ) ? (string) $entry['q'] : '';
        $a = isset( $entry['a'] ) ? (string) $entry['a'] : '';
        echo '<div class="pps-preset-faq-row" data-pps-preset-faq-row="1">';
        echo '<div class="pps-preset-faq-q-a">';
        echo '<input type="text" data-faq-field="q" value="' . esc_attr( $q ) . '" placeholder="Question" maxlength="512">';
        echo '<textarea data-faq-field="a" rows="3" placeholder="Answer" maxlength="4096">' . esc_textarea( $a ) . '</textarea>';
        echo '</div>';
        echo '<span class="pps-preset-faq-del" title="Delete FAQ">&times;</span>';
        echo '</div>';
    }
    echo '</div>';
    echo '<button type="button" class="pps-preset-faq-add">+ Add FAQ</button>';

    echo '</div>';
    echo '</details>';
}

// ═══════════════════════════════════════════════════════════════
// STYLES
// ═══════════════════════════════════════════════════════════════

function pps_presets_render_styles() {
    ?>
    <style>
        .pps-presets-wrap { max-width: 1100px; }
        .pps-presets-header { display:flex; align-items:center; gap:12px; margin:16px 0 16px; }
        .pps-presets-header h1 { margin:0; font-size:20px; }

        .pps-presets-empty { background:#fff; border:1px solid #ddd; padding:32px; text-align:center; border-radius:4px; }
        .pps-presets-empty p { font-size:14px; color:#555; margin-bottom:14px; }

        .pps-presets-table th { font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#555; }
        .pps-presets-table td { font-size:13px; vertical-align:top; padding:8px 10px; }
        .pps-presets-table code { font-size:11px; background:transparent; }

        .pps-preset-form { background:#fff; border:1px solid #ddd; padding:18px 22px; border-radius:4px; margin-top:8px; }
        .pps-preset-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px 24px; }
        .pps-preset-grid.pps-preset-grid--3col { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .pps-preset-grid.pps-preset-grid--4col { grid-template-columns:repeat(4, minmax(0, 1fr)); }
        .pps-preset-subsection { margin:14px 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#555; font-weight:600; }
        .pps-preset-collapse-body > .pps-preset-subsection:first-child { margin-top:0; }
        .pps-preset-field { display:flex; flex-direction:column; gap:4px; font-size:12px; }
        .pps-preset-field.pps-preset-wide { grid-column:1 / -1; }
        .pps-preset-field label { font-weight:600; color:#1d2327; }
        .pps-preset-field .req { color:#b32d2e; }
        .pps-preset-field input[type="text"],
        .pps-preset-field input[type="url"],
        .pps-preset-field input[type="number"],
        .pps-preset-field select,
        .pps-preset-field textarea { padding:6px 9px; border:1px solid #ccc; border-radius:3px; font-size:13px; box-sizing:border-box; font-family:inherit; }
        .pps-preset-field textarea { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; resize:vertical; min-height:60px; }
        .pps-preset-field .hint { color:#888; font-size:11px; margin-top:1px; }
        .pps-preset-field .hint code { font-size:11px; }

        .pps-preset-actions { display:flex; gap:10px; align-items:center; margin-top:18px; padding-top:14px; border-top:1px solid #eee; }

        /* Collapsible override sections */
        .pps-preset-collapse { margin-top:14px; border:1px solid #e0e0e0; border-radius:4px; background:#fafafa; }
        .pps-preset-collapse > summary { padding:8px 12px; font-size:12px; font-weight:600; color:#1d2327; cursor:pointer; user-select:none; outline:none; }
        .pps-preset-collapse > summary:hover { background:#f0f0f0; }
        .pps-preset-collapse[open] > summary { border-bottom:1px solid #e0e0e0; }
        .pps-preset-collapse-count { color:#888; font-weight:400; font-size:11px; margin-left:6px; }
        .pps-preset-collapse-body { padding:12px; background:#fff; }
        .pps-preset-collapse-hint { font-size:11px; color:#888; margin:0 0 10px; line-height:1.5; }

        /* Tier 3 extras */
        .pps-extra-row textarea { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; }
        .pps-extra-add { font-size:11px; color:#007eff; cursor:pointer; padding:3px 10px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-extra-add:hover { background:#e8f4ff; }
        .pps-extra-del { cursor:pointer; color:#b32d2e; font-size:14px; line-height:1; user-select:none; padding:0 4px; }
        .pps-extra-del:hover { background:#fce4e4; border-radius:2px; }

        /* Per-preset FAQ override */
        .pps-preset-faq-rows { display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
        .pps-preset-faq-row { display:grid; grid-template-columns:1fr 16px; gap:6px; align-items:start; padding:6px; background:#fafafa; border:1px solid #e0e0e0; border-radius:3px; }
        .pps-preset-faq-q-a { display:flex; flex-direction:column; gap:4px; }
        .pps-preset-faq-row input, .pps-preset-faq-row textarea { padding:4px 6px; border:1px solid #ccc; border-radius:2px; font-size:12px; font-family:inherit; }
        .pps-preset-faq-row textarea { resize:vertical; min-height:48px; }
        .pps-preset-faq-del { cursor:pointer; color:#b32d2e; font-size:16px; text-align:center; line-height:24px; user-select:none; }
        .pps-preset-faq-del:hover { background:#fce4e4; border-radius:2px; }
        .pps-preset-faq-add { font-size:11px; color:#007eff; cursor:pointer; padding:3px 10px; border:1px dashed #007eff; border-radius:3px; background:none; }
        .pps-preset-faq-add:hover { background:#e8f4ff; }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ── On submit: serialize FAQ rows into hidden JSON field ──
        var form = document.querySelector('.pps-preset-form');
        if (form) {
            form.addEventListener('submit', function() {
                var hidden = document.querySelector('.pps-preset-faqs-hidden');
                if (!hidden) return;
                var rows = document.querySelectorAll('[data-pps-preset-faq-row]');
                var entries = [];
                rows.forEach(function(row) {
                    var qEl = row.querySelector('[data-faq-field="q"]');
                    var aEl = row.querySelector('[data-faq-field="a"]');
                    var q = qEl ? qEl.value.trim() : '';
                    var a = aEl ? aEl.value.trim() : '';
                    if (q !== '' && a !== '') entries.push({ q: q, a: a });
                });
                hidden.value = JSON.stringify(entries);
            });
        }

        // ── FAQ override: add row ──
        document.querySelectorAll('.pps-preset-faq-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrap = this.closest('.pps-preset-collapse-body');
                if (!wrap) return;
                var rowsWrap = wrap.querySelector('.pps-preset-faq-rows');
                if (!rowsWrap) return;
                var template = rowsWrap.querySelector('[data-pps-preset-faq-row]');
                var newRow;
                if (template) {
                    newRow = template.cloneNode(true);
                    newRow.querySelectorAll('input, textarea').forEach(function(el) { el.value = ''; });
                } else {
                    newRow = document.createElement('div');
                    newRow.setAttribute('data-pps-preset-faq-row', '1');
                    newRow.className = 'pps-preset-faq-row';
                    newRow.innerHTML =
                        '<div class="pps-preset-faq-q-a">' +
                          '<input type="text" data-faq-field="q" placeholder="Question" maxlength="512">' +
                          '<textarea data-faq-field="a" rows="3" placeholder="Answer" maxlength="4096"></textarea>' +
                        '</div>' +
                        '<span class="pps-preset-faq-del" title="Delete FAQ">&times;</span>';
                }
                rowsWrap.appendChild(newRow);
                var firstInput = newRow.querySelector('input,textarea');
                if (firstInput) firstInput.focus();
            });
        });

        // ── FAQ override: delete row ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.pps-preset-faq-del');
            if (!del) return;
            var row = del.closest('[data-pps-preset-faq-row]');
            var rowsWrap = row && row.parentNode;
            if (row && rowsWrap) {
                if (rowsWrap.querySelectorAll('[data-pps-preset-faq-row]').length > 1) {
                    row.remove();
                } else {
                    row.querySelectorAll('input, textarea').forEach(function(el) { el.value = ''; });
                }
            }
        });

        // ── Tier 3 extras: add row ──
        document.querySelectorAll('.pps-extra-add').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrap = this.closest('[data-pps-extras-wrap]');
                if (!wrap) return;
                var existing = wrap.querySelectorAll('.pps-extra-row');
                var nextNum  = existing.length + 1;
                var newRow = document.createElement('div');
                newRow.className = 'pps-preset-field pps-preset-wide pps-extra-row';
                newRow.style.marginBottom = '8px';
                newRow.innerHTML =
                    '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:3px">' +
                      '<label style="margin:0">+ New extra</label>' +
                      '<span class="pps-extra-del" title="Remove">&times;</span>' +
                    '</div>' +
                    '<textarea name="preset_schema_extras[]" rows="4" placeholder=\'{"@context":"https://schema.org","@type":"…","name":"…"}\'></textarea>';
                this.parentNode.insertBefore(newRow, this);
                var ta = newRow.querySelector('textarea');
                if (ta) ta.focus();
            });
        });

        // ── Tier 3 extras: delete row ──
        document.addEventListener('click', function(e) {
            var del = e.target.closest('.pps-extra-del');
            if (!del) return;
            var row = del.closest('.pps-extra-row');
            if (row) {
                var ta = row.querySelector('textarea');
                if (ta) ta.value = '';
                // Leave at least one row in the DOM so admin can always add
                var siblings = row.parentNode.querySelectorAll('.pps-extra-row');
                if (siblings.length > 1) row.remove();
            }
        });
    });
    </script>
    <?php
}
