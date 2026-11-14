<x-layouts.admin :title="__('invoices.title')">
    <x-slot:actions>
        <a href="{{ route('admin.invoices.run') }}" class="btn-primary btn-sm">+ {{ __('invoices.new_run') }}</a>
    </x-slot:actions>

    @unless ($connected)
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber">⚠ {{ __('invoices.connect_first') }} <a class="underline" href="{{ route('admin.xero.show') }}">{{ __('xero.title') }} ›</a></p>
    @endunless

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['all', 'draft', 'unpaid', 'paid', 'failed'] as $s)
            <a href="{{ route('admin.invoices.index', $s === 'all' ? [] : ['state' => $s]) }}"
               @class(['badge min-h-9 !px-4', 'bg-navy text-offwhite' => ($state ?? 'all') === $s, 'badge-sand' => ($state ?? 'all') !== $s])>{{ __('invoices.filter.'.$s) }}</a>
        @endforeach
        <span class="ml-auto text-[15px]">{{ __('invoices.outstanding') }}: <strong>${{ number_format((float) $outstanding, 2) }}</strong></span>
        @if ($failedCount)
            <form method="POST" action="{{ route('admin.invoices.retry-failed') }}">@csrf<button class="btn-secondary btn-sm !border-alert !text-alert">↻ {{ __('invoices.retry_all') }} ({{ $failedCount }})</button></form>
        @endif
    </div>

    @include('admin.invoices._table', ['invoices' => $invoices])
    <div class="mt-4">{{ $invoices->links() }}</div>
</x-layouts.admin>
