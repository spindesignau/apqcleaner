<x-layouts.admin :title="__('reports.title')">
    <div class="grid max-w-5xl gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger>
        @foreach ($keys as $key)
            <a href="{{ route('admin.reports.show', $key) }}" class="card block hover:border-sage" style="--i: {{ $loop->index }}">
                <h2 class="text-lg">{{ __('reports.names.'.$key) }}</h2>
                <p class="mt-1 text-[15px] text-navy-500">{{ __('reports.descriptions.'.$key) }}</p>
            </a>
        @endforeach
    </div>
</x-layouts.admin>
