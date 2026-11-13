<?php

namespace App\Actions\Timer;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartJob
{
    /** @param  array{lat?: float|null, lng?: float|null}  $gps */
    public function __invoke(Appointment $appointment, User $user, array $gps = []): TimeEntry
    {
        Gate::forUser($user)->authorize('start', $appointment);

        // Idempotent: a double tap, a second tab or a reload just returns the existing entry.
        if ($existing = $appointment->timeEntries()->where('user_id', $user->id)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($appointment, $user, $gps) {
                $entry = TimeEntry::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => $user->id,
                    'client_id' => $appointment->client_id,
                    'status' => TimeEntryStatus::Running,
                    'running_lock' => $user->id,
                    'started_at' => now(),
                    'start_lat' => $gps['lat'] ?? null,
                    'start_lng' => $gps['lng'] ?? null,
                ]);

                if ($appointment->status === AppointmentStatus::Scheduled) {
                    $appointment->update(['status' => AppointmentStatus::InProgress]);
                }
                Audit::log('timer.started', $entry);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            // Either another job is running for this cleaner, or a parallel request just created this one.
            if ($existing = $appointment->timeEntries()->where('user_id', $user->id)->first()) {
                return $existing;
            }
            throw ValidationException::withMessages(['timer' => __('timer.stop_current_first')]);
        }
    }
}
