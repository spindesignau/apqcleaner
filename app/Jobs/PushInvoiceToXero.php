<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\Xero\XeroClient;
use App\Services\Xero\XeroContacts;
use App\Services\Xero\XeroException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Creates the invoice in Xero (Draft or Authorised), optionally emails it via Xero.
 * The invoice's idempotency key is sent on every attempt, so a retry after a timeout
 * can never create a duplicate invoice.
 */
class PushInvoiceToXero implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public Invoice $invoice) {}

    public function uniqueId(): string
    {
        return 'xero-invoice-'.$this->invoice->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('xero')];
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(XeroClient $xero, XeroContacts $contacts, Settings $settings): void
    {
        $invoice = $this->invoice->fresh(['lines', 'client']);
        if (! $invoice || $invoice->sync_status === Invoice::SYNC_VOIDED) {
            return;
        }
        if (! $invoice->xero_invoice_id) {
            $invoice->update(['sync_status' => Invoice::SYNC_PUSHING, 'last_error' => null]);

            try {
                $result = $xero->post('Invoices', ['Invoices' => [$this->payload($invoice, $contacts->ensure($invoice->client), $settings)]],
                    ['summarizeErrors' => 'false', 'unitdp' => 4], $invoice->idempotency_key)['Invoices'][0] ?? [];
            } catch (XeroException $e) {
                $this->failOrRetry($invoice, $e);

                return;
            }

            if (! empty($result['ValidationErrors']) || ($result['StatusAttributeString'] ?? null) === 'ERROR' || empty($result['InvoiceID'])) {
                $this->markFailed($invoice, collect($result['ValidationErrors'] ?? [])->pluck('Message')->implode('; ') ?: __('xero.unknown_error'));

                return;
            }

            $invoice->update([
                'xero_invoice_id' => $result['InvoiceID'],
                'xero_invoice_number' => $result['InvoiceNumber'] ?? null,
                'xero_status' => $result['Status'] ?? null,
                'xero_total' => $result['Total'] ?? null,
                'xero_amount_due' => $result['AmountDue'] ?? null,
                'sync_status' => Invoice::SYNC_SYNCED,
                'synced_at' => now(),
                'last_error' => null,
            ]);
            Audit::log('invoice.pushed', $invoice, after: ['number' => $invoice->xero_invoice_number, 'status' => $invoice->xero_status]);
        }

        if ($invoice->xero_status === 'AUTHORISED') {
            $this->afterAuthorised($invoice, $xero);
        }
    }

    private function afterAuthorised(Invoice $invoice, XeroClient $xero): void
    {
        try {
            if (! $invoice->xero_online_url) {
                $url = $xero->get("Invoices/{$invoice->xero_invoice_id}/OnlineInvoice")['OnlineInvoices'][0]['OnlineInvoiceUrl'] ?? null;
                $invoice->update(['xero_online_url' => $url]);
            }
            // Xero only emails approved (AUTHORISED) invoices to contacts that have an email address.
            if ($invoice->send_email && ! $invoice->emailed_at && $invoice->client->email) {
                $xero->post("Invoices/{$invoice->xero_invoice_id}/Email", [], [], 'email-'.$invoice->idempotency_key);
                $invoice->update(['emailed_at' => now()]);
                Audit::log('invoice.emailed', $invoice);
            }
        } catch (XeroException $e) {
            // The invoice itself exists; record the problem but don't fail the whole push.
            $invoice->update(['last_error' => $e->getMessage()]);
            if ($e->retryable()) {
                $this->release($e->retryAfter ?? 120);
            }
        }
    }

    private function payload(Invoice $invoice, string $contactId, Settings $settings): array
    {
        return array_filter([
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $contactId],
            'Date' => $invoice->issue_date->toDateString(),
            'DueDate' => $invoice->due_date->toDateString(),
            'Reference' => $invoice->reference,
            'LineAmountTypes' => 'Exclusive',
            'Status' => $invoice->mode === 'authorised' ? 'AUTHORISED' : 'DRAFT',
            'BrandingThemeID' => $settings->get('xero.branding_theme_id'),
            'LineItems' => $invoice->lines->map(fn ($l) => [
                'Description' => $l->description,
                'Quantity' => (float) $l->quantity,
                'UnitAmount' => (float) $l->unit_amount,
                'AccountCode' => $l->account_code,
                'TaxType' => $l->tax_type,
            ])->all(),
        ]);
    }

    private function failOrRetry(Invoice $invoice, XeroException $e): void
    {
        if ($e->retryable() && $this->attempts() < $this->tries) {
            $invoice->update(['sync_status' => Invoice::SYNC_PENDING, 'last_error' => $e->getMessage()]);
            $this->release($e->retryAfter ?? $this->backoff()[min($this->attempts() - 1, 4)]);

            return;
        }
        $this->markFailed($invoice, $e->getMessage());
    }

    private function markFailed(Invoice $invoice, string $message): void
    {
        $invoice->update(['sync_status' => Invoice::SYNC_FAILED, 'last_error' => $message]);
        Audit::log('invoice.push_failed', $invoice, reason: mb_substr($message, 0, 255));
    }
}
