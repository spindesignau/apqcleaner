@props(['title' => null])
@php
    $nav = [
        ['admin.dashboard', 'admin.dashboard', 'dashboard', 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
        ['admin.schedule', 'admin.schedule*', 'schedule', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z'],
        ['admin.timesheets.index', 'admin.timesheets.*', 'timesheets', 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        ['admin.clients.index', 'admin.clients.*', 'clients', 'M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V10Z'],
        ['admin.staff.index', 'admin.staff.*', 'staff', 'M17 20h5v-2a3 3 0 0 0-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 0 1 5.36-1.86M7 20v-2c0-.66.13-1.28.36-1.86m0 0a5 5 0 0 1 9.28 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
        ['admin.invoices.index', 'admin.invoices.*', 'invoices', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2Z'],
        [null, null, 'cancellations', 'M6 18 18 6M6 6l12 12'],
        [null, null, 'sms', 'M8 10h8m-8 4h5m-9 6 3-3h11a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14Z'],
        [null, null, 'reports', 'M9 19v-6m4 6V9m4 10V5M5 19v-2'],
        ['admin.xero.show', 'admin.xero.*', 'settings', 'M10.3 4.3c.4-1.7 3-1.7 3.4 0a1.7 1.7 0 0 0 2.6 1.1c1.5-.9 3.3.8 2.4 2.4a1.7 1.7 0 0 0 1 2.5c1.8.5 1.8 3 0 3.5a1.7 1.7 0 0 0-1 2.5c.9 1.6-.9 3.3-2.4 2.4a1.7 1.7 0 0 0-2.6 1c-.4 1.8-3 1.8-3.4 0a1.7 1.7 0 0 0-2.6-1c-1.5.9-3.3-.8-2.4-2.4a1.7 1.7 0 0 0-1-2.5c-1.8-.5-1.8-3 0-3.5a1.7 1.7 0 0 0 1-2.5c-.9-1.6.9-3.3 2.4-2.4a1.7 1.7 0 0 0 2.6-1ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh bg-canvas" x-data="{ menu: false }" @keydown.escape="menu = false">
    {{-- Sidebar (desktop) / slide-over (mobile) --}}
    <div x-show="menu" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-navy/50 lg:hidden" @click="menu = false"></div>
    <aside :class="menu ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col bg-navy pt-[env(safe-area-inset-top)] text-offwhite transition-transform lg:translate-x-0">
        <div class="flex h-24 items-center px-6">
            <a href="{{ route('admin.dashboard') }}"><x-logo variant="dark" class="h-16 w-auto" /></a>
        </div>
        <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="{{ __('common.menu') }}">
            <ul class="space-y-1">
                @foreach ($nav as [$route, $match, $key, $icon])
                    @php $active = $match && request()->routeIs($match); @endphp
                    <li>
                        @if ($route)
                            <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif
                               @class(['flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold',
                                       'bg-white/10 text-white' => $active, 'text-offwhite/80 hover:bg-white/5 hover:text-white' => ! $active])>
                                <svg class="size-5 {{ $active ? 'text-sage' : '' }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                {{ __('admin.nav.'.$key) }}
                            </a>
                        @else
                            <span class="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-semibold text-offwhite/35" title="{{ __('common.coming_soon') }}">
                                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                {{ __('admin.nav.'.$key) }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
        {{-- Admin-only "What's new" link (this layout is used only behind the admin role check). --}}
        @php $appVersion = app(\App\Support\Changelog::class)->version(); @endphp
        <a href="{{ route('admin.changelog') }}"
           @class(['mx-3 mb-2 flex items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold hover:bg-white/5',
                   'bg-white/10 text-white' => request()->routeIs('admin.changelog'), 'text-offwhite/60 hover:text-white' => ! request()->routeIs('admin.changelog')])>
            <span>✦ {{ __('admin.changelog.link') }}</span>
            @if ($appVersion)<span class="font-mono text-offwhite/50">v{{ $appVersion }}</span>@endif
        </a>
        <div class="border-t border-white/10 p-4">
            <div class="flex items-center gap-3">
                <x-avatar :user="auth()->user()" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                    <a href="{{ route('jobs.today') }}" class="text-xs text-offwhite/70 underline hover:text-white">{{ __('common.staff_view') }}</a>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-offwhite/70 hover:bg-white/10 hover:text-white" title="{{ __('common.sign_out') }}" aria-label="{{ __('common.sign_out') }}">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-30 border-b border-sand bg-canvas/90 pt-[env(safe-area-inset-top)] backdrop-blur">
            <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button type="button" class="-ml-2 rounded-lg p-2 lg:hidden" @click="menu = true" aria-label="{{ __('common.menu') }}">
                    <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="flex-1 truncate text-xl">{{ $title }}</h1>
                {{ $actions ?? '' }}
                <x-lang-pill tone="light" />
            </div>
        </header>
        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <x-flash class="mb-5 max-w-3xl" />
            {{ $slot }}
        </main>
    </div>
</body>
</html>
