<x-layouts.staff :title="__('staff.weekly_schedule')">
    <div class="flex items-center justify-between gap-2">
        @if ($prevWeek)
            <a href="{{ route('jobs.week', $prevWeek) }}" class="btn-secondary btn-sm size-12 !w-12 !px-0" aria-label="{{ __('staff.previous_week') }}">◀</a>
        @else
            <span class="size-12"></span>
        @endif
        <div class="text-center">
            <h1 class="text-[22px] leading-tight">{{ __('staff.weekly_schedule') }}</h1>
            <p class="text-[15px] text-navy-500">
                {{ $days[0]->translatedFormat(__('dates.short')) }} – {{ $days[6]->translatedFormat(__('dates.short')) }}
            </p>
        </div>
        @if ($nextWeek)
            <a href="{{ route('jobs.week', $nextWeek) }}" class="btn-secondary btn-sm size-12 !w-12 !px-0" aria-label="{{ __('staff.next_week') }}">▶</a>
        @else
            <span class="size-12"></span>
        @endif
    </div>
    @unless ($week->isCurrent())
        <p class="mt-3 text-center"><a href="{{ route('jobs.week') }}" class="badge-sage">{{ __('staff.this_week') }}</a></p>
    @endunless

    <ol class="mt-5 space-y-3" data-stagger>
        @foreach ($days as $day)
            @php
                $isToday = $day->isSameDay($now);
                $jobs = $jobsByDay->get($day->toDateString(), collect());
            @endphp
            <li style="--i: {{ $loop->index }}" @class(['card !p-0 overflow-hidden', 'border-l-[6px] !border-l-sage' => $isToday, 'opacity-70' => $day->lt($now->copy()->startOfDay())])>
                <div class="flex items-center justify-between px-4 pt-3 pb-1">
                    <h2 class="text-[17px]">{{ ucfirst($day->translatedFormat(__('dates.day_short'))) }}</h2>
                    @if ($isToday)<span class="badge-sage">{{ __('staff.today') }}</span>@endif
                </div>
                @if ($jobs->isEmpty())
                    <p class="px-4 pb-3 text-[15px] text-navy-500">{{ __('staff.no_jobs_day') }}</p>
                @else
                    <ul class="divide-y divide-sand">
                        @foreach ($jobs as $job)
                            <li>
                                <a href="{{ route('jobs.show', $job) }}" class="flex min-h-16 items-center gap-3 px-4 py-2.5 active:bg-canvas-50">
                                    <span class="w-24 shrink-0 font-semibold tabular-nums">{{ $job->localStart()->format(app()->getLocale() === 'es' ? 'H:i' : 'g:ia') }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $job->client->display_name }}</span>
                                        <span class="block truncate text-sm text-navy-500">{{ $job->client->suburb }}</span>
                                    </span>
                                    <span class="text-navy-500" aria-hidden="true">›</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ol>
</x-layouts.staff>
