<?php

namespace App\Http\Controllers\v1\Agent;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTierSetting;
use App\Responser\JsonResponser;
use App\Services\AmbassadorPromotionService;
use App\Services\AmbassadorRewardService;
use App\Services\AmbassadorTerritoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmbassadorController extends Controller
{
    protected AmbassadorPromotionService $promotionService;
    protected AmbassadorTerritoryService $territoryService;
    protected AmbassadorRewardService $rewardService;

    public function __construct(
        AmbassadorPromotionService $promotionService,
        AmbassadorTerritoryService $territoryService,
        AmbassadorRewardService $rewardService
    ) {
        $this->promotionService = $promotionService;
        $this->territoryService = $territoryService;
        $this->rewardService = $rewardService;
    }

    /**
     * Get promotion eligibility breakdown for the authenticated agent.
     *
     * Returns per-criterion progress showing current values vs required thresholds.
     */
    public function eligibility(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $eligibility = $this->promotionService->evaluateEligibility($user);

            // Add target tier name from config
            $targetTier = $eligibility['target_tier'] ?? null;
            if ($targetTier) {
                $tierConfig = DeliveryTierSetting::getForTier($targetTier);
                $eligibility['target_tier_name'] = $tierConfig ? $tierConfig->tier_name : "Tier {$targetTier}";
            } else {
                $eligibility['target_tier_name'] = null;
            }

            // Add human-readable names to criteria
            $subordinateLabel = 'Elite Agents';
            if ($targetTier) {
                $targetConfig = DeliveryTierSetting::getForTier($targetTier);
                if ($targetConfig && $targetConfig->subordinate_tier_level) {
                    $subTierConfig = DeliveryTierSetting::getForTier($targetConfig->subordinate_tier_level);
                    $subordinateLabel = $subTierConfig ? $subTierConfig->tier_name . 's' : 'Elite Agents';
                }
            }
            $criteriaNames = [
                'active_downline' => 'Active Agents',
                'subordinate_tier_count' => $subordinateLabel,
                'delivery_count' => 'Delivery Volume',
            ];
            if (!empty($eligibility['criteria'])) {
                $eligibility['criteria'] = array_map(function ($criterion) use ($criteriaNames) {
                    $criterion['name'] = $criteriaNames[$criterion['name']] ?? $criterion['name'];
                    return $criterion;
                }, $eligibility['criteria']);
            }

            // Check if there's a pending promotion request
            $eligibility['pending_request'] = $user->ambassadorPromotionRequests()
                ->where('status', 'pending')
                ->exists();

            return JsonResponser::send(false, 'Eligibility retrieved successfully.', $eligibility);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to retrieve eligibility.', null, 500, $e);
        }
    }

    /**
     * Submit a promotion request for the authenticated agent.
     *
     * Creates a pending promotion record with current metrics snapshot.
     */
    public function requestPromotion(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $promotionRequest = $this->promotionService->submitPromotionRequest($user);

            return JsonResponser::send(false, 'Promotion request submitted successfully.', [
                'promotion_request_id' => $promotionRequest->id,
                'current_tier' => $promotionRequest->current_tier,
                'requested_tier' => $promotionRequest->requested_tier,
                'status' => $promotionRequest->status,
            ], 201);
        } catch (\Exception $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to submit promotion request.', null, 500, $e);
        }
    }

    /**
     * Get ambassador dashboard data for the authenticated agent.
     *
     * Returns badge tier, current tier, territory info, active downline count,
     * delivery volume, and reward status.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Determine tier information
            $badgeTier = $user->ambassador_badge_tier ?? $user->delivery_agent_tier ?? 0;
            $currentTier = $user->effective_tier;

            // Get tier name from config
            $tierConfig = DeliveryTierSetting::getForTier($badgeTier);
            $tierName = $tierConfig ? $tierConfig->tier_name : 'Unknown';

            // Territory info
            $territory = $user->ambassadorTerritory;
            $territoryInfo = $territory ? [
                'id' => $territory->id,
                'name' => $territory->name,
                'scope' => $territory->scope,
                'state' => $territory->state,
                'lga' => $territory->lga,
            ] : null;

            // Active downline count
            $activeDownlineCount = $this->promotionService->getActiveDownlineCount($user);

            // Delivery volume within rolling window
            $deliveryWindowMonths = $tierConfig ? (int) $tierConfig->delivery_window_months : 12;
            $deliveryVolume = $this->promotionService->getDeliveryCountInWindow($user, $deliveryWindowMonths);

            // Reward status
            $rewardStatus = $this->rewardService->getRewardStatus($user);

            return JsonResponser::send(false, 'Ambassador dashboard loaded.', [
                'badge_tier' => $badgeTier,
                'badge_tier_name' => $tierName,
                'current_tier' => $currentTier,
                'territory' => $territoryInfo,
                'active_downline_count' => $activeDownlineCount,
                'delivery_volume' => $deliveryVolume,
                'delivery_window_months' => $deliveryWindowMonths,
                'reward_status' => $rewardStatus,
            ]);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load ambassador dashboard.', null, 500, $e);
        }
    }

    /**
     * Get current territory details and assignment history for the authenticated agent.
     */
    public function territory(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Current territory
            $currentTerritory = $user->ambassadorTerritory;
            $currentTerritoryData = $currentTerritory ? [
                'id' => $currentTerritory->id,
                'name' => $currentTerritory->name,
                'scope' => $currentTerritory->scope,
                'state' => $currentTerritory->state,
                'lga' => $currentTerritory->lga,
            ] : null;

            // Assignment history
            $assignmentHistory = $this->territoryService->getAssignmentHistory($user)
                ->map(function ($assignment) {
                    return [
                        'id' => $assignment->id,
                        'territory_id' => $assignment->territory_id,
                        'territory_name' => $assignment->territory ? $assignment->territory->name : null,
                        'territory_scope' => $assignment->territory ? $assignment->territory->scope : null,
                        'assigned_at' => $assignment->assigned_at ? $assignment->assigned_at->toDateTimeString() : null,
                        'unassigned_at' => $assignment->unassigned_at ? $assignment->unassigned_at->toDateTimeString() : null,
                        'is_primary' => $assignment->is_primary,
                        'notes' => $assignment->notes,
                    ];
                });

            return JsonResponser::send(false, 'Territory details retrieved.', [
                'current_territory' => $currentTerritoryData,
                'assignment_history' => $assignmentHistory,
            ]);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to retrieve territory details.', null, 500, $e);
        }
    }

    /**
     * Get annual reward status for the authenticated agent.
     */
    public function rewardStatus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $rewardStatus = $this->rewardService->getRewardStatus($user);

            return JsonResponser::send(false, 'Reward status retrieved.', $rewardStatus);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to retrieve reward status.', null, 500, $e);
        }
    }
}
