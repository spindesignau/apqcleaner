<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PricingModel;
use App\Support\AuPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mobile' => $this->filled('mobile') ? AuPhone::normalise($this->input('mobile')) : null,
            'sms_opt_in' => $this->boolean('sms_opt_in'),
            'show_phone_to_staff' => $this->boolean('show_phone_to_staff'),
            'is_active' => $this->boolean('is_active'),
            'billing_name' => $this->input('billing_name') ?: $this->input('display_name'),
            'invoice_frequency' => $this->input('invoice_frequency') ?: 'monthly',
            'cancellation_fee_type' => $this->input('cancellation_fee_type') ?: 'percent_of_visit',
            'cancellation_fee_value' => $this->input('cancellation_fee_value') ?? 50,
            'cancellation_window_hours' => $this->input('cancellation_window_hours') ?: 24,
        ]);
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:150'],
            'billing_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:190'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^\+\d{8,15}$/'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'suburb' => ['nullable', 'string', 'max:80'],
            'state' => ['required', Rule::in(['NSW', 'ACT', 'VIC', 'QLD', 'SA', 'WA', 'TAS', 'NT'])],
            'postcode' => ['nullable', 'digits:4'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'default_job_notes' => ['nullable', 'string', 'max:2000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'service_type_id' => ['nullable', 'exists:service_types,id'],
            'pricing_model' => ['required', new Enum(PricingModel::class)],
            'fixed_price' => ['nullable', 'required_if:pricing_model,fixed', 'numeric', 'min:0', 'max:99999'],
            'hourly_rate' => ['nullable', 'required_if:pricing_model,hourly', 'numeric', 'min:0', 'max:999'],
            'payment_method' => ['required', new Enum(PaymentMethod::class)],
            'invoice_frequency' => ['nullable', Rule::in(['per_visit', 'weekly', 'fortnightly', 'monthly'])],
            'cancellation_fee_type' => ['nullable', Rule::in(['percent_of_visit', 'fixed', 'none'])],
            'cancellation_fee_value' => ['nullable', 'numeric', 'min:0', 'max:99999', Rule::when($this->input('cancellation_fee_type') === 'percent_of_visit', ['max:100'])],
            'cancellation_window_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'referral_source' => ['nullable', 'string', 'max:150'],
            'preferred_language' => ['required', Rule::in(array_keys(config('apq.locales')))],
            'sms_opt_in' => ['boolean'],
            'show_phone_to_staff' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return ['mobile.regex' => __('clients.mobile_invalid')];
    }

    public function attributes(): array
    {
        return collect(array_keys($this->rules()))->mapWithKeys(fn ($f) => [$f => __('clients.fields.'.$f)])->all();
    }
}
