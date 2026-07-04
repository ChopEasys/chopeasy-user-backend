<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VendorProduct;
use App\Models\VendorProductItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\VendorStockNotifier;

class VendorProductController extends Controller
{
    private const DEFAULT_VENDOR_PRODUCT_MARKUP_PERCENT = 8.0;

    protected $inventoryProductCache = [];

    protected $inventoryVariantCache = [];

    protected function authorizeVendorInventoryRequest(Request $request, int $vendorId, bool $requireBankDetails = false)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Please log in to manage vendor inventory.',
            ], 401);
        }

        if ($user->user_type !== 'vendor' || (int) $user->id !== $vendorId) {
            return response()->json([
                'error' => 'You are not allowed to manage this vendor inventory.',
            ], 403);
        }

        if ($requireBankDetails) {
            $user->loadMissing('vendorBankDetails');

            if (!$user->vendorBankDetails) {
                return response()->json([
                    'error' => 'Please add your bank account details before adding products.',
                ], 422);
            }
        }

        return null;
    }

    /**
     * List all categories added by vendors in the B2C system
     */
    public function listVendorCategories(Request $request)
    {
        try {
            $categoryIds = VendorProductItem::distinct()
                ->pluck('category_id')
                ->filter()
                ->values();

            if ($categoryIds->isEmpty()) {
                return response()->json([
                    'categories' => [],
                    'message' => 'No categories found'
                ], 200);
            }

            $response = Http::withToken(config('services.inventory.api_token'))
                ->post(config('services.inventory.url') . '/retail/categories/bulk', [
                    'category_ids' => $categoryIds->toArray(),
                ]);
            if (!$response->successful()) {
                return response()->json([
                    'error' => 'Failed to fetch category details from inventory',
                    'message' => $response->json()['message'] ?? 'Unknown error'
                ], $response->status());
            }

            $categories = $response->json()['categories'] ?? [];

            $formattedCategories = collect($categories)->map(function ($category) {
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'code' => $category['code'] ?? null,
                    'description' => $category['description'] ?? null,
                ];
            })->values();

            return response()->json([
                'categories' => $formattedCategories,
                'total' => $formattedCategories->count(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching vendor categories',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

       public function listVendorUnits(Request $request)
    {
        try {
            $categoryIds = VendorProductItem::distinct()
                ->pluck('uom')
                ->filter()
                ->values();

            if ($categoryIds->isEmpty()) {
                return response()->json([
                    'uom' => [],
                    'message' => 'No uom found'
                ], 200);
            }

            $response = Http::withToken(config('services.inventory.api_token'))
                ->post(config('services.inventory.url') . '/retail/uom/bulk', [
                    'uoms' => $categoryIds->toArray(),
                ]);
            if (!$response->successful()) {
                return response()->json([
                    'error' => 'Failed to fetch uom details from inventory',
                    'message' => $response->json()['message'] ?? 'Unknown error'
                ], $response->status());
            }

            $uom = $response->json()['uom'] ?? [];

            $formattedUom = collect($uom)->map(function ($category) {
                return [
                    'id' => $category['id'],
                    'name' => $category['name'],
                    'code' => $category['code'] ?? null,
                    'description' => $category['description'] ?? null,
                ];
            })->values();

            return response()->json([
                'uom' => $uom,
                'total' => $formattedUom->count(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching vendor categories',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch subcategories based on selected category
     */
    public function getSubcategories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . '/retail/subcategories', [
                    'category_id' => $request->category_id,
                ]);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch subcategories'], $response->status());
            }

            return response()->json([
                'subcategories' => $response->json()['subcategories'] ?? [],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching subcategories',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch products based on category or subcategory
     */
    public function getProductsByCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|string',
            'subcategory_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $filters = [
                'category_id' => $request->category_id,
            ];

            if ($request->filled('subcategory_id')) {
                $filters['subcategory_id'] = $request->subcategory_id;
            }

            $response = Http::withToken(config('services.inventory.api_token'))
                ->post(config('services.inventory.url') . '/retail/products', $filters);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch products'], $response->status());
            }

            return response()->json([
                'products' => $response->json()['data'] ?? [],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching products',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all vendors with formatted details
     */
    // public function listVendors(Request $request)
    // {
    //     try {
    //         // Determine page size from query param or default to 10
    //         $perPage = $request->get('per_page', 10);

    //         // Paginate vendors instead of fetching all
    //         $vendors = User::where('user_type', 'vendor')
    //             ->where('is_active', 1)
    //             ->leftJoin('vendor_profiles', 'users.id', '=', 'vendor_profiles.vendor_id')
    //             ->select([
    //                 'users.id',
    //                 'users.fullname',
    //                 'users.address',
    //                 'users.state',
    //                 'users.country',
    //                 'users.is_verified',
    //                 'users.created_at',
    //                 'users.store_name',
    //                 'users.store_image',
    //                 'users.cac_certificate',
    //                 'vendor_profiles.description',
    //                 'vendor_profiles.store_type',
    //                 'vendor_profiles.delivery_time',
    //                 'vendor_profiles.logo',
    //             ])
    //             ->paginate($perPage);

    //         // Map the paginated collection
    //         $vendors->getCollection()->transform(function ($vendor) {
    //             $reviews = DB::table('product_reviews')
    //                 ->where('user_id', $vendor->id)
    //                 ->select(DB::raw('AVG(rating) as average_rating, COUNT(*) as review_count'))
    //                 ->first();

    //             return [
    //                 'id' => $vendor->id,
    //                 'name' => $vendor->fullname,
    //                 'is_verified' => $vendor->is_verified ? 'Verified' : 'Not Verified',
    //                 'description' => $vendor->description ?? 'Fresh produce and organic groceries',
    //                 'store_type' => $vendor->store_type ?? 'Grocery Store',
    //                 'distance' => '0.5km', // Replace with geolocation logic
    //                 'rating' => round($reviews->average_rating ?? 0, 1),
    //                 'reviews' => $reviews->review_count ?? 0,
    //                 'delivery_time' => $vendor->delivery_time ?? '15-30 min',
    //                 'address' => $vendor->address,
    //                 'state' => $vendor->state,
    //                 'country' => $vendor->country,
    //                 'logo' => $vendor->image,
    //                 'store_name' => $vendor->store_name,
    //                 'logo' => $vendor->store_image,
    //                 'cac' => $vendor->cac_certificate,
    //                 'created_at' => $vendor->created_at->format('Y-m-d H:i:s'),

    //             ];
    //         });

    //         return response()->json($vendors, 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => 'Server error while fetching vendors',
    //             'message' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

     public function listVendors(Request $request)
{
    try {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search'); // search term
        $categoryId = $request->get('category'); // category id from frontend
        $uom = $request->get('uom'); // unit filter
        $price = $request->get('price'); // price filter
        $sort = $request->get('sort', 'relevance'); // sorting

        $vendors = User::where('user_type', 'vendor')
            ->where('is_active', 1)
            ->leftJoin('vendor_profiles', 'users.id', '=', 'vendor_profiles.vendor_id')
            ->select([
                'users.id',
                'users.fullname',
                'users.address',
                'users.state',
                'users.country',
                'users.is_verified',
                'users.created_at',
                'users.closing_time',
                'users.opening_time',
                'users.store_name',
                'users.store_image',
                'users.cac_certificate',
                'vendor_profiles.description',
                'vendor_profiles.store_type',
                'vendor_profiles.delivery_time',
                'vendor_profiles.logo',
            ]);

        // Search by vendor name, store name, or product name
        if ($search) {
            $vendors->where(function ($q) use ($search) {
                $q->where('users.fullname', 'like', "%{$search}%")
                  ->orWhere('users.store_name', 'like', "%{$search}%")
                  ->orWhereHas('vendorProducts', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by category/unit/price
        if ($categoryId || $uom || $price) {
            $vendors->whereHas('vendorProducts', function ($q) use ($categoryId, $uom, $price) {
                if ($categoryId && $categoryId !== 'all') {
                    $q->where('category_id', $categoryId);
                }
                if ($uom && $uom !== 'all') {
                    $q->where('uom', $uom);
                }
                if ($price && $price !== 'all') {
                    if ($price === '5000+') {
                        $q->where('price', '>=', 5000);
                    } else {
                        [$min, $max] = explode('-', $price);
                        $q->whereBetween('price', [(float)$min, (float)$max]);
                    }
                }
            });
        }

        // Sorting
        switch ($sort) {
            case 'rating':
                $vendors->withAvg('reviews', 'rating')->orderByDesc('reviews_avg_rating');
                break;
case 'price-low':
    $vendors
        ->whereExists(function ($q) {
            $q->select(DB::raw(1))
              ->from('vendor_product_items')
              ->whereColumn('vendor_product_items.vendor_id', 'users.id');
        })
        ->orderBy(
            VendorProductItem::selectRaw('MIN(price)')
                ->whereColumn('vendor_id', 'users.id'),
            'asc'
        );
    break;


            case 'price-high':
                $vendors->leftJoin('vendor_product_items as vpi', 'users.id', '=', 'vpi.vendor_id')
                        ->orderBy('vpi.price', 'desc');
                break;
            case 'delivery':
                $vendors->orderBy('vendor_profiles.delivery_time', 'asc');
                break;
            default:
                $vendors->orderBy('users.created_at', 'desc');
        }

        $vendors = $vendors->paginate($perPage);

        $vendors->getCollection()->transform(function ($vendor) {
            $reviews = DB::table('product_reviews')
                ->where('user_id', $vendor->id)
                ->select(DB::raw('AVG(rating) as average_rating, COUNT(*) as review_count'))
                ->first();

            return [
                'id' => $vendor->id,
                'name' => $vendor->fullname,
                'is_verified' => $vendor->is_verified ? 'Verified' : 'Not Verified',
                'description' => $vendor->description ?? 'Fresh produce and organic groceries',
                'store_type' => $vendor->store_type ?? 'Grocery Store',
                'distance' => '0.5km',
                'rating' => round($reviews->average_rating ?? 0, 1),
                'reviews' => $reviews->review_count ?? 0,
                'delivery_time' => $vendor->delivery_time ?? '15-30 min',
                'address' => $vendor->address,
                'opening_time'=> $vendor->opening_time,
                'closing_time'=> $vendor->closing_time,
                'state' => $vendor->state,
                'country' => $vendor->country,
                'logo' => $vendor->store_image,
                'store_name' => $vendor->store_name,
                'cac' => $vendor->cac_certificate,
                'created_at' => $vendor->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json($vendors, 200);

    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Server error while fetching vendors',
            'message' => $e->getMessage(),
        ], 500);
    }
}



    /**
     * Update vendor profile
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if ($user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized. Only vendors can update profiles.'], 403);
        }

        $request->validate([
            'description' => 'nullable|string',
            'store_type' => 'nullable|string',
            'delivery_time' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $profile = \App\Models\VendorProfile::firstOrCreate(['vendor_id' => $user->id]);

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public');
            $profile->logo = $logoPath;
        }

        $profile->update($request->only(['description', 'store_type', 'delivery_time', 'latitude', 'longitude']));

        return response()->json(['message' => 'Profile updated successfully', 'profile' => $profile], 200);
    }

    /**
     * Create vendor products (save as JSON)
     */
    protected function formatVariantLabel(array $product): ?string
    {
        $variantLabel = trim((string) ($product['variant_label'] ?? ''));

        if ($variantLabel !== '') {
            return $variantLabel;
        }

        return $this->formatCompactWeightLabel(
            $product['weight'] ?? null,
            $product['uom'] ?? null
        );
    }

    protected function formatDisplayName(array $product): string
    {
        $variantLabel = trim((string) ($product['variant_label'] ?? ''));

        if ($variantLabel !== '') {
            return $variantLabel;
        }

        return trim((string) ($product['name'] ?? ''));
    }

    protected function resolveVendorMarkupPercent(?string $regionId = 'NG-DEFAULT'): float
    {
        try {
            $config = PricingConfig::getActiveConfig($regionId);
            $percent = $config ? (float) ($config->product_markup_percent ?? 0) : 0.0;

            if ($percent >= 0) {
                return $percent;
            }
        } catch (\Throwable $e) {
            // Fall back to the fixed platform markup below.
        }

        return self::DEFAULT_VENDOR_PRODUCT_MARKUP_PERCENT;
    }

    protected function applyVendorMarkup($price, ?string $regionId = 'NG-DEFAULT'): float
    {
        $basePrice = is_numeric($price) ? (float) $price : 0.0;
        $markupPercent = $this->resolveVendorMarkupPercent($regionId);

        return round($basePrice + (($markupPercent / 100) * $basePrice), 2);
    }

    protected function duplicateVendorProductItemExists(
        int $vendorId,
        int $productId,
        ?int $productVariantId,
        ?int $ignoreId = null
    ): bool {
        $query = VendorProductItem::where('vendor_id', $vendorId)
            ->where('product_id', $productId);

        if (!is_null($ignoreId)) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($productVariantId) {
            $query->where('product_variant_id', $productVariantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->exists();
    }

    protected function mapVendorProductItem(VendorProductItem $item): array
    {
        $weight = isset($item->weight) ? (float) $item->weight : null;
        $uomDisplay = $this->formatWeightUomLabel($weight, $item->uom);
        $vendorPrice = isset($item->vendor_price) && $item->vendor_price !== null
            ? (float) $item->vendor_price
            : (float) ($item->price ?? 0);
        $quantity = max((int) ($item->quantity ?? 0), 0);
        $manualOutOfStock = (bool) ($item->manual_out_of_stock ?? false);
        $isOutOfStock = $quantity <= 0 || $manualOutOfStock;
        $isLowStock = !$isOutOfStock && $quantity < 5;
        $stockStatus = $isOutOfStock ? 'out_of_stock' : ($isLowStock ? 'low_stock' : 'in_stock');

        return [
            'id' => $item->id,
            'vendor_product_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'vendor_id' => $item->vendor_id,
            'vendor_name' => $item->vendor->fullname ?? 'Unknown Vendor',
            'category_id' => $item->category_id,
            'category_name' => $item->category_name,
            'base_name' => $item->name,
            'name' => $item->display_name ?: $item->name,
            'display_name' => $item->display_name ?: $item->name,
            'variant_label' => $item->variant_label,
            'logo' => $item->logo,
            'image' => $item->logo,
            'uom' => $item->uom,
            'unit_name' => $item->uom,
            'uom_display' => $uomDisplay,
            'weight' => $weight,
            'weight_label' => $uomDisplay,
            'quantity' => $quantity,
            'manual_out_of_stock' => $manualOutOfStock,
            'stock_status' => $stockStatus,
            'stock_label' => $isOutOfStock ? 'Out of stock' : ($isLowStock ? 'Low stock' : 'In stock'),
            'is_low_stock' => $isLowStock,
            'is_out_of_stock' => $isOutOfStock,
            'vendor_price' => $vendorPrice,
            'price' => $item->price,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    protected function fetchInventoryProduct(int $productId): ?array
    {
        if (array_key_exists($productId, $this->inventoryProductCache)) {
            return $this->inventoryProductCache[$productId];
        }

        try {
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . "/product/retail/{$productId}");

            if (!$response->successful()) {
                $this->inventoryProductCache[$productId] = null;
                return null;
            }

            $product = $response->json();

            $this->inventoryProductCache[$productId] = isset($product['id']) ? $product : null;

            return $this->inventoryProductCache[$productId];
        } catch (\Throwable $e) {
            $this->inventoryProductCache[$productId] = null;
            return null;
        }
    }

    protected function fetchInventoryVariant(int $variantId): ?array
    {
        if (array_key_exists($variantId, $this->inventoryVariantCache)) {
            return $this->inventoryVariantCache[$variantId];
        }

        try {
            $response = Http::withToken(config('services.inventory.api_token'))
                ->get(config('services.inventory.url') . "/product/variant/{$variantId}");

            if (!$response->successful()) {
                $this->inventoryVariantCache[$variantId] = null;
                return null;
            }

            $variant = $response->json('variant');

            $this->inventoryVariantCache[$variantId] = isset($variant['id']) ? $variant : null;

            return $this->inventoryVariantCache[$variantId];
        } catch (\Throwable $e) {
            $this->inventoryVariantCache[$variantId] = null;
            return null;
        }
    }

    protected function extractUnitLabel($unit): ?string
    {
        if (is_string($unit)) {
            return $this->normalizeUnitLabel($unit);
        }

        if (!is_array($unit)) {
            return null;
        }

        foreach (['name', 'short_name', 'quantity_label', 'label'] as $key) {
            $value = $this->normalizeUnitLabel((string) ($unit[$key] ?? ''));
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function extractVariantName($value): ?string
    {
        if (is_string($value)) {
            $variantName = trim($value);

            return $variantName !== '' ? $variantName : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['variant_name', 'product_variant_name', 'display_name', 'name', 'title', 'label'] as $key) {
            $variantName = trim((string) ($value[$key] ?? ''));

            if ($variantName !== '') {
                return $variantName;
            }
        }

        return null;
    }

    protected function normalizeUnitLabel(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $label = trim($value);

        if ($label === '') {
            return null;
        }

        $label = preg_replace('/^\s*\d+(?:\.\d+)?\s*/', '', $label) ?? $label;
        $label = trim($label);

        return $label !== '' ? $label : null;
    }

    protected function extractWeightValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    protected function formatWeightValue(?float $weight): ?string
    {
        if ($weight === null) {
            return null;
        }

        $formatted = number_format($weight, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted !== '' ? $formatted : null;
    }

    protected function formatWeightUomLabel(?float $weight, ?string $uom): ?string
    {
        $unitLabel = $this->normalizeUnitLabel($uom);
        $weightLabel = $this->formatWeightValue($weight);

        if ($weightLabel !== null && $unitLabel !== null) {
            return trim($weightLabel . ' ' . $unitLabel);
        }

        return $unitLabel;
    }

    protected function formatCompactWeightLabel($weight, ?string $uom): ?string
    {
        $weightValue = $this->extractWeightValue($weight);
        $weightLabel = $this->formatWeightValue($weightValue);
        $unitLabel = $this->normalizeUnitLabel($uom);

        if ($weightLabel !== null && $unitLabel !== null) {
            return trim($weightLabel . $unitLabel);
        }

        return $weightLabel ?? $unitLabel;
    }

    protected function findInventoryProductVariant(?array $product, ?int $productVariantId): ?array
    {
        if (!$productVariantId) {
            return null;
        }

        $productVariants = $product['product_variants'] ?? null;

        if (!is_array($productVariants)) {
            return null;
        }

        foreach ($productVariants as $productVariant) {
            if (
                is_array($productVariant) &&
                (int) ($productVariant['id'] ?? 0) === (int) $productVariantId
            ) {
                return $productVariant;
            }
        }

        return null;
    }

    protected function resolveVendorProductUom(
        int $productId,
        ?int $productVariantId,
        ?string $fallback = null
    ): ?string {
        $variant = $productVariantId ? $this->fetchInventoryVariant($productVariantId) : null;
        $product = $this->fetchInventoryProduct($productId);
        $productVariant = $this->findInventoryProductVariant($product, $productVariantId);

        return $this->extractUnitLabel($productVariant['unit'] ?? null)
            ?? $this->extractUnitLabel($variant['unit_data'] ?? null)
            ?? $this->extractUnitLabel($variant['unit_details'] ?? null)
            ?? $this->extractUnitLabel($variant['unit_name'] ?? null)
            ?? $this->extractUnitLabel($variant['uom'] ?? null)
            ?? $this->extractUnitLabel($variant['unit'] ?? null)
            ?? $this->extractUnitLabel($product['unit'] ?? null)
            ?? $this->normalizeUnitLabel($fallback);
    }

    protected function resolveVendorProductWeight(
        int $productId,
        ?int $productVariantId,
        $fallback = null
    ): ?float {
        $variant = $productVariantId ? $this->fetchInventoryVariant($productVariantId) : null;
        $product = $this->fetchInventoryProduct($productId);
        $productVariant = $this->findInventoryProductVariant($product, $productVariantId);

        return $this->extractWeightValue($productVariant['weight'] ?? null)
            ?? $this->extractWeightValue($variant['weight'] ?? null)
            ?? $this->extractWeightValue($product['weight'] ?? null)
            ?? $this->extractWeightValue($fallback);
    }

    protected function extractImagePath($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = trim($value);

        return $path !== '' ? $path : null;
    }

    protected function resolveVendorProductLogo(
        int $productId,
        ?int $productVariantId,
        ?string $fallback = null
    ): ?string {
        $variant = $productVariantId ? $this->fetchInventoryVariant($productVariantId) : null;
        $product = $this->fetchInventoryProduct($productId);

        return $this->extractImagePath($variant['image'] ?? null)
            ?? $this->extractImagePath($product['image'] ?? null)
            ?? $this->extractImagePath($product['logo'] ?? null)
            ?? (filled($fallback) ? trim((string) $fallback) : null);
    }

    protected function resolveVendorProductBrandName(
        int $productId,
        ?int $productVariantId,
        ?string $fallback = null
    ): ?string {
        $variant = $productVariantId ? $this->fetchInventoryVariant($productVariantId) : null;
        $product = $this->fetchInventoryProduct($productId);
        $productVariant = $this->findInventoryProductVariant($product, $productVariantId);

        foreach ([
            $productVariant['brand'] ?? null,
            $variant['brand'] ?? null,
            $product['brand'] ?? null,
            $fallback,
        ] as $brand) {
            if (is_array($brand)) {
                $brand = $brand['name'] ?? null;
            }

            if (!is_string($brand)) {
                continue;
            }

            $brandName = trim($brand);

            if ($brandName !== '') {
                return $brandName;
            }
        }

        return null;
    }

    protected function resolveVendorProductVariantName(
        int $productId,
        ?int $productVariantId,
        ?string $fallback = null
    ): ?string {
        $variant = $productVariantId ? $this->fetchInventoryVariant($productVariantId) : null;
        $product = $this->fetchInventoryProduct($productId);
        $productVariant = $this->findInventoryProductVariant($product, $productVariantId);

        foreach ([$fallback, $productVariant, $variant] as $candidate) {
            $variantName = $this->extractVariantName($candidate);

            if ($variantName !== null) {
                return $variantName;
            }
        }

        return null;
    }

    /**
     * Store vendor products (create multiple rows).
     */
    public function storeVendorProducts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:users,id',
            'category_id' => 'required|string',
            'category_name' => 'required|string',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.product_variant_id' => 'nullable|integer',
            'products.*.name' => 'required|string',
            'products.*.brand_name' => 'nullable|string',
            'products.*.display_name' => 'nullable|string',
            'products.*.variant_label' => 'nullable|string',
            'products.*.uom' => 'nullable|string',
            'products.*.weight' => 'nullable|numeric|min:0',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.logo' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($authorizationError = $this->authorizeVendorInventoryRequest(
            $request,
            (int) $request->vendor_id,
            true
        )) {
            return $authorizationError;
        }

        try {
            $preparedProducts = [];

            foreach ($request->products as $product) {
                $productVariantId =
                    isset($product['product_variant_id']) && $product['product_variant_id'] !== ''
                        ? (int) $product['product_variant_id']
                        : null;
                $resolvedWeight = $this->resolveVendorProductWeight(
                    (int) $product['product_id'],
                    $productVariantId,
                    $product['weight'] ?? null
                );
                $resolvedUom = $this->resolveVendorProductUom(
                    (int) $product['product_id'],
                    $productVariantId,
                    $product['uom'] ?? null
                );
                $resolvedVariantName = $this->resolveVendorProductVariantName(
                    (int) $product['product_id'],
                    $productVariantId,
                    $product['variant_label'] ?? null
                );
                $resolvedVariantLabel = $this->formatVariantLabel([
                    'variant_label' => $resolvedVariantName,
                    'weight' => $resolvedWeight,
                    'uom' => $resolvedUom,
                ]);
                $resolvedDisplayName = $this->formatDisplayName([
                    'name' => $product['name'] ?? null,
                    'variant_label' => $resolvedVariantLabel,
                ]);
                $vendorInputPrice = (float) ($product['price'] ?? 0);

                if (
                    $this->duplicateVendorProductItemExists(
                        (int) $request->vendor_id,
                        (int) $product['product_id'],
                        $productVariantId
                    )
                ) {
                    return response()->json([
                        'error' => sprintf(
                            "Product variant '%s' already exists.",
                            $resolvedDisplayName
                        ),
                    ], 409);
                }

                $duplicateInRequest = collect($preparedProducts)->first(function ($preparedProduct) use ($product, $productVariantId) {
                    return (int) $preparedProduct['product_id'] === (int) $product['product_id']
                        && (int) ($preparedProduct['product_variant_id'] ?? 0) === (int) ($productVariantId ?? 0);
                });

                if ($duplicateInRequest) {
                    return response()->json([
                        'error' => sprintf(
                            "Product variant '%s' was selected more than once.",
                            $resolvedDisplayName
                        ),
                    ], 409);
                }

                $preparedProducts[] = [
                    'vendor_id' => (int) $request->vendor_id,
                    'category_id' => (string) $request->category_id,
                    'category_name' => (string) $request->category_name,
                    'product_id' => (int) $product['product_id'],
                    'product_variant_id' => $productVariantId,
                    'name' => trim((string) $product['name']),
                    'display_name' => $resolvedDisplayName,
                    'variant_label' => $resolvedVariantLabel,
                    'weight' => $resolvedWeight,
                    'quantity' => (int) $product['quantity'],
                    'vendor_price' => $vendorInputPrice,
                    'price' => $this->applyVendorMarkup($vendorInputPrice),
                    'uom' => $resolvedUom,
                    'logo' => $this->resolveVendorProductLogo(
                        (int) $product['product_id'],
                        $productVariantId,
                        $product['logo'] ?? null
                    ),
                ];
            }

            DB::transaction(function () use ($preparedProducts) {
                foreach ($preparedProducts as $preparedProduct) {
                    VendorProductItem::create($preparedProduct);
                }
            });

            return response()->json([
                'message' => 'Products successfully added to vendor inventory',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while saving products',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Update single vendor product item.
     */
    public function updateVendorProducts(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'vendorId' => 'required|integer|exists:users,id',
            'product_id' => 'sometimes|integer',
            'product_variant_id' => 'nullable|integer',
            'name' => 'sometimes|string',
            'brand_name' => 'nullable|string',
            'display_name' => 'sometimes|string',
            'variant_label' => 'nullable|string',
            'uom' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'quantity' => 'sometimes|integer|min:0',
            'manual_out_of_stock' => 'sometimes|boolean',
            'price' => 'sometimes|numeric|min:0',
            'logo' => 'nullable|string',
            'category_id' => 'sometimes|string',
            'category_name' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($authorizationError = $this->authorizeVendorInventoryRequest(
            $request,
            (int) $request->vendorId,
            true
        )) {
            return $authorizationError;
        }

        try {
            $vendorProduct = VendorProductItem::where('vendor_id', $request->vendorId)
                ->with('vendor')
                ->findOrFail($id);

            $previousQuantity = (int) $vendorProduct->quantity;

            $productId = $request->filled('product_id')
                ? (int) $request->product_id
                : (int) $vendorProduct->product_id;
            $existingVariantId = $vendorProduct->product_variant_id
                ? (int) $vendorProduct->product_variant_id
                : null;

            $productVariantId = $request->has('product_variant_id')
                ? (
                    $request->input('product_variant_id') !== null &&
                    $request->input('product_variant_id') !== ''
                        ? (int) $request->input('product_variant_id')
                        : null
                )
                : $existingVariantId;

            if (
                $this->duplicateVendorProductItemExists(
                    (int) $request->vendorId,
                    $productId,
                    $productVariantId,
                    (int) $vendorProduct->id
                )
            ) {
                return response()->json([
                    'error' => 'This vendor already has the selected product variant.',
                ], 409);
            }

            $payload = array_merge(
                $vendorProduct->only([
                    'name',
                    'brand_name',
                    'display_name',
                    'variant_label',
                    'logo',
                ]),
                $request->only([
                    'name',
                    'brand_name',
                    'display_name',
                    'variant_label',
                    'logo',
                ])
            );

            $payload['name'] = trim((string) ($payload['name'] ?? $vendorProduct->name));
            $resolvedUom = ($productId !== (int) $vendorProduct->product_id || $productVariantId !== $existingVariantId || blank($vendorProduct->uom))
                ? $this->resolveVendorProductUom($productId, $productVariantId, $request->input('uom', $vendorProduct->uom))
                : $vendorProduct->uom;
            $resolvedWeight = (
                $productId !== (int) $vendorProduct->product_id ||
                $productVariantId !== $existingVariantId ||
                is_null($vendorProduct->weight) ||
                $request->has('weight')
            )
                ? $this->resolveVendorProductWeight(
                    $productId,
                    $productVariantId,
                    $request->input('weight', $vendorProduct->weight)
                )
                : (isset($vendorProduct->weight) ? (float) $vendorProduct->weight : null);
            $resolvedLogo = (
                $productId !== (int) $vendorProduct->product_id ||
                $productVariantId !== $existingVariantId ||
                blank($vendorProduct->logo) ||
                $request->has('logo')
            )
                ? $this->resolveVendorProductLogo(
                    $productId,
                    $productVariantId,
                    $payload['logo'] ?? $vendorProduct->logo
                )
                : $vendorProduct->logo;
            $resolvedVariantName = $this->resolveVendorProductVariantName(
                $productId,
                $productVariantId,
                $payload['variant_label'] ?? null
            );
            $vendorInputPrice = $request->has('price')
                ? (float) $request->input('price', 0)
                : (isset($vendorProduct->vendor_price) && $vendorProduct->vendor_price !== null
                    ? (float) $vendorProduct->vendor_price
                    : (float) ($vendorProduct->price ?? 0));
            $payload['variant_label'] = $this->formatVariantLabel([
                'variant_label' => $resolvedVariantName,
                'weight' => $resolvedWeight,
                'uom' => $resolvedUom,
            ]);
            $payload['display_name'] = $this->formatDisplayName([
                'name' => $payload['name'],
                'variant_label' => $payload['variant_label'],
            ]);

            $vendorProduct->update([
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'name' => $payload['name'],
                'display_name' => $payload['display_name'],
                'variant_label' => $payload['variant_label'],
                'weight' => $resolvedWeight,
                'quantity' => (int) $request->input('quantity', $vendorProduct->quantity),
                'manual_out_of_stock' => $request->has('manual_out_of_stock') ? (bool) $request->input('manual_out_of_stock') : $vendorProduct->manual_out_of_stock,
                'vendor_price' => $vendorInputPrice,
                'price' => $this->applyVendorMarkup($vendorInputPrice),
                'uom' => $resolvedUom,
                'logo' => $resolvedLogo,
                'category_id' => $request->input('category_id', $vendorProduct->category_id),
                'category_name' => $request->input('category_name', $vendorProduct->category_name),
            ]);

            $vendorProduct->refresh()->load('vendor');

            if ($previousQuantity > 0 && (int) $vendorProduct->quantity <= 0) {
                try {
                    app(VendorStockNotifier::class)->notifyIfJustWentOutOfStock($vendorProduct);
                } catch (\Throwable $e) {
                    Log::warning('Vendor out-of-stock notification failed after inventory update', [
                        'vendor_product_item_id' => $vendorProduct->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => 'Vendor product updated successfully',
                'data' => $this->mapVendorProductItem($vendorProduct),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while updating product',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Delete single vendor product item.
     */
    public function deleteVendorProducts(Request $request)
    {
        $request->validate([
            'vendorId' => 'required|integer|exists:users,id',
            'productIds' => 'required|array',
            'productIds.*' => 'integer|exists:vendor_product_items,id',
        ]);

        $vendorId = $request->vendorId;
        $productIds = $request->productIds;

        if ($authorizationError = $this->authorizeVendorInventoryRequest(
            $request,
            (int) $vendorId,
            true
        )) {
            return $authorizationError;
        }

        try {
            $deletedCount = VendorProductItem::where('vendor_id', $vendorId)
                ->whereIn('id', $productIds)
                ->delete();

            return response()->json([
                'message' => "Deleted {$deletedCount} product(s) successfully"
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while deleting product(s)',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show vendor products (paginated).
     */
    public function showVendorProducts(Request $request, $vendorId)
    {
        try {
            $vendor = User::where('id', $vendorId)
                ->where('user_type', 'vendor')
                ->where('is_active', 1)
                ->first();

            if (!$vendor) {
                return response()->json([
                    'data' => [],
                    'vendor' => null,
                    'message' => 'Vendor is not active.',
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => (int) $request->query('per_page', 50),
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ], 404);
            }

            $perPage = (int) $request->query('per_page', 50);
            $perPage = $perPage > 0 ? min($perPage, 200) : 50;

            $vendorProducts = VendorProductItem::with('vendor')
                ->where('vendor_id', $vendorId)
                ->latest('id')
                ->paginate($perPage);

            if ($vendorProducts->isEmpty()) {
                return response()->json([
                    'data' => [],
                    'vendor' => null,
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ]);
            }

            $products = $vendorProducts->getCollection()
                ->map(fn($item) => $this->mapVendorProductItem($item))
                ->values();

            return response()->json([
                'data' => $products,
                'vendor' => $vendorProducts->first()->vendor ?? $vendor,
                'pagination' => [
                    'total' => $vendorProducts->total(),
                    'per_page' => $vendorProducts->perPage(),
                    'current_page' => $vendorProducts->currentPage(),
                    'last_page' => $vendorProducts->lastPage(),
                    'from' => $vendorProducts->firstItem(),
                    'to' => $vendorProducts->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching vendor products',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Show a single vendor product item.
     */
    public function showSingleVendorProduct($id)
    {
        try {
            $vendorProduct = VendorProductItem::where('vendor_id', Auth::id())
                ->with('vendor')
                ->findOrFail($id);

            return response()->json([
                'data' => $this->mapVendorProductItem($vendorProduct),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching product',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function publicVendorProducts($vendorId)
    {
        try {
            $vendor = User::where('id', $vendorId)
                ->where('user_type', 'vendor')
                ->where('is_active', 1)
                ->first();

            if (!$vendor) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vendor is not active.',
                    'data' => [],
                ], 404);
            }

            $products = VendorProductItem::with('vendor')
                ->where('vendor_id', $vendorId)
                ->latest()
                ->get()
                ->map(fn($item) => $this->mapVendorProductItem($item))
                ->values();

            return response()->json([
                'status' => 'success',
                'data' => $products,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch vendor products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
