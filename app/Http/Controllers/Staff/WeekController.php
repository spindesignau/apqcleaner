<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class WeekController extends Controller
{
    public function __invoke(Request $request, ?string $week = null): View
    {
        $tz = config('apq.display_timezone');
        $now = now($tz);

        $start = $week && preg_match('/^(\d{4})-W(\d{2})$/', $week, $m)
            ? Carbon::now($tz)->setISODate((int) $m[1], (int) $m[2])->startOfWeek()
            : $now->copy()->startOfWeek();

        // Staff may browse 4 weeks back and 8 weeks ahead.
        $min = $now->copy()->startOfWeek()->subWeeks(4);
        $max = $now->copy()->startOfWeek()->addWeeks(8);
        if ($start->lt($min) || $start->gt($max)) {
            return view('staff.week', $this->data($now->copy()->startOfWeek(), $now, $min, $max));
        }

        return view('staff.week', $this->data($start, $now, $min, $max));
    }

    private function data(Carbon $start, Carbon $now, Carbon $min, Carbon $max): array
    {
        $prev = $start->copy()->subWeek();
        $next = $start->copy()->addWeek();

        return [
            'start' => $start,
            'days' => CarbonPeriod::create($start, 7)->toArray(),
            'now' => $now,
            'isThisWeek' => $start->isSameWeek($now),
            'prevWeek' => $prev->gte($min) ? $prev->format('o-\WW') : null,
            'nextWeek' => $next->lte($max) ? $next->format('o-\WW') : null,
            'jobsByDay' => collect(),   // Phase 1: assigned appointments grouped by local date
        ];
    }
}
