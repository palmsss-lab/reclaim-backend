<?php

namespace App\Http\Requests\Accounts;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('account') instanceof User
            && ($this->user()?->can('modifyAccount', $this->route('account')) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $target = $this->route('account');
        $required = $this->isMethod('PUT') ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => array_merge($required, ['string', 'max:255', 'not_regex:/^\s*$/']),
            'email' => array_merge($required, ['email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)]),
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
            'staff_id' => ['prohibited'],
            'staff_full_name' => ['prohibited'],
            'is_account_admin' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }
}
