@props(['tone' => 'dark'])
<nav {{ $attributes->class([
        'inline-flex rounded-full border p-0.5 text-sm font-bold',
        'border-offwhite/30 text-offwhite' => $tone === 'dark',
        'border-sand-600 text-navy' => $tone === 'light',
    ]) }} aria-label="{{ __('common.language') }}">
    @foreach (config('apq.locales') as $code => $name)
        @php $active = app()->getLocale() === $code; @endphp
        <a href="{{ route('locale.switch', $code) }}" hreflang="{{ $code }}" lang="{{ $code }}" title="{{ $name }}"
           @if ($active) aria-current="true" @endif
           @class([
               'min-w-11 rounded-full px-3 py-1.5 text-center leading-5 transition-colors',
               'bg-sage text-white' => $active,
               'hover:bg-white/10' => ! $active && $tone === 'dark',
               'hover:bg-canvas' => ! $active && $tone === 'light',
           ])>{{ strtoupper($code) }}</a>
    @endforeach
</nav>
