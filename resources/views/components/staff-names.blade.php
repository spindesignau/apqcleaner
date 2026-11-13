@props(['staff'])
@if ($staff->isEmpty())
    <span {{ $attributes->class('text-amber') }}>{{ __('schedule.help.no_staff') }}</span>
@else
    <span {{ $attributes }}>{{ $staff->pluck('name')->map(fn ($n) => Str::before($n, ' ') ?: $n)->implode(', ') }}</span>
@endif
