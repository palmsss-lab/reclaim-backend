<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
        if (is_string($this->input('login'))) {
            $this->merge(['login' => mb_strtolower(trim($this->input('login')))]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required_without:email', 'prohibits:email', 'string', 'max:255'],
            'email' => ['required_without:login', 'prohibits:login', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
