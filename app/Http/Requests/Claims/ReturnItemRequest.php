<?php

namespace App\Http\Requests\Claims;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class ReturnItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageClaims', FoundItem::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'returned_at' => ['sometimes', 'required', 'string', 'date', 'before_or_equal:now'],
            'reviewed_at' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'outcome' => ['prohibited'],
            'item_id' => ['prohibited'],
            'claimant_id' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'evidence_text' => ['prohibited'],
        ];
    }
}
