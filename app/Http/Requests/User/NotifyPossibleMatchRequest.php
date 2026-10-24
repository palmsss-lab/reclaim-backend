<?php

namespace App\Http\Requests\User;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class NotifyPossibleMatchRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', FoundItem::class) ?? false; }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', 'exists:found_item,item_id'],
            'user_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'recipient_user_id' => ['prohibited'],
            'person_id' => ['prohibited'], 'staff_id' => ['prohibited'], 'message' => ['prohibited'],
        ];
    }
}
