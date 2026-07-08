<?php

namespace App\Console\Commands;

use App\Constants\PushNotificationType;
use App\Models\Order;
use App\Services\PushDispatchService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeductionReminderCommand extends Command
{
    protected $signature = 'push:deduction-reminders';
    protected $description = 'Send push notification reminders for savings plan deductions scheduled in 60 minutes';

    public function handle(): int
    {
        $now = Carbon::now();
        $targetTime = $now->copy()->addMinutes(60);

        // Query active savings plans with deductions due in the next minute window (60 minutes from now)
        $plans = Order::with('user')
            ->whereHas('user')
            ->whereIn('payment_type', ['daily', 'weekly', 'monthly'])
            ->where('remaining_amount', '>', 0)
            ->where('payment_status', '!=', 'paid')
            ->whereBetween('next_due_date', [
                $targetTime->copy()->startOfMinute(),
                $targetTime->copy()->endOfMinute(),
            ])
            ->get();

        if ($plans->isEmpty()) {
            $this->info('No deduction reminders to send at this time.');
            return self::SUCCESS;
        }

        $pushService = app(PushDispatchService::class);
        $sent = 0;
        $skipped = 0;

        foreach ($plans as $plan) {
            // Re-check plan is still active before sending (requirement 5.4)
            if ($plan->payment_status === 'paid' || (float) $plan->remaining_amount <= 0) {
                $this->info("Skipping plan #{$plan->id}: no longer active.");
                $skipped++;
                continue;
            }

            $user = $plan->user;

            if (!$user) {
                $this->warn("Skipping plan #{$plan->id}: no user assigned.");
                $skipped++;
                continue;
            }

            // Calculate the deduction amount (same logic as ProcessRecurringOrders)
            $total = (float) $plan->total_amount;
            $remaining = (float) $plan->remaining_amount;
            $customAmount = (float) ($plan->custom_amount ?? 0);
            $deductionAmount = 0;

            if ($plan->payment_type === 'daily') {
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / 30, 2);
                $deductionAmount = min($baseAmount, $remaining);
            } elseif ($plan->payment_type === 'weekly') {
                $n = max(1, (int) ($plan->installment_count ?: 4));
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / $n, 2);
                $deductionAmount = min($baseAmount, $remaining);
            } elseif ($plan->payment_type === 'monthly') {
                $n = max(1, (int) ($plan->installment_count ?: 2));
                $baseAmount = $customAmount > 0
                    ? $customAmount
                    : round($total / $n, 2);
                $deductionAmount = min($baseAmount, $remaining);
            }

            $deductionTime = Carbon::parse($plan->next_due_date)->format('g:i A');
            $formattedAmount = number_format($deductionAmount, 2);
            $planName = $plan->order_number;

            $payload = [
                'title' => 'Deduction Reminder 💰',
                'body' => "₦{$formattedAmount} will be deducted from your wallet for '{$planName}' at {$deductionTime}.",
                'url' => "/savings/{$plan->id}",
                'type' => PushNotificationType::DEDUCTION_REMINDER,
                'amount' => "₦{$formattedAmount}",
                'plan_name' => $planName,
                'deduction_time' => $deductionTime,
                'plan_id' => $plan->id,
            ];

            try {
                $dispatched = $pushService->dispatch(
                    $user->id,
                    PushNotificationType::DEDUCTION_REMINDER,
                    $payload
                );

                if ($dispatched) {
                    $sent++;
                    $this->info("Sent deduction reminder for plan #{$plan->id} to user #{$user->id}.");
                } else {
                    $skipped++;
                    $this->warn("Dispatch returned false for plan #{$plan->id} (rate-limited or invalid payload).");
                }
            } catch (\Throwable $e) {
                $skipped++;
                Log::error("Failed to send deduction reminder for plan #{$plan->id}", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error sending reminder for plan #{$plan->id}: " . $e->getMessage());
            }
        }

        $this->info("Deduction reminders complete: {$sent} sent, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
