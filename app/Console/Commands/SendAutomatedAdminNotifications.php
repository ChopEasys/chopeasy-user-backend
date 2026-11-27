<?php

namespace App\Console\Commands;

use App\Models\AdminNotificationAutomation;
use App\Services\AdminNotificationBroadcastService;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendAutomatedAdminNotifications extends Command
{
    protected $signature = 'notifications:send-admin-automations';
    protected $description = 'Send due admin-configured audience notifications';

    public function handle(AdminNotificationBroadcastService $broadcasts): int
    {
        foreach (AdminNotificationBroadcastService::AUDIENCES as $audience) {
            Cache::lock('admin-notification-automation-'.$audience, 55)->block(0, function () use ($audience, $broadcasts) {
                $automation = DB::transaction(function () use ($audience) {
                    $row = AdminNotificationAutomation::where('audience', $audience)->where('enabled', true)
                        ->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->lockForUpdate()->first();
                    if (!$row) return null;

                    $row->last_sent_at = now();
                    $row->next_run_at = match ($row->frequency) {
                        'hourly' => now()->addHour(),
                        'weekly' => now()->addWeek(),
                        'biweekly' => now()->addWeeks(2),
                        'monthly' => now()->addMonth(),
                        default => now()->addDay(),
                    };
                    $row->save();
                    return $row;
                });

                if ($automation) {
                    if ($audience === 'customer') {
                        $broadcasts->sendToUsers(
                            User::query()->where('user_type', 'customer')->where('is_active', true)
                                ->where('created_at', '<=', now()->subMonth())
                                ->whereNotIn('id', Transaction::query()->select('user_id')->where('type', 'deposit')->where('status', 'successful')),
                            $audience, $automation->title, $automation->message, 'customer_funding_reminder'
                        );
                    } else {
                        $broadcasts->send($audience, $automation->title, $automation->message);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
