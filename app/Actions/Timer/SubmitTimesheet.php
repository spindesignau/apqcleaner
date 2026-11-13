<?php

namespace App\Actions\Timer;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\TimeEntry;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

class SubmitTimesheet
{
    public function __invoke(TimeEntry $entry, ?string $notes): TimeEntry
    {
        DB::transaction(function () use ($entry, $notes) {
            $entry->update([
                'staff_notes' => $notes,
                'status' => TimeEntryStatus::Submitted,
                'submitted_at' => now(),
                'rejected_reason' => null,
            ]);
            Audit::log('timesheet.submitted', $entry);
            self::completeAppointmentIfDone($entry->appointment);
        });

        return $entry;
    }

    /** A visit is completed once every assigned cleaner has submitted. */
    public static function completeAppointmentIfDone(Appointment $appointment): void
    {
        if (! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::InProgress], true)) {
            return;
        }
        $assigned = $appointment->staff()->pluck('users.id');
        $submitted = $appointment->timeEntries()
            ->whereIn('status', [TimeEntryStatus::Submitted->value, TimeEntryStatus::Approved->value, TimeEntryStatus::Invoiced->value])
            ->pluck('user_id');

        if ($assigned->isNotEmpty() && $assigned->diff($submitted)->isEmpty()) {
            $appointment->update(['status' => AppointmentStatus::Completed]);
        }
    }
}
