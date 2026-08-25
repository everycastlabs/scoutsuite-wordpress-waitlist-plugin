<?php get_header(); ?>
<main id="content" class="sfl-main">
	<?php if ( have_posts() ) : ?>
		<?php if ( is_post_type_archive() || is_archive() ) : ?>
			<h1 class="sfl-archive-title"><?php echo wp_kses_post( get_the_archive_title() ); ?></h1>
		<?php endif; ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'sfl-page' ); ?>>
				<?php if ( ! is_front_page() ) : ?>
					<h1><?php the_title(); ?></h1>
				<?php endif; ?>
				<div class="sfl-content"><?php the_content(); ?></div>
			</article>
		<?php endwhile; ?>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
