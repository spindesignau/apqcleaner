@props(['user', 'size' => 'md'])
@php $classes = ['sm' => 'size-9 text-sm', 'md' => 'size-12 text-base', 'lg' => 'size-24 text-3xl'][$size]; @endphp
@if ($url = $user->avatarUrl())
    <img src="{{ $url }}" alt="" {{ $attributes->class([$classes, 'rounded-full object-cover ring-2 ring-sand']) }}>
@else
    <span {{ $attributes->class([$classes, 'inline-flex items-center justify-center rounded-full bg-sage-50 font-display font-semibold text-sage-700 ring-2 ring-sand']) }} aria-hidden="true">{{ $user->initials() }}</span>
@endif
