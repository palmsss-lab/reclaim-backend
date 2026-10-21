<?php

namespace App\Http\Requests\Claims;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListClaimsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageClaims', FoundItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['sometimes', Rule::in(['PENDING', 'REJECTED', 'RETURNED'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
