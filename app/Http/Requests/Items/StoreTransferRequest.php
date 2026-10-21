<?php

namespace App\Http\Requests\Items;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageItems', FoundItem::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'staff_id' => ['prohibited'],
            'received_by' => ['prohibited'],
            'assigned_by' => ['prohibited'],
            'location_id' => ['required', 'integer', 'exists:storage_location,location_id'],
        ];
    }
}
