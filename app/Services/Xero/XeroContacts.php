<?php

namespace App\Services\Xero;

use App\Jobs\PushClientToXero;
use App\Jobs\RefreshXeroInvoice;
use App\Models\Client;
use Illuminate\Support\Carbon;

/**
 * Two-way sync between APQ clients and Xero contacts (contact details only — see ContactMapper::SYNCED).
 *
 *  - APQ → Xero: a client's details are pushed whenever they change (ClientObserver → PushClientToXero).
 *  - Xero → APQ: webhooks (RefreshXeroContact) and the hourly apq:xero:sync-contacts pull changes back.
 *  - Conflicts: if APQ has edits Xero hasn't received yet (hash differs from xero_hash), APQ's version is pushed;
 *    otherwise Xero's version is applied. Blank fields in Xero never erase APQ data.
 */
class XeroContacts
{
    public function __construct(private XeroClient $xero, private ContactMapper $mapper) {}

    /** The client's Xero ContactID, linking to a matching contact or creating one if needed. */
    public function ensure(Client $client): string
    {
        return $client->xero_contact_id ?: $this->push($client);
    }

    /** Send APQ's details to Xero. Unlinked clients are first matched (email, then name) and merged, else created. */
    public function push(Client $client): string
    {
        if (! $client->xero_contact_id && ($match = $this->findContact($client))) {
            $this->link($client, $match, pushDifferences: false);
        }

        $payload = $this->mapper->toXero($client);
        $remote = $this->xero->post('Contacts', ['Contacts' => [$payload]])['Contacts'][0] ?? null;
        if (! $remote || ! empty($remote['ValidationErrors']) || empty($remote['ContactID'])) {
            throw new XeroException(collect($remote['ValidationErrors'] ?? [])->pluck('Message')->implode('; ') ?: __('xero.contact_failed'), 'validation');
        }
        $this->markSynced($client, $remote);

        return $remote['ContactID'];
    }

    /** Apply a Xero contact to its linked client — unless APQ has unsent edits, in which case APQ wins. */
    public function pull(Client $client, array $contact): void
    {
        if ($this->hasUnsentEdits($client)) {
            PushClientToXero::dispatch($client->id);

            return;
        }
        $client->forceFill($this->mapper->fromXero($contact));
        $this->markSynced($client, $contact);
    }

    /**
     * Link an existing APQ client to a Xero contact. Xero's details win where it has them; anything only
     * APQ knows (e.g. a mobile Xero lacks) is then pushed so both sides match.
     */
    public function link(Client $client, array $contact, bool $pushDifferences = true): void
    {
        $fromXero = $this->mapper->fromXero($contact);
        $client->forceFill($fromXero + ['xero_contact_id' => $contact['ContactID']]);
        $needsPush = $pushDifferences && collect(ContactMapper::SYNCED)->contains(fn ($f) => ! isset($fromXero[$f])
            && filled($client->getAttribute($f)) && $client->getAttribute($f) !== ContactMapper::MISSING_ADDRESS);
        $this->markSynced($client, $contact);

        if ($needsPush) {
            PushClientToXero::dispatch($client->id);
        }
    }

    /** New APQ client from a Xero contact. */
    public function import(array $contact): Client
    {
        $details = $this->mapper->fromXero($contact);
        $client = new Client([
            'display_name' => mb_substr(trim($details['billing_name'] ?? 'Xero contact').(isset($details['suburb']) ? ' – '.$details['suburb'] : ''), 0, 150),
            'address_line1' => ContactMapper::MISSING_ADDRESS,
            'is_active' => true,
        ]);
        $client->forceFill($details + ['xero_contact_id' => $contact['ContactID']]);
        $this->markSynced($client, $contact);

        return $client;
    }

    /** All active contacts (or only those changed since $since), following Xero's paging. */
    public function fetch(?Carbon $since = null): array
    {
        $headers = $since ? ['If-Modified-Since' => $since->copy()->utc()->format('Y-m-d\TH:i:s')] : [];
        $all = [];
        for ($page = 1; $page <= 100; $page++) {
            $batch = $this->xero->get('Contacts', ['page' => $page, 'pageSize' => 500, 'order' => 'Name'], $headers)['Contacts'] ?? [];
            if ($batch === []) {
                break;
            }
            foreach ($batch as $c) {   // keep only what APQ uses (the import list is cached)
                $all[] = array_intersect_key($c, array_flip(['ContactID', 'ContactStatus', 'Name', 'EmailAddress', 'Phones', 'Addresses', 'IsCustomer', 'UpdatedDateUTC']));
            }
        }

        return $all;
    }

    public function contact(string $contactId): ?array
    {
        return $this->xero->get("Contacts/{$contactId}")['Contacts'][0] ?? null;
    }

    public function hasUnsentEdits(Client $client): bool
    {
        return $client->xero_hash !== null && $client->xero_hash !== $this->mapper->hash($client);
    }

    /** One unambiguous Xero contact with the client's email, else with their billing name. */
    public function findContact(Client $client): ?array
    {
        foreach (array_filter(['EmailAddress' => $client->email, 'Name' => $client->billing_name]) as $field => $value) {
            $where = sprintf('%s=="%s"', $field, addcslashes($value, '"\\'));
            $found = $this->xero->get('Contacts', ['where' => $where, 'includeArchived' => 'false'])['Contacts'] ?? [];
            if (count($found) === 1) {
                return $found[0];
            }
        }

        return null;
    }

    private function markSynced(Client $client, array $contact): void
    {
        $client->forceFill([
            'xero_contact_id' => $contact['ContactID'] ?? $client->xero_contact_id,
            'xero_synced_at' => now(),
            'xero_updated_at' => RefreshXeroInvoice::xeroDate($contact['UpdatedDateUTC'] ?? null) ?? now(),
            'xero_sync_error' => null,
        ]);
        $client->xero_hash = $this->mapper->hash($client);
        $client->saveQuietly();   // quietly: must not trigger another push
    }
}
