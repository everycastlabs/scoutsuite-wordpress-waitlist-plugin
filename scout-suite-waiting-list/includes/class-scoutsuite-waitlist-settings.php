<?php
/**
 * Settings page: Settings > Scout Suite Waiting List.
 *
 * Uses the WordPress Settings API. All values live in a single option array
 * so uninstall clean up is one delete_option call.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScoutSuite_Waitlist_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the page under the Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Scout Suite Waiting List', 'scoutsuite-waitlist' ),
			__( 'Scout Suite Waiting List', 'scoutsuite-waitlist' ),
			'manage_options',
			'scoutsuite-waitlist',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the option, sections and fields.
	 */
	public function register_settings() {
		register_setting(
			'scoutsuite_waitlist',
			SCOUTSUITE_WAITLIST_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitise_options' ),
			)
		);

		add_settings_section(
			'scoutsuite_waitlist_connection',
			__( 'Scout Suite connection', 'scoutsuite-waitlist' ),
			array( $this, 'render_connection_intro' ),
			'scoutsuite-waitlist'
		);

		add_settings_field(
			'group_id',
			__( 'Group ID', 'scoutsuite-waitlist' ),
			array( $this, 'render_group_id_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_field(
			'api_key',
			__( 'API key (optional)', 'scoutsuite-waitlist' ),
			array( $this, 'render_api_key_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_field(
			'sections_override',
			__( 'Sections (optional)', 'scoutsuite-waitlist' ),
			array( $this, 'render_sections_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_section(
			'scoutsuite_waitlist_form_text',
			__( 'Form text', 'scoutsuite-waitlist' ),
			'__return_false',
			'scoutsuite-waitlist'
		);

		add_settings_field(
			'privacy_notice',
			__( 'Privacy notice', 'scoutsuite-waitlist' ),
			array( $this, 'render_privacy_notice_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_form_text'
		);

		add_settings_field(
			'consent_label',
			__( 'Consent checkbox label', 'scoutsuite-waitlist' ),
			array( $this, 'render_consent_label_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_form_text'
		);

		add_settings_field(
			'success_message',
			__( 'Success message', 'scoutsuite-waitlist' ),
			array( $this, 'render_success_message_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_form_text'
		);
	}

	/**
	 * Sanitise everything on save and clear the cached sections so a changed
	 * Group ID takes effect straight away.
	 *
	 * @param array $input Raw submitted values.
	 * @return array
	 */
	public function sanitise_options( $input ) {
		$defaults = scoutsuite_waitlist_get_options();
		$clean    = array();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$clean['group_id'] = isset( $input['group_id'] ) ? sanitize_text_field( $input['group_id'] ) : '';
		$clean['api_key']  = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';

		// One section per line, blanks removed.
		$sections_raw = isset( $input['sections_override'] ) ? sanitize_textarea_field( $input['sections_override'] ) : '';
		$lines        = array_filter( array_map( 'trim', explode( "\n", $sections_raw ) ) );
		$clean['sections_override'] = implode( "\n", $lines );

		$clean['privacy_notice']  = isset( $input['privacy_notice'] ) ? sanitize_textarea_field( $input['privacy_notice'] ) : $defaults['privacy_notice'];
		$clean['consent_label']   = isset( $input['consent_label'] ) && '' !== trim( $input['consent_label'] ) ? sanitize_text_field( $input['consent_label'] ) : $defaults['consent_label'];
		$clean['success_message'] = isset( $input['success_message'] ) && '' !== trim( $input['success_message'] ) ? sanitize_text_field( $input['success_message'] ) : $defaults['success_message'];

		delete_transient( SCOUTSUITE_WAITLIST_SECTIONS_TRANSIENT );

		return $clean;
	}

	/**
	 * Render the settings page shell.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scout Suite Waiting List', 'scoutsuite-waitlist' ); ?></h1>
			<p>
				<?php esc_html_e( 'Add the form to any page with the shortcode', 'scoutsuite-waitlist' ); ?>
				<code>[scoutsuite_waitlist]</code>
				<?php esc_html_e( 'or with the Scout Suite Waiting List block in the editor.', 'scoutsuite-waitlist' ); ?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'scoutsuite_waitlist' );
				do_settings_sections( 'scoutsuite-waitlist' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_connection_intro() {
		echo '<p>' . esc_html__( 'Find your Group ID in Scout Suite under your group settings. An API key is optional for this form because the waiting list signup endpoint accepts public submissions, but you can add one from the Scout Suite developer portal if you prefer authenticated requests. The key is stored on the server and is never sent to visitors.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_group_id_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="text" class="regular-text" name="%s[group_id]" value="%s" autocomplete="off" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['group_id'] )
		);
		echo '<p class="description">' . esc_html__( 'The Scout Suite ID of your group. Required.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_api_key_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="password" class="regular-text" name="%s[api_key]" value="%s" autocomplete="new-password" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['api_key'] )
		);
		echo '<p class="description">' . esc_html__( 'Starts with ss_at_. Leave blank to submit publicly.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_sections_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<textarea class="regular-text" rows="5" name="%s[sections_override]">%s</textarea>',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_textarea( $options['sections_override'] )
		);
		echo '<p class="description">' . esc_html__( 'One section per line, for example Beavers, Cubs, Scouts. Leave blank to fetch your active sections from Scout Suite automatically.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_privacy_notice_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<textarea class="large-text" rows="4" name="%s[privacy_notice]">%s</textarea>',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_textarea( $options['privacy_notice'] )
		);
		echo '<p class="description">' . esc_html__( 'Shown above the consent checkbox. Explain what you do with the data.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_consent_label_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="text" class="large-text" name="%s[consent_label]" value="%s" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['consent_label'] )
		);
	}

	public function render_success_message_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="text" class="large-text" name="%s[success_message]" value="%s" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['success_message'] )
		);
	}
}
