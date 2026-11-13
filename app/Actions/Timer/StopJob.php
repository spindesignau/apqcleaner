<?php

namespace App\Actions\Timer;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\Audit;
use Carbon\CarbonInterface;

class StopJob
{
    public function __construct(private CalculateBillableMinutes $billable) {}

    /**
     * @param  CarbonInterface|null  $at  stop time (defaults to now); used by auto-stop
     */
    public function __invoke(TimeEntry $entry, ?CarbonInterface $at = null, bool $auto = false, array $gps = []): TimeEntry
    {
        if ($entry->status !== TimeEntryStatus::Running) {
            return $entry;   // idempotent
        }

        $end = ($at ?? now())->copy()->utc();
        if ($end->lt($entry->started_at)) {
            $end = $entry->started_at->copy();
        }

        $entry->fill([
            'ended_at' => $end,
            'end_lat' => $gps['lat'] ?? null,
            'end_lng' => $gps['lng'] ?? null,
            'status' => TimeEntryStatus::Stopped,
            'running_lock' => null,
            'auto_stopped' => $auto,
        ]);
        $this->recalculate($entry);
        $entry->save();

        Audit::log($auto ? 'timer.auto_stopped' : 'timer.stopped', $entry, after: ['minutes' => $entry->duration_minutes]);

        return $entry;
    }

    /** Recompute duration and billable minutes from started_at / ended_at. */
    public function recalculate(TimeEntry $entry): void
    {
        $seconds = (int) $entry->started_at->diffInSeconds($entry->ended_at);
        $minutes = (int) round($seconds / 60);
        $entry->duration_seconds = $seconds;
        $entry->duration_minutes = $minutes;
        $entry->billable_minutes = ($this->billable)($entry->client, $minutes);
    }
}
