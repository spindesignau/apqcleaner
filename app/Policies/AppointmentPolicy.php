<?php

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Auth\Access\Response;

class AppointmentPolicy
{
    /** Staff only see visits they are assigned to; anything else looks like it doesn't exist. */
    public function view(User $user, Appointment $appointment): Response
    {
        return $user->isAdmin() || $appointment->isAssignedTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Assigned cleaners may start from N minutes before the visit until the end of that (local) day. */
    public function start(User $user, Appointment $appointment): Response
    {
        if (! $appointment->isAssignedTo($user)) {
            return Response::denyAsNotFound();
        }
        if (! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::InProgress], true)) {
            return Response::deny(__('timer.not_startable'));
        }

        $opensAt = $appointment->scheduled_start->copy()->subMinutes((int) app(Settings::class)->get('timer.start_early_minutes', 60));
        if (now()->lt($opensAt)) {
            return Response::deny(__('timer.too_early', ['time' => $opensAt->tz(config('apq.display_timezone'))->format(app()->getLocale() === 'es' ? 'H:i' : 'g:ia')]));
        }
        if (now()->gt($appointment->localStart()->endOfDay())) {
            return Response::deny(__('timer.day_over'));
        }

        return Response::allow();
    }
}
