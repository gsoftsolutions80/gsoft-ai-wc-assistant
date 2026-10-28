<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'GSoft AI Assistant requires WooCommerce to be active.', 'gsoft-ai-assistant' ) . '</p></div>';
				}
			);
			return;
		}

		$settings = new Settings();
		$api      = new ApiClient( $settings );
		$consent  = new Consent( $settings );
		$visitor  = new Visitor( $consent );
		$outbox   = new Outbox( $api, $settings );
		$identity = new Identity( $settings, $consent, $visitor, $outbox );
		$tracker  = new Tracker( $settings, $consent, $visitor, $outbox, $identity );
		$catalog  = new CatalogSync( $api, $settings );
		$backfill = new OrderBackfill( $api, $settings );

		foreach ( array( $settings, $consent, $visitor, $outbox, $identity, $tracker, $catalog, $backfill ) as $component ) {
			$component->register();
		}

		if ( is_admin() ) {
			( new Admin\SettingsPage( $settings, $api, $catalog, $backfill ) )->register();
		}

		if ( get_option( 'gsai_db_version' ) !== Outbox::DB_VERSION ) {
			Outbox::install();
		}
	}
}
