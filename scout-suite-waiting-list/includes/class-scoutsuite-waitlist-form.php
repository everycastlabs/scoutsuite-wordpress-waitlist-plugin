<?php
/**
 * Public form: shortcode rendering and submission handling.
 *
 * The form posts to admin-post.php. The handler validates, calls the Scout
 * Suite API server side (the API key never reaches the browser), stores the
 * outcome in a short lived transient and redirects back to the form. This
 * keeps the flow plain HTML with no JavaScript requirement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_Form {

	const NONCE_ACTION = 'scoutsuite_waitlist_submit';
	const POST_ACTION  = 'scoutsuite_waitlist_submit';

	public function __construct() {
		add_shortcode( 'scoutsuite_waitlist', array( __CLASS__, 'render_form' ) );

		// Handle submissions from both logged out and logged in visitors.
		add_action( 'admin_post_nopriv_' . self::POST_ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_submission' ) );
	}

	/**
	 * Render the form. Used by the shortcode and as the block render
	 * callback, so it must be static and self contained.
	 *
	 * @return string
	 */
	public static function render_form() {
		$options = scoutsuite_waitlist_get_options();

		wp_enqueue_style( 'scoutsuite-waitlist' );

		if ( '' === trim( $options['org_id'] ) && '' === trim( (string) ( $options['waitlist_group_id'] ?? '' ) ) ) {
			// Tell editors what is missing; show nothing to visitors.
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="sswl-notice sswl-notice-error">'
					. esc_html__( 'Scout Suite: set your Org ID under Settings, Scout Suite. Only administrators see this message.', 'scoutsuite-waitlist' )
					. '</div>';
			}
			return '';
		}

		$feedback = self::consume_feedback();
		$old      = isset( $feedback['old'] ) && is_array( $feedback['old'] ) ? $feedback['old'] : array();
		$errors   = isset( $feedback['errors'] ) && is_array( $feedback['errors'] ) ? $feedback['errors'] : array();

		// After a successful submission show only the success message.
		if ( isset( $feedback['status'] ) && 'success' === $feedback['status'] ) {
			return '<div id="scoutsuite-waitlist" class="sswl-notice sswl-notice-success" role="status">'
				. esc_html( $options['success_message'] )
				. '</div>';
		}

		$sections = self::get_sections( $options );

		ob_start();
		?>
		<div id="scoutsuite-waitlist" class="sswl-wrap">
			<?php if ( isset( $feedback['status'] ) && 'error' === $feedback['status'] && ! empty( $feedback['message'] ) ) : ?>
				<div class="sswl-notice sswl-notice-error" role="alert">
					<?php echo esc_html( $feedback['message'] ); ?>
				</div>
			<?php endif; ?>

			<form class="sswl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="sswl_redirect" value="<?php echo esc_url( self::current_url() ); ?>" />

				<?php // Honeypot: hidden from people, tempting to bots. Submissions that fill it are dropped. ?>
				<p class="sswl-hp" aria-hidden="true">
					<label for="sswl_website">Website</label>
					<input type="text" id="sswl_website" name="sswl_website" value="" tabindex="-1" autocomplete="off" />
				</p>

				<fieldset class="sswl-fieldset">
					<legend><?php esc_html_e( 'About your child', 'scoutsuite-waitlist' ); ?></legend>

					<div class="sswl-row sswl-row-2col">
						<p class="sswl-field">
							<label for="sswl_first_name"><?php esc_html_e( 'First name', 'scoutsuite-waitlist' ); ?> <span class="sswl-required" aria-hidden="true">*</span></label>
							<input type="text" id="sswl_first_name" name="sswl_first_name" required
								value="<?php echo esc_attr( self::old_value( $old, 'first_name' ) ); ?>" autocomplete="off" />
							<?php self::field_error( $errors, 'first_name' ); ?>
						</p>
						<p class="sswl-field">
							<label for="sswl_last_name"><?php esc_html_e( 'Last name', 'scoutsuite-waitlist' ); ?> <span class="sswl-required" aria-hidden="true">*</span></label>
							<input type="text" id="sswl_last_name" name="sswl_last_name" required
								value="<?php echo esc_attr( self::old_value( $old, 'last_name' ) ); ?>" autocomplete="off" />
							<?php self::field_error( $errors, 'last_name' ); ?>
						</p>
					</div>

					<div class="sswl-row sswl-row-2col">
						<p class="sswl-field">
							<label for="sswl_dob"><?php esc_html_e( 'Date of birth', 'scoutsuite-waitlist' ); ?></label>
							<input type="date" id="sswl_dob" name="sswl_dob"
								value="<?php echo esc_attr( self::old_value( $old, 'dob' ) ); ?>" />
							<?php self::field_error( $errors, 'dob' ); ?>
						</p>
						<?php if ( ! empty( $sections ) ) : ?>
							<p class="sswl-field">
								<label for="sswl_section"><?php esc_html_e( 'Section', 'scoutsuite-waitlist' ); ?></label>
								<select id="sswl_section" name="sswl_section">
									<option value=""><?php esc_html_e( 'Not sure', 'scoutsuite-waitlist' ); ?></option>
									<?php foreach ( $sections as $section ) : ?>
										<option value="<?php echo esc_attr( $section ); ?>" <?php selected( self::old_value( $old, 'section' ), $section ); ?>>
											<?php echo esc_html( $section ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php endif; ?>
					</div>

					<p class="sswl-field sswl-field-checkbox">
						<label>
							<input type="checkbox" name="sswl_is_sibling" value="1" <?php checked( self::old_value( $old, 'is_sibling' ), '1' ); ?> />
							<?php esc_html_e( 'This child has a sibling already in the group', 'scoutsuite-waitlist' ); ?>
						</label>
					</p>
				</fieldset>

				<fieldset class="sswl-fieldset">
					<legend><?php esc_html_e( 'About you (parent or carer)', 'scoutsuite-waitlist' ); ?></legend>

					<p class="sswl-field">
						<label for="sswl_parent_name"><?php esc_html_e( 'Your name', 'scoutsuite-waitlist' ); ?> <span class="sswl-required" aria-hidden="true">*</span></label>
						<input type="text" id="sswl_parent_name" name="sswl_parent_name" required autocomplete="name"
							value="<?php echo esc_attr( self::old_value( $old, 'parent_name' ) ); ?>" />
						<?php self::field_error( $errors, 'parent_name' ); ?>
					</p>

					<div class="sswl-row sswl-row-2col">
						<p class="sswl-field">
							<label for="sswl_parent_email"><?php esc_html_e( 'Email address', 'scoutsuite-waitlist' ); ?> <span class="sswl-required" aria-hidden="true">*</span></label>
							<input type="email" id="sswl_parent_email" name="sswl_parent_email" required autocomplete="email"
								value="<?php echo esc_attr( self::old_value( $old, 'parent_email' ) ); ?>" />
							<?php self::field_error( $errors, 'parent_email' ); ?>
						</p>
						<p class="sswl-field">
							<label for="sswl_parent_phone"><?php esc_html_e( 'Phone number', 'scoutsuite-waitlist' ); ?></label>
							<input type="tel" id="sswl_parent_phone" name="sswl_parent_phone" autocomplete="tel"
								value="<?php echo esc_attr( self::old_value( $old, 'parent_phone' ) ); ?>" />
						</p>
					</div>

					<p class="sswl-field">
						<label for="sswl_postcode"><?php esc_html_e( 'Postcode', 'scoutsuite-waitlist' ); ?></label>
						<input type="text" id="sswl_postcode" name="sswl_postcode" autocomplete="postal-code" class="sswl-input-short"
							value="<?php echo esc_attr( self::old_value( $old, 'postcode' ) ); ?>" />
					</p>

					<p class="sswl-field">
						<label for="sswl_notes"><?php esc_html_e( 'Anything else we should know?', 'scoutsuite-waitlist' ); ?></label>
						<textarea id="sswl_notes" name="sswl_notes" rows="3"><?php echo esc_textarea( self::old_value( $old, 'notes' ) ); ?></textarea>
					</p>
				</fieldset>

				<div class="sswl-consent">
					<?php if ( '' !== trim( $options['privacy_notice'] ) ) : ?>
						<p class="sswl-privacy-notice"><?php echo esc_html( $options['privacy_notice'] ); ?></p>
					<?php endif; ?>
					<p class="sswl-field sswl-field-checkbox">
						<label>
							<input type="checkbox" name="sswl_consent" value="1" required <?php checked( self::old_value( $old, 'consent' ), '1' ); ?> />
							<?php echo esc_html( $options['consent_label'] ); ?> <span class="sswl-required" aria-hidden="true">*</span>
						</label>
						<?php self::field_error( $errors, 'consent' ); ?>
					</p>
				</div>

				<p class="sswl-submit">
					<button type="submit" class="sswl-button"><?php esc_html_e( 'Join the waiting list', 'scoutsuite-waitlist' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle the admin-post.php submission.
	 */
	public function handle_submission() {
		$redirect = isset( $_POST['sswl_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['sswl_redirect'] ) ) : '';
		if ( '' === $redirect || 0 !== strpos( $redirect, home_url() ) ) {
			$redirect = home_url( '/' );
		}

		// Nonce check. On failure send the visitor back with a retry message.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			$this->redirect_with_feedback(
				$redirect,
				array(
					'status'  => 'error',
					'message' => __( 'Your session expired. Please try submitting the form again.', 'scoutsuite-waitlist' ),
				)
			);
		}

		// Honeypot: pretend success so bots learn nothing, store nothing.
		if ( ! empty( $_POST['sswl_website'] ) ) {
			$this->redirect_with_feedback( $redirect, array( 'status' => 'success' ) );
		}

		// Sanitise every input.
		$input = array(
			'first_name'   => isset( $_POST['sswl_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_first_name'] ) ) : '',
			'last_name'    => isset( $_POST['sswl_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_last_name'] ) ) : '',
			'dob'          => isset( $_POST['sswl_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_dob'] ) ) : '',
			'section'      => isset( $_POST['sswl_section'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_section'] ) ) : '',
			'is_sibling'   => isset( $_POST['sswl_is_sibling'] ) ? '1' : '',
			'parent_name'  => isset( $_POST['sswl_parent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_parent_name'] ) ) : '',
			'parent_email' => isset( $_POST['sswl_parent_email'] ) ? sanitize_email( wp_unslash( $_POST['sswl_parent_email'] ) ) : '',
			'parent_phone' => isset( $_POST['sswl_parent_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_parent_phone'] ) ) : '',
			'postcode'     => isset( $_POST['sswl_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['sswl_postcode'] ) ) : '',
			'notes'        => isset( $_POST['sswl_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sswl_notes'] ) ) : '',
			'consent'      => isset( $_POST['sswl_consent'] ) ? '1' : '',
		);

		// Validate. Required fields mirror the Scout Suite API contract:
		// firstName, lastName, parentName, parentEmail. Consent is required
		// by this plugin for GDPR.
		$errors = array();

		if ( '' === $input['first_name'] ) {
			$errors['first_name'] = __( 'Please enter your child\'s first name.', 'scoutsuite-waitlist' );
		}
		if ( '' === $input['last_name'] ) {
			$errors['last_name'] = __( 'Please enter your child\'s last name.', 'scoutsuite-waitlist' );
		}
		if ( '' === $input['parent_name'] ) {
			$errors['parent_name'] = __( 'Please enter your name.', 'scoutsuite-waitlist' );
		}
		if ( '' === $input['parent_email'] || ! is_email( $input['parent_email'] ) ) {
			$errors['parent_email'] = __( 'Please enter a valid email address.', 'scoutsuite-waitlist' );
		}
		if ( '' !== $input['dob'] ) {
			$dob_time = strtotime( $input['dob'] );
			if ( false === $dob_time || $dob_time > time() ) {
				$errors['dob'] = __( 'Please enter a valid date of birth.', 'scoutsuite-waitlist' );
			}
		}
		if ( '1' !== $input['consent'] ) {
			$errors['consent'] = __( 'Please tick the consent box so we can process your application.', 'scoutsuite-waitlist' );
		}

		if ( ! empty( $errors ) ) {
			$this->redirect_with_feedback(
				$redirect,
				array(
					'status'  => 'error',
					'message' => __( 'Please check the highlighted fields and try again.', 'scoutsuite-waitlist' ),
					'errors'  => $errors,
					'old'     => $input,
				)
			);
		}

		// Build the API payload. Optional fields are only sent when filled in.
		$payload = array(
			'firstName'   => $input['first_name'],
			'lastName'    => $input['last_name'],
			'parentName'  => $input['parent_name'],
			'parentEmail' => $input['parent_email'],
			'source'      => 'wordpress',
		);

		if ( '' !== $input['dob'] ) {
			$payload['dateOfBirth'] = $input['dob'];
		}
		if ( '' !== $input['section'] ) {
			$payload['section'] = $input['section'];
		}
		if ( '' !== $input['parent_phone'] ) {
			$payload['parentPhone'] = $input['parent_phone'];
		}
		if ( '' !== $input['postcode'] ) {
			$payload['postcode'] = $input['postcode'];
		}
		$payload['isSibling'] = ( '1' === $input['is_sibling'] );

		// Record consent in the notes so the group has an audit trail.
		$consent_note = sprintf(
			/* translators: %s: date */
			__( 'Consent given via website form on %s.', 'scoutsuite-waitlist' ),
			wp_date( 'j F Y' )
		);
		$payload['notes'] = '' !== $input['notes'] ? $input['notes'] . "\n\n" . $consent_note : $consent_note;

		$api    = scoutsuite_waitlist_get_signup_api();
		$result = $api->submit_entry( $payload );

		if ( $result['success'] ) {
			$this->redirect_with_feedback( $redirect, array( 'status' => 'success' ) );
		}

		$this->redirect_with_feedback(
			$redirect,
			array(
				'status'  => 'error',
				'message' => $result['message'],
				'old'     => $input,
			)
		);
	}

	/**
	 * Store feedback in a transient and redirect back to the form. The URL
	 * only ever carries a random token, never personal data.
	 *
	 * @param string $redirect Destination URL.
	 * @param array  $feedback Status, message, field errors, old input.
	 */
	private function redirect_with_feedback( $redirect, $feedback ) {
		// Lowercase so the token survives sanitize_key() when read back.
		$token = strtolower( wp_generate_password( 16, false, false ) );
		set_transient( 'sswl_feedback_' . $token, $feedback, 5 * MINUTE_IN_SECONDS );

		$url = add_query_arg( 'sswl', rawurlencode( $token ), $redirect ) . '#scoutsuite-waitlist';
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Read and delete the feedback transient referenced in the URL, if any.
	 *
	 * @return array
	 */
	private static function consume_feedback() {
		if ( empty( $_GET['sswl'] ) ) {
			return array();
		}

		$token = sanitize_key( wp_unslash( $_GET['sswl'] ) );
		if ( '' === $token ) {
			return array();
		}

		$feedback = get_transient( 'sswl_feedback_' . $token );
		delete_transient( 'sswl_feedback_' . $token );

		return is_array( $feedback ) ? $feedback : array();
	}

	/**
	 * Section list for the dropdown: the manual override from settings wins,
	 * otherwise fetch the group's active sections from the public
	 * signup-info endpoint (cached for an hour).
	 *
	 * @param array $options Plugin options.
	 * @return string[]
	 */
	private static function get_sections( $options ) {
		if ( '' !== trim( $options['sections_override'] ) ) {
			return array_filter( array_map( 'trim', explode( "\n", $options['sections_override'] ) ) );
		}

		$api  = scoutsuite_waitlist_get_signup_api();
		$info = $api->get_signup_info();

		return ! empty( $info['sections'] ) ? $info['sections'] : array();
	}

	/**
	 * URL of the page currently being rendered, used as the redirect target.
	 * The sswl token from any previous submission is stripped.
	 *
	 * @return string
	 */
	private static function current_url() {
		$permalink = get_permalink();
		if ( $permalink ) {
			return remove_query_arg( 'sswl', $permalink );
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return remove_query_arg( 'sswl', home_url( $request ) );
	}

	/**
	 * Previously submitted value for repopulating the form after an error.
	 *
	 * @param array  $old Old input array.
	 * @param string $key Field key.
	 * @return string
	 */
	private static function old_value( $old, $key ) {
		return isset( $old[ $key ] ) && is_string( $old[ $key ] ) ? $old[ $key ] : '';
	}

	/**
	 * Print a field level validation message when one exists.
	 *
	 * @param array  $errors Field error map.
	 * @param string $key    Field key.
	 */
	private static function field_error( $errors, $key ) {
		if ( ! empty( $errors[ $key ] ) ) {
			echo '<span class="sswl-field-error">' . esc_html( $errors[ $key ] ) . '</span>';
		}
	}
}
