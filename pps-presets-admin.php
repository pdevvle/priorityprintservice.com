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
            $data = array(
                'calc'        => isset( $_POST['preset_calc'] )        ? wp_unslash( $_POST['preset_calc'] )        : '',
                'title'       => isset( $_POST['preset_title'] )       ? wp_unslash( $_POST['preset_title'] )       : '',
                'description' => isset( $_POST['preset_description'] ) ? wp_unslash( $_POST['preset_description'] ) : '',
                'image'       => isset( $_POST['preset_image'] )       ? wp_unslash( $_POST['preset_image'] )       : '',
                'price_from'  => isset( $_POST['preset_price_from'] )  ? wp_unslash( $_POST['preset_price_from'] )  : '',
                'currency'    => isset( $_POST['preset_currency'] )    ? wp_unslash( $_POST['preset_currency'] )    : 'USD',
                'defaults'    => $defaults_arr,
            );

            $result = pps_save_preset( $slug, $data );
            if ( is_wp_error( $result ) ) {
                $notice = pps_presets_notice( 'error', esc_html( $result->get_error_message() ) );
            } else {
                // If the slug changed during edit, remove the old entry
                if ( $orig !== '' && $orig !== $slug ) {
                    pps_delete_preset( $orig );
                }
                $notice = pps_presets_notice( 'success', 'Preset saved.' );
                // Redirect to edit view of the saved preset (POST → GET)
                wp_safe_redirect( add_query_arg( array(
                    'page' => 'pps-presets',
                    'edit' => $slug,
                    'msg'  => 'saved',
                ), admin_url( 'admin.php' ) ) );
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

    echo '</div>';

    // Defaults JSON
    echo '<div class="pps-preset-field pps-preset-wide" style="margin-top:8px">';
    echo '<label for="preset_defaults_json">Defaults (JSON)</label>';
    echo '<textarea id="preset_defaults_json" name="preset_defaults_json" rows="10" placeholder=\'{"qty": 250, "pages": 16, "size": "5.5x8.5", ...}\'>' . esc_textarea( $defaults_json ) . '</textarea>';
    echo '<span class="hint">Same shape as a calculator\'s _pps_defaults postmeta. Pre-fills the form on the preset URL. JSON only — strings/numbers/booleans/arrays/null. Recursively sanitized on save (string fields capped at 1000 chars; max 200 keys total).</span>';
    echo '</div>';

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
    </style>
    <?php
}
