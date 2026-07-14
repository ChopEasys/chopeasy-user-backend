<?php

namespace App\Http\Controllers\v1\Users;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Events\OrderReadyForPickup;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Notifications\NewPickupNotification;
use App\Services\RiderAssignmentService;
use App\Support\VendorOrderSettlement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class VendorOrderController extends Controller
{
    protected function decodePayload($payload): array
    {
        return VendorOrderSettlement::decodePayload($payload);
    }

    protected function vendorSettlementForOrder(Order $order, float $grossAmount): array
    {
        return VendorOrderSettlement::forGross($order, $grossAmount);
    }

    public function vendorOrders(Request $request)
    {
        $user = $request->user();

        if ($user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $orders = Order::with([
            'user',
            'items.vendorOrders' => function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            }
        ])
            ->paidForFulfillment()
            ->whereHas('items.vendorOrders', function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            })
            ->latest()
            ->get()
            ->map(function ($order) use ($user) {
                $vendorItems = $order->items->filter(function ($item) use ($user) {
                    return $item->vendorOrders->where('vendor_id', $user->id)->isNotEmpty();
                });

                $vendorTotal = $vendorItems->sum(function ($item) {
                    $snapshot = is_array($item->product_snapshot)
                        ? $item->product_snapshot
                        : (json_decode($item->product_snapshot ?? '[]', true) ?: []);

                    $vendorUnitPrice = (float) ($snapshot['vendor_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);
                    return $vendorUnitPrice * (int) ($item->quantity ?? 0);
                });
                $settlement = $this->vendorSettlementForOrder($order, (float) $vendorTotal);

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'created_at' => $order->created_at,
                    'user' => [
                        'id' => $order->user?->id,
                        'fullname' => $order->user?->fullname,
                    ],
                    'vendor_total_amount' => $settlement['gross_amount'],
                    'vendor_take_percent' => $settlement['take_percent'],
                    'vendor_take_amount' => $settlement['take_amount'],
                    'vendor_net_amount' => $settlement['net_amount'],
                    'customer_total_amount' => round((float) ($order->total_amount ?? 0), 2),
                ];
            })
            ->values();

        return response()->json($orders);
    }

    public function orderItems(Request $request, $orderId)
    {
        $user = $request->user();

        if ($user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $order = Order::with([
            'items.vendorOrders' => function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            }
        ])
            ->where('id', $orderId)
            ->paidForFulfillment()
            ->whereHas('items.vendorOrders', function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            })
            ->firstOrFail();

        $vendorItems = $order->items->filter(function ($item) use ($user) {
            return $item->vendorOrders->where('vendor_id', $user->id)->isNotEmpty();
        });

        $formatted = $vendorItems->map(function ($item) {
            $snapshot = is_array($item->product_snapshot)
                ? $item->product_snapshot
                : (json_decode($item->product_snapshot ?? '[]', true) ?: []);

            $vendorUnitPrice = (float) ($snapshot['vendor_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);
            $customerUnitPrice = (float) ($snapshot['customer_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $vendorUnitPrice,
                'customer_price' => $customerUnitPrice,
                'total' => round($vendorUnitPrice * (int) ($item->quantity ?? 0), 2),
                'name' => $snapshot['name'] ?? null,
                'image' => $snapshot['image'] ?? null,
                'snapshot' => $snapshot,
                'order_item_id' => $item->id ?? null,
                'item_status' => $item->status ?? null,
                'order_status' => $item->order->status ?? null,
                'vendor_orders' => $item->vendorOrders,
            ];
        });

        return response()->json($formatted->values());
    }

    public function toggleItemReady(Request $request, $orderItemId)
    {
        $user = $request->user();

        if ($user->user_type !== 'vendor') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $orderItem = OrderItem::with('order')
            ->where('id', $orderItemId)
            ->whereHas('vendorOrders', function ($q) use ($user) {
                $q->where('vendor_id', $user->id);
            })
            ->firstOrFail();

        $order = $orderItem->order;

        if (!$order->isPaidForFulfillment()) {
            return response()->json([
                'message' => 'This order is not fully paid yet. Items cannot be marked ready.',
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
            ], 403);
        }

        // Repair stale rows where orders.status is "ready" but some line items are not ready (multi-vendor bugs).
        $hasGloballyUnreadyItems = $order->items()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'ready'))
            ->exists();

        if ($order->status === 'ready' && $hasGloballyUnreadyItems) {
            $order->update(['status' => 'pending']);
            $order->refresh();
        }

        if (in_array($order->status, ['ready', 'delivered', 'completed', 'cancelled'], true)) {
            return response()->json([
                'message' => 'This order can no longer be updated.',
                'order_status' => $order->status,
                'item_status' => $orderItem->status,
                'order_id' => $order->id,
            ], 403);
        }

        $newStatus = $orderItem->status === 'ready' ? null : 'ready';
        $orderItem->update(['status' => $newStatus]);

        $order->refresh();

        // Order-level "ready" must mean every line item (every vendor) is ready — never flip global ready for one vendor only.
        $orderFullyReady = !$order->items()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'ready'))
            ->exists();

        if ($orderFullyReady) {
            // Move to rider workflow only when nobody has picked up yet.
            // if ($order->status === 'pending') {
            //     $order->update(['status' => 'ready']);
            //     $order->refresh();

            //     $assignmentService = app(RiderAssignmentService::class);
            //     $assignedRider = $assignmentService->assignNearestRider($order->fresh());

            //     // Notify all eligible agents that a delivery is available
            //     try {
            //         $assignmentService->notifyEligibleAgents($order->fresh());
            //     } catch (\Throwable $e) {
            //         Log::warning('Failed to notify eligible agents of delivery availability', [
            //             'order_id' => $order->id,
            //             'error' => $e->getMessage(),
            //         ]);
            //     }

            //     if ($assignedRider) {
            //         if (Schema::hasTable('notifications')) {
            //             try {
            //                 Notification::send($assignedRider, new NewPickupNotification($order->fresh()));
            //             } catch (\Throwable $e) {
            //                 Log::warning('Failed to persist pickup notification; falling back to event.', [
            //                     'order_id' => $order->id,
            //                     'rider_id' => $assignedRider->id ?? null,
            //                     'error' => $e->getMessage(),
            //                 ]);
            //                 event(new OrderReadyForPickup($order));
            //             }
            //         } else {
            //             Log::warning('notifications table missing; using pickup event fallback.', [
            //                 'order_id' => $order->id,
            //                 'rider_id' => $assignedRider->id ?? null,
            //             ]);
            //             event(new OrderReadyForPickup($order));
            //         }
            //     } else {
            //         event(new OrderReadyForPickup($order));
            //     }
            // }
            if ($order->status === 'pending') {
    $order->update(['status' => 'ready']);
    $order->refresh();

    $assignmentService = app(RiderAssignmentService::class);

    // No pre-assignment: leave accepted_by null so every eligible agent
    // sees this in availablePickups. First to call acceptDelivery wins.
    try {
        $assignmentService->notifyEligibleAgents($order->fresh());
    } catch (\Throwable $e) {
        Log::warning('Failed to notify eligible agents of delivery availability', [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
        ]);
    }

    event(new OrderReadyForPickup($order));
}
        } elseif ($order->status === 'ready') {
            // No longer fully ready (unmarked item, etc.) — pull back from rider queue.
            $order->update(['status' => 'pending']);
            $order->refresh();
        }

        $orderItem->refresh();

        return response()->json([
            'message' => $newStatus === 'ready' ? 'Item marked ready' : 'Item unmarked',
            'order_status' => $order->status,
            'item_status' => $orderItem->status,
            'order_id' => $order->id,
        ]);
    }
}
