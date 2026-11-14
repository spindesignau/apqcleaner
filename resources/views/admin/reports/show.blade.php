@php
    $fmt = function ($value, string $format) {
        if ($value === null || $value === '') return '—';
        return match ($format) {
            'money' => '$'.number_format((float) $value, 2),
            'hours' => \App\Models\TimeEntry::formatMinutes((int) $value),
            'percent' => number_format((float) $value, 1).'%',
            'signed_minutes' => ($value > 0 ? '+' : '').$value.' min',
            'int' => number_format((int) $value),
            default => $value,
        };
    };
    $query = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'client' => $clientId, 'staff' => $staffId, 'detail' => $detail ? 1 : null]);
@endphp
<x-layouts.admin :title="__('reports.names.'.$report)">
    <x-slot:actions>
        <a href="{{ route('admin.reports.show', [$report] + $query + ['format' => 'csv']) }}" class="btn-secondary btn-sm print:hidden">⇩ CSV</a>
        <button type="button" onclick="window.print()" class="btn-secondary btn-sm print:hidden">⎙ {{ __('reports.print') }}</button>
    </x-slot:actions>

    <a href="{{ route('admin.reports.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy print:hidden">← {{ __('reports.title') }}</a>

    <form method="GET" class="card mt-4 !p-4 print:hidden">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            <div><label class="label" for="from">{{ __('reports.from') }}</label><input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="input !min-h-11"></div>
            <div><label class="label" for="to">{{ __('reports.to') }}</label><input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="input !min-h-11"></div>
            @if ($clients->isNotEmpty())
                <select name="client" class="input !min-h-11" aria-label="{{ __('reports.cols.client') }}">
                    <option value="">{{ __('reports.all_clients') }}</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}" @selected($clientId === $c->id)>{{ $c->display_name }}</option>@endforeach
                </select>
            @endif
            @if ($staffOptions->isNotEmpty())
                <select name="staff" class="input !min-h-11" aria-label="{{ __('reports.cols.staff') }}">
                    <option value="">{{ __('reports.all_staff') }}</option>
                    @foreach ($staffOptions as $s)<option value="{{ $s->id }}" @selected($staffId === $s->id)>{{ $s->name }}</option>@endforeach
                </select>
                <label class="flex min-h-11 items-center gap-2 text-[15px]"><input type="checkbox" name="detail" value="1" @checked($detail) class="size-5 rounded border-sand-600 text-navy focus:ring-sage"> {{ __('reports.shift_detail') }}</label>
            @endif
            <button class="btn-primary btn-sm !w-full">{{ __('reports.run') }}</button>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            @foreach ($presets as $key => [$pFrom, $pTo])
                @php $active = $from->isSameDay($pFrom) && $to->isSameDay($pTo); @endphp
                <a href="{{ route('admin.reports.show', [$report] + array_filter(['client' => $clientId, 'staff' => $staffId, 'detail' => $detail ? 1 : null]) + ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()]) }}"
                   @class(['badge min-h-8', 'bg-navy text-offwhite' => $active, 'badge-sand' => ! $active])>{{ __('reports.presets.'.$key) }}</a>
            @endforeach
        </div>
    </form>

    <p class="mt-4 font-display text-lg font-semibold">{{ $from->translatedFormat('j M Y') }} – {{ $to->translatedFormat('j M Y') }}</p>

    <div class="mt-3 overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)] print:shadow-none">
        <table class="w-full text-left text-[14px]">
            <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                <tr>
                    @foreach ($data['columns'] as $key => [$label, $format])
                        <th @class(['px-3 py-2.5', 'text-right' => $format !== 'text'])>{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-sand">
                @forelse ($data['rows'] as $row)
                    <tr class="odd:bg-surface even:bg-canvas-50/60">
                        @foreach ($data['columns'] as $key => [$label, $format])
                            <td @class(['px-3 py-2', 'text-right tabular-nums' => $format !== 'text',
                                        'text-alert font-semibold' => ($format === 'percent' && $row[$key] !== null && $row[$key] < 20) || ($format === 'signed_minutes' && $row[$key] >= 30)])>{{ $fmt($row[$key] ?? null, $format) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($data['columns']) }}" class="px-5 py-10 text-center text-navy-500">{{ __('reports.empty') }}</td></tr>
                @endforelse
            </tbody>
            @if ($data['rows'] && $data['totals'])
                <tfoot class="border-t-2 border-sand bg-canvas-50 font-semibold">
                    <tr>
                        @foreach ($data['columns'] as $key => [$label, $format])
                            <td @class(['px-3 py-2.5 whitespace-nowrap', 'text-right tabular-nums' => $format !== 'text'])>{{ array_key_exists($key, $data['totals']) ? $fmt($data['totals'][$key], $format) : '' }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @foreach ($data['notes'] as $note)<p class="mt-3 max-w-3xl text-sm text-navy-500">{{ $note }}</p>@endforeach
</x-layouts.admin>
