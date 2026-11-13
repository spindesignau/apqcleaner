<x-layouts.guest :title="__('login.change_title')">
    <h1 class="text-2xl">{{ __('login.change_title') }}</h1>
    <p class="mt-1 text-navy-500">{{ __('login.change_help') }}</p>

    <form method="POST" action="{{ route('password.change.update') }}" class="mt-6 space-y-5">
        @csrf
        @method('PUT')
        <div>
            <label for="current_password" class="label">{{ __('login.temporary_password') }}</label>
            <x-password-input name="current_password" />
            <x-field-error name="current_password" />
        </div>
        <div>
            <label for="password" class="label">{{ __('login.new_password') }}</label>
            <x-password-input name="password" autocomplete="new-password" />
            <p class="mt-1.5 text-sm text-navy-500">{{ __('login.password_rules') }}</p>
            <x-field-error name="password" />
        </div>
        <div>
            <label for="password_confirmation" class="label">{{ __('login.confirm_password') }}</label>
            <x-password-input name="password_confirmation" autocomplete="new-password" />
        </div>
        <button type="submit" class="btn-primary">{{ __('login.reset_button') }}</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="font-medium text-navy-500 hover:text-navy hover:underline">{{ __('common.sign_out') }}</button>
    </form>
</x-layouts.guest>
