<?php get_header(); ?>
<main id="content" class="sfl-main">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'sfl-page' ); ?>>
			<h1><?php the_title(); ?></h1>
			<div class="sfl-content"><?php the_content(); ?></div>
		</article>
	<?php endwhile; ?>
</main>
<?php get_footer(); ?>
