<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff may change ONLY: name, email, avatar, language.
 * Anything else posted (role, hourly_rate, is_active, …) is not validated and therefore never saved.
 */
class UpdateOwnProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users')->ignore($this->user()->id)],
            'locale' => ['required', Rule::in(array_keys(config('apq.locales')))],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('apq.avatars.max_kb')],
            'remove_avatar' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('profile.name'),
            'email' => __('profile.email'),
            'locale' => __('profile.language'),
            'avatar' => __('profile.avatar'),
        ];
    }
}
