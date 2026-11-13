<?php

namespace App\Console\Commands;

use App\Actions\Timer\StopJob;
use App\Models\TimeEntry;
use Illuminate\Console\Command;

/**
 * Stops timers that were left running past the end of their (local) day.
 * They stop at the scheduled end time (or the start, if they began after it), are flagged
 * "auto-stopped", and the cleaner must review them before submitting.
 */
class TimerWatchdog extends Command
{
    protected $signature = 'apq:timers:watchdog';

    protected $description = 'Auto-stop timers left running overnight';

    public function handle(StopJob $stop): int
    {
        $tz = config('apq.display_timezone');
        $count = 0;

        TimeEntry::running()->with(['appointment', 'client'])->each(function (TimeEntry $entry) use ($stop, $tz, &$count) {
            $endOfDay = $entry->localStart()->endOfDay();
            if (now($tz)->lte($endOfDay)) {
                return;
            }
            $at = $entry->appointment->scheduled_end->max($entry->started_at)->min($endOfDay);
            $stop($entry, $at, auto: true);
            $count++;
        });

        $this->info("Auto-stopped {$count} timer(s).");

        return self::SUCCESS;
    }
}
