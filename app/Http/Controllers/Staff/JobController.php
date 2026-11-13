<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Timer\AdjustTimes;
use App\Actions\Timer\StartJob;
use App\Actions\Timer\StopJob;
use App\Actions\Timer\SubmitTimesheet;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JobController extends Controller
{
    public function show(Request $request, Appointment $appointment): View
    {
        Gate::authorize('view', $appointment);
        $appointment->load(['client', 'staff:id,name,avatar_path', 'serviceType']);

        return view('staff.job', [
            'job' => $appointment,
            'entry' => $this->entryFor($request, $appointment),
            'canStart' => Gate::inspect('start', $appointment),
        ]);
    }

    public function start(Request $request, Appointment $appointment, StartJob $start): RedirectResponse
    {
        Gate::authorize('view', $appointment);
        $start($appointment, $request->user(), $this->gps($request));

        return redirect()->route('jobs.show', $appointment);
    }

    public function stop(Request $request, Appointment $appointment, StopJob $stop): RedirectResponse
    {
        $entry = $this->ownEntryOrFail($request, $appointment);
        Gate::authorize('update', $entry);
        $stop($entry, gps: $this->gps($request));

        return redirect()->route('jobs.show', $appointment);
    }

    public function adjust(Request $request, Appointment $appointment, AdjustTimes $adjust): RedirectResponse
    {
        $entry = $this->ownEntryOrFail($request, $appointment);
        Gate::authorize('update', $entry);
        if ($entry->status === TimeEntryStatus::Running) {
            throw ValidationException::withMessages(['start_time' => __('timer.stop_first')]);
        }
        $data = $request->validate([
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ], attributes: ['start_time' => __('timer.start'), 'end_time' => __('timer.end')]);

        $adjust($entry, $data['start_time'], $data['end_time']);

        return redirect()->route('jobs.show', $appointment)->with('status', __('timer.adjusted'));
    }

    public function submit(Request $request, Appointment $appointment, SubmitTimesheet $submit): RedirectResponse
    {
        $entry = $this->ownEntryOrFail($request, $appointment);
        Gate::authorize('update', $entry);
        if ($entry->status === TimeEntryStatus::Running) {
            throw ValidationException::withMessages(['staff_notes' => __('timer.stop_first')]);
        }
        $data = $request->validate(['staff_notes' => ['nullable', 'string', 'max:2000']], attributes: ['staff_notes' => __('timer.notes')]);

        $submit($entry, $data['staff_notes'] ?? null);

        return redirect()->route('jobs.today')->with('status', __('timer.submitted'));
    }

    private function entryFor(Request $request, Appointment $appointment): ?TimeEntry
    {
        return $appointment->timeEntries()->where('user_id', $request->user()->id)->first();
    }

    private function ownEntryOrFail(Request $request, Appointment $appointment): TimeEntry
    {
        Gate::authorize('view', $appointment);

        return $this->entryFor($request, $appointment) ?? abort(404);
    }

    /** Optional GPS sent by the browser; never required. */
    private function gps(Request $request): array
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return [];
        }

        return ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7)];
    }
}
