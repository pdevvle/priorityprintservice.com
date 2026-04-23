<?php
/**
 * Site header: DOCTYPE, <head>, sticky top bar, logo + primary nav.
 * Mobile nav collapses under a hamburger toggle — see assets/js/main.js.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php wp_body_open(); ?>

<a class="screen-reader-text" href="#site-main"><?php esc_html_e( 'Skip to content', 'priority-print' ); ?></a>

<header class="site-header" role="banner">

	<div class="topbar">
		<div class="container topbar__inner">
			<a href="tel:+16239778888"><?php esc_html_e( 'Call 623-977-8888', 'priority-print' ); ?></a>
			<a class="topbar__cta" href="<?php echo esc_url( home_url( '/get-a-quote/' ) ); ?>">
				<?php esc_html_e( 'Get a Quote', 'priority-print' ); ?>
			</a>
		</div>
	</div>

	<div class="container">
		<div class="masthead">

			<div class="masthead__logo">
				<?php pp_site_logo(); ?>
			</div>

			<button
				class="nav-toggle"
				type="button"
				aria-expanded="false"
				aria-controls="primary-nav"
				aria-label="<?php esc_attr_e( 'Toggle navigation', 'priority-print' ); ?>"
			>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<path d="M3 6h18M3 12h18M3 18h18"></path>
				</svg>
			</button>

			<nav id="primary-nav" class="primary-nav" role="navigation" aria-label="<?php esc_attr_e( 'Primary', 'priority-print' ); ?>">
				<?php
				if ( has_nav_menu( 'primary' ) ) {
					wp_nav_menu( array(
						'theme_location' => 'primary',
						'container'      => false,
						'depth'          => 2,
						'menu_class'     => 'primary-nav__list',
					) );
				} else {
					// First-run fallback so the header renders something even before
					// Preston creates a menu in Appearance → Menus.
					echo '<ul><li><a href="' . esc_url( home_url( '/shop/' ) )       . '">' . esc_html__( 'Shop', 'priority-print' )    . '</a></li>';
					echo     '<li><a href="' . esc_url( home_url( '/about/' ) )      . '">' . esc_html__( 'About', 'priority-print' )   . '</a></li>';
					echo     '<li><a href="' . esc_url( home_url( '/get-a-quote/' ) ). '">' . esc_html__( 'Quote', 'priority-print' )   . '</a></li>';
					echo     '<li><a href="' . esc_url( home_url( '/contact/' ) )    . '">' . esc_html__( 'Contact', 'priority-print' ) . '</a></li></ul>';
				}
				?>
			</nav>

		</div>
	</div>

</header>
