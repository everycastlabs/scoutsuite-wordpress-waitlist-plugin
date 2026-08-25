<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'sfl-body' ); ?>>
<?php wp_body_open(); ?>
<a class="sfl-skip" href="#content"><?php esc_html_e( 'Skip to content' ); ?></a>
<div class="sfl-topbar"><span>Scouts &middot; Skills for Life</span></div>
<header class="sfl-header">
	<div class="sfl-header-inner">
		<a class="sfl-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<svg class="sfl-fleur" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
				<path fill="#7413dc" d="M32 4c2.2 8.4 7.2 14.2 14 18.2-4.6 1.2-8.8 1.4-14 1.4s-9.4-.2-14-1.4C24.8 18.2 29.8 12.4 32 4z"/>
				<path fill="#7413dc" d="M18 26c6.2 2.4 10.4 7.6 12 14.8V58h-4.4C20.8 50.2 16.6 40.8 12 32c2.2-1.4 4.2-3.2 6-6z"/>
				<path fill="#7413dc" d="M46 26c1.8 2.8 3.8 4.6 6 6-4.6 8.8-8.8 18.2-13.6 26H34V40.8C35.6 33.6 39.8 28.4 46 26z"/>
				<circle cx="32" cy="24.5" r="3.2" fill="#fff"/>
			</svg>
			<span class="sfl-brand-text">
				<span class="sfl-brand-kicker">Scouts</span>
				<span class="sfl-brand-name"><?php bloginfo( 'name' ); ?></span>
			</span>
		</a>
		<nav class="sfl-nav" aria-label="Primary">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'fallback_cb'    => 'wp_page_menu',
				)
			);
			?>
		</nav>
	</div>
</header>
