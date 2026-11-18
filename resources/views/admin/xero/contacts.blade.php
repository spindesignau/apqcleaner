<x-layouts.admin :title="__('xero.import_title')">
    <a href="{{ route('admin.xero.show') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('xero.title') }}</a>
    @error('xero')<p class="mt-4 max-w-3xl rounded-xl bg-alert-50 px-4 py-3 font-medium text-alert" role="alert">{{ $message }}</p>@enderror

    <p class="mt-4 max-w-3xl text-[15px] text-navy-500">{{ __('xero.import_intro') }}</p>

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <form method="GET" class="flex flex-1 gap-2" role="search">
            <input type="hidden" name="show" value="{{ $customersOnly ? 'customers' : 'all' }}">
            <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('xero.import_search') }}" class="input !min-h-11 max-w-md" aria-label="{{ __('common.search') }}">
            <button class="btn-secondary btn-sm">{{ __('common.search') }}</button>
        </form>
        <nav class="flex flex-wrap gap-1.5">
            @foreach (['customers', 'all'] as $show)
                <a href="{{ route('admin.xero.contacts', array_filter(['show' => $show, 'q' => $search])) }}"
                   @class(['badge min-h-9 !px-4', 'bg-navy text-offwhite' => ($show === 'customers') === $customersOnly, 'badge-sand' => ($show === 'customers') !== $customersOnly])>{{ __('xero.import_show.'.$show) }}</a>
            @endforeach
            <a href="{{ route('admin.xero.contacts', ['refresh' => 1]) }}" class="badge badge-sand min-h-9 !px-4">↻ {{ __('xero.import_refresh') }}</a>
        </nav>
    </div>

    <p class="mt-3 flex flex-wrap gap-2 text-sm">
        <span class="badge-sage">{{ __('xero.status.new') }}: {{ $counts['new'] ?? 0 }}</span>
        <span class="badge-amber">{{ __('xero.status.match') }}: {{ $counts['match'] ?? 0 }}</span>
        <span class="badge-sand">{{ __('xero.status.linked') }}: {{ $counts['linked'] ?? 0 }}</span>
    </p>

    <form method="POST" action="{{ route('admin.xero.contacts.import') }}" class="mt-4"
          x-data="{ picked: [], all: @js($rows->where('status', '!=', 'linked')->pluck('id')->values()) }">
        @csrf
        <div class="overflow-hidden rounded-[var(--radius-card)] border border-sand bg-surface shadow-[var(--shadow-card)]">
            <div class="flex items-center gap-3 border-b border-sand bg-canvas-50 px-4 py-3 sm:px-5">
                <input type="checkbox" class="size-6 rounded-md border-sand-600 text-navy focus:ring-sage" aria-label="{{ __('xero.import_select_all') }}"
                       :checked="all.length && picked.length === all.length" @change="picked = $event.target.checked ? [...all] : []">
                <span class="text-sm font-semibold">{{ __('xero.import_select_all') }}</span>
                <span class="ml-auto text-sm text-navy-500" x-text="@js(__('xero.import_selected')).replace(':count', picked.length)"></span>
            </div>
            <ul class="divide-y divide-sand">
                @forelse ($rows as $row)
                    <li>
                        <label @class(['flex items-center gap-4 px-4 py-3 sm:px-5', 'cursor-pointer hover:bg-canvas-50' => $row['status'] !== 'linked', 'opacity-70' => $row['status'] === 'linked'])>
                            @if ($row['status'] === 'linked')
                                <span class="size-6 shrink-0 text-center text-sage">✓</span>
                            @else
                                <input type="checkbox" name="contacts[]" value="{{ $row['id'] }}" x-model="picked" class="size-6 shrink-0 rounded-md border-sand-600 text-navy focus:ring-sage">
                            @endif
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $row['name'] }}</p>
                                <p class="truncate text-sm text-navy-500">{{ collect([$row['email'], \App\Support\AuPhone::display($row['mobile']), $row['suburb']])->filter()->implode(' · ') ?: '—' }}</p>
                            </div>
                            <div class="text-right text-sm">
                                <span @class(['badge-sage' => $row['status'] === 'new', 'badge-amber' => $row['status'] === 'match', 'badge-sand' => $row['status'] === 'linked'])>{{ __('xero.status.'.$row['status']) }}</span>
                                @if ($row['client_name'])
                                    <p class="mt-1 text-navy-500">→ {{ $row['client_name'] }}</p>
                                @endif
                            </div>
                        </label>
                    </li>
                @empty
                    <li class="px-5 py-10 text-center text-navy-500">{{ __('xero.import_empty') }}</li>
                @endforelse
            </ul>
        </div>
        <div class="sticky bottom-4 mt-4 flex justify-end">
            <button type="submit" class="btn-primary sm:w-auto" :disabled="! picked.length">{{ __('xero.import_button') }}</button>
        </div>
    </form>
</x-layouts.admin>
