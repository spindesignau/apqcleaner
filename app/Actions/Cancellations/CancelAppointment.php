<?php

namespace App\Actions\Cancellations;

use App\Enums\AppointmentStatus;
use App\Jobs\PushInvoiceToXero;
use App\Models\Appointment;
use App\Models\Cancellation;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a visit, records the notice given, and either charges a cancellation fee (its own Xero invoice),
 * waives it (with a reason), or marks it not applicable. Optionally notifies the client and cleaners by SMS.
 *
 * @param  array{reason: string, reason_note?: ?string, notice_given_at: Carbon, decision: string, amount?: ?float,
 *               waive_reason?: ?string, mode?: string, email?: bool, notify_client?: bool, notify_staff?: bool}  $input
 */
class CancelAppointment
{
    public function __construct(private CancellationFee $fees, private Settings $settings, private SmsDispatcher $sms) {}

    public function __invoke(Appointment $appointment, array $input, User $by): Cancellation
    {
        if ($appointment->status !== AppointmentStatus::Scheduled || $appointment->timeEntries()->exists()) {
            throw ValidationException::withMessages(['reason' => __('cancellations.not_cancellable')]);
        }

        $quote = $this->fees->quote($appointment, $input['reason'], $input['notice_given_at']);
        $decision = $quote['eligible'] ? $input['decision'] : 'not_applicable';
        if ($decision === 'waived' && blank($input['waive_reason'] ?? null)) {
            throw ValidationException::withMessages(['waive_reason' => __('cancellations.waive_reason_required')]);
        }
        $amount = $decision === 'charged' ? round((float) ($input['amount'] ?? $quote['amount']), 2) : null;
        if ($decision === 'charged' && $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('cancellations.amount_required')]);
        }

        $cancellation = DB::transaction(function () use ($appointment, $input, $by, $quote, $decision, $amount) {
            $status = match (true) {
                $input['reason'] === 'client_no_access' => AppointmentStatus::NoAccess,
                $quote['eligible'] => AppointmentStatus::CancelledLate,
                default => AppointmentStatus::Cancelled,
            };
            $appointment->update(['status' => $status]);

            $invoice = $decision === 'charged' ? $this->feeInvoice($appointment, $amount, $quote, $input, $by) : null;

            $cancellation = Cancellation::create([
                'appointment_id' => $appointment->id,
                'reason' => $input['reason'],
                'reason_note' => $input['reason_note'] ?? null,
                'notice_given_at' => $input['notice_given_at']->copy()->utc(),
                'hours_notice' => $quote['hours_notice'],
                'fee_eligible' => $quote['eligible'],
                'fee_decision' => $decision,
                'fee_amount_ex_gst' => $amount,
                'waive_reason' => $decision === 'waived' ? $input['waive_reason'] : null,
                'invoice_id' => $invoice?->id,
                'cancelled_by' => $by->id,
            ]);
            Audit::log('appointment.cancelled', $appointment, after: ['reason' => $input['reason'], 'fee' => $decision, 'amount' => $amount],
                reason: $decision === 'waived' ? $input['waive_reason'] : ($input['reason_note'] ?? null));

            return $cancellation;
        });

        if ($cancellation->invoice) {
            PushInvoiceToXero::dispatch($cancellation->invoice);
        }
        if ($input['notify_client'] ?? false) {
            $this->sms->cancellationNotice($appointment, $by);
        }
        if ($input['notify_staff'] ?? false) {
            $this->sms->notifyStaffCancelled($appointment->load('staff'));
        }

        return $cancellation;
    }

    private function feeInvoice(Appointment $appointment, float $amount, array $quote, array $input, User $by): Invoice
    {
        $tz = config('apq.display_timezone');
        $issue = Carbon::now($tz)->startOfDay();
        $start = $appointment->localStart();
        $service = $appointment->serviceType?->name_en ?? $appointment->client->serviceType?->name_en ?? 'Cleaning service';
        $mode = ($input['mode'] ?? 'draft') === 'authorised' ? 'authorised' : 'draft';

        $invoice = Invoice::create([
            'client_id' => $appointment->client_id,
            'type' => 'cancellation_fee',
            'reference' => 'CXL-'.$appointment->id,
            'idempotency_key' => (string) Str::uuid(),
            'issue_date' => $issue->toDateString(),
            'due_date' => $issue->copy()->addDays((int) $this->settings->get('xero.payment_terms_days', 7))->toDateString(),
            'subtotal' => $amount,
            'gst' => round($amount * 0.10, 2),
            'total' => round($amount * 1.10, 2),
            'mode' => $mode,
            'send_email' => $mode === 'authorised' && ($input['email'] ?? false),
            'sync_status' => Invoice::SYNC_PENDING,
            'created_by' => $by->id,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'appointment_id' => $appointment->id,
            'description' => sprintf('Late cancellation fee – %s scheduled %s (notice: %s h)',
                $service, $start->format('D j M Y g:ia'), rtrim(rtrim(number_format(max(0, $quote['hours_notice']), 1), '0'), '.')),
            'quantity' => 1,
            'unit_amount' => $amount,
            'account_code' => (string) $this->settings->get('xero.cancellation_account_code', '260'),
            'tax_type' => 'OUTPUT',
            'line_amount' => $amount,
        ]);
        Audit::log('invoice.created', $invoice, after: ['type' => 'cancellation_fee', 'total' => $invoice->total]);

        return $invoice;
    }
}
