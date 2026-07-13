<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes every option and transient the plugin created.
 */

// Abort unless WordPress is genuinely uninstalling the plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'scoutsuite_waitlist_options' );
delete_transient( 'scoutsuite_waitlist_sections' );

// Remove any short lived form feedback transients that have not yet expired.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_sswl\_feedback\_%'
	    OR option_name LIKE '\_transient\_timeout\_sswl\_feedback\_%'"
);
