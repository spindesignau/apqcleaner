@php $money = fn ($v) => '$'.number_format((float) $v, 2); @endphp
<div class="mt-5 overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
    <table class="w-full min-w-[52rem] text-left text-[14px]">
        <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
            <tr>
                <th class="px-3 py-2.5">{{ __('invoices.number') }}</th>
                <th class="px-3 py-2.5">{{ __('invoices.cols.client') }}</th>
                <th class="px-3 py-2.5">{{ __('invoices.issued') }}</th>
                <th class="px-3 py-2.5 text-right">{{ __('invoices.cols.total') }}</th>
                <th class="px-3 py-2.5 text-right">{{ __('invoices.outstanding') }}</th>
                <th class="px-3 py-2.5"></th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-sand">
            @forelse ($invoices as $invoice)
                <tr class="align-top">
                    <td class="px-3 py-2.5">
                        <a href="{{ route('admin.invoices.show', $invoice) }}" class="font-semibold hover:underline">{{ $invoice->xero_invoice_number ?? $invoice->reference }}</a>
                        @if ($invoice->xero_invoice_number)<p class="text-xs text-navy-500">{{ $invoice->reference }}</p>@endif
                    </td>
                    <td class="px-3 py-2.5">{{ $invoice->client->display_name }}</td>
                    <td class="px-3 py-2.5 whitespace-nowrap">{{ $invoice->issue_date->translatedFormat(__('dates.short')) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($invoice->xero_total ?? $invoice->total) }}</td>
                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $invoice->xero_status === 'AUTHORISED' ? $money($invoice->xero_amount_due) : '—' }}</td>
                    <td class="px-3 py-2.5">
                        <span class="{{ $invoice->stateBadge() }}">{{ __('invoices.states.'.$invoice->stateKey()) }}</span>
                        @if ($invoice->last_error)<p class="mt-1 max-w-72 text-[13px] text-alert">{{ $invoice->last_error }}</p>@endif
                    </td>
                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                        @if ($invoice->sync_status === \App\Models\Invoice::SYNC_FAILED)
                            <form method="POST" action="{{ route('admin.invoices.retry', $invoice) }}" class="inline">@csrf<button class="font-semibold underline">{{ __('invoices.retry') }}</button></form>
                        @elseif ($url = $invoice->xeroUrl())
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="font-semibold underline">Xero ↗</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-5 py-10 text-center text-navy-500">{{ __('invoices.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
