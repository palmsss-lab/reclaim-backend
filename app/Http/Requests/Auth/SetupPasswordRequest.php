<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SetupPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['proof' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed']];
    }
}
