<?php
/**
 * Priority Print theme bootstrap.
 *
 * Enqueues styles / fonts / JS, registers menus, declares theme supports,
 * wires WooCommerce wrappers, and loads the lightweight SEO class that
 * replaces Yoast for this site.
 *
 * Files it assumes exist:
 *   - style.css           (theme header + CSS)
 *   - assets/css/woocommerce.css
 *   - assets/js/main.js
 *   - inc/seo-class.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PP_THEME_VERSION', '1.0.0' );
define( 'PP_THEME_DIR', get_stylesheet_directory() );
define( 'PP_THEME_URI', get_stylesheet_directory_uri() );

/* -----------------------------------------------------------------------
 * 1. Theme supports
 * --------------------------------------------------------------------- */

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
	) );

	// WooCommerce.
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	// Block editor niceties so Preston's Gutenberg content looks right.
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'style.css' );

	register_nav_menus( array(
		'primary' => __( 'Primary Navigation', 'priority-print' ),
		'footer'  => __( 'Footer Navigation',  'priority-print' ),
	) );
} );

/* -----------------------------------------------------------------------
 * 2. Asset enqueue
 *
 * Google Fonts are loaded via a combined CSS2 URL — one HTTP request, with
 * preconnects to cut handshake latency. Main stylesheet + JS are versioned
 * with PP_THEME_VERSION so cache-busting happens on every release.
 * --------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	// Preconnect to Google Fonts origins.
	add_action( 'wp_head', function () {
		echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
		echo "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
	}, 1 );

	wp_enqueue_style(
		'pp-fonts',
		'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800&family=Source+Serif+4:wght@400;500;600&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'pp-theme',
		get_stylesheet_uri(),
		array( 'pp-fonts' ),
		PP_THEME_VERSION
	);

	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_style(
			'pp-woocommerce',
			PP_THEME_URI . '/assets/css/woocommerce.css',
			array( 'pp-theme' ),
			PP_THEME_VERSION
		);
	}

	wp_enqueue_script(
		'pp-main',
		PP_THEME_URI . '/assets/js/main.js',
		array(),
		PP_THEME_VERSION,
		true
	);
} );

/* -----------------------------------------------------------------------
 * 3. SEO class (replaces Yoast for title / meta / OG / canonical / basic schema)
 *
 * Calculator pages keep getting their rich Product/WebApp/FAQ JSON-LD
 * from the pps-calculators plugin — the theme only handles the site-wide
 * head tags and homepage Organization/LocalBusiness schema. Any overlap
 * is resolved in PP_SEO::should_emit() (defers to plugin on calc pages).
 * --------------------------------------------------------------------- */

require_once PP_THEME_DIR . '/inc/seo-class.php';
add_action( 'after_setup_theme', array( 'PP_SEO', 'init' ) );

/* -----------------------------------------------------------------------
 * 4. WooCommerce wrapper overrides
 *
 * Strip WooCommerce's default <div class="woocommerce"> wrappers and
 * substitute our own container/section markup. Hooks on the loop
 * (woocommerce_before_shop_loop, woocommerce_after_shop_loop) are left
 * untouched — WC relies on them for notices, sorting, pagination.
 * --------------------------------------------------------------------- */

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content',  'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

add_action( 'woocommerce_before_main_content', function () {
	echo '<main id="site-main" class="site-main section"><div class="container">';
}, 10 );
add_action( 'woocommerce_after_main_content', function () {
	echo '</div></main>';
}, 10 );

/* -----------------------------------------------------------------------
 * 5. Small helpers
 * --------------------------------------------------------------------- */

/**
 * Fallback logo URL when no custom logo is set in Customizer.
 * Kept as a single constant so brand swaps are a one-line change.
 */
function pp_logo_url() {
	return 'https://priorityprintservice.com/wp-content/uploads/2023/06/2021-Logo-full-16.png';
}

/**
 * Render the custom logo if set; otherwise fall back to the hardcoded URL.
 */
function pp_site_logo( $classes = '' ) {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a href="%1$s" rel="home" class="%2$s"><img src="%3$s" alt="%4$s"></a>',
		esc_url( home_url( '/' ) ),
		esc_attr( $classes ),
		esc_url( pp_logo_url() ),
		esc_attr( get_bloginfo( 'name' ) )
	);
}

/**
 * Safe meta-description source: custom field → excerpt → site tagline.
 * Used by both the SEO class and any template that wants to echo it.
 */
function pp_meta_description() {
	if ( is_singular() ) {
		$custom = get_post_meta( get_the_ID(), '_meta_description', true );
		if ( $custom ) return wp_strip_all_tags( $custom );

		$excerpt = has_excerpt() ? get_the_excerpt() : '';
		if ( $excerpt ) return wp_strip_all_tags( $excerpt );
	}
	return wp_strip_all_tags( get_bloginfo( 'description' ) );
}
