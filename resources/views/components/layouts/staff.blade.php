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
                <x-logo variant="dark" class="h-11 w-auto" />
            </a>
            <div class="flex items-center gap-2">
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="rounded-full border border-offwhite/30 px-3 py-1.5 text-sm font-bold text-offwhite hover:bg-white/10">{{ __('common.admin_view') }}</a>
                @endif
                <x-lang-pill tone="dark" />
            </div>
        </div>
        {{-- Phase 2: running-timer banner goes here. --}}
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
