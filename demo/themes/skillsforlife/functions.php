<?php
/**
 * Scout-branded classic parent used by the Docker demo.
 * The Leaflet / postcodes.io child is https://github.com/everycastlabs/sfl-wordpress-child-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
		register_nav_menus(
			array(
				'primary' => 'Primary',
			)
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		$theme = wp_get_theme( 'skillsforlife' );
		$ver   = $theme->get( 'Version' );
		if ( ! is_string( $ver ) || '' === $ver ) {
			$ver = '0.2.0';
		}
		wp_enqueue_style( 'skillsforlife', get_template_directory_uri() . '/style.css', array(), $ver );
	},
	5
);

/**
 * Front page uses the hero in front-page.php; other pages use page.php.
 */
function skillsforlife_is_front() {
	return is_front_page() && ! is_paged();
}
