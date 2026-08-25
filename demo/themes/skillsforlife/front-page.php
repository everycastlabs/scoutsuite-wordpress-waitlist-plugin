<?php get_header(); ?>
<section class="sfl-hero">
	<div class="sfl-hero-inner">
		<h1><?php bloginfo( 'name' ); ?></h1>
		<p>Find a Group near you, see what's on across the District, and join a waiting list. Groups and public events are written in from Scout Suite — they are not typed into WordPress twice.</p>
		<div class="sfl-hero-actions">
			<a class="sfl-btn sfl-btn-primary" href="<?php echo esc_url( home_url( '/find-a-group/' ) ); ?>">Find a Group</a>
			<a class="sfl-btn sfl-btn-ghost" href="<?php echo esc_url( home_url( '/whats-on/' ) ); ?>">What's on</a>
		</div>
	</div>
</section>
<main id="content" class="sfl-main">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<div class="sfl-content"><?php the_content(); ?></div>
	<?php endwhile; ?>
</main>
<?php get_footer(); ?>
