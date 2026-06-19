<?php
/**
 * Allow rich HTML (headings, lists, paragraphs) in WooCommerce category descriptions.
 * Loaded by pps-calculators.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

remove_filter( 'pre_term_description', 'wp_filter_kses' );
remove_filter( 'term_description', 'wp_kses_data' );
add_filter( 'pre_term_description', 'wp_filter_post_kses' );
