@props(['name' => 'duration_minutes', 'value' => 180])
<select id="{{ $name }}" name="{{ $name }}" {{ $attributes->class('input') }}>
    @for ($m = 60; $m <= 600; $m += 15)
        <option value="{{ $m }}" @selected((int) $value === $m)>
            {{ $m % 60 ? __('schedule.hours_minutes', ['h' => intdiv($m, 60), 'm' => $m % 60]) : __('schedule.hours', ['h' => intdiv($m, 60)]) }}
        </option>
    @endfor
</select>
