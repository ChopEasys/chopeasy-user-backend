<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VendorOrder;
use App\Models\VendorProductItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVendorController extends Controller
{
    /**
     * List all vendors for admin with stats
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 15);
        $search = $request->query('search');
        $status = $request->query('status'); // approved, pending, rejected

        $query = User::where('user_type', 'vendor')
            ->withCount('vendorProducts as products_count')
            ->when($search, function ($q, $search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('fullname', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('store_name', 'like', "%{$search}%");
                });
            })
            ->when($status === 'approved', fn($q) => $q->where('is_active', true)->where('is_verified', true))
            ->when($status === 'pending', fn($q) => $q->where(function ($q2) {
                $q2->where('is_verified', false)->orWhere('is_active', false);
            }))
            ->when($status === 'rejected', fn($q) => $q->where('is_active', false))
            ->orderByDesc('created_at');

        $vendors = $query->paginate($perPage);

        $vendorIds = $vendors->pluck('id');
        $ordersCount = VendorOrder::whereIn('vendor_id', $vendorIds)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->selectRaw('vendor_id, COUNT(DISTINCT order_items.order_id) as cnt')
            ->groupBy('vendor_id')
            ->pluck('cnt', 'vendor_id');

        $revenueByVendor = VendorOrder::whereIn('vendor_id', $vendorIds)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->selectRaw('vendor_id, SUM(order_items.price_at_order * order_items.quantity) as total')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id');

        $itemsByVendor = VendorOrder::whereIn('vendor_id', $vendorIds)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->selectRaw('vendor_id, SUM(order_items.quantity) as total_items')
            ->groupBy('vendor_id')
            ->pluck('total_items', 'vendor_id');

        $formatted = $vendors->map(function ($vendor) use ($ordersCount, $revenueByVendor, $itemsByVendor) {
            $status = $vendor->is_active && $vendor->is_verified ? 'approved' : 'pending';
            return [
                'id' => (string) $vendor->id,
                'name' => $vendor->store_name ?? $vendor->fullname,
                'email' => $vendor->email,
                'status' => $status,
                'products_count' => $vendor->products_count ?? 0,
                'orders_count' => (int) ($ordersCount[$vendor->id] ?? 0),
                'items_count' => (int) ($itemsByVendor[$vendor->id] ?? 0),
                'total_revenue' => (float) ($revenueByVendor[$vendor->id] ?? 0),
                'joined_at' => $vendor->created_at->format('Y-m-d'),
            ];
        });

        return response()->json([
            'data' => $formatted,
            'pagination' => [
                'currentPage' => $vendors->currentPage(),
                'lastPage' => $vendors->lastPage(),
                'perPage' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    /**
     * Get single vendor details
     */
    public function show(int $id): JsonResponse
    {
        $vendor = User::where('user_type', 'vendor')->withCount('vendorProducts as products_count')->find($id);

        if (!$vendor) {
            return response()->json(['error' => 'Vendor not found'], 404);
        }

        $ordersCount = (int) VendorOrder::where('vendor_id', $id)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as cnt')
            ->value('cnt');
        $itemsCount = (int) VendorOrder::where('vendor_id', $id)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->sum('order_items.quantity');
        $totalRevenue = VendorOrder::where('vendor_id', $id)
            ->join('order_items', 'vendor_orders.order_item_id', '=', 'order_items.id')
            ->sum(DB::raw('order_items.price_at_order * order_items.quantity'));

        $status = $vendor->is_active && $vendor->is_verified ? 'approved' : 'pending';

        $orderItems = VendorOrder::with(['orderItem.order'])
            ->where('vendor_id', $id)
            ->latest()
            ->get()
            ->map(function ($vendorOrder) {
                $item = $vendorOrder->orderItem;
                $order = $item?->order;
                $qty = (int) ($item?->quantity ?? 0);
                $unit = (float) ($item?->price_at_order ?? 0);
                $name = is_array($item?->product_snapshot) ? ($item->product_snapshot['name'] ?? null) : null;

                return [
                    'order_number' => $order?->order_number,
                    'order_date' => $order?->created_at?->format('Y-m-d'),
                    'product_name' => $name,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'total' => $qty * $unit,
                ];
            })
            ->filter(function ($row) {
                return !empty($row['order_number']) || !empty($row['product_name']);
            })
            ->values();

        return response()->json([
            'data' => [
                'id' => (string) $vendor->id,
                'name' => $vendor->store_name ?? $vendor->fullname,
                'email' => $vendor->email,
                'status' => $status,
                'products_count' => $vendor->products_count ?? 0,
                'orders_count' => $ordersCount,
                'items_count' => $itemsCount,
                'total_revenue' => (float) $totalRevenue,
                'joined_at' => $vendor->created_at->format('Y-m-d'),
                'order_items' => $orderItems,
            ],
        ]);
    }
}
