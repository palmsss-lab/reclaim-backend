<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetCodeRequest extends FormRequest
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
            'code.required' => 'Enter the six-digit code from your email.',
            'code.string' => 'Enter the six-digit code from your email.',
            'code.regex' => 'Enter the six-digit code from your email.',
        ];
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }
}
