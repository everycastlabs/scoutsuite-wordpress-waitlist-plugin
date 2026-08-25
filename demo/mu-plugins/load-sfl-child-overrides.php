<?php
/**
 * Plugin Name: Skills for Life child overrides (Leaflet + postcodes.io)
 * Description: Loads the child's WPSL overrides if the Skills for Life child is not the active stylesheet. The Docker demo activates that child; this is a fallback. Also prints autoload stores with the page so Find a Group pins do not wait on AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'after_setup_theme',
	static function () {
		if ( 'skillsforlife-child' === get_stylesheet() ) {
			return;
		}
		$functions = WP_CONTENT_DIR . '/themes/skillsforlife-child/functions.php';
		if ( is_readable( $functions ) ) {
			require_once $functions;
		}
	}
);

/**
 * [wpsl] never prints store coordinates. Put them on wpslSettings so pins
 * can render on the first JS tick instead of waiting for store_search.
 *
 * @return array<int, array<string, mixed>>
 */
function scoutsuite_demo_wpsl_autoload_stores() {
	global $wpsl_settings;

	$limit = 0;
	if ( is_array( $wpsl_settings ) && ! empty( $wpsl_settings['autoload_limit'] ) ) {
		$limit = absint( $wpsl_settings['autoload_limit'] );
	}

	$query = new WP_Query(
		array(
			'post_type'              => 'wpsl_stores',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit ? $limit : -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	$stores = array();
	foreach ( $query->posts as $post ) {
		$lat = get_post_meta( $post->ID, 'wpsl_lat', true );
		$lng = get_post_meta( $post->ID, 'wpsl_lng', true );
		if ( '' === $lat || '' === $lng ) {
			continue;
		}
		$stores[] = array(
			'id'        => $post->ID,
			'store'     => get_the_title( $post ),
			'lat'       => $lat,
			'lng'       => $lng,
			'address'   => (string) get_post_meta( $post->ID, 'wpsl_address', true ),
			'city'      => (string) get_post_meta( $post->ID, 'wpsl_city', true ),
			'zip'       => (string) get_post_meta( $post->ID, 'wpsl_zip', true ),
			'phone'     => (string) get_post_meta( $post->ID, 'wpsl_phone', true ),
			'url'       => (string) get_post_meta( $post->ID, 'wpsl_url', true ),
			'permalink' => get_permalink( $post ),
		);
	}

	return $stores;
}

add_filter(
	'wpsl_js_settings',
	static function ( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['autoLoad'] ) ) {
			return $settings;
		}
		if ( ! empty( $settings['autoLoadStores'] ) && is_array( $settings['autoLoadStores'] ) ) {
			return $settings;
		}
		$settings['autoLoadStores'] = scoutsuite_demo_wpsl_autoload_stores();
		return $settings;
	},
	20
);

/**
 * The child theme inside the Docker volume is not bind-mounted. Prefer the
 * parent copy of wpsl-leaflet.js (demo/themes/skillsforlife/js) so the demo
 * can render autoload pins without copying into the volume.
 */
add_filter(
	'wpsl_gmap_js',
	static function ( $src ) {
		$path = get_template_directory() . '/js/wpsl-leaflet.js';
		if ( is_readable( $path ) ) {
			return get_template_directory_uri() . '/js/wpsl-leaflet.js';
		}
		return $src;
	},
	99
);
