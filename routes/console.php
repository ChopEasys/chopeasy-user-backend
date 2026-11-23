<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:clear-read')->daily();
Schedule::command('update:trending-products')->weekly();
Schedule::command('model:prune')->daily();
Schedule::command('orders:process-recurring')->hourly()->withoutOverlapping();
Schedule::command('push:deduction-reminders')->everyMinute();
Schedule::command('ambassador:evaluate-rewards')->daily();
