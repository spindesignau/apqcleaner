<?php

use Illuminate\Support\Facades\Schedule;

// cPanel cron: * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
Schedule::command('apq:appointments:generate')->dailyAt('01:00')->timezone('Australia/Sydney')->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')->everyMinute()->withoutOverlapping()->runInBackground();
