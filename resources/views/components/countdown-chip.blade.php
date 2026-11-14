@props(['appointment'])
@php
    $hours = $appointment->hoursUntilStart();
    $window = (int) ($appointment->client->cancellation_window_hours ?: 24);
    $abs = abs($hours);
    $span = $abs >= 24 ? floor($abs / 24).'d '.floor(fmod($abs, 24)).'h' : floor($abs).'h '.round(fmod($abs, 1) * 60).'m';
@endphp
@if ($hours <= 0)
    <span {{ $attributes->class('badge-navy') }}>{{ __('cancellations.countdown.due') }}</span>
@elseif ($hours < $window && $appointment->client->cancellation_fee_type !== 'none')
    <span {{ $attributes->class('badge-amber') }}>⏱ {{ __('cancellations.countdown.fee', ['time' => $span]) }}</span>
@elseif ($hours < 48)
    <span {{ $attributes->class('badge-sage') }}>{{ __('cancellations.countdown.in', ['time' => $span]) }}</span>
@else
    <span {{ $attributes->class('badge-sand') }}>{{ __('cancellations.countdown.in', ['time' => $span]) }}</span>
@endif
