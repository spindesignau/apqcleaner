<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Audit;
use App\Services\Xero\XeroClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/** Pulls an invoice's current status / amount due from Xero (webhook or hourly sync). */
class RefreshXeroInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $xeroInvoiceId) {}

    public function middleware(): array
    {
        return [new RateLimited('xero')];
    }

    public function handle(XeroClient $xero): void
    {
        $invoice = Invoice::where('xero_invoice_id', $this->xeroInvoiceId)->first();
        if (! $invoice) {
            return;   // an invoice created directly in Xero, not by APQ
        }
        $remote = $xero->get("Invoices/{$this->xeroInvoiceId}")['Invoices'][0] ?? null;
        if ($remote) {
            self::apply($invoice, $remote);
        }
    }

    public static function apply(Invoice $invoice, array $remote): void
    {
        $wasPaid = $invoice->xero_status === 'PAID';
        $invoice->update([
            'xero_status' => $remote['Status'] ?? $invoice->xero_status,
            'xero_invoice_number' => $remote['InvoiceNumber'] ?? $invoice->xero_invoice_number,
            'xero_total' => $remote['Total'] ?? $invoice->xero_total,
            'xero_amount_due' => $remote['AmountDue'] ?? $invoice->xero_amount_due,
            'paid_at' => ($remote['Status'] ?? null) === 'PAID'
                ? ($invoice->paid_at ?? self::xeroDate($remote['FullyPaidOnDate'] ?? null) ?? now())
                : null,
            'sync_status' => in_array($remote['Status'] ?? null, ['VOIDED', 'DELETED'], true) ? Invoice::SYNC_VOIDED : $invoice->sync_status,
            'synced_at' => now(),
        ]);
        if (! $wasPaid && $invoice->xero_status === 'PAID') {
            Audit::log('invoice.paid', $invoice);
        }
    }

    /** Xero dates arrive as "/Date(1731456000000+0000)/" or ISO strings. */
    private static function xeroDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (preg_match('#/Date\((\d+)#', $value, $m)) {
            return Carbon::createFromTimestampMs((int) $m[1]);
        }

        return Carbon::parse($value);
    }
}
