<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cancellations\CancelAppointment;
use App\Actions\Cancellations\CancellationFee;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Cancellation;
use App\Services\Settings;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CancellationController extends Controller
{
    /** Next 48 hours with live countdown, plus recent cancellations. */
    public function index(): View
    {
        $upcoming = Appointment::query()
            ->with(['client', 'staff', 'smsMessages' => fn ($q) => $q->where('template_key', 'reminder')->latest('id')])
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('scheduled_start', '>=', now())
            ->where('scheduled_start', '<=', now()->addHours(48))
            ->orderBy('scheduled_start')->get();

        $recent = Cancellation::with(['appointment.client', 'invoice', 'cancelledBy'])->latest('id')->limit(30)->get();

        return view('admin.cancellations.index', compact('upcoming', 'recent'));
    }

    public function create(Request $request, Appointment $appointment, CancellationFee $fees, Settings $settings): View|RedirectResponse
    {
        if ($appointment->status !== AppointmentStatus::Scheduled || $appointment->timeEntries()->exists()) {
            return redirect()->route('admin.appointments.edit', $appointment)->withErrors(['reason' => __('cancellations.not_cancellable')]);
        }
        $appointment->load(['client', 'staff']);
        $tz = config('apq.display_timezone');
        $notice = $request->filled('notice') ? Carbon::parse($request->query('notice'), $tz) : Carbon::now($tz);
        $reason = $request->query('reason', 'client_cancelled');

        return view('admin.cancellations.create', [
            'appointment' => $appointment,
            'quote' => $fees->quote($appointment, in_array($reason, Cancellation::REASONS, true) ? $reason : 'client_cancelled', $notice),
            'notice' => $notice,
            'reason' => $reason,
            'defaultMode' => $settings->get('xero.default_mode', 'draft'),
            'notifyClient' => (bool) $settings->get('sms.notify_client_on_cancel', true),
            'notifyStaff' => (bool) $settings->get('notify_staff_on_cancel', true),
        ]);
    }

    public function store(Request $request, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(Cancellation::REASONS)],
            'reason_note' => ['nullable', 'string', 'max:255'],
            'notice_date' => ['required', 'date_format:Y-m-d'],
            'notice_time' => ['required', 'date_format:H:i'],
            'decision' => ['required', Rule::in(['charged', 'waived'])],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'waive_reason' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', Rule::in(['draft', 'authorised'])],
            'email' => ['nullable', 'boolean'],
            'notify_client' => ['nullable', 'boolean'],
            'notify_staff' => ['nullable', 'boolean'],
        ], attributes: collect(['reason', 'amount', 'waive_reason'])->mapWithKeys(fn ($k) => [$k => __('cancellations.fields.'.$k)])->all());

        $notice = Carbon::parse($data['notice_date'].' '.$data['notice_time'], config('apq.display_timezone'));
        abort_if($notice->isFuture(), 422, __('cancellations.notice_in_future'));

        $cancellation = $cancel($appointment, [
            'reason' => $data['reason'], 'reason_note' => $data['reason_note'] ?? null, 'notice_given_at' => $notice,
            'decision' => $data['decision'], 'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'waive_reason' => $data['waive_reason'] ?? null, 'mode' => $data['mode'] ?? 'draft',
            'email' => $request->boolean('email'), 'notify_client' => $request->boolean('notify_client'), 'notify_staff' => $request->boolean('notify_staff'),
        ], $request->user());

        return redirect()->route('admin.cancellations.index')->with('status', match ($cancellation->fee_decision) {
            'charged' => __('cancellations.done_charged', ['amount' => number_format((float) $cancellation->fee_amount_ex_gst, 2)]),
            'waived' => __('cancellations.done_waived'),
            default => __('cancellations.done'),
        });
    }

    public function remind(Request $request, Appointment $appointment, SmsDispatcher $sms): RedirectResponse
    {
        $message = $sms->remindClient($appointment->load('client'), 'manual', $request->user());

        return back()->with($message->status === 'sent' ? 'status' : 'warning', match ($message->status) {
            'sent' => __('sms.reminder_sent', ['name' => $appointment->client->display_name]),
            'skipped' => __('sms.reminder_skipped', ['reason' => $message->status_detail]),
            default => __('sms.reminder_failed', ['reason' => $message->status_detail]),
        });
    }
}
