<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshXeroContact;
use App\Jobs\RefreshXeroInvoice;
use App\Models\IntegrationLog;
use App\Models\XeroConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Xero webhooks: verifies x-xero-signature (base64 HMAC-SHA256 of the raw body with the webhook key).
 * Xero's "intent to receive" check sends bad signatures expecting 401 and good ones expecting 200 with an empty body.
 */
class XeroWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $key = (string) config('xero.webhook_key');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $key, true));

        if ($key === '' || ! hash_equals($expected, (string) $request->header('x-xero-signature'))) {
            return response('', 401);
        }

        $tenant = XeroConnection::current()?->tenant_id;
        $events = collect($request->json('events', []))
            ->when($tenant, fn ($c) => $c->where('tenantId', $tenant));
        $invoices = $events->where('eventCategory', 'INVOICE')->pluck('resourceId')->unique();
        $contacts = $events->where('eventCategory', 'CONTACT')->pluck('resourceId')->unique();

        $invoices->each(fn ($id) => RefreshXeroInvoice::dispatch($id));
        $contacts->each(fn ($id) => RefreshXeroContact::dispatch($id));
        IntegrationLog::create(['service' => 'xero', 'direction' => 'webhook', 'endpoint' => 'webhooks/xero', 'http_status' => 200,
            'summary' => $invoices->count().' invoice / '.$contacts->count().' contact event(s)']);

        return response('', 200);
    }
}
