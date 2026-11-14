@props(['job'])
@php
    use App\Enums\TimeEntryStatus;
    $client = $job->client;
    $entry = $job->relationLoaded('timeEntries') ? $job->timeEntries->first() : null;
    $canStart = ! $entry && Gate::check('start', $job);
@endphp
<article {{ $attributes->class(['card !p-0 overflow-hidden', 'border-l-[6px] !border-l-sage' => $entry?->status === TimeEntryStatus::Running]) }}>
    <a href="{{ route('jobs.show', $job) }}" class="block p-5 active:bg-canvas-50">
        <div class="flex items-center justify-between gap-2">
            <span class="font-display text-[17px] font-semibold tabular-nums">{{ $job->timeRange() }}</span>
            @if ($entry)
                <span class="{{ $entry->status->badgeClass() }}">
                    @if ($entry->status === TimeEntryStatus::Running)<span class="size-2 animate-pulse rounded-full bg-sage"></span>@endif
                    {{ $entry->status->label() }}
                </span>
            @endif
        </div>
        <h2 class="mt-1.5 text-[21px] leading-tight">{{ $client->display_name }}</h2>
        <p class="mt-1 text-[15px] text-navy-500">📍 {{ $client->fullAddress() }}</p>
        @if ($client->access_notes && ! $entry?->status?->isSubmitted())
            <p class="mt-1 line-clamp-2 text-[15px]">🔑 {{ $client->access_notes }}</p>
        @endif
        @if ($job->staff->count() > 1)
            <p class="mt-1 text-[15px] text-navy-500">👥 {{ __('staff.with', ['names' => $job->staff->where('id', '!=', auth()->id())->pluck('name')->map(fn ($n) => Str::before($n, ' ') ?: $n)->implode(', ')]) }}</p>
        @endif
        @if ($entry?->status === TimeEntryStatus::Running)
            <p class="mt-3 text-center"><x-job-timer :entry="$entry" class="!text-[44px]" /></p>
        @elseif ($entry?->status?->isSubmitted())
            <p class="mt-2 font-semibold text-sage-700">✓ {{ \App\Models\TimeEntry::formatMinutes($entry->duration_minutes) }}</p>
        @endif
    </a>

    <div class="border-t border-sand p-3">
        @if ($canStart)
            <form method="POST" action="{{ route('jobs.start', $job) }}" data-gps data-haptic>
                @csrf
                <x-gps-fields />
                <button type="submit" class="btn-primary">▶ {{ __('timer.start_job') }}</button>
            </form>
        @elseif ($entry?->status === TimeEntryStatus::Running)
            <a href="{{ route('jobs.show', $job) }}" class="btn-stop">■ {{ __('timer.stop_job') }}</a>
        @elseif ($entry && $entry->status->editableByStaff())
            <a href="{{ route('jobs.show', $job) }}" class="btn-primary">✓ {{ __('timer.review_and_submit') }}</a>
        @else
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ $client->mapUrl() }}" target="_blank" rel="noopener" class="btn-secondary btn-sm !w-full">🗺 {{ __('clients.map') }}</a>
                <a href="{{ route('jobs.show', $job) }}" class="btn-secondary btn-sm !w-full">{{ __('staff.view_job') }} ›</a>
            </div>
        @endif
    </div>
</article>
