<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\VendorProductItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Internal service-to-service endpoints.
 *
 * These are called by the inventory/admin backend (not by end users). They are
 * protected by a shared secret token passed in the X-Sync-Token header, matching
 * config('services.inventory.sync_token').
 */
class InternalSyncController extends Controller
{
    protected function authorize(Request $request): bool
    {
        $expected = config('services.inventory.sync_token');
        $provided = $request->header('X-Sync-Token') ?? $request->input('sync_token');

        return $expected && hash_equals((string) $expected, (string) $provided);
    }

    /**
     * Sync product weights from the inventory into vendor_product_items.
     *
     * When a product/variant weight changes in the inventory, every vendor
     * product that references that product (and variant, when applicable) must
     * have its cached weight updated so delivery-fee calculations stay correct.
     *
     * Accepts either:
     *   { "product_id": 12, "product_variant_id": 34, "weight": 2.5 }
     * or a batch:
     *   { "items": [ { "product_id": 12, "product_variant_id": 34, "weight": 2.5 }, ... ] }
     *
     * product_variant_id is optional. When provided, only vendor products tied
     * to that exact variant are updated. When null, all vendor products for the
     * product that have no variant (or any variant) are updated.
     */
    public function syncWeight(Request $request)
    {
        if (!$this->authorize($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $items = $request->input('items');
        if (!is_array($items) || empty($items)) {
            // Single-item payload fallback
            $items = [[
                'product_id' => $request->input('product_id'),
                'product_variant_id' => $request->input('product_variant_id'),
                'weight' => $request->input('weight'),
            ]];
        }

        $totalUpdated = 0;
        $results = [];

        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $variantId = isset($item['product_variant_id']) && $item['product_variant_id'] !== null && $item['product_variant_id'] !== ''
                ? (int) $item['product_variant_id']
                : null;
            $weight = isset($item['weight']) && is_numeric($item['weight']) ? (float) $item['weight'] : null;

            if (!$productId || $weight === null) {
                $results[] = ['product_id' => $productId, 'skipped' => true, 'reason' => 'missing product_id or weight'];
                continue;
            }

            $query = VendorProductItem::where('product_id', $productId);

            if ($variantId !== null) {
                // Update rows tied to this specific variant, plus rows with no
                // variant (single-variant products stored without a variant id).
                $query->where(function ($q) use ($variantId) {
                    $q->where('product_variant_id', $variantId)
                        ->orWhereNull('product_variant_id');
                });
            }

            $updated = $query->update(['weight' => $weight]);
            $totalUpdated += $updated;
            $results[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'weight' => $weight,
                'vendor_items_updated' => $updated,
            ];
        }

        Log::info('Vendor product weight sync', [
            'total_updated' => $totalUpdated,
            'results' => $results,
        ]);

        return response()->json([
            'message' => 'Weight sync complete',
            'total_updated' => $totalUpdated,
            'results' => $results,
        ]);
    }
}
