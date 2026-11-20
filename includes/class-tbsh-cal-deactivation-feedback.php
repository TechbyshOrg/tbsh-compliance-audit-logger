<?php
/**
 * Optional deactivation feedback survey.
 *
 * When an admin clicks "Deactivate" for this plugin on the Plugins screen, a modal asks
 * why. Nothing is sent unless the admin clicks "Submit & Deactivate". The site URL and
 * admin email are only included when the admin ticks the opt-in checkbox.
 *
 * The answer is posted (form-encoded) from the server to a Google Apps Script web app,
 * which appends it to a Google Sheet. Until a valid https endpoint is configured the
 * feature sends nothing and deactivation works normally.
 *
 * Configuration (any of):
 * - Edit ENDPOINT_URL / SECRET_TOKEN below.
 * - Define TBSH_CAL_FEEDBACK_URL / TBSH_CAL_FEEDBACK_TOKEN (e.g. in wp-config.php).
 * - Filter tbsh_cal_deactivation_feedback_url / tbsh_cal_deactivation_feedback_token.
 *
 * Note: this plugin's code is public, so SECRET_TOKEN only deters casual abuse of the
 * endpoint. Treat incoming rows as untrusted and rotate the token if needed.
 *
 * @package TBSHComplianceAuditLogger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TBSH_CAL_Deactivation_Feedback {

	/**
	 * Google Apps Script web app URL (ends with /exec).
	 *
	 * @var string
	 */
	const ENDPOINT_URL = 'https://script.google.com/macros/s/AKfycbxH4AIQgQpxVqcGsIutXYaWL5Nx-Wg5AVsaLbfo2_I5MhWrjUzrK9V0Rjam5M077X7b/exec';

	/**
	 * Shared secret checked by the Apps Script.
	 *
	 * @var string
	 */
	const SECRET_TOKEN = 'RObugXJsyrGIlwX4UEpXS591iPqcrkNftrZZBoWn';

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'tbsh_cal_deactivation_feedback';

	/**
	 * Maximum length of the free-text details field.
	 *
	 * @var int
	 */
	const DETAILS_MAX_LENGTH = 1000;

	/**
	 * Milliseconds the browser waits for the AJAX request before deactivating anyway.
	 *
	 * @var int
	 */
	const CLIENT_TIMEOUT_MS = 3000;

	/**
	 * Register hooks. Call once, in the admin only.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Allowed reasons and their labels.
	 *
	 * Each detail key is 'none', 'input' (single line) or 'textarea'.
	 *
	 * @return array<string, array{label: string, detail: string, placeholder: string}>
	 */
	private static function get_reasons() {
		return array(
			'no_longer_needed' => array(
				'label'       => __( 'I no longer need the plugin', 'tbsh-compliance-audit-logger' ),
				'detail'      => 'none',
				'placeholder' => '',
			),
			'better_plugin'    => array(
				'label'       => __( 'I found a better plugin', 'tbsh-compliance-audit-logger' ),
				'detail'      => 'input',
				'placeholder' => __( 'Which plugin? (optional)', 'tbsh-compliance-audit-logger' ),
			),
			'not_working'      => array(
				'label'       => __( "The plugin didn't work as expected", 'tbsh-compliance-audit-logger' ),
				'detail'      => 'textarea',
				'placeholder' => __( 'What went wrong? (optional)', 'tbsh-compliance-audit-logger' ),
			),
			'broke_site'       => array(
				'label'       => __( 'It broke my site or conflicts with another plugin', 'tbsh-compliance-audit-logger' ),
				'detail'      => 'textarea',
				'placeholder' => __( 'Which plugin or theme conflicts, or what broke? (optional)', 'tbsh-compliance-audit-logger' ),
			),
			'missing_feature'  => array(
				'label'       => __( "It's missing a feature I need", 'tbsh-compliance-audit-logger' ),
				'detail'      => 'textarea',
				'placeholder' => __( 'Which feature do you need? (optional)', 'tbsh-compliance-audit-logger' ),
			),
			'too_complicated'  => array(
				'label'       => __( "It's too complicated to use", 'tbsh-compliance-audit-logger' ),
				'detail'      => 'textarea',
				'placeholder' => __( 'What was confusing? (optional)', 'tbsh-compliance-audit-logger' ),
			),
			'temporary'        => array(
				'label'       => __( "It's a temporary deactivation, I'm just debugging", 'tbsh-compliance-audit-logger' ),
				'detail'      => 'none',
				'placeholder' => '',
			),
			'other'            => array(
				'label'       => __( 'Other', 'tbsh-compliance-audit-logger' ),
				'detail'      => 'textarea',
				'placeholder' => __( 'Please tell us more (optional)', 'tbsh-compliance-audit-logger' ),
			),
		);
	}

	/**
	 * Plugin basename (folder/main-file.php), computed from the main file.
	 *
	 * @return string
	 */
	private static function get_basename() {
		return plugin_basename( TBSH_CAL_FILE );
	}

	/**
	 * Plugin slug (folder name).
	 *
	 * @return string
	 */
	private static function get_slug() {
		return dirname( self::get_basename() );
	}

	/**
	 * Plugin version, from the existing constant or the plugin header.
	 *
	 * @return string
	 */
	private static function get_version() {
		static $version = null;
		if ( null === $version ) {
			if ( defined( 'TBSH_CAL_VERSION' ) ) {
				$version = TBSH_CAL_VERSION;
			} else {
				$data    = get_file_data( TBSH_CAL_FILE, array( 'Version' => 'Version' ) );
				$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			}
		}
		return $version;
	}

	/**
	 * Translated plugin name from the plugin header.
	 *
	 * @return string
	 */
	private static function get_plugin_name() {
		static $name = null;
		if ( null === $name ) {
			$data = get_file_data( TBSH_CAL_FILE, array( 'Name' => 'Plugin Name' ) );
			$name = ! empty( $data['Name'] )
				? translate( $data['Name'], 'tbsh-compliance-audit-logger' ) // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction,WordPress.WP.I18n.NonSingularStringLiteralText
				: self::get_slug();
		}
		return $name;
	}

	/**
	 * Endpoint URL: constant override, then class constant, then filter.
	 *
	 * @return string
	 */
	private static function get_endpoint_url() {
		$url = defined( 'TBSH_CAL_FEEDBACK_URL' ) ? (string) TBSH_CAL_FEEDBACK_URL : self::ENDPOINT_URL;

		/**
		 * Filter the deactivation feedback endpoint URL.
		 *
		 * @param string $url Google Apps Script web app URL.
		 */
		return (string) apply_filters( 'tbsh_cal_deactivation_feedback_url', $url );
	}

	/**
	 * Shared secret token: constant override, then class constant, then filter.
	 *
	 * @return string
	 */
	private static function get_token() {
		$token = defined( 'TBSH_CAL_FEEDBACK_TOKEN' ) ? (string) TBSH_CAL_FEEDBACK_TOKEN : self::SECRET_TOKEN;

		/**
		 * Filter the deactivation feedback secret token.
		 *
		 * @param string $token Shared secret.
		 */
		return (string) apply_filters( 'tbsh_cal_deactivation_feedback_token', $token );
	}

	/**
	 * Whether a usable endpoint is configured (https and not the placeholder).
	 *
	 * @param string $url Endpoint URL.
	 * @return bool
	 */
	private static function is_valid_endpoint( $url ) {
		if ( '' === $url || false !== strpos( $url, 'PASTE_YOUR' ) ) {
			return false;
		}
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && false !== wp_http_validate_url( $url );
	}

	/**
	 * Whether the current screen is the (network) Plugins screen.
	 *
	 * The hook suffix is "plugins.php" on both the site and network admin Plugins screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return bool
	 */
	private static function is_plugins_screen( $hook_suffix ) {
		return 'plugins.php' === $hook_suffix && current_user_can( 'activate_plugins' );
	}

	/**
	 * Enqueue the modal script and styles on the Plugins screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::is_plugins_screen( $hook_suffix ) ) {
			return;
		}

		$version = self::get_version();

		wp_enqueue_style(
			'tbsh-cal-deactivation-feedback',
			TBSH_CAL_URL . 'assets/css/deactivation-feedback.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'tbsh-cal-deactivation-feedback',
			TBSH_CAL_URL . 'assets/js/deactivation-feedback.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'tbsh-cal-deactivation-feedback',
			'tbshCalDeactivationFeedback',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
				'basename'  => self::get_basename(),
				'timeout'   => self::CLIENT_TIMEOUT_MS,
				'maxLength' => self::DETAILS_MAX_LENGTH,
				'i18n'      => array(
					'deactivating' => __( 'Deactivating...', 'tbsh-compliance-audit-logger' ),
					'submit'       => __( 'Submit & Deactivate', 'tbsh-compliance-audit-logger' ),
				),
			)
		);
	}

	/**
	 * Print the (hidden) modal markup in the Plugins screen footer.
	 */
	public static function render_modal() {
		global $hook_suffix;
		if ( ! self::is_plugins_screen( (string) $hook_suffix ) ) {
			return;
		}

		$title = sprintf(
			/* translators: %s: plugin name */
			__( 'Quick feedback - why are you deactivating %s?', 'tbsh-compliance-audit-logger' ),
			self::get_plugin_name()
		);
		?>
		<div id="tbsh-cal-df-modal" class="tbsh-cal-df-modal" hidden>
			<div class="tbsh-cal-df-backdrop" data-tbsh-cal-df-close="1"></div>
			<div class="tbsh-cal-df-dialog" role="dialog" aria-modal="true" aria-labelledby="tbsh-cal-df-title" aria-describedby="tbsh-cal-df-subtitle">
				<div class="tbsh-cal-df-header">
					<span class="tbsh-cal-df-icon dashicons dashicons-feedback" aria-hidden="true"></span>
					<div class="tbsh-cal-df-heading">
						<h2 id="tbsh-cal-df-title" class="tbsh-cal-df-title"><?php echo esc_html( $title ); ?></h2>
						<p id="tbsh-cal-df-subtitle" class="tbsh-cal-df-subtitle"><?php esc_html_e( 'Your answer helps us improve. It takes 5 seconds.', 'tbsh-compliance-audit-logger' ); ?></p>
					</div>
					<button type="button" class="tbsh-cal-df-close" data-tbsh-cal-df-close="1" aria-label="<?php esc_attr_e( 'Close and keep the plugin active', 'tbsh-compliance-audit-logger' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>

				<form id="tbsh-cal-df-form" class="tbsh-cal-df-form" novalidate>
					<div class="tbsh-cal-df-body">
						<fieldset class="tbsh-cal-df-reasons">
							<legend class="screen-reader-text"><?php esc_html_e( 'Reason for deactivating', 'tbsh-compliance-audit-logger' ); ?></legend>
							<?php foreach ( self::get_reasons() as $value => $reason ) : ?>
								<?php $input_id = 'tbsh-cal-df-reason-' . $value; ?>
								<div class="tbsh-cal-df-reason">
									<label for="<?php echo esc_attr( $input_id ); ?>" class="tbsh-cal-df-reason-label">
										<input type="radio" id="<?php echo esc_attr( $input_id ); ?>" name="tbsh_cal_df_reason" value="<?php echo esc_attr( $value ); ?>" />
										<span><?php echo esc_html( $reason['label'] ); ?></span>
									</label>
									<?php if ( 'none' !== $reason['detail'] ) : ?>
										<?php $detail_id = 'tbsh-cal-df-detail-' . $value; ?>
										<div class="tbsh-cal-df-detail" data-tbsh-cal-df-detail="<?php echo esc_attr( $value ); ?>" hidden>
											<label for="<?php echo esc_attr( $detail_id ); ?>" class="tbsh-cal-df-detail-label"><?php echo esc_html( $reason['placeholder'] ); ?></label>
											<?php if ( 'input' === $reason['detail'] ) : ?>
												<input type="text" id="<?php echo esc_attr( $detail_id ); ?>" class="tbsh-cal-df-field" maxlength="<?php echo esc_attr( self::DETAILS_MAX_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Plugin name', 'tbsh-compliance-audit-logger' ); ?>" />
											<?php else : ?>
												<textarea id="<?php echo esc_attr( $detail_id ); ?>" class="tbsh-cal-df-field" rows="3" maxlength="<?php echo esc_attr( self::DETAILS_MAX_LENGTH ); ?>" placeholder="<?php esc_attr_e( 'Type your answer here…', 'tbsh-compliance-audit-logger' ); ?>"></textarea>
											<?php endif; ?>
											<?php $max_label = number_format_i18n( self::DETAILS_MAX_LENGTH ); ?>
											<span class="tbsh-cal-df-count" data-max="<?php echo esc_attr( $max_label ); ?>" aria-hidden="true">0 / <?php echo esc_html( $max_label ); ?></span>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</fieldset>

						<div class="tbsh-cal-df-consent">
							<label for="tbsh-cal-df-consent">
								<input type="checkbox" id="tbsh-cal-df-consent" name="tbsh_cal_df_consent" value="1" />
								<span><?php esc_html_e( 'Share my site URL and admin email so you can contact me about this issue.', 'tbsh-compliance-audit-logger' ); ?></span>
							</label>
						</div>
					</div>

					<div class="tbsh-cal-df-footer">
						<button type="button" class="button tbsh-cal-df-skip"><?php esc_html_e( 'Skip & Deactivate', 'tbsh-compliance-audit-logger' ); ?></button>
						<span class="tbsh-cal-df-status" aria-live="polite">
							<span class="spinner tbsh-cal-df-spinner"></span>
							<span class="tbsh-cal-df-status-text"></span>
						</span>
						<button type="submit" class="button button-primary tbsh-cal-df-submit" disabled><?php esc_html_e( 'Submit & Deactivate', 'tbsh-compliance-audit-logger' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: validate the answer and forward it to the endpoint.
	 *
	 * Always responds with success so the browser proceeds to deactivate,
	 * whatever happens with the remote request. Nothing is stored locally.
	 */
	public static function handle_ajax() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}

		$url = self::get_endpoint_url();
		if ( ! self::is_valid_endpoint( $url ) ) {
			// Not configured yet: send nothing, let deactivation continue.
			wp_send_json_success();
		}

		$reasons = self::get_reasons();
		$reason  = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		if ( ! isset( $reasons[ $reason ] ) ) {
			$reason = 'other';
		}

		$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
		$details = trim( function_exists( 'mb_substr' ) ? mb_substr( $details, 0, self::DETAILS_MAX_LENGTH ) : substr( $details, 0, self::DETAILS_MAX_LENGTH ) );

		$consent = isset( $_POST['consent'] ) && in_array( wp_unslash( $_POST['consent'] ), array( '1', 'true', 'yes' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list.

		$theme = wp_get_theme();

		$payload = array(
			'token'          => self::get_token(),
			'plugin'         => self::get_slug(),
			'plugin_version' => self::get_version(),
			'reason'         => $reason,
			'details'        => $details,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'locale'         => get_locale(),
			'multisite'      => is_multisite() ? 'yes' : 'no',
			'active_theme'   => $theme ? (string) $theme->get( 'Name' ) : '',
			'site_url'       => $consent ? home_url() : '',
			'admin_email'    => $consent ? (string) get_option( 'admin_email' ) : '',
		);

		/**
		 * Filter the deactivation feedback payload before it is sent.
		 *
		 * @param array $payload Form fields posted to the endpoint.
		 */
		$payload = apply_filters( 'tbsh_cal_deactivation_feedback_payload', $payload );

		// The browser may move on to the deactivation URL before this finishes; keep going.
		ignore_user_abort( true );

		wp_remote_post(
			$url,
			array(
				'body'        => is_array( $payload ) ? $payload : array(),
				'timeout'     => 5,
				'blocking'    => false,
				'redirection' => 0,
			)
		);

		wp_send_json_success();
	}
}
