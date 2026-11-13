<x-layouts.admin :title="__('clients.title')">
    <x-slot:actions>
        <a href="{{ route('admin.clients.create') }}" class="btn-primary btn-sm hidden sm:inline-flex">+ {{ __('clients.add') }}</a>
    </x-slot:actions>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <form method="GET" class="flex flex-1 gap-2" role="search">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('clients.search_placeholder') }}" class="input !min-h-11 max-w-md" aria-label="{{ __('common.search') }}">
            <button class="btn-secondary btn-sm">{{ __('common.search') }}</button>
        </form>
        <nav class="flex gap-1.5">
            @foreach (['active', 'inactive', 'all'] as $s)
                <a href="{{ route('admin.clients.index', array_filter(['status' => $s, 'q' => $q])) }}"
                   @class(['badge min-h-9 !px-4', 'bg-navy text-offwhite' => $status === $s, 'badge-sand' => $status !== $s])>{{ __('clients.filter.'.$s) }}</a>
            @endforeach
        </nav>
        <a href="{{ route('admin.clients.create') }}" class="btn-primary sm:hidden">+ {{ __('clients.add') }}</a>
    </div>

    <div class="mt-5 overflow-hidden rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
        <ul class="divide-y divide-sand">
            @forelse ($clients as $client)
                <li>
                    <a href="{{ route('admin.clients.show', $client) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-canvas-50 sm:px-5">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ $client->display_name }}
                                @unless ($client->is_active)<span class="badge-sand !py-0.5 !text-[11px]">{{ __('clients.inactive') }}</span>@endunless
                            </p>
                            <p class="truncate text-sm text-navy-500">{{ $client->fullAddress() }}</p>
                        </div>
                        <div class="hidden text-right text-sm sm:block">
                            <p class="font-semibold">
                                @if ($price = $client->currentPrice())
                                    ${{ number_format($price, 2) }} <span class="font-normal text-navy-500">{{ $client->pricing_model->value === 'hourly' ? __('clients.per_hour') : __('clients.per_visit') }}</span>
                                @else
                                    <span class="text-amber">{{ __('clients.no_price') }}</span>
                                @endif
                            </p>
                            <p class="text-navy-500">{{ $client->payment_method->label() }}</p>
                        </div>
                        <span @class(['hidden w-44 text-right text-sm md:block', 'text-navy-500' => $client->schedules_count, 'text-amber' => ! $client->schedules_count])>
                            {{ trans_choice('clients.schedules_count', $client->schedules_count) }}
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-navy-500">{{ __('clients.empty') }}</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $clients->links() }}</div>
</x-layouts.admin>
