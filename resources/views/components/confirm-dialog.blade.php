{{-- One animated confirm dialog per page. Forms opt in with data-confirm="Question?" (see resources/js/motion.js). --}}
<div x-data="confirmDialog" @apq-confirm.window="show($event)" @keydown.escape.window="open && cancel()"
     x-show="open" x-cloak class="fixed inset-0 z-[90] flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" :aria-label="message">
    <div x-show="open" class="apq-dialog-backdrop absolute inset-0 bg-navy/45 backdrop-blur-[2px]" @click="cancel()"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
    <div x-show="open" class="apq-dialog-panel relative w-full max-w-sm rounded-[var(--radius-card)] bg-surface p-6 shadow-2xl"
         x-transition:enter-start="opacity-0 translate-y-6 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
         x-transition:leave-start="opacity-100 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
        <p class="font-display text-lg font-semibold" x-text="message"></p>
        <div class="mt-6 grid grid-cols-2 gap-3">
            <button type="button" class="btn-secondary btn-sm !w-full !min-h-12" @click="cancel()">{{ __('common.cancel') }}</button>
            <button type="button" x-ref="ok" class="btn-sm !w-full !min-h-12" :class="danger ? 'btn-danger' : 'btn-primary'" @click="confirm()"
                    x-text="okLabel || @js(__('common.confirm'))"></button>
        </div>
    </div>
</div>
