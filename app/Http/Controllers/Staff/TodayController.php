<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TodayController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = now(config('apq.display_timezone'));

        // Phase 1 replaces this with the user's assigned appointments for today.
        return view('staff.today', ['today' => $today, 'jobs' => collect()]);
    }
}
