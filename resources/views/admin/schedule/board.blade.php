@php $today = now(config('apq.display_timezone'))->toDateString(); @endphp
<x-layouts.admin :title="__('schedule.board_title')">
    <x-slot:actions>
        <a href="{{ route('admin.schedule.template') }}" data-no-progress class="hidden text-sm font-semibold underline md:inline">⇩ {{ __('schedule_import.download') }}</a>
        <a href="{{ route('admin.schedule.import', array_filter(['cleaner' => $staffId])) }}" class="btn-secondary btn-sm hidden sm:inline-flex">⇪ {{ __('schedule_import.button') }}</a>
        <a href="{{ route('admin.appointments.create') }}" class="btn-primary btn-sm hidden sm:inline-flex">+ {{ __('schedule.add_visit') }}</a>
    </x-slot:actions>

    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.schedule', array_filter(['week' => $week->previous(), 'staff' => $staffId])) }}" class="btn-secondary btn-sm !w-11 !px-0" aria-label="{{ __('staff.previous_week') }}">◀</a>
            <a href="{{ route('admin.schedule', array_filter(['week' => $week->next(), 'staff' => $staffId])) }}" class="btn-secondary btn-sm !w-11 !px-0" aria-label="{{ __('staff.next_week') }}">▶</a>
        </div>
        <h2 class="font-display text-lg font-semibold">
            {{ $week->start->translatedFormat(__('dates.short')) }} – {{ $week->end->translatedFormat(__('dates.short').' Y') }}
        </h2>
        @unless ($week->isCurrent())
            <a href="{{ route('admin.schedule', array_filter(['staff' => $staffId])) }}" class="badge-sage">{{ __('schedule.this_week') }}</a>
        @endunless
        <form method="GET" class="ml-auto" x-data>
            <input type="hidden" name="week" value="{{ $week->param() }}">
            <select name="staff" class="input !min-h-11 !py-0" @change="$el.form.submit()" aria-label="{{ __('schedule.fields.staff') }}">
                <option value="">{{ __('schedule.all_staff') }}</option>
                @foreach ($staffOptions as $person)
                    <option value="{{ $person->id }}" @selected($staffId === $person->id)>{{ $person->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if ($unassigned)
        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-2.5 text-[15px] font-medium text-amber">⚠ {{ trans_choice('schedule.unassigned_count', $unassigned, ['count' => $unassigned]) }}</p>
    @endif

    <div class="mt-5 grid gap-3 md:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7" data-stagger>
        @foreach ($week->days() as $day)
            @php
                $key = $day->toDateString();
                $visits = $byDay->get($key, collect());
                $isToday = $key === $today;
            @endphp
            <section style="--i: {{ $loop->index }}" @class(['flex flex-col rounded-[var(--radius-card)] border bg-surface 2xl:min-h-72', 'border-sage ring-2 ring-sage/40' => $isToday, 'border-sand' => ! $isToday])>
                <header class="flex items-center justify-between border-b border-sand px-3 py-2">
                    <h3 class="text-[15px]">
                        {{ ucfirst($day->translatedFormat('D j')) }}
                        @if ($isToday)<span class="badge-sage ml-1 !py-0 !text-[11px]">{{ __('schedule.today') }}</span>@endif
                    </h3>
                    <a href="{{ route('admin.appointments.create', ['date' => $key]) }}" class="rounded-lg px-2 py-0.5 text-lg font-semibold text-navy-500 hover:bg-canvas hover:text-navy" aria-label="{{ __('schedule.add_visit') }}">+</a>
                </header>
                <ul class="flex-1 space-y-2 p-2">
                    @forelse ($visits as $visit)
                        <li>
                            <a href="{{ route('admin.appointments.edit', $visit) }}" @class([
                                'block rounded-xl border px-2.5 py-2 text-[14px] leading-snug hover:border-navy',
                                'border-alert bg-alert-50' => isset($conflictIds[$visit->id]),
                                'border-sand bg-canvas-50' => ! isset($conflictIds[$visit->id]) && ! $visit->status->isCancelled(),
                                'border-sand opacity-60' => $visit->status->isCancelled(),
                            ])>
                                <p class="flex items-center justify-between gap-1 font-semibold">
                                    <span>{{ $visit->timeRange() }}</span>
                                    @if (isset($conflictIds[$visit->id]))<span class="text-[11px] font-bold uppercase text-alert">{{ __('schedule.overlap') }}</span>@endif
                                </p>
                                <p class="truncate font-semibold">{{ $visit->client->display_name }}</p>
                                <p class="truncate text-navy-500">{{ $visit->client->suburb }}</p>
                                <p class="mt-1 truncate text-[13px]"><x-staff-names :staff="$visit->staff" /></p>
                                @if ($visit->status === \App\Enums\AppointmentStatus::Scheduled && $visit->scheduled_start->between(now(), now()->addHours(48)))
                                    <x-countdown-chip :appointment="$visit" class="mt-1 !py-0 !text-[11px]" />
                                @endif
                                @if ($visit->status !== \App\Enums\AppointmentStatus::Scheduled)
                                    <span class="{{ $visit->status->badgeClass() }} mt-1 !py-0 !text-[11px]">{{ $visit->status->label() }}</span>
                                @elseif (! $visit->recurring_schedule_id)
                                    <span class="badge-sand mt-1 !py-0 !text-[11px]">{{ __('schedule.one_off') }}</span>
                                @elseif ($visit->is_detached)
                                    <span class="badge-sand mt-1 !py-0 !text-[11px]">{{ __('schedule.edited') }}</span>
                                @endif
                            </a>
                        </li>
                    @empty
                        <li class="px-1 py-1 text-sm text-navy-500">{{ __('schedule.no_visits') }}</li>
                    @endforelse
                </ul>
            </section>
        @endforeach
    </div>
    <div class="mt-5 grid gap-2 sm:hidden">
        <a href="{{ route('admin.appointments.create') }}" class="btn-primary">+ {{ __('schedule.add_visit') }}</a>
        <a href="{{ route('admin.schedule.import', array_filter(['cleaner' => $staffId])) }}" class="btn-secondary">⇪ {{ __('schedule_import.button') }}</a>
    </div>
</x-layouts.admin>
