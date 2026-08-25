<?php
/**
 * Thin client for the Scout Suite API.
 *
 * Endpoints used (confirmed against https://api.scoutsuite.app/openapi.json
 * where they exist; WordPress plugin-only routes are documented here so the
 * backend can ship them without this plugin inventing fallback data):
 *
 *   POST /api/groups/{groupId}/waiting-list
 *     Adds an entry to the group's waiting list. Callable publicly for the
 *     public form, or with a Bearer API key (ss_at_...).
 *     Required body fields: firstName, lastName, parentName, parentEmail.
 *     Optional: dateOfBirth, section, parentPhone, notes, isSibling, postcode,
 *     source ("wordpress" when submitted from this plugin).
 *
 *   GET /api/groups/{groupId}/waiting-list/signup-info
 *     Public. Returns the group name and active sections, used to populate
 *     the section dropdown on the form.
 *
 *   GET /api/orgs/{orgId}/wordpress/directory
 *     Plugin-only. Bearer required. Directory of Groups in a District/County,
 *     or the single Group when orgId is a Group. 404 must fail the sync.
 *
 *   GET /api/orgs/{orgId}/wordpress/events
 *     Plugin-only. Bearer required. Public events for that org. 404 must
 *     fail the sync rather than inventing events.
 *
 * Errors come back as { success: false, error: { code, message, details } }.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_API {

	const DEFAULT_BASE_URL = 'https://api.scoutsuite.app';

	/**
	 * Scout Suite API key (ss_at_...). May be empty: the signup endpoint
	 * also accepts unauthenticated calls from the public form. Directory
	 * and events sync always send it.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Scout Suite org ID (Group, District or County).
	 *
	 * @var string
	 */
	private $org_id;

	/**
	 * API origin, no trailing slash. Override for staging.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * @param string $api_key  Bearer token, may be empty for public signup.
	 * @param string $org_id   Group, District or County id.
	 * @param string $base_url Optional API base URL.
	 */
	public function __construct( $api_key, $org_id, $base_url = '' ) {
		$this->api_key  = trim( (string) $api_key );
		$this->org_id   = trim( (string) $org_id );
		$this->base_url = self::normalise_base_url( $base_url );
	}

	/**
	 * Strip trailing slashes and fall back to production.
	 *
	 * @param string $base_url Raw setting.
	 * @return string
	 */
	public static function normalise_base_url( $base_url ) {
		$base_url = untrailingslashit( trim( (string) $base_url ) );
		if ( '' === $base_url ) {
			return self::DEFAULT_BASE_URL;
		}
		return $base_url;
	}

	/**
	 * Public web origin derived from the API host, used for waiting-list URLs.
	 * api.scoutsuite.app → scoutsuite.app.
	 *
	 * @return string
	 */
	public function origin_url() {
		$parts = wp_parse_url( $this->base_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'https://scoutsuite.app';
		}

		$host   = preg_replace( '/^api\./', '', $parts['host'] );
		$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';

		return $scheme . '://' . $host;
	}

	/**
	 * Whether the client has the org ID it needs to make any call.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->org_id;
	}

	/**
	 * Directory and events sync require a Bearer token.
	 *
	 * @return bool
	 */
	public function is_sync_configured() {
		return $this->is_configured() && '' !== $this->api_key;
	}

	/**
	 * @return string
	 */
	public function get_org_id() {
		return $this->org_id;
	}

	/**
	 * Submit a waiting list entry.
	 *
	 * @param array $fields Body fields already validated by the caller.
	 * @return array { success: bool, message: string, code: string }
	 */
	public function submit_entry( $fields ) {
		$url = $this->base_url . '/api/groups/' . rawurlencode( $this->org_id ) . '/waiting-list';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->build_headers( true ),
				'body'    => wp_json_encode( $fields ),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Fetch the group name and active sections for the public form.
	 * Results are cached in a transient for one hour to keep page loads fast.
	 *
	 * @return array { success: bool, group_name: string, sections: string[] }
	 */
	public function get_signup_info() {
		$cached = get_transient( SCOUTSUITE_WAITLIST_SECTIONS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['group_id'] ) && $cached['group_id'] === $this->org_id ) {
			return $cached;
		}

		$url = $this->base_url . '/api/groups/' . rawurlencode( $this->org_id ) . '/waiting-list/signup-info';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->build_headers( false ),
			)
		);

		$parsed = $this->parse_response( $response );

		$info = array(
			'success'    => $parsed['success'],
			'group_id'   => $this->org_id,
			'group_name' => '',
			'sections'   => array(),
		);

		if ( $parsed['success'] && is_array( $parsed['data'] ) ) {
			$data = $parsed['data'];

			if ( isset( $data['groupName'] ) && is_string( $data['groupName'] ) ) {
				$info['group_name'] = $data['groupName'];
			} elseif ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
				$info['group_name'] = $data['name'];
			}

			// Sections may be an array of strings or of objects with a name.
			if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
				foreach ( $data['sections'] as $section ) {
					if ( is_string( $section ) && '' !== $section ) {
						$info['sections'][] = $section;
					} elseif ( is_array( $section ) ) {
						if ( isset( $section['name'] ) && is_string( $section['name'] ) ) {
							$info['sections'][] = $section['name'];
						} elseif ( isset( $section['section'] ) && is_string( $section['section'] ) ) {
							$info['sections'][] = $section['section'];
						}
					}
				}
			}

			// Only cache successful lookups.
			set_transient( SCOUTSUITE_WAITLIST_SECTIONS_TRANSIENT, $info, HOUR_IN_SECONDS );
		}

		return $info;
	}

	/**
	 * Plugin-only directory for this org.
	 *
	 * @return array { success: bool, message: string, code: string, data: mixed, status: int }
	 */
	public function get_directory() {
		$url = $this->base_url . '/api/orgs/' . rawurlencode( $this->org_id ) . '/wordpress/directory';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $this->build_headers( false, true ),
			)
		);

		return $this->parse_response( $response, 'directory' );
	}

	/**
	 * Plugin-only public events for this org.
	 *
	 * @return array { success: bool, message: string, code: string, data: mixed, status: int }
	 */
	public function get_public_events() {
		$url = $this->base_url . '/api/orgs/' . rawurlencode( $this->org_id ) . '/wordpress/events';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $this->build_headers( false, true ),
			)
		);

		return $this->parse_response( $response, 'events' );
	}

	/**
	 * Build request headers. The API key is only ever used server side and
	 * is never printed into page markup.
	 *
	 * @param bool $json_body   Whether the request sends a JSON body.
	 * @param bool $require_key Whether to send Authorization even if empty
	 *                          (sync calls). An empty key still omits the header.
	 * @return array
	 */
	private function build_headers( $json_body, $require_key = false ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( $json_body ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		} elseif ( $require_key ) {
			// Sync endpoints require a key; omitting it produces a clear 401
			// from the API rather than a silent empty directory.
			$headers['Authorization'] = 'Bearer';
		}

		return $headers;
	}

	/**
	 * Turn a wp_remote_* response into a predictable array. Pulls the human
	 * readable message out of the Scout Suite error envelope when present.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @param string         $context  signup|directory|events for wording.
	 * @return array { success: bool, message: string, code: string, data: mixed, status: int }
	 */
	private function parse_response( $response, $context = 'signup' ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not reach Scout Suite. Please try again in a few minutes.', 'scoutsuite-waitlist' ),
				'code'    => 'network_error',
				'data'    => null,
				'status'  => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $body ) && ! empty( $body['success'] ) ) {
			return array(
				'success' => true,
				'message' => '',
				'code'    => '',
				'data'    => isset( $body['data'] ) ? $body['data'] : null,
				'status'  => $status,
			);
		}

		$code    = 'api_error';
		$message = __( 'Something went wrong while submitting the form. Please try again.', 'scoutsuite-waitlist' );

		if ( 'directory' === $context ) {
			$message = __( 'Scout Suite directory sync failed.', 'scoutsuite-waitlist' );
		} elseif ( 'events' === $context ) {
			$message = __( 'Scout Suite events sync failed.', 'scoutsuite-waitlist' );
		}

		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			if ( ! empty( $body['error']['code'] ) && is_string( $body['error']['code'] ) ) {
				$code = $body['error']['code'];
			}
			if ( ! empty( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
				$message = $body['error']['message'];
			}
		}

		// Friendlier wording for likely duplicate signups.
		if ( 'signup' === $context && ( 409 === $status || false !== stripos( $code . ' ' . $message, 'duplicate' ) || false !== stripos( $message, 'already' ) ) ) {
			$message = __( 'It looks like this child is already on the waiting list. If you think that is wrong, please contact the group directly.', 'scoutsuite-waitlist' );
			$code    = 'duplicate';
		} elseif ( 404 === $status ) {
			$code = 'not_found';
			if ( 'directory' === $context ) {
				$message = __( 'Scout Suite directory endpoint was not found (404). Sync stopped rather than inventing Groups. Confirm the plugin-only API is deployed and the Org ID is correct.', 'scoutsuite-waitlist' );
			} elseif ( 'events' === $context ) {
				$message = __( 'Scout Suite events endpoint was not found (404). Sync stopped rather than inventing events. Confirm the plugin-only API is deployed and the Org ID is correct.', 'scoutsuite-waitlist' );
			} else {
				$message = __( 'This form is not set up correctly (group not found). Please contact the website owner.', 'scoutsuite-waitlist' );
			}
		} elseif ( 401 === $status || 403 === $status ) {
			$code = 'not_authorised';
			if ( 'signup' === $context ) {
				$message = __( 'This form is not set up correctly (not authorised). Please contact the website owner.', 'scoutsuite-waitlist' );
			} else {
				$message = __( 'Scout Suite rejected the API key (not authorised). Check the Bearer token from the developer portal.', 'scoutsuite-waitlist' );
			}
		}

		return array(
			'success' => false,
			'message' => $message,
			'code'    => $code,
			'data'    => is_array( $body ) && isset( $body['error']['details'] ) ? $body['error']['details'] : null,
			'status'  => $status,
		);
	}
}
