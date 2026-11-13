@props(['name', 'bag' => 'default'])
@error($name, $bag)
    <p {{ $attributes->class('mt-1.5 text-sm font-medium text-alert') }} id="{{ $name }}-error">{{ $message }}</p>
@enderror
