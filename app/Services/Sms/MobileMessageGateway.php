<?php

namespace App\Services\Sms;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mobile Message v1 API — the only class that knows the provider's request/response shape.
 * ⚠ Verify against https://mobilemessage.com.au API docs before going live (see config/mobilemessage.php).
 *
 * Assumed: POST {base}/messages, HTTP Basic auth,
 *   body {"messages":[{"to","message","sender","custom_ref"}]}
 *   response {"status":"complete","results":[{"custom_ref","status":"success","message_id","cost"}]}
 */
class MobileMessageGateway implements SmsGateway
{
    public function send(string $to, string $body, string $customRef): array
    {
        $started = microtime(true);
        try {
            $res = Http::baseUrl(config('mobilemessage.base_url'))
                ->withBasicAuth((string) config('mobilemessage.username'), (string) config('mobilemessage.password'))
                ->acceptJson()->asJson()->timeout(config('mobilemessage.timeout'))
                ->post('/messages', ['messages' => [[
                    'to' => $to,
                    'message' => $body,
                    'sender' => config('mobilemessage.sender'),
                    'custom_ref' => $customRef,
                ]]]);
        } catch (ConnectionException) {
            $this->log(null, $started, 'connection error');

            return ['ok' => false, 'provider_id' => null, 'cost' => null, 'error' => __('sms.unreachable')];
        }

        $result = collect($res->json('results', []))->firstWhere('custom_ref', $customRef) ?? ($res->json('results.0') ?? []);
        $ok = $res->successful() && in_array(strtolower((string) ($result['status'] ?? '')), ['success', 'sent', 'queued', 'accepted'], true);
        $error = $ok ? null : ($result['error'] ?? $result['message'] ?? $res->json('error') ?? $res->json('message') ?? 'HTTP '.$res->status());
        $this->log($res->status(), $started, $ok ? null : (string) $error);

        return [
            'ok' => $ok,
            'provider_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'cost' => isset($result['cost']) ? (float) $result['cost'] : null,
            'error' => $ok ? null : (string) $error,
        ];
    }

    private function log(?int $status, float $started, ?string $summary): void
    {
        IntegrationLog::create([
            'service' => 'mobile_message', 'direction' => 'request', 'method' => 'POST', 'endpoint' => 'messages',
            'http_status' => $status, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'summary' => $summary ? mb_substr($summary, 0, 500) : null,
        ]);
    }
}
