<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['full_name', 'contact_details', 'email'] as $field) if (is_string($this->input($field))) $this->merge([$field => trim($this->input($field))]);
        if (is_string($this->input('email'))) $this->merge(['email' => mb_strtolower($this->input('email'))]);
    }
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $required = $this->isMethod('PUT') ? ['required'] : ['sometimes', 'required'];
        return [
            'full_name' => array_merge($required, ['string', 'max:120', 'not_regex:/^\s*$/']),
            'contact_details' => array_merge($required, ['string', 'max:200', 'not_regex:/^\s*$/']),
            'email' => array_merge($required, ['email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()?->id)]),
            'password' => ['prohibited'], 'password_confirmation' => ['prohibited'],
            'person_id' => ['prohibited'], 'staff_id' => ['prohibited'], 'is_account_admin' => ['prohibited'], 'deactivated_at' => ['prohibited'],
        ];
    }
}