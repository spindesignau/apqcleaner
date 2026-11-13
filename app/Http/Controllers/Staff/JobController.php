<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class JobController extends Controller
{
    public function show(Appointment $appointment): View
    {
        Gate::authorize('view', $appointment);
        $appointment->load(['client', 'staff:id,name,avatar_path', 'serviceType']);

        return view('staff.job', ['job' => $appointment]);
    }
}
