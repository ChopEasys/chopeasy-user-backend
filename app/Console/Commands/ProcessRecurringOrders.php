<?php

namespace App\Console\Commands;

use App\Constants\PushNotificationType;
use App\Models\Order;
use App\Models\Transaction;
use App\Notifications\InsufficientWalletBalanceNotification;
use App\Services\PushDispatchService;
use App\Services\VendorOrderPayoutNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessRecurringOrders extends Command
{
    protected $signature = 'orders:process-recurring';
    protected $description = 'Deduct installment payments for recurring orders';

    public function handle()
    {
        $orders = Order::with(['user', 'items'])
            ->whereHas('user')
            ->whereIn('payment_type', ['daily', 'weekly', 'monthly'])
            ->where('remaining_amount', '>', 0)
            ->where('next_due_date', '<=', now())
            ->get();
    
        foreach ($orders as $order) {
            $user = $order->user;
    
            if (!$user) {
                $this->error("Skipping order #{$order->id}: no user assigned.");
                continue;
            }
    
            $total = (float) $order->total_amount;
            $remaining = (float) $order->remaining_amount;
            $customAmount = (float) ($order->custom_amount ?? 0);
    
            $deduction = 0;
    
            if ($order->payment_type === 'daily') {
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / 30, 2);
    
                $deduction = min($baseAmount, $remaining);
                $order->next_due_date = now()->addDay();
            } elseif ($order->payment_type === 'weekly') {
                $n = max(1, (int) ($order->installment_count ?: 4));
    
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / $n, 2);
    
                $deduction = min($baseAmount, $remaining);
                $order->next_due_date = now()->addWeek();
            } elseif ($order->payment_type === 'monthly') {
                $n = max(1, (int) ($order->installment_count ?: 2));
    
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / $n, 2);
    
                $deduction = min($baseAmount, $remaining);
                $order->next_due_date = now()->addMonth();
            }
    
            if ($user->main_wallet >= $deduction) {
    
                DB::beginTransaction();
    
                $becameFullyPaid = false;
    
                try {
    
                    $user->main_wallet -= $deduction;
    
                    $order->amount_paid += $deduction;
                    $order->remaining_amount -= $deduction;
    
                    if ($order->remaining_amount <= 0) {
    
                        $becameFullyPaid = true;
                        $order->payment_status = 'paid';
                        $order->status = 'ongoing';
                        $order->next_due_date = null;
    
                        if (!$order->vendor_order_code) {
    
                            $code = 'VC-' . strtoupper(Str::random(8));
    
                            $order->vendor_order_code = $code;
    
                            foreach ($order->items as $item) {
    
                                DB::table('vendor_orders')
                                    ->where('order_item_id', $item->id)
                                    ->update([
                                        'vendor_order_code' => $code
                                    ]);
                            }
                        }
                    }
    
                    $user->save();
                    $order->save();
    
                    Transaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'type' => 'deduction',
                        'source_wallet' => 'main_wallet',
                        'destination_wallet' => 'main_wallet',
                        'amount' => $deduction,
                        'reference' => $order->order_number,
                        'status' => 'successful',
                        'description' => ucfirst($order->payment_type) . " installment for Order #{$order->id}",
                    ]);
    
                    DB::commit();
    
                    $this->info("Processed order #{$order->id} for user {$user->id}");
    
                    if ($becameFullyPaid) {
    
                        try {
    
                            app(VendorOrderPayoutNotifier::class)
                                ->notifyIfEligible(
                                    $order->fresh()->load('items')
                                );
    
                        } catch (\Throwable $e) {
    
                            $this->warn(
                                "Vendor payout notification failed for order #{$order->id}: "
                                . $e->getMessage()
                            );
                        }

                        // Send savings plan completion push notification to the customer
                        try {
                            $totalSaved = (float) $order->total_amount;

                            app(PushDispatchService::class)->dispatch(
                                $user->id,
                                PushNotificationType::SAVINGS_COMPLETED,
                                [
                                    'title' => 'Savings Plan Completed! 🎉',
                                    'body' => "Your savings plan '{$order->order_number}' is now complete! Total saved: ₦" . number_format($totalSaved, 2),
                                    'url' => "/savings/{$order->id}",
                                    'type' => PushNotificationType::SAVINGS_COMPLETED,
                                    'plan_name' => $order->order_number,
                                    'total_amount' => number_format($totalSaved, 2),
                                    'order_number' => $order->order_number,
                                ]
                            );
                        } catch (\Throwable $e) {
                            $this->warn(
                                "Savings completion notification failed for order #{$order->id}: "
                                . $e->getMessage()
                            );
                        }
                    }
    
                } catch (\Throwable $e) {
    
                    DB::rollBack();
    
                    $this->error(
                        "Error processing order #{$order->id}: "
                        . $e->getMessage()
                    );
                }
    
            } else {
    
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'deduction',
                    'source_wallet' => 'main_wallet',
                    'destination_wallet' => 'main_wallet',
                    'amount' => $deduction,
                    'reference' => $order->order_number,
                    'status' => 'failed',
                    'description' => "Failed {$order->payment_type} deduction due to insufficient funds",
                ]);
    
                if ($deduction > 0) {
    
                    try {
    
                        $user->notify(
                            new InsufficientWalletBalanceNotification(
                                $order,
                                $deduction
                            )
                        );
    
                    } catch (\Throwable $e) {
    
                        $this->warn(
                            "Failed to send insufficient funds email for user {$user->id}, order #{$order->id}: "
                            . $e->getMessage()
                        );
                    }
                }
    
                $this->warn(
                    "Insufficient funds for user {$user->id}, order #{$order->id}"
                );
            }
        }
    }
}
