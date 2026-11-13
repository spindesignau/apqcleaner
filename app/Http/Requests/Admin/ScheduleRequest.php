<?php

namespace App\Http\Requests\Admin;

use App\Enums\Frequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }

    public function rules(): array
    {
        return [
            'frequency' => ['required', new Enum(Frequency::class)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'nth_week' => ['nullable', 'required_if:frequency,monthly_nth_weekday', Rule::in([1, 2, 3, 4, -1])],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'anchor_date' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:anchor_date'],
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'staff' => ['array'],
            'staff.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
            'default_job_notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return collect(array_keys($this->rules()))->mapWithKeys(fn ($f) => [$f => __('schedule.fields.'.str_replace('.*', '', $f))])->all();
    }
}
