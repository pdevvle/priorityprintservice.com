<?php
/**
 * Fallback template.
 *
 * @package pps-theme
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();
?>
<div class="pps-container" style="max-width:var(--pps-max-w);margin:0 auto;padding:var(--pps-space-7) var(--pps-gutter);">
	<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
		<article <?php post_class(); ?>>
			<h1 style="font-size:clamp(32px,5vw,56px);letter-spacing:-0.02em;margin:0 0 var(--pps-space-5);"><?php the_title(); ?></h1>
			<div class="pps-prose"><?php the_content(); ?></div>
		</article>
	<?php endwhile; else : ?>
		<p>Nothing found.</p>
	<?php endif; ?>
</div>
<?php
get_footer();
