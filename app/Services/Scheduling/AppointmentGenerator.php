<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns recurring schedules into concrete appointments for the next N weeks.
 * Idempotent: (recurring_schedule_id, occurrence_date) is unique.
 */
class AppointmentGenerator
{
    public const WEEKS_AHEAD = 8;

    public function __construct(private OccurrenceCalculator $calculator) {}

    /** @return int number of appointments created */
    public function generate(RecurringSchedule $schedule, ?CarbonImmutable $from = null, ?int $weeks = null): int
    {
        if (! $schedule->is_active || $schedule->trashed() || ! $schedule->client?->is_active) {
            return 0;
        }

        $tz = config('apq.display_timezone');
        $from ??= CarbonImmutable::now($tz)->startOfDay();
        $to = $from->addWeeks($weeks ?? self::WEEKS_AHEAD);

        $existing = $schedule->appointments()->withTrashed()
            ->pluck('occurrence_date')->map(fn ($d) => $d->toDateString())->flip();
        $staffIds = $schedule->staff()->pluck('users.id')->all();
        $client = $schedule->client;
        $created = 0;

        foreach ($this->calculator->dates($schedule, $from, $to) as $date) {
            if ($existing->has($date->toDateString())) {
                continue;   // already generated (or deliberately deleted by an admin)
            }

            // Build the start in local wall-clock time so 9:00am stays 9:00am across daylight saving.
            $start = CarbonImmutable::parse($date->toDateString().' '.$schedule->start_time, $tz);
            $end = $start->addMinutes($schedule->duration_minutes);

            DB::transaction(function () use ($schedule, $client, $date, $start, $end, $staffIds, &$created) {
                $appointment = Appointment::create([
                    'client_id' => $schedule->client_id,
                    'recurring_schedule_id' => $schedule->id,
                    'occurrence_date' => $date->toDateString(),
                    'service_type_id' => $schedule->service_type_id ?? $client->service_type_id,
                    'scheduled_start' => $start,
                    'scheduled_end' => $end,
                    'status' => AppointmentStatus::Scheduled,
                    'job_notes' => $schedule->default_job_notes,
                    'pricing_model' => $client->pricing_model,
                    'price' => $client->currentPrice(),
                ]);
                $appointment->staff()->sync($staffIds);
                $created++;
            });
        }

        return $created;
    }

    /**
     * After a schedule is edited: remove future visits nobody has touched, then rebuild them.
     */
    public function regenerate(RecurringSchedule $schedule): int
    {
        $this->removeFutureUntouched($schedule);

        return $this->generate($schedule->fresh());
    }

    public function removeFutureUntouched(RecurringSchedule $schedule): int
    {
        $ids = $schedule->appointments()
            ->where('scheduled_start', '>', now())
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('is_detached', false)
            ->pluck('id');

        // Force-delete so the generator may recreate those dates with the new settings.
        Appointment::whereIn('id', $ids)->get()->each->forceDelete();

        return $ids->count();
    }
}
