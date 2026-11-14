<?php

use App\Models\IntegrationLog;
use App\Services\Settings;
use Illuminate\Support\Facades\Schedule;

// cPanel cron: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
Schedule::command('apq:appointments:generate')->dailyAt('01:00')->timezone('Australia/Sydney')->withoutOverlapping();
Schedule::command('apq:timers:watchdog')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('apq:sms:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('apq:xero:sync-invoices')->hourly()->withoutOverlapping();
Schedule::command('apq:xero:keepalive')->weekly();
Schedule::command('model:prune', ['--model' => [IntegrationLog::class]])->daily();
Schedule::command('apq:backup')->dailyAt('02:30')->timezone('Australia/Sydney')->withoutOverlapping();
// Heartbeat: the admin System page warns if the cron job stops running.
Schedule::call(fn () => app(Settings::class)->set('system.scheduler_heartbeat', now()->toIso8601String()))->everyMinute()->name('heartbeat');
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')->everyMinute()->withoutOverlapping()->runInBackground();
