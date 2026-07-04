<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentCommissionSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAgentCommissionController extends Controller
{
    public function show(): JsonResponse
    {
        $s = AgentCommissionSetting::query()->first();
        if (!$s) {
            $s = AgentCommissionSetting::query()->create([
                'customer_percent' => 10,
                'vendor_percent' => 10,
                'agent_percent' => 10,
                'downline_percent' => 15,
                'max_vendor_rider_payout_commissions' => 5,
            ]);
        }

        return response()->json([
            'data' => [
                'customer_percent' => (float) $s->customer_percent,
                'vendor_percent' => (float) $s->vendor_percent,
                'downline_percent' => (float) ($s->downline_percent ?? 15),
                'max_vendor_rider_payout_commissions' => (int) $s->max_vendor_rider_payout_commissions,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_percent' => 'required|numeric|min:0|max:100',
            'vendor_percent' => 'required|numeric|min:0|max:100',
            'downline_percent' => 'required|numeric|min:0|max:100',
            'max_vendor_rider_payout_commissions' => 'required|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => true, 'message' => $validator->errors()->first()], 422);
        }

        $s = AgentCommissionSetting::query()->first();
        if (!$s) {
            $s = new AgentCommissionSetting;
        }

        $s->fill($validator->validated());
        $s->save();

        return response()->json([
            'message' => 'Agent commission settings updated.',
            'data' => [
                'customer_percent' => (float) $s->customer_percent,
                'vendor_percent' => (float) $s->vendor_percent,
                'downline_percent' => (float) $s->downline_percent,
                'max_vendor_rider_payout_commissions' => (int) $s->max_vendor_rider_payout_commissions,
            ],
        ]);
    }
}
