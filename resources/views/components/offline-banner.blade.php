<div x-data="connection" x-show="!online" x-cloak role="status"
     x-transition:enter="apq-enter" x-transition:enter-start="apq-enter-start" x-transition:enter-end="apq-enter-end"
     x-transition:leave="apq-leave" x-transition:leave-start="apq-leave-start" x-transition:leave-end="apq-leave-end"
     {{ $attributes->class('bg-amber-50 px-4 py-2 text-center text-[14px] font-semibold text-amber') }}>
    📶 {{ __('common.offline') }}
</div>
