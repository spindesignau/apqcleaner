<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\XeroConnection;
use App\Services\Audit;
use App\Services\Xero\ContactMapper;
use App\Services\Xero\XeroContacts;
use App\Services\Xero\XeroException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → Xero → Import clients: lists Xero contacts, shows which are already linked, which match an
 * existing APQ client (by email or name) and which are new, and imports / links the ones ticked.
 */
class XeroContactImportController extends Controller
{
    public function index(Request $request, XeroContacts $xero, ContactMapper $mapper): View|RedirectResponse
    {
        if (! XeroConnection::current()) {
            return redirect()->route('admin.xero.show')->withErrors(['xero' => __('xero.not_connected')]);
        }
        if ($request->boolean('refresh')) {
            Cache::forget($this->cacheKey());
        }

        try {
            $contacts = $this->contacts($xero);
        } catch (XeroException $e) {
            return redirect()->route('admin.xero.show')->withErrors(['xero' => $e->getMessage()]);
        }

        $customersOnly = $request->query('show', 'customers') === 'customers';
        $search = mb_strtolower(trim((string) $request->query('q')));
        $rows = $this->classify($contacts, $mapper)
            ->when($customersOnly, fn ($rows) => $rows->filter(fn ($r) => $r['is_customer'] || $r['status'] !== 'new'))
            ->when($search !== '', fn ($rows) => $rows->filter(fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['email'].' '.$r['suburb']), $search)))
            ->values();

        return view('admin.xero.contacts', [
            'rows' => $rows,
            'counts' => $rows->countBy('status'),
            'customersOnly' => $customersOnly,
            'search' => $request->query('q'),
        ]);
    }

    public function store(Request $request, XeroContacts $xero, ContactMapper $mapper): RedirectResponse
    {
        $ids = $request->validate(['contacts' => ['required', 'array', 'max:2000'], 'contacts.*' => ['string', 'size:36']])['contacts'];

        try {
            $rows = $this->classify($this->contacts($xero), $mapper)->keyBy('id')->only($ids);
        } catch (XeroException $e) {
            return back()->withErrors(['xero' => $e->getMessage()]);
        }

        $imported = $linked = 0;
        DB::transaction(function () use ($rows, $xero, &$imported, &$linked) {
            $used = [];
            foreach ($rows as $row) {
                if ($row['status'] === 'match' && ! isset($used[$row['client_id']])) {
                    $used[$row['client_id']] = true;
                    $xero->link(Client::findOrFail($row['client_id']), $row['contact']);
                    $linked++;
                } elseif ($row['status'] === 'new') {
                    $xero->import($row['contact']);
                    $imported++;
                }
            }
        });
        Cache::forget($this->cacheKey());
        Audit::log('xero.contacts_imported', null, after: ['imported' => $imported, 'linked' => $linked]);

        return redirect()->route('admin.xero.contacts')->with('status', __('xero.import_done', ['imported' => $imported, 'linked' => $linked]));
    }

    public function sync(): RedirectResponse
    {
        Artisan::call('apq:xero:sync-contacts');

        return back()->with('status', trim(Artisan::output()) ?: __('xero.sync_done'));
    }

    private function contacts(XeroContacts $xero): array
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(15), fn () => $xero->fetch());
    }

    private function cacheKey(): string
    {
        return 'xero.contacts.'.auth()->id();
    }

    /** @return Collection<int, array> one row per Xero contact: linked | match | new */
    private function classify(array $contacts, ContactMapper $mapper): Collection
    {
        $clients = Client::query()->get(['id', 'display_name', 'billing_name', 'email', 'xero_contact_id']);
        $linked = $clients->whereNotNull('xero_contact_id')->keyBy('xero_contact_id');
        $unlinked = $clients->whereNull('xero_contact_id');
        $byEmail = $unlinked->filter(fn ($c) => filled($c->email))->groupBy(fn ($c) => mb_strtolower(trim($c->email)));
        $byName = $unlinked->groupBy(fn ($c) => mb_strtolower(trim($c->billing_name)))
            ->union($unlinked->groupBy(fn ($c) => mb_strtolower(trim($c->display_name))));

        return collect($contacts)
            ->filter(fn ($c) => ($c['ContactStatus'] ?? 'ACTIVE') === 'ACTIVE' && filled($c['ContactID'] ?? null))
            ->map(function (array $c) use ($mapper, $linked, $byEmail, $byName) {
                $details = $mapper->fromXero($c);
                $email = mb_strtolower($details['email'] ?? '');
                $match = $linked->get($c['ContactID'])
                    ?? (($byEmail->get($email)?->count() === 1) ? $byEmail->get($email)->first() : null)
                    ?? (($byName->get(mb_strtolower($details['billing_name'] ?? ''))?->count() === 1) ? $byName->get(mb_strtolower($details['billing_name']))->first() : null);

                return [
                    'id' => $c['ContactID'],
                    'contact' => $c,
                    'name' => $details['billing_name'] ?? '—',
                    'email' => $details['email'] ?? null,
                    'mobile' => $details['mobile'] ?? $details['phone_alt'] ?? null,
                    'suburb' => $details['suburb'] ?? null,
                    'is_customer' => (bool) ($c['IsCustomer'] ?? false),
                    'status' => $linked->has($c['ContactID']) ? 'linked' : ($match ? 'match' : 'new'),
                    'client_id' => $match?->id,
                    'client_name' => $match?->display_name,
                ];
            })->values();
    }
}
