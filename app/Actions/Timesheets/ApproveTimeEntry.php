<?php

namespace App\Actions\Timesheets;

use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Audit;

/**
 * Approval freezes the rates in force for that visit: the visit price (or client hourly rate)
 * and the cleaner's pay rate for that weekday (Saturday/Sunday rates applied).
 */
class ApproveTimeEntry
{
    public function __invoke(TimeEntry $entry, User $admin): bool
    {
        if (! in_array($entry->status, [TimeEntryStatus::Submitted, TimeEntryStatus::Stopped], true)) {
            return false;
        }

        $entry->update([
            'status' => TimeEntryStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'charge_rate' => $entry->appointment->price ?? $entry->client->currentPrice(),
            'pay_rate' => $entry->user->payRateFor($entry->localStart()),
            'submitted_at' => $entry->submitted_at ?? now(),
        ]);
        Audit::log('timesheet.approved', $entry);

        return true;
    }
}
