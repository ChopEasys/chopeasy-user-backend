<?php

namespace App\Constants;

class PushNotificationType
{
    const ORDER_CONFIRMED = 'order_confirmed';
    const ORDER_READY_PICKUP = 'order_ready_pickup';
    const ORDER_OUT_FOR_DELIVERY = 'order_out_delivery';
    const ORDER_DELIVERED = 'order_delivered';
    const ORDER_CANCELLED = 'order_cancelled';
    const DEDUCTION_REMINDER = 'deduction_reminder';
    const SAVINGS_COMPLETED = 'savings_completed';
    const DELIVERY_AVAILABLE = 'delivery_available';
    const VENDOR_NEW_ORDER = 'vendor_new_order';

    /**
     * Get all notification types as an array.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::ORDER_CONFIRMED,
            self::ORDER_READY_PICKUP,
            self::ORDER_OUT_FOR_DELIVERY,
            self::ORDER_DELIVERED,
            self::ORDER_CANCELLED,
            self::DEDUCTION_REMINDER,
            self::SAVINGS_COMPLETED,
            self::DELIVERY_AVAILABLE,
            self::VENDOR_NEW_ORDER,
        ];
    }

    /**
     * Check if a given type is valid.
     *
     * @param string $type
     * @return bool
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
