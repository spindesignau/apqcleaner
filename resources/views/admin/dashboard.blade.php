@php
    $hour = now(config('apq.display_timezone'))->hour;
    $period = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
@endphp
<x-layouts.admin :title="__('admin.dashboard.title')">
    <p class="font-display text-2xl font-semibold">
        {{ __('admin.dashboard.greeting', ['period' => __('admin.dashboard.periods.'.$period), 'name' => Str::before(auth()->user()->name, ' ')]) }}
    </p>
    <p class="mt-1 max-w-2xl text-navy-500">{{ __('admin.dashboard.roadmap') }}</p>

    <div class="mt-6 grid max-w-3xl grid-cols-2 gap-4">
        <a href="{{ route('admin.staff.index', ['status' => 'active']) }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.active_staff') }}</p>
            <p class="mt-1 font-display text-4xl font-semibold">{{ $activeStaff }}</p>
        </a>
        <a href="{{ route('admin.staff.index', ['status' => 'locked']) }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.locked_staff') }}</p>
            <p @class(['mt-1 font-display text-4xl font-semibold', 'text-alert' => $lockedStaff > 0])>{{ $lockedStaff }}</p>
        </a>
    </div>

    <section class="card mt-6 max-w-3xl">
        <h2 class="text-lg">{{ __('admin.dashboard.recent_activity') }}</h2>
        <ul class="mt-3 divide-y divide-sand">
            @forelse ($recentAudit as $log)
                <li class="flex items-baseline justify-between gap-4 py-2.5 text-[15px]">
                    <span>
                        {{ __('admin.audit.'.$log->action, [
                            'actor' => $log->user?->name ?? __('admin.audit.system'),
                            'subject' => $log->auditable?->name ?? '#'.$log->auditable_id,
                        ]) }}
                        @if ($log->reason)<span class="text-navy-500">— {{ $log->reason }}</span>@endif
                    </span>
                    <time class="shrink-0 text-sm text-navy-500" datetime="{{ $log->created_at->toIso8601String() }}">
                        {{ $log->created_at->tz(config('apq.display_timezone'))->diffForHumans() }}
                    </time>
                </li>
            @empty
                <li class="py-3 text-navy-500">{{ __('admin.dashboard.no_activity') }}</li>
            @endforelse
        </ul>
    </section>
</x-layouts.admin>
