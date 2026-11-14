@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $count = $preview->count();
@endphp
<x-layouts.admin :title="__('invoices.run_title')">
    <a href="{{ route('admin.invoices.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('invoices.title') }}</a>
    @unless ($connected)
        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber">⚠ {{ __('invoices.connect_first') }} <a class="underline" href="{{ route('admin.xero.show') }}">{{ __('xero.title') }} ›</a></p>
    @endunless

    <form method="GET" class="card mt-4 !p-4" x-data="{ mode: @js($mode) }">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            <div><label class="label" for="from">{{ __('invoices.from') }}</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="input !min-h-11"></div>
            <div><label class="label" for="to">{{ __('invoices.to') }}</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="input !min-h-11"></div>
            <div>
                <label class="label" for="frequency">{{ __('invoices.frequency') }}</label>
                <select id="frequency" name="frequency" class="input !min-h-11">
                    <option value="">{{ __('invoices.all_frequencies') }}</option>
                    @foreach ($frequencies as $f)<option value="{{ $f }}" @selected($frequency === $f)>{{ __('invoices.frequencies.'.$f) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="mode">{{ __('invoices.mode') }}</label>
                <select id="mode" name="mode" x-model="mode" class="input !min-h-11">
                    @foreach (['draft', 'authorised'] as $m)<option value="{{ $m }}">{{ __('xero.modes.'.$m) }}</option>@endforeach
                </select>
            </div>
            <button class="btn-secondary btn-sm !w-full">{{ __('invoices.refresh') }}</button>
        </div>
        <label class="mt-3 flex items-center gap-3 text-[15px]" x-show="mode === 'authorised'" x-cloak x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end" x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end">
            <input type="checkbox" name="email" value="1" @checked($email) class="size-5 rounded border-sand-600 text-navy focus:ring-sage">
            {{ __('invoices.email') }} <span class="text-sm text-navy-500">— {{ __('invoices.email_help') }}</span>
        </label>
    </form>

    <p class="mt-4 max-w-3xl text-[15px] text-navy-500">{{ __('invoices.preview_help') }}</p>

    @if ($count === 0)
        <div class="card mt-4 py-10 text-center text-navy-500">{{ __('invoices.nothing') }}</div>
    @else
        <form method="POST" action="{{ route('admin.invoices.run.store') }}" class="mt-4" x-data="{ selected: @js($preview->pluck('client.id')->map(fn ($i) => (string) $i)->values()) }">
            @csrf
            @foreach (['from' => $from->toDateString(), 'to' => $to->toDateString(), 'mode' => $mode, 'frequency' => $frequency] as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            @if ($email)<input type="hidden" name="email" value="1">@endif

            <div class="overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
                <table class="w-full min-w-[48rem] text-left text-[14px]">
                    <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                        <tr>
                            <th class="w-10 px-3 py-2.5"></th>
                            <th class="px-3 py-2.5">{{ __('invoices.cols.client') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('invoices.cols.visits') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('invoices.cols.subtotal') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('invoices.cols.gst') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('invoices.cols.total') }}</th>
                            <th class="px-3 py-2.5">{{ __('invoices.cols.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @foreach ($preview as $row)
                            <tr class="align-top">
                                <td class="px-3 py-2.5"><input type="checkbox" name="clients[]" value="{{ $row['client']->id }}" x-model="selected" class="rounded border-sand-600 text-navy focus:ring-sage" aria-label="{{ $row['client']->display_name }}"></td>
                                <td class="px-3 py-2.5">
                                    <a href="{{ route('admin.clients.show', $row['client']) }}" class="font-semibold hover:underline">{{ $row['client']->display_name }}</a>
                                    <details class="mt-1 text-[13px] text-navy-500">
                                        <summary class="cursor-pointer">{{ __('invoices.lines') }}</summary>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($row['visits'] as $v)<li>{{ $v['description'] }} — {{ $v['unit'] === null ? '—' : $money($v['amount']) }}</li>@endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $row['visits']->count() }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['subtotal']) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($row['gst']) }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $money($row['total']) }}</td>
                                <td class="px-3 py-2.5 text-[13px] text-amber">@foreach ($row['warnings'] as $w)<p>⚠ {{ $w }}</p>@endforeach</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-sand bg-canvas-50 font-semibold">
                        <tr>
                            <td></td><td class="px-3 py-2.5">{{ __('invoices.totals') }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $preview->sum(fn ($r) => $r['visits']->count()) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $money($preview->sum('subtotal')) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $money($preview->sum('gst')) }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $money($preview->sum('total')) }}</td><td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <button type="submit" class="btn-primary mt-4 sm:w-auto" :disabled="selected.length === 0" @disabled(! $connected)>
                ✓ <span x-text="@js(__('invoices.create', ['count' => '__N__'])).replace('__N__', selected.length)">{{ __('invoices.create', ['count' => $count]) }}</span>
            </button>
        </form>
    @endif
</x-layouts.admin>
