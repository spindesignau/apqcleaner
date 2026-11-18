<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\XeroConnection;
use App\Services\Xero\XeroContacts;
use App\Services\Xero\XeroException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Sends a client's contact details to Xero (creating or linking the contact if needed). */
class PushClientToXero implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $clientId) {}

    public function middleware(): array
    {
        return [new RateLimited('xero'), (new WithoutOverlapping('client-'.$this->clientId))->releaseAfter(30)];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(XeroContacts $contacts): void
    {
        $client = Client::withTrashed()->find($this->clientId);
        if (! $client || ! XeroConnection::current()) {
            return;
        }

        try {
            $contacts->push($client);
        } catch (XeroException $e) {
            $client->forceFill(['xero_sync_error' => mb_substr($e->getMessage(), 0, 255)])->saveQuietly();
            if ($e->retryable()) {
                throw $e;   // retried with backoff
            }
        }
    }
}
