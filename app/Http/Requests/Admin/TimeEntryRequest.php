<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'billable_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'approve' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return collect(array_keys($this->rules()))->mapWithKeys(fn ($f) => [$f => __('timesheets.fields.'.$f)])->all();
    }
}
