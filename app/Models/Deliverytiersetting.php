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
    ];

    protected $casts = [
        'max_order_amount' => 'float',
        'min_security_deposit' => 'float',
        'max_security_deposit' => 'float',
        'active' => 'boolean',
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
}