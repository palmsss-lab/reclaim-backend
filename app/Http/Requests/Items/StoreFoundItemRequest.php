<?php

namespace App\Http\Requests\Items;

use App\Models\FoundItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreFoundItemRequest extends FormRequest
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
            'finder_id' => ['required', 'integer', 'exists:person,person_id'],
            'category_id' => ['required', 'integer', 'exists:category,category_id'],
            'initial_location_id' => ['required', 'integer', 'exists:storage_location,location_id'],
            'item_description' => ['required', 'string', 'not_regex:/^\s*$/'],
            'found_place' => ['required', 'string', 'max:200', 'not_regex:/^\s*$/'],
            'found_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
