<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
        if (is_string($this->input('full_name'))) {
            $this->merge(['full_name' => trim($this->input('full_name'))]);
        }
        if (is_string($this->input('contact_details'))) {
            $this->merge(['contact_details' => trim($this->input('contact_details'))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'contact_details' => ['required', 'string', 'max:200', 'not_regex:/^\s*$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/\A[a-z][a-z0-9._-]*\z/', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            'person_id' => ['prohibited'], 'staff_id' => ['prohibited'],
            'is_account_admin' => ['prohibited'], 'deactivated_at' => ['prohibited'],
        ];
    }
}
