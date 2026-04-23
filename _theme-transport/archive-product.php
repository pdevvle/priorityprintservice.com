<?php
/**
 * WooCommerce category/shop archive. Clean grid, preserving WC's own
 * woocommerce_before_shop_loop / woocommerce_after_shop_loop hooks so
 * notices, result count, sorting, and pagination keep working.
 *
 * Product card markup (thumbnail, title, price, CTA) is overridden via
 * assets/css/woocommerce.css + the filters below rather than by
 * overriding WooCommerce templates — keeps plugin upgrades safe.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header(); ?>

<main id="site-main" class="site-main">

	<section class="section section--tight section--light">
		<div class="container">
			<header class="archive-header">
				<p class="eyebrow"><?php esc_html_e( 'Shop', 'priority-print' ); ?></p>
				<h1><?php woocommerce_page_title(); ?></h1>
				<?php
				if ( is_product_category() ) {
					$term = get_queried_object();
					if ( $term && ! empty( $term->description ) ) {
						echo '<div class="archive-header__desc">' . wp_kses_post( wpautop( $term->description ) ) . '</div>';
					}
				}
				?>
			</header>
		</div>
	</section>

	<section class="section">
		<div class="container">

			<?php
			/**
			 * woocommerce_before_shop_loop fires WC's notices, result count, ordering dropdown.
			 * Do not remove — these are expected by customers and by the plugin.
			 */
			do_action( 'woocommerce_before_shop_loop' );
			?>

			<?php if ( woocommerce_product_loop() ) : ?>

				<?php woocommerce_product_loop_start(); ?>

					<?php if ( wc_get_loop_prop( 'total' ) ) : ?>
						<?php while ( have_posts() ) : the_post(); ?>
							<?php wc_get_template_part( 'content', 'product' ); ?>
						<?php endwhile; ?>
					<?php endif; ?>

				<?php woocommerce_product_loop_end(); ?>

				<?php do_action( 'woocommerce_after_shop_loop' ); ?>

			<?php else : ?>

				<?php do_action( 'woocommerce_no_products_found' ); ?>

			<?php endif; ?>

		</div>
	</section>

</main>

<?php get_footer();
