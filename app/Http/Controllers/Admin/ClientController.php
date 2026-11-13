<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PricingModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Models\Client;
use App\Models\ServiceType;
use App\Services\Audit;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $status = $request->query('status', 'active');

        $digits = ltrim(preg_replace('/\D/', '', $q), '0');   // "0412 345" → matches "+61412345…"

        $clients = Client::query()
            ->withCount('schedules')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('display_name', 'like', "%{$q}%")->orWhere('billing_name', 'like', "%{$q}%")
                ->orWhere('suburb', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('mobile', 'like', "%{$digits}%"))))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('display_name')
            ->paginate(50)->withQueryString();

        return view('admin.clients.index', compact('clients', 'q', 'status'));
    }

    public function create(): View
    {
        return view('admin.clients.form', [
            'client' => new Client(['state' => 'NSW', 'pricing_model' => PricingModel::Fixed, 'preferred_language' => 'en', 'sms_opt_in' => true, 'is_active' => true]),
            'serviceTypes' => ServiceType::active()->get(),
        ]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::create($request->validated());
        Audit::log('client.created', $client);

        return redirect()->route('admin.clients.show', $client)->with('status', __('clients.created'));
    }

    public function show(Client $client): View
    {
        $client->load(['serviceType', 'schedules' => fn ($q) => $q->with('staff')->orderByDesc('is_active')]);
        $upcoming = $client->appointments()->with('staff')
            ->where('scheduled_start', '>=', now()->subDay())
            ->orderBy('scheduled_start')->limit(12)->get();

        return view('admin.clients.show', compact('client', 'upcoming'));
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', ['client' => $client, 'serviceTypes' => ServiceType::active()->get()]);
    }

    public function update(ClientRequest $request, Client $client, AppointmentGenerator $generator): RedirectResponse
    {
        $snapshot = fn () => collect($client->only(array_keys($request->validated())))
            ->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (string) $v)->all();
        $before = $snapshot();
        $client->update($request->validated());
        $after = $snapshot();

        // Deactivating a client removes their future untouched visits.
        if ($client->wasChanged('is_active') && ! $client->is_active) {
            $client->schedules->each(fn ($s) => $generator->removeFutureUntouched($s));
        }
        if ($client->wasChanged('is_active') && $client->is_active) {
            $client->schedules->each(fn ($s) => $generator->generate($s));
        }

        Audit::log('client.updated', $client, before: array_diff_assoc($before, $after), after: array_diff_assoc($after, $before));

        return redirect()->route('admin.clients.show', $client)->with('status', __('clients.saved'));
    }
}
