<x-layouts.guest :title="__('login.forgot_title')">
    <h1 class="text-2xl">{{ __('login.forgot_title') }}</h1>
    <p class="mt-1 text-navy-500">{{ __('login.forgot_help') }}</p>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-sage-50 px-4 py-3 text-[15px] text-sage-700" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
        @csrf
        <div>
            <label for="email" class="label">{{ __('login.email') }}</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username" autocapitalize="none"
                   required autofocus value="{{ old('email') }}" class="input">
            <x-field-error name="email" />
        </div>
        <button type="submit" class="btn-primary">{{ __('login.send_link') }}</button>
    </form>

    <p class="mt-6 text-center">
        <a href="{{ route('login') }}" class="font-medium text-navy-500 hover:text-navy hover:underline">← {{ __('login.back_to_login') }}</a>
    </p>
</x-layouts.guest>
