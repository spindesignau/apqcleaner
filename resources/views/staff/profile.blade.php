<x-layouts.staff :title="__('profile.title')">
    <h1 class="text-[26px]">{{ __('profile.title') }}</h1>

    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="card mt-4 space-y-5"
          x-data="{ preview: null, remove: false }">
        @csrf
        @method('PATCH')
        <h2 class="text-lg">{{ __('profile.details') }}</h2>

        <div class="flex items-center gap-4">
            <div class="relative">
                <img x-show="preview" x-cloak :src="preview" alt="" class="size-24 rounded-full object-cover ring-2 ring-sand">
                <div x-show="!preview"><x-avatar :user="$user" size="lg" /></div>
            </div>
            <div class="flex-1 space-y-2">
                <label class="btn-secondary btn-sm cursor-pointer">
                    {{ __('profile.change_photo') }}
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="sr-only"
                           @change="const f = $event.target.files[0]; if (f) { preview = URL.createObjectURL(f); remove = false }">
                </label>
                @if ($user->avatar_path)
                    <label class="flex items-center gap-2 text-sm text-navy-500">
                        <input type="checkbox" name="remove_avatar" value="1" x-model="remove" class="rounded border-sand-600 text-navy focus:ring-sage">
                        {{ __('profile.remove_photo') }}
                    </label>
                @endif
                <p class="text-xs text-navy-500">{{ __('profile.photo_help') }}</p>
            </div>
        </div>
        <x-field-error name="avatar" />

        <div>
            <label for="name" class="label">{{ __('profile.name') }}</label>
            <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name', $user->name) }}" class="input">
            <x-field-error name="name" />
        </div>
        <div>
            <label for="email" class="label">{{ __('profile.email') }}</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email" autocapitalize="none" required
                   value="{{ old('email', $user->email) }}" class="input">
            <x-field-error name="email" />
        </div>
        <fieldset>
            <legend class="label">{{ __('profile.language') }}</legend>
            <div class="grid grid-cols-2 gap-2">
                @foreach (config('apq.locales') as $code => $name)
                    <label class="relative">
                        <input type="radio" name="locale" value="{{ $code }}" class="peer sr-only" @checked(old('locale', $user->locale) === $code)>
                        <span class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-sand-600 font-semibold peer-checked:border-navy peer-checked:bg-navy peer-checked:text-offwhite peer-focus-visible:ring-2 peer-focus-visible:ring-sage">{{ $name }}</span>
                    </label>
                @endforeach
            </div>
            <x-field-error name="locale" />
        </fieldset>
        <button type="submit" class="btn-primary">{{ __('profile.save') }}</button>
    </form>

    <form method="POST" action="{{ route('profile.password') }}" class="card mt-4 space-y-5">
        @csrf
        @method('PUT')
        <h2 class="text-lg">{{ __('profile.password_title') }}</h2>
        <div>
            <label for="current_password" class="label">{{ __('profile.current_password') }}</label>
            <x-password-input name="current_password" bag="password" />
            <x-field-error name="current_password" bag="password" />
        </div>
        <div>
            <label for="password" class="label">{{ __('profile.new_password') }}</label>
            <x-password-input name="password" autocomplete="new-password" bag="password" />
            <p class="mt-1.5 text-sm text-navy-500">{{ __('login.password_rules') }}</p>
            <x-field-error name="password" bag="password" />
        </div>
        <div>
            <label for="password_confirmation" class="label">{{ __('profile.confirm_password') }}</label>
            <x-password-input name="password_confirmation" autocomplete="new-password" bag="password" />
        </div>
        <button type="submit" class="btn-secondary">{{ __('profile.update_password') }}</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="btn-secondary !border-sand-600 !text-navy-500">{{ __('common.sign_out') }}</button>
    </form>
</x-layouts.staff>
