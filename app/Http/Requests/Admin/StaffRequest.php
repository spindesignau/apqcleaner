<?php

namespace App\Http\Requests\Admin;

use App\Enums\EmploymentType;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('mobile')) {
            $digits = preg_replace('/[^\d+]/', '', (string) $this->input('mobile'));
            // 04xx xxx xxx → +614xxxxxxxx
            if (preg_match('/^0(4\d{8})$/', $digits, $m)) {
                $digits = '+61'.$m[1];
            } elseif (preg_match('/^614\d{8}$/', $digits)) {
                $digits = '+'.$digits;
            }
            $this->merge(['mobile' => $digits]);
        }
    }

    public function rules(): array
    {
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($staff?->id)],
            'mobile' => ['nullable', 'regex:/^\+614\d{8}$/'],
            'role' => ['required', new Enum(Role::class)],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'saturday_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'sunday_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'abn' => ['nullable', 'regex:/^\d{2}\s?\d{3}\s?\d{3}\s?\d{3}$/'],
            'locale' => ['required', Rule::in(array_keys(config('apq.locales')))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'password' => [$staff ? 'nullable' : 'required', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return ['mobile.regex' => __('admin.staff.mobile_invalid')];
    }

    public function attributes(): array
    {
        return collect(['name', 'email', 'mobile', 'role', 'employment_type', 'hourly_rate', 'saturday_rate', 'sunday_rate', 'abn', 'locale', 'notes', 'password'])
            ->mapWithKeys(fn ($f) => [$f => __('admin.staff.fields.'.$f)])->all();
    }
}
