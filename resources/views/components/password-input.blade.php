@props(['name', 'id' => null, 'autocomplete' => 'current-password', 'bag' => 'default'])
@php $id ??= $name; @endphp
<div x-data="{ visible: false }" class="relative">
    <input :type="visible ? 'text' : 'password'" type="password" name="{{ $name }}" id="{{ $id }}"
           autocomplete="{{ $autocomplete }}" required
           @error($name, $bag) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
           {{ $attributes->class('input pr-14') }}>
    <button type="button" @click="visible = !visible"
            class="absolute inset-y-0 right-0 flex w-14 items-center justify-center text-navy-500 hover:text-navy"
            :aria-label="visible ? @js(__('login.hide_password')) : @js(__('login.show_password'))"
            aria-label="{{ __('login.show_password') }}">
        <svg x-show="!visible" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3 9.96 7.18a1 1 0 0 1 0 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3-9.96-7.18Z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg x-show="visible" x-cloak class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.98 8.22A10.48 10.48 0 0 0 2.04 12.3a1 1 0 0 0 0 .64C3.42 16.49 7.36 19.5 12 19.5c.99 0 1.95-.14 2.86-.4M6.23 6.23A10.45 10.45 0 0 1 12 4.5c4.64 0 8.57 3 9.96 7.18a1 1 0 0 1 0 .64 10.52 10.52 0 0 1-4.29 5.45M6.23 6.23 3 3m3.23 3.23 3.65 3.65m7.89 7.89L21 21m-3.23-3.23-3.65-3.65m0 0a3 3 0 1 0-4.24-4.24m4.24 4.24L9.88 9.88"/></svg>
    </button>
</div>
