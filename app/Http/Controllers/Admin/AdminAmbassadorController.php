<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AmbassadorAnnualReward;
use App\Models\AmbassadorPromotionRequest;
use App\Models\AmbassadorTerritory;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\AmbassadorPromotionService;
use App\Services\AmbassadorRewardService;
use App\Services\AmbassadorTerritoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAmbassadorController extends Controller
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
     * List all ambassadors (users with ambassador_badge_tier >= 4).
     *
     * Returns tier, territory, and active downline count for each ambassador.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $ambassadors = User::whereNotNull('ambassador_badge_tier')
                ->where('ambassador_badge_tier', '>=', 4)
                ->with('ambassadorTerritory')
                ->orderByDesc('ambassador_badge_tier')
                ->get()
                ->map(function ($ambassador) {
                    $activeDownlineCount = $this->promotionService->getActiveDownlineCount($ambassador);

                    return [
                        'id' => $ambassador->id,
                        'name' => $ambassador->fullname,
                        'email' => $ambassador->email,
                        'badge_tier' => (int) $ambassador->ambassador_badge_tier,
                        'current_tier' => (int) ($ambassador->delivery_agent_tier ?? $ambassador->ambassador_badge_tier),
                        'territory' => $ambassador->ambassadorTerritory ? [
                            'id' => $ambassador->ambassadorTerritory->id,
                            'name' => $ambassador->ambassadorTerritory->name,
                            'scope' => $ambassador->ambassadorTerritory->scope,
                        ] : null,
                        'active_downline_count' => $activeDownlineCount,
                        'promoted_at' => $ambassador->ambassador_promoted_at
                            ? $ambassador->ambassador_promoted_at->toIso8601String()
                            : null,
                    ];
                });

            return JsonResponser::send(false, 'Ambassadors loaded.', $ambassadors, 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load ambassadors.', null, 500, $e);
        }
    }

    /**
     * List pending promotion requests with agent metrics.
     */
    public function promotionRequests(Request $request): JsonResponse
    {
        try {
            $requests = AmbassadorPromotionRequest::where('status', 'pending')
                ->with('agent')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($promotionRequest) {
                    return [
                        'id' => $promotionRequest->id,
                        'agent_id' => $promotionRequest->agent_id,
                        'agent_name' => $promotionRequest->agent?->fullname,
                        'agent_email' => $promotionRequest->agent?->email,
                        'current_tier' => (int) $promotionRequest->current_tier,
                        'requested_tier' => (int) $promotionRequest->requested_tier,
                        'active_agents_snapshot' => (int) $promotionRequest->active_agents_snapshot,
                        'subordinate_count_snapshot' => (int) $promotionRequest->subordinate_count_snapshot,
                        'delivery_count_snapshot' => (int) $promotionRequest->delivery_count_snapshot,
                        'created_at' => $promotionRequest->created_at?->toIso8601String(),
                    ];
                });

            return JsonResponser::send(false, 'Promotion requests loaded.', $requests, 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load promotion requests.', null, 500, $e);
        }
    }

    /**
     * Approve a pending promotion request.
     */
    public function approvePromotion(Request $request, int $requestId): JsonResponse
    {
        try {
            $promotionRequest = AmbassadorPromotionRequest::find($requestId);

            if (!$promotionRequest) {
                return JsonResponser::send(true, 'Promotion request not found.', null, 404);
            }

            if ($promotionRequest->status !== 'pending') {
                return JsonResponser::send(true, 'Promotion request is not pending.', null, 400);
            }

            $admin = $request->user();

            $this->promotionService->approvePromotion($promotionRequest, $admin);

            return JsonResponser::send(false, 'Promotion approved successfully.', [
                'request_id' => $promotionRequest->id,
                'agent_id' => $promotionRequest->agent_id,
                'new_tier' => (int) $promotionRequest->requested_tier,
            ], 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to approve promotion.', null, 500, $e);
        }
    }

    /**
     * Reject a pending promotion request with a reason.
     */
    public function rejectPromotion(Request $request, int $requestId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return JsonResponser::send(true, $validator->errors()->first(), null, 422);
            }

            $promotionRequest = AmbassadorPromotionRequest::find($requestId);

            if (!$promotionRequest) {
                return JsonResponser::send(true, 'Promotion request not found.', null, 404);
            }

            if ($promotionRequest->status !== 'pending') {
                return JsonResponser::send(true, 'Promotion request is not pending.', null, 400);
            }

            $admin = $request->user();
            $reason = $request->input('reason');

            $this->promotionService->rejectPromotion($promotionRequest, $admin, $reason);

            return JsonResponser::send(false, 'Promotion rejected.', [
                'request_id' => $promotionRequest->id,
                'agent_id' => $promotionRequest->agent_id,
                'status' => 'rejected',
            ], 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to reject promotion.', null, 500, $e);
        }
    }

    /**
     * List all ambassador territories.
     */
    public function territories(Request $request): JsonResponse
    {
        try {
            $territories = AmbassadorTerritory::orderBy('scope')
                ->orderBy('name')
                ->get()
                ->map(function ($territory) {
                    return [
                        'id' => $territory->id,
                        'name' => $territory->name,
                        'scope' => $territory->scope,
                        'state' => $territory->state,
                        'lga' => $territory->lga,
                        'active_ambassadors' => $territory->activeAssignments()->count(),
                        'created_at' => $territory->created_at?->toIso8601String(),
                    ];
                });

            return JsonResponser::send(false, 'Territories loaded.', $territories, 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load territories.', null, 500, $e);
        }
    }

    /**
     * Create a new ambassador territory.
     */
    public function createTerritory(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'scope' => 'required|string|in:community,lga,state,national',
                'state' => 'nullable|string|max:100',
                'lga' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return JsonResponser::send(true, $validator->errors()->first(), null, 422);
            }

            $territory = AmbassadorTerritory::create($validator->validated());

            return JsonResponser::send(false, 'Territory created successfully.', [
                'id' => $territory->id,
                'name' => $territory->name,
                'scope' => $territory->scope,
                'state' => $territory->state,
                'lga' => $territory->lga,
            ], 201);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to create territory.', null, 500, $e);
        }
    }

    /**
     * Assign a territory to an ambassador.
     */
    public function assignTerritory(Request $request, int $ambassadorId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'territory_id' => 'required|integer|exists:ambassador_territories,id',
            ]);

            if ($validator->fails()) {
                return JsonResponser::send(true, $validator->errors()->first(), null, 422);
            }

            $ambassador = User::find($ambassadorId);

            if (!$ambassador || !$ambassador->ambassador_badge_tier || $ambassador->ambassador_badge_tier < 4) {
                return JsonResponser::send(true, 'Ambassador not found or user is not an ambassador.', null, 404);
            }

            $territory = AmbassadorTerritory::findOrFail($request->input('territory_id'));

            $assignment = $this->territoryService->assignTerritory($ambassador, $territory);

            return JsonResponser::send(false, 'Territory assigned successfully.', [
                'assignment_id' => $assignment->id,
                'ambassador_id' => $ambassadorId,
                'territory_id' => $territory->id,
                'territory_name' => $territory->name,
                'territory_scope' => $territory->scope,
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return JsonResponser::send(true, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to assign territory.', null, 500, $e);
        }
    }

    /**
     * Get the network hierarchy for an ambassador (downline grouped by tier).
     */
    public function networkHierarchy(int $ambassadorId): JsonResponse
    {
        try {
            $ambassador = User::find($ambassadorId);

            if (!$ambassador || !$ambassador->ambassador_badge_tier || $ambassador->ambassador_badge_tier < 4) {
                return JsonResponser::send(true, 'Ambassador not found or user is not an ambassador.', null, 404);
            }

            // Get direct downline grouped by tier
            $downline = User::where('referred_by_agent_id', $ambassadorId)
                ->select('id', 'fullname', 'email', 'delivery_agent_tier', 'ambassador_badge_tier')
                ->get()
                ->map(function ($agent) {
                    $effectiveTier = (int) ($agent->ambassador_badge_tier ?? $agent->delivery_agent_tier ?? 0);
                    $isActive = $this->promotionService->isAgentActive($agent);

                    return [
                        'id' => $agent->id,
                        'name' => $agent->fullname,
                        'email' => $agent->email,
                        'tier' => $effectiveTier,
                        'is_active' => $isActive,
                    ];
                })
                ->groupBy('tier')
                ->sortKeysDesc();

            return JsonResponser::send(false, 'Network hierarchy loaded.', [
                'ambassador_id' => $ambassadorId,
                'ambassador_name' => $ambassador->fullname,
                'badge_tier' => (int) $ambassador->ambassador_badge_tier,
                'total_downline' => $downline->flatten(1)->count(),
                'hierarchy' => $downline,
            ], 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load network hierarchy.', null, 500, $e);
        }
    }

    /**
     * List all ambassador annual reward records with optional filters.
     */
    public function rewards(Request $request): JsonResponse
    {
        try {
            $query = AmbassadorAnnualReward::with('ambassador');

            // Apply optional filters
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('ambassador_id')) {
                $query->where('ambassador_id', $request->input('ambassador_id'));
            }

            $rewards = $query->orderByDesc('created_at')
                ->get()
                ->map(function ($reward) {
                    return [
                        'id' => $reward->id,
                        'ambassador_id' => $reward->ambassador_id,
                        'ambassador_name' => $reward->ambassador?->fullname,
                        'tier_at_evaluation' => (int) $reward->tier_at_evaluation,
                        'evaluation_start' => $reward->evaluation_start?->toDateString(),
                        'evaluation_end' => $reward->evaluation_end?->toDateString(),
                        'status' => $reward->status,
                        'reward_amount' => $reward->reward_amount,
                        'active_agents_avg' => $reward->active_agents_avg,
                        'delivery_count' => $reward->delivery_count,
                        'paid_at' => $reward->paid_at?->toDateTimeString(),
                        'notes' => $reward->notes,
                        'created_at' => $reward->created_at?->toIso8601String(),
                    ];
                });

            return JsonResponser::send(false, 'Rewards loaded.', $rewards, 200);
        } catch (\Throwable $e) {
            return JsonResponser::send(true, 'Failed to load rewards.', null, 500, $e);
        }
    }
}
