<?php
/**
 * Upsert Scout Suite public events into The Events Calendar `tribe_events`.
 *
 * If TEC is not active, callers skip this class and show an admin notice.
 * No Scout Suite events CPT is created.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_Events {

	const POST_TYPE     = 'tribe_events';
	const META_EVENT_ID = '_scoutsuite_event_id';
	const META_SYNC_STATUS = '_scoutsuite_sync_status';

	/**
	 * Whether The Events Calendar is available for writing.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( class_exists( 'Tribe__Events__Main' ) ) {
			return true;
		}
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * @param array $events Normalised event rows from Scout Suite.
	 * @return array { created: int, updated: int, skipped: int, missing: int, errors: string[] }
	 */
	public static function upsert_many( $events ) {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'missing' => 0,
			'errors'  => array(),
		);

		if ( ! self::is_available() ) {
			$result['errors'][] = __( 'The Events Calendar is not active, so Scout Suite events were not written.', 'scoutsuite-waitlist' );
			return $result;
		}

		$seen_ids = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = self::pick_string( $event, array( 'id', 'eventId' ) );
			if ( '' === $event_id ) {
				$result['skipped']++;
				continue;
			}

			$seen_ids[] = $event_id;
			$outcome    = self::upsert_one( $event, $event_id );

			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = $outcome->get_error_message();
				$result['skipped']++;
				continue;
			}

			if ( 'created' === $outcome ) {
				$result['created']++;
			} else {
				$result['updated']++;
			}
		}

		$result['missing'] = self::mark_missing( $seen_ids );

		return $result;
	}

	/**
	 * @param array  $event
	 * @param string $event_id
	 * @return string|WP_Error created|updated
	 */
	private static function upsert_one( $event, $event_id ) {
		$existing = self::find_event_id( $event_id );
		$title    = self::pick_string( $event, array( 'name', 'title' ) );
		if ( '' === $title ) {
			$title = $event_id;
		}

		$description = self::pick_string( $event, array( 'description', 'content', 'body' ) );
		$start       = self::pick_string( $event, array( 'startDate', 'start', 'startsAt' ) );
		$end         = self::pick_string( $event, array( 'endDate', 'end', 'endsAt' ) );
		if ( '' === $end ) {
			$end = $start;
		}

		$all_day   = ! empty( $event['allDay'] ) || ! empty( $event['all_day'] );
		$timezone  = self::pick_string( $event, array( 'timezone', 'timeZone' ) );
		if ( '' === $timezone ) {
			$timezone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'Europe/London';
		}
		$location = self::pick_string( $event, array( 'location', 'venue', 'place' ) );

		$start_local = self::format_local_datetime( $start, $timezone, $all_day );
		$end_local   = self::format_local_datetime( $end, $timezone, $all_day );
		if ( '' === $start_local ) {
			return new WP_Error( 'event_no_start', sprintf( /* translators: %s: Scout Suite event id */ __( 'Event %s has no start date.', 'scoutsuite-waitlist' ), $event_id ) );
		}
		if ( '' === $end_local ) {
			$end_local = $start_local;
		}

		$is_create = ( 0 === $existing );
		$post_id   = $existing;

		$args = array(
			'post_title'     => $title,
			'post_status'    => 'publish',
			'EventStartDate' => $start_local,
			'EventEndDate'   => $end_local,
			'EventAllDay'    => $all_day,
			'EventTimezone'  => $timezone,
		);

		if ( $is_create ) {
			$args['post_content'] = $description;
			$post_id              = self::create_event( $args );
		} else {
			$existing_post = get_post( $existing );
			if ( $existing_post && '' === trim( (string) $existing_post->post_content ) && '' !== $description ) {
				$args['post_content'] = $description;
			}
			$post_id = self::update_event( $existing, $args );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new WP_Error( 'event_save_failed', sprintf( /* translators: %s: Scout Suite event id */ __( 'Could not save event %s.', 'scoutsuite-waitlist' ), $event_id ) );
		}

		update_post_meta( $post_id, self::META_EVENT_ID, $event_id );
		update_post_meta( $post_id, self::META_SYNC_STATUS, 'synced' );
		update_post_meta( $post_id, '_scoutsuite_synced_at', gmdate( 'c' ) );

		if ( '' !== $location ) {
			update_post_meta( $post_id, '_EventVenueID', '' );
			update_post_meta( $post_id, '_scoutsuite_event_location', $location );
		}

		return $is_create ? 'created' : 'updated';
	}

	/**
	 * @param array $args
	 * @return int|WP_Error
	 */
	private static function create_event( $args ) {
		if ( function_exists( 'tribe_create_event' ) ) {
			$created = tribe_create_event( $args );
			if ( $created ) {
				return (int) $created;
			}
			return new WP_Error( 'tec_create_failed', __( 'The Events Calendar could not create the event.', 'scoutsuite-waitlist' ) );
		}

		return self::write_event_post( 0, $args );
	}

	/**
	 * @param int   $post_id
	 * @param array $args
	 * @return int|WP_Error
	 */
	private static function update_event( $post_id, $args ) {
		if ( function_exists( 'tribe_update_event' ) ) {
			$updated = tribe_update_event( $post_id, $args );
			if ( $updated ) {
				return (int) $updated;
			}
			return new WP_Error( 'tec_update_failed', __( 'The Events Calendar could not update the event.', 'scoutsuite-waitlist' ) );
		}

		return self::write_event_post( $post_id, $args );
	}

	/**
	 * Last-resort write when TEC helpers are missing but the CPT exists.
	 *
	 * @param int   $post_id
	 * @param array $args
	 * @return int|WP_Error
	 */
	private static function write_event_post( $post_id, $args ) {
		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => isset( $args['post_status'] ) ? $args['post_status'] : 'publish',
			'post_title'  => $args['post_title'],
		);
		if ( isset( $args['post_content'] ) ) {
			$postarr['post_content'] = $args['post_content'];
		}
		if ( $post_id > 0 ) {
			$postarr['ID'] = $post_id;
			$result          = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = (int) $result;
		update_post_meta( $result, '_EventStartDate', $args['EventStartDate'] );
		update_post_meta( $result, '_EventEndDate', $args['EventEndDate'] );
		update_post_meta( $result, '_EventAllDay', ! empty( $args['EventAllDay'] ) ? 'yes' : 'no' );
		update_post_meta( $result, '_EventTimezone', $args['EventTimezone'] );

		return $result;
	}

	/**
	 * @param string $event_id
	 * @return int
	 */
	private static function find_event_id( $event_id ) {
		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => self::META_EVENT_ID,
				'meta_value'     => $event_id,
			)
		);

		return ! empty( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * @param string[] $seen_ids
	 * @return int
	 */
	private static function mark_missing( $seen_ids ) {
		$synced = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EVENT_ID,
			)
		);

		$missing = 0;
		foreach ( $synced as $post_id ) {
			$id = (string) get_post_meta( $post_id, self::META_EVENT_ID, true );
			if ( '' === $id || in_array( $id, $seen_ids, true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::META_SYNC_STATUS, 'missing_from_source' );
			$missing++;
		}

		return $missing;
	}

	/**
	 * TEC wants local datetime as Y-m-d H:i:s (or Y-m-d for all-day).
	 *
	 * @param string $value
	 * @param string $timezone
	 * @param bool   $all_day
	 * @return string
	 */
	private static function format_local_datetime( $value, $timezone, $all_day ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		try {
			if ( preg_match( '/Z$|[+\-]\d{2}:\d{2}$/', $value ) ) {
				$dt = new DateTimeImmutable( $value );
			} else {
				$tz = timezone_open( $timezone );
				$dt = new DateTimeImmutable( $value, $tz ? $tz : wp_timezone() );
			}
			$tz = timezone_open( $timezone );
			if ( $tz ) {
				$dt = $dt->setTimezone( $tz );
			}
		} catch ( Exception $e ) {
			$ts = strtotime( $value );
			if ( false === $ts ) {
				return '';
			}
			return $all_day ? gmdate( 'Y-m-d', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );
		}

		return $all_day ? $dt->format( 'Y-m-d' ) : $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array    $source
	 * @param string[] $keys
	 * @return string
	 */
	private static function pick_string( $source, $keys ) {
		if ( ! is_array( $source ) ) {
			return '';
		}
		foreach ( $keys as $key ) {
			if ( ! isset( $source[ $key ] ) ) {
				continue;
			}
			$value = $source[ $key ];
			if ( is_numeric( $value ) ) {
				return (string) $value;
			}
			if ( is_string( $value ) ) {
				$value = trim( $value );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}
}
