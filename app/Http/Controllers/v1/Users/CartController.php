<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartItemResource;
use App\Models\Cart;
use App\Models\VendorProductItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Illuminate\Support\Str;

class CartController extends Controller
{
    /**
     * Retrieve or create a session ID for guest users using cookies.
     */
    protected function getSessionId(Request $request, &$cookie = null): ?string
    {
        $existing = $request->cookie('cart_session_id');

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
    protected function getVariantDetails(int $variantId): ?array
    {
        try {
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . "/product/variant/{$variantId}");

            if (!$response->successful()) {
                return null;
            }

            $variant = $response->json('variant');

            if (!$variant || !isset($variant['id'])) {
                return null;
            }

            return $variant;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function normalizeVariantId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function stockPayloadForQuantity(int $quantity): array
    {
        $availableQuantity = max($quantity, 0);
        $isOutOfStock = $availableQuantity <= 0;
        $isLowStock = !$isOutOfStock && $availableQuantity < 5;

        return [
            'quantity' => $availableQuantity,
            'stock_status' => $isOutOfStock ? 'out_of_stock' : ($isLowStock ? 'low_stock' : 'in_stock'),
            'stock_label' => $isOutOfStock ? 'Out of stock' : ($isLowStock ? 'Low stock' : 'In stock'),
            'is_low_stock' => $isLowStock,
            'is_out_of_stock' => $isOutOfStock,
        ];
    }

    protected function enforceStockLimit(
        VendorProductItem $vendorProductItem,
        int $requestedQuantity,
        ?string $fallbackName = null
    ): ?array {
        $availableQuantity = max((int) ($vendorProductItem->quantity ?? 0), 0);
        $productName = trim((string) ($vendorProductItem->display_name ?: $vendorProductItem->name ?: $fallbackName ?: 'This product'));

        if ($availableQuantity <= 0) {
            return [
                'error' => "{$productName} is out of stock.",
            ];
        }

        if ($requestedQuantity > $availableQuantity) {
            return [
                'error' => "Only {$availableQuantity} item(s) left for {$productName}.",
            ];
        }

        return null;
    }

    protected function resolveVendorProductItemForCartItem(Cart $cartItem): ?VendorProductItem
    {
        $productSnapshot = is_array($cartItem->product_snapshot)
            ? $cartItem->product_snapshot
            : (json_decode($cartItem->product_snapshot ?? '[]', true) ?: []);

        $vendorProductItemId = isset($productSnapshot['vendor_product_item_id']) && $productSnapshot['vendor_product_item_id'] !== ''
            ? (int) $productSnapshot['vendor_product_item_id']
            : null;

        $query = VendorProductItem::query();

        if ($vendorProductItemId) {
            return $query->find($vendorProductItemId);
        }

        $vendorId = isset($productSnapshot['vendor_id']) && $productSnapshot['vendor_id'] !== ''
            ? (int) $productSnapshot['vendor_id']
            : null;

        if (!$vendorId) {
            return null;
        }

        $variantId = $this->normalizeVariantId(
            $productSnapshot['product_variant_id'] ?? $cartItem->product_variant_id
        );

        $query->where('vendor_id', $vendorId)
            ->where('product_id', $cartItem->product_id);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->latest('id')->first();
    }


    /**
     * Fetch product details from Inventory API and local vendor pricing.
     * 
     * @param int $productId Product ID from inventory
     * @param int|null $vendorId Vendor ID to get custom pricing
     */
    protected function getProductDetails(
        int $productId,
        ?int $vendorId = null,
        ?VendorProductItem $vendorProductItem = null
    ): ?array {
        try {
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . "/product/retail/{$productId}");

            if (!$response->successful()) {
                return null;
            }

            $product = $response->json();
            if (!$product || !isset($product['id'])) {
                return null;
            }

            if ($vendorProductItem) {
                $vendorProductItem->loadMissing('vendor');
                $vendorId = $vendorProductItem->vendor_id;
            }

            if (!$vendorId && (isset($product['vendor']) || isset($product['user']))) {
                $vendorData = $product['vendor'] ?? $product['user'] ?? null;
                $vendorId = $vendorData['id'] ?? null;
            }

            if (!$vendorProductItem && $vendorId) {
                $vendorProductItem = VendorProductItem::where('product_id', $productId)
                    ->where('vendor_id', $vendorId)
                    ->with('vendor')
                    ->latest('id')
                    ->first();
            }

            $customerFacingPrice = $vendorProductItem ? (float) $vendorProductItem->price : 0;
            $vendorBasePrice = $vendorProductItem?->vendor_price !== null
                ? (float) $vendorProductItem->vendor_price
                : $customerFacingPrice;
            $vendor = null;

            if ($vendorProductItem?->vendor) {
                $vendor = [
                    'id' => $vendorProductItem->vendor->id,
                    'fullname' => $vendorProductItem->vendor->fullname ?? null,
                    'username' => $vendorProductItem->vendor->username ?? null,
                    'email' => $vendorProductItem->vendor->email ?? null,
                    'phoneno' => $vendorProductItem->vendor->phoneno ?? null,
                    'address' => $vendorProductItem->vendor->address ?? null,
                    'lga' => $vendorProductItem->vendor->lga ?? null,
                    'state' => $vendorProductItem->vendor->state ?? null,
                    'country' => $vendorProductItem->vendor->country ?? null,
                    'store_name' => $vendorProductItem->vendor->store_name ?? null,
                    'store_image' => $vendorProductItem->vendor->store_image ?? null,
                    'latitude' => $vendorProductItem->vendor->latitude ?? null,
                    'longitude' => $vendorProductItem->vendor->longitude ?? null,
                ];
            }

            return [
                'id' => $product['id'],
                'name' => $vendorProductItem?->display_name ?: $product['name'],
                'base_name' => $product['name'],
                'display_name' => $vendorProductItem?->display_name ?: $product['name'],
                'variant_label' => $vendorProductItem?->variant_label,
                'cost' => $customerFacingPrice,
                'original_cost' => $product['cost'] ?? 0,
                'price' => $customerFacingPrice,
                'customer_price' => $customerFacingPrice,
                'vendor_price' => $vendorBasePrice,
                'weight_kg' => $product['weight_kg'] ?? $product['weight'] ?? 0,
                'category_id' => $product['category_id'] ?? null,
                'product_for' => $product['product_for'] ?? 'retail',
                'image' => $vendorProductItem?->logo ?: ($product['image'] ?? null),
                'other_images' => json_decode($product['other_images'] ?? '[]', true),
                'unit' => isset($product['unit']) ? (array) $product['unit'] : null,
                'uom' => $vendorProductItem?->uom,
                'vendor_id' => $vendorProductItem?->vendor_id ?? $vendorId,
                'vendor_name' => $vendor['store_name'] ?? $vendor['fullname'] ?? null,
                'vendor_latitude' => $vendor['latitude'] ?? null,
                'vendor_longitude' => $vendor['longitude'] ?? null,
                'vendor' => $vendor,
                'vendor_product_item_id' => $vendorProductItem?->id,
                'selected_variant_id' => $vendorProductItem?->product_variant_id,
            ] + $this->stockPayloadForQuantity((int) ($vendorProductItem?->quantity ?? 0));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Add product to cart for user or guest.
     */

    public function addToCart(Request $request)
    {
        $request->validate([
            'vendor_product_item_id' => 'required|integer|exists:vendor_product_items,id',
            'quantity' => 'required|integer|min:1',
            'product_variant_id'  => 'nullable|integer',
        ]);

        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;

        $cookie = null;
        $sessionId = $userId ? null : $this->getSessionId($request, $cookie);

        $vendorProductItem = VendorProductItem::with('vendor')->find($request->vendor_product_item_id);

        if (!$vendorProductItem) {
            return $this->buildResponse(['error' => 'Vendor product not found'], 404, $cookie);
        }

        $storedVariantId = $vendorProductItem->product_variant_id
            ? (int) $vendorProductItem->product_variant_id
            : null;

        if (
            $storedVariantId &&
            $request->filled('product_variant_id') &&
            (int) $request->input('product_variant_id') !== $storedVariantId
        ) {
            return $this->buildResponse([
                'error' => 'This vendor product is already tied to a specific variant.',
            ], 422, $cookie);
        }

        $productVariantId = $storedVariantId ?: (
            $request->filled('product_variant_id')
                ? (int) $request->input('product_variant_id')
                : null
        );

        $variantSnapshot = null;
        if ($productVariantId) {
            $variantSnapshot = $this->getVariantDetails($productVariantId);

            if (!$variantSnapshot) {
                return $this->buildResponse(['error' => 'Product Variant not found'], 404, $cookie);
            }

            if ((int) $variantSnapshot['product_id'] !== (int) $vendorProductItem->product_id) {
                return $this->buildResponse([
                    'error' => 'The selected product variant does not belong to the specified product.',
                ], 422, $cookie);
            }
        }

        $product = $this->getProductDetails(
            $vendorProductItem->product_id,
            $vendorProductItem->vendor_id,
            $vendorProductItem
        );

        if (!$product) {
            return $this->buildResponse(['error' => 'Product not found in inventory'], 404, $cookie);
        }

        if (is_null($userId) && is_null($sessionId)) {
            return $this->buildResponse(['error' => 'Unauthorized or session missing'], 401, $cookie);
        }

        if ($userId && $sessionId) {
            $this->mergeSessionCartToUser($userId, $sessionId);
            $sessionId = null;
            Cookie::queue(Cookie::forget('cart_session_id'));
        }

        $cartItem = Cart::where(function ($query) use ($userId, $sessionId) {
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                $query->where('session_id', $sessionId);
            }
        })
            ->where('product_id', $product['id'])
            ->where('vendor_product_item_id', $vendorProductItem->id)
            ->where(function ($q) use ($productVariantId) {
                if ($productVariantId) {
                    $q->where('product_variant_id', $productVariantId);
                } else {
                    $q->whereNull('product_variant_id');
                }
            })
            ->first();

        $priceToUse = (float) ($vendorProductItem->price ?? 0);
        $productSnapshot = $product;
        $productSnapshot['product_variant_id'] = $productVariantId;

        if ($variantSnapshot && isset($variantSnapshot['weight'])) {
            $productSnapshot['weight_kg'] = $variantSnapshot['weight'];
        }

        $requestedQuantity = $cartItem
            ? ((int) $cartItem->quantity + (int) $request->quantity)
            : (int) $request->quantity;

        if ($stockError = $this->enforceStockLimit(
            $vendorProductItem,
            $requestedQuantity,
            $productSnapshot['name'] ?? null
        )) {
            return $this->buildResponse($stockError, 422, $cookie);
        }

        if ($cartItem) {
            $cartItem->quantity += $request->quantity;
            $cartItem->price_at_addition = $priceToUse;
            $cartItem->total_cost = $cartItem->quantity * $cartItem->price_at_addition;
            $cartItem->product_variant_id = $productVariantId;
            $cartItem->product_snapshot = $productSnapshot;
            $cartItem->variant_snapshot = $variantSnapshot;
            $cartItem->save();
        } else {
            $cartItem = Cart::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'product_id' => $product['id'],
                'vendor_product_item_id' => $vendorProductItem->id,
                'quantity' => $request->quantity,
                'price_at_addition' => $priceToUse,
                'total_cost' => $request->quantity * $priceToUse,
                'product_snapshot' => $productSnapshot,
                'product_variant_id' => $productVariantId,
                'variant_snapshot' => $variantSnapshot,
            ]);
        }

        return $this->buildResponse([
            'message' => 'Product added to cart',
            'cart_item' => $cartItem,
            'session_id' => $sessionId,
        ], 200, $cookie);
    }

    /**
     * Update quantity of a cart item.
     */
    public function updateQuantity(Request $request)
    {
        $request->validate([
            'cart_item_id' => 'required|integer|exists:carts,id',
            'quantity' => 'required|integer|min:1',
            'operation' => 'nullable|in:add,subtract,set',
        ]);

        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;
        $sessionId = $userId ? null : $this->getSessionId($request);

        $cartItem = Cart::find($request->cart_item_id);

        // Authorization check
        if ($userId && $cartItem->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (!$userId && $cartItem->session_id !== $sessionId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $operation = $request->input('operation', 'set');
        $vendorProductItem = $this->resolveVendorProductItemForCartItem($cartItem);

        if ($operation === 'add') {
            if (!$vendorProductItem) {
                return response()->json(['error' => 'This product is no longer available.'], 404);
            }

            if ($stockError = $this->enforceStockLimit(
                $vendorProductItem,
                (int) $cartItem->quantity + (int) $request->quantity
            )) {
                return response()->json($stockError, 422);
            }

            $cartItem->quantity += $request->quantity;
        } elseif ($operation === 'subtract') {
            $cartItem->quantity -= $request->quantity;
            if ($cartItem->quantity < 1) {
                return response()->json(['error' => 'Minimum quantity is 1'], 400);
            }
        } else { // set
            if (!$vendorProductItem) {
                return response()->json(['error' => 'This product is no longer available.'], 404);
            }

            if ($stockError = $this->enforceStockLimit(
                $vendorProductItem,
                (int) $request->quantity
            )) {
                return response()->json($stockError, 422);
            }

            $cartItem->quantity = $request->quantity;
        }

        $cartItem->total_cost = $cartItem->quantity * $cartItem->price_at_addition;
        $cartItem->save();

        return response()->json([
            'message' => 'Cart updated',
            'cart_item' => $cartItem,
        ]);
    }

    /**
     * Remove item from cart.
     */
    public function removeFromCart(Request $request)
    {
        $request->validate([
            'cart_item_id' => 'required|integer|exists:carts,id',
        ]);

        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;
        $sessionId = $userId ? null : $this->getSessionId($request);

        $cartItem = Cart::find($request->cart_item_id);

        // Authorization check
        if ($userId && $cartItem->user_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        if (!$userId && $cartItem->session_id !== $sessionId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $cartItem->delete();

        return response()->json([
            'message' => 'Item removed from cart',
            'cart_item_id' => $request->cart_item_id,
        ]);
    }

    /**
     * View all cart items for user or guest.
     */
    public function viewCart(Request $request)
    {
        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;

        // Support explicit session_id from request for guests
        $sessionId = $userId ? null : ($request->session_id ?? $this->getSessionId($request));

        $cartItems = Cart::where(function ($query) use ($userId, $sessionId) {
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                $query->where('session_id', $sessionId);
            }
        })->get();

        if ($cartItems->isEmpty()) {
            if ($userId) {
                return response()->json([
                    'error' => 'You have no items in your cart.'
                ], 200);
            } else {
                return response()->json([
                    'message' => 'Cart is empty or session expired.',
                    'error' => []
                ], 200);
            }
        }

        $totalPrice = $cartItems->sum(fn($item) => ($item->price_at_addition ?? 0) * $item->quantity);

        return response()->json([
            'cart_items' => CartItemResource::collection($cartItems),
            'total_price' => number_format($totalPrice, 2),
            'total_items' => $cartItems->count(),
            'session_id' => $sessionId,
        ]);
    }

    public function addMultipleToCart(Request $request)
    {
        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.vendor_product_item_id' => 'required|integer|exists:vendor_product_items,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.product_variant_id' => 'nullable|integer',
        ]);

        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;
        $sessionId = $userId ? null : $this->getSessionId($request);
        $cookie = Cookie::make('cart_session_id', $sessionId, 60 * 24 * 30);

        if ($userId && $sessionId) {
            $this->mergeSessionCartToUser($userId, $sessionId);
            $sessionId = null;
            Cookie::queue(Cookie::forget('cart_session_id'));
        }

        $addedItems = [];
        $pendingQuantities = [];

        foreach ($request->products as $item) {
            $vendorProductItem = VendorProductItem::with('vendor')->find($item['vendor_product_item_id']);

            if (!$vendorProductItem) {
                continue;
            }

            $storedVariantId = $vendorProductItem->product_variant_id
                ? (int) $vendorProductItem->product_variant_id
                : null;

            if (
                $storedVariantId &&
                !empty($item['product_variant_id']) &&
                (int) $item['product_variant_id'] !== $storedVariantId
            ) {
                continue;
            }

            $productVariantId = $storedVariantId ?: (!empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null);
            $variant = null;

            if ($productVariantId) {
                $variant = $this->getVariantDetails($productVariantId);

                if (!$variant || (int) $variant['product_id'] !== (int) $vendorProductItem->product_id) {
                    continue;
                }
            }

            $product = $this->getProductDetails(
                $vendorProductItem->product_id,
                $vendorProductItem->vendor_id,
                $vendorProductItem
            );

            if (!$product) {
                continue;
            }

            $cartItem = Cart::where(function ($query) use ($userId, $sessionId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where('session_id', $sessionId);
                }
            })
                ->where('product_id', $product['id'])
                ->where('vendor_product_item_id', $vendorProductItem->id)
                ->where(function ($q) use ($productVariantId) {
                    if ($productVariantId) {
                        $q->where('product_variant_id', $productVariantId);
                    } else {
                        $q->whereNull('product_variant_id');
                    }
                })
                ->first();

            $cost = (float) ($vendorProductItem->price ?? 0);
            $productSnapshot = $product;
            $productSnapshot['product_variant_id'] = $productVariantId;

            if ($variant && isset($variant['weight'])) {
                $productSnapshot['weight_kg'] = $variant['weight'];
            }

            $desiredQuantity = ($pendingQuantities[$vendorProductItem->id] ?? (int) ($cartItem->quantity ?? 0))
                + (int) $item['quantity'];

            if ($stockError = $this->enforceStockLimit(
                $vendorProductItem,
                $desiredQuantity,
                $productSnapshot['name'] ?? null
            )) {
                continue;
            }

            if ($cartItem) {
                $cartItem->quantity += $item['quantity'];
                $cartItem->price_at_addition = $cost;
                $cartItem->total_cost = $cartItem->quantity * $cost;
                $cartItem->product_variant_id = $productVariantId;
                $cartItem->product_snapshot = $productSnapshot;
                $cartItem->variant_snapshot = $variant;
                $cartItem->save();
            } else {
                $cartItem = Cart::create([
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'product_id' => $product['id'],
                    'vendor_product_item_id' => $vendorProductItem->id,
                    'product_variant_id' => $productVariantId,
                    'quantity' => $item['quantity'],
                    'price_at_addition' => $cost,
                    'total_cost' => $item['quantity'] * $cost,
                    'product_snapshot' => $productSnapshot,
                    'variant_snapshot' => $variant,
                ]);
            }

            $pendingQuantities[$vendorProductItem->id] = (int) $cartItem->quantity;
            $addedItems[] = $cartItem;
        }

        if (empty($addedItems)) {
            return response()->json(['error' => 'No valid products added'], 400);
        }

        return response()->json([
            'message' => 'Products added to cart',
            'cart_items' => $addedItems,
            'session_id' => $sessionId,
        ])->withCookie($cookie);
    }

    public function deleteMultipleFromCart(Request $request)
    {
        $request->validate([
            'cart_item_ids' => 'required|array|min:1',
            'cart_item_ids.*' => 'integer|exists:carts,id',
        ]);

        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;
        $sessionId = $userId ? null : $this->getSessionId($request);

        $deleted = Cart::whereIn('id', $request->cart_item_ids)
            ->where(function ($q) use ($userId, $sessionId) {
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->where('session_id', $sessionId);
                }
            })
            ->delete();

        if ($deleted === 0) {
            return response()->json(['error' => 'No items deleted or items not found for user/session'], 404);
        }

        return response()->json([
            'message' => 'Selected cart items deleted successfully.',
            'deleted_count' => $deleted,
        ]);
    }


    /**
     * Merge guest session cart to authenticated user cart.
     */
    protected function mergeSessionCartToUser(int $userId, string $sessionId): void
    {
        DB::transaction(function () use ($userId, $sessionId) {
            Cart::where('session_id', $sessionId)
                ->whereNull('user_id')
                ->update(['user_id' => $userId, 'session_id' => null]);
        });
    }

    protected function buildResponse(array $data, int $status = 200, $cookie = null)
    {
        $response = response()->json($data, $status);

        if ($cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
