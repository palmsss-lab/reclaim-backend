<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter the six-digit code from your email.',
            'code.string' => 'Enter the six-digit code from your email.',
            'code.regex' => 'Enter the six-digit code from your email.',
        ];
    }
}
