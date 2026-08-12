<?php

namespace App\Services;

use App\Models\AgentEarning;
use App\Models\AmbassadorAnnualReward;
use App\Models\DeliveryTierSetting;
use App\Models\User;
use Carbon\Carbon;

class AmbassadorRewardService
{
    protected AmbassadorPromotionService $promotionService;

    public function __construct(AmbassadorPromotionService $promotionService)
    {
        $this->promotionService = $promotionService;
    }

    /**
     * Create a new evaluation period for an ambassador.
     *
     * Calculates evaluation_start from the ambassador's promotion date or the end
     * of their last evaluation period. Sets evaluation_end to 12 months later.
     * Creates an AmbassadorAnnualReward record with status 'pending'.
     */
    public function createEvaluationPeriod(User $ambassador): AmbassadorAnnualReward
    {
        // Determine the ambassador's current badge tier
        $badgeTier = (int) ($ambassador->ambassador_badge_tier ?? $ambassador->delivery_tier ?? 0);

        // Get tier configuration for reward amount
        $tierConfig = DeliveryTierSetting::getForTier($badgeTier);
        $rewardAmount = $tierConfig ? (float) $tierConfig->annual_reward_amount : 0;

        // Calculate evaluation_start:
        // Use the end of last evaluation period if one exists, otherwise use ambassador_promoted_at
        $lastReward = AmbassadorAnnualReward::where('ambassador_id', $ambassador->id)
            ->orderBy('evaluation_end', 'desc')
            ->first();

        if ($lastReward) {
            $evaluationStart = Carbon::parse($lastReward->evaluation_end);
        } else {
            $evaluationStart = $ambassador->ambassador_promoted_at
                ? Carbon::parse($ambassador->ambassador_promoted_at)
                : Carbon::now();
        }

        // Evaluation end is 12 months after start
        $evaluationEnd = $evaluationStart->copy()->addMonths(12);

        return AmbassadorAnnualReward::create([
            'ambassador_id' => $ambassador->id,
            'tier_at_evaluation' => $badgeTier,
            'evaluation_start' => $evaluationStart->toDateString(),
            'evaluation_end' => $evaluationEnd->toDateString(),
            'status' => 'pending',
            'reward_amount' => $rewardAmount,
        ]);
    }

    /**
     * Evaluate an annual reward at the end of its evaluation period.
     *
     * Checks if the evaluation period has ended. If not, returns early.
     * Verifies the ambassador meets min_active_agents and min_deliveries thresholds.
     * If both criteria are met: credits the wallet, records payment, creates an AgentEarning.
     * If not met: sets status to 'withheld' with notes describing shortfalls.
     */
    public function evaluateAnnualReward(AmbassadorAnnualReward $reward): void
    {
        // Only evaluate if the evaluation period has ended
        if (Carbon::now()->lt($reward->evaluation_end)) {
            return;
        }

        $ambassador = $reward->ambassador;
        $tierConfig = DeliveryTierSetting::getForTier($reward->tier_at_evaluation);

        if (!$tierConfig || !$ambassador) {
            $reward->status = 'withheld';
            $reward->notes = 'Tier configuration or ambassador not found.';
            $reward->save();
            return;
        }

        $requiredActiveAgents = (int) $tierConfig->min_active_agents;
        $requiredDeliveries = (int) $tierConfig->min_deliveries;

        // Check active downline count
        $activeAgentsCount = $this->promotionService->getActiveDownlineCount($ambassador);

        // Check delivery volume within the 12-month evaluation window
        $deliveryCount = $this->promotionService->getDeliveryCountInWindow($ambassador, 12);

        // Store metrics on the reward record
        $reward->active_agents_avg = $activeAgentsCount;
        $reward->delivery_count = $deliveryCount;

        $activeAgentsMet = $activeAgentsCount >= $requiredActiveAgents;
        $deliveriesMet = $deliveryCount >= $requiredDeliveries;

        if ($activeAgentsMet && $deliveriesMet) {
            // Both criteria met — mark as earned and credit wallet
            $reward->status = 'earned';
            $reward->paid_at = now();

            // Credit the reward amount to the ambassador's main wallet
            $ambassador->main_wallet = (float) $ambassador->main_wallet + (float) $reward->reward_amount;
            $ambassador->save();

            // Create an AgentEarning record for this reward payment
            AgentEarning::create([
                'agent_id' => $ambassador->id,
                'earning_type' => 'annual_reward',
                'amount' => $reward->reward_amount,
                'commission_percent' => 0,
                'order_amount' => $reward->reward_amount,
                'status' => 'paid',
            ]);
        } else {
            // Criteria not met — withhold and document shortfalls
            $reward->status = 'withheld';

            $shortfalls = [];

            if (!$activeAgentsMet) {
                $shortfalls[] = "Active downline agents: {$activeAgentsCount}/{$requiredActiveAgents} required.";
            }

            if (!$deliveriesMet) {
                $shortfalls[] = "Delivery volume: {$deliveryCount}/{$requiredDeliveries} required.";
            }

            $reward->notes = 'Reward withheld due to unmet criteria. ' . implode(' ', $shortfalls);
        }

        $reward->save();
    }

    /**
     * Get the current reward status for an ambassador.
     *
     * Returns the latest reward period info including status, evaluation dates,
     * reward amount, and next evaluation date. Returns an empty state if no
     * reward period exists.
     */
    public function getRewardStatus(User $ambassador): array
    {
        $latestReward = AmbassadorAnnualReward::where('ambassador_id', $ambassador->id)
            ->orderBy('evaluation_end', 'desc')
            ->first();

        if (!$latestReward) {
            return [
                'has_reward_period' => false,
                'status' => null,
                'evaluation_start' => null,
                'evaluation_end' => null,
                'reward_amount' => null,
                'next_evaluation_date' => null,
                'active_agents_avg' => null,
                'delivery_count' => null,
                'paid_at' => null,
                'notes' => null,
            ];
        }

        // Calculate next evaluation date
        // If latest period is pending, next evaluation is its evaluation_end
        // If latest period is earned/withheld, next evaluation would be a new period's end
        $nextEvaluationDate = null;

        if ($latestReward->status === 'pending') {
            $nextEvaluationDate = $latestReward->evaluation_end->toDateString();
        } else {
            // Next period would start at the end of the last period
            $nextEvaluationDate = Carbon::parse($latestReward->evaluation_end)
                ->addMonths(12)
                ->toDateString();
        }

        return [
            'has_reward_period' => true,
            'status' => $latestReward->status,
            'evaluation_start' => $latestReward->evaluation_start->toDateString(),
            'evaluation_end' => $latestReward->evaluation_end->toDateString(),
            'reward_amount' => $latestReward->reward_amount,
            'next_evaluation_date' => $nextEvaluationDate,
            'active_agents_avg' => $latestReward->active_agents_avg,
            'delivery_count' => $latestReward->delivery_count,
            'paid_at' => $latestReward->paid_at ? $latestReward->paid_at->toDateTimeString() : null,
            'notes' => $latestReward->notes,
        ];
    }
}
