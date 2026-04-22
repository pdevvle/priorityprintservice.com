<?php
/**
 * pps-theme — functions.
 *
 * Minimal. Registers theme supports, nav-menu locations, image sizes, and
 * enqueues the single stylesheet + progressive-enhancement JS for the header.
 *
 * @package pps-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Theme supports + menu slots.
 */
function pps_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'custom-logo', array( 'height' => 48, 'width' => 240, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'woocommerce' );

	register_nav_menus( array(
		'primary' => __( 'Primary — services (header fold-out)', 'pps' ),
		'about'   => __( 'Secondary — about & support', 'pps' ),
		'footer'  => __( 'Footer — utility links', 'pps' ),
	) );
}
add_action( 'after_setup_theme', 'pps_theme_setup' );

/**
 * Enqueue theme assets.
 * Single stylesheet. Defer JS so it never blocks the header render.
 */
function pps_theme_enqueue() {
	$ver = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'pps-theme', get_template_directory_uri() . '/assets/css/main.css', array(), $ver );
	wp_enqueue_script( 'pps-theme', get_template_directory_uri() . '/assets/js/main.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
add_action( 'wp_enqueue_scripts', 'pps_theme_enqueue' );
