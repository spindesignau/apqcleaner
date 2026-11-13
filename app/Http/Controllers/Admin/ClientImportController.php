<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\ClientImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Clients → Import from spreadsheet: upload the CSV, preview, confirm.
 * The uploaded file is kept on the private disk only until the import is confirmed or cancelled.
 */
class ClientImportController extends Controller
{
    private const SESSION = 'client_import';

    private const MODES = [ClientImporter::CANCELLED_ACTIVE, ClientImporter::CANCELLED_INACTIVE, ClientImporter::CANCELLED_SKIP];

    public function create(): View
    {
        return view('admin.clients.import', ['modes' => self::MODES]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'cancelled' => ['required', Rule::in(self::MODES)],
        ], attributes: ['file' => __('import.file')]);

        $this->discard($request);
        $path = $request->file('file')->storeAs('imports', Str::uuid().'.csv', 'local');
        $request->session()->put(self::SESSION, ['path' => $path, 'mode' => $data['cancelled'], 'name' => $request->file('file')->getClientOriginalName()]);

        return redirect()->route('admin.clients.import.preview');
    }

    public function preview(Request $request, ClientImporter $importer): View|RedirectResponse
    {
        if (! $state = $this->state($request)) {
            return $this->expired();
        }
        if ($request->filled('cancelled') && in_array($request->query('cancelled'), self::MODES, true)) {
            $state['mode'] = $request->query('cancelled');
            $request->session()->put(self::SESSION, $state);
        }

        $plan = $importer->plan(Storage::disk('local')->path($state['path']), $state['mode']);
        if ($plan === null) {
            $this->discard($request);

            return redirect()->route('admin.clients.import')->withErrors(['file' => __('import.bad_file')]);
        }

        return view('admin.clients.import-preview', ['plan' => $plan, 'state' => $state, 'modes' => self::MODES]);
    }

    public function confirm(Request $request, ClientImporter $importer): RedirectResponse
    {
        if (! $state = $this->state($request)) {
            return $this->expired();
        }
        $plan = $importer->plan(Storage::disk('local')->path($state['path']), $state['mode']);
        abort_if($plan === null, 422);

        $created = $importer->commit($plan, $state['name']);
        $this->discard($request);

        return redirect()->route('admin.clients.index', ['status' => 'all'])
            ->with('status', trans_choice('import.done', $created, ['count' => $created]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->discard($request);

        return redirect()->route('admin.clients.index');
    }

    private function state(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION);

        return $state && Storage::disk('local')->exists($state['path']) ? $state : null;
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('admin.clients.import')->withErrors(['file' => __('import.expired')]);
    }

    private function discard(Request $request): void
    {
        if ($state = $request->session()->pull(self::SESSION)) {
            Storage::disk('local')->delete($state['path']);
        }
    }
}
