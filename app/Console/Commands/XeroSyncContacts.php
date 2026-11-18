<?php

namespace App\Console\Commands;

use App\Jobs\PushClientToXero;
use App\Models\Client;
use App\Models\XeroConnection;
use App\Services\Settings;
use App\Services\Xero\XeroContacts;
use App\Services\Xero\XeroException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Hourly two-way catch-up: pull contacts changed in Xero, re-push clients whose last push didn't land. */
class XeroSyncContacts extends Command
{
    protected $signature = 'apq:xero:sync-contacts {--full : Check every contact, not just those changed since the last sync}';

    protected $description = 'Sync client contact details with Xero in both directions';

    public function handle(XeroContacts $contacts, Settings $settings): int
    {
        if (! XeroConnection::current()) {
            $this->line('Xero is not connected.');

            return self::SUCCESS;
        }

        $started = now();
        $last = $settings->get('xero.contacts_synced_at');
        $since = $this->option('full') || ! $last ? null : Carbon::parse($last)->subMinutes(5);

        try {
            $remote = collect($contacts->fetch($since))->keyBy('ContactID');
        } catch (XeroException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $pulled = 0;
        Client::whereIn('xero_contact_id', $remote->keys())->each(function (Client $client) use ($contacts, $remote, &$pulled) {
            $contacts->pull($client, $remote->get($client->xero_contact_id));
            $pulled++;
        });

        $pushed = 0;
        Client::whereNotNull('xero_contact_id')->whereNotIn('xero_contact_id', $remote->keys())->each(function (Client $client) use ($contacts, &$pushed) {
            if ($client->xero_sync_error || $client->xero_hash === null || $contacts->hasUnsentEdits($client)) {
                PushClientToXero::dispatch($client->id);
                $pushed++;
            }
        });

        $settings->set('xero.contacts_synced_at', $started->toIso8601String());
        $this->info("Updated {$pulled} client(s) from Xero, queued {$pushed} for sending to Xero.");

        return self::SUCCESS;
    }
}
