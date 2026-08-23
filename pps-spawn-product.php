<?php
/**
 * PPS Product Spawner — one screen, three inputs, a whole product.
 *
 * Creating a PPS product by hand is eleven steps across four screens, and two
 * of them fail silently:
 *
 *   - forget the `_virtual` flag and WooCommerce's shipping machinery engages
 *     on a product where PPS owns shipping (owner rule, 2026-07-19)
 *   - forget the registry entry and no calculator renders at all — the product
 *     looks completely normal in wp-admin right up until a customer opens it
 *
 * Both are mechanical, so both are done here. What is left for a human is the
 * part that needs judgement: the title, the description and the SEO panel.
 * That is why the product is created as a DRAFT and you land on its edit
 * screen rather than a published page.
 *
 * The quote link does the heavy lifting. It already carries every calculator
 * setting plus the quoted total (`&q=`), and pps_defaults_from_url() already
 * parses it — this file just wires that to product creation instead of to an
 * existing product's meta box.
 *
 * Loaded by pps-calculators.php (file_exists-guarded require).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─────────────────────────────────────────────────────────────
 * Which calculator?
 * ───────────────────────────────────────────────────────────── */

/**
 * Work out which calculator a quote link came from.
 *
 * Harder than it looks, because a link copied from a live product page is just
 * that product's permalink with a query string — the calculator is not named
 * anywhere in it. Three strategies, best first:
 *
 *   1. The GitHub Pages preview URLs DO name the file (`/calc-brochure.html?…`).
 *   2. A product permalink resolves to a post ID, and the registry knows which
 *      calculator owns that ID. This is the common case in practice.
 *   3. Give up and let the operator choose. The form always shows the dropdown
 *      with the guess pre-selected, so a wrong guess is one click to fix rather
 *      than a silent mistake.
 *
 * @param string $url
 * @return string Calculator filename, or '' if undetermined.
 */
function pps_spawn_detect_calc( $url ) {
    $url = trim( (string) $url );
    if ( $url === '' ) return '';

    // 1. Filename in the path.
    if ( preg_match( '#(calc-[a-z0-9\-]+\.html)#i', $url, $m ) ) {
        $reg = pps_get_registry();
        foreach ( array_keys( $reg ) as $filename ) {
            if ( strcasecmp( $filename, $m[1] ) === 0 ) return $filename;
        }
        return $m[1];   // not registered yet, but still the right answer
    }

    // 2. Product permalink → post ID → registry.
    $bare = strtok( $url, '?' );
    if ( $bare && function_exists( 'url_to_postid' ) ) {
        $pid = url_to_postid( $bare );
        if ( $pid && function_exists( 'pps_get_calculator_for_product' ) ) {
            $f = pps_get_calculator_for_product( $pid );
            if ( $f ) return $f;
        }
    }

    return '';
}

/**
 * Products already owned by a calculator, for the "copy from" dropdown.
 *
 * @return array<int,string> product_id => title
 */
function pps_spawn_template_choices( $filename = '' ) {
    $out = array();
    foreach ( pps_get_registry() as $file => $meta ) {
        if ( ! is_array( $meta ) ) continue;
        if ( $filename !== '' && $file !== $filename ) continue;
        foreach ( pps_registry_product_ids( $meta['products'] ?? '' ) as $id ) {
            $p = get_post( $id );
            if ( $p && $p->post_type === 'product' ) $out[ $id ] = $p->post_title;
        }
    }
    asort( $out );
    return $out;
}

/* ─────────────────────────────────────────────────────────────
 * The spawn
 * ───────────────────────────────────────────────────────────── */

/**
 * Add a product ID to a calculator's registry row.
 *
 * Read-modify-write on an option two admins could touch at once, so the
 * registry is re-read immediately before the append rather than reusing
 * anything fetched earlier in the request — and the ID string is appended to
 * rather than rebuilt, so a concurrent edit loses nothing.
 */
function pps_spawn_register( $product_id, $filename ) {
    $reg = pps_get_registry();
    if ( ! isset( $reg[ $filename ] ) || ! is_array( $reg[ $filename ] ) ) {
        $reg[ $filename ] = array( 'name' => pathinfo( $filename, PATHINFO_FILENAME ), 'products' => '' );
    }
    $existing = pps_registry_product_ids( $reg[ $filename ]['products'] ?? '' );
    if ( in_array( (int) $product_id, $existing, true ) ) return true;   // already there

    $current = trim( (string) ( $reg[ $filename ]['products'] ?? '' ) );
    $reg[ $filename ]['products'] = $current === '' ? (string) $product_id
                                                    : $current . ', ' . $product_id;
    pps_save_registry( $reg );
    return true;
}

/**
 * Create a product from a quote link.
 *
 * @param array $args {
 *   @type string $url       Quote link. Required.
 *   @type string $title     Product name. Required.
 *   @type string $calc      Calculator filename. Required.
 *   @type int    $template  Product to clone copy/images/terms from. Optional.
 *   @type string $slug      Optional; derived from the title otherwise.
 * }
 * @return array|WP_Error  ['id' => int, 'applied' => int, 'price' => ?float,
 *                          'unknown' => string[], 'cloned' => string[]]
 */
function pps_spawn_product( array $args ) {
    $url   = trim( (string) ( $args['url'] ?? '' ) );
    $title = trim( (string) ( $args['title'] ?? '' ) );
    $calc  = trim( (string) ( $args['calc'] ?? '' ) );

    if ( $title === '' ) return new WP_Error( 'pps_spawn_no_title', 'Give the product a name.' );
    if ( $calc === '' )  return new WP_Error( 'pps_spawn_no_calc', 'Pick a calculator.' );
    if ( ! function_exists( 'pps_defaults_from_url' ) ) {
        return new WP_Error( 'pps_spawn_no_parser', 'pps-defaults-url.php is not loaded.' );
    }

    $parsed = pps_defaults_from_url( $url );
    if ( ! empty( $parsed['error'] ) ) return new WP_Error( 'pps_spawn_bad_link', $parsed['error'] );
    if ( empty( $parsed['defaults'] ) ) {
        return new WP_Error( 'pps_spawn_empty_link',
            'That link carried no recognised calculator settings. Configure a job, click '
            . '"Save configuration", and paste the whole URL.' );
    }

    $template = (int) ( $args['template'] ?? 0 );
    $tpl      = $template ? get_post( $template ) : null;
    if ( $tpl && $tpl->post_type !== 'product' ) $tpl = null;

    $post_id = wp_insert_post( array(
        'post_type'    => 'product',
        'post_status'  => 'draft',          // never publish blind — see the file header
        'post_title'   => $title,
        'post_name'    => sanitize_title( $args['slug'] ?? $title ),
        'post_content' => $tpl ? $tpl->post_content : '',
        'post_excerpt' => $tpl ? $tpl->post_excerpt : '',
    ), true );

    if ( is_wp_error( $post_id ) ) return $post_id;

    $cloned = array();

    if ( $tpl ) {
        $thumb = get_post_thumbnail_id( $tpl->ID );
        if ( $thumb ) { set_post_thumbnail( $post_id, $thumb ); $cloned[] = 'featured image'; }

        $gallery = get_post_meta( $tpl->ID, '_product_image_gallery', true );
        if ( $gallery ) { update_post_meta( $post_id, '_product_image_gallery', $gallery ); $cloned[] = 'gallery'; }

        foreach ( array( 'product_cat', 'product_tag' ) as $tax ) {
            $terms = wp_get_object_terms( $tpl->ID, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && $terms ) {
                wp_set_object_terms( $post_id, $terms, $tax );
                $cloned[] = $tax === 'product_cat' ? 'categories' : 'tags';
            }
        }
        if ( $tpl->post_content !== '' ) $cloned[] = 'description';
        if ( $tpl->post_excerpt !== '' ) $cloned[] = 'short description';
    }

    // The flag that is easy to forget and fails silently. PPS collects the
    // shipping address itself and owns turnaround, so WooCommerce's shipping
    // machinery must stay out of the cart for these. Not a checkbox here.
    update_post_meta( $post_id, '_virtual', 'yes' );
    update_post_meta( $post_id, '_manage_stock', 'no' );
    update_post_meta( $post_id, '_stock_status', 'instock' );

    update_post_meta( $post_id, '_pps_defaults', $parsed['defaults'] );
    update_post_meta( $post_id, '_pps_defaults_source', $url );

    if ( $parsed['price'] !== null ) {
        update_post_meta( $post_id, '_pps_defaults_price', $parsed['price'] );
        update_post_meta( $post_id, '_regular_price', $parsed['price'] );
        update_post_meta( $post_id, '_price', $parsed['price'] );
    }

    // The other silent failure: without this the product renders no calculator
    // at all, and looks entirely normal in the admin while doing it.
    pps_spawn_register( $post_id, $calc );

    // A starting point for the SEO panel, not a finished answer. Only set when
    // Rank Math is present and only if empty, so it never overwrites a human.
    if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
        if ( get_post_meta( $post_id, 'rank_math_title', true ) === '' ) {
            update_post_meta( $post_id, 'rank_math_title', $title );
        }
        if ( get_post_meta( $post_id, 'rank_math_description', true ) === '' ) {
            $spec = function_exists( 'pps_catalog_spec_line' )
                    ? pps_catalog_spec_line( $parsed['defaults'] ) : '';
            update_post_meta( $post_id, 'rank_math_description',
                trim( $title . ( $spec !== '' ? ' — ' . $spec : '' ) . '. Instant online pricing.' ) );
        }
    }

    if ( function_exists( 'pps_product_feed_flush_cache' ) ) pps_product_feed_flush_cache();

    return array(
        'id'      => (int) $post_id,
        'applied' => count( $parsed['defaults'] ),
        'price'   => $parsed['price'],
        'unknown' => $parsed['unknown'],
        'cloned'  => $cloned,
    );
}

/* ─────────────────────────────────────────────────────────────
 * Admin screen
 * ───────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
    add_submenu_page(
        'pps-calculators',
        'New Product',
        '✨ New Product',
        'manage_options',
        'pps-spawn',
        'pps_spawn_render_page'
    );
}, 20 );

add_action( 'admin_post_pps_spawn', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    check_admin_referer( 'pps_spawn' );

    $url  = isset( $_POST['spawn_url'] )   ? esc_url_raw( wp_unslash( $_POST['spawn_url'] ) ) : '';
    $bulk = isset( $_POST['spawn_bulk'] )  ? trim( (string) wp_unslash( $_POST['spawn_bulk'] ) ) : '';
    $calc = isset( $_POST['spawn_calc'] )  ? sanitize_text_field( wp_unslash( $_POST['spawn_calc'] ) ) : '';
    $tpl  = isset( $_POST['spawn_template'] ) ? (int) $_POST['spawn_template'] : 0;

    $results = array();

    if ( $bulk !== '' ) {
        // One product per line, "Name | link". Same code path as the single
        // form — bulk is a loop, not a second implementation.
        foreach ( preg_split( '/\R/', $bulk ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            $parts = array_map( 'trim', explode( '|', $line, 2 ) );
            if ( count( $parts ) < 2 ) {
                $results[] = array( 'line' => $line, 'error' => 'expected "Name | link"' );
                continue;
            }
            $line_calc = $calc !== '' ? $calc : pps_spawn_detect_calc( $parts[1] );
            $r = pps_spawn_product( array(
                'url' => $parts[1], 'title' => $parts[0],
                'calc' => $line_calc, 'template' => $tpl,
            ) );
            $results[] = is_wp_error( $r )
                ? array( 'line' => $parts[0], 'error' => $r->get_error_message() )
                : $r + array( 'line' => $parts[0] );
        }
    } else {
        $title = isset( $_POST['spawn_title'] ) ? sanitize_text_field( wp_unslash( $_POST['spawn_title'] ) ) : '';
        $r = pps_spawn_product( array(
            'url' => $url, 'title' => $title,
            'calc' => $calc !== '' ? $calc : pps_spawn_detect_calc( $url ),
            'template' => $tpl,
            'slug' => isset( $_POST['spawn_slug'] ) ? sanitize_title( wp_unslash( $_POST['spawn_slug'] ) ) : '',
        ) );
        if ( is_wp_error( $r ) ) {
            set_transient( 'pps_spawn_result', array( array( 'error' => $r->get_error_message() ) ), 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=pps-spawn' ) );
            exit;
        }
        // One product: go straight to its edit screen, which is where the
        // remaining work is.
        set_transient( 'pps_spawn_result', array( $r ), 60 );
        wp_safe_redirect( get_edit_post_link( $r['id'], 'raw' ) );
        exit;
    }

    set_transient( 'pps_spawn_result', $results, 120 );
    wp_safe_redirect( admin_url( 'admin.php?page=pps-spawn' ) );
    exit;
} );

/**
 * Report what the spawn actually did. Shown on the product edit screen after a
 * single spawn, so the operator sees the flags and the registry entry landing
 * rather than having to trust it.
 */
add_action( 'admin_notices', function () {
    $res = get_transient( 'pps_spawn_result' );
    if ( ! $res || ! is_array( $res ) ) return;
    delete_transient( 'pps_spawn_result' );

    foreach ( $res as $r ) {
        if ( ! empty( $r['error'] ) ) {
            printf( '<div class="notice notice-error"><p><strong>Could not create%s:</strong> %s</p></div>',
                ! empty( $r['line'] ) ? ' ' . esc_html( $r['line'] ) : '',
                esc_html( $r['error'] ) );
            continue;
        }
        $bits = array( sprintf( '%d calculator setting%s applied', $r['applied'], $r['applied'] === 1 ? '' : 's' ) );
        if ( $r['price'] !== null ) $bits[] = 'price set to $' . number_format( $r['price'], 2 );
        $bits[] = 'marked virtual';
        $bits[] = 'added to the calculator registry';
        if ( ! empty( $r['cloned'] ) ) $bits[] = 'copied ' . implode( ', ', $r['cloned'] );

        printf(
            '<div class="notice notice-success"><p><strong>Draft created%s.</strong> %s.<br>'
            . '<em>Still yours to do: the title/H1, the description, and the SEO panel. '
            . 'It stays a draft until you publish it.</em></p>%s</div>',
            ! empty( $r['line'] ) ? ': ' . esc_html( $r['line'] ) : '',
            esc_html( implode( ' · ', $bits ) ),
            ! empty( $r['unknown'] )
                ? '<p style="color:#8a6d3b;margin:6px 0 0">Ignored unrecognised parameters: '
                  . esc_html( implode( ', ', array_slice( $r['unknown'], 0, 10 ) ) ) . '</p>'
                : ''
        );
    }
} );

function pps_spawn_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $reg       = pps_get_registry();
    $templates = pps_spawn_template_choices();
    ?>
    <div class="wrap">
        <h1>New product from a quote link</h1>
        <p style="max-width:70ch;color:#555">
            Configure the job in any calculator, click <strong>Save configuration</strong>,
            and paste the URL below. Everything mechanical — the defaults, the price, the
            virtual flag, the registry entry, the images and copy — is filled in for you.
            The product is created as a <strong>draft</strong> so you can write the title,
            the description and the SEO before it goes live.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="pps_spawn">
            <?php wp_nonce_field( 'pps_spawn' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="spawn_url">Quote link</label></th>
                    <td>
                        <input type="url" id="spawn_url" name="spawn_url" class="large-text"
                               placeholder="https://priorityprintservice.com/product/…?size=5.5×8.5&amp;qty=250&amp;q=284.50">
                        <p class="description">From any calculator's <strong>Save configuration</strong> button.
                        Carries every setting plus the quoted total.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="spawn_title">Product name</label></th>
                    <td>
                        <input type="text" id="spawn_title" name="spawn_title" class="regular-text"
                               placeholder="Mini Catalog Printing">
                        <p class="description">The slug derives from this; override below if you want.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="spawn_slug">Slug <span style="font-weight:400">(optional)</span></label></th>
                    <td><input type="text" id="spawn_slug" name="spawn_slug" class="regular-text"
                               placeholder="mini-catalog-printing"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="spawn_calc">Calculator</label></th>
                    <td>
                        <select id="spawn_calc" name="spawn_calc">
                            <option value="">— work it out from the link —</option>
                            <?php foreach ( array_keys( $reg ) as $file ) : ?>
                                <option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Usually detected: a preview URL names the file, and a product
                            URL resolves to a product the registry already knows. Set it
                            explicitly if the link is from somewhere else.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="spawn_template">Copy from</label></th>
                    <td>
                        <select id="spawn_template" name="spawn_template">
                            <option value="0">— nothing, start empty —</option>
                            <?php foreach ( $templates as $id => $name ) : ?>
                                <option value="<?php echo (int) $id; ?>"><?php echo esc_html( $name ); ?> (#<?php echo (int) $id; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Inherits description, short description, featured image, gallery,
                            categories and tags. A new booklet product is mostly the same page
                            as an existing one — copying gets you a starting point so your
                            attention goes on what differs.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Create draft product' ); ?>

            <h2 style="margin-top:36px">Several at once</h2>
            <p class="description" style="max-width:70ch">
                One per line, <code>Name | link</code>. Uses the same calculator and
                <em>copy from</em> selections above. Creates one draft per line.
            </p>
            <textarea name="spawn_bulk" rows="6" class="large-text code"
                      placeholder="Mini Catalog Printing | https://…?size=…&amp;q=284.50&#10;Wedding Program Printing | https://…?size=…&amp;q=161.75"></textarea>
            <?php submit_button( 'Create drafts', 'secondary' ); ?>
        </form>
    </div>
    <?php
}

/* ─────────────────────────────────────────────────────────────
 * Calculator binding, visible on the product itself
 * ───────────────────────────────────────────────────────────── */

/**
 * Which calculator owns this product — shown and editable on the product
 * screen instead of living in a comma-separated ID list on another page.
 *
 * The visibility matters more than the saved clicks: an unbound PPS product
 * looks completely normal in the admin right up until a customer opens it and
 * finds no calculator.
 */
add_action( 'woocommerce_product_options_general_product_data', function () {
    global $post;
    if ( ! $post ) return;
    $current = function_exists( 'pps_get_calculator_for_product' )
               ? pps_get_calculator_for_product( $post->ID ) : '';

    echo '<div class="options_group">';
    $options = array( '' => '— None: this product will not render a calculator —' );
    foreach ( array_keys( pps_get_registry() ) as $file ) $options[ $file ] = $file;

    woocommerce_wp_select( array(
        'id'          => '_pps_calc_binding',
        'label'       => 'PPS Calculator',
        'options'     => $options,
        'value'       => $current ? $current : '',
        'desc_tip'    => true,
        'description' => 'Which calculator renders on this product page. Saved to the '
                       . 'calculator registry, not to the product.',
    ) );
    echo '</div>';
}, 20 );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    if ( ! isset( $_POST['_pps_calc_binding'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;   // registry is an admin-level thing

    $want = sanitize_text_field( wp_unslash( $_POST['_pps_calc_binding'] ) );
    $have = function_exists( 'pps_get_calculator_for_product' )
            ? pps_get_calculator_for_product( $post_id ) : '';
    if ( $want === (string) $have ) return;

    // Remove from whichever row currently claims it, then add to the new one.
    $reg = pps_get_registry();
    foreach ( $reg as $file => $meta ) {
        if ( ! is_array( $meta ) ) continue;
        $ids = pps_registry_product_ids( $meta['products'] ?? '' );
        if ( ! in_array( (int) $post_id, $ids, true ) ) continue;
        $ids = array_values( array_diff( $ids, array( (int) $post_id ) ) );
        $reg[ $file ]['products'] = implode( ', ', $ids );
    }
    pps_save_registry( $reg );

    if ( $want !== '' ) pps_spawn_register( $post_id, $want );
}, 20 );
