<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\RiderPayout;
use App\Models\VendorPayout;
use App\Support\VendorOrderSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutHistoryController extends Controller
{
    /**
     * Paginated automatic payout rows for the authenticated vendor (gross, platform take, net).
     */
    public function vendorHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || $user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = VendorPayout::query()
            ->where('vendor_id', $user->id)
            ->with(['order' => fn ($q) => $q->with(['items.vendorOrders'])])
            ->latest()
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(function (VendorPayout $payout) use ($user) {
            $order = $payout->order;
            $settlement = [
                'gross_amount' => 0.0,
                'take_percent' => 0.0,
                'take_amount' => 0.0,
                'net_amount' => (float) $payout->amount,
            ];

            if ($order) {
                $gross = VendorOrderSettlement::grossForVendorOnOrder($order, (int) $user->id);
                $settlement = VendorOrderSettlement::forGross($order, $gross);
            }

            return [
                'id' => $payout->id,
                'order_id' => $payout->order_id,
                'order_number' => $order?->order_number,
                'gross_amount' => $settlement['gross_amount'],
                'platform_take_percent' => $settlement['take_percent'],
                'platform_take_amount' => $settlement['take_amount'],
                'net_amount' => $settlement['net_amount'],
                'payout_amount' => (float) $payout->amount,
                'status' => $payout->status,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'failure_reason' => $payout->failure_reason,
                'transfer_reference' => $payout->transfer_reference,
                'created_at' => $payout->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Paginated payout rows for the authenticated rider (net amount after platform keeps base fee and weight-tier %).
     */
    public function riderHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !in_array($user->user_type, ['rider', 'agent'], true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = RiderPayout::query()
            ->where('rider_id', $user->id)
            ->with(['order'])
            ->latest()
            ->paginate($perPage);

        $data = $paginator->getCollection()->map(function (RiderPayout $payout) {
            $order = $payout->order;
            $amount = (float) $payout->amount;

            return [
                'id' => $payout->id,
                'order_id' => $payout->order_id,
                'order_number' => $order?->order_number,
                'payout_amount' => round($amount, 2),
                'status' => $payout->status,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'failure_reason' => $payout->failure_reason,
                'transfer_reference' => $payout->transfer_reference,
                'created_at' => $payout->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
