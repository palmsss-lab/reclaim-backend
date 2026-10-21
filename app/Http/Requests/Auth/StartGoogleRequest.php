<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StartGoogleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['challenge' => ['required', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'current_password' => ['nullable', 'string', 'max:255']];
    }
}
