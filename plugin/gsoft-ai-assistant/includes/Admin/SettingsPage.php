<?php
namespace GSoft\AIAssistant\Admin;

use GSoft\AIAssistant\ApiClient;
use GSoft\AIAssistant\CatalogSync;
use GSoft\AIAssistant\OrderBackfill;
use GSoft\AIAssistant\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	private const SLUG = 'gsai-settings';

	private Settings $settings;
	private ApiClient $api;
	private CatalogSync $catalog;
	private OrderBackfill $backfill;

	public function __construct( Settings $settings, ApiClient $api, CatalogSync $catalog, OrderBackfill $backfill ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->catalog  = $catalog;
		$this->backfill = $backfill;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gsai_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_gsai_sync_catalog', array( $this, 'handle_sync_catalog' ) );
		add_action( 'admin_post_gsai_backfill_orders', array( $this, 'handle_backfill' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'AI Assistant', 'gsoft-ai-assistant' ),
			__( 'AI Assistant', 'gsoft-ai-assistant' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'gsai_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public function handle_test_connection(): void {
		$this->authorize( 'gsai_test_connection' );

		$response = $this->api->get( 'ping' );
		if ( ApiClient::is_success( $response ) ) {
			$name = isset( $response['body']['site'] ) ? (string) $response['body']['site'] : '';
			$this->finish( 'success', sprintf( /* translators: %s: site name registered on the platform */ __( 'Connected successfully to site "%s".', 'gsoft-ai-assistant' ), $name ) );
		}
		$this->finish( 'error', sprintf( /* translators: %s: error detail */ __( 'Connection failed: %s', 'gsoft-ai-assistant' ), ApiClient::error_message( $response ) ) );
	}

	public function handle_sync_catalog(): void {
		$this->authorize( 'gsai_sync_catalog' );

		if ( $this->catalog->start_full_sync() ) {
			$this->finish( 'success', __( 'Catalog sync started. It runs in the background.', 'gsoft-ai-assistant' ) );
		}
		$this->finish( 'error', __( 'Save a valid configuration first.', 'gsoft-ai-assistant' ) );
	}

	public function handle_backfill(): void {
		$this->authorize( 'gsai_backfill_orders' );

		if ( $this->backfill->start() ) {
			$this->finish( 'success', __( 'Order import started. It runs in the background.', 'gsoft-ai-assistant' ) );
		}
		$this->finish( 'error', __( 'Save a valid configuration and confirm the legal basis for the order import first.', 'gsoft-ai-assistant' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$values = $this->settings->all();
		$notice = get_transient( $this->notice_key() );
		delete_transient( $this->notice_key() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Assistant', 'gsoft-ai-assistant' ); ?></h1>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php settings_errors( Settings::OPTION ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'gsai_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gsai_api_base_url"><?php esc_html_e( 'Platform URL', 'gsoft-ai-assistant' ); ?></label></th>
						<td><input type="url" id="gsai_api_base_url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[api_base_url]" value="<?php echo esc_attr( $values['api_base_url'] ); ?>" placeholder="https://app.example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsai_site_key"><?php esc_html_e( 'Site key', 'gsoft-ai-assistant' ); ?></label></th>
						<td><input type="text" id="gsai_site_key" class="regular-text code" name="<?php echo esc_attr( Settings::OPTION ); ?>[site_key]" value="<?php echo esc_attr( $values['site_key'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="gsai_site_secret"><?php esc_html_e( 'Site secret', 'gsoft-ai-assistant' ); ?></label></th>
						<td>
							<input type="password" id="gsai_site_secret" class="regular-text code" name="<?php echo esc_attr( Settings::OPTION ); ?>[site_secret]" value="" autocomplete="new-password" placeholder="<?php echo '' !== $values['site_secret'] ? esc_attr__( '(saved - leave blank to keep)', 'gsoft-ai-assistant' ) : ''; ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'gsoft-ai-assistant' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[tracking_enabled]" value="1" <?php checked( 1, (int) $values['tracking_enabled'] ); ?> /> <?php esc_html_e( 'Track shopper behaviour', 'gsoft-ai-assistant' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gsai_consent_mode"><?php esc_html_e( 'Consent', 'gsoft-ai-assistant' ); ?></label></th>
						<td>
							<select id="gsai_consent_mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[consent_mode]">
								<option value="wp_consent_api" <?php selected( $values['consent_mode'], 'wp_consent_api' ); ?>><?php esc_html_e( 'WP Consent API (recommended)', 'gsoft-ai-assistant' ); ?></option>
								<option value="always" <?php selected( $values['consent_mode'], 'always' ); ?>><?php esc_html_e( 'Always allowed (consent handled elsewhere)', 'gsoft-ai-assistant' ); ?></option>
							</select>
							<p class="description">
								<?php
								if ( function_exists( 'wp_has_consent' ) ) {
									esc_html_e( 'A WP Consent API compatible consent plugin was detected. Tracking starts only after the visitor accepts the "marketing" category.', 'gsoft-ai-assistant' );
								} else {
									esc_html_e( 'No WP Consent API plugin detected: in this mode nothing is tracked. Install a consent plugin (e.g. Complianz, CookieYes) or choose "Always allowed" only if you collect consent by other means.', 'gsoft-ai-assistant' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Order import', 'gsoft-ai-assistant' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[backfill_confirmed]" value="1" <?php checked( 1, (int) $values['backfill_confirmed'] ); ?> /> <?php esc_html_e( 'I confirm this shop has a legal basis to process past customers\' orders for personalised recommendations.', 'gsoft-ai-assistant' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Connection and data', 'gsoft-ai-assistant' ); ?></h2>
			<p>
				<?php $this->action_button( 'gsai_test_connection', __( 'Test connection', 'gsoft-ai-assistant' ) ); ?>
				<?php $this->action_button( 'gsai_sync_catalog', __( 'Sync catalog now', 'gsoft-ai-assistant' ) ); ?>
				<?php $this->action_button( 'gsai_backfill_orders', __( 'Import past orders', 'gsoft-ai-assistant' ) ); ?>
			</p>
			<?php $this->render_status( __( 'Catalog sync', 'gsoft-ai-assistant' ), CatalogSync::STATUS_OPTION, 'synced' ); ?>
			<?php $this->render_status( __( 'Order import', 'gsoft-ai-assistant' ), OrderBackfill::STATUS_OPTION, 'sent' ); ?>
		</div>
		<?php
	}

	private function render_status( string $label, string $option, string $counter ): void {
		$status = get_option( $option );
		if ( ! is_array( $status ) || empty( $status['state'] ) ) {
			return;
		}

		$line = sprintf(
			/* translators: 1: task label, 2: state, 3: processed items */
			__( '%1$s: %2$s (%3$d items)', 'gsoft-ai-assistant' ),
			$label,
			$status['state'],
			(int) ( $status[ $counter ] ?? 0 )
		);
		if ( ! empty( $status['error'] ) ) {
			$line .= ' - ' . $status['error'];
		}
		echo '<p>' . esc_html( $line ) . '</p>';
	}

	private function action_button( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gsoft-ai-assistant' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function finish( string $type, string $message ): void {
		set_transient( $this->notice_key(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private function notice_key(): string {
		return 'gsai_notice_' . get_current_user_id();
	}
}
