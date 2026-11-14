<?php

namespace App\Console\Commands;

use App\Models\XeroConnection;
use App\Services\Xero\XeroAuth;
use Illuminate\Console\Command;

/** Weekly token refresh so a quiet month never lets Xero's 60-day refresh token expire. */
class XeroKeepalive extends Command
{
    protected $signature = 'apq:xero:keepalive';

    protected $description = 'Refresh the Xero tokens so the connection never lapses';

    public function handle(XeroAuth $auth): int
    {
        if ($connection = XeroConnection::current()) {
            $auth->refreshNow($connection);
            $this->info('Xero connection refreshed.');
        }

        return self::SUCCESS;
    }
}
