<?php

namespace App\Console\Commands;

use App\Models\AdminNotificationAutomation;
use App\Services\AdminNotificationBroadcastService;
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
                        'monthly' => now()->addMonth(),
                        default => now()->addDay(),
                    };
                    $row->save();
                    return $row;
                });

                if ($automation) {
                    $broadcasts->send($audience, $automation->title, $automation->message);
                }
            });
        }

        return self::SUCCESS;
    }
}
