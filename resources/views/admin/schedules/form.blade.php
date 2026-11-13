@php
    $editing = $schedule->exists;
    $days = collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => ucfirst(now()->startOfWeek()->addDays($d - 1)->translatedFormat('l'))]);
@endphp
<x-layouts.admin :title="$editing ? __('schedule.edit_regular', ['client' => $client->display_name]) : __('schedule.new_regular', ['client' => $client->display_name])">
    <a href="{{ route('admin.clients.show', $client) }}" class="text-sm font-semibold text-navy-500 hover:text-navy">← {{ $client->display_name }}</a>

    <form method="POST" action="{{ $editing ? route('admin.schedules.update', $schedule) : route('admin.schedules.store', $client) }}"
          class="card mt-4 max-w-3xl space-y-5" x-data="{ frequency: @js(old('frequency', $schedule->frequency?->value)) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="frequency" class="label">{{ __('schedule.fields.frequency') }}</label>
                <select id="frequency" name="frequency" x-model="frequency" class="input">
                    @foreach (\App\Enums\Frequency::cases() as $f)
                        <option value="{{ $f->value }}">{{ $f->label() }}</option>
                    @endforeach
                </select>
                <x-field-error name="frequency" />
            </div>
            <div>
                <label for="weekday" class="label">{{ __('schedule.fields.weekday') }}</label>
                <select id="weekday" name="weekday" class="input">
                    @foreach ($days as $num => $label)
                        <option value="{{ $num }}" @selected((int) old('weekday', $schedule->weekday) === $num)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="frequency === 'monthly_nth_weekday'" x-cloak>
                <label for="nth_week" class="label">{{ __('schedule.fields.nth_week') }}</label>
                <select id="nth_week" name="nth_week" class="input" :disabled="frequency !== 'monthly_nth_weekday'">
                    @foreach (__('schedule.nth_options') as $value => $label)
                        <option value="{{ $value }}" @selected((string) old('nth_week', $schedule->nth_week) === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-field-error name="nth_week" />
            </div>
            <div>
                <label for="start_time" class="label">{{ __('schedule.fields.start_time') }}</label>
                <input id="start_time" name="start_time" type="time" step="900" required value="{{ old('start_time', substr((string) $schedule->start_time, 0, 5)) }}" class="input">
                <x-field-error name="start_time" />
            </div>
            <div>
                <label for="duration_minutes" class="label">{{ __('schedule.fields.duration_minutes') }}</label>
                <x-duration-select :value="old('duration_minutes', $schedule->duration_minutes)" />
            </div>
            <div>
                <label for="anchor_date" class="label">{{ __('schedule.fields.anchor_date') }}</label>
                <input id="anchor_date" name="anchor_date" type="date" required value="{{ old('anchor_date', $schedule->anchor_date?->toDateString()) }}" class="input">
                <p class="mt-1 text-sm text-navy-500">{{ __('schedule.help.anchor') }}</p>
                <x-field-error name="anchor_date" />
            </div>
            <div>
                <label for="effective_until" class="label">{{ __('schedule.fields.effective_until') }}</label>
                <input id="effective_until" name="effective_until" type="date" value="{{ old('effective_until', $schedule->effective_until?->toDateString()) }}" class="input">
                <x-field-error name="effective_until" />
            </div>
            <div>
                <label for="service_type_id" class="label">{{ __('schedule.fields.service_type_id') }}</label>
                <select id="service_type_id" name="service_type_id" class="input">
                    <option value="">—</option>
                    @foreach ($serviceTypes as $type)
                        <option value="{{ $type->id }}" @selected((int) old('service_type_id', $schedule->service_type_id) === $type->id)>{{ $type->name() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <x-staff-picker :options="$staffOptions" :selected="$selectedStaff" />

        <div>
            <label for="default_job_notes" class="label">{{ __('schedule.fields.default_job_notes') }}</label>
            <textarea id="default_job_notes" name="default_job_notes" rows="3" class="input !min-h-24 py-3">{{ old('default_job_notes', $schedule->default_job_notes) }}</textarea>
        </div>

        @if ($editing)
            <p class="rounded-xl bg-sage-50 px-4 py-3 text-sm text-sage-700">{{ __('schedule.help.regenerate') }}</p>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <button type="submit" class="btn-primary sm:w-auto">{{ __('schedule.save_regular') }}</button>
        </div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('admin.schedules.destroy', $schedule) }}" class="mt-4 max-w-3xl"
              x-data @submit="if (! confirm(@js(__('schedule.end_confirm')))) $event.preventDefault()">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary btn-sm !border-alert !text-alert">{{ __('schedule.end_regular') }}</button>
        </form>
    @endif
</x-layouts.admin>
