<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Support\Week;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WeekController extends Controller
{
    /** Staff may browse 4 weeks back and 8 weeks ahead. */
    private const WEEKS_BACK = 4;

    private const WEEKS_AHEAD = 8;

    public function __invoke(Request $request, ?string $week = null): View
    {
        $current = Week::fromParam(null);
        $selected = Week::fromParam($week);
        $min = $current->start->copy()->subWeeks(self::WEEKS_BACK);
        $max = $current->start->copy()->addWeeks(self::WEEKS_AHEAD);
        if ($selected->start->lt($min) || $selected->start->gt($max)) {
            $selected = $current;
        }

        $jobs = Appointment::query()
            ->forStaff($request->user())
            ->active()
            ->betweenLocalDates($selected->start, $selected->end)
            ->with('client')
            ->orderBy('scheduled_start')
            ->get();

        return view('staff.week', [
            'week' => $selected,
            'days' => $selected->days(),
            'now' => now(config('apq.display_timezone')),
            'prevWeek' => $selected->start->copy()->subWeek()->gte($min) ? $selected->previous() : null,
            'nextWeek' => $selected->start->copy()->addWeek()->lte($max) ? $selected->next() : null,
            'jobsByDay' => $jobs->groupBy(fn ($a) => $a->localStart()->toDateString()),
        ]);
    }
}
