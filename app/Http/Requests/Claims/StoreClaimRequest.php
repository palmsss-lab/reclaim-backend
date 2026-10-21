<?php

namespace App\Http\Requests\Claims;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageClaims', FoundItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'claimant_id' => ['required', 'integer', 'min:1', 'exists:person,person_id'],
            'evidence_text' => ['required', 'string', 'max:16000', 'not_regex:/^\s*$/'],
            'submitted_at' => ['prohibited'],
            'outcome' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
        ];
    }
}
