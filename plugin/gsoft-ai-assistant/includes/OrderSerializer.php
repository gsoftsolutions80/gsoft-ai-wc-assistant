<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

class OrderSerializer {

	public static function payload( \WC_Order $order ): array {
		$items = array();
		$ids   = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'total'        => (float) $item->get_total(),
			);
			$ids[]   = (int) $item->get_product_id();
		}

		return array(
			'order_id'       => $order->get_id(),
			'total'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'items'          => $items,
			'product_ids'    => array_values( array_unique( $ids ) ),
			'coupons'        => array_values( $order->get_coupon_codes() ),
			'payment_method' => (string) $order->get_payment_method(),
		);
	}

	/** @return array{wc_user_id?:int,email_sha256?:string}|null */
	public static function identity( \WC_Order $order ): ?array {
		$identity = array();

		if ( $order->get_customer_id() ) {
			$identity['wc_user_id'] = (int) $order->get_customer_id();
		}
		$hash = Identity::hash_email( (string) $order->get_billing_email() );
		if ( $hash ) {
			$identity['email_sha256'] = $hash;
		}

		return $identity ? $identity : null;
	}
}
