<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\VendorProductItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class WishlistController extends Controller
{
    protected function getSessionId(Request $request, &$cookie = null): ?string
    {
        // Prefer explicit session id from request (mobile clients cannot rely on
        // cookies): cookie -> X-Session-ID header -> session_id input.
        $existing = $request->cookie('cart_session_id')
            ?? $request->header('X-Session-ID')
            ?? $request->input('session_id');

        if ($existing) {
            return $existing;
        }

        $sessionId = Str::uuid()->toString();
        $secure = app()->environment('production') || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        $sameSite = $secure ? 'None' : 'Lax';

        $cookie = new SymfonyCookie(
            'cart_session_id',
            $sessionId,
            now()->addYear(),
            '/',
            null,
            $secure,
            false,
            false,
            $sameSite
        );

        return $sessionId;
    }

    public function index(Request $request)
    {
        try {
            Auth::shouldUse('api');
            $userId = Auth::id();
            $sessionId = $userId ? null : $this->getSessionId($request);

            $wishlistItems = Wishlist::where(function ($q) use ($userId, $sessionId) {
                $userId ? $q->where('user_id', $userId) : $q->where('session_id', $sessionId);
            })->get();

            // Split rows by whether they carry a specific vendor product item.
            // Item-bearing rows resolve their name/store/image/price from
            // vendor_product_items (which has a positive vendor price); legacy
            // NULL-item rows keep the existing catalog /product/wishlist path.
            $itemRows = $wishlistItems->filter(fn ($w) => !is_null($w->vendor_product_item_id));
            $legacyRows = $wishlistItems->filter(fn ($w) => is_null($w->vendor_product_item_id));

            $itemDetails = $this->resolveVendorProductItems($itemRows);
            $legacyDetails = $this->resolveLegacyCatalogItems($legacyRows);

            $wishlist = $itemDetails->concat($legacyDetails)->values();

            return response()->json(['wishlist' => $wishlist]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Failed to fetch wishlist'], 500);
        }
    }


    public function store(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|integer',
                'vendor_product_item_id' => 'nullable|integer',
            ]);

            // Try both variant and main product endpoints
            $productId = $request->product_id;
            $vendorProductItemId = $request->input('vendor_product_item_id');
            
            // First try to get as variant
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . "/product/variant/{$productId}");

            if (!$response->successful() || isset($response->json()['error'])) {
                Log::info('Variant endpoint failed for product ' . $productId . ', trying main product endpoint', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                $response = Http::withToken(config('services.inventory.api_token'))
                    ->get(config('services.inventory.url') . "/product/retail/{$productId}");
            }

            if (!$response->successful() || isset($response->json()['error'])) {
                Log::error('Both endpoints failed for product ' . $productId, [
                    'variant_status' => $response->status(),
                    'variant_body' => $response->body(),
                    'retail_status' => $response->status(),
                    'retail_body' => $response->body()
                ]);
                return response()->json(['error' => 'Product does not exist in inventory'], 422);
            }

            Auth::shouldUse('api');
            $userId = Auth::id();
            $sessionId = $userId ? null : $this->getSessionId($request);

            // Duplicate detection: prefer the specific vendor product item when
            // present so siblings sharing a catalog product_id are treated as
            // distinct items; otherwise fall back to the catalog product_id check.
            $exists = Wishlist::where(function ($q) use ($userId, $sessionId) {
                    $userId ? $q->where('user_id', $userId) : $q->where('session_id', $sessionId);
                })
                ->when($vendorProductItemId !== null, function ($q) use ($vendorProductItemId) {
                    $q->where('vendor_product_item_id', $vendorProductItemId);
                }, function ($q) use ($productId) {
                    $q->where('product_id', $productId);
                })->exists();

            if ($exists) {
                return response()->json(['message' => 'Already in wishlist'], 200);
            }

            Wishlist::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'product_id' => $productId,
                'vendor_product_item_id' => $vendorProductItemId,
            ]);

            return response()->json(['message' => 'Added to wishlist'], 201);
        } catch (Exception $e) {
            Log::error('Error adding to wishlist', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to add to wishlist'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            Auth::shouldUse('api');
            $userId = Auth::id();
            $sessionId = $userId ? null : $this->getSessionId($request);

            $deleted = Wishlist::where('id', $id)
                ->where(function ($q) use ($userId, $sessionId) {
                    $userId ? $q->where('user_id', $userId) : $q->where('session_id', $sessionId);
                })->delete();

            if (!$deleted) {
                return response()->json(['error' => 'Wishlist item not found'], 404);
            }

            return response()->json(['message' => 'Removed from wishlist']);
        } catch (Exception $e) {
            // Log::error('Error removing from wishlist', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to remove from wishlist'], 500);
        }
    }

    protected function fetchProductsFromInventory(array $productIds)
    {
        try {
            if (empty($productIds)) {
                return [];
            }

            $response = Http::withToken(config('services.inventory.api_token'))
                ->post(config('services.inventory.url') . '/product/wishlist', [
                    'product_ids' => $productIds,
                ]);

            if (!$response->successful()) {
                Log::error('Inventory fetch failed', ['status' => $response->status(), 'body' => $response->body()]);
                return [];
            }

            $products = $response->json()['products'] ?? [];
            
            // Ensure each product has the correct ID structure for variants
            return collect($products)->map(function ($product) {
                // If this is a variant, ensure vendor_product_item_id is set
                if (isset($product['variant_id']) && !isset($product['vendor_product_item_id'])) {
                    $product['vendor_product_item_id'] = $product['variant_id'];
                }
                return $product;
            })->toArray();
        } catch (Exception $e) {
            // Log::error('Error fetching product data from inventory', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Resolve wishlist rows that reference a specific vendor product item.
     *
     * Each returned entry carries the true wishlists.id as `wishlist_id`, the
     * `vendor_product_item_id`, and name/store/image/price resolved from the
     * vendor_product_items table (using the positive vendor `price`).
     *
     * @param  \Illuminate\Support\Collection  $itemRows  Wishlist rows with a non-null vendor_product_item_id.
     * @return \Illuminate\Support\Collection
     */
    protected function resolveVendorProductItems($itemRows)
    {
        $itemRows = collect($itemRows);

        if ($itemRows->isEmpty()) {
            return collect();
        }

        $itemIds = $itemRows->pluck('vendor_product_item_id')->unique()->values()->all();

        $vendorItems = VendorProductItem::with('vendor:id,fullname,store_name,store_image')
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        return $itemRows->map(function ($row) use ($vendorItems) {
            $item = $vendorItems->get($row->vendor_product_item_id);

            if (!$item) {
                return null;
            }

            // Prefer the vendor-facing price, then the customer price; both are
            // stored on the item and are expected to be > 0 for a live item.
            $price = (float) ($item->price ?? 0);
            if ($price <= 0) {
                $price = (float) ($item->vendor_price ?? 0);
            }

            $storeName = $item->vendor?->store_name ?? $item->vendor?->fullname;

            return [
                'id' => $item->product_id,
                'wishlist_id' => $row->id,
                'vendor_product_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->display_name ?: $item->name,
                'display_name' => $item->display_name ?: $item->name,
                'variant_label' => $item->variant_label,
                'store_name' => $storeName,
                'vendor_id' => $item->vendor_id,
                'image' => $item->logo,
                'price' => $price,
                'vendor_price' => (float) ($item->vendor_price ?? $item->price ?? 0),
                'uom' => $item->uom,
                'quantity' => $item->quantity,
            ];
        })->filter()->values();
    }

    /**
     * Resolve legacy wishlist rows that have no vendor_product_item_id by using
     * the existing catalog /product/wishlist path. Each entry is keyed to its
     * true wishlists.id and continues to carry name/store/image as before.
     *
     * @param  \Illuminate\Support\Collection  $legacyRows  Wishlist rows with a null vendor_product_item_id.
     * @return \Illuminate\Support\Collection
     */
    protected function resolveLegacyCatalogItems($legacyRows)
    {
        $legacyRows = collect($legacyRows);

        if ($legacyRows->isEmpty()) {
            return collect();
        }

        // A catalog product_id can back multiple legacy rows; keep a list of
        // wishlist ids per product_id so each row maps to its true id.
        $wishlistIdsByProduct = $legacyRows->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('id')->values());

        $productIds = $wishlistIdsByProduct->keys()->all();
        $productDetails = $this->fetchProductsFromInventory($productIds);

        return collect($productDetails)->flatMap(function ($product) use ($wishlistIdsByProduct) {
            $wishlistIds = $wishlistIdsByProduct->get($product['id'], collect());

            return collect($wishlistIds)->map(function ($wishlistId) use ($product) {
                $product['wishlist_id'] = $wishlistId;
                $product['vendor_product_item_id'] = $product['vendor_product_item_id'] ?? null;
                return $product;
            });
        })->values();
    }
}
