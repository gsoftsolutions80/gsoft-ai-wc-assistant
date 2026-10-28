<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

/** Server-side behaviour tracking (WooCommerce hooks), queued in the Outbox. Nothing runs without consent. */
class Tracker {

	/** Event types other code may emit through the `gsai_track_event` action. */
	private const CUSTOM_TYPES = array( 'product_view', 'category_view', 'search', 'wishlist_add', 'widget_impression', 'widget_click' );

	private static bool $checkout_tracked = false;

	private Settings $settings;
	private Consent $consent;
	private Visitor $visitor;
	private Outbox $outbox;
	private Identity $identity;

	public function __construct( Settings $settings, Consent $consent, Visitor $visitor, Outbox $outbox, Identity $identity ) {
		$this->settings = $settings;
		$this->consent  = $consent;
		$this->visitor  = $visitor;
		$this->outbox   = $outbox;
		$this->identity = $identity;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'track_page_view' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'on_checkout_start' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_processed' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'gsai_track_event', array( $this, 'track_custom' ), 10, 2 );
	}

	public function track_page_view(): void {
		if ( ! $this->is_plain_page_request() ) {
			return;
		}

		if ( is_singular( 'product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$this->track( 'product_view', $this->product_payload( $product ) );
			}
			return;
		}

		if ( is_tax( 'product_cat' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$this->track(
					'category_view',
					array(
						'term_id' => $term->term_id,
						'name'    => $term->name,
						'slug'    => $term->slug,
					)
				);
			}
			return;
		}

		if ( is_search() && 'product' === get_query_var( 'post_type' ) ) {
			$query = trim( (string) get_search_query( false ) );
			if ( '' !== $query ) {
				global $wp_query;
				$this->track(
					'search',
					array(
						'query'   => mb_substr( $query, 0, 200 ),
						'results' => (int) $wp_query->found_posts,
					)
				);
			}
		}
	}

	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ): void {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( $product ) {
			$this->track( 'add_to_cart', $this->product_payload( $product ) + array( 'quantity' => (int) $quantity ) );
		}
	}

	public function on_cart_item_removed( $cart_item_key, $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		if ( ! $item ) {
			return;
		}
		$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
		if ( $product ) {
			$this->track( 'remove_from_cart', $this->product_payload( $product ) + array( 'quantity' => (int) ( $item['quantity'] ?? 1 ) ) );
		}
	}

	public function on_checkout_start(): void {
		if ( self::$checkout_tracked || ! WC()->cart ) {
			return;
		}
		self::$checkout_tracked = true;

		$cart = WC()->cart;
		$ids  = array();
		foreach ( $cart->get_cart() as $item ) {
			$ids[] = (int) $item['product_id'];
		}

		$this->track(
			'checkout_start',
			array(
				'item_count'  => (int) $cart->get_cart_contents_count(),
				'total'       => (float) $cart->get_total( 'edit' ),
				'product_ids' => array_values( array_unique( $ids ) ),
			)
		);
	}

	public function on_order_processed_classic( $order_id, $posted_data, $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->on_order_processed( $order );
		}
	}

	public function on_order_processed( $order ): void {
		if ( ! $order instanceof \WC_Order || ! $this->settings->is_configured() || '1' === $order->get_meta( '_gsai_placed_tracked' ) ) {
			return;
		}

		$allowed = $this->can_track();
		$this->consent->record_order_consent( $order, $allowed );
		$order->update_meta_data( '_gsai_placed_tracked', '1' );
		$order->save();

		if ( ! $allowed ) {
			return;
		}

		$this->identity->identify_order( $order );
		$this->track( 'order_placed', OrderSerializer::payload( $order ), 'order-' . $order->get_id() . '-placed' );
	}

	public function on_order_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order || ! $this->settings->is_configured() || '1' !== $order->get_meta( '_gsai_tracking_consent' ) ) {
			return;
		}

		$identity = OrderSerializer::identity( $order );
		if ( ! $identity ) {
			return;
		}

		$this->queue(
			array(
				'event_id'    => wp_generate_uuid4(),
				'type'        => 'order_status_changed',
				'occurred_at' => time(),
				'identity'    => $identity,
				'payload'     => array(
					'order_id' => (int) $order_id,
					'from'     => (string) $from,
					'to'       => (string) $to,
					'total'    => (float) $order->get_total(),
					'currency' => $order->get_currency(),
				),
			)
		);
	}

	public function on_login( $user_login, $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->track( 'login', array(), null, $user );
		}
	}

	public function on_comment_post( $comment_id, $approved ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || 'review' !== $comment->comment_type || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}

		$this->track(
			'review_posted',
			array(
				'product_id' => (int) $comment->comment_post_ID,
				'rating'     => (int) get_comment_meta( $comment_id, 'rating', true ),
			)
		);
	}

	public function track_custom( $type, $payload = array() ): void {
		if ( is_string( $type ) && in_array( $type, self::CUSTOM_TYPES, true ) && is_array( $payload ) ) {
			$this->track( $type, $payload );
		}
	}

	private function track( string $type, array $payload = array(), ?string $event_id = null, ?\WP_User $user = null ): void {
		if ( ! $this->can_track( $user ) ) {
			return;
		}

		$anon     = $this->visitor->current();
		$identity = $user ? Identity::for_user( $user ) : $this->identity->current();
		if ( ! $anon && ! $identity ) {
			return;
		}

		$event = array(
			'event_id'    => $event_id ? $event_id : wp_generate_uuid4(),
			'type'        => $type,
			'occurred_at' => time(),
			'payload'     => $payload,
		);
		if ( $anon ) {
			$event['anon_id'] = $anon;
		}
		if ( $identity ) {
			$event['identity'] = $identity;
		}

		$this->queue( $event );
	}

	private function queue( array $event ): void {
		$event = apply_filters( 'gsai_event', $event );
		if ( is_array( $event ) ) {
			$this->outbox->enqueue( 'event', $event );
		}
	}

	private function can_track( ?\WP_User $user = null ): bool {
		if ( ! $this->settings->is_configured() || ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return false;
		}
		if ( $this->visitor->is_bot() || ! $this->consent->tracking_allowed() ) {
			return false;
		}

		$user = $user ? $user : ( is_user_logged_in() ? wp_get_current_user() : null );
		return ! ( $user && $this->consent->ignore_user( $user ) );
	}

	private function is_plain_page_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== $method || is_feed() || is_robots() || is_embed() || is_trackback() ) {
			return false;
		}

		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_PURPOSE' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 'prefetch' ) ) {
				return false;
			}
		}

		return true;
	}

	private function product_payload( \WC_Product $product ): array {
		$base = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

		return array(
			'product_id'   => $base ? $base->get_id() : $product->get_id(),
			'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'price'        => (float) $product->get_price(),
			'category_ids' => $base ? array_map( 'intval', $base->get_category_ids() ) : array(),
		);
	}
}
