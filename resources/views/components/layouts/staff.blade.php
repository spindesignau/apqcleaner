@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh bg-canvas">
    <header class="sticky top-0 z-30 bg-navy pt-[env(safe-area-inset-top)]">
        <div class="mx-auto flex h-16 max-w-md items-center justify-between gap-3 px-4">
            <a href="{{ route('home') }}" aria-label="{{ __('common.app_name') }}">
                <x-logo variant="dark" class="h-10 w-auto shrink-0" />
            </a>
            <div class="flex items-center gap-1.5">
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="rounded-full border border-offwhite/30 px-2.5 py-1.5 text-sm font-bold text-offwhite hover:bg-white/10">{{ __('common.admin_view') }}</a>
                @endif
                <x-lang-pill tone="dark" />
                <form method="POST" action="{{ route('logout') }}" x-data
                      @submit="if (! confirm(@js(__('common.sign_out_confirm')))) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="flex size-11 items-center justify-center rounded-full border border-offwhite/30 text-offwhite hover:bg-white/10"
                            aria-label="{{ __('common.sign_out') }}" title="{{ __('common.sign_out') }}">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                    </button>
                </form>
            </div>
        </div>
        @php $running = auth()->user()->runningEntry(); @endphp
        @if ($running && ! request()->routeIs('jobs.show'))
            <a href="{{ route('jobs.show', $running->appointment_id) }}" class="block bg-sage-50 text-sage-700">
                <span class="mx-auto flex h-11 max-w-md items-center gap-2 px-4 text-[15px] font-bold">
                    <span class="size-2.5 shrink-0 animate-pulse rounded-full bg-sage"></span>
                    <span class="min-w-0 flex-1 truncate">{{ __('timer.banner', ['client' => $running->appointment->client->display_name]) }}</span>
                    <x-job-timer :entry="$running" size="sm" class="shrink-0" />
                    <span class="shrink-0" aria-hidden="true">›</span>
                </span>
            </a>
        @endif
    </header>

    <main class="mx-auto max-w-md px-4 pt-5 pb-[calc(6.5rem+env(safe-area-inset-bottom))]">
        <x-flash class="mb-4" />
        {{ $slot }}
    </main>

    @php
        $tabs = [
            ['route' => 'jobs.today', 'match' => 'jobs.today', 'label' => __('staff.nav.today'),
             'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z'],
            ['route' => 'jobs.week', 'match' => 'jobs.week', 'label' => __('staff.nav.week'),
             'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
            ['route' => 'profile.edit', 'match' => 'profile.*', 'label' => __('staff.nav.profile'),
             'icon' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z'],
        ];
    @endphp
    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-sand bg-surface pb-[env(safe-area-inset-bottom)]" aria-label="{{ __('common.menu') }}">
        <ul class="mx-auto grid h-[4.5rem] max-w-md grid-cols-3">
            @foreach ($tabs as $tab)
                @php $active = request()->routeIs($tab['match']); @endphp
                <li>
                    <a href="{{ route($tab['route']) }}" @if ($active) aria-current="page" @endif
                       @class(['flex h-full flex-col items-center justify-center gap-1 text-[13px] font-semibold',
                               'text-navy' => $active, 'text-navy-500' => ! $active])>
                        <span @class(['h-1 w-7 rounded-full', 'bg-sage' => $active, 'bg-transparent' => ! $active])></span>
                        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="{{ $active ? 2.2 : 1.7 }}" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $tab['icon'] }}"/></svg>
                        {{ $tab['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</body>
</html>
