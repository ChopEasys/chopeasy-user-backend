<?php

namespace App\Support;

class DeliveryTier
{
    /**
     * Max order fulfillment amount a given delivery tier is allowed to
     * see/accept. Returns null for "no cap" (Tier 3).
     */
    public static function maxAmountForTier(int $tier): ?float
    {
        return match ($tier) {
            3 => null,
            2 => 50000.0,
            default => 10000.0, // Tier 1
        };
    }

    /**
     * Whether a given order amount is within the cap for a tier.
     */
    public static function tierCanHandle(int $tier, float $orderAmount): bool
    {
        $max = self::maxAmountForTier($tier);

        return $max === null || $orderAmount < $max;
    }
}