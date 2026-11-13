<x-layouts.guest :title="__('login.sign_in')">
    <h1 class="text-2xl">{{ __('login.welcome') }}</h1>
    <p class="mt-1 text-navy-500">{{ __('login.subtitle') }}</p>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-sage-50 px-4 py-3 text-[15px] text-sage-700" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5" novalidate>
        @csrf
        <div>
            <label for="email" class="label">{{ __('login.email') }}</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username" autocapitalize="none"
                   spellcheck="false" required autofocus value="{{ old('email') }}" class="input"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            <x-field-error name="email" />
        </div>
        <div>
            <label for="password" class="label">{{ __('login.password') }}</label>
            <x-password-input name="password" />
            <x-field-error name="password" />
        </div>
        <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[16px]">
            <input type="checkbox" name="remember" value="1" checked class="size-6 rounded-md border-sand-600 text-navy focus:ring-sage">
            {{ __('login.remember') }}
        </label>
        <button type="submit" class="btn-primary">{{ __('login.sign_in') }}</button>
    </form>

    <p class="mt-6 text-center">
        <a href="{{ route('password.request') }}" class="font-medium text-navy-500 underline-offset-4 hover:text-navy hover:underline">{{ __('login.forgot') }}</a>
    </p>
</x-layouts.guest>
