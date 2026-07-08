<?php

namespace App\Services;

use App\Constants\PushNotificationType;
use App\Models\Order;
use App\Models\User;
use App\Notifications\VendorOrderPayoutNotification;
use App\Support\VendorOrderSettlement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VendorOrderPayoutNotifier
{
    /**
     * Notify vendors of order payout only when the customer has fully funded the order
     * (outright paid, or installment plan with no remaining balance).
     *
     * Guard: do NOT send if remaining_amount > 0 AND payment_status is not "paid".
     */
    public function notifyIfEligible(Order $order): void
    {
        if (!$order->isPaidForFulfillment()) {
            return;
        }

        if (!Schema::hasTable('notifications')) {
            return;
        }

        $order->loadMissing('items');
        $vendorTotals = [];

        foreach ($order->items as $item) {
            $snapshot = is_array($item->product_snapshot)
                ? $item->product_snapshot
                : (json_decode($item->product_snapshot ?? '[]', true) ?: []);

            $vendorId = isset($snapshot['vendor_id']) ? (int) $snapshot['vendor_id'] : null;
            if (!$vendorId) {
                continue;
            }

            $vendorUnitPrice = (float) ($snapshot['vendor_price'] ?? $snapshot['price'] ?? $item->price_at_order ?? 0);
            $vendorTotals[$vendorId] = ($vendorTotals[$vendorId] ?? 0) + $vendorUnitPrice * (int) ($item->quantity ?? 0);
        }

        $pushDispatchService = app(PushDispatchService::class);

        foreach ($vendorTotals as $vendorId => $gross) {
            $settlement = VendorOrderSettlement::forGross($order, (float) $gross);
            $vendor = User::find($vendorId);
            if (!$vendor || $vendor->user_type !== 'vendor') {
                continue;
            }

            // Send database (in-app) notification
            try {
                $vendor->notify(new VendorOrderPayoutNotification(
                    (int) $order->id,
                    (string) $order->order_number,
                    $settlement
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send vendor payout notification', [
                    'order_id' => $order->id,
                    'vendor_id' => $vendorId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send push notification to vendor
            try {
                $fmt = static fn (float $n): string => number_format($n, 2, '.', ',');

                $grossAmount = (float) ($settlement['gross_amount'] ?? 0);
                $takePercent = (float) ($settlement['take_percent'] ?? 0);
                $takeAmount = (float) ($settlement['take_amount'] ?? 0);
                $netAmount = (float) ($settlement['net_amount'] ?? 0);

                $body = $takeAmount > 0.0001
                    ? "Order {$order->order_number}: subtotal ₦{$fmt($grossAmount)}. Platform take {$fmt($takePercent)}% (₦{$fmt($takeAmount)}). Estimated payout: ₦{$fmt($netAmount)}."
                    : "Order {$order->order_number}: subtotal ₦{$fmt($grossAmount)}. Estimated payout: ₦{$fmt($netAmount)}.";

                $pushDispatchService->dispatch(
                    $vendor->id,
                    PushNotificationType::VENDOR_NEW_ORDER,
                    [
                        'title' => "New Order — {$order->order_number}",
                        'body' => $body,
                        'url' => "/vendor/orders/{$order->id}",
                        'type' => PushNotificationType::VENDOR_NEW_ORDER,
                        'data' => [
                            'order_id' => (int) $order->id,
                            'order_number' => (string) $order->order_number,
                            'vendor_gross_subtotal' => $grossAmount,
                            'platform_take_percent' => $takePercent,
                            'platform_take_amount' => $takeAmount,
                            'estimated_net_payout' => $netAmount,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to send vendor order push notification', [
                    'order_id' => $order->id,
                    'vendor_id' => $vendorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
