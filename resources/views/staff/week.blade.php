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
    @unless ($isThisWeek)
        <p class="mt-3 text-center"><a href="{{ route('jobs.week') }}" class="badge-sage">{{ __('staff.this_week') }}</a></p>
    @endunless

    <ol class="mt-5 space-y-3">
        @foreach ($days as $day)
            @php $isToday = $day->isSameDay($now); @endphp
            <li @class(['card !p-4', 'border-l-[6px] !border-l-sage' => $isToday])>
                <div class="flex items-center justify-between">
                    <h2 class="text-[17px]">{{ ucfirst($day->translatedFormat(__('dates.day_short'))) }}</h2>
                    @if ($isToday)<span class="badge-sage">{{ __('staff.today') }}</span>@endif
                </div>
                @php $jobs = $jobsByDay->get($day->toDateString(), collect()); @endphp
                @if ($jobs->isEmpty())
                    <p class="mt-1 text-[15px] text-navy-500">{{ __('staff.no_jobs_day') }}</p>
                @endif
            </li>
        @endforeach
    </ol>
</x-layouts.staff>
