<?php

use App\Models\NotificationDelivery;
use App\Models\WebhookDelivery;
use App\Services\Health;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Shared hosting has no supervisor, so the queue is drained by cron. Add ONE cron line (every minute):
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
| `queue:work --stop-when-empty --max-time=55` processes what is waiting and exits before the next minute's run.
*/
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --queue=default')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();

// Keep the log tables from growing forever.
Schedule::command('model:prune', ['--model' => [NotificationDelivery::class, WebhookDelivery::class]])->daily();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily();

// Scheduled marketing campaigns that are due (Phase 15).
Schedule::command('campaigns:dispatch')->everyMinute()->withoutOverlapping(5);

// Online-payment safety net: re-check open sessions whose callback never arrived (Phase 15b).
Schedule::command('gateways:reconcile')->everyFiveMinutes()->withoutOverlapping(10);

// Nightly encrypted backup, then retention pruning (Phase 16). A failed run is recorded and shown in Settings → Backups.
Schedule::command('backup:run')->dailyAt((string) config('backup.schedule_at', '02:30'))->withoutOverlapping(120);
Schedule::command('backup:prune')->dailyAt('03:30');

// Lets /health report whether cron is actually running (shared hosting has no other way to tell).
Schedule::call(fn () => Cache::put(Health::HEARTBEAT_KEY, time(), 3600))->everyMinute()->name('health-heartbeat');
