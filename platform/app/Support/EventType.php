<?php

namespace App\Support;

final class EventType
{
    public const PRODUCT_VIEW = 'product_view';

    public const CATEGORY_VIEW = 'category_view';

    public const SEARCH = 'search';

    public const ADD_TO_CART = 'add_to_cart';

    public const REMOVE_FROM_CART = 'remove_from_cart';

    public const CHECKOUT_START = 'checkout_start';

    public const ORDER_PLACED = 'order_placed';

    public const ORDER_STATUS_CHANGED = 'order_status_changed';

    public const LOGIN = 'login';

    public const REVIEW_POSTED = 'review_posted';

    public const WISHLIST_ADD = 'wishlist_add';

    public const WIDGET_IMPRESSION = 'widget_impression';

    public const WIDGET_CLICK = 'widget_click';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PRODUCT_VIEW, self::CATEGORY_VIEW, self::SEARCH, self::ADD_TO_CART,
            self::REMOVE_FROM_CART, self::CHECKOUT_START, self::ORDER_PLACED,
            self::ORDER_STATUS_CHANGED, self::LOGIN, self::REVIEW_POSTED, self::WISHLIST_ADD,
            self::WIDGET_IMPRESSION, self::WIDGET_CLICK,
        ];
    }
}
