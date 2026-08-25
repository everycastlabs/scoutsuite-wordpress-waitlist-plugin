<?php
/**
 * Pull Scout Suite directory + public events and write them into WordPress.
 *
 * Triggered by the settings "Sync now" button and by hourly WP-Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_Sync {

	const CRON_HOOK     = 'scoutsuite_waitlist_sync';
	const LOCK_TRANSIENT = 'scoutsuite_waitlist_sync_lock';
	const STATE_OPTION  = 'scoutsuite_waitlist_sync_state';
	const NOTICES_OPTION = 'scoutsuite_waitlist_notices';

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_from_cron' ) );
		add_action( 'admin_post_scoutsuite_waitlist_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Schedule hourly sync if it is not already queued.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the hourly event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Settings page "Sync now" handler.
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to sync Scout Suite data.', 'scoutsuite-waitlist' ) );
		}

		check_admin_referer( 'scoutsuite_waitlist_sync_now' );

		$this->run( 'manual' );

		wp_safe_redirect( admin_url( 'options-general.php?page=scoutsuite-waitlist&synced=1' ) );
		exit;
	}

	/**
	 * WP-Cron entry point.
	 */
	public function run_from_cron() {
		$this->run( 'cron' );
	}

	/**
	 * Fetch directory (always) and events (when TEC is active), then upsert.
	 * A 404 on a fetched endpoint aborts the run so we never invent data.
	 *
	 * @param string $source manual|cron
	 * @return array Sync state written to the options table.
	 */
	public function run( $source = 'manual' ) {
		$state = array(
			'started_at'  => time(),
			'finished_at' => 0,
			'source'      => $source,
			'status'      => 'error',
			'message'     => '',
			'stores'      => array(),
			'events'      => array(),
		);

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			$state['message'] = __( 'A Scout Suite sync is already running. Try again in a minute.', 'scoutsuite-waitlist' );
			$this->store_state( $state );
			return $state;
		}

		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$state = $this->run_unlocked( $state );
		} catch ( Exception $e ) {
			$state['status']  = 'error';
			$state['message'] = $e->getMessage();
			$this->add_notice( 'error', $state['message'] );
		}

		delete_transient( self::LOCK_TRANSIENT );
		$state['finished_at'] = time();
		$this->store_state( $state );

		return $state;
	}

	/**
	 * @param array $state
	 * @return array
	 */
	private function run_unlocked( $state ) {
		$api    = scoutsuite_waitlist_get_api();
		$manual = ( 'manual' === $state['source'] );

		if ( ! $api->is_sync_configured() ) {
			$state['message'] = __( 'Set the Org ID and API key before syncing.', 'scoutsuite-waitlist' );
			if ( $manual ) {
				$this->add_notice( 'error', $state['message'] );
			}
			return $state;
		}

		if ( ! ScoutSuite_Waitlist_Stores::is_available() ) {
			$state['message'] = __( 'WP Store Locator is not active. Activate it so Groups can be written to wpsl_stores. The existing Skills for Life map/list UI is left in place.', 'scoutsuite-waitlist' );
			if ( $manual ) {
				$this->add_notice( 'error', $state['message'] );
			}
			return $state;
		}

		$directory = $api->get_directory();
		if ( ! $directory['success'] ) {
			$state['message'] = $directory['message'];
			$this->add_notice( 'error', $directory['message'] );
			return $state;
		}

		$tec_active     = ScoutSuite_Waitlist_Events::is_available();
		$events_payload = null;

		if ( $tec_active ) {
			$events = $api->get_public_events();
			if ( ! $events['success'] ) {
				$state['message'] = $events['message'];
				$this->add_notice( 'error', $events['message'] );
				return $state;
			}
			$events_payload = $events['data'];
		} elseif ( $manual ) {
			$this->add_notice(
				'warning',
				__( 'The Events Calendar is not active, so Scout Suite events were skipped. Groups were still synced into WP Store Locator.', 'scoutsuite-waitlist' )
			);
		}

		$groups = self::extract_list(
			$directory['data'],
			array( 'groups', 'directory', 'items', 'orgs', 'stores' )
		);

		$state['stores'] = ScoutSuite_Waitlist_Stores::upsert_many( $groups, $api->origin_url() );

		if ( $tec_active ) {
			$event_rows       = self::extract_list( $events_payload, array( 'events', 'items' ) );
			$state['events'] = ScoutSuite_Waitlist_Events::upsert_many( $event_rows );
		} else {
			$state['events'] = array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'missing' => 0,
				'errors'  => array(),
				'skipped_reason' => 'tec_inactive',
			);
		}

		$store_errors = isset( $state['stores']['errors'] ) ? $state['stores']['errors'] : array();
		$event_errors = isset( $state['events']['errors'] ) ? $state['events']['errors'] : array();

		if ( ! empty( $store_errors ) || ! empty( $event_errors ) ) {
			$state['status']  = 'error';
			$state['message'] = implode( ' ', array_merge( $store_errors, $event_errors ) );
			$this->add_notice( 'error', $state['message'] );
			return $state;
		}

		$state['status']  = 'success';
		$state['message'] = $this->summarise( $state );
		$this->add_notice( 'success', $state['message'] );

		return $state;
	}

	/**
	 * Pull an array of rows out of a loosely shaped API payload.
	 *
	 * @param mixed    $data
	 * @param string[] $keys
	 * @return array
	 */
	public static function extract_list( $data, $keys ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		if ( self::is_list( $data ) ) {
			return $data;
		}

		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return self::is_list( $data[ $key ] ) ? $data[ $key ] : self::extract_list( $data[ $key ], $keys );
			}
		}

		$values = array_values( $data );
		if ( isset( $values[0] ) && is_array( $values[0] ) && ( isset( $values[0]['id'] ) || isset( $values[0]['orgId'] ) || isset( $values[0]['groupId'] ) || isset( $values[0]['name'] ) ) ) {
			return $values;
		}

		// A single Group org may be returned as one object rather than a list.
		if ( isset( $data['id'] ) || isset( $data['orgId'] ) || isset( $data['groupId'] ) ) {
			return array( $data );
		}

		return array();
	}

	/**
	 * @param array $value
	 * @return bool
	 */
	private static function is_list( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return is_array( $value );
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @param array $state
	 * @return string
	 */
	private function summarise( $state ) {
		$stores = $state['stores'];
		$events = $state['events'];

		$parts = array();
		$parts[] = sprintf(
			/* translators: 1: created count, 2: updated count */
			__( 'Stores: %1$d created, %2$d updated.', 'scoutsuite-waitlist' ),
			isset( $stores['created'] ) ? (int) $stores['created'] : 0,
			isset( $stores['updated'] ) ? (int) $stores['updated'] : 0
		);

		if ( ! empty( $events['skipped_reason'] ) && 'tec_inactive' === $events['skipped_reason'] ) {
			$parts[] = __( 'Events skipped (The Events Calendar is not active).', 'scoutsuite-waitlist' );
		} else {
			$parts[] = sprintf(
				/* translators: 1: created count, 2: updated count */
				__( 'Events: %1$d created, %2$d updated.', 'scoutsuite-waitlist' ),
				isset( $events['created'] ) ? (int) $events['created'] : 0,
				isset( $events['updated'] ) ? (int) $events['updated'] : 0
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * @param array $state
	 */
	private function store_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Last completed sync, if any.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Queue a one-shot admin notice for the next dashboard view.
	 *
	 * @param string $type    success|error|warning
	 * @param string $message
	 */
	private function add_notice( $type, $message ) {
		$notices = get_option( self::NOTICES_OPTION, array() );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		if ( count( $notices ) > 5 ) {
			$notices = array_slice( $notices, -5 );
		}
		update_option( self::NOTICES_OPTION, $notices, false );
	}

	/**
	 * Print and consume queued notices.
	 */
	public function render_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notices = get_option( self::NOTICES_OPTION, array() );
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}

		delete_option( self::NOTICES_OPTION );

		foreach ( $notices as $notice ) {
			$type    = isset( $notice['type'] ) ? $notice['type'] : 'info';
			$message = isset( $notice['message'] ) ? $notice['message'] : '';
			if ( '' === $message ) {
				continue;
			}
			$class = 'notice notice-' . sanitize_html_class( $type );
			if ( 'success' === $type ) {
				$class .= ' is-dismissible';
			}
			printf(
				'<div class="%1$s"><p>%2$s</p></div>',
				esc_attr( $class ),
				esc_html( $message )
			);
		}
	}
}
