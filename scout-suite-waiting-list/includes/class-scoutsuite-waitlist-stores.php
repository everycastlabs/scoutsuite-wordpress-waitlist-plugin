<?php
/**
 * Upsert Scout Suite directory rows into WP Store Locator `wpsl_stores`.
 *
 * WPSL and Skills for Life keep the public map/list UI. This class only
 * writes the post meta those plugins already read. It never deletes stores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_Stores {

	const POST_TYPE       = 'wpsl_stores';
	const META_ORG_ID     = '_scoutsuite_org_id';
	const META_SYNC_STATUS = '_scoutsuite_sync_status';

	/**
	 * SFL meeting days, matching SFL_WPSL_DAYS (day is an index).
	 *
	 * @var string[]
	 */
	private static $days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * Whether WPSL's store CPT is registered.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Upsert every directory org as a store. Match on `_scoutsuite_org_id`.
	 * Rows that vanished from Scout Suite are marked missing, never deleted.
	 *
	 * @param array  $groups      Normalised directory rows.
	 * @param string $origin_url  Scout Suite public origin for waiting-list URLs.
	 * @return array { created: int, updated: int, skipped: int, missing: int, errors: string[] }
	 */
	public static function upsert_many( $groups, $origin_url ) {
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'missing' => 0,
			'errors'  => array(),
		);

		if ( ! self::is_available() ) {
			$result['errors'][] = __( 'WP Store Locator is not active, so Groups were not written to wpsl_stores.', 'scoutsuite-waitlist' );
			return $result;
		}

		$seen_ids = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$org_id = self::pick_string( $group, array( 'id', 'orgId', 'groupId' ) );
			if ( '' === $org_id ) {
				$result['skipped']++;
				continue;
			}

			$seen_ids[] = $org_id;
			$outcome    = self::upsert_one( $group, $org_id, $origin_url );

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

		self::clear_wpsl_cache();

		return $result;
	}

	/**
	 * Create or update one store. Never creates a second post for the same org.
	 *
	 * @param array  $group      Directory row.
	 * @param string $org_id     Scout Suite org id.
	 * @param string $origin_url Public Scout Suite origin.
	 * @return string|WP_Error created|updated
	 */
	private static function upsert_one( $group, $org_id, $origin_url ) {
		$existing = self::find_store_id( $org_id );
		$name     = self::pick_string( $group, array( 'name', 'title', 'groupName' ) );
		if ( '' === $name ) {
			$name = $org_id;
		}

		$is_create = ( 0 === $existing );
		$description = self::pick_string( $group, array( 'description', 'content', 'about' ) );

		if ( $is_create ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $name,
					'post_content' => $description,
				),
				true
			);
		} else {
			$update = array(
				'ID'         => $existing,
				'post_title' => $name,
			);
			$existing_post = get_post( $existing );
			if ( $existing_post && '' === trim( (string) $existing_post->post_content ) && '' !== $description ) {
				$update['post_content'] = $description;
			}
			$post_id = wp_update_post( $update, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new WP_Error( 'store_save_failed', sprintf( /* translators: %s: Scout Suite org id */ __( 'Could not save a store for org %s.', 'scoutsuite-waitlist' ), $org_id ) );
		}

		self::write_meta( $post_id, $group, $org_id, $origin_url );

		return $is_create ? 'created' : 'updated';
	}

	/**
	 * Map Scout Suite fields onto WPSL + SFL meta keys.
	 *
	 * @param int    $post_id
	 * @param array  $group
	 * @param string $org_id
	 * @param string $origin_url
	 */
	private static function write_meta( $post_id, $group, $org_id, $origin_url ) {
		$address = self::extract_address( $group );

		self::update_meta( $post_id, 'wpsl_address', $address['address'] );
		self::update_meta( $post_id, 'wpsl_address2', $address['address2'] );
		self::update_meta( $post_id, 'wpsl_city', $address['city'] );
		self::update_meta( $post_id, 'wpsl_state', $address['state'] );
		self::update_meta( $post_id, 'wpsl_zip', $address['zip'] );
		self::update_meta( $post_id, 'wpsl_country', $address['country'] );
		self::update_meta( $post_id, 'wpsl_country_iso', $address['country_iso'] );

		$lat = self::pick_string( $group, array( 'lat', 'latitude' ) );
		$lng = self::pick_string( $group, array( 'lng', 'lon', 'longitude' ) );
		if ( is_array( isset( $group['address'] ) ? $group['address'] : null ) ) {
			if ( '' === $lat ) {
				$lat = self::pick_string( $group['address'], array( 'lat', 'latitude' ) );
			}
			if ( '' === $lng ) {
				$lng = self::pick_string( $group['address'], array( 'lng', 'lon', 'longitude' ) );
			}
		}
		if ( '' !== $lat ) {
			self::update_meta( $post_id, 'wpsl_lat', $lat );
		}
		if ( '' !== $lng ) {
			self::update_meta( $post_id, 'wpsl_lng', $lng );
		}

		self::update_meta( $post_id, 'wpsl_phone', self::pick_string( $group, array( 'phone', 'telephone', 'tel' ) ) );
		self::update_meta( $post_id, 'wpsl_email', self::pick_string( $group, array( 'email', 'contactEmail' ) ) );
		self::update_meta( $post_id, 'wpsl_group_contact', self::pick_string( $group, array( 'contactName', 'contact', 'groupContact' ) ) );

		$website = self::pick_string( $group, array( 'website', 'url', 'groupWebsite' ) );
		self::update_meta( $post_id, 'wpsl_group_website', $website );
		self::update_meta( $post_id, 'wpsl_url', $website );

		$waiting_list = self::pick_string( $group, array( 'waitingListUrl', 'waiting_list_url' ) );
		if ( '' === $waiting_list ) {
			$waiting_list = untrailingslashit( $origin_url ) . '/waiting-list/' . rawurlencode( $org_id );
		}
		self::update_meta( $post_id, 'wpsl_group_wl', $waiting_list );

		self::update_meta( $post_id, 'wpsl_group_type', self::map_group_type( $group ) );
		self::update_meta( $post_id, 'wpsl_section_details', wp_json_encode( self::map_sections( $group ) ) );

		$scarf = self::map_scarf( $group );
		if ( ! empty( $scarf ) ) {
			self::update_meta( $post_id, 'wpsl_section_scarf', wp_json_encode( $scarf ) );
		}

		update_post_meta( $post_id, self::META_ORG_ID, $org_id );
		update_post_meta( $post_id, self::META_SYNC_STATUS, 'synced' );
		update_post_meta( $post_id, '_scoutsuite_synced_at', gmdate( 'c' ) );
	}

	/**
	 * Find the existing store for this Scout Suite org. Lowest ID wins so we
	 * never create a second row for the same org.
	 *
	 * @param string $org_id
	 * @return int
	 */
	private static function find_store_id( $org_id ) {
		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_key'       => self::META_ORG_ID,
				'meta_value'     => $org_id,
			)
		);

		return ! empty( $found[0] ) ? (int) $found[0] : 0;
	}

	/**
	 * Mark previously synced stores that are no longer in the payload.
	 *
	 * @param string[] $seen_ids
	 * @return int Number marked missing.
	 */
	private static function mark_missing( $seen_ids ) {
		$synced = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_ORG_ID,
			)
		);

		$missing = 0;
		foreach ( $synced as $post_id ) {
			$org_id = (string) get_post_meta( $post_id, self::META_ORG_ID, true );
			if ( '' === $org_id || in_array( $org_id, $seen_ids, true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::META_SYNC_STATUS, 'missing_from_source' );
			$missing++;
		}

		return $missing;
	}

	/**
	 * WPSL caches autoload map results in transients.
	 */
	private static function clear_wpsl_cache() {
		global $wpdb;

		if ( isset( $GLOBALS['wpsl_admin'] ) && is_object( $GLOBALS['wpsl_admin'] ) && method_exists( $GLOBALS['wpsl_admin'], 'delete_autoload_transient' ) ) {
			$GLOBALS['wpsl_admin']->delete_autoload_transient();
			return;
		}

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_wpsl_autoload_%'
			    OR option_name LIKE '_transient_timeout_wpsl_autoload_%'"
		);
	}

	/**
	 * Flatten nested or dotted address fields. Country defaults to the UK
	 * because Scout Suite orgs are UK Scouting units; we do not invent a
	 * street address.
	 *
	 * @param array $group
	 * @return array
	 */
	private static function extract_address( $group ) {
		$nested = ( isset( $group['address'] ) && is_array( $group['address'] ) ) ? $group['address'] : array();
		$source = array_merge( $group, $nested );

		$address = self::pick_string( $source, array( 'address', 'address1', 'addressLine1', 'line1', 'street' ) );
		$address2 = self::pick_string( $source, array( 'address2', 'addressLine2', 'line2' ) );
		$city     = self::pick_string( $source, array( 'city', 'town' ) );
		$state    = self::pick_string( $source, array( 'state', 'county', 'region' ) );
		$zip      = self::pick_string( $source, array( 'zip', 'postcode', 'postalCode' ) );
		$country  = self::pick_string( $source, array( 'country' ) );
		$iso      = strtoupper( self::pick_string( $source, array( 'countryIso', 'country_iso', 'countryCode' ) ) );

		if ( '' === $country ) {
			$country = 'United Kingdom';
		}
		if ( '' === $iso ) {
			$iso = ( 'United Kingdom' === $country || 'UK' === $country || 'Great Britain' === $country ) ? 'GB' : '';
		}

		return array(
			'address'     => $address,
			'address2'    => $address2,
			'city'        => $city,
			'state'       => $state,
			'zip'         => $zip,
			'country'     => $country,
			'country_iso' => $iso,
		);
	}

	/**
	 * SFL group_type dropdown: 0 Group, 1 District Section, 2 County Section.
	 *
	 * @param array $group
	 * @return string
	 */
	private static function map_group_type( $group ) {
		$type = strtolower( self::pick_string( $group, array( 'type', 'orgType', 'orgKind', 'level' ) ) );

		if ( in_array( $type, array( 'district', 'district_section', '1' ), true ) ) {
			return '1';
		}
		if ( in_array( $type, array( 'county', 'county_section', '2' ), true ) ) {
			return '2';
		}

		return '0';
	}

	/**
	 * Build the JSON array SFL stores in wpsl_section_details.
	 * Each row: { day, type, time_start, time_finish, name, key } as index strings.
	 *
	 * @param array $group
	 * @return array
	 */
	private static function map_sections( $group ) {
		$raw = array();
		if ( isset( $group['sections'] ) && is_array( $group['sections'] ) ) {
			$raw = $group['sections'];
		} elseif ( isset( $group['meetingNights'] ) && is_array( $group['meetingNights'] ) ) {
			$raw = $group['meetingNights'];
		}

		$rows = array();
		$key  = 1;

		foreach ( $raw as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$schedules = array();
			if ( isset( $section['meetingSchedules'] ) && is_array( $section['meetingSchedules'] ) && ! empty( $section['meetingSchedules'] ) ) {
				$schedules = $section['meetingSchedules'];
			} else {
				$schedules[] = $section;
			}

			$name = self::pick_string( $section, array( 'name', 'sectionName' ) );
			$type = self::map_section_type( self::pick_string( $section, array( 'sectionType', 'type', 'section' ) ) );

			foreach ( $schedules as $schedule ) {
				if ( ! is_array( $schedule ) ) {
					continue;
				}

				$day   = self::map_day( self::pick_string( $schedule, array( 'meetingDay', 'day' ) ) );
				$start = self::map_time( self::pick_string( $schedule, array( 'meetingStartTime', 'startTime', 'time_start' ) ) );
				$end   = self::map_time( self::pick_string( $schedule, array( 'meetingEndTime', 'endTime', 'time_finish' ) ) );

				$rows[] = array(
					'day'         => (string) $day,
					'type'        => (string) $type,
					'time_start'  => (string) $start,
					'time_finish' => (string) $end,
					'name'        => $name,
					'key'         => (string) $key,
				);
				$key++;
			}
		}

		return $rows;
	}

	/**
	 * Map Scout Suite necker data onto the SFL scarf JSON object.
	 *
	 * @param array $group
	 * @return array
	 */
	private static function map_scarf( $group ) {
		foreach ( array( 'section_scarf', 'scarf', 'necker' ) as $key ) {
			if ( isset( $group[ $key ] ) && is_array( $group[ $key ] ) && isset( $group[ $key ]['scarf_type'] ) ) {
				return $group[ $key ];
			}
		}

		$colors = array();
		if ( isset( $group['neckerColors'] ) && is_array( $group['neckerColors'] ) ) {
			$colors = $group['neckerColors'];
		} elseif ( isset( $group['necker'] ) && is_array( $group['necker'] ) && isset( $group['necker']['colors'] ) && is_array( $group['necker']['colors'] ) ) {
			$colors = $group['necker']['colors'];
		}

		$hex = array();
		foreach ( $colors as $color ) {
			if ( ! is_string( $color ) ) {
				continue;
			}
			$color = trim( $color );
			if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
				$hex[] = $color;
			}
		}

		$overlay = self::pick_string( $group, array( 'groupBadgeNeckerUrl', 'neckerOverlayUrl', 'neckerImageUrl' ) );
		if ( isset( $group['necker'] ) && is_array( $group['necker'] ) && '' === $overlay ) {
			$overlay = self::pick_string( $group['necker'], array( 'overlayUrl', 'imageUrl', 'o' ) );
		}

		if ( empty( $hex ) && '' === $overlay ) {
			return array();
		}

		$left  = isset( $hex[0] ) ? $hex[0] : '#ffffff';
		$right = isset( $hex[1] ) ? $hex[1] : $left;
		$b1    = isset( $hex[2] ) ? $hex[2] : '#ffffff';
		$b2    = isset( $hex[3] ) ? $hex[3] : '#ffffff';
		$b3    = isset( $hex[4] ) ? $hex[4] : '#ffffff';
		$stripe = isset( $hex[5] ) ? $hex[5] : '#ffffff';

		$count = count( $hex );
		if ( $count >= 5 ) {
			$scarf_type = '3'; // triple border
		} elseif ( 4 === $count ) {
			$scarf_type = '2'; // double border
		} elseif ( 3 === $count ) {
			$scarf_type = '1'; // single border
		} else {
			$scarf_type = '0'; // plain / split
		}

		if ( '' !== $overlay && empty( $hex ) ) {
			$scarf_type = '9'; // blank, image overlay only
		}

		return array(
			'scarf_type' => $scarf_type,
			'l'          => $left,
			'r'          => $right,
			'b1l'        => $b1,
			'b1r'        => $b1,
			'b2l'        => $b2,
			'b2r'        => $b2,
			'b3l'        => $b3,
			'b3r'        => $b3,
			's'          => $stripe,
			'o'          => $overlay,
		);
	}

	/**
	 * @param string $type Section name from Scout Suite.
	 * @return int
	 */
	private static function map_section_type( $type ) {
		$normalised = strtolower( trim( $type ) );
		$aliases    = array(
			'squirrels' => 0,
			'drey'      => 0,
			'beavers'   => 1,
			'colony'    => 1,
			'cubs'      => 2,
			'pack'      => 2,
			'scouts'    => 3,
			'troop'     => 3,
			'explorers' => 4,
			'unit'      => 4,
			'network'   => 5,
			'adults'    => 6,
			'sasu'      => 6,
		);

		if ( isset( $aliases[ $normalised ] ) ) {
			return $aliases[ $normalised ];
		}

		if ( is_numeric( $type ) && (int) $type >= 0 && (int) $type <= 6 ) {
			return (int) $type;
		}

		return 1;
	}

	/**
	 * @param string $day
	 * @return int
	 */
	private static function map_day( $day ) {
		$normalised = strtolower( trim( $day ) );
		foreach ( self::$days as $index => $label ) {
			if ( $normalised === strtolower( $label ) ) {
				return $index;
			}
		}
		if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
			return (int) $day;
		}
		return 0;
	}

	/**
	 * Convert a time string to the SFL 15-minute index (12:00 AM = 0).
	 *
	 * @param string $time
	 * @return int
	 */
	private static function map_time( $time ) {
		$time = trim( (string) $time );
		if ( '' === $time ) {
			return 0;
		}
		if ( is_numeric( $time ) && (int) $time >= 0 && (int) $time <= 95 ) {
			return (int) $time;
		}

		$parsed = strtotime( $time );
		if ( false === $parsed ) {
			return 0;
		}

		$minutes = ( (int) gmdate( 'G', $parsed ) * 60 ) + (int) gmdate( 'i', $parsed );
		// strtotime of "18:15" is parsed in server TZ; use date() on a fixed day instead.
		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m ) ) {
			$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];
		} else {
			$minutes = ( (int) date( 'G', $parsed ) * 60 ) + (int) date( 'i', $parsed );
		}

		$slot = (int) round( $minutes / 15 );
		if ( $slot < 0 ) {
			$slot = 0;
		}
		if ( $slot > 95 ) {
			$slot = 95;
		}

		return $slot;
	}

	/**
	 * First non-empty string among candidate keys.
	 *
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

	/**
	 * @param int    $post_id
	 * @param string $key
	 * @param string $value
	 */
	private static function update_meta( $post_id, $key, $value ) {
		update_post_meta( $post_id, $key, $value );
	}
}
