<?php
/**
 * Removes plugin data on uninstall. Order meta (_gsai_*) is left in place; data stored on the platform
 * is removed through the platform's erasure tooling.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'gsai_settings', 'gsai_db_version', 'gsai_catalog_status', 'gsai_backfill_status' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gsai_outbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'gsai_cross_site_consent' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'gsai_flush_outbox', 'gsai_flush_outbox_cron', 'gsai_sync_product', 'gsai_full_catalog_sync', 'gsai_backfill_orders' ) as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}
