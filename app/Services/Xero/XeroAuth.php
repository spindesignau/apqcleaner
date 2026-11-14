<?php

namespace App\Services\Xero;

use App\Models\IntegrationLog;
use App\Models\User;
use App\Models\XeroConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth 2.0 (authorisation code flow) against Xero Identity, and token lifecycle.
 * Access tokens last 30 min; refresh tokens are single-use and expire after 60 days unused.
 */
class XeroAuth
{
    public function isConfigured(): bool
    {
        return filled(config('xero.client_id')) && filled(config('xero.client_secret'));
    }

    public function authorizeUrl(string $state): string
    {
        return config('xero.urls.authorize').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('xero.client_id'),
            'redirect_uri' => config('xero.redirect_uri'),
            'scope' => config('xero.scopes'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange the callback code for tokens and store the (single) organisation connection. */
    public function connect(string $code, User $by): XeroConnection
    {
        $tokens = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('xero.redirect_uri')]);

        $connections = Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)->get(config('xero.urls.connections'));
        $this->log('GET', 'connections', $connections->status());
        $orgs = collect($connections->json() ?? [])->where('tenantType', 'ORGANISATION')->values();
        if ($orgs->isEmpty()) {
            throw new XeroException(__('xero.no_organisation'), 'auth');
        }
        if ($orgs->count() > 1) {
            // APQ uses one Xero file. If the admin ticked several, keep the first and say so on screen.
            session()->flash('warning', __('xero.multiple_orgs', ['name' => $orgs[0]['tenantName'] ?? '']));
        }
        $org = $orgs[0];

        XeroConnection::where('is_active', true)->update(['is_active' => false]);

        return XeroConnection::create([
            'connection_id' => $org['id'] ?? null,
            'tenant_id' => $org['tenantId'],
            'tenant_name' => $org['tenantName'] ?? null,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 1800)),
            'scopes' => $tokens['scope'] ?? config('xero.scopes'),
            'connected_by' => $by->id,
            'is_active' => true,
        ]);
    }

    /** A valid access token, refreshed when it is within 5 minutes of expiry. */
    public function accessToken(XeroConnection $connection): string
    {
        if ($connection->expires_at->gt(now()->addMinutes(5))) {
            return $connection->access_token;
        }

        // Refresh tokens are single-use: never let two workers refresh at once.
        return Cache::lock('xero-token-refresh', 30)->block(20, function () use ($connection) {
            $fresh = $connection->fresh();
            if ($fresh->expires_at->gt(now()->addMinutes(5))) {
                return $fresh->access_token;
            }

            try {
                $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $fresh->refresh_token]);
            } catch (XeroException $e) {
                if ($e->kind === 'auth') {
                    $fresh->update(['is_active' => false, 'last_error' => $e->getMessage()]);
                }
                throw $e;
            }

            $fresh->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $fresh->refresh_token,
                'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 1800)),
                'last_error' => null,
            ]);
            $connection->setRawAttributes($fresh->getAttributes(), true);

            return $fresh->access_token;
        });
    }

    /** Force a refresh now (weekly keep-alive, so a quiet period never lets the 60-day refresh token lapse). */
    public function refreshNow(XeroConnection $connection): void
    {
        $connection->update(['expires_at' => Carbon::now()]);
        $this->accessToken($connection);
    }

    public function disconnect(XeroConnection $connection): void
    {
        try {
            if ($connection->connection_id) {
                $res = Http::withToken($this->accessToken($connection))->timeout(20)
                    ->delete(config('xero.urls.connections').'/'.$connection->connection_id);
                $this->log('DELETE', 'connections/{id}', $res->status());
            }
            Http::asForm()->withBasicAuth(config('xero.client_id'), config('xero.client_secret'))->timeout(20)
                ->post(config('xero.urls.revoke'), ['token' => $connection->refresh_token]);
        } catch (\Throwable) {
            // Best effort: the local connection is deactivated regardless.
        }
        $connection->update(['is_active' => false]);
    }

    private function tokenRequest(array $form): array
    {
        $res = Http::asForm()->acceptJson()->timeout(20)
            ->withBasicAuth((string) config('xero.client_id'), (string) config('xero.client_secret'))
            ->post(config('xero.urls.token'), $form);
        $this->log('POST', 'identity/token ('.$form['grant_type'].')', $res->status());

        if ($res->successful() && $res->json('access_token')) {
            return $res->json();
        }

        $error = $res->json('error') ?? 'http_'.$res->status();
        throw new XeroException(
            match ($error) {
                'invalid_grant' => __('xero.reconnect_needed'),
                'invalid_scope' => __('xero.invalid_scope'),
                'invalid_client', 'unauthorized_client' => __('xero.bad_credentials'),
                default => __('xero.token_failed', ['error' => $error]),
            },
            in_array($error, ['invalid_grant', 'invalid_scope', 'invalid_client', 'unauthorized_client'], true) ? 'auth' : 'transient',
            status: $res->status(),
        );
    }

    private function log(string $method, string $endpoint, ?int $status): void
    {
        IntegrationLog::create(['service' => 'xero', 'direction' => 'request', 'method' => $method, 'endpoint' => $endpoint, 'http_status' => $status]);
    }
}
