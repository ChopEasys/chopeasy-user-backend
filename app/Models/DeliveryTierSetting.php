<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryTierSetting extends Model
{
    protected $table = 'delivery_tier_settings';

    protected $fillable = [
        'tier',
        'tier_name',
        'max_order_amount',
        'min_completed_deliveries',
        'min_security_deposit',
        'max_security_deposit',
        'description',
        'active',
        'commission_percent',
        'annual_reward_amount',
        'min_active_agents',
        'min_subordinate_tier',
        'subordinate_tier_level',
        'min_deliveries',
        'delivery_window_months',
        'territory_scope',
    ];

    protected $casts = [
        'max_order_amount' => 'float',
        'min_security_deposit' => 'float',
        'max_security_deposit' => 'float',
        'active' => 'boolean',
        'commission_percent' => 'decimal:2',
        'annual_reward_amount' => 'decimal:2',
        'min_active_agents' => 'integer',
        'min_subordinate_tier' => 'integer',
        'subordinate_tier_level' => 'integer',
        'min_deliveries' => 'integer',
        'delivery_window_months' => 'integer',
    ];

    /**
     * Get tier settings for a specific tier
     */
    public static function getForTier($tierNumber)
    {
        return self::where('tier', $tierNumber)->where('active', true)->first();
    }

    /**
     * Get all active tiers ordered by tier number
     */
    public static function getActiveTiers()
    {
        return self::where('active', true)->orderBy('tier')->get();
    }

    /**
     * Get max order amount for a specific tier
     */
    public static function maxAmountForTier($tierNumber)
    {
        $tier = self::getForTier($tierNumber);
        return $tier ? $tier->max_order_amount : 0;
    }

    /**
     * Get min completed deliveries required to upgrade to this tier
     */
    public static function minDeliveriesForTier($tierNumber)
    {
        $tier = self::getForTier($tierNumber);
        return $tier ? $tier->min_completed_deliveries : 0;
    }

    /**
     * Get next tier after the given tier
     */
    public static function getNextTier($currentTierNumber)
    {
        return self::where('tier', '>', $currentTierNumber)
            ->where('active', true)
            ->orderBy('tier')
            ->first();
    }

    /**
     * Determine if this tier is an ambassador tier (Tier 4+)
     */
    public function isAmbassadorTier(): bool
    {
        return $this->tier >= 4;
    }

    /**
     * Get the expected territory scope for this tier
     */
    public function expectedTerritoryScope(): string
    {
        return match ((int) $this->tier) {
            4 => 'community',
            5 => 'lga',
            6 => 'state',
            7 => 'national',
            default => 'none',
        };
    }
}