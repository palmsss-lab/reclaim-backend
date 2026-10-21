<?php

namespace App\Http\Requests\Accounts;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAccounts', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'staff_full_name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            'staff_id' => ['prohibited'],
            'is_account_admin' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }
}
