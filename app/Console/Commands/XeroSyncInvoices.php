<?php

namespace App\Console\Commands;

use App\Jobs\RefreshXeroInvoice;
use App\Models\Invoice;
use App\Models\XeroConnection;
use App\Services\Xero\XeroClient;
use Illuminate\Console\Command;

/** Hourly fallback for webhooks: refresh status/amount due of open invoices (50 per Xero call). */
class XeroSyncInvoices extends Command
{
    protected $signature = 'apq:xero:sync-invoices';

    protected $description = 'Update payment status of open APQ invoices from Xero';

    public function handle(XeroClient $xero): int
    {
        if (! XeroConnection::current()) {
            $this->line('Xero is not connected.');

            return self::SUCCESS;
        }

        $updated = 0;
        Invoice::query()->whereNotNull('xero_invoice_id')
            ->whereIn('xero_status', ['DRAFT', 'SUBMITTED', 'AUTHORISED'])
            ->where('sync_status', Invoice::SYNC_SYNCED)
            ->chunkById(50, function ($invoices) use ($xero, &$updated) {
                $remote = collect($xero->get('Invoices', ['IDs' => $invoices->pluck('xero_invoice_id')->implode(',')])['Invoices'] ?? [])
                    ->keyBy('InvoiceID');
                foreach ($invoices as $invoice) {
                    if ($r = $remote->get($invoice->xero_invoice_id)) {
                        RefreshXeroInvoice::apply($invoice, $r);
                        $updated++;
                    }
                }
            });

        $this->info("Checked {$updated} open invoice(s).");

        return self::SUCCESS;
    }
}
