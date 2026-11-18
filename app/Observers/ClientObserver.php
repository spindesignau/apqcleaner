<?php

namespace App\Observers;

use App\Jobs\PushClientToXero;
use App\Models\Client;
use App\Models\XeroConnection;
use App\Services\Xero\ContactMapper;

/**
 * APQ → Xero: queue a push whenever a linked client's contact details change (Xero-originated saves are quiet).
 * Unlinked clients are linked by the Xero import page or automatically on their first invoice.
 */
class ClientObserver
{
    public function saved(Client $client): void
    {
        if (! $client->xero_contact_id) {
            return;
        }
        if (collect(ContactMapper::SYNCED)->contains(fn ($f) => $client->wasChanged($f)) && XeroConnection::current()) {
            PushClientToXero::dispatch($client->id)->afterCommit();
        }
    }
}
