@php
    $c = $plan['counts'];
    $saveCount = $c['visits'] + $c['schedules'];
    $tz = config('apq.display_timezone');
    $timeFmt = app()->getLocale() === 'es' ? 'H:i' : 'g:ia';
@endphp
<x-layouts.admin :title="__('schedule_import.preview_title')">
    <a href="{{ route('admin.schedule.import') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('schedule_import.title') }}</a>

    <section class="card mt-4 space-y-4">
        <div>
            <p class="text-sm text-navy-500">{{ __('schedule_import.from_file', ['name' => $state['name']]) }}@if ($defaultCleaner) · {{ __('schedule_import.default_cleaner', ['name' => $defaultCleaner]) }}@endif</p>
            <p class="mt-1 font-display text-lg font-semibold">{{ __('schedule_import.summary', $c) }}</p>
        </div>
        @if ($c['errors'])<p class="text-sm text-navy-500">{{ __('schedule_import.confirm_help') }}</p>@endif
        <div class="flex flex-wrap gap-3">
            @if ($saveCount > 0)
                <form method="POST" action="{{ route('admin.schedule.import.confirm') }}">
                    @csrf
                    <button type="submit" class="btn-primary sm:w-auto">✓ {{ __('schedule_import.confirm', ['count' => $saveCount]) }}</button>
                </form>
            @else
                <p class="font-semibold">{{ __('schedule_import.nothing') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.schedule.import.cancel') }}">
                @csrf
                <button type="submit" class="btn-secondary sm:w-auto">{{ __('schedule_import.cancel') }}</button>
            </form>
        </div>
    </section>

    <div class="mt-5 overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
        <table class="w-full min-w-[52rem] text-left text-[14px]">
            <thead class="bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                <tr>@foreach (['line', 'cleaner', 'client', 'when', 'repeat', 'result'] as $col)<th class="px-3 py-2.5">{{ __('schedule_import.cols.'.$col) }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-sand">
                @foreach ($plan['rows'] as $row)
                    <tr @class(['align-top', 'bg-alert-50/60' => $row['action'] === 'error', 'opacity-55' => $row['action'] === 'skip', 'bg-amber-50/50' => in_array($row['action'], ['visit', 'schedule'], true) && $row['warnings']])>
                        <td class="px-3 py-2 text-navy-500 tabular-nums">{{ $row['line'] }}</td>
                        <td class="px-3 py-2">{{ $row['cleaners'] ? collect($row['cleaners'])->pluck('name')->implode(', ') : '—' }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $row['client_name'] ?: '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            @if ($row['date'] && $row['start'] && $row['end'])
                                {{ ucfirst(\Illuminate\Support\Carbon::parse($row['date'], $tz)->translatedFormat(__('dates.day_short'))) }},
                                {{ \Illuminate\Support\Carbon::parse($row['start'])->format($timeFmt) }}–{{ \Illuminate\Support\Carbon::parse($row['end'])->format($timeFmt) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $row['frequency'] ? \App\Enums\Frequency::from($row['frequency'])->label() : __('schedule_import.once') }}</td>
                        <td class="px-3 py-2">
                            @switch($row['action'])
                                @case('visit')<span class="badge-sage">{{ __('schedule_import.will_visit') }}</span>@break
                                @case('schedule')<span class="badge-navy">{{ __('schedule_import.will_schedule') }}</span>@break
                                @case('skip')<span class="badge-sand">{{ __('schedule_import.will_skip') }}</span> <span class="text-navy-500">{{ $row['note'] }}</span>@break
                                @default<span class="badge-alert">{{ __('schedule_import.will_error') }}</span>
                            @endswitch
                            @foreach ($row['errors'] as $e)<p class="mt-1 text-[13px] text-alert">✕ {{ $e }}</p>@endforeach
                            @foreach ($row['warnings'] as $w)<p class="mt-1 text-[13px] text-amber">⚠ {{ $w }}</p>@endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.admin>
