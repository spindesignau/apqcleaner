<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\IntegrationLog;
use App\Models\SmsMessage;
use App\Services\Audit;
use App\Support\AuPhone;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Mobile Message delivery receipts and inbound replies (https://mobilemessage.com.au/api-documentation → Webhooks).
 *  - URL contains a secret token (MOBILEMESSAGE_WEBHOOK_TOKEN); optionally also signed with X-MM-Signature.
 *  - Status payload: status (delivered|failed), message_id, custom_ref, part_number, total_parts.
 *    Long messages send one receipt per part, retries can repeat, order isn't guaranteed — so handling is idempotent
 *    and a "failed" part is never overwritten by a later "delivered" one.
 *  - Inbound payload: type (inbound|unsubscribe), sender (their number), to, message, received_at.
 */
class MobileMessageWebhookController extends Controller
{
    private const OPT_OUT_WORDS = ['STOP', 'STOP ALL', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'PARAR', 'BAJA'];

    public function status(Request $request, string $token): Response
    {
        $this->authorizeRequest($request, $token);
        $id = $request->input('message_id') ?? $request->input('id');
        $ref = $request->input('custom_ref') ?? $request->input('reference');
        $raw = Str::lower((string) ($request->input('status') ?? $request->input('delivery_status') ?? ''));

        $message = SmsMessage::query()
            ->when($id, fn ($q) => $q->where('provider_message_id', $id), fn ($q) => $q->where('custom_ref', $ref))
            ->first();
        if ($message) {
            $status = match (true) {
                $raw === 'failed' => 'failed',
                $raw === 'delivered' && $message->status !== 'failed' => 'delivered',
                default => $message->status,
            };
            $message->update([
                'status' => $status,
                'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
                'status_detail' => $status === 'failed' ? __('sms.delivery_failed') : $message->status_detail,
            ]);
        }
        IntegrationLog::create(['service' => 'mobile_message', 'direction' => 'webhook', 'endpoint' => 'status', 'http_status' => 200, 'summary' => $raw ?: 'status']);

        return response('', 200);
    }

    public function inbound(Request $request, string $token): Response
    {
        $this->authorizeRequest($request, $token);
        $from = AuPhone::normalise((string) ($request->input('sender') ?? $request->input('from') ?? ''));
        $isUnsubscribe = $request->input('type') === 'unsubscribe';
        $body = trim((string) ($request->input('message') ?? $request->input('body') ?? $request->input('text') ?? ''));
        $client = $from ? Client::where('mobile', $from)->first() : null;

        SmsMessage::create([
            'direction' => 'inbound', 'client_id' => $client?->id, 'trigger' => 'system',
            'from_number' => $from, 'to_number' => (string) ($request->input('to') ?? ''), 'body' => mb_substr($body, 0, 2000),
            'status' => 'received', 'segments' => 1,
        ]);

        if ($client && ($isUnsubscribe || in_array(Str::upper(preg_replace('/[^\pL ]/u', '', $body)), self::OPT_OUT_WORDS, true))) {
            $client->forceFill(['sms_opt_in' => false, 'sms_opted_out_at' => now()])->save();
            Audit::log('client.sms_opted_out', $client, reason: 'Replied '.$body);
        }
        IntegrationLog::create(['service' => 'mobile_message', 'direction' => 'webhook', 'endpoint' => 'inbound', 'http_status' => 200, 'summary' => $client ? 'matched client' : 'unknown sender']);

        return response('', 200);
    }

    private function authorizeRequest(Request $request, string $token): void
    {
        $expected = (string) config('mobilemessage.webhook_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        // Optional signing: lowercase hex HMAC-SHA256 of "{timestamp}.{raw body}", timestamp within 5 minutes.
        $secret = (string) config('mobilemessage.webhook_secret');
        if ($secret !== '') {
            $timestamp = (string) $request->header('X-MM-Timestamp', '');
            $signature = (string) $request->header('X-MM-Signature', '');
            abort_if($timestamp === '' || abs(time() - (int) $timestamp) > 300, 400);
            abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature), 401);
        }
    }
}
