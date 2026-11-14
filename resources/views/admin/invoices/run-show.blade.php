@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $total = $run->invoices->count();
@endphp
<x-layouts.admin :title="__('invoices.run_show', ['from' => $run->period_from->translatedFormat(__('dates.short')), 'to' => $run->period_to->translatedFormat(__('dates.short').' Y')])">
    @if ($pending)
        <meta http-equiv="refresh" content="5">
    @endif
    <a href="{{ route('admin.invoices.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('invoices.title') }}</a>

    <section class="card mt-4">
        <p class="font-display text-xl font-semibold">{{ __('invoices.run_progress', ['done' => $total - $pending, 'total' => $total]) }}</p>
        <div class="mt-3 h-3 overflow-hidden rounded-full bg-canvas">
            <div class="h-full rounded-full bg-sage transition-all" style="width: {{ $total ? round(($total - $pending) / $total * 100) : 100 }}%"></div>
        </div>
        @if ($pending)<p class="mt-2 text-sm text-navy-500">{{ __('invoices.run_auto_refresh') }}</p>@endif
    </section>

    @include('admin.invoices._table', ['invoices' => $run->invoices])
</x-layouts.admin>
