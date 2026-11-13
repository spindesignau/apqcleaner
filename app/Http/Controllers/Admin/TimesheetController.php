<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Timer\AdjustTimes;
use App\Actions\Timer\CalculateBillableMinutes;
use App\Actions\Timer\StopJob;
use App\Actions\Timer\SubmitTimesheet;
use App\Actions\Timesheets\ApproveTimeEntry;
use App\Actions\Timesheets\RejectTimeEntry;
use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TimeEntryRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Audit;
use App\Support\PayPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TimesheetController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(TimeEntryStatus::class)],
        ]);
        $tz = config('apq.display_timezone');
        $period = PayPeriod::containing();
        $from = $request->date('from', 'Y-m-d', $tz)?->startOfDay() ?? $period->start;
        $to = $request->date('to', 'Y-m-d', $tz)?->endOfDay() ?? $period->end;
        $staffId = $request->integer('staff') ?: null;
        $clientId = $request->integer('client') ?: null;
        $status = $request->query('status');

        $entries = TimeEntry::query()
            ->with(['user', 'client', 'appointment'])
            ->where('is_legacy', false)
            ->where('started_at', '>=', $from->copy()->utc())
            ->where('started_at', '<=', $to->copy()->utc())
            ->when($staffId, fn ($q) => $q->where('user_id', $staffId))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('started_at')
            ->get();

        return view('admin.timesheets.index', [
            'entries' => $entries,
            'missing' => $this->missing($from, $to, $staffId, $clientId),
            'from' => $from, 'to' => $to, 'staffId' => $staffId, 'clientId' => $clientId, 'status' => $status,
            'staffOptions' => User::orderBy('name')->get(['id', 'name']),
            'clientOptions' => Client::orderBy('display_name')->get(['id', 'display_name']),
            'totals' => [
                'logged' => $entries->sum('duration_minutes'),
                'billable' => $entries->sum('billable_minutes'),
                'labour' => $entries->sum(fn ($e) => $e->labourCost() ?? 0),
                'awaiting' => $entries->where('status', TimeEntryStatus::Submitted)->count(),
            ],
            'periodPresets' => [
                'current' => PayPeriod::containing(),
                'previous' => PayPeriod::containing(PayPeriod::containing()->start->copy()->subDay()),
            ],
        ]);
    }

    public function edit(TimeEntry $entry): View
    {
        $entry->load(['user', 'client', 'appointment.staff']);

        return view('admin.timesheets.edit', [
            'entry' => $entry,
            'audits' => AuditLog::with('user')->where('auditable_type', $entry->getMorphClass())
                ->where('auditable_id', $entry->id)->latest('created_at')->get(),
        ]);
    }

    public function update(TimeEntryRequest $request, TimeEntry $entry, AdjustTimes $adjust, ApproveTimeEntry $approve): RedirectResponse
    {
        Gate::authorize('update', $entry);
        abort_if($entry->status === TimeEntryStatus::Running, 422, __('timer.stop_first'));
        $data = $request->validated();

        DB::transaction(function () use ($request, $entry, $adjust, $approve, $data) {
            $local = fn ($t) => $t?->copy()->tz(config('apq.display_timezone'));
            $timesChanged = $local($entry->started_at)->format('Y-m-d H:i') !== $data['date'].' '.$data['start_time']
                || $local($entry->ended_at)?->format('H:i') !== $data['end_time'];

            if ($timesChanged) {
                $adjust($entry, $data['start_time'], $data['end_time'], byAdmin: true, reason: $data['reason'] ?? null, date: $data['date']);
            }

            $before = $entry->only(['billable_minutes', 'staff_notes', 'admin_notes']);
            $entry->fill([
                'billable_minutes' => $data['billable_minutes'] ?? $entry->billable_minutes,
                'staff_notes' => $data['staff_notes'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
            ]);
            if ($entry->isDirty('billable_minutes')) {
                $entry->admin_adjusted = true;
            }
            if ($entry->isDirty()) {
                $after = $entry->only(array_keys($entry->getDirty()));
                $entry->save();
                Audit::log('timesheet.updated', $entry, before: array_intersect_key($before, $after), after: $after, reason: $data['reason'] ?? null);
            }

            if ($request->boolean('approve')) {
                $approve($entry->fresh(), $request->user());
            }
        });

        return redirect()->route('admin.timesheets.index', $request->only(['from', 'to', 'staff', 'client', 'status']))
            ->with('status', $request->boolean('approve') ? __('timesheets.approved_one') : __('timesheets.saved'));
    }

    public function approve(Request $request, ApproveTimeEntry $approve): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];
        $count = 0;
        TimeEntry::with(['appointment', 'client', 'user'])->whereIn('id', $ids)->each(function ($entry) use ($approve, $request, &$count) {
            $count += $approve($entry, $request->user()) ? 1 : 0;
        });

        return back()->with('status', trans_choice('timesheets.approved_many', $count, ['count' => $count]));
    }

    public function reject(Request $request, TimeEntry $entry, RejectTimeEntry $reject): RedirectResponse
    {
        $reason = $request->validate(['rejected_reason' => ['required', 'string', 'max:255']],
            attributes: ['rejected_reason' => __('timesheets.fields.rejected_reason')])['rejected_reason'];

        abort_unless($reject($entry, $reason), 422, __('timesheets.cannot_reject'));

        return redirect()->route('admin.timesheets.index')->with('status', __('timesheets.rejected'));
    }

    public function stop(TimeEntry $entry, StopJob $stop): RedirectResponse
    {
        $stop($entry);

        return redirect()->route('admin.timesheets.edit', $entry)->with('status', __('timesheets.stopped'));
    }

    /** Add time for a cleaner who never clocked a visit (forgot, phone died, day already over). */
    public function create(Request $request): View
    {
        $appointment = Appointment::with(['client', 'staff'])->findOrFail($request->integer('appointment'));
        $user = $appointment->staff->firstWhere('id', $request->integer('user')) ?? abort(404);

        return view('admin.timesheets.create', compact('appointment', 'user'));
    }

    public function store(TimeEntryRequest $request, CalculateBillableMinutes $billable, ApproveTimeEntry $approve): RedirectResponse
    {
        $request->validate(['appointment_id' => ['required', 'exists:appointments,id'], 'user_id' => ['required', 'exists:users,id']]);
        $appointment = Appointment::with('client')->findOrFail($request->integer('appointment_id'));
        abort_unless($appointment->isAssignedTo($user = User::findOrFail($request->integer('user_id'))), 422);
        abort_if($appointment->timeEntries()->where('user_id', $user->id)->exists(), 422, __('timesheets.already_exists'));

        $data = $request->validated();
        $tz = config('apq.display_timezone');
        $start = Carbon::parse($data['date'].' '.$data['start_time'], $tz);
        $end = Carbon::parse($data['date'].' '.$data['end_time'], $tz);
        if ($end->lte($start)) {
            $end->addDay();
        }
        $minutes = (int) round($start->diffInSeconds($end) / 60);

        $entry = DB::transaction(function () use ($appointment, $user, $start, $end, $minutes, $billable, $data, $request, $approve) {
            $entry = TimeEntry::create([
                'appointment_id' => $appointment->id, 'user_id' => $user->id, 'client_id' => $appointment->client_id,
                'status' => TimeEntryStatus::Submitted, 'started_at' => $start, 'ended_at' => $end,
                'duration_seconds' => $start->diffInSeconds($end), 'duration_minutes' => $minutes,
                'billable_minutes' => $data['billable_minutes'] ?? $billable($appointment->client, $minutes),
                'staff_notes' => $data['staff_notes'] ?? null, 'admin_notes' => $data['admin_notes'] ?? null,
                'admin_adjusted' => true, 'submitted_at' => now(),
            ]);
            Audit::log('timesheet.updated', $entry, after: ['created_by_admin' => true], reason: $data['reason'] ?? null);
            if ($appointment->status === AppointmentStatus::Scheduled) {
                $appointment->update(['status' => AppointmentStatus::InProgress]);
            }
            SubmitTimesheet::completeAppointmentIfDone($appointment->fresh());
            if ($request->boolean('approve')) {
                $approve($entry, $request->user());
            }

            return $entry;
        });

        return redirect()->route('admin.timesheets.index', ['from' => $entry->localStart()->toDateString(), 'to' => $entry->localStart()->toDateString()])
            ->with('status', __('timesheets.saved'));
    }

    /**
     * Past visits where an assigned cleaner has not submitted time yet.
     *
     * @return Collection<int, array{appointment: Appointment, user: User, entry: ?TimeEntry}>
     */
    private function missing(Carbon $from, Carbon $to, ?int $staffId, ?int $clientId)
    {
        return Appointment::query()
            ->with(['client', 'staff', 'timeEntries'])
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::InProgress->value])
            ->where('scheduled_end', '<', now())
            ->where('scheduled_start', '>=', $from->copy()->utc())
            ->where('scheduled_start', '<=', $to->copy()->utc())
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('scheduled_start')
            ->get()
            ->flatMap(fn (Appointment $a) => $a->staff
                ->when($staffId, fn ($s) => $s->where('id', $staffId))
                ->map(fn (User $u) => ['appointment' => $a, 'user' => $u, 'entry' => $a->timeEntries->firstWhere('user_id', $u->id)])
                ->reject(fn ($row) => $row['entry']?->status?->isSubmitted()))
            ->values();
    }
}
