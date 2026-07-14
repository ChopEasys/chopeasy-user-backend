<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentEarning;
use App\Models\Order;
use App\Models\RiderPayout;
use App\Models\VendorPayout;
use App\Services\AgentCommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRevenueController extends Controller
{
    /**
     * Paginated list of orders with revenue breakdown summary.
     */
    public function index(Request $request, AgentCommissionService $commissionService): JsonResponse
    {
        $perPage = min(50, max(10, (int) $request->query('per_page', 20)));
        $status = $request->query('status');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = Order::query()
            ->with('user:id,fullname,email')
            ->orderByDesc('created_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $paginator = $query->paginate($perPage);

        $rows = $paginator->getCollection()->map(function (Order $order) use ($commissionService) {
            $revenue = $commissionService->platformRevenueSummary($order);
            $agentCommissions = AgentEarning::where('order_id', $order->id)->sum('amount');
            $vendorPayout = VendorPayout::where('order_id', $order->id)->sum('amount');
            $riderPayout = RiderPayout::where('order_id', $order->id)->sum('amount');

            // Company revenue = service fee + delivery profit (delivery fee - rider payout) + product markup
            // This is what the company keeps BEFORE agent commissions (agent commissions shown separately)
            $serviceFee = $revenue['service_fee'];
            $deliveryFee = $revenue['delivery_fee_total'];
            $actualRiderPayout = (float) $riderPayout > 0 ? (float) $riderPayout : $revenue['rider_payout'];
            $deliveryProfit = round(max($deliveryFee - $actualRiderPayout, 0), 2);
            $productMarkup = $revenue['product_markup'];
            $companyRevenue = round($serviceFee + $deliveryProfit + $productMarkup, 2);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'customer_name' => $order->user?->fullname ?? 'N/A',
                'total_amount' => (float) $order->total_amount,
                'product_markup' => round($productMarkup, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'rider_payout' => round($actualRiderPayout, 2),
                'delivery_profit' => $deliveryProfit,
                'vendor_payout' => round((float) $vendorPayout, 2),
                'agent_commission' => round((float) $agentCommissions, 2),
                'company_revenue' => $companyRevenue,
                'created_at' => $order->created_at?->toIso8601String(),
            ];
        });

        // Calculate totals from current page for display
        $totalServiceFee = $rows->sum('service_fee');
        $totalDeliveryProfit = $rows->sum('delivery_profit');
        $totalProductMarkup = $rows->sum('product_markup');
        $totalCompanyRevenue = $rows->sum('company_revenue');
        $totalAgentCommission = $rows->sum('agent_commission');
        $totalVendorPayout = $rows->sum('vendor_payout');
        $totalRiderPayout = $rows->sum('rider_payout');

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total_service_fee' => round($totalServiceFee, 2),
                'total_delivery_profit' => round($totalDeliveryProfit, 2),
                'total_product_markup' => round($totalProductMarkup, 2),
                'total_company_revenue' => round($totalCompanyRevenue, 2),
                'total_agent_commission' => round($totalAgentCommission, 2),
                'total_vendor_payout' => round($totalVendorPayout, 2),
                'total_rider_payout' => round($totalRiderPayout, 2),
                'order_count' => $paginator->total(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Single order revenue breakdown — full detail.
     */
    public function show(int $orderId, AgentCommissionService $commissionService): JsonResponse
    {
        $order = Order::with(['user:id,fullname,email', 'items'])->find($orderId);
        if (!$order) {
            return response()->json(['error' => true, 'message' => 'Order not found'], 404);
        }

        $revenue = $commissionService->platformRevenueSummary($order);

        // Agent commissions on this order
        $agentEarnings = AgentEarning::where('order_id', $order->id)
            ->with('agent:id,fullname')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'agent_name' => $e->agent?->fullname ?? 'N/A',
                'earning_type' => $e->earning_type,
                'commission_percent' => (float) $e->commission_percent,
                'amount' => (float) $e->amount,
                'status' => $e->status,
            ]);

        $vendorPayouts = VendorPayout::where('order_id', $order->id)->get()->map(fn ($p) => [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'status' => $p->status,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ]);

        $riderPayouts = RiderPayout::where('order_id', $order->id)->get()->map(fn ($p) => [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'status' => $p->status,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ]);

        $totalAgentCommission = $agentEarnings->sum('amount');
        $netRevenue = $revenue['platform_revenue_total'] - $totalAgentCommission;

        return response()->json([
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'payment_type' => $order->payment_type,
                    'customer_name' => $order->user?->fullname ?? 'N/A',
                    'customer_email' => $order->user?->email ?? '',
                    'total_amount' => (float) $order->total_amount,
                    'created_at' => $order->created_at?->toIso8601String(),
                ],
                'revenue_breakdown' => [
                    'product_markup' => $revenue['product_markup'],
                    'service_fee' => $revenue['service_fee'],
                    'customer_commission_base' => $revenue['customer_commission_base'],
                    'delivery_fee_total' => $revenue['delivery_fee_total'],
                    'rider_payout' => $revenue['rider_payout'],
                    'delivery_profit' => $revenue['delivery_profit'],
                    'vendor_take_total' => $revenue['vendor_take_total'],
                    'platform_revenue_total' => $revenue['platform_revenue_total'],
                    'agent_commission_total' => round($totalAgentCommission, 2),
                    'net_revenue' => round($netRevenue, 2),
                ],
                'agent_earnings' => $agentEarnings,
                'vendor_payouts' => $vendorPayouts,
                'rider_payouts' => $riderPayouts,
            ],
        ]);
    }
}
