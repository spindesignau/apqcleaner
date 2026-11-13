<?php

namespace App\Http\Controllers\Staff;

use App\Enums\TimeEntryStatus;
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
            ->with(['client', 'staff:id,name', 'timeEntries' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->orderBy('scheduled_start')
            ->get()
            // A running job always sits at the top.
            ->sortByDesc(fn ($job) => $job->timeEntries->first()?->status === TimeEntryStatus::Running)
            ->values();

        return view('staff.today', compact('today', 'jobs'));
    }
}
