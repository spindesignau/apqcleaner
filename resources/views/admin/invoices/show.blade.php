@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $tz = config('apq.display_timezone');
@endphp
<x-layouts.admin :title="__('invoices.number').' '.($invoice->xero_invoice_number ?? $invoice->reference)">
    <a href="{{ route('admin.invoices.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('invoices.title') }}</a>
    @error('invoice')<p class="mt-4 max-w-3xl rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert" role="alert">{{ $message }}</p>@enderror

    <div class="mt-4 grid max-w-6xl gap-6 lg:grid-cols-[1fr_20rem]">
        <section class="card">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl"><a href="{{ route('admin.clients.show', $invoice->client) }}" class="hover:underline">{{ $invoice->client->billing_name }}</a></h2>
                    <p class="text-navy-500">{{ __('invoices.reference') }}: {{ $invoice->reference }}</p>
                    <p class="text-navy-500">{{ __('invoices.issued') }} {{ $invoice->issue_date->translatedFormat(__('dates.short').' Y') }} · {{ __('invoices.due') }} {{ $invoice->due_date->translatedFormat(__('dates.short').' Y') }}</p>
                </div>
                <span class="{{ $invoice->stateBadge() }}">{{ __('invoices.states.'.$invoice->stateKey()) }}</span>
            </div>
            @if ($invoice->last_error)
                <p class="mt-3 rounded-xl bg-alert-50 px-4 py-3 text-[15px] text-alert"><strong>{{ __('invoices.error') }}:</strong> {{ $invoice->last_error }}</p>
            @endif

            <table class="mt-5 w-full text-left text-[14px]">
                <thead class="text-xs font-bold tracking-wider text-navy-500 uppercase">
                    <tr><th class="py-2">{{ __('invoices.line_cols.description') }}</th><th class="py-2 text-right">{{ __('invoices.line_cols.qty') }}</th><th class="py-2 text-right">{{ __('invoices.line_cols.unit') }}</th><th class="py-2 text-right">{{ __('invoices.line_cols.amount') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @foreach ($invoice->lines as $line)
                        <tr>
                            <td class="py-2 pr-3">{{ $line->description }}</td>
                            <td class="py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $money($line->unit_amount) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $money($line->line_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-sand">
                    <tr><td colspan="3" class="pt-2 text-right">{{ __('invoices.cols.subtotal') }}</td><td class="pt-2 text-right tabular-nums">{{ $money($invoice->subtotal) }}</td></tr>
                    <tr><td colspan="3" class="text-right">GST 10%</td><td class="text-right tabular-nums">{{ $money($invoice->gst) }}</td></tr>
                    <tr class="font-semibold"><td colspan="3" class="text-right">{{ __('invoices.cols.total') }}</td><td class="text-right tabular-nums">{{ $money($invoice->xero_total ?? $invoice->total) }}</td></tr>
                </tfoot>
            </table>
        </section>

        <aside class="space-y-4">
            <section class="card space-y-3 text-[15px]">
                @if ($url = $invoice->xeroUrl())<a href="{{ $url }}" target="_blank" rel="noopener" class="btn-secondary btn-sm !w-full">{{ __('invoices.open_in_xero') }} ↗</a>@endif
                @if ($invoice->xero_online_url)<a href="{{ $invoice->xero_online_url }}" target="_blank" rel="noopener" class="block text-center font-semibold underline">{{ __('invoices.online_invoice') }} ↗</a>@endif
                @if ($invoice->emailed_at)<p>✉ {{ __('invoices.emailed', ['date' => $invoice->emailed_at->tz($tz)->translatedFormat(__('dates.datetime'))]) }}</p>@endif
                @if ($invoice->paid_at)<p>✓ {{ __('invoices.paid', ['date' => $invoice->paid_at->tz($tz)->translatedFormat(__('dates.short').' Y')]) }}</p>@endif
                @if ($invoice->sync_status === \App\Models\Invoice::SYNC_FAILED)
                    <form method="POST" action="{{ route('admin.invoices.retry', $invoice) }}">@csrf<button class="btn-primary btn-sm !w-full">↻ {{ __('invoices.retry') }}</button></form>
                @endif
            </section>
            @if ($invoice->sync_status !== \App\Models\Invoice::SYNC_VOIDED && $invoice->xero_status !== 'PAID')
                <form method="POST" action="{{ route('admin.invoices.void', $invoice) }}" class="card space-y-3" data-confirm="{{ __('invoices.void_confirm') }}" data-confirm-danger>
                    @csrf
                    <p class="text-sm text-navy-500">{{ __('invoices.void_help') }}</p>
                    <button class="btn-secondary btn-sm !w-full !border-alert !text-alert">{{ __('invoices.void') }}</button>
                </form>
            @endif
        </aside>
    </div>
</x-layouts.admin>
