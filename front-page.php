<?php
/**
 * Homepage template. Five fixed sections (hero, services, values, CTA banner,
 * then Gutenberg content) that Preston can edit the CTA copy / services cards
 * of by editing the placeholder constants here, or by swapping the hardcoded
 * services array for an ACF/CPT-driven loop later.
 *
 * the_content() is still called at the bottom — any blocks the homepage page
 * holds in the editor render there, so Preston can append news / promos
 * without asking a developer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$services = array(
	array(
		'title' => __( 'Saddle-Stitch Booklets', 'priority-print' ),
		'copy'  => __( 'Catalogs, programs, manuals. Up to 92 pages, two staples, folded flat.', 'priority-print' ),
		'href'  => '/product-category/booklets/',
	),
	array(
		'title' => __( 'Perfect-Bound Books', 'priority-print' ),
		'copy'  => __( 'Square spines, short runs, sharp finish. 40 to 500+ pages.', 'priority-print' ),
		'href'  => '/product-category/perfect-bound/',
	),
	array(
		'title' => __( 'Brochures & Flyers', 'priority-print' ),
		'copy'  => __( 'Nine fold styles, coated or uncoated, same-day rush available.', 'priority-print' ),
		'href'  => '/product-category/brochures/',
	),
	array(
		'title' => __( 'Coupon Books', 'priority-print' ),
		'copy'  => __( 'Perforated, numbered, color-coded. Built for redemption.', 'priority-print' ),
		'href'  => '/product-category/coupon-books/',
	),
	array(
		'title' => __( 'Variable Data', 'priority-print' ),
		'copy'  => __( 'Personalized mail, numbered tickets, targeted marketing at scale.', 'priority-print' ),
		'href'  => '/variable-data/',
	),
	array(
		'title' => __( 'Custom Quote', 'priority-print' ),
		'copy'  => __( 'Not sure what fits? Send us the spec — quote back in hours, not days.', 'priority-print' ),
		'href'  => '/get-a-quote/',
	),
);
?>

<main id="site-main" class="site-main">

	<!-- ========== HERO ========== -->
	<section class="hero">
		<div class="container">
			<div class="hero__inner">
				<p class="eyebrow"><?php esc_html_e( 'Peoria, AZ — Commercial Printing', 'priority-print' ); ?></p>
				<h1><?php esc_html_e( 'Your print, your deadline, done right.', 'priority-print' ); ?></h1>
				<p class="hero__sub">
					<?php esc_html_e( 'Booklets, brochures, coupon books, variable data. In-house bindery, instant online pricing, rush turnaround when you need it.', 'priority-print' ); ?>
				</p>
				<div class="hero__ctas">
					<a class="btn btn--accent" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">
						<?php esc_html_e( 'Shop Now', 'priority-print' ); ?>
					</a>
					<a class="btn btn--ghost" href="<?php echo esc_url( home_url( '/get-a-quote/' ) ); ?>">
						<?php esc_html_e( 'Get a Quote', 'priority-print' ); ?>
					</a>
				</div>
			</div>
		</div>
	</section>

	<!-- ========== SERVICES ========== -->
	<section class="section section--light">
		<div class="container">
			<header style="margin-bottom: var(--pp-s-7); max-width: 720px;">
				<p class="eyebrow"><?php esc_html_e( 'What we print', 'priority-print' ); ?></p>
				<h2><?php esc_html_e( 'Six specialities. One press floor.', 'priority-print' ); ?></h2>
			</header>
			<div class="card-grid">
				<?php foreach ( $services as $s ) : ?>
					<article class="card">
						<h3><?php echo esc_html( $s['title'] ); ?></h3>
						<p><?php echo esc_html( $s['copy'] ); ?></p>
						<a class="card__link" href="<?php echo esc_url( home_url( $s['href'] ) ); ?>">
							<?php esc_html_e( 'See options', 'priority-print' ); ?>
						</a>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- ========== VALUES ========== -->
	<section class="section">
		<div class="container">
			<header style="margin-bottom: var(--pp-s-7); max-width: 720px;">
				<p class="eyebrow"><?php esc_html_e( 'Why choose us', 'priority-print' ); ?></p>
				<h2><?php esc_html_e( 'Work that ships when you said it would.', 'priority-print' ); ?></h2>
			</header>
			<div class="values">
				<div class="values__item">
					<div class="values__num">01</div>
					<h3><?php esc_html_e( 'Fast turnaround', 'priority-print' ); ?></h3>
					<p><?php esc_html_e( 'Standard jobs ship in 3–5 business days. Rush add-ons can drop that to 24 hours when you need it.', 'priority-print' ); ?></p>
				</div>
				<div class="values__item">
					<div class="values__num">02</div>
					<h3><?php esc_html_e( 'Print experts', 'priority-print' ); ?></h3>
					<p><?php esc_html_e( 'Local press operators review every file for bleed, trim, and ink coverage before plates are cut. Fewer proofs, cleaner runs.', 'priority-print' ); ?></p>
				</div>
				<div class="values__item">
					<div class="values__num">03</div>
					<h3><?php esc_html_e( 'Custom quantities', 'priority-print' ); ?></h3>
					<p><?php esc_html_e( 'Need 87 copies? 8,700? Our calculators price any quantity in real time — no sales-rep back-and-forth.', 'priority-print' ); ?></p>
				</div>
			</div>
		</div>
	</section>

	<!-- ========== CTA BANNER ========== -->
	<section class="cta-banner">
		<div class="container">
			<h2><?php esc_html_e( 'Ready to print? Let\'s get started.', 'priority-print' ); ?></h2>
			<p style="max-width: 560px; margin: 0 auto var(--pp-s-6); color: rgba(255,255,255,.85);">
				<?php esc_html_e( 'Pick a product, configure the specs, and see your price instantly.', 'priority-print' ); ?>
			</p>
			<div style="display:flex; gap: var(--pp-s-4); justify-content: center; flex-wrap: wrap;">
				<a class="btn btn--accent" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">
					<?php esc_html_e( 'Shop Now', 'priority-print' ); ?>
				</a>
				<a class="btn btn--ghost" href="<?php echo esc_url( home_url( '/get-a-quote/' ) ); ?>">
					<?php esc_html_e( 'Get a Quote', 'priority-print' ); ?>
				</a>
			</div>
		</div>
	</section>

	<!-- ========== GUTENBERG EDITABLE ========== -->
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
		<?php if ( get_the_content() ) : ?>
			<section class="section">
				<div class="container container--narrow entry-content">
					<?php the_content(); ?>
				</div>
			</section>
		<?php endif; ?>
	<?php endwhile; endif; ?>

</main>

<?php get_footer();
