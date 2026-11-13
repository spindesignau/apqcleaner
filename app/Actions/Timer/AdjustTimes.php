<?php

namespace App\Actions\Timer;

use App\Models\TimeEntry;
use App\Services\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "Oops, I forgot to stop": a cleaner corrects start/end before submitting.
 * Times are local wall-clock on the visit's date. Originals are kept for the admin.
 */
class AdjustTimes
{
    public const MAX_MINUTES = 16 * 60;

    public function __construct(private StopJob $stop) {}

    public function __invoke(TimeEntry $entry, string $startHm, string $endHm, bool $byAdmin = false, ?string $reason = null, ?string $date = null): TimeEntry
    {
        $tz = config('apq.display_timezone');
        $day = $date ?? $entry->appointment->localStart()->toDateString();
        $start = Carbon::parse("{$day} {$startHm}", $tz);
        $end = Carbon::parse("{$day} {$endHm}", $tz);
        if ($end->lte($start)) {
            $end->addDay();   // past midnight
        }
        if ($start->diffInMinutes($end) > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['end_time' => __('timer.too_long', ['hours' => self::MAX_MINUTES / 60])]);
        }
        if ($end->isFuture()) {
            throw ValidationException::withMessages(['end_time' => __('timer.end_in_future')]);
        }

        $before = ['started_at' => $entry->started_at->toIso8601String(), 'ended_at' => $entry->ended_at?->toIso8601String()];

        $entry->original_started_at ??= $entry->started_at;
        $entry->original_ended_at ??= $entry->ended_at;
        $entry->started_at = $start;
        $entry->ended_at = $end;
        $entry->{$byAdmin ? 'admin_adjusted' : 'staff_adjusted'} = true;
        $this->stop->recalculate($entry);
        $entry->save();

        Audit::log('timer.adjusted', $entry, before: $before,
            after: ['started_at' => $entry->started_at->toIso8601String(), 'ended_at' => $entry->ended_at->toIso8601String()], reason: $reason);

        return $entry;
    }
}
