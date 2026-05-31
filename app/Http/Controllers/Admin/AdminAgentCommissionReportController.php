<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\AgentCommissionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAgentCommissionReportController extends Controller
{
    public function index(Request $request, AgentCommissionReportService $reportService): JsonResponse
    {
        $paginator = $reportService->listOrders([
            'search' => $request->query('search'),
            'agent_id' => $request->query('agent_id'),
            'only_credited' => $request->query('only_credited', '1'),
            'per_page' => $request->query('per_page', 20),
        ]);

        $items = $paginator->getCollection()
            ->map(fn (Order $order) => $reportService->formatListItem($order))
            ->values();

        return response()->json([
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(int $orderId, AgentCommissionReportService $reportService): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'data' => $reportService->buildOrderReport($order),
        ]);
    }
}
