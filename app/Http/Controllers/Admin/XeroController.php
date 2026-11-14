<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\XeroConnection;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\Xero\XeroAuth;
use App\Services\Xero\XeroClient;
use App\Services\Xero\XeroException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class XeroController extends Controller
{
    public function show(XeroAuth $auth, Settings $settings): View
    {
        return view('admin.xero.show', [
            'configured' => $auth->isConfigured(),
            'connection' => XeroConnection::current()?->load('connectedBy'),
            'redirectUri' => config('xero.redirect_uri'),
            'webhookUrl' => url('/webhooks/xero'),
            'settings' => collect(['default_account_code', 'cancellation_account_code', 'payment_terms_days', 'default_mode', 'email_by_default'])
                ->mapWithKeys(fn ($k) => [$k => $settings->get('xero.'.$k)])->all(),
            'failed' => Invoice::where('sync_status', Invoice::SYNC_FAILED)->count(),
        ]);
    }

    public function connect(Request $request, XeroAuth $auth): RedirectResponse
    {
        abort_unless($auth->isConfigured(), 422, __('xero.not_configured'));
        $state = Str::random(40);
        $request->session()->put('xero_oauth_state', $state);

        return redirect()->away($auth->authorizeUrl($state));
    }

    public function callback(Request $request, XeroAuth $auth): RedirectResponse
    {
        $expected = $request->session()->pull('xero_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('admin.xero.show')->withErrors(['xero' => __('xero.state_mismatch')]);
        }
        if ($request->filled('error')) {
            return redirect()->route('admin.xero.show')->withErrors(['xero' => __('xero.denied', ['error' => $request->query('error')])]);
        }

        try {
            $connection = $auth->connect((string) $request->query('code'), $request->user());
        } catch (XeroException $e) {
            return redirect()->route('admin.xero.show')->withErrors(['xero' => $e->getMessage()]);
        }
        Audit::log('xero.connected', $connection, after: ['organisation' => $connection->tenant_name]);

        return redirect()->route('admin.xero.show')->with('status', __('xero.connected', ['name' => $connection->tenant_name]));
    }

    public function disconnect(XeroAuth $auth): RedirectResponse
    {
        if ($connection = XeroConnection::current()) {
            $auth->disconnect($connection);
            Audit::log('xero.disconnected', $connection);
        }

        return redirect()->route('admin.xero.show')->with('status', __('xero.disconnected'));
    }

    /** Checks the organisation responds and that the configured account codes exist as revenue accounts. */
    public function test(XeroClient $xero, Settings $settings): RedirectResponse
    {
        try {
            $org = $xero->get('Organisation')['Organisations'][0] ?? [];
            $accounts = collect($xero->get('Accounts')['Accounts'] ?? [])->keyBy('Code');
        } catch (XeroException $e) {
            return back()->withErrors(['xero' => $e->getMessage()]);
        }

        $problems = [];
        foreach (['default_account_code', 'cancellation_account_code'] as $key) {
            $code = (string) $settings->get('xero.'.$key);
            $account = $accounts->get($code);
            if (! $account) {
                $problems[] = __('xero.account_missing', ['code' => $code]);
            } elseif (! in_array($account['Class'] ?? '', ['REVENUE'], true)) {
                $problems[] = __('xero.account_not_revenue', ['code' => $code, 'name' => $account['Name'] ?? '']);
            }
        }

        $message = __('xero.test_ok', ['name' => $org['Name'] ?? '?', 'currency' => $org['BaseCurrency'] ?? '?']);

        return $problems
            ? back()->with('status', $message)->with('warning', implode(' ', $problems))
            : back()->with('status', $message.' '.__('xero.accounts_ok'));
    }

    public function saveSettings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'default_account_code' => ['required', 'string', 'max:10'],
            'cancellation_account_code' => ['required', 'string', 'max:10'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:90'],
            'default_mode' => ['required', Rule::in(['draft', 'authorised'])],
            'email_by_default' => ['nullable', 'boolean'],
        ], attributes: collect(['default_account_code', 'cancellation_account_code', 'payment_terms_days', 'default_mode'])
            ->mapWithKeys(fn ($k) => [$k => __('xero.fields.'.$k)])->all());

        $data['email_by_default'] = $request->boolean('email_by_default');
        foreach ($data as $key => $value) {
            $settings->set('xero.'.$key, $value);
        }
        Audit::log('xero.settings_updated', null, after: $data);

        return back()->with('status', __('xero.settings_saved'));
    }
}
