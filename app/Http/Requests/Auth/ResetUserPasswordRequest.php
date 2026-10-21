<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
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
    }

    public function messages(): array
    {
        return [
            'authorization.required' => 'Your password-reset session is invalid or has expired. Request another code.',
            'authorization.string' => 'Your password-reset session is invalid or has expired. Request another code.',
            'authorization.regex' => 'Your password-reset session is invalid or has expired. Request another code.',
        ];
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'authorization' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ];
    }
}
