<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TodayController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = now(config('apq.display_timezone'));

        $jobs = Appointment::query()
            ->forStaff($request->user())
            ->active()
            ->betweenLocalDates($today, $today)
            ->with(['client', 'staff:id,name'])
            ->orderBy('scheduled_start')
            ->get();

        return view('staff.today', compact('today', 'jobs'));
    }
}
