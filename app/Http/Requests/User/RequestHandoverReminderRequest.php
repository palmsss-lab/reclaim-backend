<?php

namespace App\Http\Requests\User;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class RequestHandoverReminderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('manageItems', FoundItem::class) ?? false; }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid']] + array_fill_keys([
            'user_id', 'recipient_id', 'recipient_user_id', 'person_id', 'staff_id', 'message',
            'status', 'item_id', 'outcome', 'claimant_id', 'evidence_text',
        ], ['prohibited']);
    }
}
