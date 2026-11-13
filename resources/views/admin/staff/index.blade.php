<x-layouts.admin :title="__('admin.staff.title')">
    <x-slot:actions>
        <a href="{{ route('admin.staff.create') }}" class="btn-primary btn-sm hidden sm:inline-flex">+ {{ __('admin.staff.add') }}</a>
    </x-slot:actions>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <form method="GET" class="flex flex-1 gap-2" role="search">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('admin.staff.search_placeholder') }}" class="input !min-h-11 max-w-sm" aria-label="{{ __('common.search') }}">
            <button class="btn-secondary btn-sm">{{ __('common.search') }}</button>
        </form>
        <nav class="flex gap-1.5">
            @foreach (['all', 'active', 'locked'] as $s)
                <a href="{{ route('admin.staff.index', array_filter(['status' => $s, 'q' => $q])) }}"
                   @class(['badge min-h-9 !px-4', 'bg-navy text-offwhite' => $status === $s, 'badge-sand' => $status !== $s])>{{ __('admin.staff.filter.'.$s) }}</a>
            @endforeach
        </nav>
        <a href="{{ route('admin.staff.create') }}" class="btn-primary sm:hidden">+ {{ __('admin.staff.add') }}</a>
    </div>

    <div class="mt-5 overflow-hidden rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
        <ul class="divide-y divide-sand">
            @forelse ($staff as $person)
                <li>
                    <a href="{{ route('admin.staff.edit', $person) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-canvas-50 sm:px-5">
                        <x-avatar :user="$person" size="md" @class(['opacity-50' => ! $person->is_active]) />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ $person->name }}
                                @if ($person->isAdmin())<span class="badge-navy !py-0.5 !text-[11px]">{{ $person->role->label() }}</span>@endif
                            </p>
                            <p class="truncate text-sm text-navy-500">{{ $person->email }}</p>
                        </div>
                        <div class="hidden text-right text-sm sm:block">
                            <p class="font-semibold">{{ $person->hourly_rate ? '$'.number_format($person->hourly_rate, 2).__('admin.staff.per_hour') : '—' }}</p>
                            <p class="text-navy-500">{{ $person->employment_type->label() }}</p>
                        </div>
                        <div class="hidden w-40 text-right text-sm text-navy-500 md:block">
                            {{ __('admin.staff.last_login') }}<br>
                            {{ $person->last_login_at?->tz(config('apq.display_timezone'))->diffForHumans() ?? __('admin.staff.never') }}
                        </div>
                        @if ($person->is_active)
                            <span class="badge-sage">{{ __('admin.staff.status_active') }}</span>
                        @else
                            <span class="badge-alert">{{ __('admin.staff.status_locked') }}</span>
                        @endif
                    </a>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-navy-500">{{ __('admin.staff.empty') }}</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $staff->links() }}</div>
</x-layouts.admin>
