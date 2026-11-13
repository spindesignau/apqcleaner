@php $editing = $staff->exists; @endphp
<x-layouts.admin :title="$editing ? __('admin.staff.edit', ['name' => $staff->name]) : __('admin.staff.new')">
    <a href="{{ route('admin.staff.index') }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ __('admin.staff.title') }}</a>

    <div class="mt-4 grid max-w-5xl gap-6 lg:grid-cols-[1fr_20rem]">
        <form method="POST" action="{{ $editing ? route('admin.staff.update', $staff) : route('admin.staff.store') }}" class="space-y-6">
            @csrf
            @if ($editing) @method('PUT') @endif

            <section class="card space-y-5">
                <h2 class="text-lg">{{ __('admin.staff.sections.account') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="label">{{ __('admin.staff.fields.name') }}</label>
                        <input id="name" name="name" required value="{{ old('name', $staff->name) }}" class="input">
                        <x-field-error name="name" />
                    </div>
                    <div>
                        <label for="email" class="label">{{ __('admin.staff.fields.email') }}</label>
                        <input id="email" name="email" type="email" required value="{{ old('email', $staff->email) }}" class="input">
                        <x-field-error name="email" />
                    </div>
                    <div>
                        <label for="mobile" class="label">{{ __('admin.staff.fields.mobile') }}</label>
                        <input id="mobile" name="mobile" type="tel" inputmode="tel" value="{{ old('mobile', $staff->mobile) }}" placeholder="0412 345 678" class="input">
                        <p class="mt-1 text-sm text-navy-500">{{ __('admin.staff.help.mobile') }}</p>
                        <x-field-error name="mobile" />
                    </div>
                    <div>
                        <label for="role" class="label">{{ __('admin.staff.fields.role') }}</label>
                        <select id="role" name="role" class="input">
                            @foreach (\App\Enums\Role::cases() as $role)
                                <option value="{{ $role->value }}" @selected(old('role', $staff->role?->value ?? 'staff') === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        <x-field-error name="role" />
                    </div>
                    <div>
                        <label for="locale" class="label">{{ __('admin.staff.fields.locale') }}</label>
                        <select id="locale" name="locale" class="input">
                            @foreach (config('apq.locales') as $code => $label)
                                <option value="{{ $code }}" @selected(old('locale', $staff->locale ?? 'en') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="password" class="label">{{ __('admin.staff.fields.password') }}</label>
                        <input id="password" name="password" type="text" autocomplete="off" @required(! $editing) class="input font-mono">
                        <p class="mt-1 text-sm text-navy-500">{{ $editing ? __('admin.staff.help.password_edit') : __('admin.staff.help.password_new') }}</p>
                        <x-field-error name="password" />
                    </div>
                </div>
            </section>

            <section class="card space-y-5">
                <div>
                    <h2 class="text-lg">{{ __('admin.staff.sections.employment') }}</h2>
                    <p class="text-sm text-navy-500">🔒 {{ __('admin.staff.help.private') }}</p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="employment_type" class="label">{{ __('admin.staff.fields.employment_type') }}</label>
                        <select id="employment_type" name="employment_type" class="input">
                            @foreach (\App\Enums\EmploymentType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('employment_type', $staff->employment_type?->value ?? 'casual') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="hourly_rate" class="label">{{ __('admin.staff.fields.hourly_rate') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                            <input id="hourly_rate" name="hourly_rate" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('hourly_rate', $staff->hourly_rate) }}" class="input pl-8">
                        </div>
                        <x-field-error name="hourly_rate" />
                    </div>
                    @foreach (['saturday_rate', 'sunday_rate'] as $field)
                        <div>
                            <label for="{{ $field }}" class="label">{{ __('admin.staff.fields.'.$field) }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-navy-500">$</span>
                                <input id="{{ $field }}" name="{{ $field }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old($field, $staff->{$field}) }}" class="input pl-8">
                            </div>
                            <p class="mt-1 text-sm text-navy-500">{{ __('admin.staff.help.weekend') }}</p>
                            <x-field-error :name="$field" />
                        </div>
                    @endforeach
                    <div>
                        <label for="abn" class="label">{{ __('admin.staff.fields.abn') }}</label>
                        <input id="abn" name="abn" inputmode="numeric" value="{{ old('abn', $staff->abn) }}" placeholder="12 345 678 901" class="input">
                        <x-field-error name="abn" />
                    </div>
                    <div class="sm:col-span-2">
                        <label for="notes" class="label">{{ __('admin.staff.fields.notes') }}</label>
                        <textarea id="notes" name="notes" rows="3" class="input !min-h-24 py-3">{{ old('notes', $staff->notes) }}</textarea>
                    </div>
                </div>
            </section>

            <button type="submit" class="btn-primary sm:w-auto">{{ __('admin.staff.save') }}</button>
        </form>

        @if ($editing)
            <aside class="space-y-4">
                <section class="card">
                    <h2 class="text-lg">{{ __('admin.staff.sections.access') }}</h2>
                    <div class="mt-3 flex items-center gap-3">
                        <x-avatar :user="$staff" size="md" />
                        @if ($staff->is_active)
                            <span class="badge-sage">{{ __('admin.staff.status_active') }}</span>
                        @else
                            <span class="badge-alert">{{ __('admin.staff.status_locked') }}</span>
                        @endif
                    </div>
                    <p class="mt-3 text-sm text-navy-500">
                        {{ __('admin.staff.last_login') }}:
                        {{ $staff->last_login_at?->tz(config('apq.display_timezone'))->translatedFormat(__('dates.datetime')) ?? __('admin.staff.never') }}
                    </p>

                    @if ($staff->is_active)
                        @unless ($staff->is(auth()->user()))
                            <form method="POST" action="{{ route('admin.staff.lock', $staff) }}" class="mt-4 space-y-3"
                                  x-data @submit="if (! confirm(@js(__('admin.staff.lock_confirm', ['name' => $staff->name])))) $event.preventDefault()">
                                @csrf
                                <p class="text-sm text-navy-500">{{ __('admin.staff.lock_help') }}</p>
                                <div>
                                    <label for="lock_reason" class="label">{{ __('admin.staff.lock_reason') }}</label>
                                    <input id="lock_reason" name="lock_reason" maxlength="255" class="input !min-h-11">
                                </div>
                                <button type="submit" class="btn-danger btn-sm !w-full">{{ __('admin.staff.lock') }}</button>
                            </form>
                        @endunless
                    @else
                        <p class="mt-3 rounded-xl bg-alert-50 px-3 py-2 text-sm text-alert">
                            {{ __('admin.staff.locked_since', [
                                'date' => $staff->locked_at?->tz(config('apq.display_timezone'))->translatedFormat(__('dates.datetime')),
                                'by' => $staff->lockedBy?->name ?? '—',
                            ]) }}
                            @if ($staff->lock_reason)<br>“{{ $staff->lock_reason }}”@endif
                        </p>
                        <form method="POST" action="{{ route('admin.staff.unlock', $staff) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn-secondary btn-sm !w-full">{{ __('admin.staff.unlock') }}</button>
                        </form>
                    @endif
                </section>
            </aside>
        @endif
    </div>
</x-layouts.admin>
