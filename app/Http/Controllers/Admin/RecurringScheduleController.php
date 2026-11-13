<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Frequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduleRequest;
use App\Models\Client;
use App\Models\RecurringSchedule;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Audit;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RecurringScheduleController extends Controller
{
    public function create(Client $client): View
    {
        $schedule = new RecurringSchedule([
            'frequency' => Frequency::Fortnightly,
            'weekday' => now(config('apq.display_timezone'))->addDay()->isoWeekday(),
            'start_time' => '09:00',
            'duration_minutes' => 180,
            'anchor_date' => now(config('apq.display_timezone'))->addDay()->toDateString(),
            'default_job_notes' => $client->default_job_notes,
            'service_type_id' => $client->service_type_id,
            'is_active' => true,
        ]);

        return $this->form($client, $schedule);
    }

    public function store(ScheduleRequest $request, Client $client, AppointmentGenerator $generator): RedirectResponse
    {
        $data = $request->validated();
        $schedule = $client->schedules()->create(collect($data)->except('staff')->all() + ['created_by' => $request->user()->id]);
        $schedule->staff()->sync($data['staff'] ?? []);

        $count = $generator->generate($schedule->load('client'));
        Audit::log('schedule.created', $schedule, after: ['visits_created' => $count]);

        return redirect()->route('admin.clients.show', $client)->with('status', trans_choice('schedule.generated', $count, ['count' => $count]));
    }

    public function edit(RecurringSchedule $schedule): View
    {
        return $this->form($schedule->client, $schedule);
    }

    public function update(ScheduleRequest $request, RecurringSchedule $schedule, AppointmentGenerator $generator): RedirectResponse
    {
        $data = $request->validated();
        $schedule->update(collect($data)->except('staff')->all());
        $schedule->staff()->sync($data['staff'] ?? []);

        $count = $generator->regenerate($schedule);
        Audit::log('schedule.updated', $schedule, after: ['visits_rebuilt' => $count]);

        return redirect()->route('admin.clients.show', $schedule->client)->with('status', __('schedule.updated'));
    }

    public function destroy(RecurringSchedule $schedule, AppointmentGenerator $generator): RedirectResponse
    {
        $client = $schedule->client;
        $removed = $generator->removeFutureUntouched($schedule);
        $schedule->update(['is_active' => false]);
        $schedule->delete();
        Audit::log('schedule.ended', $schedule, after: ['future_visits_removed' => $removed]);

        return redirect()->route('admin.clients.show', $client)->with('status', __('schedule.ended', ['count' => $removed]));
    }

    private function form(Client $client, RecurringSchedule $schedule): View
    {
        return view('admin.schedules.form', [
            'client' => $client,
            'schedule' => $schedule,
            'serviceTypes' => ServiceType::active()->get(),
            'staffOptions' => User::assignable()->get(),
            'selectedStaff' => old('staff', $schedule->exists ? $schedule->staff()->pluck('users.id')->all() : []),
        ]);
    }
}
