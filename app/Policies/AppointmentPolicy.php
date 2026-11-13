<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
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
}
