<?php

namespace App\Support;

use App\Models\DeliveryTierSetting;

class DeliveryTier
{
    /**
     * Default tier names used as fallback when DB config is unavailable.
     */
    public const TIER_NAMES = [
        1 => 'Active Agent',
        2 => 'Executive Agent',
        3 => 'Elite Agent',
        4 => 'Community Ambassador',
        5 => 'LGA Ambassador',
        6 => 'State Ambassador',
        7 => 'National Ambassador',
    ];

    /**
     * Max order fulfillment amount a given delivery tier is allowed to
     * see/accept. Returns null for "no cap" (Tier 3 and above).
     */
    public static function maxAmountForTier(int $tier): ?float
    {
        return match ($tier) {
            2 => 50000.0,
            1 => 20000.0,
            default => null, // Tier 3+ have no cap
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

    /**
     * Required minimum security wallet deposit for a given tier.
     *
     * Tier 1: no deposit required.
     * Tier 2: ₦20,000 required.
     * Tier 3: ₦50,000 required.
     * Tier 4-7: same as Tier 3 (₦50,000) — no additional deposit.
     *
     * Falls back to the DB config if available.
     */
    public static function requiredSecurityDeposit(int $tier): float
    {
        // Try DB lookup first
        $tierConfig = DeliveryTierSetting::getForTier($tier);

        if ($tierConfig) {
            return (float) $tierConfig->min_security_deposit;
        }

        // Hardcoded fallback matching requirements
        return match (true) {
            $tier <= 1 => 0.0,
            $tier === 2 => 20000.0,
            default    => 50000.0, // Tier 3+ all require ₦50,000
        };
    }

    /**
     * Check if the agent's security wallet deposit meets their tier requirement.
     */
    public static function meetsSecurityDepositRequirement(int $tier, float $currentDeposit): bool
    {
        $required = self::requiredSecurityDeposit($tier);

        return $required <= 0 || $currentDeposit >= $required;
    }

    /**
     * Get the display name for a given tier number.
     *
     * Looks up the tier name from the database configuration first,
     * falls back to the TIER_NAMES constant array, and returns
     * "Unknown" if the tier is not recognized.
     */
    public static function tierName(int $tier): string
    {
        // Try DB lookup first
        $tierConfig = DeliveryTierSetting::getForTier($tier);

        if ($tierConfig && !empty($tierConfig->tier_name)) {
            return $tierConfig->tier_name;
        }

        // Fall back to constant array
        return self::TIER_NAMES[$tier] ?? 'Unknown';
    }
}