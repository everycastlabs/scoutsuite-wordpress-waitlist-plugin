<?php
/**
 * Thin client for the Scout Suite API.
 *
 * Endpoints used (confirmed against https://api.scoutsuite.app/openapi.json):
 *
 *   POST /api/groups/{groupId}/waiting-list
 *     Adds an entry to the group's waiting list. Callable publicly for the
 *     public form, or with a Bearer API key (ss_at_...).
 *     Required body fields: firstName, lastName, parentName, parentEmail.
 *     Optional: dateOfBirth, section, parentPhone, notes, isSibling, postcode.
 *
 *   GET /api/groups/{groupId}/waiting-list/signup-info
 *     Public. Returns the group name and active sections, used to populate
 *     the section dropdown on the form.
 *
 * Errors come back as { success: false, error: { code, message, details } }.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_API {

	const BASE_URL = 'https://api.scoutsuite.app';

	/**
	 * Scout Suite API key (ss_at_...). May be empty: the signup endpoint
	 * also accepts unauthenticated calls from the public form.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Scout Suite group ID.
	 *
	 * @var string
	 */
	private $group_id;

	public function __construct( $api_key, $group_id ) {
		$this->api_key  = trim( (string) $api_key );
		$this->group_id = trim( (string) $group_id );
	}

	/**
	 * Whether the client has the group ID it needs to make any call.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->group_id;
	}

	/**
	 * Submit a waiting list entry.
	 *
	 * @param array $fields Body fields already validated by the caller.
	 * @return array { success: bool, message: string, code: string }
	 */
	public function submit_entry( $fields ) {
		$url = self::BASE_URL . '/api/groups/' . rawurlencode( $this->group_id ) . '/waiting-list';

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
		if ( is_array( $cached ) && isset( $cached['group_id'] ) && $cached['group_id'] === $this->group_id ) {
			return $cached;
		}

		$url = self::BASE_URL . '/api/groups/' . rawurlencode( $this->group_id ) . '/waiting-list/signup-info';

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
			'group_id'   => $this->group_id,
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
	 * Build request headers. The API key is only ever used server side and
	 * is never printed into page markup.
	 *
	 * @param bool $json_body Whether the request sends a JSON body.
	 * @return array
	 */
	private function build_headers( $json_body ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( $json_body ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		return $headers;
	}

	/**
	 * Turn a wp_remote_* response into a predictable array. Pulls the human
	 * readable message out of the Scout Suite error envelope when present.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return array { success: bool, message: string, code: string, data: mixed }
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not reach Scout Suite. Please try again in a few minutes.', 'scoutsuite-waitlist' ),
				'code'    => 'network_error',
				'data'    => null,
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
			);
		}

		$code    = 'api_error';
		$message = __( 'Something went wrong while submitting the form. Please try again.', 'scoutsuite-waitlist' );

		if ( is_array( $body ) && isset( $body['error'] ) && is_array( $body['error'] ) ) {
			if ( ! empty( $body['error']['code'] ) && is_string( $body['error']['code'] ) ) {
				$code = $body['error']['code'];
			}
			if ( ! empty( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
				$message = $body['error']['message'];
			}
		}

		// Friendlier wording for likely duplicate signups.
		if ( 409 === $status || false !== stripos( $code . ' ' . $message, 'duplicate' ) || false !== stripos( $message, 'already' ) ) {
			$message = __( 'It looks like this child is already on the waiting list. If you think that is wrong, please contact the group directly.', 'scoutsuite-waitlist' );
			$code    = 'duplicate';
		} elseif ( 404 === $status ) {
			$message = __( 'This form is not set up correctly (group not found). Please contact the website owner.', 'scoutsuite-waitlist' );
		} elseif ( 401 === $status || 403 === $status ) {
			$message = __( 'This form is not set up correctly (not authorised). Please contact the website owner.', 'scoutsuite-waitlist' );
		}

		return array(
			'success' => false,
			'message' => $message,
			'code'    => $code,
			'data'    => is_array( $body ) && isset( $body['error']['details'] ) ? $body['error']['details'] : null,
		);
	}
}
