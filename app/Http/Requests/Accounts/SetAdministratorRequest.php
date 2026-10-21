<?php

namespace App\Http\Requests\Accounts;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SetAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('account') instanceof User
            && ($this->user()?->can('modifyAccount', $this->route('account')) ?? false);
    }

    public function rules(): array
    {
        return [
            'is_account_admin' => ['required', 'boolean'],
        ];
    }
}
