@php
    $hour = now(config('apq.display_timezone'))->hour;
    $period = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
@endphp
<x-layouts.admin :title="__('admin.dashboard.title')">
    <p class="font-display text-2xl font-semibold">
        {{ __('admin.dashboard.greeting', ['period' => __('admin.dashboard.periods.'.$period), 'name' => Str::before(auth()->user()->name, ' ')]) }}
    </p>
    <p class="mt-1 max-w-2xl text-navy-500">{{ __('admin.dashboard.roadmap') }}</p>

    @if ($systemProblems)
        <p class="mt-4 max-w-4xl rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert">⚠ {{ __('system.banner', ['list' => collect($systemProblems)->map(fn ($k) => __('system.checks.'.$k))->implode(', ')]) }} <a class="underline" href="{{ route('admin.settings') }}">{{ __('system.title') }} ›</a></p>
    @endif
    @unless ($xeroConnected)
        <p class="mt-4 max-w-4xl rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber">⚠ {{ __('admin.dashboard.xero_disconnected') }} <a class="underline" href="{{ route('admin.xero.show') }}">{{ __('xero.connect') }} ›</a></p>
    @endunless
    @if ($failedInvoices)
        <p class="mt-4 max-w-4xl rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert">{{ trans_choice('xero.failed_banner', $failedInvoices, ['count' => $failedInvoices]) }} <a class="underline" href="{{ route('admin.invoices.index', ['state' => 'failed']) }}">{{ __('invoices.title') }} ›</a></p>
    @endif

    <div class="mt-6 grid max-w-5xl grid-cols-2 gap-4 md:grid-cols-5">
        <a href="{{ route('admin.schedule') }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.today_visits') }}</p>
            <p class="mt-1 font-display text-4xl font-semibold">{{ $todayVisits }}</p>
        </a>
        <a href="{{ route('admin.timesheets.index', ['status' => 'submitted']) }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.awaiting_approval') }}</p>
            <p @class(['mt-1 font-display text-4xl font-semibold', 'text-amber' => $awaitingApproval > 0])>{{ $awaitingApproval }}</p>
        </a>
        <a href="{{ route('admin.invoices.run') }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.uninvoiced') }}</p>
            <p class="mt-1 font-display text-4xl font-semibold">{{ $uninvoiced }}</p>
        </a>
        <a href="{{ route('admin.staff.index', ['status' => 'active']) }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.active_staff') }}</p>
            <p class="mt-1 font-display text-4xl font-semibold">{{ $activeStaff }}</p>
        </a>
        <a href="{{ route('admin.staff.index', ['status' => 'locked']) }}" class="card block hover:border-sage">
            <p class="label !mb-0">{{ __('admin.dashboard.locked_staff') }}</p>
            <p @class(['mt-1 font-display text-4xl font-semibold', 'text-alert' => $lockedStaff > 0])>{{ $lockedStaff }}</p>
        </a>
    </div>

    <section class="card mt-6 max-w-4xl">
        <h2 class="flex items-center gap-2 text-lg"><span class="size-2.5 animate-pulse rounded-full bg-sage"></span> {{ __('admin.dashboard.live_timers') }}</h2>
        <ul class="mt-3 divide-y divide-sand">
            @forelse ($running as $entry)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-[15px]">
                    <span><strong>{{ $entry->user->name }}</strong> · {{ $entry->appointment->client->display_name }}</span>
                    <span class="flex items-center gap-3">
                        @if ($entry->isOverrunning())<span class="badge-alert">{{ __('admin.dashboard.overrunning') }}</span>@endif
                        <x-job-timer :entry="$entry" size="sm" />
                        <a href="{{ route('admin.timesheets.edit', $entry) }}" class="text-sm font-semibold underline">{{ __('common.edit') }}</a>
                    </span>
                </li>
            @empty
                <li class="py-3 text-navy-500">{{ __('admin.dashboard.no_live_timers') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card mt-6 max-w-4xl">
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
