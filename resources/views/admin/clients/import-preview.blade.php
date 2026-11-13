@php $c = $plan['counts']; @endphp
<x-layouts.admin :title="__('import.preview_title')">
    <a href="{{ route('admin.clients.import') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('import.title') }}</a>

    <section class="card mt-4 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm text-navy-500">{{ __('import.from_file', ['name' => $state['name']]) }}</p>
                <p class="mt-1 font-display text-lg font-semibold">
                    {{ __('import.summary', ['create' => $c['create'], 'duplicate' => $c['duplicate'], 'cancelled' => $c['cancelled'], 'warnings' => $c['warnings']]) }}
                </p>
            </div>
            <form method="GET" x-data>
                <label class="label" for="cancelled">{{ __('import.cancelled_label') }}</label>
                <select id="cancelled" name="cancelled" class="input !min-h-11 !py-0" @change="$el.form.submit()">
                    @foreach ($modes as $mode)
                        <option value="{{ $mode }}" @selected($state['mode'] === $mode)>{{ __('import.modes.'.$mode) }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <p class="rounded-xl bg-sage-50 px-4 py-3 text-[15px] text-sage-700">{{ __('import.after') }}</p>
        <div class="flex flex-wrap gap-3">
            @if ($c['create'] > 0)
                <form method="POST" action="{{ route('admin.clients.import.confirm') }}">
                    @csrf
                    <button type="submit" class="btn-primary sm:w-auto">✓ {{ __('import.confirm', ['count' => $c['create']]) }}</button>
                </form>
            @else
                <p class="font-semibold">{{ __('import.nothing') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.clients.import.cancel') }}">
                @csrf
                <button type="submit" class="btn-secondary sm:w-auto">{{ __('import.cancel') }}</button>
            </form>
        </div>
    </section>

    <div class="mt-5 overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
        <table class="w-full min-w-[56rem] text-left text-[14px]">
            <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                <tr>
                    @foreach (['line', 'client', 'address', 'phone', 'payment', 'frequency', 'result'] as $col)
                        <th class="px-3 py-2.5">{{ __('import.cols.'.$col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-sand">
                @foreach ($plan['rows'] as $row)
                    @php $d = $row['data']; @endphp
                    <tr @class(['opacity-55' => $row['action'] === 'skip', 'bg-amber-50/50' => $row['action'] === 'create' && $row['warnings']])>
                        <td class="px-3 py-2 text-navy-500 tabular-nums">{{ $row['line'] }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $d['display_name'] }}</td>
                        <td class="px-3 py-2">{{ $d['address_line1'] }}@if ($d['suburb']), {{ $d['suburb'] }}@endif</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ \App\Support\AuPhone::display($d['mobile']) ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $d['payment_method']->label() }}</td>
                        <td class="px-3 py-2">{{ $d['usual_frequency'] ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if ($row['action'] === 'skip')
                                <span class="badge-sand">{{ __('import.will_skip') }}</span> <span class="text-navy-500">{{ $row['note'] }}</span>
                            @else
                                <span class="badge-sage">{{ $d['is_active'] ? __('import.will_add') : __('import.will_add_inactive') }}</span>
                                @foreach ($row['warnings'] as $w)<p class="mt-1 text-[13px] text-amber">⚠ {{ $w }}</p>@endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.admin>
