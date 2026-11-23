<?php

namespace App\Console\Commands;

use App\Models\XeroConnection;
use App\Services\Audit;
use Illuminate\Console\Command;

/**
 * Removes this site's Xero connection WITHOUT revoking it at Xero — used on staging after the live site takes over,
 * so staging can't create real invoices while the live site's own connection keeps working.
 */
class XeroForget extends Command
{
    protected $signature = 'apq:xero:forget';

    protected $description = 'Remove the stored Xero connection on this site (does not revoke it at Xero)';

    public function handle(): int
    {
        $removed = XeroConnection::query()->delete();
        if ($removed) {
            Audit::log('xero.forgotten');
        }
        $this->info($removed ? 'Xero connection removed from this site.' : 'This site had no Xero connection.');

        return self::SUCCESS;
    }
}
