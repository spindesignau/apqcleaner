<x-layouts.staff :title="__('staff.today_jobs')">
    <h1 class="text-[26px] leading-tight">{{ ucfirst($today->translatedFormat(__('dates.long_day'))) }}</h1>
    <p class="mt-0.5 text-navy-500">{{ trans_choice('staff.jobs_count', $jobs->count()) }}</p>

    @forelse ($jobs as $job)
        {{-- Phase 1/2: <x-job-card :appointment="$job" /> --}}
    @empty
        <div class="card mt-5 flex flex-col items-center px-6 py-10 text-center">
            <svg class="size-14 text-sage" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
                <path d="M16 2 Q17.6 13 30 16 Q17.6 19 16 30 Q14.4 19 2 16 Q14.4 13 16 2 Z"/>
            </svg>
            <p class="mt-4 font-display text-lg font-semibold">{{ __('staff.no_jobs_today') }}</p>
            <p class="mt-1 text-navy-500">{{ __('staff.no_jobs_today_help') }}</p>
        </div>
    @endforelse
</x-layouts.staff>
