<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiderPayoutRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class RiderPayoutRuleController extends Controller
{
    /**
     * Get all rider payout rules
     */
    public function index(Request $request): JsonResponse
    {
        $query = RiderPayoutRule::query();

        if ($request->has('region_id')) {
            $query->forRegion($request->region_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rules = $query->orderedByMinDistance()->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Create a new rider payout rule
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'zone_name' => 'required|string|max:50',
            'min_distance' => 'required|numeric|min:0',
            'max_distance' => 'nullable|numeric|min:0|gte:min_distance',
            'flat_payout' => 'required|numeric|min:0',  // Zone fee
            'region_id' => 'required|string|max:50',
            'is_active' => 'boolean',
        ]);

        try {
            $data = $request->only(['zone_name', 'min_distance', 'max_distance', 'flat_payout', 'region_id', 'is_active']);
            $data['priority'] = RiderPayoutRule::where('region_id', $request->region_id)->max('priority') + 1;
            $rule = RiderPayoutRule::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Rider payout rule created successfully',
                'data' => $rule,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create rider payout rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific rider payout rule
     */
    public function show(RiderPayoutRule $riderPayoutRule): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $riderPayoutRule,
        ]);
    }

    /**
     * Update a rider payout rule
     */
    public function update(Request $request, RiderPayoutRule $riderPayoutRule): JsonResponse
    {
        $request->validate([
            'zone_name' => 'required|string|max:50',
            'min_distance' => 'required|numeric|min:0',
            'max_distance' => 'nullable|numeric|min:0|gte:min_distance',
            'flat_payout' => 'required|numeric|min:0',
            'region_id' => 'required|string|max:50',
            'is_active' => 'boolean',
        ]);

        try {
            $riderPayoutRule->update($request->only([
                'zone_name', 'min_distance', 'max_distance', 'flat_payout',
                'region_id', 'is_active'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Rider payout rule updated successfully',
                'data' => $riderPayoutRule->fresh(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update rider payout rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive(RiderPayoutRule $riderPayoutRule): JsonResponse
    {
        try {
            $riderPayoutRule->update(['is_active' => !$riderPayoutRule->is_active]);
            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
                'data' => $riderPayoutRule->fresh(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a rider payout rule
     */
    public function destroy(RiderPayoutRule $riderPayoutRule): JsonResponse
    {
        try {
            $riderPayoutRule->delete();

            return response()->json([
                'success' => true,
                'message' => 'Rider payout rule deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete rider payout rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
