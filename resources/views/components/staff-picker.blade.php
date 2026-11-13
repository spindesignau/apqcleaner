@props(['options', 'selected' => []])
<fieldset>
    <legend class="label">{{ __('schedule.fields.staff') }}</legend>
    <div class="flex flex-wrap gap-2">
        @foreach ($options as $person)
            <label>
                <input type="checkbox" name="staff[]" value="{{ $person->id }}" class="peer sr-only" @checked(in_array($person->id, array_map('intval', $selected), true))>
                <span class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border-2 border-sand-600 px-4 font-semibold peer-checked:border-navy peer-checked:bg-navy peer-checked:text-offwhite peer-focus-visible:ring-2 peer-focus-visible:ring-sage">{{ $person->name }}</span>
            </label>
        @endforeach
    </div>
    <x-field-error name="staff" />
    <x-field-error name="staff.0" />
</fieldset>
