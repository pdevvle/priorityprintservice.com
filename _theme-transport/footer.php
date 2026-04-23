<?php
/**
 * Site footer: three columns + bottom copyright bar.
 * Contact details are intentionally hardcoded for v1 — move to Customizer
 * or an options page when Preston asks. Not before.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>

<footer class="site-footer" role="contentinfo">
	<div class="container">

		<div class="footer-grid">

			<div class="footer-col">
				<img
					class="footer-col__logo"
					src="<?php echo esc_url( pp_logo_url() ); ?>"
					alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
				>
				<p class="footer-col__tagline">
					<?php esc_html_e( 'Fast turnaround. Professional results.', 'priority-print' ); ?>
				</p>
			</div>

			<div class="footer-col">
				<h4><?php esc_html_e( 'Explore', 'priority-print' ); ?></h4>
				<?php
				if ( has_nav_menu( 'footer' ) ) {
					wp_nav_menu( array(
						'theme_location' => 'footer',
						'container'      => false,
						'depth'          => 1,
						'menu_class'     => 'footer-col__list',
					) );
				} else {
					echo '<ul>';
					echo '<li><a href="' . esc_url( home_url( '/shop/' ) )        . '">' . esc_html__( 'Shop',     'priority-print' ) . '</a></li>';
					echo '<li><a href="' . esc_url( home_url( '/about/' ) )       . '">' . esc_html__( 'About',    'priority-print' ) . '</a></li>';
					echo '<li><a href="' . esc_url( home_url( '/get-a-quote/' ) ) . '">' . esc_html__( 'Get a Quote', 'priority-print' ) . '</a></li>';
					echo '<li><a href="' . esc_url( home_url( '/contact/' ) )     . '">' . esc_html__( 'Contact',  'priority-print' ) . '</a></li>';
					echo '</ul>';
				}
				?>
			</div>

			<div class="footer-col">
				<h4><?php esc_html_e( 'Visit', 'priority-print' ); ?></h4>
				<address style="font-style: normal; line-height: var(--pp-lh-body);">
					Priority Print Service<br>
					Peoria, AZ<br>
					<a href="tel:+16239778888">623-977-8888</a><br>
					<a href="mailto:hello@priorityprintservice.com">hello@priorityprintservice.com</a>
				</address>
			</div>

		</div>

		<div class="footer-bottom">
			<span>&copy; <?php echo esc_html( date( 'Y' ) ); ?> Priority Print Service — Peoria, AZ</span>
			<span><?php esc_html_e( 'Commercial printing, fast.', 'priority-print' ); ?></span>
		</div>

	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
