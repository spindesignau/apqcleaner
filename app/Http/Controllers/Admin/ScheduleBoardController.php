<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Scheduling\ConflictFinder;
use App\Support\Week;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleBoardController extends Controller
{
    public function __invoke(Request $request, ConflictFinder $conflicts): View
    {
        $week = Week::fromParam($request->query('week'));
        $staffId = $request->integer('staff') ?: null;

        $appointments = Appointment::query()
            ->with(['client', 'staff'])
            ->betweenLocalDates($week->start, $week->end)
            ->when($staffId, fn ($q) => $q->whereHas('staff', fn ($s) => $s->whereKey($staffId)))
            ->orderBy('scheduled_start')
            ->get();

        return view('admin.schedule.board', [
            'week' => $week,
            'byDay' => $appointments->groupBy(fn ($a) => $a->localStart()->toDateString()),
            'conflictIds' => $conflicts->overlappingIds($appointments),
            'staffOptions' => User::assignable()->get(['id', 'name']),
            'staffId' => $staffId,
            'unassigned' => $appointments->filter(fn ($a) => $a->staff->isEmpty() && ! $a->status->isCancelled())->count(),
        ]);
    }
}
