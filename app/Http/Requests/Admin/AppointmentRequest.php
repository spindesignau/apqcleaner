<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'client_id' => [$this->route('appointment') ? 'prohibited' : 'required', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:30', 'max:720'],
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'staff' => ['array'],
            'staff.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
            'job_notes' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ];
    }

    public function attributes(): array
    {
        return collect(array_keys($this->rules()))->mapWithKeys(fn ($f) => [$f => __('schedule.fields.'.str_replace('.*', '', $f))])->all();
    }
}
