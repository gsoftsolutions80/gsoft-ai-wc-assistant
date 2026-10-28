<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/** Pushes the product catalog to the platform: debounced per-product updates plus a paginated full sync. */
class CatalogSync {

	public const STATUS_OPTION = 'gsai_catalog_status';

	private const GROUP        = 'gsoft-ai-assistant';
	private const PAGE_SIZE    = 50;
	private const MAX_ATTEMPTS = 5;

	private ApiClient $api;
	private Settings $settings;

	public function __construct( ApiClient $api, Settings $settings ) {
		$this->api      = $api;
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'woocommerce_new_product', array( $this, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( $this, 'queue_product' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'queue_variation' ) );
		add_action( 'wp_trash_post', array( $this, 'queue_post' ) );
		add_action( 'untrashed_post', array( $this, 'queue_post' ) );
		add_action( 'before_delete_post', array( $this, 'queue_post' ) );

		add_action( 'gsai_sync_product', array( $this, 'sync_product' ), 10, 2 );
		add_action( 'gsai_full_catalog_sync', array( $this, 'run_full_sync' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'ensure_schedules' ) );
	}

	public function ensure_schedules(): void {
		if ( ! $this->settings->is_configured() || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'gsai_full_catalog_sync', array( 1, 1 ), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'gsai_full_catalog_sync', array( 1, 1 ), self::GROUP );
		}
	}

	public function start_full_sync(): bool {
		if ( ! $this->settings->is_configured() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		update_option( self::STATUS_OPTION, array( 'state' => 'running', 'synced' => 0, 'started_at' => time() ), false );
		as_enqueue_async_action( 'gsai_full_catalog_sync', array( 1, 1 ), self::GROUP );
		return true;
	}

	public function queue_variation( $variation_id ): void {
		$parent = wp_get_post_parent_id( (int) $variation_id );
		if ( $parent ) {
			$this->queue_product( $parent );
		}
	}

	public function queue_post( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			$this->queue_product( $post_id );
		}
	}

	/** Debounced: bulk edits and imports collapse into one request per product. */
	public function queue_product( $product_id ): void {
		$product_id = (int) $product_id;
		if ( ! $product_id || ! $this->settings->is_configured() || ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'gsai_sync_product', array( $product_id, 1 ), self::GROUP ) ) {
			as_schedule_single_action( time() + 30, 'gsai_sync_product', array( $product_id, 1 ), self::GROUP );
		}
	}

	public function sync_product( $product_id, $attempt = 1 ): void {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		// Only published products are recommendable; anything else is removed from the platform catalog.
		if ( $product && 'publish' === $product->get_status() && ! $product->is_type( 'variation' ) ) {
			$body = array( 'products' => array( ProductSerializer::serialize( $product ) ) );
		} else {
			$body = array( 'deleted' => array( $product_id ) );
		}

		$response = $this->api->post( 'catalog/sync', $body );
		if ( ! ApiClient::is_success( $response ) && $this->is_retryable( $response ) && (int) $attempt < self::MAX_ATTEMPTS ) {
			as_schedule_single_action( time() + 300 * (int) $attempt, 'gsai_sync_product', array( $product_id, (int) $attempt + 1 ), self::GROUP );
		}
	}

	public function run_full_sync( $page = 1, $attempt = 1 ): void {
		$page    = max( 1, (int) $page );
		$attempt = max( 1, (int) $attempt );

		$result = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => self::PAGE_SIZE,
				'page'     => $page,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'paginate' => true,
			)
		);

		$products = array();
		foreach ( $result->products as $product ) {
			$products[] = ProductSerializer::serialize( $product );
		}

		$status = (array) get_option( self::STATUS_OPTION, array() );
		if ( 1 === $page ) {
			$status = array( 'state' => 'running', 'synced' => 0, 'started_at' => time() );
		}

		if ( $products ) {
			$response = $this->api->post( 'catalog/sync', array( 'products' => $products ) );
			if ( ! ApiClient::is_success( $response ) ) {
				if ( $this->is_retryable( $response ) && $attempt < self::MAX_ATTEMPTS ) {
					as_schedule_single_action( time() + 300 * $attempt, 'gsai_full_catalog_sync', array( $page, $attempt + 1 ), self::GROUP );
				}
				$status['state'] = $attempt < self::MAX_ATTEMPTS ? 'retrying' : 'failed';
				$status['error'] = ApiClient::error_message( $response );
				update_option( self::STATUS_OPTION, $status, false );
				return;
			}
			$status['synced'] = (int) ( $status['synced'] ?? 0 ) + count( $products );
		}

		unset( $status['error'] );
		if ( $page < (int) $result->max_num_pages ) {
			$status['state'] = 'running';
			as_schedule_single_action( time() + 5, 'gsai_full_catalog_sync', array( $page + 1, 1 ), self::GROUP );
		} else {
			$status['state']       = 'done';
			$status['finished_at'] = time();
		}
		update_option( self::STATUS_OPTION, $status, false );
	}

	/** @param array|\WP_Error $response */
	private function is_retryable( $response ): bool {
		return is_wp_error( $response ) || in_array( $response['code'], array( 401, 408, 429 ), true ) || $response['code'] >= 500;
	}
}
