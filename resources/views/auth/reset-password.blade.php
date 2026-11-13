<x-layouts.guest :title="__('login.reset_title')">
    <h1 class="text-2xl">{{ __('login.reset_title') }}</h1>
    <p class="mt-1 text-navy-500">{{ __('login.password_rules') }}</p>

    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="label">{{ __('login.email') }}</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $email) }}" class="input">
            <x-field-error name="email" />
        </div>
        <div>
            <label for="password" class="label">{{ __('login.new_password') }}</label>
            <x-password-input name="password" autocomplete="new-password" />
            <x-field-error name="password" />
        </div>
        <div>
            <label for="password_confirmation" class="label">{{ __('login.confirm_password') }}</label>
            <x-password-input name="password_confirmation" autocomplete="new-password" />
        </div>
        <button type="submit" class="btn-primary">{{ __('login.reset_button') }}</button>
    </form>
</x-layouts.guest>
