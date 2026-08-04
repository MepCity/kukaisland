<?php
/** Default content page. */
defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) : the_post(); ?>
	<article <?php post_class( 'kuka-content-page' ); ?>><header><p class="kuka-eyebrow"><?php esc_html_e( 'Kuka Island', 'kuka-island' ); ?></p><h1><?php the_title(); ?></h1></header><div class="kuka-prose"><?php the_content(); ?></div></article>
<?php endwhile;
get_footer();
