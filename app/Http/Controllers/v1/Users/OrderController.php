<?php

namespace App\Http\Controllers\v1\Users;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\AgentEarning;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VendorProductItem;
use App\Services\AgentCommissionService;
use App\Services\AutomaticPayoutService;
use App\Services\PricingService;
use App\Services\RiderAssignmentService;
use App\Services\VendorOrderPayoutNotifier;
use App\Services\VendorStockNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Illuminate\Support\Str;
use App\Support\DeliveryTier;


class OrderController extends Controller
{

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

    protected function decimalValue($value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return round((float) $value, 2);
    }

    /**
     * Allowed installment counts for budget / installment plans (must match frontend).
     */
    protected static function allowedInstallmentCounts(string $paymentType): array
    {
        return match ($paymentType) {
            'weekly' => range(4, 48, 2),
            'monthly' => [2, 4, 6, 8, 10, 12],
            default => [],
        };
    }

    /**
     * Default installment count for legacy rows that pre-date installment_count.
     */
    protected static function defaultInstallmentCount(string $paymentType): int
    {
        return match ($paymentType) {
            'weekly' => 4,
            'monthly' => 2,
            default => 1,
        };
    }

    protected function resolveInstallmentCount(Request $request, string $paymentType): ?int
    {
        if (!in_array($paymentType, ['weekly', 'monthly'], true)) {
            return null;
        }

        $raw = $request->input('installment_count');
        if ($raw === null || $raw === '') {
            throw ValidationException::withMessages([
                'installment_count' => ['Please select the number of installments for this payment plan.'],
            ]);
        }

        $count = (int) $raw;
        $allowed = self::allowedInstallmentCounts($paymentType);

        if (!in_array($count, $allowed, true)) {
            throw ValidationException::withMessages([
                'installment_count' => ['The selected installment count is not valid for this plan.'],
            ]);
        }

        return $count;
    }

    protected function resolveCustomAmount(Request $request, string $paymentType): ?float
    {
        $raw = $request->input('custom_amount');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!in_array($paymentType, ['daily', 'weekly', 'monthly'], true)) {
            throw ValidationException::withMessages([
                'custom_amount' => ['Custom amount is only allowed for daily, weekly, or monthly plans.'],
            ]);
        }

        $amount = $this->decimalValue($raw);
        $minimum = match ($paymentType) {
            'daily' => 100,
            'weekly' => 1000,
            'monthly' => 3000,
            default => 0,
        };

        if ($amount < $minimum) {
            throw ValidationException::withMessages([
                'custom_amount' => ["The custom {$paymentType} amount must be at least {$minimum}."],
            ]);
        }

        return round($amount, 2);
    }

    protected function firstInstallmentAmount(float $totalAmount, string $paymentType, ?int $installmentCount): float
    {
        return match ($paymentType) {
            'daily' => round($totalAmount / 30, 2),
            'weekly' => round($totalAmount / max(1, $installmentCount ?? self::defaultInstallmentCount('weekly')), 2),
            'monthly' => round($totalAmount / max(1, $installmentCount ?? self::defaultInstallmentCount('monthly')), 2),
            default => round($totalAmount, 2),
        };
    }

    protected function buildOrderItemSnapshot(array $item): array
    {
        $customerPrice = $this->decimalValue($item['price'] ?? 0);
        $vendorPrice = $this->decimalValue($item['vendor_price'] ?? $customerPrice);

        return [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'name' => $item['name'] ?? null,
            'price' => $customerPrice,
            'customer_price' => $customerPrice,
            'vendor_price' => $vendorPrice,
            'weight_kg' => $this->decimalValue($item['weight_kg'] ?? $item['weight'] ?? 0),
            'image' => $item['image'] ?? null,
            'vendor_id' => isset($item['vendor_id']) && $item['vendor_id'] !== '' ? (int) $item['vendor_id'] : null,
            'vendor_product_item_id' => isset($item['vendor_product_item_id']) && $item['vendor_product_item_id'] !== ''
                ? (int) $item['vendor_product_item_id']
                : null,
            'product_variant_id' => isset($item['product_variant_id']) && $item['product_variant_id'] !== ''
                ? (int) $item['product_variant_id']
                : null,
        ];
    }

    protected function buildVariantSnapshotFromItem(array $item): ?array
    {
        $snapshot = $this->buildOrderItemSnapshot($item);

        if (!$snapshot['product_variant_id'] && !$snapshot['weight_kg']) {
            return null;
        }

        return [
            'product_variant_id' => $snapshot['product_variant_id'],
            'weight_kg' => $snapshot['weight_kg'],
        ];
    }

    protected function calculateOrderPricingData(Request $request, array $items, array $existingCoordinates = []): array
    {
        $customerSubtotal = 0;
        $vendorSubtotal = 0;
        $totalWeight = 0;
        $itemCount = 0;

        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $customerPrice = $this->decimalValue($item['price'] ?? 0);
            $vendorPrice = $this->decimalValue($item['vendor_price'] ?? $customerPrice);
            $weight = $this->decimalValue($item['weight_kg'] ?? $item['weight'] ?? 0);

            $itemCount += $quantity;
            $customerSubtotal += $customerPrice * $quantity;
            $vendorSubtotal += $vendorPrice * $quantity;
            $totalWeight += $weight * $quantity;
        }

        $user = $request->user();
        $fallbackDeliveryLat = $existingCoordinates['delivery_latitude']
            ?? $user?->latitude
            ?? $user?->lat
            ?? 6.5244;
        $fallbackDeliveryLng = $existingCoordinates['delivery_longitude']
            ?? $user?->longitude
            ?? $user?->lng
            ?? $user?->lon
            ?? 3.3792;

        $deliveryLat = $this->decimalValue($request->input('delivery_latitude', $fallbackDeliveryLat), (float) $fallbackDeliveryLat);
        $deliveryLng = $this->decimalValue($request->input('delivery_longitude', $fallbackDeliveryLng), (float) $fallbackDeliveryLng);
        $pickupLat = $this->decimalValue($request->input('pickup_latitude', $existingCoordinates['pickup_latitude'] ?? $deliveryLat), (float) $deliveryLat);
        $pickupLng = $this->decimalValue($request->input('pickup_longitude', $existingCoordinates['pickup_longitude'] ?? $deliveryLng), (float) $deliveryLng);

        $distance = PricingService::calculateDistance($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        $regionId = $request->input('region_id', 'NG-DEFAULT');
        $pricingService = new PricingService($regionId);
        $pricing = $pricingService->calculateOrderPricing(
            $itemCount,
            round($totalWeight, 2),
            $distance,
            round($customerSubtotal, 2),
            round($vendorSubtotal, 2)
        );

        return [
            'pricing' => $pricing,
            'total_amount' => (float) ($pricing['payout_breakdown']['total_to_collect_from_customer'] ?? 0),
            'pickup_latitude' => $pickupLat,
            'pickup_longitude' => $pickupLng,
            'delivery_latitude' => $deliveryLat,
            'delivery_longitude' => $deliveryLng,
        ];
    }

    protected function formatPricingAttributes(array $pricingData): array
    {
        $pricing = $pricingData['pricing'];

        return [
            'customer_product_subtotal' => $pricing['customer_product_subtotal'] ?? 0,
            'service_fee_total' => $pricing['service_fee_total'] ?? $pricing['service_charge_total'] ?? 0,
            'delivery_fee_total' => $pricing['delivery_fee_total'] ?? $pricing['total_charge'] ?? 0,
            'base_fee_total' => $pricing['base_fee'] ?? $pricing['base_charge'] ?? 0,
            'weight_fee_total' => $pricing['weight_fee'] ?? $pricing['weight_service_fee'] ?? 0,
            'distance_fee_total' => $pricing['distance_fee'] ?? $pricing['distance_charge_total'] ?? 0,
            'total_weight' => $pricing['total_weight_kg'] ?? 0,
            'item_count' => $pricing['item_count'] ?? 0,
            'distance_in_km' => $pricing['distance_km'] ?? 0,
            'computed_total_charge' => $pricing['delivery_fee_total'] ?? $pricing['total_charge'] ?? 0,
            'platform_revenue' => $pricing['payout_breakdown']['platform_revenue'] ?? 0,
            'rider_payout' => $pricing['payout_breakdown']['rider_payout'] ?? 0,
            'vendor_payout' => $pricing['payout_breakdown']['vendor_payout'] ?? 0,
            'pricing_config_id' => $pricing['metadata']['pricing_config_id'] ?? null,
            'weight_tier_id' => $pricing['metadata']['weight_tier_id'] ?? null,
            'pricing_breakdown' => $pricing,
            'pickup_latitude' => $pricingData['pickup_latitude'],
            'pickup_longitude' => $pricingData['pickup_longitude'],
            'delivery_latitude' => $pricingData['delivery_latitude'],
            'delivery_longitude' => $pricingData['delivery_longitude'],
        ];
    }

    protected function normalizeVariantId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function resolveInventoryProductItem(array $item): ?VendorProductItem
    {
        $vendorProductItemId = isset($item['vendor_product_item_id']) && $item['vendor_product_item_id'] !== ''
            ? (int) $item['vendor_product_item_id']
            : null;

        $query = VendorProductItem::query()->lockForUpdate();

        if ($vendorProductItemId) {
            return $query->find($vendorProductItemId);
        }

        $vendorId = isset($item['vendor_id']) && $item['vendor_id'] !== '' ? (int) $item['vendor_id'] : null;
        $productId = isset($item['product_id']) && $item['product_id'] !== '' ? (int) $item['product_id'] : null;

        if (!$vendorId || !$productId) {
            return null;
        }

        $variantId = $this->normalizeVariantId($item['product_variant_id'] ?? null);

        $query->where('vendor_id', $vendorId)
            ->where('product_id', $productId);

        if ($variantId) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->latest('id')->first();
    }

    protected function reserveInventoryForItems(array $items): array
    {
        $aggregated = [];
        $normalizedItems = [];

        foreach ($items as $item) {
            $requestedQuantity = max((int) ($item['quantity'] ?? 0), 0);
            $vendorProductItem = $this->resolveInventoryProductItem($item);
            $fallbackName = trim((string) ($item['name'] ?? 'This product'));

            if (!$vendorProductItem) {
                throw ValidationException::withMessages([
                    'items' => ["{$fallbackName} is no longer available from this vendor."],
                ]);
            }

            $normalizedItems[] = array_merge($item, [
                'vendor_product_item_id' => $vendorProductItem->id,
                'vendor_id' => $vendorProductItem->vendor_id,
                'product_id' => $vendorProductItem->product_id,
                'product_variant_id' => $vendorProductItem->product_variant_id,
            ]);

            if (!isset($aggregated[$vendorProductItem->id])) {
                $aggregated[$vendorProductItem->id] = [
                    'model' => $vendorProductItem,
                    'quantity' => 0,
                    'label' => trim((string) ($vendorProductItem->display_name ?: $vendorProductItem->name ?: $fallbackName)),
                ];
            }

            $aggregated[$vendorProductItem->id]['quantity'] += $requestedQuantity;
        }

        foreach ($aggregated as $entry) {
            $availableQuantity = max((int) ($entry['model']->quantity ?? 0), 0);

            if ($availableQuantity < $entry['quantity']) {
                $message = $availableQuantity <= 0
                    ? "{$entry['label']} is out of stock."
                    : "Only {$availableQuantity} item(s) left for {$entry['label']}.";

                throw ValidationException::withMessages([
                    'items' => [$message],
                ]);
            }
        }

        foreach ($aggregated as $entry) {
            $entry['model']->decrement('quantity', $entry['quantity']);
            $entry['model']->refresh();
            if ((int) $entry['model']->quantity <= 0) {
                try {
                    app(VendorStockNotifier::class)->notifyIfJustWentOutOfStock($entry['model']);
                } catch (\Throwable $e) {
                    // Log::warning('Vendor stock notifier failed after inventory decrement', [
                    //     'vendor_product_item_id' => $entry['model']->id,
                    //     'error' => $e->getMessage(),
                    // ]);
                }
            }
        }

        return $normalizedItems;
    }

    protected function createOrderItems(Order $order, array $items, string $vendorOrderCode): void
    {
        foreach ($items as $item) {
            $snapshot = $this->buildOrderItemSnapshot($item);

            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $snapshot['product_id'],
                'quantity' => $snapshot['quantity'],
                'price_at_order' => $snapshot['customer_price'],
                'product_snapshot' => $snapshot,
                'variant_snapshot' => $this->buildVariantSnapshotFromItem($item),
            ]);

            if (!empty($snapshot['vendor_id'])) {
                DB::table('vendor_orders')->insert([
                    'vendor_id' => $snapshot['vendor_id'],
                    'order_item_id' => $orderItem->id,
                    'vendor_order_code' => $vendorOrderCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'payment_type' => 'required|in:outright,daily,weekly,monthly',
            'custom_amount' => 'nullable|numeric|min:0',
            'delivery_address' => 'required|string|max:255',
            'delivery_latitude' => 'nullable|numeric|between:-90,90',
            'delivery_longitude' => 'nullable|numeric|between:-180,180',
            'pickup_latitude' => 'nullable|numeric|between:-90,90',
            'pickup_longitude' => 'nullable|numeric|between:-180,180',
            'region_id' => 'nullable|string|max:50',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.vendor_price' => 'nullable|numeric|min:0',
            'items.*.weight_kg' => 'nullable|numeric|min:0',
            'items.*.image' => 'nullable|string',
            'items.*.vendor_id' => 'required|integer',
            'items.*.vendor_product_item_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        try {
            DB::beginTransaction();

            $pricingData = $this->calculateOrderPricingData($request, $request->items);
            $totalAmount = $pricingData['total_amount'];
            $installmentCount = null;
            if (in_array($request->payment_type, ['weekly', 'monthly'], true)) {
                $installmentCount = $this->resolveInstallmentCount($request, $request->payment_type);
            }
            $customAmount = $this->resolveCustomAmount($request, $request->payment_type);

            $amountPaid = 0;
            $remainingAmount = $totalAmount;
            $nextDueDate = null;
            $paymentStatus = $request->payment_type === 'outright' ? 'paid' : 'installment';
            $deductedAmount = 0;

            $mainVendorOrderCode = strtoupper(Str::random(8));

            if ($request->payment_type === 'outright') {
                if ($user->main_wallet < $totalAmount) {
                    DB::rollBack();
                    return response()->json(['error' => 'Insufficient funds in main wallet'], 400);
                }

                $amountPaid = $totalAmount;
                $remainingAmount = 0;
                $deductedAmount = $totalAmount;

                $user->main_wallet -= $totalAmount;
                $user->save();
            } else {
                $nextDueDate = match ($request->payment_type) {
                    'daily' => now()->addDay(),
                    'weekly' => now()->addWeek(),
                    'monthly' => now()->addMonth(),
                };

                $installmentAmount = $customAmount ?? $this->firstInstallmentAmount(
                    $totalAmount,
                    $request->payment_type,
                    $installmentCount
                );
                $installmentAmount = min($installmentAmount, $totalAmount);

                if ($user->main_wallet < $installmentAmount) {
                    DB::rollBack();
                    return response()->json(['error' => 'Insufficient funds for first installment'], 400);
                }

                $user->main_wallet -= $installmentAmount;

                $deductedAmount = $installmentAmount;
                $amountPaid = $installmentAmount;
                $remainingAmount = max($totalAmount - $installmentAmount, 0);

                if ($remainingAmount <= 0) {
                    $paymentStatus = 'paid';
                    $nextDueDate = null;
                }

                $user->save();
            }

            $reservedItems = $this->reserveInventoryForItems($request->items);

            $order = Order::create(array_merge([
                'user_id' => $user->id,
                'agent_id' => $user->referred_by_agent_id,
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'payment_type' => $request->payment_type,
                'installment_count' => $installmentCount,
                'custom_amount' => $customAmount,
                'payment_status' => $paymentStatus,
                'amount_paid' => $amountPaid,
                'remaining_amount' => $remainingAmount,
                'next_due_date' => $nextDueDate,
                'vendor_order_code' => $mainVendorOrderCode,
                'delivery_address' => $request->delivery_address,
                'delivery_cycle' => Order::calculateDeliveryCycle(),
                'expected_delivery_date' => Order::calculateExpectedDeliveryDate(),
            ], $this->formatPricingAttributes($pricingData)));

            $this->createOrderItems($order, $reservedItems, $mainVendorOrderCode);

            $transactionDestination = 'main_wallet';

            if ($deductedAmount > 0) {
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'deduction',
                    'source_wallet' => 'main_wallet',
                    'destination_wallet' => $transactionDestination,
                    'amount' => $deductedAmount,
                    'reference' => ($request->payment_type === 'outright' ? 'OUTRIGHT-' : strtoupper($request->payment_type) . '-') . strtoupper(Str::random(8)),
                    'status' => 'successful',
                    'description' => $request->payment_type === 'outright'
                        ? "Outright payment for Order {$order->order_number}"
                        : ucfirst($request->payment_type) . " installment for Order {$order->order_number}",
                ]);
            }

            DB::table('carts')->where('user_id', $user->id)->delete();

            DB::commit();

            try {
                app(VendorOrderPayoutNotifier::class)->notifyIfEligible($order->fresh()->load('items'));
            } catch (\Throwable $e) {
                Log::warning('VendorOrderPayoutNotifier failed after checkout', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => $order->fresh()->load('items'),
                'user' => $user->fresh(),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            $errors = $e->errors();
            if (!empty($errors['installment_count'])) {
                return response()->json([
                    'error' => $errors['installment_count'][0],
                ], 422);
            }
            if (!empty($errors['custom_amount'])) {
                return response()->json([
                    'error' => $errors['custom_amount'][0],
                ], 422);
            }
            $messages = $errors['items'] ?? [$e->getMessage()];

            return response()->json([
                'error' => $messages[0] ?? 'Unable to place order because stock is no longer available.',
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Checkout failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    protected function generateOrderNumber()
    {
        $lastOrder = Order::where('order_number', 'like', 'ORD-%')
            ->orderByRaw('CAST(SUBSTRING(order_number, 5) AS UNSIGNED) DESC')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, 4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return 'ORD-' . $newNumber;
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'payment_type' => 'nullable|in:outright,daily,weekly,monthly',
            'custom_amount' => 'nullable|numeric|min:0',
            'delivery_latitude' => 'nullable|numeric|between:-90,90',
            'delivery_longitude' => 'nullable|numeric|between:-180,180',
            'pickup_latitude' => 'nullable|numeric|between:-90,90',
            'pickup_longitude' => 'nullable|numeric|between:-180,180',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer|exists:order_items,id',
            'items.*.name' => 'required|string|max:255',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.vendor_price' => 'nullable|numeric|min:0',
            'items.*.weight_kg' => 'nullable|numeric|min:0',
            'items.*.image' => 'nullable|string',
            'items.*.vendor_id' => 'nullable|integer',
            'items.*.vendor_product_item_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'installment_count' => 'nullable|integer|min:2|max:48',
        ]);

        try {
            DB::beginTransaction();

            $user = $request->user();
            $paymentType = $request->payment_type ?? $order->payment_type;
            $installmentCount = null;
            if (in_array($paymentType, ['weekly', 'monthly'], true)) {
                $installmentCount = $this->resolveInstallmentCount($request, $paymentType);
            }
            $customAmount = $this->resolveCustomAmount($request, $paymentType);

            $pricingData = $this->calculateOrderPricingData($request, $request->items, [
                'pickup_latitude' => $order->pickup_latitude,
                'pickup_longitude' => $order->pickup_longitude,
                'delivery_latitude' => $order->delivery_latitude,
                'delivery_longitude' => $order->delivery_longitude,
            ]);

            $totalAmount = $pricingData['total_amount'];
            $alreadyPaid = (float) ($order->amount_paid ?? 0);
            $amountPaid = min($alreadyPaid, $totalAmount);
            $nextDueDate = $order->next_due_date;
            $paymentStatus = $order->payment_status;
            $deductedAmount = 0;

            if ($paymentType === 'outright') {
                $additionalCharge = max($totalAmount - $alreadyPaid, 0);

                if ($additionalCharge > 0) {
                    if ($user->main_wallet < $additionalCharge) {
                        DB::rollBack();
                        return response()->json([
                            'error' => 'Insufficient funds to complete outright payment'
                        ], 400);
                    }

                    $user->main_wallet -= $additionalCharge;
                    $user->save();

                    $deductedAmount = $additionalCharge;
                    $amountPaid = $alreadyPaid + $additionalCharge;
                }

                $amountPaid = min($amountPaid, $totalAmount);
                $remainingAmount = max($totalAmount - $amountPaid, 0);
                $nextDueDate = null;
                $paymentStatus = $remainingAmount <= 0 ? 'paid' : 'installment';
            } else {
                if ($paymentType !== $order->payment_type || $alreadyPaid <= 0) {
                    $installmentAmount = $customAmount ?? $this->firstInstallmentAmount(
                        $totalAmount,
                        $paymentType,
                        $installmentCount
                    );
                    $installmentAmount = min($installmentAmount, $totalAmount);

                    if ($installmentAmount > 0) {
                        if ($user->main_wallet < $installmentAmount) {
                            DB::rollBack();
                            return response()->json([
                                'error' => 'Insufficient funds for the selected payment plan',
                            ], 400);
                        }

                        $user->main_wallet -= $installmentAmount;
                        $user->save();

                        $deductedAmount = $installmentAmount;
                        $amountPaid = min($installmentAmount, $totalAmount);
                    }
                }

                $remainingAmount = max($totalAmount - $amountPaid, 0);
                $nextDueDate = match ($paymentType) {
                    'daily' => now()->addDay(),
                    'weekly' => now()->addWeek(),
                    'monthly' => now()->addMonth(),
                };
                $paymentStatus = $remainingAmount <= 0 ? 'paid' : 'installment';

                if ($remainingAmount <= 0) {
                    $nextDueDate = null;
                    $user->save();
                }
            }

            $transactionDestination = 'main_wallet';

            if ($deductedAmount > 0) {
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'deduction',
                    'source_wallet' => 'main_wallet',
                    'destination_wallet' => $transactionDestination,
                    'amount' => $deductedAmount,
                    'reference' => strtoupper($paymentType) . '-' . strtoupper(Str::random(8)),
                    'status' => 'successful',
                    'description' => $paymentType === 'outright'
                        ? "Updated outright payment for Order {$order->order_number}"
                        : ucfirst($paymentType) . " installment for Order {$order->order_number}",
                ]);
            }

            $submittedOrderItemIds = collect($request->items)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($submittedOrderItemIds->isNotEmpty()) {
                $ownedSubmittedItemCount = $order->items()
                    ->whereIn('id', $submittedOrderItemIds)
                    ->count();

                if ($ownedSubmittedItemCount !== $submittedOrderItemIds->count()) {
                    throw ValidationException::withMessages([
                        'items' => ['One or more order items do not belong to this order.'],
                    ]);
                }
            }

            $removedOrderItemIds = $order->items()
                ->when(
                    $submittedOrderItemIds->isNotEmpty(),
                    fn ($query) => $query->whereNotIn('id', $submittedOrderItemIds)
                )
                ->pluck('id');

            if ($removedOrderItemIds->isNotEmpty()) {
                DB::table('vendor_orders')
                    ->whereIn('order_item_id', $removedOrderItemIds)
                    ->delete();

                OrderItem::whereIn('id', $removedOrderItemIds)->delete();
            }

            $order->update(array_merge([
                'payment_type' => $paymentType,
                'installment_count' => in_array($paymentType, ['weekly', 'monthly'], true)
                    ? $installmentCount
                    : null,
                'custom_amount' => $customAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'remaining_amount' => $remainingAmount,
                'next_due_date' => $nextDueDate,
                'payment_status' => $paymentStatus,
            ], $this->formatPricingAttributes($pricingData)));

            foreach ($request->items as $itemData) {
                $snapshot = $this->buildOrderItemSnapshot($itemData);
                $variantSnapshot = $this->buildVariantSnapshotFromItem($itemData);

                if (!empty($itemData['id'])) {
                    $orderItem = $order->items()->whereKey($itemData['id'])->first();
                    $orderItem?->update([
                        'product_id' => $snapshot['product_id'],
                        'quantity' => $snapshot['quantity'],
                        'price_at_order' => $snapshot['customer_price'],
                        'product_snapshot' => $snapshot,
                        'variant_snapshot' => $variantSnapshot,
                    ]);
                } else {
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $snapshot['product_id'],
                        'quantity' => $snapshot['quantity'],
                        'price_at_order' => $snapshot['customer_price'],
                        'product_snapshot' => $snapshot,
                        'variant_snapshot' => $variantSnapshot,
                    ]);

                    if (!empty($snapshot['vendor_id'])) {
                        DB::table('vendor_orders')->insert([
                            'vendor_id' => $snapshot['vendor_id'],
                            'order_item_id' => $orderItem->id,
                            'vendor_order_code' => $order->vendor_order_code,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Order updated successfully',
                'order' => $order->fresh()->load('items'),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            $errors = $e->errors();

            return response()->json([
                'error' => $errors['custom_amount'][0]
                    ?? $errors['installment_count'][0]
                    ?? $errors['items'][0]
                    ?? 'Invalid request data.',
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Update failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function getUserOrders(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        $perPage = $request->query('per_page', 10);
        $statusGroup = strtolower((string) $request->query('status_group', ''));
        $status = strtolower((string) $request->query('status', ''));

        $ordersQuery = Order::where('user_id', $user->id)->with(['items.vendorOrders.vendor', 'rider:id,fullname,phoneno']);

        if ($statusGroup === 'ongoing') {
            // Delivery tracking: only orders that are fully funded.
            $ordersQuery->paidForFulfillment()->whereIn('status', ['pending', 'ready', 'ongoing']);
        } elseif ($statusGroup === 'delivered') {
            $ordersQuery->paidForFulfillment()->where('status', 'delivered');
        } elseif (!empty($status)) {
            if (in_array($status, ['pending', 'ready', 'ongoing', 'delivered'], true)) {
                $ordersQuery->paidForFulfillment();
            }
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery
            ->latest()
            ->paginate($perPage);

        $orders->setCollection(
            $orders->getCollection()->map(function (Order $order) {
                $order->setAttribute(
                    'items_by_vendor',
                    $this->buildVendorPickupGroups($order)
                );

                return $order;
            })
        );

        return response()->json([
            'orders' => $orders->items(),
            'pagination' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'perPage' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function confirmDelivery(Request $request, Order $order, AutomaticPayoutService $automaticPayoutService, AgentCommissionService $agentCommissionService)
    {
        $user = $request->user();

        if (!$user || (int) $order->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'You are not allowed to confirm this order.'
            ], 403);
        }

        if ($order->status !== 'ongoing') {
            return response()->json([
                'message' => 'Order cannot be confirmed yet'
            ], 400);
        }

        DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'delivered',
                'completed_at' => now(),
            ]);

            OrderStatusLog::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'status' => 'delivered',
                ],
                [
                    'message' => 'Order delivered and confirmed by customer.',
                    'fulfilled_at' => now(),
                ]
            );

            // Credit agent wallet with delivery fee upon delivery confirmation
            if ($order->accepted_by) {
                $agent = User::find($order->accepted_by);
                if ($agent && $agent->user_type === 'agent') {
                    $deliveryFee = $order->rider_payout ?? $order->rider_payout ?? 0;
                    if ($deliveryFee > 0) {
                        $agent->increment('main_wallet', $deliveryFee);

                        // Create earning record for the agent
                        AgentEarning::create([
                            'agent_id' => $agent->id,
                            'amount' => $deliveryFee,
                            'order_amount' => $order->total_amount,
                            'earning_type' => 'delivery_fee',
                            'order_id' => $order->id,
                            'earning_type' => 'delivery_fee',
                            'order_number' => $order->order_number,
                            'status' => 'completed',
                        ]);
                    }
                }
            }
        });

        $fresh = $order->fresh(['items.vendorOrders.vendor', 'user']);
        // $riderPayout = $automaticPayoutService->processRiderPayoutForOrder($fresh);
        // $vendorCatchUp = $automaticPayoutService->processVendorPayoutsIfOutstanding(
        //     $order->fresh(['items.vendorOrders.vendor', 'user'])
        // );

        $orderForCommission = $order->fresh(['user']);
        $agentCommissionService->creditCustomerOrderOnDeliveryConfirm($orderForCommission);
        $agentCommissionService->creditAgentReferralAfterPayout($orderForCommission, $riderPayout);

        return response()->json([
            'message' => 'Order confirmed successfully',
            'payouts' => [
                'rider_payout' => $riderPayout,
                // 'vendor_payouts' => $vendorCatchUp,
            ],
        ]);
    }

    public function getOrderDetails($orderId, Request $request)
    {
        Auth::shouldUse('api');
        $userId = Auth::check() ? Auth::id() : null;
        $sessionId = $userId ? null : $this->getSessionId($request);

        try {
            $order = Order::with(['items.vendorOrders.vendor', 'statusLogs', 'rider:id,fullname,phoneno'])
                ->where('id', $orderId)
                ->when($userId, fn($q) => $q->where('user_id', $userId))
                ->when(!$userId, fn($q) => $q->where('session_id', $sessionId))
                ->firstOrFail();

            $order->setAttribute(
                'items_by_vendor',
                $this->buildVendorPickupGroups($order)
            );

            return response()->json([
                'order' => $order,
                'stages' => $order->statusLogs
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => true,
                'message' => 'Order not found.',
            ], 404);
        }
    }

    protected function getDefaultOrderStages(): array
    {
        $now = now();

        return [
            [
                'status' => 'confirmed',
                'message' => 'Your order has been received and confirmed. Weâ€™re preparing it for processing.',
                'fulfilled_at' => $now,
            ],
            [
                'status' => 'preparing',
                'message' => 'Your order is being prepared. Weâ€™ll notify you once itâ€™s ready to ship.',
                'fulfilled_at' => null,
            ],
            [
                'status' => 'packed',
                'message' => 'Your order is packed and ready to leave our store.',
                'fulfilled_at' => null,
            ],
            [
                'status' => 'shipped',
                'message' => 'Your order is on its way!',
                'fulfilled_at' => null,
            ],
            [
                'status' => 'delivered',
                'message' => 'Delivered',
                'fulfilled_at' => null,
            ],
        ];
    }

    public function getAllOrdersForAdmin(Request $request)
    {
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search');
        $status = $request->query('status');
        $paymentStatus = $request->query('payment_status');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $ordersQuery = Order::with(['user', 'shippingAddress', 'vendorOrders.vendor'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%$search%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('fullname', 'like', "%$search%")
                                ->orWhere('email', 'like', "%$search%");
                        });
                });
            })
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($paymentStatus, fn($q) => $q->where('payment_status', $paymentStatus))
            ->when($startDate, fn($q) => $q->whereDate('created_at', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('created_at', '<=', $endDate))
            ->latest();

        $orders = $ordersQuery->paginate($perPage);

        // Format orders
        $formattedOrders = $orders->map(function ($order) {
            $customer = optional($order->shipping_address_snapshot)['first_name'] . ' ' .
                optional($order->shipping_address_snapshot)['last_name'];
            $vendorNames = $order->vendorOrders
                ->map(fn($vendorOrder) => $vendorOrder->vendor?->store_name ?? $vendorOrder->vendor?->fullname)
                ->filter()
                ->unique()
                ->values();
            $vendorLabel = null;
            if ($vendorNames->count() === 1) {
                $vendorLabel = $vendorNames->first();
            } elseif ($vendorNames->count() > 1) {
                $vendorLabel = "Multiple ({$vendorNames->count()})";
            }

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'date' => $order->created_at,
                'customer' => trim($customer) ?: optional($order->user)->fullname,
                'vendor' => $vendorLabel,
                'vendors' => $vendorNames,
                'items_count' => (int) ($order->item_count ?? 0),
                'total' => $order->total_amount,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
            ];
        });

        // Dashboard Stats
        $statsQuery = clone $ordersQuery;
        $totalOrders = (clone $statsQuery)->count();
        $pendingOrders = (clone $statsQuery)->where('status', 'ongoing')->count();
        $completedOrders = (clone $statsQuery)->where('status', 'delivered')->count();
        $avgOrderValue = (clone $statsQuery)->avg('total_amount');

        return response()->json([
            'data' => $formattedOrders,
            'pagination' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'perPage' => $orders->perPage(),
                'total' => $orders->total(),
            ],
            'stats' => [
                'totalOrders' => $totalOrders,
                'pendingOrders' => $pendingOrders,
                'completedOrders' => $completedOrders,
                'avgOrderValue' => round($avgOrderValue, 2),
            ]
        ]);
    }

    public function getOrderDetailsForAdmin($orderId)
    {
        try {
            $order = Order::with([
                'items',
                'user',
                'shippingAddress',
                'statusLogs',
                'vendorOrders.vendor',
            ])->findOrFail($orderId);

            $shipping = $order->shipping_address_snapshot ?? [];
            $customerName = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
            $vendorOrdersByItem = $order->vendorOrders->keyBy('order_item_id');
            $mappedItems = $order->items->map(function ($item) use ($vendorOrdersByItem) {
                $vendorOrder = $vendorOrdersByItem->get($item->id);
                $vendor = $vendorOrder?->vendor;
                $vendorName = $vendor ? ($vendor->store_name ?? $vendor->fullname) : null;

                return [
                    'product_id' => $item->product_id,
                    'name' => $item->product_snapshot['name'] ?? null,
                    'image' => $item->product_snapshot['image'] ?? null,
                    'vendor_id' => $vendorOrder?->vendor_id,
                    'vendor_name' => $vendorName,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->price_at_order,
                    'total' => (float) ($item->quantity * $item->price_at_order),
                ];
            })->values();

            $vendorGroups = $mappedItems
                ->groupBy(fn($item) => $item['vendor_id'] ?? 'unknown')
                ->map(function ($items) {
                    $first = $items->first();

                    return [
                        'vendor_id' => $first['vendor_id'] ?? null,
                        'vendor_name' => $first['vendor_name'] ?? 'Unknown Store',
                        'items_count' => (int) $items->sum('quantity'),
                        'total' => (float) $items->sum('total'),
                        'items' => $items->map(function ($item) {
                            return [
                                'product_id' => $item['product_id'],
                                'name' => $item['name'],
                                'image' => $item['image'],
                                'quantity' => $item['quantity'],
                                'unit_price' => $item['unit_price'],
                                'total' => $item['total'],
                            ];
                        })->values(),
                    ];
                })
                ->values();

            $response = [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'created_at' => $order->created_at->toDateTimeString(),
                'notes' => $order->note ?? null,
                'items_count' => (int) ($order->item_count ?? $order->items->sum('quantity')),
                'vendors' => $order->vendorOrders
                    ->map(fn($vendorOrder) => $vendorOrder->vendor?->store_name ?? $vendorOrder->vendor?->fullname)
                    ->filter()
                    ->unique()
                    ->values(),

                'order_items' => $mappedItems->toArray(),
                'vendor_groups' => $vendorGroups->toArray(),

                'summary' => [
                    'subtotal' => $order->total_amount,
                    'discount' => $order->discount ?? 0,
                    'tax' => $order->tax ?? 0,
                    'shipping_fee' => $order->shipping_fee ?? 0,
                    'total' => ($order->total_amount - ($order->discount ?? 0)) + ($order->tax ?? 0) + ($order->shipping_fee ?? 0),
                ],

                'shipping' => [
                    'method' => $order->shipping_method ?? 'Not specified',
                    'tracking_number' => $order->tracking_number ?? null,
                    'estimated_delivery' => $order->estimated_delivery ?? null,
                    'status' => $order->delivery_status ?? null,
                ],

                'customer' => [
                    'name' => $customerName ?: optional($order->user)->fullname,
                    'email' => $shipping['email'] ?? optional($order->user)->email,
                    'phone' => $shipping['phone_number'] ?? optional($order->user)->phoneno,
                    'address' => $order->user->address ?? null,
                    'registered' => $order->user_id ? 'Yes' : 'No',
                ],

                'shipping_address' => [
                    'line_1' => $shipping['address_line_1'] ?? null,
                    'line_2' => $shipping['address_line_2'] ?? null,
                    'city' => $shipping['city'] ?? null,
                    'state' => $shipping['state'] ?? null,
                    'country' => $shipping['country'] ?? null,
                    'postal_code' => $shipping['postal_code'] ?? null,
                ],

                'status_logs' => $order->statusLogs->map(function ($log) {
                    return [
                        'status' => $log->status,
                        'message' => $log->message,
                        'fulfilled_at' => optional($log->fulfilled_at)->toDateTimeString(),
                    ];
                }),
            ];

            return response()->json(['order' => $response]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => true,
                'message' => 'Order not found',
            ], 404);
        }
    }

    public function updateOrderStatusForAdmin(Request $request, $orderId)
    {
        $request->validate(['status' => 'required|in:pending,confirmed,processing,delivered,cancelled,ongoing,completed']);

        try {
            $order = Order::findOrFail($orderId);
            $order->status = $request->status;
            $order->save();

            OrderStatusLog::create([
                'order_id' => $order->id,
                'status' => $request->status,
                'message' => 'Status updated by admin',
                'fulfilled_at' => now(),
            ]);

            return response()->json([
                'message' => 'Order status updated successfully',
                'data' => ['id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => true,
                'message' => 'Order not found.',
            ], 404);
        }
    }
    public function userTransactions(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => true,
                'message' => 'User not authenticated',
            ], 401);
        }

        $perPage = (int) $request->query('per_page', 20);
        $type = $request->query('type');
        $status = $request->query('status');

        $transactionsQuery = Transaction::with('order')
            ->where('user_id', $user->id)
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($status, fn($q) => $q->where('status', $status))
            ->latest();

        $transactions = $transactionsQuery->paginate($perPage);

        $rows = $transactions->getCollection()->map(function ($transaction) {
            $order = $transaction->order;
            $paymentType = $order?->payment_type;
            $installmentCount = $order?->installment_count;
            $installmentLabel = null;

            if ($paymentType === 'daily') {
                $installmentLabel = 'Daily';
            } elseif ($paymentType === 'weekly' && $installmentCount) {
                $installmentLabel = $installmentCount . ' weeks';
            } elseif ($paymentType === 'monthly' && $installmentCount) {
                $installmentLabel = $installmentCount . ' months';
            }

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'source_wallet' => $transaction->source_wallet,
                'destination_wallet' => $transaction->destination_wallet,
                'amount' => (float) $transaction->amount,
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'description' => $transaction->description,
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
                'order_id' => $transaction->order_id,
                'order_number' => $order?->order_number,
                'order_status' => $order?->status,
                'payment_status' => $order?->payment_status,
                'payment_type' => $paymentType,
                'installment_count' => $installmentCount,
                'installment_label' => $installmentLabel,
                'custom_amount' => $order?->custom_amount,
            ];
        });

        return response()->json([
            'error' => false,
            'message' => 'Transactions retrieved successfully',
            'data' => $rows,
            'pagination' => [
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'perPage' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function reorder(Request $request, $orderId)
    {
        $request->validate([
            'payment_type' => 'required|in:outright,daily,weekly,monthly',
            'custom_amount' => 'nullable|numeric|min:0',
            'installment_count' => 'nullable|integer|min:2|max:48',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        $oldOrder = Order::with('items')
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('remaining_amount', 0)
                    ->orWhere('payment_status', 'paid');
            })
            ->first();

        if (!$oldOrder) {
            return response()->json(['error' => 'Order not found or not completed'], 404);
        }

        $itemsPayload = $oldOrder->items->map(function ($item) {
            $snapshot = is_array($item->product_snapshot)
                ? $item->product_snapshot
                : (json_decode($item->product_snapshot ?? '[]', true) ?: []);

            $customerPrice = (float) ($snapshot['customer_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);
            $vendorPrice = (float) ($snapshot['vendor_price'] ?? $customerPrice);

            return [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'name' => $snapshot['name'] ?? null,
                'price' => $customerPrice,
                'vendor_price' => $vendorPrice,
                'weight_kg' => $snapshot['weight_kg'] ?? 0,
                'image' => $snapshot['image'] ?? null,
                'vendor_id' => $snapshot['vendor_id'] ?? null,
                'vendor_product_item_id' => $snapshot['vendor_product_item_id'] ?? null,
                'product_variant_id' => $snapshot['product_variant_id'] ?? null,
            ];
        })->values()->all();

        try {
            DB::beginTransaction();

            $pricingData = $this->calculateOrderPricingData($request, $itemsPayload, [
                'pickup_latitude' => $oldOrder->pickup_latitude,
                'pickup_longitude' => $oldOrder->pickup_longitude,
                'delivery_latitude' => $oldOrder->delivery_latitude,
                'delivery_longitude' => $oldOrder->delivery_longitude,
            ]);

            $totalAmount = $pricingData['total_amount'];
            $installmentCount = null;
            if (in_array($request->payment_type, ['weekly', 'monthly'], true)) {
                $installmentCount = $this->resolveInstallmentCount($request, $request->payment_type);
            }
            $customAmount = $this->resolveCustomAmount($request, $request->payment_type);

            $amountPaid = 0;
            $remainingAmount = $totalAmount;
            $nextDueDate = null;
            $paymentStatus = $request->payment_type === 'outright' ? 'paid' : 'installment';
            $deductedAmount = 0;

            if ($request->payment_type === 'outright') {
                if ($user->main_wallet < $totalAmount) {
                    DB::rollBack();
                    return response()->json(['error' => 'Insufficient funds in main wallet'], 400);
                }

                $user->main_wallet -= $totalAmount;
                $user->save();

                $deductedAmount = $totalAmount;
                $amountPaid = $totalAmount;
                $remainingAmount = 0;
            } else {
                $nextDueDate = match ($request->payment_type) {
                    'daily' => now()->addDay(),
                    'weekly' => now()->addWeek(),
                    'monthly' => now()->addMonth(),
                };

                $installmentAmount = $customAmount ?? $this->firstInstallmentAmount(
                    $totalAmount,
                    $request->payment_type,
                    $installmentCount
                );
                $installmentAmount = min($installmentAmount, $totalAmount);

                if ($user->main_wallet < $installmentAmount) {
                    DB::rollBack();
                    return response()->json(['error' => 'Insufficient funds for first installment'], 400);
                }

                $user->main_wallet -= $installmentAmount;

                $deductedAmount = $installmentAmount;
                $amountPaid = $installmentAmount;
                $remainingAmount = max($totalAmount - $installmentAmount, 0);

                if ($remainingAmount <= 0) {
                    $paymentStatus = 'paid';
                    $nextDueDate = null;
                }

                $user->save();
            }

            $reservedItems = $this->reserveInventoryForItems($itemsPayload);

            $newOrder = Order::create(array_merge([
                'user_id' => $user->id,
                'agent_id' => $user->referred_by_agent_id,
                'payment_type' => $request->payment_type,
                'installment_count' => $installmentCount,
                'custom_amount' => $customAmount,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'order_number' => $this->generateOrderNumber(),
                'vendor_order_code' => strtoupper(Str::random(8)),
                'remaining_amount' => $remainingAmount,
                'payment_status' => $paymentStatus,
                'next_due_date' => $nextDueDate,
                'status' => 'pending',
                'delivery_address' => $oldOrder->delivery_address,
                'delivery_cycle' => Order::calculateDeliveryCycle(),
                'expected_delivery_date' => Order::calculateExpectedDeliveryDate(),
            ], $this->formatPricingAttributes($pricingData)));

            $this->createOrderItems($newOrder, $reservedItems, $newOrder->vendor_order_code);

            $transactionDestination = 'main_wallet';

            if ($deductedAmount > 0) {
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $newOrder->id,
                    'type' => 'deduction',
                    'source_wallet' => 'main_wallet',
                    'destination_wallet' => $transactionDestination,
                    'amount' => $deductedAmount,
                    'reference' => $newOrder->order_number,
                    'status' => 'successful',
                    'description' => "Reorder placed successfully for Order #{$oldOrder->order_number}",
                ]);
            }

            DB::commit();

            try {
                app(VendorOrderPayoutNotifier::class)->notifyIfEligible($newOrder->fresh()->load('items'));
            } catch (\Throwable $e) {
                Log::warning('VendorOrderPayoutNotifier failed after reorder', [
                    'order_id' => $newOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Reorder successful',
                'order' => $newOrder->fresh()->load('items'),
                'new_balance' => $user->fresh()->main_wallet,
                'vendor_code' => $newOrder->vendor_order_code,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            $errors = $e->errors();
            if (!empty($errors['installment_count'])) {
                return response()->json([
                    'error' => $errors['installment_count'][0],
                ], 422);
            }
            if (!empty($errors['custom_amount'])) {
                return response()->json([
                    'error' => $errors['custom_amount'][0],
                ], 422);
            }
            $messages = $errors['items'] ?? [$e->getMessage()];

            return response()->json([
                'error' => 'Unable to reorder because stock is no longer available.',
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Reorder failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Single line item row including vendor/store labels for rider + customer views.
     */
    protected function formatOrderItemRowWithVendor(OrderItem $item): array
    {
        $snapshot = is_array($item->product_snapshot)
            ? $item->product_snapshot
            : (json_decode($item->product_snapshot ?? '[]', true) ?: []);

        $vo = $item->vendorOrders->first();
        $vendor = $vo?->vendor;

        $storeName = $vendor?->store_name;
        $vendorLabel = $storeName ?: ($vendor?->fullname ?? null);

        return [
            'id' => $item->id,
            'name' => $snapshot['name'] ?? null,
            'image' => $snapshot['image'] ?? null,
            'quantity' => $item->quantity,
            'price' => $item->price_at_order,
            'status' => $item->status,
            'vendor_id' => $vendor?->id,
            'vendor_name' => $vendorLabel,
            'store_name' => $storeName,
        ];
    }

    /**
     * Items grouped by vendor/store for multi-stop pickup and tracking UIs.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildVendorPickupGroups(Order $order): array
    {
        $groups = [];

        foreach ($order->items as $item) {
            $row = $this->formatOrderItemRowWithVendor($item);
            $vid = (int) ($row['vendor_id'] ?? 0);
            $key = $vid > 0 ? 'v_'.$vid : 'item_'.$item->id;

            if (! isset($groups[$key])) {
                $vo = $item->vendorOrders->first();
                $vendor = $vo?->vendor;

                $groups[$key] = [
                    'vendor_id' => $vid > 0 ? $vid : null,
                    'vendor_name' => $row['vendor_name'],
                    'store_name' => $row['store_name'],
                    'vendor_phone' => $vendor?->phoneno ?? null,
                    'pickup_address' => $vendor?->address ?? null,
                    'pickup_latitude' => $vendor?->latitude ?? null,
                    'pickup_longitude' => $vendor?->longitude ?? null,
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = $row;
        }

        return array_values($groups);
    }



public function availablePickups(Request $request)
{
    $agent = $request->user();

    if ($agent->user_type !== 'agent') {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    if (!$agent->is_delivery_agent) {
        return response()->json([
            'error' => false,
            'message' => 'You are not yet an approved delivery agent.',
            'data' => [],
        ]);
    }

    // Catch-up assignment for ready orders, including stale offers created before
    // assignment used vendor pickup coordinates.
    $assignmentService = app(RiderAssignmentService::class);
    $readyOrders = Order::where('status', 'ready')
        ->paidForFulfillment()
        ->latest()
        ->take(50)
        ->get();

    foreach ($readyOrders as $readyOrder) {
        $assignmentService->assignNearestRider($readyOrder, true);
    }

    $agentTier = (int) ($agent->delivery_tier ?? 1);

    $orders = Order::with(['items.vendorOrders.vendor', 'user'])
        ->where('status', 'ready')
        ->paidForFulfillment()
        ->where('accepted_by', $agent->id)
        ->get()
        ->filter(function ($order) use ($agentTier) {
        return DeliveryTier::tierCanHandle($agentTier, $order->fulfillmentAmount());
    })
        ->map(function ($order) {
            $vendorGroups = $this->buildVendorPickupGroups($order);
            $stopCount = count($vendorGroups);
            $first = $vendorGroups[0] ?? null;

            $summaryVendorName = $stopCount > 1
                ? 'Multiple stores ('.$stopCount.' pickups)'
                : ($first['vendor_name'] ?? null);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'multi_vendor' => $stopCount > 1,
                'vendor_stop_count' => $stopCount,

                'vendor_name' => $summaryVendorName,
                'vendor_phone' => $stopCount === 1 ? ($first['vendor_phone'] ?? null) : null,
                'pickup_address' => $stopCount === 1 ? ($first['pickup_address'] ?? null) : null,
                'vendor_address' => $stopCount === 1 ? ($first['pickup_address'] ?? null) : null,
                'pickup_latitude' => $first['pickup_latitude'] ?? null,
                'pickup_longitude' => $first['pickup_longitude'] ?? null,

                'customer_name' => $order->user->fullname ?? null,
                'customer_phone' => $order->user->phoneno ?? null,
                'dropoff_address' => $order->delivery_address,
                'customer_address' => $order->delivery_address,
                'delivery_latitude' => $order->delivery_latitude,
                'delivery_longitude' => $order->delivery_longitude,

                'status' => $order->status,
                'accepted_by' => $order->accepted_by,

                'items' => $order->items->map(fn ($item) => $this->formatOrderItemRowWithVendor($item))->values(),
                'vendor_pickup_stops' => $vendorGroups,
            ];
        })
        ->values();

    return response()->json([
        'error' => false,
        'message' => 'Available pickups fetched successfully',
        'data' => $orders,
    ]);
}

   protected function success($data = null, string $message = 'Success', int $status = 200)
    {
        return response()->json([
            'error' => false,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function failure(string $message, int $status = 400, $data = null)
    {
        return response()->json([
            'error' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

public function acceptDelivery(Request $request, $orderId, AutomaticPayoutService $automaticPayoutService, AgentCommissionService $agentCommissionService)
{
    $agent = $request->user();

    if ($agent->user_type !== 'agent') {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    if (!$agent->is_delivery_agent) {
        return response()->json(['error' => 'You are not an approved delivery agent.'], 403);
    }

    $agent->loadMissing('agentBankDetails');

    if (!$agent->agentBankDetails) {
            return $this->failure(
        'Please add your bank account details before accepting deliveries.',
        422
    );
    }

    $order = Order::with('items')
        ->where('id', $orderId)
        ->where('status', 'ready')
        ->paidForFulfillment()
        ->where(function ($q) use ($agent) {
            $q->whereNull('accepted_by')
                ->orWhere('accepted_by', $agent->id);
        })
        ->first();

    if (!$order) {
        return response()->json(['error' => 'Order already taken or not available'], 400);
    }

$agentTier = (int) ($agent->delivery_tier ?? 1);
if (!\App\Support\DeliveryTier::tierCanHandle($agentTier, $order->fulfillmentAmount())) {
    return response()->json([
        'error' => 'This order exceeds your current delivery tier limit. Upgrade your tier to accept larger orders.',
    ], 403);
}
if (!$order) {
    return $this->failure(
        'Order already taken or not available.',
        400
    );
}

    $order->update([
        'accepted_by' => $agent->id,
        'status' => 'ongoing',
    ]);

    $vendorPayouts = $automaticPayoutService->processVendorPayoutsForOrder(
        $order->fresh(['items.vendorOrders.vendor', 'user'])
    );

    $agentCommissionService->creditVendorReferralsAfterPayout(
        $order->fresh(['items.vendorOrders.vendor', 'user']),
        $vendorPayouts
    );
return $this->success([
    'order_id' => $order->id,
    'status' => $order->status,
    'vendor_payouts' => $vendorPayouts,
], 'Delivery accepted successfully');
}
    public function myPickups(Request $request)
    {
        $rider = $request->user();

       if ($rider->user_type !== 'agent') {  
        return response()->json(['error' => 'Unauthorized'], 403);
    }

        $orders = Order::with(['items.vendorOrders.vendor', 'user']) // include vendor + customer
            ->where('accepted_by', $rider->id)
            ->paidForFulfillment()
            ->whereIn('status', ['ongoing', 'delivered'])
            ->get()
            ->map(function ($order) {
                $vendorGroups = $this->buildVendorPickupGroups($order);
                $stopCount = count($vendorGroups);
                $first = $vendorGroups[0] ?? null;

                $summaryVendorName = $stopCount > 1
                    ? 'Multiple stores ('.$stopCount.' pickups)'
                    : ($first['vendor_name'] ?? null);

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'multi_vendor' => $stopCount > 1,
                    'vendor_stop_count' => $stopCount,

                    'vendor_name' => $summaryVendorName,
                    'vendor_phone' => $stopCount === 1 ? ($first['vendor_phone'] ?? null) : null,
                    'vendor_address' => $stopCount === 1 ? ($first['pickup_address'] ?? null) : null,
                    'pickup_address' => $stopCount === 1 ? ($first['pickup_address'] ?? null) : null,
                    'pickup_latitude' => $first['pickup_latitude'] ?? null,
                    'pickup_longitude' => $first['pickup_longitude'] ?? null,

                    'customer_name' => $order->user->fullname ?? null,
                    'customer_phone' => $order->user->phoneno ?? null,
                    'customer_address' => $order->delivery_address,
                    'delivery_latitude' => $order->delivery_latitude,
                    'delivery_longitude' => $order->delivery_longitude,

                    'status' => $order->status,
                    'accepted_by' => $order->accepted_by,

                    'items' => $order->items->map(fn ($item) => $this->formatOrderItemRowWithVendor($item))->values(),
                    'vendor_pickup_stops' => $vendorGroups,
                ];
            });

      return response()->json([
    'error' => false,
    'message' => 'My pickups fetched successfully',
    'data' => $orders,
]);
    }
}

