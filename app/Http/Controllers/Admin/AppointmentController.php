<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentRequest;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Audit;
use App\Services\Scheduling\ConflictFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function create(Request $request): View
    {
        $tz = config('apq.display_timezone');
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;

        return $this->form(new Appointment([
            'client_id' => $client?->id,
            'service_type_id' => $client?->service_type_id,
            'job_notes' => $client?->default_job_notes,
        ]), [
            'date' => $request->date('date')?->toDateString() ?? now($tz)->addDay()->toDateString(),
            'start_time' => '09:00',
            'duration_minutes' => 180,
        ]);
    }

    public function store(AppointmentRequest $request, ConflictFinder $conflicts): RedirectResponse
    {
        $data = $request->validated();
        $client = Client::findOrFail($data['client_id']);
        [$start, $end] = $this->slot($data);

        $appointment = Appointment::create([
            'client_id' => $client->id,
            'service_type_id' => $data['service_type_id'] ?? $client->service_type_id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'status' => AppointmentStatus::Scheduled,
            'job_notes' => $data['job_notes'] ?? null,
            'pricing_model' => $client->pricing_model,
            'price' => $data['price'] ?? $client->currentPrice(),
            'created_by' => $request->user()->id,
        ]);
        $appointment->staff()->sync($data['staff'] ?? []);
        Audit::log('appointment.created', $appointment);

        return $this->redirectWithConflicts($appointment, $conflicts, __('schedule.visit_created'));
    }

    public function edit(Appointment $appointment): View
    {
        $local = $appointment->localStart();

        return $this->form($appointment, [
            'date' => $local->toDateString(),
            'start_time' => $local->format('H:i'),
            'duration_minutes' => $appointment->durationMinutes(),
        ]);
    }

    public function update(AppointmentRequest $request, Appointment $appointment, ConflictFinder $conflicts): RedirectResponse
    {
        abort_unless($appointment->status === AppointmentStatus::Scheduled, 422, __('schedule.not_editable'));

        $data = $request->validated();
        [$start, $end] = $this->slot($data);
        $before = ['start' => $appointment->scheduled_start->toIso8601String(), 'staff' => $appointment->staff->pluck('id')->all()];

        $appointment->update([
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'service_type_id' => $data['service_type_id'] ?? $appointment->service_type_id,
            'job_notes' => $data['job_notes'] ?? null,
            'price' => $data['price'] ?? $appointment->price,
            'is_detached' => $appointment->recurring_schedule_id !== null,   // keep the generator's hands off
        ]);
        $appointment->staff()->sync($data['staff'] ?? []);
        Audit::log('appointment.updated', $appointment, before: $before,
            after: ['start' => $appointment->scheduled_start->toIso8601String(), 'staff' => $data['staff'] ?? []]);

        return $this->redirectWithConflicts($appointment, $conflicts, __('schedule.visit_saved'));
    }

    public function destroy(Appointment $appointment): RedirectResponse
    {
        abort_unless($appointment->status === AppointmentStatus::Scheduled, 422, __('schedule.not_editable'));
        $week = $appointment->localStart()->format('o-\WW');
        $appointment->delete();   // soft delete: the generator won't recreate this date
        Audit::log('appointment.deleted', $appointment);

        return redirect()->route('admin.schedule', ['week' => $week])->with('status', __('schedule.visit_deleted'));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(array $data): array
    {
        $start = Carbon::parse($data['date'].' '.$data['start_time'], config('apq.display_timezone'));

        return [$start, $start->copy()->addMinutes((int) $data['duration_minutes'])];
    }

    private function redirectWithConflicts(Appointment $appointment, ConflictFinder $conflicts, string $message): RedirectResponse
    {
        $clashes = $conflicts->forSlot($appointment->staff()->pluck('users.id')->all(),
            $appointment->scheduled_start, $appointment->scheduled_end, $appointment->id);

        $redirect = redirect()->route('admin.schedule', ['week' => $appointment->localStart()->format('o-\WW')])
            ->with('status', $message);

        if ($clashes->isNotEmpty()) {
            $redirect->with('warning', __('schedule.conflict_warning', [
                'list' => $clashes->map(fn ($c) => $c['user']->name.' → '.$c['appointment']->client->display_name.' ('.$c['appointment']->timeRange().')')->unique()->implode('; '),
            ]));
        }

        return $redirect;
    }

    private function form(Appointment $appointment, array $slot): View
    {
        return view('admin.appointments.form', [
            'appointment' => $appointment,
            'slot' => $slot,
            'clients' => Client::active()->orderBy('display_name')->get(['id', 'display_name', 'suburb']),
            'serviceTypes' => ServiceType::active()->get(),
            'staffOptions' => User::assignable()->get(),
            'selectedStaff' => old('staff', $appointment->exists ? $appointment->staff()->pluck('users.id')->all() : []),
        ]);
    }
}
