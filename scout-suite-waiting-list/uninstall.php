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
delete_option( 'scoutsuite_waitlist_sync_state' );
delete_option( 'scoutsuite_waitlist_notices' );
delete_transient( 'scoutsuite_waitlist_sections' );
delete_transient( 'scoutsuite_waitlist_sync_lock' );

$timestamp = wp_next_scheduled( 'scoutsuite_waitlist_sync' );
while ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'scoutsuite_waitlist_sync' );
	$timestamp = wp_next_scheduled( 'scoutsuite_waitlist_sync' );
}

// Remove any short lived form feedback transients that have not yet expired.
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_sswl\_feedback\_%'
	    OR option_name LIKE '\_transient\_timeout\_sswl\_feedback\_%'"
);
