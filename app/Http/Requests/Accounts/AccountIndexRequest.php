<?php

namespace App\Http\Requests\Accounts;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AccountIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->can('manageAccounts', User::class) ?? false)) {
            return false;
        }
        if ($this->isMethod('GET') || $this->isMethod('HEAD')) {
            return true;
        }

        return $this->route('account') instanceof User
            && $this->user()->can('modifyAccount', $this->route('account'));
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
