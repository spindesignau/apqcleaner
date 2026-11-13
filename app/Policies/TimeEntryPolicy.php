<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TimeEntryPolicy
{
    /** Cleaners change only their own entry, only before submitting (or after it was sent back). */
    public function update(User $user, TimeEntry $entry): Response
    {
        if ($entry->invoice_line_id) {
            return Response::deny(__('timer.locked'));
        }
        if ($user->isAdmin()) {
            return Response::allow();
        }
        if ($entry->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $entry->status->editableByStaff() ? Response::allow() : Response::deny(__('timer.locked'));
    }
}
