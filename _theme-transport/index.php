<?php
/**
 * Fallback template. WordPress requires index.php to exist; it also serves
 * as the last-resort template when a more specific one (front-page, archive,
 * single, page, archive-product) is not present.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header(); ?>

<main id="site-main" class="site-main section">
	<div class="container container--narrow">

		<?php if ( have_posts() ) : ?>

			<?php if ( is_home() && ! is_front_page() ) : ?>
				<header class="page-header">
					<h1><?php single_post_title(); ?></h1>
				</header>
			<?php endif; ?>

			<?php while ( have_posts() ) : the_post(); ?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry' ); ?>>
					<header class="entry-header">
						<?php if ( is_singular() ) : ?>
							<h1 class="entry-title"><?php the_title(); ?></h1>
						<?php else : ?>
							<h2 class="entry-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>
						<?php endif; ?>
					</header>
					<div class="entry-content">
						<?php is_singular() ? the_content() : the_excerpt(); ?>
					</div>
				</article>
			<?php endwhile; ?>

			<nav class="pagination">
				<?php
				the_posts_pagination( array(
					'mid_size'  => 1,
					'prev_text' => '← ' . __( 'Previous', 'priority-print' ),
					'next_text' => __( 'Next', 'priority-print' ) . ' →',
				) );
				?>
			</nav>

		<?php else : ?>

			<h1><?php esc_html_e( 'Nothing here yet.', 'priority-print' ); ?></h1>
			<p><?php esc_html_e( 'Looking for a printing quote? Visit our shop or request a custom quote.', 'priority-print' ); ?></p>
			<p>
				<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>"><?php esc_html_e( 'Shop Now', 'priority-print' ); ?></a>
			</p>

		<?php endif; ?>

	</div>
</main>

<?php get_footer();
