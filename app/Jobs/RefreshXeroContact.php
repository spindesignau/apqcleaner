<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Xero\XeroContacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/** Pulls a Xero contact's details into its linked APQ client (from a CONTACT webhook). */
class RefreshXeroContact implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $contactId) {}

    public function middleware(): array
    {
        return [new RateLimited('xero')];
    }

    public function handle(XeroContacts $contacts): void
    {
        $client = Client::where('xero_contact_id', $this->contactId)->first();
        if (! $client) {
            return;   // not one of APQ's clients (yet) — import it from Admin → Xero
        }
        if ($contact = $contacts->contact($this->contactId)) {
            $contacts->pull($client, $contact);
        }
    }
}
