<?php

namespace App\Services\Xero;

use App\Models\IntegrationLog;
use App\Models\XeroConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Xero Accounting API (https://api.xero.com/api.xro/2.0).
 * Adds auth + tenant headers, retries once after a 401 with a refreshed token, and turns
 * failures into XeroException with a kind the caller can act on.
 */
class XeroClient
{
    public function __construct(private XeroAuth $auth) {}

    public function connection(): XeroConnection
    {
        return XeroConnection::current() ?? throw new XeroException(__('xero.not_connected'), 'not_connected');
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, query: $query);
    }

    public function post(string $path, array $body = [], array $query = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, $body, $query, $idempotencyKey);
    }

    public function send(string $method, string $path, array $body = [], array $query = [], ?string $idempotencyKey = null, bool $retried = false): array
    {
        $connection = $this->connection();
        $started = microtime(true);

        try {
            $request = Http::withToken($this->auth->accessToken($connection))
                ->withHeaders(array_filter(['xero-tenant-id' => $connection->tenant_id, 'Idempotency-Key' => $idempotencyKey]))
                ->acceptJson()->timeout(30);
            $url = config('xero.urls.api').'/'.ltrim($path, '/').($query ? '?'.http_build_query($query) : '');
            /** @var Response $res */
            $res = $method === 'GET' ? $request->get($url) : $request->send($method, $url, ['json' => (object) $body]);
        } catch (ConnectionException $e) {
            $this->log($method, $path, null, $started, 'connection error');
            throw new XeroException(__('xero.unreachable'), 'transient');
        }

        $this->log($method, $path, $res->status(), $started, $res->successful() ? null : $this->errorText($res));
        $connection->forceFill(['last_used_at' => now()])->saveQuietly();

        if ($res->status() === 401 && ! $retried) {
            $connection->update(['expires_at' => now()]);   // force a refresh, then try once more

            return $this->send($method, $path, $body, $query, $idempotencyKey, true);
        }

        return match (true) {
            $res->successful() => $res->json() ?? [],
            $res->status() === 400 => throw new XeroException($this->errorText($res), 'validation', status: 400),
            in_array($res->status(), [401, 403], true) => throw new XeroException(__('xero.reconnect_needed'), 'auth', status: $res->status()),
            $res->status() === 404 => throw new XeroException(__('xero.not_found'), 'validation', status: 404),
            $res->status() === 429 => throw new XeroException(__('xero.rate_limited'), 'rate_limited', (int) ($res->header('Retry-After') ?: 60), 429),
            default => throw new XeroException(__('xero.server_error', ['status' => $res->status()]), 'transient', status: $res->status()),
        };
    }

    /** Pull human-readable validation messages out of Xero's error formats. */
    public function errorText(Response $res): string
    {
        $json = $res->json() ?? [];
        $messages = collect($json['Elements'] ?? [])
            ->flatMap(fn ($el) => collect($el['ValidationErrors'] ?? [])->pluck('Message'))
            ->filter()->unique()->values();

        return $messages->isNotEmpty()
            ? $messages->implode('; ')
            : ($json['Message'] ?? $json['Detail'] ?? $json['Title'] ?? 'HTTP '.$res->status());
    }

    private function log(string $method, string $path, ?int $status, float $started, ?string $summary): void
    {
        IntegrationLog::create([
            'service' => 'xero', 'direction' => 'request', 'method' => $method,
            'endpoint' => preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/i', '{id}', strtok($path, '?')),
            'http_status' => $status, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'summary' => $summary ? mb_substr($summary, 0, 500) : null,
        ]);
    }
}
