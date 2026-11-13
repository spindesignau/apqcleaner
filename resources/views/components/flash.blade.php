@if (session('status'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)" x-transition.opacity
         role="status" {{ $attributes->class('flex items-start gap-3 rounded-2xl bg-navy px-4 py-3 text-offwhite shadow-lg') }}>
        <svg class="mt-0.5 size-5 shrink-0 text-sage" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
        <p class="flex-1 text-[15px]">{{ session('status') }}</p>
        <button type="button" @click="show = false" class="-m-1 p-1 text-offwhite/70 hover:text-offwhite" aria-label="{{ __('common.close') }}">✕</button>
    </div>
@endif
@if (session('warning'))
    <div role="alert" {{ $attributes->class('mt-3 flex items-start gap-3 rounded-2xl border border-amber/30 bg-amber-50 px-4 py-3 text-amber') }}>
        <span aria-hidden="true">⚠</span>
        <p class="flex-1 text-[15px] font-medium">{{ session('warning') }}</p>
    </div>
@endif
