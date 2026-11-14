<?php

namespace App\Actions\Invoicing;

use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Services\Audit;
use App\Services\Xero\XeroClient;
use App\Services\Xero\XeroException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an invoice and puts its time back to "approved" so it can be corrected and invoiced again.
 * Xero: drafts are DELETED, approved-but-unpaid invoices are VOIDED. Paid invoices need a credit note in Xero.
 */
class VoidInvoice
{
    public function __construct(private XeroClient $xero) {}

    public function __invoke(Invoice $invoice): void
    {
        if ($invoice->sync_status === Invoice::SYNC_VOIDED) {
            return;
        }
        if ($invoice->xero_invoice_id) {
            $remote = $this->xero->get("Invoices/{$invoice->xero_invoice_id}")['Invoices'][0] ?? [];
            if (($remote['AmountPaid'] ?? 0) > 0 || ($remote['Status'] ?? null) === 'PAID') {
                throw new XeroException(__('invoices.paid_cannot_void'), 'validation');
            }
            $target = in_array($remote['Status'] ?? $invoice->xero_status, ['DRAFT', 'SUBMITTED'], true) ? 'DELETED' : 'VOIDED';
            if (! in_array($remote['Status'] ?? null, ['DELETED', 'VOIDED'], true)) {
                $this->xero->post("Invoices/{$invoice->xero_invoice_id}", ['Invoices' => [['InvoiceID' => $invoice->xero_invoice_id, 'Status' => $target]]]);
            }
            $invoice->xero_status = $target;
        }

        DB::transaction(function () use ($invoice) {
            $lineIds = $invoice->lines()->pluck('id');
            $appointmentIds = $invoice->lines()->pluck('appointment_id')->filter();
            // Query-level update on purpose: the "invoiced entries are frozen" guard protects single-model
            // saves; this is the one sanctioned path that unfreezes them, after the Xero invoice is cancelled.
            $unlocked = TimeEntry::whereIn('invoice_line_id', $lineIds)->update([
                'invoice_line_id' => null,
                'status' => TimeEntryStatus::Approved->value,
            ]);
            Appointment::whereIn('id', $appointmentIds)->where('status', AppointmentStatus::Invoiced->value)
                ->update(['status' => AppointmentStatus::Completed->value]);
            $invoice->update(['sync_status' => Invoice::SYNC_VOIDED, 'xero_status' => $invoice->xero_status]);
            Audit::log('invoice.voided', $invoice, after: ['entries_unlocked' => $unlocked, 'xero_status' => $invoice->xero_status]);
        });
    }
}
