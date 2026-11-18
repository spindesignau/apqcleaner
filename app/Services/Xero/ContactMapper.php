<?php

namespace App\Services\Xero;

use App\Models\Client;
use App\Support\AuPhone;

/**
 * Converts between an APQ client's contact details and a Xero contact.
 * Only these fields sync both ways; pricing, notes, access details and schedules stay in APQ.
 */
class ContactMapper
{
    public const SYNCED = ['billing_name', 'email', 'mobile', 'phone_alt', 'address_line1', 'address_line2', 'suburb', 'state', 'postcode'];

    public const MISSING_ADDRESS = '(address missing)';

    private const STATES = ['NSW', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'ACT', 'NT'];

    /** Client attributes from a Xero contact. Fields Xero leaves blank are omitted (they never wipe APQ data). */
    public function fromXero(array $contact): array
    {
        $phones = collect($contact['Phones'] ?? [])->keyBy('PhoneType')
            ->map(fn ($p) => trim(($p['PhoneCountryCode'] ?? '').' '.($p['PhoneAreaCode'] ?? '').' '.($p['PhoneNumber'] ?? '')));
        $mobile = AuPhone::normalise($phones->get('MOBILE') ?: null);
        $other = AuPhone::normalise($phones->get('DEFAULT') ?: ($phones->get('DDI') ?: null));
        if (! $mobile && AuPhone::isMobile($other)) {
            [$mobile, $other] = [$other, null];
        }

        $address = collect($contact['Addresses'] ?? [])->firstWhere('AddressType', 'STREET') ?? [];
        $line2 = trim(implode(' ', array_filter([$address['AddressLine2'] ?? null, $address['AddressLine3'] ?? null, $address['AddressLine4'] ?? null])));
        $state = strtoupper(trim((string) ($address['Region'] ?? '')));
        $state = collect(['NEW SOUTH WALES' => 'NSW', 'VICTORIA' => 'VIC', 'QUEENSLAND' => 'QLD', 'SOUTH AUSTRALIA' => 'SA', 'WESTERN AUSTRALIA' => 'WA', 'TASMANIA' => 'TAS', 'AUSTRALIAN CAPITAL TERRITORY' => 'ACT', 'NORTHERN TERRITORY' => 'NT'])->get($state, $state);

        return array_filter([
            'billing_name' => mb_substr(trim((string) ($contact['Name'] ?? '')), 0, 150),
            'email' => mb_substr(trim((string) ($contact['EmailAddress'] ?? '')), 0, 190),
            'mobile' => $mobile ? mb_substr($mobile, 0, 20) : null,
            'phone_alt' => $other ? mb_substr($other, 0, 30) : null,
            'address_line1' => mb_substr(trim((string) ($address['AddressLine1'] ?? '')), 0, 190),
            'address_line2' => mb_substr($line2, 0, 190),
            'suburb' => mb_substr(trim((string) ($address['City'] ?? '')), 0, 80),
            'state' => in_array($state, self::STATES, true) ? $state : null,
            'postcode' => preg_match('/^\d{4}$/', trim((string) ($address['PostalCode'] ?? ''))) ? trim($address['PostalCode']) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Xero contact payload for a client (ContactID added when already linked). */
    public function toXero(Client $client): array
    {
        $phones = array_values(array_filter([
            $client->mobile ? ['PhoneType' => 'MOBILE', 'PhoneNumber' => AuPhone::display($client->mobile)] : null,
            $client->phone_alt ? ['PhoneType' => 'DEFAULT', 'PhoneNumber' => AuPhone::display($client->phone_alt)] : null,
        ]));
        $hasAddress = $client->address_line1 && $client->address_line1 !== self::MISSING_ADDRESS;

        return array_filter([
            'ContactID' => $client->xero_contact_id,
            'Name' => $client->billing_name,
            'EmailAddress' => $client->email,
            'Phones' => $phones ?: null,
            'Addresses' => $hasAddress ? [[
                'AddressType' => 'STREET',
                'AddressLine1' => $client->address_line1,
                'AddressLine2' => (string) $client->address_line2,
                'City' => (string) $client->suburb,
                'Region' => (string) $client->state,
                'PostalCode' => (string) $client->postcode,
                'Country' => 'Australia',
            ]] : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Fingerprint of the synced fields, to tell whether APQ has edits Xero hasn't seen yet. */
    public function hash(Client $client): string
    {
        return hash('sha256', json_encode(collect(self::SYNCED)->mapWithKeys(fn ($f) => [$f => (string) $client->getAttribute($f)])->all()));
    }
}
