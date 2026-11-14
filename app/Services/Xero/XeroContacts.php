<?php

namespace App\Services\Xero;

use App\Models\Client;

/**
 * Links an APQ client to a Xero contact: existing link → match by email → match by name → create.
 */
class XeroContacts
{
    public function __construct(private XeroClient $xero) {}

    public function ensure(Client $client): string
    {
        if ($client->xero_contact_id) {
            return $client->xero_contact_id;
        }

        $contactId = $this->find($client) ?? $this->create($client);
        $client->forceFill(['xero_contact_id' => $contactId, 'xero_synced_at' => now()])->save();

        return $contactId;
    }

    public function find(Client $client): ?string
    {
        foreach (array_filter(['EmailAddress' => $client->email, 'Name' => $client->billing_name]) as $field => $value) {
            $where = sprintf('%s=="%s"', $field, addcslashes($value, '"\\'));
            $found = $this->xero->get('Contacts', ['where' => $where, 'includeArchived' => 'false'])['Contacts'] ?? [];
            if (count($found) === 1) {
                return $found[0]['ContactID'];
            }
        }

        return null;
    }

    private function create(Client $client): string
    {
        $contact = array_filter([
            'Name' => $client->billing_name,
            'EmailAddress' => $client->email,
            'Phones' => $client->mobile ? [['PhoneType' => 'MOBILE', 'PhoneNumber' => $client->mobile]] : null,
            'Addresses' => [[
                'AddressType' => 'STREET',
                'AddressLine1' => trim($client->address_line1.' '.$client->address_line2),
                'City' => $client->suburb,
                'Region' => $client->state,
                'PostalCode' => $client->postcode,
                'Country' => 'Australia',
            ]],
        ]);

        $created = $this->xero->post('Contacts', ['Contacts' => [$contact]])['Contacts'][0] ?? null;
        if (! $created || ! empty($created['ValidationErrors'])) {
            throw new XeroException(collect($created['ValidationErrors'] ?? [])->pluck('Message')->implode('; ') ?: __('xero.contact_failed'), 'validation');
        }

        return $created['ContactID'];
    }
}
