<?php
/**
 * Settings page: Settings > Scout Suite.
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
			__( 'Scout Suite', 'scoutsuite-waitlist' ),
			__( 'Scout Suite', 'scoutsuite-waitlist' ),
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
			'org_id',
			__( 'Org ID', 'scoutsuite-waitlist' ),
			array( $this, 'render_org_id_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_field(
			'waitlist_group_id',
			__( 'Waiting list Group ID', 'scoutsuite-waitlist' ),
			array( $this, 'render_waitlist_group_id_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_field(
			'api_key',
			__( 'API key', 'scoutsuite-waitlist' ),
			array( $this, 'render_api_key_field' ),
			'scoutsuite-waitlist',
			'scoutsuite_waitlist_connection'
		);

		add_settings_field(
			'api_base_url',
			__( 'API base URL', 'scoutsuite-waitlist' ),
			array( $this, 'render_api_base_url_field' ),
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
	 * Org ID takes effect straight away.
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

		$org_id = '';
		if ( isset( $input['org_id'] ) ) {
			$org_id = sanitize_text_field( $input['org_id'] );
		} elseif ( isset( $input['group_id'] ) ) {
			$org_id = sanitize_text_field( $input['group_id'] );
		}

		$clean['org_id']   = $org_id;
		$clean['group_id'] = $org_id;
		$clean['waitlist_group_id'] = isset( $input['waitlist_group_id'] )
			? sanitize_text_field( $input['waitlist_group_id'] )
			: '';
		$clean['api_key']  = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';

		$base = isset( $input['api_base_url'] ) ? esc_url_raw( trim( (string) $input['api_base_url'] ) ) : '';
		$clean['api_base_url'] = ScoutSuite_Waitlist_API::normalise_base_url( $base );

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
			<h1><?php esc_html_e( 'Scout Suite', 'scoutsuite-waitlist' ); ?></h1>
			<p>
				<?php esc_html_e( 'On a Group site, add the waiting list form with', 'scoutsuite-waitlist' ); ?>
				<code>[scoutsuite_waitlist]</code>
				<?php esc_html_e( 'or the Scout Suite Waiting List block. On a District or County site, use Sync now and keep Skills for Life for the public Group list — the shortcode is a single-Group form, not a Group picker.', 'scoutsuite-waitlist' ); ?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'scoutsuite_waitlist' );
				do_settings_sections( 'scoutsuite-waitlist' );
				submit_button();
				?>
			</form>
			<?php $this->render_sync_panel(); ?>
		</div>
		<?php
	}

	public function render_connection_intro() {
		echo '<p>' . esc_html__( 'Use the same Scout Suite id leaders see in URLs: a District, a County, or a single Group. Directory sync writes Groups into WP Store Locator posts that Skills for Life already displays. The waiting list form posts to that org and is meant for a Group id. An API key is required for sync and optional for the public signup form. The key is stored on the server and is never sent to visitors.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_org_id_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="text" class="regular-text" name="%s[org_id]" value="%s" autocomplete="off" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['org_id'] )
		);
		echo '<p class="description">' . esc_html__( 'District, County, or Group ID from Scout Suite. Directory and events sync use this id. Required.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_waitlist_group_id_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="text" class="regular-text" name="%s[waitlist_group_id]" value="%s" autocomplete="off" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( (string) ( $options['waitlist_group_id'] ?? '' ) )
		);
		echo '<p class="description">' . esc_html__( 'Group ID for the [scoutsuite_waitlist] form. Needed on a District site if the form should post to one Group. Leave blank to use the Org ID (a Group site).', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_api_key_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="password" class="regular-text" name="%s[api_key]" value="%s" autocomplete="new-password" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['api_key'] )
		);
		echo '<p class="description">' . esc_html__( 'Bearer token from the Scout Suite developer portal. Starts with ss_at_. Required to sync directory and events. Optional for the public waiting list form.', 'scoutsuite-waitlist' ) . '</p>';
	}

	public function render_api_base_url_field() {
		$options = scoutsuite_waitlist_get_options();
		printf(
			'<input type="url" class="regular-text" name="%s[api_base_url]" value="%s" placeholder="%s" />',
			esc_attr( SCOUTSUITE_WAITLIST_OPTION ),
			esc_attr( $options['api_base_url'] ),
			esc_attr( ScoutSuite_Waitlist_API::DEFAULT_BASE_URL )
		);
		echo '<p class="description">' . esc_html__( 'Default is https://api.scoutsuite.app. Override this for staging.', 'scoutsuite-waitlist' ) . '</p>';
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

	/**
	 * Sync now lives outside options.php so it can run without saving settings.
	 */
	private function render_sync_panel() {
		$state   = ScoutSuite_Waitlist_Sync::get_state();
		$next    = wp_next_scheduled( ScoutSuite_Waitlist_Sync::CRON_HOOK );
		$wpsl_ok = ScoutSuite_Waitlist_Stores::is_available();
		$tec_ok  = ScoutSuite_Waitlist_Events::is_available();
		?>
		<div class="card" style="max-width: 50rem; padding: 1rem 1.25rem; margin-top: 1rem;">
			<h2><?php esc_html_e( 'Sync now', 'scoutsuite-waitlist' ); ?></h2>
			<p><?php esc_html_e( 'Sync pulls the plugin-only directory and public events APIs for this org and writes into WordPress. WP Store Locator and Skills for Life keep the public Group map and list. The Events Calendar keeps the public events UI when it is active. Hourly WP-Cron runs the same job. Stores that disappear from Scout Suite are marked, not deleted. Editor body and featured images are left alone after first create.', 'scoutsuite-waitlist' ); ?></p>
			<p>
				<?php if ( $wpsl_ok ) : ?>
					<?php esc_html_e( 'WP Store Locator is active. Groups will be upserted as Scout Group (wpsl_stores) posts.', 'scoutsuite-waitlist' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'WP Store Locator is not active. Install and activate it before syncing Groups.', 'scoutsuite-waitlist' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<?php if ( $tec_ok ) : ?>
					<?php esc_html_e( 'The Events Calendar is active. Public events will be upserted as tribe_events.', 'scoutsuite-waitlist' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'The Events Calendar is not active. Events will be skipped and an admin notice will be shown on sync.', 'scoutsuite-waitlist' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $state['finished_at'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: datetime, 2: status */
						esc_html__( 'Last sync: %1$s (%2$s).', 'scoutsuite-waitlist' ),
						esc_html( wp_date( 'j F Y H:i', (int) $state['finished_at'] ) ),
						esc_html( isset( $state['status'] ) ? $state['status'] : '' )
					);
					?>
					<?php if ( ! empty( $state['message'] ) ) : ?>
						<br /><?php echo esc_html( $state['message'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( $next ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: datetime */
						esc_html__( 'Next hourly sync: %s.', 'scoutsuite-waitlist' ),
						esc_html( wp_date( 'j F Y H:i', (int) $next ) )
					);
					?>
				</p>
			<?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="scoutsuite_waitlist_sync_now" />
				<?php wp_nonce_field( 'scoutsuite_waitlist_sync_now' ); ?>
				<?php submit_button( __( 'Sync now', 'scoutsuite-waitlist' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
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
