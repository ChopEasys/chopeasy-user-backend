<?php

namespace App\Console;

use App\Repositories\ProductViewed\RecentlyViewedRepositoryInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    // Register your commands here - Not needed
    protected $commands = [
        \App\Console\Commands\ClearOldReadNotifications::class,
        \App\Console\Commands\UpdateTrendingProducts::class,
        \App\Console\Commands\DeductionReminderCommand::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // Schedule the notification clearance to run daily at midnight
        $schedule->command('notifications:clear-read')->daily();
        $schedule->command('update:trending-products')->weekly();
        // $schedule->call(function () {
        //     app(RecentlyViewedRepositoryInterface::class)->clearOldViews();
        // })->daily();
        $schedule->command('model:prune')->daily();
        $schedule->command('orders:process-recurring')->hourly();
        $schedule->command('push:deduction-reminders')->everyMinute();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    //on server
    // * * * * * php /path-to-your-project/artisan schedule:run >> /dev/null 2>&1

}
