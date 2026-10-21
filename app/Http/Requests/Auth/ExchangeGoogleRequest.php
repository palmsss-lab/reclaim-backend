<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeGoogleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'verifier' => ['required', 'regex:/^[A-Za-z0-9_-]{43,128}$/']];
    }
}
