<?php

namespace App\Services;

use App\Models\AmbassadorPromotionRequest;
use App\Models\DeliveryTierSetting;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

class AmbassadorPromotionService
{
    /**
     * Maximum ambassador tier level.
     */
    const MAX_TIER = 7;

    /**
     * Determine if an agent is "active" based on four conjunctive criteria:
     * An agent is "active" if they exist as a referred downline agent.
     * Simply being referred counts as active.
     */
    public function isAgentActive(User $agent): bool
    {
        return true;
    }

    /**
     * Count direct downline agents that are classified as "active".
     */
    public function getActiveDownlineCount(User $agent): int
    {
        $downline = User::where('referred_by_agent_id', $agent->id)
            ->where('user_type', 'agent')
            ->where('id', '!=', $agent->id)
            ->get();

        $activeCount = 0;

        foreach ($downline as $subordinate) {
            if ($this->isAgentActive($subordinate)) {
                $activeCount++;
            }
        }

        return $activeCount;
    }

    /**
     * Count direct downline agents at the specified tier level.
     * Checks both delivery_tier and ambassador_badge_tier.
     */
    public function getSubordinateTierCount(User $agent, int $requiredTier): int
    {
        return User::where('referred_by_agent_id', $agent->id)
            ->where('id', '!=', $agent->id)
            ->where(function ($query) use ($requiredTier) {
                $query->where('delivery_tier', $requiredTier)
                    ->orWhere('ambassador_badge_tier', $requiredTier);
            })
            ->count();
    }

    /**
     * Count delivered orders for this agent within a rolling window of $months months.
     */
    public function getDeliveryCountInWindow(User $agent, int $months): int
    {
        $windowStart = Carbon::now()->subMonths($months);

        return Order::where('agent_id', $agent->id)
            ->where('status', 'delivered')
            ->where('created_at', '>=', $windowStart)
            ->count();
    }

    /**
     * Evaluate promotion eligibility for the given agent.
     * Returns a detailed breakdown of each criterion with current vs required values.
     * Enforces sequential promotion only (target tier = current effective tier + 1).
     */
    public function evaluateEligibility(User $agent): array
    {
        $currentTier = $agent->effective_tier ?? max(
            (int) ($agent->ambassador_badge_tier ?? 0),
            (int) ($agent->delivery_tier ?? 1)
        );

        $targetTier = $currentTier + 1;

        // If already at max tier, not eligible for further promotion
        if ($currentTier >= self::MAX_TIER) {
            return [
                'eligible' => false,
                'current_tier' => $currentTier,
                'target_tier' => null,
                'criteria' => [],
                'reason' => 'Already at maximum tier level.',
            ];
        }

        // Get the target tier's configuration
        $tierConfig = DeliveryTierSetting::getForTier($targetTier);

        if (!$tierConfig) {
            return [
                'eligible' => false,
                'current_tier' => $currentTier,
                'target_tier' => $targetTier,
                'criteria' => [],
                'reason' => 'Target tier configuration not found.',
            ];
        }

        // Evaluate each criterion
        $activeDownlineCount = $this->getActiveDownlineCount($agent);
        $requiredActiveAgents = (int) $tierConfig->min_active_agents;

        $subordinateTierLevel = $tierConfig->subordinate_tier_level;
        $subordinateCount = $subordinateTierLevel
            ? $this->getSubordinateTierCount($agent, $subordinateTierLevel)
            : 0;
        $requiredSubordinateCount = (int) $tierConfig->min_subordinate_tier;

        $deliveryWindowMonths = (int) ($tierConfig->delivery_window_months ?? 12);
        $deliveryCount = $this->getDeliveryCountInWindow($agent, $deliveryWindowMonths);
        $requiredDeliveries = (int) $tierConfig->min_deliveries;

        // Build criteria breakdown — only include criteria with non-zero requirements.
        // Tiers 1-3 don't have ambassador-style requirements (active agents, subordinates, deliveries),
        // so showing "0 required" with "Criterion met ✓" is misleading.
        $criteria = [];

        if ($requiredActiveAgents > 0) {
            $criteria[] = [
                'name' => 'active_downline',
                'current' => $activeDownlineCount,
                'required' => $requiredActiveAgents,
                'met' => $activeDownlineCount >= $requiredActiveAgents,
            ];
        }

        if ($requiredSubordinateCount > 0) {
            $criteria[] = [
                'name' => 'subordinate_tier_count',
                'current' => $subordinateCount,
                'required' => $requiredSubordinateCount,
                'met' => $subordinateCount >= $requiredSubordinateCount,
            ];
        }

        if ($requiredDeliveries > 0) {
            $criteria[] = [
                'name' => 'delivery_count',
                'current' => $deliveryCount,
                'required' => $requiredDeliveries,
                'met' => $deliveryCount >= $requiredDeliveries,
            ];
        }

        $allMet = empty($criteria) || collect($criteria)->every(fn($criterion) => $criterion['met']);

        return [
            'eligible' => $allMet,
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'criteria' => $criteria,
        ];
    }

    /**
     * Submit a promotion request for the given agent.
     *
     * Checks that no pending request already exists, verifies eligibility,
     * and creates a promotion request record with a metrics snapshot.
     *
     * @throws \Exception If a pending request already exists or the agent is not eligible.
     */
    public function submitPromotionRequest(User $agent): AmbassadorPromotionRequest
    {
        // Property 7: No duplicate pending promotion requests
        $existingPending = AmbassadorPromotionRequest::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            throw new \Exception('A pending promotion request already exists for this agent.');
        }

        // Evaluate eligibility - all criteria must be met
        $eligibility = $this->evaluateEligibility($agent);

        if (!$eligibility['eligible']) {
            throw new \Exception('Agent does not meet all promotion criteria.');
        }

        // Get target tier config for delivery window
        $targetTier = $eligibility['target_tier'];
        $tierConfig = DeliveryTierSetting::getForTier($targetTier);
        $deliveryWindowMonths = (int) ($tierConfig->delivery_window_months ?? 12);

        // Get current effective tier
        $currentTier = $eligibility['current_tier'];

        // Gather metrics snapshot
        $activeDownlineCount = $this->getActiveDownlineCount($agent);
        $subordinateTierLevel = $tierConfig->subordinate_tier_level;
        $subordinateCount = $subordinateTierLevel
            ? $this->getSubordinateTierCount($agent, $subordinateTierLevel)
            : 0;
        $deliveryCount = $this->getDeliveryCountInWindow($agent, $deliveryWindowMonths);

        // Create the promotion request record
        $promotionRequest = AmbassadorPromotionRequest::create([
            'agent_id' => $agent->id,
            'current_tier' => $currentTier,
            'requested_tier' => $targetTier,
            'status' => 'pending',
            'active_agents_snapshot' => $activeDownlineCount,
            'subordinate_count_snapshot' => $subordinateCount,
            'delivery_count_snapshot' => $deliveryCount,
        ]);

        return $promotionRequest;
    }

    /**
     * Approve a promotion request.
     *
     * Updates the agent's delivery_agent_tier to the requested tier,
     * ensures ambassador_badge_tier never decreases (Property 2: Badge Tier Never Decreases),
     * sets ambassador_promoted_at to now, clears tier_upgrade_status,
     * and marks the request as approved with reviewer info.
     */
    public function approvePromotion(AmbassadorPromotionRequest $request, User $admin): void
    {
        $agent = $request->agent;

        // Update delivery_tier (numeric) and delivery_agent_tier (string format) to the new tier
        $agent->delivery_tier = $request->requested_tier;
        $agent->delivery_agent_tier = 'tier_' . $request->requested_tier;

        // Property 2: Badge tier never decreases — only set if new tier is higher
        $agent->ambassador_badge_tier = max(
            (int) ($agent->ambassador_badge_tier ?? 0),
            (int) $request->requested_tier
        );

        // Record promotion timestamp
        $agent->ambassador_promoted_at = now();

        // Clear any pending tier upgrade status
        $agent->tier_upgrade_status = null;

        $agent->save();

        // Mark the promotion request as approved
        $request->status = 'approved';
        $request->reviewed_by = $admin->id;
        $request->reviewed_at = now();
        $request->save();
    }

    /**
     * Reject a promotion request.
     *
     * Updates the request status to rejected with a reason and reviewer info.
     * The agent's tier remains unchanged — no modification to delivery_agent_tier
     * or ambassador_badge_tier is made.
     */
    public function rejectPromotion(AmbassadorPromotionRequest $request, User $admin, string $reason): void
    {
        $request->status = 'rejected';
        $request->rejection_reason = $reason;
        $request->reviewed_by = $admin->id;
        $request->reviewed_at = now();
        $request->save();
    }
}
