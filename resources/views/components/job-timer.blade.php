@props(['entry', 'size' => 'lg'])
<span x-data="jobTimer(@js($entry->started_at->toIso8601String()), @js(now()->toIso8601String()))"
      x-text="display" role="timer" aria-live="off"
      {{ $attributes->class(['font-display font-semibold tabular-nums', 'text-[56px] leading-none' => $size === 'lg']) }}>00:00:00</span>
