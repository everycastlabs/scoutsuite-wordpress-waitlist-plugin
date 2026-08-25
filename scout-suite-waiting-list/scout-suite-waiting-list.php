<?php
/**
 * Plugin Name:       Scout Suite
 * Plugin URI:        https://scoutsuite.app
 * Description:       Connect a Scout Suite Group, District or County to WordPress. Sync Groups into WP Store Locator / Skills for Life, and embed a waiting list form on Group sites.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Scout Suite
 * Author URI:        https://scoutsuite.app
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scoutsuite-waitlist
 */

// Abort if this file is called directly rather than loaded by WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOUTSUITE_WAITLIST_VERSION', '1.1.0' );
define( 'SCOUTSUITE_WAITLIST_PLUGIN_FILE', __FILE__ );
define( 'SCOUTSUITE_WAITLIST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOUTSUITE_WAITLIST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Name of the single option array that holds all plugin settings.
define( 'SCOUTSUITE_WAITLIST_OPTION', 'scoutsuite_waitlist_options' );

// Transient used to cache the group's active sections fetched from the API.
define( 'SCOUTSUITE_WAITLIST_SECTIONS_TRANSIENT', 'scoutsuite_waitlist_sections' );

require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-api.php';
require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-settings.php';
require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-form.php';
require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-stores.php';
require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-events.php';
require_once SCOUTSUITE_WAITLIST_PLUGIN_DIR . 'includes/class-scoutsuite-waitlist-sync.php';

/**
 * Return the plugin settings merged with defaults.
 *
 * @return array
 */
function scoutsuite_waitlist_get_options() {
	$defaults = array(
		'api_key'           => '',
		'org_id'            => '',
		'waitlist_group_id' => '',
		'api_base_url'      => ScoutSuite_Waitlist_API::DEFAULT_BASE_URL,
		'sections_override' => '',
		'privacy_notice'    => __( 'We use the details you provide only to manage our waiting list and to contact you about a place for your child. We store them securely in Scout Suite, our membership system, and we do not share them with anyone else. You can ask us to remove your details at any time.', 'scoutsuite-waitlist' ),
		'consent_label'     => __( 'I agree to my details being stored and used to manage this waiting list application.', 'scoutsuite-waitlist' ),
		'success_message'   => __( 'Thank you. Your child has been added to our waiting list and we will be in touch.', 'scoutsuite-waitlist' ),
	);

	$saved = get_option( SCOUTSUITE_WAITLIST_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$options = wp_parse_args( $saved, $defaults );

	// Existing installs stored the Scout Suite id as group_id.
	if ( '' === trim( (string) $options['org_id'] ) && '' !== trim( (string) $options['group_id'] ) ) {
		$options['org_id'] = $options['group_id'];
	}
	if ( '' === trim( (string) $options['group_id'] ) && '' !== trim( (string) $options['org_id'] ) ) {
		$options['group_id'] = $options['org_id'];
	}

	$options['api_base_url'] = ScoutSuite_Waitlist_API::normalise_base_url( $options['api_base_url'] );

	return $options;
}

function scoutsuite_waitlist_signup_org_id() {
	$options = scoutsuite_waitlist_get_options();
	$waitlist = trim( (string) ( $options['waitlist_group_id'] ?? '' ) );
	if ( '' !== $waitlist ) {
		return $waitlist;
	}
	return trim( (string) $options['org_id'] );
}

/**
 * API client for the public waiting-list form (always a Group id).
 *
 * @return ScoutSuite_Waitlist_API
 */
function scoutsuite_waitlist_get_signup_api() {
	$options = scoutsuite_waitlist_get_options();

	return new ScoutSuite_Waitlist_API(
		$options['api_key'],
		scoutsuite_waitlist_signup_org_id(),
		$options['api_base_url']
	);
}

/**
 * API client from current settings.
 *
 * @return ScoutSuite_Waitlist_API
 */
function scoutsuite_waitlist_get_api() {
	$options = scoutsuite_waitlist_get_options();

	return new ScoutSuite_Waitlist_API(
		$options['api_key'],
		$options['org_id'],
		$options['api_base_url']
	);
}

/**
 * Boot the plugin pieces.
 */
function scoutsuite_waitlist_init() {
	new ScoutSuite_Waitlist_Settings();
	new ScoutSuite_Waitlist_Form();
	new ScoutSuite_Waitlist_Sync();
}
add_action( 'plugins_loaded', 'scoutsuite_waitlist_init' );
add_action( 'init', array( 'ScoutSuite_Waitlist_Sync', 'schedule' ) );

/**
 * Register front end and editor assets.
 */
function scoutsuite_waitlist_register_assets() {
	wp_register_style(
		'scoutsuite-waitlist',
		SCOUTSUITE_WAITLIST_PLUGIN_URL . 'assets/css/scoutsuite-waitlist.css',
		array(),
		SCOUTSUITE_WAITLIST_VERSION
	);
}
add_action( 'init', 'scoutsuite_waitlist_register_assets' );

/**
 * Register the Gutenberg block. The block is dynamic: the editor shows a
 * placeholder and the real form is rendered server side on the front end,
 * so no build step is needed.
 */
function scoutsuite_waitlist_register_block() {
	wp_register_script(
		'scoutsuite-waitlist-block',
		SCOUTSUITE_WAITLIST_PLUGIN_URL . 'assets/js/scoutsuite-waitlist-block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-i18n' ),
		SCOUTSUITE_WAITLIST_VERSION,
		true
	);

	register_block_type(
		'scoutsuite/waitlist',
		array(
			'api_version'     => 2,
			'editor_script'   => 'scoutsuite-waitlist-block',
			'style'           => 'scoutsuite-waitlist',
			'render_callback' => array( 'ScoutSuite_Waitlist_Form', 'render_form' ),
		)
	);
}
add_action( 'init', 'scoutsuite_waitlist_register_block' );

/**
 * Add a Settings link on the Plugins screen for convenience.
 *
 * @param array $links Existing action links.
 * @return array
 */
function scoutsuite_waitlist_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=scoutsuite-waitlist' ) ),
		esc_html__( 'Settings', 'scoutsuite-waitlist' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'scoutsuite_waitlist_action_links' );

/**
 * Schedule hourly directory/events sync.
 */
function scoutsuite_waitlist_activate() {
	ScoutSuite_Waitlist_Sync::schedule();
}
register_activation_hook( __FILE__, 'scoutsuite_waitlist_activate' );

/**
 * On deactivation, clear cached data and unschedule cron. Options are kept
 * so settings survive a temporary deactivation; they are removed on uninstall.
 */
function scoutsuite_waitlist_deactivate() {
	delete_transient( SCOUTSUITE_WAITLIST_SECTIONS_TRANSIENT );
	ScoutSuite_Waitlist_Sync::unschedule();
}
register_deactivation_hook( __FILE__, 'scoutsuite_waitlist_deactivate' );
