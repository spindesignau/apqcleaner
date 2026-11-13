@props(['job'])
@php $client = $job->client; @endphp
<article {{ $attributes->class('card !p-0 overflow-hidden') }}>
    <a href="{{ route('jobs.show', $job) }}" class="block p-5 active:bg-canvas-50">
        <div class="flex items-center justify-between gap-2">
            <span class="font-display text-[17px] font-semibold tabular-nums">{{ $job->timeRange() }}</span>
            @if ($job->status !== \App\Enums\AppointmentStatus::Scheduled)
                <span class="{{ $job->status->badgeClass() }}">{{ $job->status->label() }}</span>
            @endif
        </div>
        <h2 class="mt-1.5 text-[21px] leading-tight">{{ $client->display_name }}</h2>
        <p class="mt-1 text-[15px] text-navy-500">📍 {{ $client->fullAddress() }}</p>
        @if ($client->access_notes)
            <p class="mt-1 line-clamp-2 text-[15px]">🔑 {{ $client->access_notes }}</p>
        @endif
        @if ($job->staff->count() > 1)
            <p class="mt-1 text-[15px] text-navy-500">👥 {{ __('staff.with', ['names' => $job->staff->where('id', '!=', auth()->id())->pluck('name')->map(fn ($n) => Str::before($n, ' ') ?: $n)->implode(', ')]) }}</p>
        @endif
    </a>
    <div class="grid grid-cols-2 border-t border-sand">
        <a href="{{ $client->mapUrl() }}" target="_blank" rel="noopener" class="flex min-h-14 items-center justify-center gap-2 font-semibold text-navy hover:bg-canvas-50">🗺 {{ __('clients.map') }}</a>
        <a href="{{ route('jobs.show', $job) }}" class="flex min-h-14 items-center justify-center gap-2 border-l border-sand font-semibold text-navy hover:bg-canvas-50">{{ __('staff.view_job') }} ›</a>
    </div>
</article>
