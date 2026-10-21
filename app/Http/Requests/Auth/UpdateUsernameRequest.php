<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUsernameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/\A[a-z][a-z0-9._-]*\z/', Rule::unique('users', 'username')->ignore($this->user()->id)],
            'current_password' => $this->user()->has_local_password ? ['required', 'string', 'current_password:sanctum'] : ['nullable', 'string'],
            'user_id' => ['prohibited'], 'staff_id' => ['prohibited'], 'person_id' => ['prohibited'],
            'is_account_admin' => ['prohibited'],
        ];
    }
}
