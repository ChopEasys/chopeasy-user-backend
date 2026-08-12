<?php

namespace App\Services;

use App\Models\AgentCommissionSetting;
use App\Models\DeliveryTierSetting;
use App\Models\User;

class AmbassadorCommissionService
{
    /**
     * Get the downline commission rate for an agent based on their badge tier.
     *
     * For Tier 1-3 (or null/0): returns the global downline_percent from agent_commission_settings.
     * For Tier 4-7: returns the tier-specific commission_percent from delivery_tier_settings.
     *
     * @param  User  $agent
     * @return float
     */
    public function getDownlineCommissionRate(User $agent): float
    {
        $badgeTier = $agent->ambassador_badge_tier ?? $agent->delivery_tier ?? 0;

        // For Tier 1-3 (or no tier), use the global downline_percent
        if ($badgeTier <= 3) {
            return $this->getGlobalDownlinePercent();
        }

        // For Tier 4-7, use the tier-specific commission_percent
        $tierSetting = DeliveryTierSetting::getForTier($badgeTier);

        if ($tierSetting && $tierSetting->commission_percent !== null) {
            return (float) $tierSetting->commission_percent;
        }

        // Fallback to global rate if tier config is missing
        return $this->getGlobalDownlinePercent();
    }

    /**
     * Get the global downline percent from agent_commission_settings.
     *
     * @return float
     */
    protected function getGlobalDownlinePercent(): float
    {
        $settings = AgentCommissionSetting::query()->first();

        if ($settings) {
            return (float) $settings->downline_percent;
        }

        // Default fallback matching AgentCommissionService behavior
        $settings = AgentCommissionSetting::query()->create([
            'customer_percent' => 10,
            'vendor_percent' => 10,
            'agent_percent' => 10,
            'max_vendor_rider_payout_commissions' => 5,
            'downline_percent' => 15,
        ]);

        return (float) $settings->downline_percent;
    }
}
