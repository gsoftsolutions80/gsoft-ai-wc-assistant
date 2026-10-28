<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Imports historical orders as identity-only order_placed events (no cookie involved) so profiles start warm.
 * Gated by an explicit admin confirmation that the shop has a legal basis to process past orders.
 * Event ids match the live tracker ("order-{id}-placed"), so re-runs and overlaps are idempotent.
 */
class OrderBackfill {

	public const STATUS_OPTION = 'gsai_backfill_status';

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
		add_action( 'gsai_backfill_orders', array( $this, 'run' ), 10, 2 );
	}

	public function start(): bool {
		if ( ! $this->settings->is_configured() || ! $this->settings->get( 'backfill_confirmed' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		update_option( self::STATUS_OPTION, array( 'state' => 'running', 'sent' => 0, 'started_at' => time() ), false );
		as_enqueue_async_action( 'gsai_backfill_orders', array( 1, 1 ), self::GROUP );
		return true;
	}

	public function run( $page = 1, $attempt = 1 ): void {
		if ( ! $this->settings->get( 'backfill_confirmed' ) ) {
			return;
		}

		$page    = max( 1, (int) $page );
		$attempt = max( 1, (int) $attempt );
		$status  = (array) get_option( self::STATUS_OPTION, array() );
		if ( 1 === $page && 1 === $attempt ) {
			$status = array( 'state' => 'running', 'sent' => 0, 'started_at' => time() );
		}

		$result = wc_get_orders(
			array(
				'status'   => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'limit'    => self::PAGE_SIZE,
				'paged'    => $page,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'paginate' => true,
			)
		);

		$events = array();
		foreach ( $result->orders as $order ) {
			if ( ! $order instanceof \WC_Order || '0' === $order->get_meta( '_gsai_tracking_consent' ) ) {
				continue;
			}
			$identity = OrderSerializer::identity( $order );
			$created  = $order->get_date_created();
			if ( ! $identity || ! $created ) {
				continue;
			}
			$events[] = array(
				'event_id'    => 'order-' . $order->get_id() . '-placed',
				'type'        => 'order_placed',
				'occurred_at' => $created->getTimestamp(),
				'identity'    => $identity,
				'payload'     => OrderSerializer::payload( $order ),
			);
		}

		if ( $events ) {
			$response = $this->api->post( 'events', array( 'events' => $events ) );
			if ( ! ApiClient::is_success( $response ) ) {
				$retryable = is_wp_error( $response ) || in_array( $response['code'], array( 401, 408, 429 ), true ) || $response['code'] >= 500;
				if ( $retryable && $attempt < self::MAX_ATTEMPTS ) {
					as_schedule_single_action( time() + 300 * $attempt, 'gsai_backfill_orders', array( $page, $attempt + 1 ), self::GROUP );
				}
				$status['state'] = $retryable && $attempt < self::MAX_ATTEMPTS ? 'retrying' : 'failed';
				$status['error'] = ApiClient::error_message( $response );
				update_option( self::STATUS_OPTION, $status, false );
				return;
			}
			$status['sent'] = (int) ( $status['sent'] ?? 0 ) + count( $events );
		}

		unset( $status['error'] );
		if ( $page < (int) $result->max_num_pages ) {
			$status['state'] = 'running';
			as_schedule_single_action( time() + 5, 'gsai_backfill_orders', array( $page + 1, 1 ), self::GROUP );
		} else {
			$status['state']       = 'done';
			$status['finished_at'] = time();
		}
		update_option( self::STATUS_OPTION, $status, false );
	}
}
