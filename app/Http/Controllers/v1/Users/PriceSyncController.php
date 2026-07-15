<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\VendorProductItem;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PriceSyncController extends Controller
{
    /**
     * Sync current prices and availability for a batch of vendor product items.
     */
    public function sync(Request $request)
    {
        $request->validate([
            'vendor_product_item_ids' => 'required|array|min:1|max:50',
            'vendor_product_item_ids.*' => 'required|integer',
        ]);

        $ids = $request->input('vendor_product_item_ids');

        $vendorProductItems = VendorProductItem::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = collect($ids)->map(function ($id) use ($vendorProductItems) {
            $item = $vendorProductItems->get($id);

            if (!$item || $item->quantity <= 0 || $item->manual_out_of_stock) {
                return [
                    'vendor_product_item_id' => $id,
                    'current_price' => null,
                    'available' => false,
                ];
            }

            return [
                'vendor_product_item_id' => $id,
                'current_price' => (float) $item->price,
                'available' => true,
            ];
        })->values()->all();

        return response()->json([
            'items' => $items,
            'synced_at' => Carbon::now()->toIso8601String(),
        ]);
    }
}
