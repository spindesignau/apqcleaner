<?php

namespace App\Actions\Timesheets;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\Audit;

/** Sends a submitted entry back to the cleaner with a reason; they can adjust and resubmit. */
class RejectTimeEntry
{
    public function __invoke(TimeEntry $entry, string $reason): bool
    {
        if (! in_array($entry->status, [TimeEntryStatus::Submitted, TimeEntryStatus::Approved], true) || $entry->invoice_line_id) {
            return false;
        }

        $entry->update([
            'status' => TimeEntryStatus::Rejected,
            'rejected_reason' => $reason,
            'approved_at' => null,
            'approved_by' => null,
            'charge_rate' => null,
            'pay_rate' => null,
        ]);
        if ($entry->appointment->status === AppointmentStatus::Completed) {
            $entry->appointment->update(['status' => AppointmentStatus::InProgress]);
        }
        Audit::log('timesheet.rejected', $entry, reason: $reason);

        return true;
    }
}
