<?php
/**
 * Plugin Name:       GSoft AI Assistant for WooCommerce
 * Description:       AI-powered sales assistant: learns each shopper's tastes across devices and suggests what to buy.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            GSoft
 * License:           GPL-2.0-or-later
 * Text Domain:       gsoft-ai-assistant
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GSAI_VERSION', '0.1.0' );
define( 'GSAI_FILE', __FILE__ );
define( 'GSAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSAI_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'GSoft\\AIAssistant\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = GSAI_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GSAI_FILE, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\GSoft\AIAssistant\Outbox::install();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'gsai_flush_outbox', 'gsai_flush_outbox_cron', 'gsai_sync_product', 'gsai_full_catalog_sync', 'gsai_backfill_orders' ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\GSoft\AIAssistant\Plugin::instance()->boot();
	}
);
