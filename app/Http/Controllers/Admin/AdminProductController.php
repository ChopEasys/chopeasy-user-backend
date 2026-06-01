<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorProductItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProductController extends Controller
{
    /**
     * List all products for admin (from VendorProductItem)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 15);
        $search = $request->query('search');
        $vendorId = $request->query('vendor_id');
        $category = $request->query('category');

        $query = VendorProductItem::with('vendor:id,fullname,store_name,email')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%")
                        ->orWhere('variant_label', 'like', "%{$search}%")
                        ->orWhere('category_name', 'like', "%{$search}%");
                });
            })
            ->when($vendorId, fn($q) => $q->where('vendor_id', $vendorId))
            ->when($category, fn($q) => $q->where('category_name', $category))
            ->orderByDesc('created_at');

        $products = $query->paginate($perPage);

        $formatted = $products->map(function ($item) {
            $vendor = $item->vendor;

            return [
                'id' => (string) $item->id,
                'name' => $item->display_name ?: $item->name,
                'category' => $item->category_name ?? 'Uncategorized',
                'price' => (float) $item->price,
                'cost_price' => (float) ($item->vendor_price ?? $item->price),
                'stock' => (int) ($item->quantity ?? 0),
                'vendor_id' => (string) $item->vendor_id,
                'vendor_name' => $vendor ? ($vendor->store_name ?? $vendor->fullname) : 'N/A',
                'image' => $item->logo ?? null,
                'created_at' => $item->created_at?->format('Y-m-d') ?? '',
            ];
        });

        return response()->json([
            'data' => $formatted,
            'pagination' => [
                'currentPage' => $products->currentPage(),
                'lastPage' => $products->lastPage(),
                'perPage' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }
}
