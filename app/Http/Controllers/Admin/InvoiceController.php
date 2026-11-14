<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Invoicing\BuildInvoices;
use App\Actions\Invoicing\VoidInvoice;
use App\Http\Controllers\Controller;
use App\Jobs\PushInvoiceToXero;
use App\Models\Invoice;
use App\Models\InvoiceRun;
use App\Models\XeroConnection;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\Xero\XeroException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    private const FREQUENCIES = ['per_visit', 'weekly', 'fortnightly', 'monthly'];

    public function index(Request $request): View
    {
        $state = $request->query('state');
        $invoices = Invoice::query()->with('client')
            ->when($state === 'failed', fn ($q) => $q->where('sync_status', Invoice::SYNC_FAILED))
            ->when($state === 'unpaid', fn ($q) => $q->where('xero_status', 'AUTHORISED'))
            ->when($state === 'draft', fn ($q) => $q->where('xero_status', 'DRAFT'))
            ->when($state === 'paid', fn ($q) => $q->where('xero_status', 'PAID'))
            ->when($request->integer('client'), fn ($q, $id) => $q->where('client_id', $id))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'state' => $state,
            'connected' => (bool) XeroConnection::current(),
            'outstanding' => Invoice::where('xero_status', 'AUTHORISED')->sum('xero_amount_due'),
            'failedCount' => Invoice::where('sync_status', Invoice::SYNC_FAILED)->count(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['client', 'lines.appointment', 'lines.timeEntries.user', 'run']);

        return view('admin.invoices.show', compact('invoice'));
    }

    public function create(Request $request, BuildInvoices $builder, Settings $settings): View
    {
        $data = $this->runInput($request, $settings);

        return view('admin.invoices.run', $data + [
            'preview' => $builder->preview($data['from'], $data['to'], $data['frequency']),
            'connected' => (bool) XeroConnection::current(),
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    public function store(Request $request, BuildInvoices $builder, Settings $settings): RedirectResponse
    {
        abort_unless(XeroConnection::current(), 422, __('xero.not_connected'));
        $data = $this->runInput($request, $settings);
        $request->validate(['clients' => ['required', 'array', 'min:1'], 'clients.*' => ['integer']]);

        $run = InvoiceRun::create([
            'period_from' => $data['from']->toDateString(), 'period_to' => $data['to']->toDateString(),
            'mode' => $data['mode'], 'send_email' => $data['email'], 'created_by' => $request->user()->id,
        ]);
        $invoices = $builder->build($data['from'], $data['to'], $data['mode'], $data['email'], $run->id,
            array_map('intval', $request->input('clients')), $data['frequency'], $request->user()->id);
        $run->update(['invoices_count' => $invoices->count()]);
        $invoices->each(fn ($invoice) => PushInvoiceToXero::dispatch($invoice));
        Audit::log('invoice_run.created', $run, after: ['invoices' => $invoices->count(), 'mode' => $data['mode']]);

        return redirect()->route('admin.invoices.runs.show', $run)
            ->with('status', trans_choice('invoices.run_started', $invoices->count(), ['count' => $invoices->count()]));
    }

    public function run(InvoiceRun $run): View
    {
        $run->load(['invoices.client', 'creator']);

        return view('admin.invoices.run-show', [
            'run' => $run,
            'pending' => $run->invoices->whereIn('sync_status', [Invoice::SYNC_PENDING, Invoice::SYNC_PUSHING])->count(),
        ]);
    }

    public function retry(Invoice $invoice): RedirectResponse
    {
        abort_unless(in_array($invoice->sync_status, [Invoice::SYNC_FAILED, Invoice::SYNC_PENDING], true), 422);
        $invoice->update(['sync_status' => Invoice::SYNC_PENDING, 'last_error' => null]);
        PushInvoiceToXero::dispatch($invoice);

        return back()->with('status', __('invoices.retrying', ['ref' => $invoice->reference]));
    }

    public function retryFailed(): RedirectResponse
    {
        $failed = Invoice::where('sync_status', Invoice::SYNC_FAILED)->get();
        $failed->each(function ($invoice) {
            $invoice->update(['sync_status' => Invoice::SYNC_PENDING, 'last_error' => null]);
            PushInvoiceToXero::dispatch($invoice);
        });

        return back()->with('status', trans_choice('invoices.retrying_many', $failed->count(), ['count' => $failed->count()]));
    }

    public function void(Invoice $invoice, VoidInvoice $void): RedirectResponse
    {
        try {
            $void($invoice);
        } catch (XeroException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', __('invoices.voided'));
    }

    /** @return array{from: Carbon, to: Carbon, mode: string, email: bool, frequency: ?string} */
    private function runInput(Request $request, Settings $settings): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'mode' => ['nullable', Rule::in(['draft', 'authorised'])], 'frequency' => ['nullable', Rule::in(self::FREQUENCIES)],
        ]);
        $tz = config('apq.display_timezone');
        $lastMonth = Carbon::now($tz)->subMonthNoOverflow();
        $mode = $request->input('mode', $settings->get('xero.default_mode', 'draft'));

        return [
            'from' => $request->filled('from') ? Carbon::parse($request->input('from'), $tz)->startOfDay() : $lastMonth->copy()->startOfMonth(),
            'to' => $request->filled('to') ? Carbon::parse($request->input('to'), $tz)->endOfDay() : $lastMonth->copy()->endOfMonth(),
            'mode' => $mode,
            'email' => $mode === 'authorised' && ($request->has('mode') ? $request->boolean('email') : (bool) $settings->get('xero.email_by_default')),
            'frequency' => $request->input('frequency') ?: null,
        ];
    }
}
