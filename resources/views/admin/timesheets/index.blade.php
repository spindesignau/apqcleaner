@php
    use App\Enums\TimeEntryStatus;
    $fmt = app()->getLocale() === 'es' ? 'H:i' : 'g:ia';
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $query = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'staff' => $staffId, 'client' => $clientId, 'status' => $status]);
@endphp
<x-layouts.admin :title="__('timesheets.title')">
    {{-- Filters --}}
    <form method="GET" class="card !p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
            <div>
                <label class="label" for="from">{{ __('timesheets.from') }}</label>
                <input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="input !min-h-11">
            </div>
            <div>
                <label class="label" for="to">{{ __('timesheets.to') }}</label>
                <input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="input !min-h-11">
            </div>
            <select name="staff" class="input !min-h-11" aria-label="{{ __('timesheets.cols.cleaner') }}">
                <option value="">{{ __('timesheets.all_staff') }}</option>
                @foreach ($staffOptions as $o)<option value="{{ $o->id }}" @selected($staffId === $o->id)>{{ $o->name }}</option>@endforeach
            </select>
            <select name="client" class="input !min-h-11" aria-label="{{ __('timesheets.cols.client') }}">
                <option value="">{{ __('timesheets.all_clients') }}</option>
                @foreach ($clientOptions as $o)<option value="{{ $o->id }}" @selected($clientId === $o->id)>{{ $o->display_name }}</option>@endforeach
            </select>
            <select name="status" class="input !min-h-11" aria-label="{{ __('timesheets.cols.status') }}">
                <option value="">{{ __('timesheets.all_statuses') }}</option>
                @foreach (TimeEntryStatus::cases() as $s)<option value="{{ $s->value }}" @selected($status === $s->value)>{{ $s->label() }}</option>@endforeach
            </select>
            <button class="btn-primary btn-sm !w-full">{{ __('timesheets.filter') }}</button>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            @foreach (['current', 'previous'] as $key)
                @php $p = $periodPresets[$key]; @endphp
                <a href="{{ route('admin.timesheets.index', ['from' => $p->start->toDateString(), 'to' => $p->end->toDateString()]) }}"
                   @class(['badge min-h-8', 'bg-navy text-offwhite' => $from->isSameDay($p->start) && $to->isSameDay($p->end), 'badge-sand' => ! ($from->isSameDay($p->start) && $to->isSameDay($p->end))])>
                    {{ __('timesheets.'.$key.'_period') }} · {{ $p->start->translatedFormat(__('dates.short')) }} – {{ $p->end->translatedFormat(__('dates.short')) }}
                </a>
            @endforeach
        </div>
    </form>

    {{-- Missing timesheets --}}
    @if ($missing->isNotEmpty())
        <section class="mt-5 rounded-[var(--radius-card)] border border-amber/30 bg-amber-50 p-4">
            <h2 class="text-lg text-amber">⚠ {{ __('timesheets.missing_title') }} ({{ $missing->count() }})</h2>
            <p class="text-sm text-amber">{{ __('timesheets.missing_help') }}</p>
            <ul class="mt-3 divide-y divide-amber/20">
                @foreach ($missing as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-[15px]">
                        <span>
                            <strong>{{ $row['user']->name }}</strong> · {{ $row['appointment']->client->display_name }} ·
                            {{ ucfirst($row['appointment']->localStart()->translatedFormat(__('dates.day_short'))) }} {{ $row['appointment']->timeRange() }}
                            @if ($row['entry'])<span class="{{ $row['entry']->status->badgeClass() }} ml-1 !py-0">{{ $row['entry']->status->label() }}</span>
                            @else<span class="badge-sand ml-1 !py-0">{{ __('timesheets.not_started') }}</span>@endif
                        </span>
                        @if ($row['entry'])
                            <a href="{{ route('admin.timesheets.edit', $row['entry']) }}" class="btn-secondary btn-sm">{{ __('common.edit') }}</a>
                        @else
                            <a href="{{ route('admin.timesheets.create', ['appointment' => $row['appointment']->id, 'user' => $row['user']->id]) }}" class="btn-secondary btn-sm">+ {{ __('timesheets.add_time') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Entries --}}
    <form method="POST" action="{{ route('admin.timesheets.approve') }}" class="mt-5" x-data="{ selected: [] }">
        @csrf
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <p class="text-[15px] font-semibold">{{ trans_choice('timesheets.awaiting', $totals['awaiting'], ['count' => $totals['awaiting']]) }}</p>
            <button type="submit" class="btn-primary btn-sm ml-auto" :disabled="selected.length === 0">
                ✓ {{ __('timesheets.approve_selected') }} <span x-show="selected.length" x-text="'(' + selected.length + ')'"></span>
            </button>
        </div>

        <div class="overflow-x-auto rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
            <table class="w-full min-w-[60rem] text-left text-[14px]">
                <thead class="sticky top-0 bg-canvas-50 text-xs font-bold tracking-wider text-navy-500 uppercase">
                    <tr>
                        <th class="w-10 px-3 py-2.5">
                            <input type="checkbox" aria-label="{{ __('timesheets.select_all') }}" class="rounded border-sand-600 text-navy focus:ring-sage"
                                   @change="selected = $event.target.checked ? @js($entries->where('status', TimeEntryStatus::Submitted)->pluck('id')->map(fn ($i) => (string) $i)->values()) : []">
                        </th>
                        @foreach (['date', 'cleaner', 'client', 'scheduled', 'logged', 'billable', 'variance', 'cost', 'status'] as $col)
                            <th class="px-3 py-2.5 {{ in_array($col, ['scheduled', 'logged', 'billable', 'variance', 'cost']) ? 'text-right' : '' }}">{{ __('timesheets.cols.'.$col) }}</th>
                        @endforeach
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @forelse ($entries as $e)
                        @php
                            $scheduled = $e->appointment?->durationMinutes();
                            $variance = $e->duration_minutes !== null && $scheduled ? $e->duration_minutes - $scheduled : null;
                        @endphp
                        <tr class="odd:bg-surface even:bg-canvas-50/60 hover:bg-sage-50/50">
                            <td class="px-3 py-2.5">
                                @if ($e->status === TimeEntryStatus::Submitted)
                                    <input type="checkbox" name="ids[]" value="{{ $e->id }}" x-model="selected" class="rounded border-sand-600 text-navy focus:ring-sage" aria-label="{{ $e->user->name }} {{ $e->client->display_name }}">
                                @endif
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <span class="font-semibold">{{ ucfirst($e->localStart()->translatedFormat(__('dates.day_short'))) }}</span><br>
                                <span class="text-navy-500">{{ $e->localStart()->format($fmt) }}–{{ $e->localEnd()?->format($fmt) ?? '…' }}</span>
                            </td>
                            <td class="px-3 py-2.5">{{ $e->user->name }}</td>
                            <td class="px-3 py-2.5">
                                {{ $e->client->display_name }}
                                @if ($e->staff_notes)<p class="max-w-56 truncate text-xs text-navy-500" title="{{ $e->staff_notes }}">“{{ $e->staff_notes }}”</p>@endif
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-navy-500">{{ $scheduled ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">
                                @if ($e->status === TimeEntryStatus::Running)<x-job-timer :entry="$e" size="sm" />@else{{ $e->duration_minutes }}@endif
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $e->billable_minutes ?? '—' }}</td>
                            <td @class(['px-3 py-2.5 text-right tabular-nums', 'text-amber font-semibold' => $variance !== null && abs($variance) >= 30])>
                                {{ $variance === null ? '—' : ($variance > 0 ? '+' : '').$variance }}
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ ($c = $e->labourCost()) !== null ? $money($c) : '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span class="{{ $e->status->badgeClass() }}">{{ $e->status->label() }}</span>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if ($e->auto_stopped)<span class="badge-amber !px-2 !py-0 !text-[11px]">{{ __('timesheets.flags.auto') }}</span>@endif
                                    @if ($e->staff_adjusted)<span class="badge-amber !px-2 !py-0 !text-[11px]">{{ __('timesheets.flags.staff_adjusted') }}</span>@endif
                                    @if ($e->admin_adjusted)<span class="badge-sand !px-2 !py-0 !text-[11px]">{{ __('timesheets.flags.admin_adjusted') }}</span>@endif
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-right"><a href="{{ route('admin.timesheets.edit', array_merge(['entry' => $e->id], $query)) }}" class="font-semibold underline">{{ __('common.edit') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-5 py-10 text-center text-navy-500">{{ __('timesheets.empty') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($entries->isNotEmpty())
                    <tfoot class="border-t-2 border-sand bg-canvas-50 font-semibold">
                        <tr>
                            <td colspan="5" class="px-3 py-2.5">{{ __('timesheets.totals') }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">{{ \App\Models\TimeEntry::formatMinutes($totals['logged']) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">{{ \App\Models\TimeEntry::formatMinutes($totals['billable']) }}</td>
                            <td></td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $money($totals['labour']) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </form>
</x-layouts.admin>
