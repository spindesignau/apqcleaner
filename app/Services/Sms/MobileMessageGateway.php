<?php

namespace App\Services\Sms;

use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mobile Message v1 API (https://mobilemessage.com.au/api-documentation, checked 14 Nov 2026).
 *
 *  POST /v1/messages   Basic auth · {"messages":[{"to","message","sender","custom_ref"}]} · Idempotency-Key header
 *    to: Australian mobile only, local (0412345678) or international without "+" (61412345678)
 *    per-message status: success | error | blocked (recipient unsubscribed)
 *    HTTP: 401 credentials · 400 malformed · 403 insufficient credits · 429 >5 concurrent · 5xx retry same key
 *  GET  /v1/account    {"credit_balance": n}
 *  GET  /v1/senders    approved sender IDs (the sender must be one of these)
 */
class MobileMessageGateway implements SmsGateway
{
    public function send(string $to, string $body, string $customRef): array
    {
        $started = microtime(true);
        try {
            $res = $this->http()
                ->withHeaders(['Idempotency-Key' => self::idempotencyKey($customRef)])
                ->post('v1/messages', ['messages' => [[
                    'to' => self::providerNumber($to),
                    'message' => $body,
                    'sender' => config('mobilemessage.sender'),
                    'custom_ref' => $customRef,
                ]]]);
        } catch (ConnectionException) {
            $this->log('POST', 'v1/messages', null, $started, 'connection error');

            return ['ok' => false, 'provider_id' => null, 'cost' => null, 'error' => __('sms.unreachable'), 'retryable' => true];
        }

        if (! $res->successful()) {
            $error = match ($res->status()) {
                401 => __('sms.err_credentials'),
                403 => __('sms.err_credits'),
                429 => __('sms.err_busy'),
                default => $this->errorText($res),
            };
            $this->log('POST', 'v1/messages', $res->status(), $started, $error);

            return ['ok' => false, 'provider_id' => null, 'cost' => null, 'error' => $error, 'retryable' => $res->status() === 429 || $res->serverError()];
        }

        $result = collect($res->json('results', []))->firstWhere('custom_ref', $customRef) ?? ($res->json('results.0') ?? []);
        $status = strtolower((string) ($result['status'] ?? ''));
        $ok = $status === 'success';
        $error = match (true) {
            $ok => null,
            $status === 'blocked' => __('sms.err_blocked'),
            default => (string) ($result['error'] ?? __('sms.err_rejected')),
        };
        $this->log('POST', 'v1/messages', $res->status(), $started, $error);

        return [
            'ok' => $ok,
            'provider_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'cost' => isset($result['cost']) ? (float) $result['cost'] : null,
            'error' => $error,
            'blocked' => $status === 'blocked',
            'retryable' => false,
        ];
    }

    /** @return array{ok: bool, balance: ?float, senders: string[], error: ?string} */
    public function account(): array
    {
        $started = microtime(true);
        try {
            $account = $this->http()->get('v1/account');
            $senders = $this->http()->get('v1/senders');
        } catch (ConnectionException) {
            return ['ok' => false, 'balance' => null, 'senders' => [], 'error' => __('sms.unreachable')];
        }
        $this->log('GET', 'v1/account', $account->status(), $started, null);
        if (! $account->successful()) {
            return ['ok' => false, 'balance' => null, 'senders' => [], 'error' => $account->status() === 401 ? __('sms.err_credentials') : $this->errorText($account)];
        }

        $list = $senders->json('results') ?? [];   // documented shape: {"results":[{"sender","type","label","is_default"}]}

        return [
            'ok' => true,
            'balance' => $account->json('credit_balance') !== null ? (float) $account->json('credit_balance') : null,
            'senders' => collect($list)->map(fn ($s) => is_array($s) ? (string) ($s['sender'] ?? '') : (string) $s)->filter()->values()->all(),
            'error' => null,
        ];
    }

    /** "+61412345678" → "61412345678" (the API rejects the "+"). */
    public static function providerNumber(string $e164): string
    {
        return ltrim(preg_replace('/\s+/', '', $e164), '+');
    }

    /** A stable UUID per message, so a retried request is recognised and never sent twice. */
    public static function idempotencyKey(string $customRef): string
    {
        $h = md5('apq-sms-'.$customRef);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('mobilemessage.base_url'), '/').'/')
            ->withBasicAuth((string) config('mobilemessage.username'), (string) config('mobilemessage.password'))
            ->acceptJson()->asJson()->timeout(config('mobilemessage.timeout'));
    }

    private function errorText(Response $res): string
    {
        return (string) ($res->json('error') ?? $res->json('message') ?? 'HTTP '.$res->status());
    }

    private function log(string $method, string $endpoint, ?int $status, float $started, ?string $summary): void
    {
        IntegrationLog::create([
            'service' => 'mobile_message', 'direction' => 'request', 'method' => $method, 'endpoint' => $endpoint,
            'http_status' => $status, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'summary' => $summary ? mb_substr($summary, 0, 500) : null,
        ]);
    }
}
